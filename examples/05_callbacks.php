<?php

declare(strict_types=1);

// PHP closures as Python callbacks. The pandas part needs pandas:
//   python -m pip install pandas
// Run with: php examples/05_callbacks.php

require __DIR__.'/bootstrap.php';

use PyHP\Py;
use PyHP\PyObject;

$py = Py::session();

// 1. Any Python function that takes a function: sorted(key=...), map(...), ...
$people = [['name' => 'Sam', 'age' => 41], ['name' => 'Ahmed', 'age' => 30], ['name' => 'Lina', 'age' => 35]];
$byAge = $py->functions->sorted($people, key: fn (array $p): int => $p['age']);
echo 'By age: '.implode(', ', array_column($byAge, 'name'))."\n";

// 2. Python code calling PHP business logic, with keyword arguments.
$py->vars->price = fn (float $net, float $vat = 0.16): float => round($net * (1 + $vat), 2);
$py->exec(<<<'PY'
    totals = [price(n) for n in (10, 20.5)]
    discounted = price(100, vat=0)
    PY);
echo 'Totals: '.json_encode($py->vars->totals).', no VAT: '.$py->vars->discounted."\n";

// 3. A PHP exception travels through Python and comes back as the same object.
$py->exec("def process(rows, check):\n    return [check(r) for r in rows]");
try {
    $py->functions->process([1, -2, 3], function (int $row): int {
        if ($row < 0) {
            throw new InvalidArgumentException("negative row: {$row}");
        }

        return $row;
    });
} catch (InvalidArgumentException $e) {
    echo 'Caught in PHP: '.$e->getMessage()."\n";
}

// 4. pandas: one callback per value (simple) vs. one per column (fast).
$hasPandas = $py->eval("__import__('importlib.util').util.find_spec('pandas') is not None");
if ($hasPandas === true) {
    $pd = $py->import('pandas');
    $names = $pd->Series(['ahmed', 'sam', 'lina']);
    echo 'apply: '.json_encode($names->apply(ucfirst(...))->tolist())."\n";

    $scores = $pd->Series([7, 9, 8]);
    $curved = $scores->pipe(fn (PyObject $s) => $s->mul(10)->clip(upper: 85));
    echo 'pipe:  '.json_encode($curved->tolist())."\n";
}

$py->close();
