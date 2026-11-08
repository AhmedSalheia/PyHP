<?php

declare(strict_types=1);

namespace PyHP\Setup;

/** One Python requirement in pip syntax ("pandas>=2") and who asked for it. */
final readonly class Requirement
{
    /** The distribution name in its normalized form (PEP 503): "Scikit_Learn" -> "scikit-learn". */
    public string $name;

    public function __construct(public string $spec, public string $origin)
    {
        $this->name = self::normalize(self::rawName($spec) ?? $spec);
    }

    /** The name as written at the start of a requirement ("spacy[ja]>=3" -> "spacy"), or null if there is none. */
    public static function rawName(string $spec): ?string
    {
        return preg_match('/^\s*([A-Za-z0-9][A-Za-z0-9._-]*)/', $spec, $match) === 1 ? $match[1] : null;
    }

    public static function normalize(string $name): string
    {
        return strtolower((string) preg_replace('/[-_.]+/', '-', $name));
    }

    /**
     * The requirement lines of a requirements file, without comments and blank lines.
     *
     * @return list<string> requirement lines; lines starting with "-" (pip options) are returned as they are
     */
    public static function linesOf(string $contents): array
    {
        $lines = [];
        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            $line = trim((string) preg_replace('/(^|\s)#.*$/', '', $line));
            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return $lines;
    }
}
