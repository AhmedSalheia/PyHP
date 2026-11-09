<?php

declare(strict_types=1);

namespace PyHP\Setup;

/**
 * Where things live inside a Python virtual environment.
 *
 * @internal Part of the pyhp command line, not of the public API: use `vendor/bin/pyhp` instead.
 */
final class VirtualEnv
{
    /** The interpreter of the virtual environment in $directory. */
    public static function python(string $directory, string $osFamily = PHP_OS_FAMILY): string
    {
        $parts = $osFamily === 'Windows' ? ['Scripts', 'python.exe'] : ['bin', 'python'];

        return rtrim($directory, '/\\').DIRECTORY_SEPARATOR.implode(DIRECTORY_SEPARATOR, $parts);
    }
}
