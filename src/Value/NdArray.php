<?php

declare(strict_types=1);

namespace PyHP\Value;

use PyHP\Exception\ConversionException;

/**
 * Sent to Python as a NumPy array of the given dtype, as raw bytes instead of
 * JSON. Create with Py::ndarray($data, 'float64').
 */
final readonly class NdArray
{
    public const DTYPES = ['float64', 'float32', 'int64', 'int32', 'int16', 'int8', 'uint32', 'uint16', 'uint8', 'bool'];

    /**
     * @param  array<array-key, mixed>  $data  nested lists of numbers (or bools), as rectangular as NumPy needs them
     */
    public function __construct(public array $data, public string $dtype = 'float64')
    {
        if (! in_array($dtype, self::DTYPES, true)) {
            throw new ConversionException(sprintf(
                'Py::ndarray() does not know the dtype "%s"; use one of: %s.',
                $dtype,
                implode(', ', self::DTYPES),
            ));
        }
    }
}
