<?php

declare(strict_types=1);

use PyHP\Exception\PythonException;
use PyHP\Py;
use PyHP\PyObject;

beforeEach(function (): void {
    $this->py = testSession();
});

afterEach(function (): void {
    $this->py->close();
});

test('import binds the module name like Python does', function (): void {
    $math = $this->py->import('math');
    $this->py->import('os.path');

    expect($math)->toBeInstanceOf(PyObject::class)
        ->and($this->py->eval('math.floor(2.7)'))->toBe(2)
        ->and($this->py->eval('os.path.basename("a/b.txt")'))->toBe('b.txt');
});

test('import with as binds the alias only', function (): void {
    $this->py->import('json', as: 'j');

    expect($this->py->eval('j.dumps([1])'))->toBe('[1]')
        ->and(isset($this->py->vars->json))->toBeFalse();
});

test('import with from works like from ... import', function (): void {
    $time = $this->py->import('time', from: 'datetime');
    $this->py->import('date', from: 'datetime', as: 'd');

    expect($time)->toBeInstanceOf(PyObject::class)
        ->and(Py::type($time))->toBe('type')
        ->and($this->py->eval('time(3, 30).minute'))->toBe(30)
        ->and($this->py->eval('d(2024, 5, 1).month'))->toBe(5);
});

test('importing a plain value returns it as a PHP value', function (): void {
    expect($this->py->import('pi', from: 'math'))->toEqualWithDelta(3.14159, 1e-5)
        ->and($this->py->vars->pi)->toEqualWithDelta(3.14159, 1e-5);
});

test('star imports bind every public name', function (): void {
    expect($this->py->import('*', from: 'math'))->toBeNull()
        ->and($this->py->functions->sqrt(9))->toBe(3.0);
});

test('importing something that does not exist raises ImportError', function (): void {
    expect(fn () => $this->py->import('nope', from: 'math'))
        ->toThrow(PythonException::class, "cannot import name 'nope' from 'math'")
        ->and(fn () => $this->py->import('pyhp_no_such_module'))
        ->toThrow(PythonException::class, 'ModuleNotFoundError');
});

test('modules lists and returns every module in the session', function (): void {
    $this->py->import('math');
    $this->py->import('json', as: 'j');
    $this->py->exec("import string\nnot_a_module = 5");

    expect($this->py->modules->names())->toBe(['math', 'j', 'string'])
        ->and($this->py->modules->math)->toBeInstanceOf(PyObject::class)
        ->and($this->py->modules->math->sqrt(16))->toBe(4.0)
        ->and($this->py->modules->j->dumps(['a' => 1]))->toBe('{"a": 1}')
        ->and(isset($this->py->modules->string))->toBeTrue()
        ->and(isset($this->py->modules->not_a_module))->toBeFalse()
        ->and(isset($this->py->modules->missing))->toBeFalse();
});

test('modules refuses names that are not modules', function (): void {
    $this->py->vars->n = 1;

    expect(fn () => $this->py->modules->n)->toThrow(PythonException::class, "'n' is a int, not a module")
        ->and(fn () => $this->py->modules->missing)->toThrow(PythonException::class, 'NameError');
});

test('modules cannot be assigned, only imported', function (): void {
    expect(fn () => $this->py->modules->math = 1)->toThrow(LogicException::class, 'import(')
        ->and(fn () => $this->py->classes->Thing = 1)->toThrow(LogicException::class, 'exec(');
});

test('classes instantiates, lists and returns Python classes', function (): void {
    $this->py->exec(<<<'PY'
        class Invoice:
            def __init__(self, customer, *, currency="USD"):
                self.customer = customer
                self.currency = currency

        def not_a_class():
            return 1
        PY);
    $invoice = $this->py->classes->Invoice('Ahmed', currency: 'ILS');
    $class = $this->py->classes->Invoice;

    expect(Py::type($invoice))->toBe('__pyhp__.Invoice')
        ->and($invoice->currency)->toBe('ILS')
        ->and($this->py->classes->names())->toBe(['Invoice'])
        ->and(isset($this->py->classes->Invoice))->toBeTrue()
        ->and(isset($this->py->classes->not_a_class))->toBeFalse()
        ->and($class->__name__)->toBe('Invoice')
        ->and($class('Sam')->customer)->toBe('Sam');
});

test('classes includes Python builtins and imported classes', function (): void {
    $this->py->import('OrderedDict', from: 'collections');

    expect($this->py->classes->dict(a: 1))->toBe(['a' => 1])
        ->and(Py::type($this->py->classes->OrderedDict()))->toBe('collections.OrderedDict')
        ->and($this->py->classes->names())->toBe(['OrderedDict']);
});

test('classes refuses to call things that are not classes', function (): void {
    $this->py->exec('def make(): return 1');

    expect(fn () => $this->py->classes->make())->toThrow(PythonException::class, "'make' is a function, not a class")
        ->and(fn () => $this->py->classes->make)->toThrow(PythonException::class, 'not a class');
});

test('the facade exposes modules and classes of the default session', function (): void {
    Py::reset(forgetConfig: true);
    Py::config(['python' => testPython(), 'output' => 'capture']);
    Py::import('math');
    Py::exec('class Point: pass');

    expect(Py::modules()->names())->toBe(['math'])
        ->and(Py::classes()->names())->toBe(['Point']);
    Py::reset(forgetConfig: true);
});
