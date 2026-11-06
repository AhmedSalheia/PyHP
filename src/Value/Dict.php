<?php

declare(strict_types=1);

namespace PyHP\Value;

/**
 * Sent to Python as a dict even when the array is empty or list-shaped.
 * Create with Py::dict([...]).
 */
final readonly class Dict
{
    /**
     * @param  array<array-key, mixed>  $items
     */
    public function __construct(public array $items) {}
}
