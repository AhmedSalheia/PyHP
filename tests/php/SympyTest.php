<?php

declare(strict_types=1);

use PyHP\Py;
use PyHP\PyObject;

/*
 * Optional: runs only when the interpreter under test has SymPy
 * (python -m pip install sympy). Shows symbolic math working through proxies.
 */

beforeEach(function (): void {
    $this->py = testSession();
    // Import only when available, so a missing library skips instead of failing.
    if (pythonHasModule('sympy')) {
        $this->sp = $this->py->import('sympy', as: 'sp');
    }
});

afterEach(function (): void {
    $this->py->close();
});

test('equations parsed from strings are solved symbolically', function (): void {
    $x = $this->sp->symbols('x');
    $roots = $this->sp->solve($this->sp->sympify('x**2 - 5*x + 6'), $x);

    // SymPy numbers aren't Python ints, so they stay proxies; convert explicitly.
    expect($roots[0])->toBeInstanceOf(PyObject::class)
        ->and(Py::type($roots[0]))->toBe('sympy.core.numbers.Integer')
        ->and(array_map(fn (PyObject $r): int => $this->py->functions->int($r), $roots))->toBe([2, 3]);
})->skip(fn () => ! pythonHasModule('sympy'), 'SymPy is not installed');

test('calculus, simplification and LaTeX', function (): void {
    $x = $this->sp->symbols('x');
    $expr = $this->sp->sympify('sin(x)**2 + cos(x)**2 + x**3');

    expect((string) $this->sp->simplify($expr))->toBe('x**3 + 1')
        ->and((string) $this->sp->diff($expr, $x))->toContain('3*x**2')
        ->and((string) $this->sp->integrate($this->sp->exp($x), $x))->toBe('exp(x)')
        ->and($this->sp->latex($this->sp->Integral($this->sp->sqrt($x), $x)))->toBe('\int \sqrt{x}\, dx');
})->skip(fn () => ! pythonHasModule('sympy'), 'SymPy is not installed');

test('substitution, exact and numeric values', function (): void {
    $x = $this->sp->symbols('x');
    $expr = $this->sp->sympify('x**2 + 1');

    expect((string) $expr->subs($x, $this->sp->Rational(1, 2)))->toBe('5/4')
        ->and($this->py->functions->float($expr->subs($x, 0.5)))->toBe(1.25)
        ->and((string) $this->sp->sqrt(2)->evalf(20))->toBe('1.4142135623730950488');
})->skip(fn () => ! pythonHasModule('sympy'), 'SymPy is not installed');

test('systems of equations come back as a dict keyed by symbols', function (): void {
    $this->py->exec("x, y = sp.symbols('x y')");
    $solution = $this->py->eval('sp.solve([x + y - 3, x - y - 1], [x, y])');

    // Symbol keys can't be PHP array keys, so the dict stays a proxy; convert in Python.
    expect(Py::type($solution))->toBe('dict')
        ->and($this->py->eval('{str(k): int(v) for k, v in sp.solve([x + y - 3, x - y - 1], [x, y]).items()}'))
        ->toBe(['x' => 2, 'y' => 1]);
})->skip(fn () => ! pythonHasModule('sympy'), 'SymPy is not installed');

test('lambdify turns an expression into a fast function PHP can call', function (): void {
    $x = $this->sp->symbols('x');
    $f = $this->sp->lambdify($x, $this->sp->sympify('x**2 - 5*x + 6'));

    expect(array_map(fn (int $v) => $f($v), [0, 1, 2, 3, 4]))->toBe([6, 2, 0, 0, 2]);
})->skip(fn () => ! pythonHasModule('sympy'), 'SymPy is not installed');

test('matrices: determinant, inverse and conversion to floats', function (): void {
    $m = $this->sp->Matrix([[1, 2], [3, 4]]);

    expect($this->py->functions->int($m->det()))->toBe(-2)
        ->and((string) $m->inv())->toBe('Matrix([[-2, 1], [3/2, -1/2]])')
        ->and($this->sp->matrix2numpy($m->inv(), dtype: 'float')->tolist())->toBe([[-2.0, 1.0], [1.5, -0.5]]);
})->skip(fn () => ! pythonHasModule('sympy') || ! pythonHasModule('numpy'), 'SymPy or numpy is not installed');

test('solution sets can be iterated', function (): void {
    $x = $this->sp->symbols('x');
    $values = [];
    foreach ($this->sp->solveset($this->sp->Eq($x->__pow__(2), 4), $x) as $value) {
        $values[] = (string) $value;
    }

    expect($values)->toBe(['-2', '2']);
})->skip(fn () => ! pythonHasModule('sympy'), 'SymPy is not installed');
