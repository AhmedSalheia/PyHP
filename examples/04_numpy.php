<?php

declare(strict_types=1);

// NumPy arrays from PHP. Needs numpy: python -m pip install numpy
// Run with: php examples/04_numpy.php

require __DIR__.'/bootstrap.php';

use PyHP\Py;

$py = Py::session();
$np = $py->import('numpy');

$a = $np->array([[2.0, 1.0], [1.0, 3.0]]);
$b = $np->array([5.0, 10.0]);

// Solve a x = b. The arrays never leave Python; only the answer is copied.
$x = $np->linalg->solve($a, $b);
echo 'Solution: '.json_encode($x->tolist())."\n";

// Element access with a tuple index, like a[1, 0] in Python.
echo 'a[1, 0] = '.$a[Py::tuple(1, 0)]."\n";

// Slices: every second value of a range, like r[::2].
$r = $np->arange(10);
echo 'r[::2] = '.json_encode($r[Py::slice(null, null, 2)]->tolist())."\n";

// NumPy scalars arrive as PHP numbers.
$stats = $np->random->default_rng(42)->normal(0, 1, 100_000);
printf("mean=%.4f std=%.4f\n", $stats->mean(), $stats->std());

$py->close();
