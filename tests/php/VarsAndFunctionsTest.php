<?php

declare(strict_types=1);

use PyHP\Exception\PythonException;
use PyHP\PyObject;

test('vars move values between PHP and Python', function (): void {
    $py = testSession();
    $py->vars->minAmount = 1000;
    $py->exec('doubled = minAmount * 2');

    expect($py->vars->doubled)->toBe(2000);
    $py->close();
});

test('vars support isset, unset and listing names', function (): void {
    $py = testSession();
    $py->vars->a = 1;
    $py->vars->b = null;

    expect(isset($py->vars->a))->toBeTrue()
        ->and(isset($py->vars->missing))->toBeFalse()
        ->and($py->vars->names())->toBe(['a', 'b']);

    unset($py->vars->a, $py->vars->missing);

    expect($py->vars->names())->toBe(['b']);
    $py->close();
});

test('reading an undefined var raises NameError', function (): void {
    $py = testSession();

    expect(fn () => $py->vars->nope)->toThrow(PythonException::class, 'NameError');
    $py->close();
});

test('vars->ref gives a live proxy instead of a copy', function (): void {
    $py = testSession();
    $py->vars->items = [1, 2];
    $items = $py->vars->ref('items');
    $items->append(3);

    expect($items)->toBeInstanceOf(PyObject::class)
        ->and($py->vars->items)->toBe([1, 2, 3]);
    $py->close();
});

test('functions calls global Python functions with positional and named arguments', function (): void {
    $py = testSession();
    $py->exec(<<<'PY'
        def greet(name, greeting="Hello", *, excited=False):
            return f"{greeting}, {name}" + ("!" if excited else "")
        PY);

    expect($py->functions->greet('Ahmed'))->toBe('Hello, Ahmed')
        ->and($py->functions->greet('Ahmed', 'Marhaba', excited: true))->toBe('Marhaba, Ahmed!')
        ->and($py->functions->greet(name: 'Sam'))->toBe('Hello, Sam');
    $py->close();
});

test('functions supports isset and returns proxies for later calls', function (): void {
    $py = testSession();
    $py->exec('def square(x): return x * x');
    $py->vars->notAFunction = 5;
    $square = $py->functions->square;

    expect(isset($py->functions->square))->toBeTrue()
        ->and(isset($py->functions->notAFunction))->toBeFalse()
        ->and(isset($py->functions->missing))->toBeFalse()
        ->and($square)->toBeInstanceOf(PyObject::class)
        ->and($square(7))->toBe(49);
    $py->close();
});

test('calling a missing function raises NameError', function (): void {
    $py = testSession();

    expect(fn () => $py->functions->missing())->toThrow(PythonException::class, 'NameError');
    $py->close();
});

test('arguments are validated in PHP before sending', function (): void {
    $py = testSession();
    $py->exec('def f(*a, **k): return [a, k]');

    expect($py->functions->f(1, [2], ['k' => 'v'], key: null))
        ->toBe([[1, [2], ['k' => 'v']], ['key' => null]]);
    $py->close();
});
