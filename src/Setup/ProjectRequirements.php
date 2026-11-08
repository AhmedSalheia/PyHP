<?php

declare(strict_types=1);

namespace PyHP\Setup;

use JsonException;
use PyHP\Exception\ConfigException;

/**
 * Every Python requirement of a project: its own (extra.pyhp in composer.json, or a
 * requirements file) plus those declared by installed Composer packages in their
 * own extra.pyhp, read from vendor/composer/installed.json.
 */
final class ProjectRequirements
{
    /** Origin of the requirements the root project declares itself. */
    public const ROOT = 'your project';

    /** PyHP's own extra.pyhp is for developing PyHP, never for the projects using it. */
    private const SELF = 'pyhp/pyhp';

    /**
     * @param  list<Requirement>  $requirements  installed one by one, in this order
     * @param  ?string  $rootFile  the root project's requirements file, installed with -r
     * @param  list<Requirement>  $rootFileRequirements  what that file lists, for names and reports
     * @param  list<string>  $overrides
     * @param  list<string>  $ignored  ignored packages that are installed
     */
    private function __construct(
        private readonly array $requirements,
        private readonly ?string $rootFile,
        private readonly array $rootFileRequirements,
        private readonly array $overrides,
        private readonly array $ignored,
    ) {}

    public static function collect(string $root, SetupConfig $config): self
    {
        $requirements = array_map(
            static fn (string $spec): Requirement => new Requirement($spec, self::ROOT),
            $config->requirements,
        );

        $rootFileRequirements = [];
        if ($config->requirementsFile !== null) {
            $file = $root.DIRECTORY_SEPARATOR.$config->requirementsFile;
            if (is_file($file)) {
                foreach (Requirement::linesOf((string) file_get_contents($file)) as $line) {
                    if (! str_starts_with($line, '-')) {
                        $rootFileRequirements[] = new Requirement($line, self::ROOT);
                    }
                }
            }
        }

        $ignored = [];
        foreach (self::installedPackages($root, $config->vendorDir) as $package) {
            if ($package['name'] === self::SELF || $package['pyhp'] === null) {
                continue;
            }
            if (in_array($package['name'], $config->ignorePackages, true)) {
                $ignored[] = $package['name'];

                continue;
            }
            foreach (self::packageRequirements($package['name'], $package['pyhp'], $package['path']) as $spec) {
                $requirements[] = new Requirement($spec, $package['name']);
            }
        }

        return new self($requirements, $config->requirementsFile, $rootFileRequirements, $config->overrides, $ignored);
    }

    /** @return list<Requirement> every requirement, the project's own first */
    public function all(): array
    {
        return [...$this->rootFileRequirements, ...$this->requirements];
    }

    /** @return list<Requirement> only the requirements that come from Composer packages */
    public function fromPackages(): array
    {
        return array_values(array_filter($this->requirements, static fn (Requirement $r): bool => $r->origin !== self::ROOT));
    }

    public function isEmpty(): bool
    {
        return $this->requirements === [] && $this->rootFile === null;
    }

    /** @return list<string> arguments for `pip install` / `uv pip install` (identical requirements once) */
    public function installArguments(): array
    {
        $specs = array_values(array_unique(array_map(static fn (Requirement $r): string => $r->spec, $this->requirements)));

        return $this->rootFile === null ? $specs : ['-r', $this->rootFile, ...$specs];
    }

    /** @return list<string> the distribution names, as first written, each once */
    public function names(): array
    {
        $names = [];
        foreach ($this->all() as $requirement) {
            $names[$requirement->name] ??= Requirement::rawName($requirement->spec) ?? $requirement->spec;
        }

        return array_values($names);
    }

    /** @return array<string, list<Requirement>> normalized name => its requirements, for names asked for by more than one origin */
    public function shared(): array
    {
        $byName = [];
        foreach ($this->all() as $requirement) {
            $byName[$requirement->name][] = $requirement;
        }

        return array_filter(
            $byName,
            static fn (array $list): bool => count(array_unique(array_map(static fn (Requirement $r): string => $r->origin, $list))) > 1,
        );
    }

    /** @return list<string> */
    public function overrides(): array
    {
        return $this->overrides;
    }

    /** @return list<string> packages in extra.pyhp.ignore-packages that are installed and declare requirements */
    public function ignored(): array
    {
        return $this->ignored;
    }

    /** @return list<array{name: string, path: string, pyhp: mixed}> installed packages; pyhp is their extra.pyhp, or null */
    private static function installedPackages(string $root, string $vendorDir): array
    {
        $composerDir = rtrim($root, '/\\').DIRECTORY_SEPARATOR.$vendorDir.DIRECTORY_SEPARATOR.'composer';
        $file = $composerDir.DIRECTORY_SEPARATOR.'installed.json';
        if (! is_file($file)) {
            return [];
        }
        try {
            $data = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new ConfigException("Could not read {$file}: {$e->getMessage()}. Run composer install again.", 0, $e);
        }
        $packages = is_array($data) && isset($data['packages']) ? $data['packages'] : $data;

        $found = [];
        foreach (is_array($packages) ? $packages : [] as $package) {
            if (! is_array($package) || ! is_string($package['name'] ?? null)) {
                continue;
            }
            $extra = $package['extra'] ?? null;
            $installPath = is_string($package['install-path'] ?? null)
                ? $composerDir.DIRECTORY_SEPARATOR.$package['install-path']
                : dirname($composerDir).DIRECTORY_SEPARATOR.$package['name'];
            $found[] = [
                'name' => $package['name'],
                'path' => $installPath,
                'pyhp' => is_array($extra) && array_key_exists('pyhp', $extra) ? $extra['pyhp'] : null,
            ];
        }

        return $found;
    }

    /** @return list<string> */
    private static function packageRequirements(string $package, mixed $pyhp, string $path): array
    {
        $invalid = static fn (string $problem): ConfigException => new ConfigException(
            "The Composer package {$package} declares invalid Python requirements in its extra.pyhp: {$problem}. "
            ."To skip them, add \"{$package}\" to extra.pyhp.ignore-packages in your composer.json."
        );
        if (! is_array($pyhp) || ($pyhp !== [] && array_is_list($pyhp))) {
            throw $invalid('extra.pyhp must be an object');
        }
        $listed = $pyhp['requirements'] ?? [];

        if (is_string($listed)) {
            $file = $path.DIRECTORY_SEPARATOR.$listed;
            if (! is_file($file)) {
                throw $invalid("its requirements file {$listed} doesn't exist");
            }
            $lines = Requirement::linesOf((string) file_get_contents($file));
            foreach ($lines as $line) {
                if (str_starts_with($line, '-')) {
                    throw $invalid("its requirements file {$listed} uses the pip option \"{$line}\", which packages can't use");
                }
            }

            return $lines;
        }
        if (! is_array($listed) || ! array_is_list($listed)) {
            throw $invalid('requirements must be a list of packages or the path of a requirements file');
        }
        $specs = [];
        foreach ($listed as $spec) {
            if (! is_string($spec) || trim($spec) === '') {
                throw $invalid('every requirement must be a string like "spacy>=3.8"');
            }
            $specs[] = $spec;
        }

        return $specs;
    }
}
