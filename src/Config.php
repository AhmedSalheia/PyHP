<?php

declare(strict_types=1);

namespace PyHP;

use Composer\InstalledVersions;
use PyHP\Exception\ConfigException;
use PyHP\Setup\PythonLocation;
use PyHP\Setup\PythonSource;
use PyHP\Setup\SetupConfig;
use PyHP\Setup\VirtualEnv;

/**
 * Immutable settings for one Python session.
 *
 * Build it from an options array (snake_case keys, the same keys accepted by
 * Py::config() and Py::session()) or with named constructor arguments.
 */
final readonly class Config
{
    public const DEFAULT_TIMEOUT = 30.0;

    public const DEFAULT_STARTUP_TIMEOUT = 10.0;

    public const DEFAULT_MAX_MESSAGE_BYTES = 64 * 1024 * 1024;

    public const MIN_MESSAGE_BYTES = 1024;

    public const DEFAULT_ITER_BATCH_SIZE = 100;

    private const OPTIONS = [
        'python', 'timeout', 'startup_timeout', 'cwd', 'env', 'inherit_env',
        'paths', 'output', 'max_message_bytes', 'log_file', 'iter_batch_size', 'auto_restart',
    ];

    /** Options that were renamed, so old code gets a hint instead of a bare "unknown option". */
    private const RENAMED = ['restart' => 'auto_restart'];

    public string $python;

    /**
     * @param  string|null  $python  Interpreter to run: a command on PATH or a full path (e.g. a venv's python.exe);
     *                               null uses detectPython().
     * @param  float|null  $timeout  Seconds one call may take; null waits forever.
     * @param  float  $startupTimeout  Seconds to wait for the worker to start and connect.
     * @param  string|null  $cwd  Working directory of the Python process; null inherits PHP's.
     * @param  array<string, string>  $env  Extra environment variables for Python.
     * @param  bool  $inheritEnv  Start from PHP's environment (true) or only $env (false).
     * @param  list<string>  $paths  Extra directories put on Python's sys.path.
     * @param  OutputMode  $output  What to do with text Python prints.
     * @param  int  $maxMessageBytes  Largest request or result allowed on the wire.
     * @param  string|null  $logFile  Where the worker's stderr goes; null uses a temp file deleted on close.
     * @param  int  $iterBatchSize  Items fetched per round trip when iterating with foreach.
     * @param  bool  $autoRestart  Start a fresh worker on the next call after a crash or timeout (and run the onStart hooks).
     */
    public function __construct(
        ?string $python = null,
        public ?float $timeout = self::DEFAULT_TIMEOUT,
        public float $startupTimeout = self::DEFAULT_STARTUP_TIMEOUT,
        public ?string $cwd = null,
        public array $env = [],
        public bool $inheritEnv = true,
        public array $paths = [],
        public OutputMode $output = OutputMode::Echo,
        public int $maxMessageBytes = self::DEFAULT_MAX_MESSAGE_BYTES,
        public ?string $logFile = null,
        public int $iterBatchSize = self::DEFAULT_ITER_BATCH_SIZE,
        public bool $autoRestart = false,
    ) {
        $this->python = $python ?? self::detectPython();

        if ($this->python === '') {
            throw new ConfigException('Option "python" must not be empty.');
        }
        if ($timeout !== null && $timeout <= 0) {
            throw new ConfigException('Option "timeout" must be a positive number of seconds, or null for no limit.');
        }
        if ($startupTimeout <= 0) {
            throw new ConfigException('Option "startup_timeout" must be a positive number of seconds.');
        }
        if ($maxMessageBytes < self::MIN_MESSAGE_BYTES) {
            throw new ConfigException('Option "max_message_bytes" must be at least '.self::MIN_MESSAGE_BYTES.'.');
        }
        if ($iterBatchSize < 1) {
            throw new ConfigException('Option "iter_batch_size" must be at least 1.');
        }
    }

    /** The interpreter used when nothing else is found: "python" on Windows, "python3" elsewhere. */
    public static function defaultPython(): string
    {
        return PHP_OS_FAMILY === 'Windows' ? 'python' : 'python3';
    }

    /**
     * The interpreter to use when none is configured, in this order:
     * the PYHP_PYTHON environment variable, the project's virtual environment
     * (.venv, or extra.pyhp.venv in composer.json, as created by `pyhp setup`),
     * then defaultPython().
     *
     * @param  string|null  $projectRoot  null means the root of the Composer project PyHP is installed in
     */
    public static function detectPython(?string $projectRoot = null): string
    {
        return self::locatePython($projectRoot)->python;
    }

    /**
     * Like detectPython(), but also says which rule picked the interpreter and
     * where the project's venv is expected (null when there's no Composer project).
     *
     * @internal Used by `pyhp info`; call detectPython() instead.
     *
     * @param  string|null  $projectRoot  null means the root of the Composer project PyHP is installed in
     */
    public static function locatePython(?string $projectRoot = null): PythonLocation
    {
        $root = $projectRoot ?? self::projectRoot();
        $venv = $root === null ? null : SetupConfig::fromProject($root)->venvPath($root);

        $fromEnv = getenv('PYHP_PYTHON');
        if (is_string($fromEnv) && $fromEnv !== '') {
            return new PythonLocation($fromEnv, PythonSource::EnvironmentVariable, $venv);
        }
        if ($venv !== null && is_file(VirtualEnv::python($venv))) {
            return new PythonLocation(VirtualEnv::python($venv), PythonSource::ProjectVenv, $venv);
        }

        return new PythonLocation(self::defaultPython(), PythonSource::PlatformDefault, $venv);
    }

    private static function projectRoot(): ?string
    {
        // Composer always generates this class; it's missing only when PyHP is loaded some other way.
        if (! class_exists(InstalledVersions::class)) {
            return null;
        }
        $root = realpath(InstalledVersions::getRootPackage()['install_path']);

        return $root === false ? null : $root;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public static function fromArray(array $options): self
    {
        return (new self)->with($options);
    }

    /**
     * A copy of this config with some options replaced.
     *
     * @param  array<string, mixed>  $options
     */
    public function with(array $options): self
    {
        foreach (array_keys($options) as $key) {
            if (isset(self::RENAMED[$key])) {
                throw new ConfigException(sprintf('PyHP option "%s" was renamed to "%s".', $key, self::RENAMED[$key]));
            }
            if (! in_array($key, self::OPTIONS, true)) {
                throw new ConfigException(sprintf(
                    'Unknown PyHP option "%s". Valid options: %s.',
                    $key,
                    implode(', ', self::OPTIONS),
                ));
            }
        }

        return new self(
            python: array_key_exists('python', $options) ? self::string($options, 'python') : $this->python,
            timeout: array_key_exists('timeout', $options) ? self::nullableSeconds($options, 'timeout') : $this->timeout,
            startupTimeout: array_key_exists('startup_timeout', $options)
                ? self::seconds($options, 'startup_timeout') : $this->startupTimeout,
            cwd: array_key_exists('cwd', $options) ? self::nullableString($options, 'cwd') : $this->cwd,
            env: array_key_exists('env', $options) ? self::env($options) : $this->env,
            inheritEnv: array_key_exists('inherit_env', $options) ? self::bool($options, 'inherit_env') : $this->inheritEnv,
            paths: array_key_exists('paths', $options) ? self::paths($options) : $this->paths,
            output: array_key_exists('output', $options) ? self::output($options) : $this->output,
            maxMessageBytes: array_key_exists('max_message_bytes', $options)
                ? self::int($options, 'max_message_bytes') : $this->maxMessageBytes,
            logFile: array_key_exists('log_file', $options) ? self::nullableString($options, 'log_file') : $this->logFile,
            iterBatchSize: array_key_exists('iter_batch_size', $options)
                ? self::int($options, 'iter_batch_size') : $this->iterBatchSize,
            autoRestart: array_key_exists('auto_restart', $options) ? self::bool($options, 'auto_restart') : $this->autoRestart,
        );
    }

    /** @param array<string, mixed> $options */
    private static function string(array $options, string $key): string
    {
        $value = $options[$key];
        if (! is_string($value)) {
            throw self::invalid($key, 'a string', $value);
        }

        return $value;
    }

    /** @param array<string, mixed> $options */
    private static function nullableString(array $options, string $key): ?string
    {
        return $options[$key] === null ? null : self::string($options, $key);
    }

    /** @param array<string, mixed> $options */
    private static function seconds(array $options, string $key): float
    {
        $value = $options[$key];
        if (! is_int($value) && ! is_float($value)) {
            throw self::invalid($key, 'a number of seconds', $value);
        }

        return (float) $value;
    }

    /** @param array<string, mixed> $options */
    private static function nullableSeconds(array $options, string $key): ?float
    {
        return $options[$key] === null ? null : self::seconds($options, $key);
    }

    /** @param array<string, mixed> $options */
    private static function int(array $options, string $key): int
    {
        $value = $options[$key];
        if (! is_int($value)) {
            throw self::invalid($key, 'an integer', $value);
        }

        return $value;
    }

    /** @param array<string, mixed> $options */
    private static function bool(array $options, string $key): bool
    {
        $value = $options[$key];
        if (! is_bool($value)) {
            throw self::invalid($key, 'true or false', $value);
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, string>
     */
    private static function env(array $options): array
    {
        $value = $options['env'];
        if (! is_array($value)) {
            throw self::invalid('env', 'an array of name => string value', $value);
        }
        $env = [];
        foreach ($value as $name => $item) {
            if (! is_string($name) || ! is_string($item)) {
                throw self::invalid('env', 'an array of name => string value', $value);
            }
            $env[$name] = $item;
        }

        return $env;
    }

    /**
     * @param  array<string, mixed>  $options
     * @return list<string>
     */
    private static function paths(array $options): array
    {
        $value = $options['paths'];
        if (! is_array($value)) {
            throw self::invalid('paths', 'a list of directory paths', $value);
        }
        $paths = [];
        foreach ($value as $item) {
            if (! is_string($item)) {
                throw self::invalid('paths', 'a list of directory paths', $value);
            }
            $paths[] = $item;
        }

        return $paths;
    }

    /** @param array<string, mixed> $options */
    private static function output(array $options): OutputMode
    {
        $value = $options['output'];
        if ($value instanceof OutputMode) {
            return $value;
        }
        $mode = is_string($value) ? OutputMode::tryFrom($value) : null;
        if ($mode === null) {
            throw self::invalid('output', 'OutputMode::Echo, OutputMode::Capture, "echo" or "capture"', $value);
        }

        return $mode;
    }

    private static function invalid(string $key, string $expected, mixed $actual): ConfigException
    {
        return new ConfigException(sprintf('Option "%s" must be %s, got %s.', $key, $expected, get_debug_type($actual)));
    }
}
