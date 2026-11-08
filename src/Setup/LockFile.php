<?php

declare(strict_types=1);

namespace PyHP\Setup;

use JsonException;
use RuntimeException;

/**
 * pyhp.lock: the exact version of every Python package a project needs, written
 * by `pyhp lock` and installed by `pyhp setup`. A header line records a
 * fingerprint of the requirements it was resolved from, so setup and info can
 * tell when it's out of date.
 */
final class LockFile
{
    public const NAME = 'pyhp.lock';

    private const FINGERPRINT_PREFIX = '# pyhp-fingerprint: ';

    /** Everything that changes the resolution: requirements (and file contents), overrides, Python version. */
    public static function fingerprint(string $root, SetupConfig $config, ProjectRequirements $requirements): string
    {
        $file = $config->requirementsFile === null ? null : $root.DIRECTORY_SEPARATOR.$config->requirementsFile;

        return hash('sha256', (string) json_encode([
            'requirements' => $requirements->installArguments(),
            'file' => $file !== null && is_file($file) ? file_get_contents($file) : null,
            'overrides' => $requirements->overrides(),
            'python' => $config->python,
        ]));
    }

    public static function withHeader(string $pinned, string $fingerprint, string $resolver): string
    {
        return "# pyhp.lock: exact versions of every Python package this project needs.\n"
            ."# Written by `vendor/bin/pyhp lock` (resolved with {$resolver}). Commit it; don't edit it.\n"
            .self::FINGERPRINT_PREFIX.$fingerprint."\n"
            .$pinned;
    }

    public static function fingerprintIn(string $contents): ?string
    {
        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            if (str_starts_with($line, self::FINGERPRINT_PREFIX)) {
                return trim(substr($line, strlen(self::FINGERPRINT_PREFIX)));
            }
        }

        return null;
    }

    /** Turns the JSON of `pip install --dry-run --report` into sorted "name==version" lines. */
    public static function fromPipReport(string $json): string
    {
        try {
            $report = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException("pip wrote an unreadable report: {$e->getMessage()}", 0, $e);
        }
        $install = is_array($report) ? ($report['install'] ?? null) : null;
        if (! is_array($install)) {
            throw new RuntimeException('pip wrote a report without the list of packages to install.');
        }
        $lines = [];
        foreach ($install as $entry) {
            $metadata = is_array($entry) ? ($entry['metadata'] ?? null) : null;
            $name = is_array($metadata) ? ($metadata['name'] ?? null) : null;
            $version = is_array($metadata) ? ($metadata['version'] ?? null) : null;
            if (! is_string($name) || ! is_string($version)) {
                throw new RuntimeException('pip wrote a report entry without a package name and version.');
            }
            $lines[] = Requirement::normalize($name).'=='.$version;
        }
        sort($lines);

        return implode("\n", $lines)."\n";
    }

    /** @return list<string> normalized names of the packages a lock pins */
    public static function packageNames(string $contents): array
    {
        $names = [];
        foreach (Requirement::linesOf($contents) as $line) {
            $name = str_starts_with($line, '-') ? null : Requirement::rawName($line);
            if ($name !== null) {
                $names[] = Requirement::normalize($name);
            }
        }

        return $names;
    }
}
