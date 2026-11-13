<?php

declare(strict_types=1);

namespace PyHP\Value;

use Closure;
use InvalidArgumentException;

/**
 * A PHP closure that Python applies to data in chunks: one round trip per chunk
 * instead of one per value. Create with Py::vectorize().
 */
final readonly class Vectorized
{
    /**
     * The chunk closure PyHP sends to Python: your closure, wrapped.
     *
     * @internal Used by PyHP's codec; may change in any release.
     *
     * @var Closure(list<mixed>): array<array-key, mixed> receives a chunk, returns one value per item
     */
    public Closure $callback;

    /**
     * @param  Closure  $callback  a chunk closure (array in, array out), or a per-value closure with $elementwise
     */
    public function __construct(Closure $callback, bool $elementwise = false, public int $batchSize = 1000)
    {
        if ($batchSize < 1) {
            throw new InvalidArgumentException("The batch size must be at least 1, got {$batchSize}.");
        }
        $this->callback = $elementwise
            ? static fn (array $chunk): array => array_map($callback, $chunk)
            : static function (array $chunk) use ($callback): array {
                $result = $callback($chunk);

                return is_array($result) ? $result : [$result];
            };
    }
}
