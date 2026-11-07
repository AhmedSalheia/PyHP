<?php

declare(strict_types=1);

use PyHP\Exception\PythonException;
use PyHP\Py;
use PyHP\PyObject;

beforeEach(function (): void {
    $this->py = testSession();
    $this->py->exec(<<<'PY'
        class Holder:
            def __init__(self):
                self.data = {}
                self.items = [1, 2]
                self.count = 5

        holder = Holder()
        PY);
    $this->holder = $this->py->vars->holder;
});

afterEach(function (): void {
    $this->py->close();
});

test('writing through a ref changes the dict attribute in Python', function (): void {
    Py::ref($this->holder, 'data')['checked_by'] = 'PHP';

    expect($this->py->eval('holder.data'))->toBe(['checked_by' => 'PHP']);
});

test('reading the attribute normally still gives a copy', function (): void {
    $copy = $this->holder->data;
    $copy['ignored'] = true;

    expect($this->py->eval('holder.data'))->toBe([])
        ->and($copy)->toBe(['ignored' => true]);
});

test('methods called on a ref act on the real list', function (): void {
    Py::ref($this->holder, 'items')->append(3);

    expect($this->py->eval('holder.items'))->toBe([1, 2, 3]);
});

test('even plain numbers come back as a proxy', function (): void {
    $count = Py::ref($this->holder, 'count');

    expect($count)->toBeInstanceOf(PyObject::class)
        ->and(Py::type($count))->toBe('int')
        ->and(Py::toPhp($count))->toBe(5);
});

test('a missing attribute raises AttributeError', function (): void {
    expect(fn () => Py::ref($this->holder, 'missing'))->toThrow(PythonException::class, 'AttributeError');
});
