<?php

declare(strict_types=1);

namespace PyHP\Setup;

use Closure;

/**
 * Builds a project's Python environment: a virtual environment plus the
 * packages the project needs. Uses uv when it's installed (fast, and it can
 * download any Python version), otherwise the system Python with venv and pip.
 *
 * @internal Part of the pyhp command line, not of the public API: use `vendor/bin/pyhp` instead.
 */
final class Installer
{
    /** Prints "major.minor" of the interpreter it runs in. */
    public const VERSION_PROBE = 'import sys; print("%d.%d" % sys.version_info[:2])';

    public const MIN_PYTHON = '3.10';

    public const UV_INSTALL_URL = 'https://docs.astral.sh/uv/getting-started/installation/';

    /**
     * @param  Closure(string): void  $write  receives each line of progress for the user
     */
    public function __construct(
        private readonly CommandRunner $runner,
        private readonly Closure $write,
        private readonly string $osFamily = PHP_OS_FAMILY,
    ) {}

    /** Files of this name are temporary inputs for `uv pip compile`, written in the project root and removed again. */
    private const RESOLVE_INPUT = '.pyhp-requirements.in';

    /** Packages a venv brings itself; `--exact` never removes them. */
    private const VENV_TOOLS = ['pip', 'setuptools', 'wheel'];

    /**
     * @param  bool  $exact  make the venv match the lock (or the requirements) exactly, removing anything else
     * @return int 0 on success, 1 on failure (the reason has been written)
     */
    public function install(SetupConfig $config, string $root, bool $useUv = true, bool $dryRun = false, bool $exact = false): int
    {
        $venv = $config->venvPath($root);
        $python = VirtualEnv::python($venv, $this->osFamily);
        $uv = $useUv ? $this->runner->find('uv') : null;
        $requirements = ProjectRequirements::collect($root, $config);

        if ($requirements->overrides() !== [] && $uv === null) {
            $this->say('extra.pyhp.overrides needs uv: pip has no way to override other packages\' version rules. '
                .'Install uv ('.self::UV_INSTALL_URL.') or remove extra.pyhp.overrides from composer.json.');

            return 1;
        }
        $this->describe($requirements);

        if (is_file($python)) {
            $this->say("The virtual environment in {$venv} already exists; reusing it.");
        } else {
            $create = $uv !== null
                ? [$uv, 'venv', $venv, ...($config->python !== null ? ['--python', $config->python] : [])]
                : $this->systemVenvCommand($venv, $config->python, $root);
            if ($create === null || ! $this->step($create, $root, $dryRun)) {
                return 1;
            }
        }

        $lock = $root.DIRECTORY_SEPARATOR.LockFile::NAME;
        if (is_file($lock)) {
            $installed = $this->installLock($config, $root, $requirements, $python, $uv, $dryRun, $exact);
        } elseif ($requirements->isEmpty()) {
            $this->say('No Python packages to install. List them in composer.json under extra.pyhp.requirements '
                .'(or in requirements.txt), then run pyhp setup again.');
            $installed = true;
        } elseif ($exact) {
            $installed = $this->syncResolved($config, $root, $requirements, $python, $uv, $dryRun);
        } else {
            $installed = $this->installRequirements($root, $requirements, $python, $uv, $dryRun);
        }
        if (! $installed) {
            return 1;
        }

        if ($useUv && $uv === null) {
            $this->say('Tip: install uv ('.self::UV_INSTALL_URL.') for much faster installs and any Python version on demand.');
        }
        $this->say($dryRun ? 'Dry run: nothing was changed.' : "Done. PyHP will use {$python} automatically.");

        return 0;
    }

    /**
     * `pyhp lock`: resolves every requirement and writes pyhp.lock.
     *
     * @return int 0 on success, 1 on failure (the reason has been written)
     */
    public function lock(SetupConfig $config, string $root, bool $useUv = true, bool $dryRun = false): int
    {
        $uv = $useUv ? $this->runner->find('uv') : null;
        $requirements = ProjectRequirements::collect($root, $config);
        if ($requirements->overrides() !== [] && $uv === null) {
            $this->say('extra.pyhp.overrides needs uv: pip has no way to override other packages\' version rules. '
                .'Install uv ('.self::UV_INSTALL_URL.') or remove extra.pyhp.overrides from composer.json.');

            return 1;
        }
        if ($requirements->isEmpty()) {
            $this->say('Nothing to lock: the project has no Python requirements.');

            return 0;
        }
        $this->describe($requirements);

        $python = VirtualEnv::python($config->venvPath($root), $this->osFamily);
        if (! $this->resolve(LockFile::NAME, $config, $root, $requirements, is_file($python) ? [$python] : null, $uv, $dryRun)) {
            return 1;
        }
        if (! $dryRun) {
            $count = count(LockFile::packageNames((string) file_get_contents($root.DIRECTORY_SEPARATOR.LockFile::NAME)));
            $this->say('Wrote '.LockFile::NAME." ({$count} packages). Commit it; pyhp setup installs exactly these versions.");
        }

        return 0;
    }

    private function installRequirements(string $root, ProjectRequirements $requirements, string $python, ?string $uv, bool $dryRun): bool
    {
        $packages = $requirements->installArguments();
        $overrideFile = $requirements->overrides() === [] ? null : self::temporaryFile($requirements->overrides());
        $install = $uv !== null
            ? [$uv, 'pip', 'install', '--python', $python, ...$packages, ...($overrideFile !== null ? ['--override', $overrideFile] : [])]
            : [$python, '-m', 'pip', 'install', ...$packages];
        try {
            $installed = $this->step($install, $root, $dryRun);
        } finally {
            if ($overrideFile !== null) {
                self::remove($overrideFile);
            }
        }
        if (! $installed) {
            $this->explainFailure($requirements);
        }

        return $installed;
    }

    private function installLock(SetupConfig $config, string $root, ProjectRequirements $requirements, string $python, ?string $uv, bool $dryRun, bool $exact): bool
    {
        $contents = (string) file_get_contents($root.DIRECTORY_SEPARATOR.LockFile::NAME);
        if (LockFile::fingerprintIn($contents) !== LockFile::fingerprint($root, $config, $requirements)) {
            $this->say(LockFile::NAME.' is out of date: your Python requirements changed since it was written. '
                .'Installing the locked versions anyway; run vendor/bin/pyhp lock to update it.');
        } else {
            $this->say('Installing the exact versions from '.LockFile::NAME.'.');
        }

        return $exact
            ? $this->sync(LockFile::NAME, $root, $python, $uv, $dryRun)
            : $this->step($uv !== null
                ? [$uv, 'pip', 'install', '--python', $python, '-r', LockFile::NAME]
                : [$python, '-m', 'pip', 'install', '-r', LockFile::NAME], $root, $dryRun);
    }

    /** --exact without a lock file: resolve into a temporary lock, then sync to it. */
    private function syncResolved(SetupConfig $config, string $root, ProjectRequirements $requirements, string $python, ?string $uv, bool $dryRun): bool
    {
        $temporary = self::temporaryFile([]);
        try {
            return $this->resolve($temporary, $config, $root, $requirements, [$python], $uv, $dryRun)
                && $this->sync($temporary, $root, $python, $uv, $dryRun);
        } finally {
            self::remove($temporary);
        }
    }

    /**
     * Resolves every requirement into a lock file at $target (relative to $root, or absolute).
     *
     * @param  list<string>|null  $python  the interpreter pip resolves with; null finds a system Python
     */
    private function resolve(string $target, SetupConfig $config, string $root, ProjectRequirements $requirements, ?array $python, ?string $uv, bool $dryRun): bool
    {
        $fingerprint = LockFile::fingerprint($root, $config, $requirements);
        $targetPath = self::isAbsolute($target) ? $target : $root.DIRECTORY_SEPARATOR.$target;

        if ($uv !== null) {
            $input = $root.DIRECTORY_SEPARATOR.self::RESOLVE_INPUT;
            $overrideFile = $requirements->overrides() === [] ? null : self::temporaryFile($requirements->overrides());
            $command = [
                $uv, 'pip', 'compile', self::RESOLVE_INPUT, '--universal', '--output-file', $target,
                '--custom-compile-command', 'vendor/bin/pyhp lock', '--quiet',
                ...($config->python !== null ? ['--python-version', $config->python] : []),
                ...($overrideFile !== null ? ['--override', $overrideFile] : []),
            ];
            file_put_contents($input, self::inputLines($requirements->installArguments()));
            try {
                if (! $this->step($command, $root, $dryRun)) {
                    self::remove($targetPath);
                    $this->explainFailure($requirements);

                    return false;
                }
            } finally {
                self::remove($input);
                if ($overrideFile !== null) {
                    self::remove($overrideFile);
                }
            }
            if (! $dryRun) {
                file_put_contents($targetPath, LockFile::withHeader((string) file_get_contents($targetPath), $fingerprint, 'uv, for every operating system'));
            }

            return true;
        }

        $python ??= $this->systemPython($config->python, $root);
        if ($python === null) {
            return false;
        }
        $version = $this->runner->capture([...$python, '-c', self::VERSION_PROBE], $root) ?? 'unknown';
        $report = self::temporaryFile([]);
        try {
            $command = [...$python, '-m', 'pip', 'install', '--dry-run', '--ignore-installed', '--quiet', '--report', $report, ...$requirements->installArguments()];
            if (! $this->step($command, $root, $dryRun)) {
                $this->explainFailure($requirements);

                return false;
            }
            if (! $dryRun) {
                $pinned = LockFile::fromPipReport((string) file_get_contents($report));
                file_put_contents($targetPath, LockFile::withHeader($pinned, $fingerprint, "pip, for {$this->osFamily} with Python {$version}"));
                $this->say("Without uv the lock is resolved only for {$this->osFamily} and Python {$version}; "
                    .'install uv to get one lock for every operating system.');
            }
        } finally {
            self::remove($report);
        }

        return true;
    }

    /** Makes the venv match a lock file exactly. */
    private function sync(string $lock, string $root, string $python, ?string $uv, bool $dryRun): bool
    {
        if ($uv !== null) {
            return $this->step([$uv, 'pip', 'sync', '--python', $python, $lock], $root, $dryRun);
        }
        if (! $this->step([$python, '-m', 'pip', 'install', '-r', $lock], $root, $dryRun)) {
            return false;
        }
        if ($dryRun) {
            $this->say('(then uninstall every package not in the lock)');

            return true;
        }

        $listed = $this->runner->capture([$python, '-m', 'pip', 'list', '--format=json'], $root);
        $installed = $listed === null ? null : json_decode($listed, true);
        if (! is_array($installed)) {
            $this->say('Could not read the installed packages from pip list; nothing was removed.');

            return false;
        }
        $keep = [...LockFile::packageNames((string) file_get_contents(self::isAbsolute($lock) ? $lock : $root.DIRECTORY_SEPARATOR.$lock)), ...self::VENV_TOOLS];
        $extra = [];
        foreach ($installed as $package) {
            $name = is_array($package) && is_string($package['name'] ?? null) ? Requirement::normalize($package['name']) : null;
            if ($name !== null && ! in_array($name, $keep, true)) {
                $extra[] = $name;
            }
        }

        return $extra === [] || $this->step([$python, '-m', 'pip', 'uninstall', '-y', ...$extra], $root, $dryRun);
    }

    /** @param list<string> $arguments install arguments ("-r", "file" become one "-r file" line) */
    private static function inputLines(array $arguments): string
    {
        $lines = [];
        for ($i = 0; $i < count($arguments); $i++) {
            $lines[] = $arguments[$i] === '-r' ? '-r '.$arguments[++$i] : $arguments[$i];
        }

        return implode("\n", $lines)."\n";
    }

    /** Deletes a temporary or half-written file if it exists, without a warning when it doesn't. */
    private static function remove(string $file): void
    {
        if (is_file($file)) {
            unlink($file);
        }
    }

    private static function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/') || str_starts_with($path, '\\') || preg_match('/^[A-Za-z]:[\/\\\\]/', $path) === 1;
    }

    private function describe(ProjectRequirements $requirements): void
    {
        foreach ($requirements->ignored() as $package) {
            $this->say("Skipping the Python requirements of {$package} (extra.pyhp.ignore-packages).");
        }
        $fromPackages = $requirements->fromPackages();
        if ($fromPackages !== []) {
            $this->say('Python packages requested by your Composer packages:');
            foreach ($fromPackages as $requirement) {
                $this->say("  - {$requirement->origin}: {$requirement->spec}");
            }
        }
        if ($requirements->overrides() !== []) {
            $this->say('Overriding version rules (extra.pyhp.overrides): '.implode(', ', $requirements->overrides()));
        }
    }

    private function explainFailure(ProjectRequirements $requirements): void
    {
        $shared = $requirements->shared();
        if ($shared === []) {
            return;
        }
        $this->say('These Python packages are requested by more than one source; check that their version rules can all be met:');
        foreach ($shared as $name => $list) {
            $this->say("  {$name}: ".implode(', ', array_map(
                static fn (Requirement $r): string => "{$r->spec} ({$r->origin})",
                $list,
            )));
        }
        $this->say('To resolve it, tighten your own requirement, force a version with extra.pyhp.overrides (uv), '
            .'or skip a package\'s requirements with extra.pyhp.ignore-packages.');
    }

    /** @param list<string> $lines */
    private static function temporaryFile(array $lines): string
    {
        $file = tempnam(sys_get_temp_dir(), 'pyhp-');
        if ($file === false) {
            throw new \RuntimeException('Could not create a temporary file in the temp folder.');
        }
        file_put_contents($file, implode("\n", $lines)."\n");

        return $file;
    }

    /** @return list<string>|null */
    private function systemVenvCommand(string $venv, ?string $version, string $root): ?array
    {
        $python = $this->systemPython($version, $root);

        return $python === null ? null : [...$python, '-m', 'venv', $venv];
    }

    /**
     * The command that starts a suitable system Python, preferring the requested version.
     *
     * @return list<string>|null
     */
    private function systemPython(?string $version, string $root): ?array
    {
        $tooOld = [];
        $fallback = null;
        foreach ($this->pythonCandidates($version) as [$program, $arguments]) {
            $executable = $this->runner->find($program);
            if ($executable === null) {
                continue;
            }
            $command = [$executable, ...$arguments];
            $found = $this->runner->capture([...$command, '-c', self::VERSION_PROBE], $root);
            if ($found === null) {
                continue;
            }
            if (version_compare($found, self::MIN_PYTHON, '<')) {
                $tooOld[] = $found;

                continue;
            }
            if ($version === null || $found === $version) {
                return $command;
            }
            $fallback ??= ['command' => $command, 'version' => $found];
        }

        if ($fallback !== null) {
            $this->say("Python {$version} was not found, so Python {$fallback['version']} is used instead. "
                .'With uv installed, setup can download the exact version.');

            return $fallback['command'];
        }
        if ($tooOld !== []) {
            $this->say('PyHP needs Python '.self::MIN_PYTHON.' or newer, but only found '.implode(', ', array_unique($tooOld)).'. '
                .'Install uv ('.self::UV_INSTALL_URL.') and run pyhp setup again; it downloads a suitable Python.');

            return null;
        }
        $this->say('No Python was found. Install uv ('.self::UV_INSTALL_URL.') and run pyhp setup again; '
            .'it downloads Python for you. Or install Python '.self::MIN_PYTHON.'+ from https://www.python.org/downloads/.');

        return null;
    }

    /** @return list<array{string, list<string>}> program name and the arguments that pick the version */
    private function pythonCandidates(?string $version): array
    {
        if ($this->osFamily === 'Windows') {
            $requested = $version !== null ? [['py', ["-{$version}"]]] : [];

            return [...$requested, ['py', ['-3']], ['python', []]];
        }
        $requested = $version !== null ? [["python{$version}", []]] : [];

        return [...$requested, ['python3', []], ['python', []]];
    }

    /** @param list<string> $command */
    private function step(array $command, string $root, bool $dryRun): bool
    {
        $shown = self::display($command);
        $this->say("> {$shown}");
        if ($dryRun) {
            return true;
        }

        $code = $this->runner->run($command, $root);
        if ($code !== 0) {
            $this->say("That command failed with exit code {$code}: {$shown}");

            return false;
        }

        return true;
    }

    /** @param list<string> $command */
    private static function display(array $command): string
    {
        return implode(' ', array_map(
            static fn (string $part): string => str_contains($part, ' ') ? '"'.$part.'"' : $part,
            $command,
        ));
    }

    private function say(string $line): void
    {
        ($this->write)($line);
    }
}
