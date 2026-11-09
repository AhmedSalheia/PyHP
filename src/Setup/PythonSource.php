<?php

declare(strict_types=1);

namespace PyHP\Setup;

/**
 * Why PyHP picked an interpreter when the "python" option wasn't set.
 *
 * @internal Part of the pyhp command line, not of the public API: use `vendor/bin/pyhp` instead.
 */
enum PythonSource: string
{
    case EnvironmentVariable = 'environment';
    case ProjectVenv = 'venv';
    case PlatformDefault = 'default';

    public function describe(): string
    {
        return match ($this) {
            self::EnvironmentVariable => 'the PYHP_PYTHON environment variable',
            self::ProjectVenv => "the project's virtual environment",
            self::PlatformDefault => 'the default command on PATH (no PYHP_PYTHON, no project venv)',
        };
    }
}
