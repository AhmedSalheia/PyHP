<?php

declare(strict_types=1);

namespace PyHP\Internal;

use PyHP\Exception\ConversionException;
use PyHP\Exception\ProtocolException;
use PyHP\Value\NdArray;

/**
 * NumPy arrays on the wire: nested PHP lists <-> raw little-endian bytes.
 *
 * pack() and unpack() do the per-value work in C, which is what makes this much
 * faster than JSON. The signed formats (q, l, s) use the machine's byte order;
 * PyHP only runs on little-endian machines (x86-64, ARM64).
 *
 * @internal
 */
final class ArrayPacker
{
    /** NdArray dtype => [wire dtype, pack() format, smallest int, largest int] */
    private const FORMATS = [
        'float64' => ['f8', 'e', null, null],
        'float32' => ['f4', 'g', null, null],
        'int64' => ['i8', 'q', PHP_INT_MIN, PHP_INT_MAX],
        'int32' => ['i4', 'l', -2147483648, 2147483647],
        'int16' => ['i2', 's', -32768, 32767],
        'int8' => ['i1', 'c', -128, 127],
        'uint32' => ['u4', 'V', 0, 4294967295],
        'uint16' => ['u2', 'v', 0, 65535],
        'uint8' => ['u1', 'C', 0, 255],
        'bool' => ['b1', 'C', null, null],
    ];

    /** wire dtype => [unpack() format, bytes per value] */
    private const WIRE = [
        'f8' => ['e', 8], 'f4' => ['g', 4],
        'i8' => ['q', 8], 'i4' => ['l', 4], 'i2' => ['s', 2], 'i1' => ['c', 1],
        'u4' => ['V', 4], 'u2' => ['v', 2], 'u1' => ['C', 1],
        'b1' => ['C', 1],
    ];

    private const RAGGED = 'Py::ndarray() needs a rectangular array: all rows at the same depth must have the same length.';

    /**
     * @return array{0: string, 1: list<int>, 2: string} wire dtype, shape, bytes
     */
    public static function pack(NdArray $array): array
    {
        [$wire, $format, $min, $max] = self::FORMATS[$array->dtype];
        $shape = self::shapeOf($array->data);
        $rows = [];
        self::collectRows($array->data, $shape, 0, $rows);
        $values = $rows === [] ? [] : array_merge(...$rows);
        self::check($values, $array->dtype, $min, $max);
        if ($array->dtype === 'bool') {
            $values = array_map(static fn (mixed $value): int => $value === true ? 1 : 0, $values);
        }

        return [$wire, $shape, $values === [] ? '' : pack($format.'*', ...$values)];
    }

    /**
     * @param  list<int>  $shape
     */
    public static function unpack(string $dtype, array $shape, string $bytes): mixed
    {
        if (! isset(self::WIRE[$dtype])) {
            throw new ProtocolException('The worker sent an array of unknown dtype '.json_encode($dtype).'.');
        }
        [$format, $size] = self::WIRE[$dtype];
        $count = (int) array_product($shape);
        if (strlen($bytes) !== $count * $size) {
            throw new ProtocolException(sprintf(
                'The worker sent %d bytes for an array of shape %s and dtype %s, which needs %d.',
                strlen($bytes),
                json_encode($shape),
                $dtype,
                $count * $size,
            ));
        }
        if ($count === 0) {
            return self::empty($shape);
        }
        $values = array_values((array) unpack($format.'*', $bytes));
        if ($dtype === 'b1') {
            $values = array_map(static fn (mixed $value): bool => $value !== 0, $values);
        }
        if ($shape === []) {
            return $values[0];
        }
        for ($axis = count($shape) - 1; $axis > 0; $axis--) {
            $values = array_chunk($values, max(1, $shape[$axis])); // every size is >= 1 here: count > 0
        }

        return $values;
    }

    /**
     * The shape, read from the first item at each depth; collectRows() checks the rest.
     *
     * @param  array<array-key, mixed>  $data
     * @return list<int>
     */
    private static function shapeOf(array $data): array
    {
        $shape = [];
        $node = $data;
        while (is_array($node)) {
            if (! array_is_list($node)) {
                throw new ConversionException('Py::ndarray() needs lists (keys 0, 1, 2, ...); use array_values() on arrays with other keys.');
            }
            $shape[] = count($node);
            if ($node === []) {
                break;
            }
            $node = $node[0];
        }

        return $shape;
    }

    /**
     * Gathers the innermost rows in order, checking every row against the shape.
     *
     * @param  list<int>  $shape
     * @param  list<list<mixed>>  $rows
     */
    private static function collectRows(mixed $node, array $shape, int $depth, array &$rows): void
    {
        if (! is_array($node) || ! array_is_list($node) || count($node) !== $shape[$depth]) {
            throw new ConversionException(is_array($node) && ! array_is_list($node)
                ? 'Py::ndarray() needs lists (keys 0, 1, 2, ...); use array_values() on arrays with other keys.'
                : self::RAGGED);
        }
        if ($depth === count($shape) - 1) {
            $rows[] = $node;

            return;
        }
        foreach ($node as $child) {
            self::collectRows($child, $shape, $depth + 1, $rows);
        }
    }

    /**
     * @param  list<mixed>  $values
     */
    private static function check(array $values, string $dtype, ?int $min, ?int $max): void
    {
        foreach ($values as $value) {
            $ok = match (true) {
                $dtype === 'bool' => is_bool($value),
                $min === null || $max === null => is_int($value) || is_float($value),
                default => is_int($value) && $value >= $min && $value <= $max,
            };
            if (! $ok) {
                throw new ConversionException(is_array($value) ? self::RAGGED : sprintf(
                    'Py::ndarray() with dtype %s needs %s, got %s.',
                    $dtype,
                    match (true) {
                        $dtype === 'bool' => 'bools',
                        $min === null => 'ints or floats',
                        default => "ints from {$min} to {$max}",
                    },
                    self::describe($value),
                ));
            }
        }
    }

    private static function describe(mixed $value): string
    {
        return is_scalar($value) ? get_debug_type($value).' '.var_export($value, true) : get_debug_type($value);
    }

    /**
     * Nested empty lists for a shape with a zero in it.
     *
     * @param  list<int>  $shape
     * @return list<mixed>
     */
    private static function empty(array $shape): array
    {
        if ($shape === [] || $shape[0] === 0 || count($shape) === 1) {
            return [];
        }
        $inner = self::empty(array_slice($shape, 1));

        return array_fill(0, $shape[0], $inner);
    }
}
