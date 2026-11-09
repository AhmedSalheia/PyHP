<?php

declare(strict_types=1);

use PyHP\Exception\PythonException;
use PyHP\Py;
use PyHP\Value\Vectorized;

beforeEach(function (): void {
    $this->py = testSession();
    $this->py->exec("def run(f, data):\n    return f(data)");
});

afterEach(function (): void {
    $this->py->close();
});

test('a chunk closure is called once per batch, not once per value', function (): void {
    $calls = new ArrayObject;
    $double = Py::vectorize(function (array $chunk) use ($calls): array {
        $calls->append(count($chunk));

        return array_map(fn (int $v): int => $v * 2, $chunk);
    }, batchSize: 1000);

    $result = $this->py->functions->run($double, range(1, 2500));

    expect($result)->toBe(array_map(fn (int $v): int => $v * 2, range(1, 2500)))
        ->and($calls->getArrayCopy())->toBe([1000, 1000, 500]);
});

test('elementwise wraps an ordinary per-value closure', function (): void {
    $result = $this->py->functions->run(Py::vectorize(ucfirst(...), elementwise: true), ['ahmed', 'lina']);

    expect($result)->toBe(['Ahmed', 'Lina']);
});

test('a pandas Series keeps its index and name through pipe()', function (): void {
    $pd = $this->py->import('pandas', as: 'pd');
    $names = $pd->Series(['ahmed', 'lina', 'sam'], index: [10, 20, 30], name: 'name');

    $upper = $names->pipe(Py::vectorize(strtoupper(...), elementwise: true, batchSize: 2));

    expect($upper->tolist())->toBe(['AHMED', 'LINA', 'SAM'])
        ->and($upper->index->tolist())->toBe([10, 20, 30])
        ->and($upper->name)->toBe('name');
})->skip(fn () => ! pythonHasModule('pandas'), 'pandas is not installed');

test('returning the wrong number of values is a clear Python error', function (): void {
    $broken = Py::vectorize(fn (array $chunk): array => [1], batchSize: 10);

    expect(fn () => $this->py->functions->run($broken, [1, 2, 3]))
        ->toThrow(PythonException::class, 'returned 1 values for a chunk of 3');
});

test('the batch size must be at least 1', function (): void {
    expect(fn () => Py::vectorize(fn (array $c): array => $c, batchSize: 0))
        ->toThrow(InvalidArgumentException::class, 'at least 1');
});

test('vectorize returns a value object that can be reused', function (): void {
    $vectorized = Py::vectorize(fn (array $c): array => $c, batchSize: 5);

    expect($vectorized)->toBeInstanceOf(Vectorized::class)
        ->and($vectorized->batchSize)->toBe(5)
        ->and($this->py->functions->run($vectorized, [1]))->toBe([1])
        ->and($this->py->functions->run($vectorized, [2]))->toBe([2]);
});
