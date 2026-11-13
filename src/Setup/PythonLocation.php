<?php

declare(strict_types=1);

namespace PyHP\Setup;

/**
 * The interpreter PyHP would use, why, and where the project's venv is expected.
 *
 * @internal Part of the pyhp command line, not of the public API: use `vendor/bin/pyhp` instead.
 */
final readonly class PythonLocation
{
    public function __construct(
        public string $python,
        public PythonSource $source,
        public ?string $venv,
    ) {}
}
