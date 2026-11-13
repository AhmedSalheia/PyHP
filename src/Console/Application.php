<?php

declare(strict_types=1);

namespace PyHP\Console;

use Closure;
use PyHP\Exception\ConfigException;
use PyHP\Setup\CommandRunner;
use PyHP\Setup\Installer;
use PyHP\Setup\SetupConfig;

/**
 * The `vendor/bin/pyhp` command line tool.
 *
 * @internal Part of the pyhp command line, not of the public API: use `vendor/bin/pyhp` instead.
 */
final class Application
{
    /** Exit code when the command failed or found problems. */
    public const FAILURE = 1;

    /** Exit code for a wrong command line: unknown command or option, missing or bad value. */
    public const USAGE_ERROR = 2;

    private const USAGE = <<<'TXT'
        PyHP command line tool

        Usage:
          pyhp setup [options]   Create the project's Python environment and install its packages
          pyhp lock [options]    Resolve every Python requirement and write exact versions to pyhp.lock
          pyhp info [--json]     Show PHP, Python, venv and package details for debugging
                                 (--json: the same as one JSON document, for scripts)
          pyhp help              Show this help

        Run it from your project's root folder (where composer.json is).

        Options for setup:
          --python=VERSION   Python version for the environment, e.g. 3.12 (default: extra.pyhp.python)
          --venv=DIR         Folder of the virtual environment (default: extra.pyhp.venv, or .venv)
          --no-uv            Don't use uv even if it's installed; use the system Python with venv and pip
          --exact            Make the venv match pyhp.lock (or the requirements) exactly, removing other packages
          --dry-run          Only print the commands that would run

        Options for lock: --python, --no-uv and --dry-run, as for setup. With uv the lock
        covers every operating system; without it, only the current one.
        When pyhp.lock exists, setup installs exactly the versions it lists.

        Packages come from composer.json:
          "extra": {"pyhp": {"python": "3.12", "requirements": ["pandas>=2"]}}
        "requirements" can also name a requirements file. Without it, requirements.txt is used if present.

        PyHP finds the environment by itself: sessions use .venv (or extra.pyhp.venv)
        unless you set the "python" option or the PYHP_PYTHON environment variable.

        Exit codes: 0 success, 1 failure (or problems found by info), 2 wrong usage.

        TXT;

    /**
     * @param  Closure(string): void  $out
     * @param  Closure(string): void  $err
     * @param  (Closure(string): ?string)|null  $workerCheck  for `info`; null starts a real worker
     */
    public function __construct(
        private readonly CommandRunner $runner,
        private readonly Closure $out,
        private readonly Closure $err,
        private readonly string $osFamily = PHP_OS_FAMILY,
        private readonly ?Closure $workerCheck = null,
    ) {}

    /**
     * @param  list<string>  $args  command line arguments without the program name
     * @return int process exit code
     */
    public function run(array $args, string $cwd): int
    {
        $command = $args[0] ?? 'help';
        if (in_array($command, ['help', '--help', '-h'], true)) {
            ($this->out)(self::USAGE);

            return 0;
        }
        if ($command === 'info') {
            $json = false;
            foreach (array_slice($args, 1) as $option) {
                if ($option !== '--json') {
                    return $this->usage(sprintf('Unknown option "%s" for info. Run "pyhp help" to see the options.', $option));
                }
                $json = true;
            }
            try {
                return (new InfoCommand($this->runner, $this->out, $this->workerCheck))->run($cwd, $json);
            } catch (ConfigException $e) {
                return $this->fail($e->getMessage());
            }
        }
        if ($command !== 'setup' && $command !== 'lock') {
            return $this->usage(sprintf('Unknown command "%s". Run "pyhp help" to see the commands.', $command));
        }

        try {
            return $this->setupOrLock($command, array_slice($args, 1), $cwd);
        } catch (ConfigException $e) {
            return $this->fail($e->getMessage());
        }
    }

    /** @param list<string> $args */
    private function setupOrLock(string $command, array $args, string $cwd): int
    {
        $python = null;
        $venv = null;
        $useUv = true;
        $dryRun = false;
        $exact = false;

        for ($i = 0; $i < count($args); $i++) {
            $arg = $args[$i];
            $equals = strpos($arg, '=');
            $name = $equals === false ? $arg : substr($arg, 0, $equals);
            $value = $equals === false ? null : substr($arg, $equals + 1);
            switch ($name) {
                case '--help':
                case '-h':
                    ($this->out)(self::USAGE);

                    return 0;
                case '--no-uv':
                    $useUv = false;
                    break;
                case '--dry-run':
                    $dryRun = true;
                    break;
                case '--exact':
                    if ($command === 'lock') {
                        return $this->usage('Unknown option "--exact" for lock. Run "pyhp help" to see the options.');
                    }
                    $exact = true;
                    break;
                case '--python':
                case '--venv':
                    if ($value === null) {
                        $value = $args[$i + 1] ?? null;
                        $i++;
                    }
                    if ($value === null || $value === '' || str_starts_with($value, '--')) {
                        return $this->usage("{$name} needs a value, e.g. {$name}=".($name === '--python' ? '3.12' : '.venv'));
                    }
                    if ($name === '--python') {
                        $python = $value;
                    } else {
                        $venv = $value;
                    }
                    break;
                default:
                    return $this->usage(sprintf('Unknown option "%s" for %s. Run "pyhp help" to see the options.', $name, $command));
            }
        }

        $project = SetupConfig::fromProject($cwd);
        try {
            $config = $project->with(python: $python, venv: $venv);
        } catch (ConfigException $e) {
            return $this->usage($e->getMessage());
        }
        $out = $this->out;
        $installer = new Installer($this->runner, static function (string $line) use ($out): void {
            $out($line.PHP_EOL);
        }, $this->osFamily);

        return $command === 'lock'
            ? $installer->lock($config, $cwd, $useUv, $dryRun)
            : $installer->install($config, $cwd, $useUv, $dryRun, $exact);
    }

    /** Something went wrong: exit code 1. */
    private function fail(string $message): int
    {
        ($this->err)("pyhp: {$message}".PHP_EOL);

        return self::FAILURE;
    }

    /** The command line itself is wrong (unknown command or option, bad value): exit code 2. */
    private function usage(string $message): int
    {
        ($this->err)("pyhp: {$message}".PHP_EOL);

        return self::USAGE_ERROR;
    }
}
