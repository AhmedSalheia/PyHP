<?php

declare(strict_types=1);

use PyHP\OutputMode;
use PyHP\Py;
use PyHP\PyObject;
use PyHP\Session;

beforeEach(function (): void {
    Py::reset(forgetConfig: true);
    Py::config(['python' => testPython(), 'output' => OutputMode::Capture]);
});

afterEach(function (): void {
    Py::reset(forgetConfig: true);
});

test('the static facade drives one shared default session', function (): void {
    Py::exec('counter = 1');
    Py::exec('counter += 1');
    Py::vars()->extra = 10;

    expect(Py::eval('counter + extra'))->toBe(12)
        ->and(Py::default())->toBe(Py::default());
});

test('functions and imports are reachable statically', function (): void {
    Py::exec('def add(a, b): return a + b');
    $math = Py::import('math');

    expect(Py::functions()->add(2, 3))->toBe(5)
        ->and($math)->toBeInstanceOf(PyObject::class)
        ->and($math->sqrt(81))->toBe(9.0);
});

test('import can bind a name in the session namespace', function (): void {
    Py::import('math', as: 'm');

    expect(Py::eval('m.floor(2.7)'))->toBe(2);
});

test('execFile is reachable statically', function (): void {
    $script = tempnam(sys_get_temp_dir(), 'pyhp').'.py';
    file_put_contents($script, "loaded = True\n");
    Py::execFile($script);

    expect(Py::vars()->loaded)->toBeTrue();
    unlink($script);
});

test('Py::session creates independent sessions with overrides', function (): void {
    $a = Py::session();
    $b = Py::session(['timeout' => 3]);
    $a->vars->x = 'a';

    expect($a)->toBeInstanceOf(Session::class)
        ->and(isset($b->vars->x))->toBeFalse()
        ->and($b->configuration()->timeout)->toBe(3.0);
    $a->close();
    $b->close();
});

test('config changes apply to sessions created afterwards', function (): void {
    $first = Py::default();
    Py::config(['timeout' => 7]);

    expect(Py::default())->toBe($first)
        ->and(Py::configuration()->timeout)->toBe(7.0);

    Py::reset();

    expect(Py::default())->not->toBe($first)
        ->and(Py::default()->configuration()->timeout)->toBe(7.0);
});

test('a closed default session is replaced automatically', function (): void {
    $first = Py::default();
    $first->close();

    expect(Py::default())->not->toBe($first)
        ->and(Py::eval('5'))->toBe(5);
});
