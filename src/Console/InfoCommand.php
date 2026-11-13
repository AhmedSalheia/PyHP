<?php

declare(strict_types=1);

namespace PyHP\Console;

use Closure;
use Composer\InstalledVersions;
use JsonException;
use PyHP\Config;
use PyHP\Exception\PyHPException;
use PyHP\OutputMode;
use PyHP\Session;
use PyHP\Setup\CommandRunner;
use PyHP\Setup\Installer;
use PyHP\Setup\LockFile;
use PyHP\Setup\ProjectRequirements;
use PyHP\Setup\PythonLocation;
use PyHP\Setup\PythonSource;
use PyHP\Setup\Requirement;
use PyHP\Setup\SetupConfig;
use stdClass;

/**
 * `pyhp info`: everything worth knowing when PyHP doesn't work as expected,
 * from PHP and the chosen interpreter to missing packages and a live worker test.
 * `pyhp info --json` prints the same facts as one JSON document (see FORMAT).
 *
 * @internal Part of the pyhp command line, not of the public API: use `vendor/bin/pyhp` instead.
 */
final class InfoCommand
{
    /**
     * Prints the interpreter's details and the installed versions of the package
     * names given as a JSON list in argv[1] (null when not installed).
     */
    public const PYTHON_PROBE = <<<'PY'
        import json, platform, sys
        from importlib import metadata
        packages = {}
        for name in json.loads(sys.argv[1]):
            try:
                packages[name] = metadata.version(name)
            except metadata.PackageNotFoundError:
                packages[name] = None
        print(json.dumps({"version": platform.python_version(), "implementation": platform.python_implementation(),
                          "executable": sys.executable, "prefix": sys.prefix, "packages": packages}))
        PY;

    /** Version of the --json document; fields are only added within a version. */
    public const FORMAT = 1;

    private const LABEL_WIDTH = 14;

    /** @var list<string> */
    private array $problems = [];

    private bool $json = false;

    /** @var array<string, mixed> what --json prints */
    private array $report = [];

    /** @var Closure(string): ?string */
    private readonly Closure $workerCheck;

    /**
     * @param  Closure(string): void  $out
     * @param  (Closure(string): ?string)|null  $workerCheck  starts a worker with the interpreter; returns an error or null
     */
    public function __construct(
        private readonly CommandRunner $runner,
        private readonly Closure $out,
        ?Closure $workerCheck = null,
    ) {
        $this->workerCheck = $workerCheck ?? self::checkWorker(...);
    }

    /** @return int 0 when everything looks fine, 1 when problems were found */
    public function run(string $root, bool $json = false): int
    {
        $this->problems = [];
        $this->json = $json;
        $this->report = [];
        $setup = SetupConfig::fromProject($root);
        $location = Config::locatePython($root);

        $requirements = ProjectRequirements::collect($root, $setup);

        $this->php();
        $names = $this->project($root, $setup, $requirements);
        $this->python($root, $location, $names, $requirements->overrides() !== []);
        $this->problemsReport();

        if ($json) {
            ($this->out)(json_encode([
                'format' => self::FORMAT,
                'ok' => $this->problems === [],
                ...$this->report,
                'problems' => $this->problems,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL);
        }

        return $this->problems === [] ? 0 : 1;
    }

    /** Starts a real worker with $python and asks it a question; returns the error, or null when it works. */
    public static function checkWorker(string $python): ?string
    {
        try {
            $session = new Session(new Config(python: $python, output: OutputMode::Capture, timeout: 10.0));
            try {
                return $session->eval('1 + 1') === 2 ? null : 'the worker answered 1 + 1 incorrectly';
            } finally {
                $session->close();
            }
        } catch (PyHPException $e) {
            return $e->getMessage();
        }
    }

    private function php(): void
    {
        $this->heading('PyHP '.self::pyhpVersion());
        $this->heading('PHP');
        $this->row('Version', sprintf('%s (%s, %s, %d-bit)', PHP_VERSION, PHP_SAPI, PHP_ZTS ? 'ZTS' : 'NTS', PHP_INT_SIZE * 8));
        $this->row('Binary', PHP_BINARY);
        $this->row('OS', sprintf('%s %s (%s)', PHP_OS_FAMILY, php_uname('r'), php_uname('m')));
        $ini = php_ini_loaded_file();
        $this->row('php.ini', $ini === false ? 'none loaded' : $ini);

        $disabled = array_map(trim(...), explode(',', (string) ini_get('disable_functions')));
        $procOpen = function_exists('proc_open') && ! in_array('proc_open', $disabled, true);
        $this->row('proc_open', $procOpen ? 'available' : 'DISABLED');
        $this->report['pyhp'] = ['version' => self::pyhpVersion()];
        $this->report['php'] = [
            'version' => PHP_VERSION,
            'sapi' => PHP_SAPI,
            'zts' => PHP_ZTS === 1,
            'bits' => PHP_INT_SIZE * 8,
            'binary' => PHP_BINARY,
            'os' => PHP_OS_FAMILY,
            'os_release' => php_uname('r'),
            'machine' => php_uname('m'),
            'ini' => $ini === false ? null : $ini,
            'proc_open' => $procOpen,
        ];
        if (! $procOpen) {
            $this->problems[] = 'proc_open() is disabled in php.ini (disable_functions); PyHP needs it to start Python.';
        }
        if (PHP_INT_SIZE < 8) {
            $this->problems[] = 'PyHP needs 64-bit PHP.';
        }
    }

    /** @return list<string> the package names to look up in the interpreter */
    private function project(string $root, SetupConfig $setup, ProjectRequirements $requirements): array
    {
        $this->heading('Project');
        $this->row('Root', $root);
        $this->row('Python wanted', $setup->python ?? 'any (extra.pyhp.python not set)');
        $this->report['project'] = [
            'root' => $root,
            'python' => $setup->python,
            'requirements_file' => $setup->requirementsFile === null ? null : [
                'path' => $setup->requirementsFile,
                'exists' => is_file($root.DIRECTORY_SEPARATOR.$setup->requirementsFile),
            ],
            'requirements' => array_map(
                static fn (Requirement $r): array => ['spec' => $r->spec, 'origin' => $r->origin],
                $requirements->all(),
            ),
            'ignored' => $requirements->ignored(),
            'overrides' => $requirements->overrides(),
        ];

        if ($setup->requirementsFile !== null && ! is_file($root.DIRECTORY_SEPARATOR.$setup->requirementsFile)) {
            $this->row('Requirements', "{$setup->requirementsFile} (not found)");
            $this->problems[] = "The requirements file {$setup->requirementsFile} doesn't exist.";
        } elseif ($setup->requirementsFile !== null) {
            $own = array_filter($requirements->all(), static fn (Requirement $r): bool => $r->origin === ProjectRequirements::ROOT);
            $this->row('Requirements', $setup->requirementsFile.' ('.count($own).' packages)');
        } else {
            $this->row('Requirements', $setup->requirements === [] ? 'none' : implode(', ', $setup->requirements));
        }

        $fromPackages = array_map(
            static fn (Requirement $r): string => "{$r->origin}: {$r->spec}",
            $requirements->fromPackages(),
        );
        $this->row('From packages', $fromPackages === [] ? 'none' : implode(', ', $fromPackages));
        if ($requirements->ignored() !== []) {
            $this->row('Ignored', implode(', ', $requirements->ignored()).' (extra.pyhp.ignore-packages)');
        }
        if ($requirements->overrides() !== []) {
            $this->row('Overrides', implode(', ', $requirements->overrides()));
        }
        $this->lockRow($root, $setup, $requirements);

        return $requirements->names();
    }

    private function lockRow(string $root, SetupConfig $setup, ProjectRequirements $requirements): void
    {
        $file = $root.DIRECTORY_SEPARATOR.LockFile::NAME;
        if (! is_file($file)) {
            $this->row('Lock file', 'none (optional; vendor/bin/pyhp lock writes one with exact versions)');
            $this->reportLock(null, 'none', 0);

            return;
        }
        $contents = (string) file_get_contents($file);
        $count = count(LockFile::packageNames($contents));
        if (LockFile::fingerprintIn($contents) === LockFile::fingerprint($root, $setup, $requirements)) {
            $this->row('Lock file', LockFile::NAME." (up to date, {$count} packages)");
            $this->reportLock(LockFile::NAME, 'up_to_date', $count);

            return;
        }
        $this->row('Lock file', LockFile::NAME.' (out of date)');
        $this->reportLock(LockFile::NAME, 'out_of_date', $count);
        $this->problems[] = LockFile::NAME.' is out of date: the Python requirements changed since it was written. Run: vendor/bin/pyhp lock';
    }

    private function reportLock(?string $file, string $status, int $packages): void
    {
        $project = is_array($this->report['project'] ?? null) ? $this->report['project'] : [];
        $project['lock'] = ['file' => $file, 'status' => $status, 'packages' => $packages];
        $this->report['project'] = $project;
    }

    /** @param list<string> $requirements */
    private function python(string $root, PythonLocation $location, array $requirements, bool $hasOverrides): void
    {
        $this->heading('Python');
        $this->row('Interpreter', $location->python);
        $this->row('Chosen by', $location->source->describe());
        $noVenv = false;
        if ($location->venv !== null) {
            $exists = $location->source === PythonSource::ProjectVenv;
            $this->row('Project venv', $location->venv.($exists ? '' : ' (not created yet)'));
            $noVenv = ! $exists && $location->source === PythonSource::PlatformDefault && $requirements !== [];
        }

        $uv = $this->runner->find('uv');
        $uvVersion = $uv === null ? null : $this->runner->capture([$uv, '--version'], $root);
        /** @var array<string, mixed> $report */
        $report = [
            'interpreter' => $location->python,
            'chosen_by' => $location->source->value,
            'venv' => $location->venv === null ? null : [
                'path' => $location->venv,
                'exists' => $location->source === PythonSource::ProjectVenv,
            ],
            'uv' => $uv === null ? null : ['path' => $uv, 'version' => $uvVersion],
            'started' => false,
        ];
        $this->report['python'] = $report;
        if ($uv === null && $hasOverrides) {
            $this->problems[] = 'extra.pyhp.overrides needs uv, which is not installed: '.Installer::UV_INSTALL_URL;
        }
        $this->row('uv', $uv === null ? 'not installed (optional; makes pyhp setup faster and can download Python)' : ($uvVersion ?? 'found')." at {$uv}");

        $probe = $this->runner->capture(
            [$location->python, '-c', self::PYTHON_PROBE, json_encode($requirements, JSON_THROW_ON_ERROR)],
            $root,
        );
        $details = $probe === null ? null : self::decode($probe);
        if ($details === null) {
            $this->row('Status', 'could not be started');
            $this->report['python'] = $report;
            $this->problems[] = "Python could not be started: {$location->python}";

            return;
        }

        $this->row('Version', "{$details['version']} ({$details['implementation']})");
        $this->row('Executable', $details['executable']);
        if (version_compare($details['version'], Installer::MIN_PYTHON, '<')) {
            $this->problems[] = 'PyHP needs Python '.Installer::MIN_PYTHON." or newer; this is {$details['version']}.";
        }

        $missing = [];
        $listed = [];
        foreach ($details['packages'] as $name => $version) {
            $listed[] = $version === null ? "{$name} missing" : "{$name} {$version}";
            if ($version === null) {
                $missing[] = $name;
            }
        }
        $this->row('Packages', $listed === [] ? 'none required' : implode(', ', $listed));
        if ($missing !== []) {
            if ($noVenv) {
                $this->problems[] = "No virtual environment at {$location->venv}.";
            }
            $this->problems[] = 'Not installed: '.implode(', ', $missing).'. Run: vendor/bin/pyhp setup';
        } elseif ($noVenv) {
            $this->row('Note', 'the packages come from the system Python; run vendor/bin/pyhp setup to give the project its own venv.');
        }

        $started = microtime(true);
        $error = ($this->workerCheck)($location->python);
        $milliseconds = (int) round((microtime(true) - $started) * 1000);
        $this->row('Worker', $error === null ? "started and answered in {$milliseconds} ms" : 'FAILED');
        $this->report['python'] = [
            ...$report,
            'started' => true,
            'version' => $details['version'],
            'implementation' => $details['implementation'],
            'executable' => $details['executable'],
            'packages' => $details['packages'] === [] ? new stdClass : $details['packages'],
            'worker' => ['ok' => $error === null, 'error' => $error, 'ms' => $milliseconds],
        ];
        if ($error !== null) {
            $this->problems[] = "The PyHP worker failed: {$error}";
        }

        $this->say('');
        $this->say('  An application can still choose another interpreter with Py::config([\'python\' => ...]).');
    }

    private function problemsReport(): void
    {
        $this->say('');
        if ($this->problems === []) {
            $this->say('Everything looks fine.');

            return;
        }
        $this->say('Problems:');
        foreach ($this->problems as $problem) {
            $this->say("  - {$problem}");
        }
    }

    /** @return array{version: string, implementation: string, executable: string, packages: array<string, string|null>}|null */
    private static function decode(string $json): ?array
    {
        try {
            $data = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        if (! is_array($data) || ! is_string($data['version'] ?? null) || ! is_string($data['implementation'] ?? null)
            || ! is_string($data['executable'] ?? null) || ! is_array($data['packages'] ?? null)) {
            return null;
        }
        $packages = [];
        foreach ($data['packages'] as $name => $version) {
            if (is_string($name) && (is_string($version) || $version === null)) {
                $packages[$name] = $version;
            }
        }

        return [
            'version' => $data['version'],
            'implementation' => $data['implementation'],
            'executable' => $data['executable'],
            'packages' => $packages,
        ];
    }

    private static function pyhpVersion(): string
    {
        if (class_exists(InstalledVersions::class) && InstalledVersions::isInstalled('pyhp/pyhp')) {
            return InstalledVersions::getPrettyVersion('pyhp/pyhp') ?? 'unknown version';
        }

        return 'unknown version';
    }

    private function heading(string $title): void
    {
        if ($this->json) {
            return;
        }
        $this->say('');
        $this->say($title);
    }

    private function row(string $label, string $value): void
    {
        if ($this->json) {
            return;
        }
        $this->say('  '.str_pad($label, self::LABEL_WIDTH).' '.$value);
    }

    private function say(string $line): void
    {
        if ($this->json) {
            return;
        }
        ($this->out)($line.PHP_EOL);
    }
}
