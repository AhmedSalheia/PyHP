<?php

declare(strict_types=1);

use PyHP\Exception\ConversionException;
use PyHP\Exception\PythonException;
use PyHP\Exception\SessionClosedException;
use PyHP\Py;
use PyHP\PyObject;

beforeEach(function (): void {
    $this->py = testSession();
    $this->py->exec(<<<'PY'
        class Account:
            bank = "PyHP Bank"

            def __init__(self, owner, balance=0):
                self.owner = owner
                self.balance = balance
                self.history = []

            def deposit(self, amount, *, note=None):
                self.balance += amount
                self.history.append(note or "deposit")
                return self.balance

            def __str__(self):
                return f"{self.owner}: {self.balance}"

            def __len__(self):
                return len(self.history)
        PY);
});

afterEach(function (): void {
    $this->py->close();
});

test('calling a Python class gives a proxy with readable attributes', function (): void {
    $account = $this->py->functions->Account('Ahmed', 10);

    expect($account)->toBeInstanceOf(PyObject::class)
        ->and($account->owner)->toBe('Ahmed')
        ->and($account->balance)->toBe(10)
        ->and($account->bank)->toBe('PyHP Bank');
});

test('methods accept positional and keyword arguments', function (): void {
    $account = $this->py->functions->Account('Ahmed');

    expect($account->deposit(5))->toBe(5)
        ->and($account->deposit(7, note: 'salary'))->toBe(12)
        ->and($account->history)->toBe(['deposit', 'salary']);
});

test('attributes can be set, checked and deleted', function (): void {
    $account = $this->py->functions->Account('Ahmed');
    $account->nickname = 'A';

    expect(isset($account->nickname))->toBeTrue()
        ->and($account->nickname)->toBe('A');

    unset($account->nickname);

    expect(isset($account->nickname))->toBeFalse()
        ->and(fn () => $account->nickname)->toThrow(PythonException::class, 'AttributeError');
});

test('a method can be fetched and invoked later', function (): void {
    $account = $this->py->functions->Account('Ahmed');
    $deposit = $account->deposit;

    expect($deposit(3))->toBe(3)
        ->and(Py::type($deposit))->toBe('method');
});

test('string conversion, repr, len and type use Python semantics', function (): void {
    $account = $this->py->functions->Account('Ahmed', 2);
    $account->deposit(1);

    expect((string) $account)->toBe('Ahmed: 3')
        ->and(Py::repr($account))->toStartWith('<__pyhp__.Account object')
        ->and(Py::len($account))->toBe(1)
        ->and(Py::type($account))->toBe('__pyhp__.Account');
});

test('array access maps to Python item access', function (): void {
    $this->py->exec("data = {'a': 1, 'b': None}\nnums = [10, 20, 30]");
    $data = $this->py->vars->ref('data');
    $nums = $this->py->vars->ref('nums');

    $data['c'] = 3;
    $nums[] = 40;
    unset($data['a']);

    expect($data['b'])->toBeNull()
        ->and(isset($data['c']))->toBeTrue()
        ->and(isset($data['b']))->toBeFalse()
        ->and(isset($data['zzz']))->toBeFalse()
        ->and($nums[-1])->toBe(40)
        ->and($nums[Py::slice(1, 3)])->toBe([20, 30])
        ->and($this->py->vars->data)->toBe(['b' => null, 'c' => 3]);
});

test('foreach iterates lazily in batches', function (): void {
    $range = $this->py->eval('range(250)');
    $seen = [];
    foreach ($range as $index => $value) {
        $seen[$index] = $value;
    }

    expect($seen)->toBe(range(0, 249));
});

test('foreach over a dict yields keys, items() yields pairs', function (): void {
    $this->py->exec("d = {'x': 1, 'y': 2}");
    $d = $this->py->vars->ref('d');
    $keys = [];
    foreach ($d as $key) {
        $keys[] = $key;
    }
    $pairs = [];
    foreach ($d->items() as [$key, $value]) {
        $pairs[$key] = $value;
    }

    expect($keys)->toBe(['x', 'y'])
        ->and($pairs)->toBe(['x' => 1, 'y' => 2]);
});

test('proxies can be passed back to Python', function (): void {
    $a = $this->py->functions->Account('A', 1);
    $this->py->vars->stored = $a;

    expect($this->py->eval('stored.owner'))->toBe('A')
        ->and($this->py->functions->sorted([3, 1, 2]))->toBe([1, 2, 3]);
});

test('toPhp converts sets and other containers strictly', function (): void {
    $set = $this->py->eval('{3}');

    expect($set)->toBeInstanceOf(PyObject::class)
        ->and(Py::toPhp($set))->toBe([3]);
});

test('toPhp refuses objects that have no PHP equivalent', function (): void {
    $account = $this->py->functions->Account('A');

    expect(fn () => Py::toPhp($account))->toThrow(ConversionException::class, 'Account');
});

test('released proxies free their Python objects', function (): void {
    $account = $this->py->functions->Account('A');
    expect($this->py->info()['objects'])->toBe(1);

    unset($account);

    // the release is sent with the next request
    expect($this->py->info()['objects'])->toBe(0);
});

test('proxies stop working after their session closes', function (): void {
    $account = $this->py->functions->Account('A');
    $this->py->close();

    expect(fn () => $account->owner)->toThrow(SessionClosedException::class);
});

test('proxies cannot be cloned or serialized', function (): void {
    $account = $this->py->functions->Account('A');

    expect(fn () => clone $account)->toThrow(LogicException::class)
        ->and(fn () => serialize($account))->toThrow(LogicException::class);
});

test('var_dump shows the Python type without calling Python', function (): void {
    $account = $this->py->functions->Account('A');

    expect($account->__debugInfo())->toMatchArray(['type' => '__pyhp__.Account']);
});

test('a proxy from one session cannot be used in another', function (): void {
    $other = testSession();
    $account = $this->py->functions->Account('A');

    expect(fn () => $other->vars->x = $account)
        ->toThrow(ConversionException::class, 'different session');
    $other->close();
});

test('__call can be used directly for Python methods named like PHP interface methods', function (): void {
    $this->py->exec("class Weird:\n    def getIterator(self):\n        return 'python method'\nw = Weird()");
    $weird = $this->py->vars->w;

    expect($weird->__call('getIterator', []))->toBe('python method');
});
