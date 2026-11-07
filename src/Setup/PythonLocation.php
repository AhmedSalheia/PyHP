<?php

declare(strict_types=1);

namespace PyHP\Setup;

/** The interpreter PyHP would use, why, and where the project's venv is expected. */
final readonly class PythonLocation
{
    public function __construct(
        public string $python,
        public PythonSource $source,
        public ?string $venv,
    ) {}
}
