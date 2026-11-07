<?php

declare(strict_types=1);

namespace PyHP\Setup;

use Closure;

/**
 * Builds a project's Python environment: a virtual environment plus the
 * packages the project needs. Uses uv when it's installed (fast, and it can
 * download any Python version), otherwise the system Python with venv and pip.
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

    /** @return int 0 on success, 1 on failure (the reason has been written) */
    public function install(SetupConfig $config, string $root, bool $useUv = true, bool $dryRun = false): int
    {
        $venv = $config->venvPath($root);
        $python = VirtualEnv::python($venv, $this->osFamily);
        $uv = $useUv ? $this->runner->find('uv') : null;

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

        $packages = $config->requirementsFile !== null ? ['-r', $config->requirementsFile] : $config->requirements;
        if ($packages === []) {
            $this->say('No Python packages to install. List them in composer.json under extra.pyhp.requirements '
                .'(or in requirements.txt), then run pyhp setup again.');
        } else {
            $install = $uv !== null
                ? [$uv, 'pip', 'install', '--python', $python, ...$packages]
                : [$python, '-m', 'pip', 'install', ...$packages];
            if (! $this->step($install, $root, $dryRun)) {
                return 1;
            }
        }

        if ($useUv && $uv === null) {
            $this->say('Tip: install uv ('.self::UV_INSTALL_URL.') for much faster installs and any Python version on demand.');
        }
        $this->say($dryRun ? 'Dry run: nothing was changed.' : "Done. PyHP will use {$python} automatically.");

        return 0;
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
