<?php

declare(strict_types=1);

namespace PyHP\Setup;

use JsonException;
use PyHP\Exception\ConfigException;

/**
 * What `pyhp setup` should build: read from the "extra.pyhp" section of the
 * project's composer.json, for example
 *
 *     "extra": {"pyhp": {"python": "3.12", "requirements": ["pandas>=2"]}}
 *
 * "requirements" is either a list of packages or the path of a requirements file.
 * Without it, a requirements.txt in the project root is used if there is one.
 */
final readonly class SetupConfig
{
    public const DEFAULT_VENV = '.venv';

    public const DEFAULT_REQUIREMENTS_FILE = 'requirements.txt';

    private const KEYS = ['python', 'venv', 'requirements'];

    private const VERSION_PATTERN = '/^3\.\d{1,2}(\.\d{1,2})?$/';

    /**
     * @param  string|null  $python  Python version for the venv, e.g. "3.12"; null lets uv or the system choose.
     * @param  string  $venv  Folder of the virtual environment, relative to the project root or absolute.
     * @param  list<string>  $requirements  Packages to install, in pip syntax ("pandas>=2").
     * @param  string|null  $requirementsFile  A requirements file to install from, relative to the project root.
     */
    public function __construct(
        public ?string $python = null,
        public string $venv = self::DEFAULT_VENV,
        public array $requirements = [],
        public ?string $requirementsFile = null,
    ) {
        if ($python !== null && ! self::isVersion($python)) {
            throw new ConfigException(sprintf('The Python version must look like 3.12, got "%s".', $python));
        }
        if ($venv === '') {
            throw new ConfigException('The venv folder must not be empty.');
        }
    }

    /** Reads the settings of the project in $root (its composer.json and requirements.txt). */
    public static function fromProject(string $root): self
    {
        $settings = self::composerSettings($root);
        foreach (array_keys($settings) as $key) {
            if (! in_array($key, self::KEYS, true)) {
                throw new ConfigException(sprintf(
                    'Unknown key "%s" in composer.json extra.pyhp. Valid keys: %s.',
                    $key,
                    implode(', ', self::KEYS),
                ));
            }
        }

        $requirements = [];
        $requirementsFile = null;
        $listed = $settings['requirements'] ?? null;
        if (is_string($listed)) {
            $requirementsFile = $listed;
        } elseif ($listed !== null) {
            $requirements = self::packageList($listed);
        } elseif (is_file($root.DIRECTORY_SEPARATOR.self::DEFAULT_REQUIREMENTS_FILE)) {
            $requirementsFile = self::DEFAULT_REQUIREMENTS_FILE;
        }

        $python = self::optionalString($settings, 'python');
        if ($python !== null && ! self::isVersion($python)) {
            throw new ConfigException(sprintf('composer.json extra.pyhp.python must be a version like 3.12, got "%s".', $python));
        }
        $venv = self::optionalString($settings, 'venv') ?? self::DEFAULT_VENV;
        if ($venv === '') {
            throw new ConfigException('composer.json extra.pyhp.venv must not be empty.');
        }

        return new self($python, $venv, $requirements, $requirementsFile);
    }

    /** A copy with the command line's choices applied; null keeps the current value. */
    public function with(?string $python = null, ?string $venv = null): self
    {
        if ($python !== null && ! self::isVersion($python)) {
            throw new ConfigException(sprintf('--python must be a version like 3.12, got "%s".', $python));
        }

        return new self($python ?? $this->python, $venv ?? $this->venv, $this->requirements, $this->requirementsFile);
    }

    /** The venv folder as an absolute path. */
    public function venvPath(string $root): string
    {
        return self::isAbsolute($this->venv) ? $this->venv : rtrim($root, '/\\').DIRECTORY_SEPARATOR.$this->venv;
    }

    /** @return array<array-key, mixed> */
    private static function composerSettings(string $root): array
    {
        $file = $root.DIRECTORY_SEPARATOR.'composer.json';
        if (! is_file($file)) {
            return [];
        }

        $contents = file_get_contents($file);
        try {
            $composer = json_decode($contents === false ? '' : $contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new ConfigException(sprintf('Could not read %s: %s.', $file, $e->getMessage()), 0, $e);
        }

        $settings = is_array($composer) && is_array($composer['extra'] ?? null) ? ($composer['extra']['pyhp'] ?? []) : [];
        if (! is_array($settings) || ($settings !== [] && array_is_list($settings))) {
            throw new ConfigException('composer.json extra.pyhp must be an object, like {"requirements": ["pandas"]}.');
        }

        return $settings;
    }

    /** @return list<string> */
    private static function packageList(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new ConfigException('composer.json extra.pyhp.requirements must be a list of packages or the path of a requirements file.');
        }
        $packages = [];
        foreach ($value as $package) {
            if (! is_string($package) || $package === '') {
                throw new ConfigException('composer.json extra.pyhp.requirements must contain package names as strings, like "pandas>=2".');
            }
            $packages[] = $package;
        }

        return $packages;
    }

    /** @param array<array-key, mixed> $settings */
    private static function optionalString(array $settings, string $key): ?string
    {
        $value = $settings[$key] ?? null;
        if ($value !== null && ! is_string($value)) {
            throw new ConfigException(sprintf('composer.json extra.pyhp.%s must be a string, got %s.', $key, get_debug_type($value)));
        }

        return $value;
    }

    private static function isVersion(string $version): bool
    {
        return preg_match(self::VERSION_PATTERN, $version) === 1;
    }

    private static function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/') || str_starts_with($path, '\\') || preg_match('/^[A-Za-z]:[\/\\\\]/', $path) === 1;
    }
}
