<?php

declare(strict_types=1);

use PyHP\Exception\ConversionException;
use PyHP\Exception\MessageTooLargeException;
use PyHP\Exception\ProtocolException;
use PyHP\Internal\ArrayPacker;
use PyHP\Py;
use PyHP\Value\NdArray;

/*
 * NumPy arrays as binary frames: Py::toPhp() of an array copies its bytes and
 * unpacks them in PHP; Py::ndarray() packs a PHP array the other way.
 */

// -- packing and unpacking ------------------------------------------------------ //

test('every dtype packs to little-endian bytes', function (string $dtype, string $wire, array $data, string $bytes): void {
    expect(ArrayPacker::pack(new NdArray($data, $dtype)))->toBe([$wire, [count($data)], $bytes]);
})->with([
    'float64' => ['float64', 'f8', [1.5, -2], pack('e*', 1.5, -2.0)],
    'float32' => ['float32', 'f4', [1.5], pack('g', 1.5)],
    'int64' => ['int64', 'i8', [PHP_INT_MIN, PHP_INT_MAX], pack('P*', PHP_INT_MIN, PHP_INT_MAX)],
    'int32' => ['int32', 'i4', [-2147483648, 2147483647], "\x00\x00\x00\x80\xff\xff\xff\x7f"],
    'int16' => ['int16', 'i2', [-32768, 32767], "\x00\x80\xff\x7f"],
    'int8' => ['int8', 'i1', [-128, 127], "\x80\x7f"],
    'uint32' => ['uint32', 'u4', [0, 4294967295], "\x00\x00\x00\x00\xff\xff\xff\xff"],
    'uint16' => ['uint16', 'u2', [0, 65535], "\x00\x00\xff\xff"],
    'uint8' => ['uint8', 'u1', [0, 255], "\x00\xff"],
    'bool' => ['bool', 'b1', [true, false], "\x01\x00"],
]);

test('nested lists give the shape, row after row', function (): void {
    expect(ArrayPacker::pack(new NdArray([[[1, 2], [3, 4], [5, 6]]], 'int8')))->toBe(['i1', [1, 3, 2], "\x01\x02\x03\x04\x05\x06"])
        ->and(ArrayPacker::pack(new NdArray([], 'float64')))->toBe(['f8', [0], ''])
        ->and(ArrayPacker::pack(new NdArray([[], []], 'float64')))->toBe(['f8', [2, 0], '']);
});

test('arrays NumPy cannot take are refused with what to change', function (array $data, string $dtype, string $message): void {
    expect(fn () => ArrayPacker::pack(new NdArray($data, $dtype)))->toThrow(ConversionException::class, $message);
})->with([
    'keys' => [['a' => 1.0], 'float64', 'Py::ndarray() needs lists (keys 0, 1, 2, ...); use array_values() on arrays with other keys.'],
    'ragged' => [[[1, 2], [3]], 'float64', 'Py::ndarray() needs a rectangular array: all rows at the same depth must have the same length.'],
    'deeper row' => [[[1, 2], [3, [4]]], 'float64', 'Py::ndarray() needs a rectangular array: all rows at the same depth must have the same length.'],
    'shallower row' => [[[1, 2], 3], 'float64', 'Py::ndarray() needs a rectangular array: all rows at the same depth must have the same length.'],
    'string for float' => [[1.0, 'x'], 'float64', "Py::ndarray() with dtype float64 needs ints or floats, got string 'x'."],
    'float for int' => [[1, 1.5], 'int32', 'Py::ndarray() with dtype int32 needs ints from -2147483648 to 2147483647, got float 1.5.'],
    'int out of range' => [[300], 'int8', 'Py::ndarray() with dtype int8 needs ints from -128 to 127, got int 300.'],
    'negative unsigned' => [[-1], 'uint16', 'Py::ndarray() with dtype uint16 needs ints from 0 to 65535, got int -1.'],
    'int for bool' => [[1], 'bool', 'Py::ndarray() with dtype bool needs bools, got int 1.'],
    'null' => [[null], 'float64', 'Py::ndarray() with dtype float64 needs ints or floats, got null.'],
]);

test('an unknown dtype is refused with the ones that exist', function (): void {
    expect(fn () => Py::ndarray([1], 'complex128'))->toThrow(
        ConversionException::class,
        'Py::ndarray() does not know the dtype "complex128"; use one of: float64, float32, int64, int32, int16, int8, uint32, uint16, uint8, bool.',
    );
});

test('unpacking restores the shape, including empty dimensions', function (): void {
    expect(ArrayPacker::unpack('i1', [2, 3], "\x01\x02\x03\x04\x05\x06"))->toBe([[1, 2, 3], [4, 5, 6]])
        ->and(ArrayPacker::unpack('i1', [3, 1, 2], "\x01\x02\x03\x04\x05\x06"))->toBe([[[1, 2]], [[3, 4]], [[5, 6]]])
        ->and(ArrayPacker::unpack('f8', [0], ''))->toBe([])
        ->and(ArrayPacker::unpack('f8', [2, 0], ''))->toBe([[], []])
        ->and(ArrayPacker::unpack('f8', [2, 0, 3], ''))->toBe([[], []])
        ->and(ArrayPacker::unpack('f8', [0, 2], ''))->toBe([])
        ->and(ArrayPacker::unpack('f8', [], pack('e', 2.5)))->toBe(2.5)
        ->and(ArrayPacker::unpack('b1', [3], "\x01\x00\x01"))->toBe([true, false, true]);
});

test('packing and unpacking are inverse for every dtype', function (string $dtype, array $data): void {
    [$wire, $shape, $bytes] = ArrayPacker::pack(new NdArray($data, $dtype));

    expect(ArrayPacker::unpack($wire, $shape, $bytes))->toBe($data);
})->with([
    'float64' => ['float64', [[0.1, -2.5], [INF, -INF]]],
    'float32' => ['float32', [0.5, -2.25]],
    'int64' => ['int64', [PHP_INT_MIN, -1, 0, PHP_INT_MAX]],
    'int32' => ['int32', [-2147483648, 2147483647]],
    'int16' => ['int16', [-32768, -1, 32767]],
    'int8' => ['int8', [[-128], [127]]],
    'uint32' => ['uint32', [0, 4294967295]],
    'uint16' => ['uint16', [0, 65535]],
    'uint8' => ['uint8', [0, 255]],
    'bool' => ['bool', [[true, false], [false, true]]],
]);

test('NaN survives packing and unpacking', function (): void {
    [$wire, $shape, $bytes] = ArrayPacker::pack(new NdArray([NAN], 'float64'));

    expect(is_nan(ArrayPacker::unpack($wire, $shape, $bytes)[0]))->toBeTrue();
});

test('broken array data from the worker is a protocol error', function (string $dtype, array $shape, string $bytes, string $message): void {
    expect(fn () => ArrayPacker::unpack($dtype, $shape, $bytes))->toThrow(ProtocolException::class, $message);
})->with([
    'unknown dtype' => ['c16', [1], str_repeat("\0", 16), 'The worker sent an array of unknown dtype "c16".'],
    'wrong size' => ['f8', [2], str_repeat("\0", 8), 'The worker sent 8 bytes for an array of shape [2] and dtype f8, which needs 16.'],
]);

// -- through the worker ------------------------------------------------------- //

test('Py::toPhp copies NumPy arrays of every numeric dtype', function (string $dtype): void {
    $py = testSession();
    $py->exec("import numpy as np\na = np.array([[1, 0, 1], [0, 1, 0]], dtype='{$dtype}')");

    expect(Py::toPhp($py->eval('a')))->toBe($py->eval('a.tolist()'));
})->with(['float64', 'float32', 'float16', 'int64', 'int32', 'int16', 'int8', 'uint64', 'uint32', 'uint16', 'uint8', 'bool'])->skip(fn () => ! pythonHasModule('numpy'), 'numpy is not installed');

test('Py::toPhp keeps shape, special floats, empty arrays and arrays inside other values', function (): void {
    $py = testSession();
    $py->exec(<<<'PY'
import numpy as np
cube = np.arange(24, dtype='int32').reshape(2, 3, 4)
special = np.array([np.nan, np.inf, -np.inf, 1.5])
empty = np.zeros((2, 0))
class Box:
    mixed = {'a': np.arange(3), 'b': [np.ones(2, dtype=bool), 'text'], 'c': np.array(['x', 'y'])}
box = Box()
scalar = np.array(7.5)
transposed = np.arange(6).reshape(2, 3).T
PY);

    $special = Py::toPhp($py->eval('special'));
    expect(Py::toPhp($py->eval('cube')))->toBe($py->eval('cube.tolist()'))
        ->and(is_nan($special[0]))->toBeTrue()
        ->and(array_slice($special, 1))->toBe([INF, -INF, 1.5])
        ->and(Py::toPhp($py->eval('empty')))->toBe([[], []])
        ->and(Py::toPhp(Py::ref($py->eval('box'), 'mixed')))->toBe(['a' => [0, 1, 2], 'b' => [[true, true], 'text'], 'c' => ['x', 'y']])
        ->and(Py::toPhp($py->eval('scalar')))->toBe(7.5)
        ->and(Py::toPhp($py->eval('transposed')))->toBe([[0, 3], [1, 4], [2, 5]]);
})->skip(fn () => ! pythonHasModule('numpy'), 'numpy is not installed');

test('Py::toPhp refuses arrays whose values PHP cannot hold', function (): void {
    $py = testSession();
    $py->exec("import numpy as np\nbig = np.array([2**63], dtype='uint64')\ncomplex_ = np.array([1+2j])");

    expect(fn () => Py::toPhp($py->eval('big')))->toThrow(
        ConversionException::class,
        'cannot convert a numpy.ndarray of uint64 values above 9223372036854775807 (the largest PHP int)',
    )->and(fn () => Py::toPhp($py->eval('complex_')))->toThrow(ConversionException::class, 'cannot convert a complex to a PHP value');
})->skip(fn () => ! pythonHasModule('numpy'), 'numpy is not installed');

test('Py::ndarray sends a PHP array as a NumPy array of the given dtype', function (): void {
    $py = testSession();
    $py->exec('import numpy as np');
    $py->vars->m = Py::ndarray([[1, 2, 3], [4, 5, 6]], 'int16');
    $py->vars->f = Py::ndarray([0.5, 1.5]);

    expect($py->eval('type(m).__name__'))->toBe('ndarray')
        ->and($py->eval('str(m.dtype)'))->toBe('int16')
        ->and($py->eval('list(m.shape)'))->toBe([2, 3])
        ->and($py->eval('m.tolist()'))->toBe([[1, 2, 3], [4, 5, 6]])
        ->and($py->eval('str(f.dtype)'))->toBe('float64')
        ->and($py->eval('m.flags.writeable'))->toBeTrue();
})->skip(fn () => ! pythonHasModule('numpy'), 'numpy is not installed');

test('Py::ndarray works as a call argument, a keyword argument and inside other values', function (): void {
    $py = testSession();
    $py->exec("import numpy as np\ndef total(a, *, b):\n    return float(a.sum() + b['x'].sum())");
    $total = $py->eval('total');

    expect($total(Py::ndarray([1, 2]), b: ['x' => Py::ndarray([[3, 4]], 'int8')]))->toBe(10.0);
})->skip(fn () => ! pythonHasModule('numpy'), 'numpy is not installed');

test('a PHP callback can return Py::ndarray to Python', function (): void {
    $py = testSession();
    $py->exec("import numpy as np\ndef run(f):\n    a = f()\n    return [str(a.dtype), a.tolist()]");

    expect($py->eval('run')(fn () => Py::ndarray([true, false], 'bool')))->toBe(['bool', [true, false]]);
})->skip(fn () => ! pythonHasModule('numpy'), 'numpy is not installed');

test('a large array makes the round trip intact', function (): void {
    $py = testSession(['max_message_bytes' => 64 * 1024 * 1024]);
    $py->exec("import numpy as np\nrng = np.random.default_rng(7)\nbig = rng.random((1000, 1000))");

    $copy = Py::toPhp($py->eval('big'));
    $py->vars->back = Py::ndarray($copy);

    expect(count($copy))->toBe(1000)
        ->and(count($copy[999]))->toBe(1000)
        ->and($copy[123][456])->toBe($py->eval('float(big[123, 456])'))
        ->and($py->eval('bool((back == big).all())'))->toBeTrue();
})->skip(fn () => ! pythonHasModule('numpy'), 'numpy is not installed');

test('an array above max_message_bytes is refused in both directions', function (): void {
    $py = testSession(['max_message_bytes' => 64 * 1024]);
    $py->exec("import numpy as np\nbig = np.zeros(10_000)");

    expect(fn () => Py::toPhp($py->eval('big')))->toThrow(MessageTooLargeException::class, 'the response is 80000 bytes, above the 65536-byte limit')
        ->and(fn () => $py->vars->x = Py::ndarray(array_fill(0, 10_000, 0.0)))->toThrow(
            MessageTooLargeException::class,
            'The message is 80000 bytes, above the 65536-byte max_message_bytes limit.',
        )
        ->and($py->eval('1 + 1'))->toBe(2);
})->skip(fn () => ! pythonHasModule('numpy'), 'numpy is not installed');
