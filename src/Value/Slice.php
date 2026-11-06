<?php

declare(strict_types=1);

namespace PyHP\Value;

/** Sent to Python as slice(start, stop, step). Create with Py::slice(...). */
final readonly class Slice
{
    public function __construct(
        public ?int $start = null,
        public ?int $stop = null,
        public ?int $step = null,
    ) {}
}
