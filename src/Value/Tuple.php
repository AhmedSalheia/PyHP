<?php

declare(strict_types=1);

namespace PyHP\Value;

/**
 * Sent to Python as a tuple. Create with Py::tuple(...).
 *
 * Needed for multi-axis indexing, e.g. $df->loc[Py::tuple($mask, 'col')].
 */
final readonly class Tuple
{
    /** @var list<mixed> */
    public array $items;

    public function __construct(mixed ...$items)
    {
        $this->items = array_values($items);
    }
}
