<?php

declare(strict_types=1);

// Symbolic math with SymPy, written in PHP. Needs SymPy:
//   python -m pip install sympy
// Run with: php examples/06_sympy.php

require __DIR__.'/bootstrap.php';

use PyHP\Py;

$py = Py::session();
$sp = $py->import('sympy', as: 'sp');

// 1. Solve an equation written as a string.
$x = $sp->symbols('x');
$roots = $sp->solve($sp->sympify('x**2 - 5*x + 6'), $x);
// SymPy's whole numbers arrive as PHP ints; fractions and high-precision floats stay proxies.
echo 'Roots of x^2 - 5x + 6: '.implode(', ', $roots)."\n";

// 2. Calculus and simplification. Proxies print through (string).
$expr = $sp->sympify('sin(x)**2 + cos(x)**2 + x**3');
echo 'simplify: '.$sp->simplify($expr)."\n";
echo 'd/dx:     '.$sp->diff($expr, $x)."\n";
echo 'LaTeX:    '.$sp->latex($sp->Integral($sp->sqrt($x), $x)).' = '.$sp->latex($sp->integrate($sp->sqrt($x), $x))."\n";

// 3. Exact and numeric values.
$square = $sp->sympify('x**2 + 1');
echo 'f(1/2) exact: '.$square->subs($x, $sp->Rational(1, 2)).', as float: '.$py->functions->float($square->subs($x, 0.5))."\n";
echo 'sqrt(2) to 30 digits: '.$sp->sqrt(2)->evalf(30)."\n";

// 4. A system of equations. The answer is a dict keyed by Symbols, which can't be
//    PHP array keys, so build the PHP-friendly version in Python.
$py->exec(<<<'PY'
    x, y = sp.symbols('x y')
    solution = {str(k): int(v) for k, v in sp.solve([x + y - 10, 2*x - y - 2], [x, y]).items()}
    PY);
echo 'x + y = 10, 2x - y = 2  =>  '.json_encode($py->vars->solution)."\n";

// 5. lambdify: turn an expression into a function PHP can call in a loop.
$f = $sp->lambdify($x, $sp->sympify('x**2 - 5*x + 6'));
$table = [];
foreach (range(0, 5) as $v) {
    $table[] = "f({$v})=".$f($v);
}
echo implode('  ', $table)."\n";

// 6. Matrices.
$m = $sp->Matrix([[1, 2], [3, 4]]);
echo 'det: '.$m->det().', inverse: '.$m->inv()."\n";

// 7. Solution sets are iterable.
$values = [];
foreach ($sp->solveset($sp->Eq($x->__pow__(2), 9), $x) as $value) {
    $values[] = (string) $value;
}
echo 'x^2 = 9  =>  {'.implode(', ', $values)."}\n";

$py->close();
