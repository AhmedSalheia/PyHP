<?php

declare(strict_types=1);

use PyHP\Exception\ConversionException;
use PyHP\Py;
use PyHP\PyObject;

beforeEach(function (): void {
    $this->py = testSession();
    $this->py->exec("def echo(x):\n    return x\ndef kind(x):\n    return type(x).__name__");
});

afterEach(function (): void {
    $this->py->close();
});

test('values survive a round trip', function (mixed $value): void {
    expect($this->py->functions->echo($value))->toBe($value);
})->with([
    'null' => [null],
    'true' => [true],
    'int' => [PHP_INT_MAX],
    'negative int' => [PHP_INT_MIN],
    'float' => [0.1],
    'whole float stays float' => [2.0],
    'empty string' => [''],
    'unicode' => ['مرحبا ✓ 😀'],
    'list' => [[1, 'two', 3.0]],
    'assoc' => [['name' => 'A', 'tags' => ['x']]],
    'int keys' => [[5 => 'a', 9 => 'b']],
    'nested' => [['a' => ['b' => ['c' => [1, [2]]]]]],
]);

test('PHP values arrive as the expected Python types', function (mixed $value, string $type): void {
    expect($this->py->functions->kind($value))->toBe($type);
})->with([
    [null, 'NoneType'],
    [true, 'bool'],
    [1, 'int'],
    [1.0, 'float'],
    ['s', 'str'],
    [[1, 2], 'list'],
    [['a' => 1], 'dict'],
    [[], 'list'],
]);

test('helpers create tuples, slices and dicts explicitly', function (): void {
    expect($this->py->functions->kind(Py::tuple(1, 2)))->toBe('tuple')
        ->and($this->py->functions->kind(Py::dict([])))->toBe('dict')
        ->and($this->py->functions->kind(Py::dict([1, 2])))->toBe('dict')
        ->and($this->py->functions->kind(Py::slice(1)))->toBe('slice')
        ->and($this->py->eval('(1, 2)'))->toBe([1, 2]);
});

test('non-finite floats survive', function (): void {
    expect($this->py->functions->echo(INF))->toBe(INF)
        ->and($this->py->functions->echo(-INF))->toBe(-INF)
        ->and(is_nan($this->py->functions->echo(NAN)))->toBeTrue();
});

test('binary PHP strings become bytes and come back as strings', function (): void {
    $binary = "\x00\xff\xfe";

    expect($this->py->functions->kind($binary))->toBe('bytes')
        ->and($this->py->functions->echo($binary))->toBe($binary);
});

test('numeric string keys become integers, as PHP itself does', function (): void {
    expect($this->py->eval("{'1': 'a', '01': 'b'}"))->toBe([1 => 'a', '01' => 'b']);
});

test('values PHP cannot represent stay in Python as proxies', function (string $expression, string $type): void {
    $value = $this->py->eval($expression);

    expect($value)->toBeInstanceOf(PyObject::class)
        ->and(Py::type($value))->toBe($type);
})->with([
    'big int' => ['2 ** 70', 'int'],
    'set' => ['{1, 2}', 'set'],
    'colliding keys' => ["{1: 'a', '1': 'b'}", 'dict'],
    'tuple keys' => ["{(1, 2): 'x'}", 'dict'],
    'datetime' => ["__import__('datetime').date(2024, 1, 2)", 'datetime.date'],
]);

test('nested unconvertible values become nested proxies', function (): void {
    $value = $this->py->eval("{'when': __import__('datetime').date(2024, 1, 2), 'n': 1}");

    expect($value['n'])->toBe(1)
        ->and($value['when'])->toBeInstanceOf(PyObject::class)
        ->and($value['when']->isoformat())->toBe('2024-01-02');
});

test('PHP values without a Python equivalent are rejected', function (mixed $value, string $message): void {
    expect(fn () => $this->py->functions->echo($value))->toThrow(ConversionException::class, $message);
})->with([
    'object' => [new DateTimeImmutable, 'DateTimeImmutable'],
    'resource' => [STDIN, 'resource'],
]);

test('deeply nested PHP arrays are rejected', function (): void {
    $deep = [];
    $cursor = &$deep;
    for ($i = 0; $i < 300; $i++) {
        $cursor['x'] = [];
        $cursor = &$cursor['x'];
    }
    unset($cursor);

    expect(fn () => $this->py->functions->echo($deep))->toThrow(ConversionException::class, 'deep');
});

test('a Python dict with a "$t" key arrives as a normal array', function (): void {
    expect($this->py->eval('{"$t": "not a tag", "a": [1]}'))->toBe(['$t' => 'not a tag', 'a' => [1]]);
});

test('copying plain data needs little more memory than the result itself', function (): void {
    $this->py->exec("rows = [{'id': i, 'name': 'row', 'score': i / 3} for i in range(20000)]");
    gc_collect_cycles();
    $before = memory_get_usage();
    memory_reset_peak_usage();

    $rows = $this->py->vars->rows;

    $kept = memory_get_usage() - $before;
    $peak = memory_get_peak_usage() - $before;
    expect(count($rows))->toBe(20000)
        ->and($rows[19999])->toBe(['id' => 19999, 'name' => 'row', 'score' => 19999 / 3])
        ->and($peak)->toBeLessThan($kept * 2);
});
