<?php

declare(strict_types=1);

use PyHP\Py;
use PyHP\PyObject;

/*
 * Optional: these run only when the interpreter under test has pandas/numpy.
 * They prove the proxy design works on real scientific objects.
 */

beforeEach(function (): void {
    $this->py = testSession();
});

afterEach(function (): void {
    $this->py->close();
});

test('a pandas workflow runs on real DataFrames through proxies', function (): void {
    $pd = $this->py->import('pandas');
    $df = $pd->DataFrame([
        'region' => ['north', 'south', 'north', 'east'],
        'amount' => [1200, 300, 1800, 2500],
    ]);
    $this->py->vars->minAmount = 1000;

    $big = $df[$df['amount']->gt($this->py->vars->minAmount)];
    $totals = $big->groupby('region')['amount']->sum()->sort_values(ascending: false);

    expect($df)->toBeInstanceOf(PyObject::class)
        ->and(Py::type($df))->toBe('pandas.DataFrame')
        ->and($df->shape)->toBe([4, 2])
        ->and($totals->to_dict())->toBe(['north' => 3000, 'east' => 2500])
        ->and($df['amount']->sum())->toBe(5800)
        ->and($df['amount']->mean())->toBe(1450.0)
        ->and(Py::len($df))->toBe(4)
        ->and((string) $df->head(1))->toContain('north');
})->skip(fn () => ! pythonHasModule('pandas'), 'pandas is not installed');

test('dataframe columns can be assigned and loc works with tuples', function (): void {
    $pd = $this->py->import('pandas');
    $df = $pd->DataFrame(['a' => [1, 2, 3]]);
    $df['b'] = $df['a']->mul(10);
    $df->loc[Py::tuple($df['a']->gt(1), 'b')] = 0;

    expect($df['b']->tolist())->toBe([10, 0, 0]);
})->skip(fn () => ! pythonHasModule('pandas'), 'pandas is not installed');

test('numpy arrays stay in Python and scalars come back as PHP numbers', function (): void {
    $np = $this->py->import('numpy');
    $matrix = $np->array([[1, 2], [3, 4]]);

    expect(Py::type($matrix))->toBe('numpy.ndarray')
        ->and($matrix->shape)->toBe([2, 2])
        ->and($matrix->sum())->toBe(10)
        ->and($matrix->T->tolist())->toBe([[1, 3], [2, 4]])
        ->and($np->linalg->det($matrix))->toEqualWithDelta(-2.0, 1e-9)
        ->and($matrix[Py::tuple(1, 0)])->toBe(3);
})->skip(fn () => ! pythonHasModule('numpy'), 'numpy is not installed');

test('pandas timestamps stay in Python', function (): void {
    $pd = $this->py->import('pandas');
    $stamp = $pd->Timestamp('2024-05-01 10:30');

    expect($stamp)->toBeInstanceOf(PyObject::class)
        ->and($stamp->isoformat())->toBe('2024-05-01T10:30:00')
        ->and($stamp->year)->toBe(2024);
})->skip(fn () => ! pythonHasModule('pandas'), 'pandas is not installed');

test('Py::rows and Py::columns copy a DataFrame in PHP-friendly form', function (): void {
    $this->py->exec(<<<'PY'
        import numpy as np, pandas as pd
        df = pd.DataFrame({
            'id': [1, 2],
            'price': [9.5, np.nan],
            'name': ['café', None],
            'when': pd.to_datetime(['2024-01-02 03:04:05', None]),
        }, index=[10, 20])
        PY);
    $df = $this->py->vars->df;

    $rows = Py::rows($df);
    $columns = Py::columns($df);

    expect($rows)->toBe([
        ['id' => 1, 'price' => 9.5, 'name' => 'café', 'when' => '2024-01-02T03:04:05'],
        ['id' => 2, 'price' => null, 'name' => null, 'when' => null],
    ])
        ->and($columns)->toBe([
            'id' => [1, 2],
            'price' => [9.5, null],
            'name' => ['café', null],
            'when' => ['2024-01-02T03:04:05', null],
        ])
        ->and(json_encode($rows))->toBeString();
})->skip(fn () => ! pythonHasModule('pandas'), 'pandas is not installed');

test('Py::rows handles empty frames and infinite floats', function (): void {
    $this->py->exec("import numpy as np, pandas as pd\nempty = pd.DataFrame({'a': []})\ninf = pd.DataFrame({'x': [np.inf]})");

    expect(Py::rows($this->py->vars->empty))->toBe([])
        ->and(Py::columns($this->py->vars->empty))->toBe(['a' => []])
        ->and(Py::rows($this->py->vars->inf))->toBe([['x' => INF]]);
})->skip(fn () => ! pythonHasModule('pandas'), 'pandas is not installed');

test('Py::rows refuses cells PHP cannot hold and names the column', function (): void {
    $this->py->exec("import pandas as pd\nfrom decimal import Decimal\ndf = pd.DataFrame({'amount': [Decimal('1.10')]})");

    expect(fn () => Py::rows($this->py->vars->df))
        ->toThrow(PyHP\Exception\ConversionException::class, "column 'amount' holds decimal.Decimal values");
})->skip(fn () => ! pythonHasModule('pandas'), 'pandas is not installed');

test('Py::rows needs a DataFrame', function (): void {
    expect(fn () => Py::rows($this->py->eval('{1, 2}')))
        ->toThrow(PyHP\Exception\PythonException::class, 'a pandas DataFrame is needed, not a set');
})->skip(fn () => ! pythonHasModule('pandas'), 'pandas is not installed');

test('Py::rows is much faster than to_dict records for big frames', function (): void {
    $this->py->exec("import numpy as np, pandas as pd\nbig = pd.DataFrame({'g': np.arange(50_000) % 10, 'v': np.arange(50_000) / 7, 's': ['abc'] * 50_000})");
    $big = $this->py->vars->big;

    $start = hrtime(true);
    $viaRecords = $big->to_dict('records');
    $recordsMs = (hrtime(true) - $start) / 1e6;
    $start = hrtime(true);
    $viaRows = Py::rows($big);
    $rowsMs = (hrtime(true) - $start) / 1e6;

    expect($viaRows)->toBe($viaRecords)
        ->and($rowsMs)->toBeLessThan($recordsMs);
})->skip(fn () => ! pythonHasModule('pandas'), 'pandas is not installed');
