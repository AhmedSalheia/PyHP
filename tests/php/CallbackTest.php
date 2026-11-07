<?php

declare(strict_types=1);

use PyHP\Exception\ConversionException;
use PyHP\Exception\PythonException;
use PyHP\Exception\SessionClosedException;
use PyHP\Py;
use PyHP\PyObject;

beforeEach(function (): void {
    $this->py = testSession();
});

afterEach(function (): void {
    $this->py->close();
});

test('closures become Python callables', function (): void {
    $people = [['name' => 'Sam', 'age' => 41], ['name' => 'Ahmed', 'age' => 30]];

    $sorted = $this->py->functions->sorted($people, key: fn (array $p): int => $p['age']);

    expect(array_column($sorted, 'name'))->toBe(['Ahmed', 'Sam']);
});

test('closures work with map and receive keyword arguments as named arguments', function (): void {
    $this->py->vars->double = fn (int $x): int => $x * 2;
    $this->py->vars->scale = fn (float $x, float $by = 1.0): float => $x * $by;

    expect($this->py->eval('list(map(double, [1, 2, 3]))'))->toBe([2, 4, 6])
        ->and($this->py->eval('scale(2.0, by=1.5)'))->toBe(3.0)
        ->and($this->py->eval('scale(2.0)'))->toBe(2.0);
});

test('first-class callable syntax works', function (): void {
    $this->py->vars->upper = strtoupper(...);

    expect($this->py->eval("upper('abc')"))->toBe('ABC');
});

test('callbacks receive proxies for values that stay in Python', function (): void {
    $this->py->exec("def give(f):\n    return f({1, 2, 3})");

    $result = $this->py->functions->give(function (PyObject $set): int {
        return Py::len($set) * 10;
    });

    expect($result)->toBe(30);
});

test('callbacks can return arrays, null and proxies', function (): void {
    $this->py->exec("def run(f):\n    return f()");
    $proxy = $this->py->eval('{7}');

    expect($this->py->functions->run(fn (): array => ['a' => [1, 2]]))->toBe(['a' => [1, 2]])
        ->and($this->py->functions->run(fn () => null))->toBeNull()
        ->and(Py::type($this->py->functions->run(fn (): PyObject => $proxy)))->toBe('set');
});

test('a closure comes back to PHP as the same closure', function (): void {
    $closure = fn (int $x): int => $x + 1;
    $this->py->vars->f = $closure;

    expect($this->py->vars->f)->toBe($closure);
});

test('strings and arrays are never treated as callables', function (): void {
    $this->py->vars->s = 'strlen';
    $this->py->vars->a = ['strtoupper', 'x'];

    expect($this->py->eval('type(s).__name__'))->toBe('str')
        ->and($this->py->eval('type(a).__name__'))->toBe('list');
});

test('the original PHP exception is rethrown when Python does not catch it', function (): void {
    $this->py->exec("def run(f):\n    return f()");
    $thrown = new DomainException('invoice is closed');

    try {
        $this->py->functions->run(function () use ($thrown): never {
            throw $thrown;
        });
        $this->fail('expected an exception');
    } catch (DomainException $caught) {
        expect($caught)->toBe($thrown);
    }
});

test('Python code can catch a PHP exception as PhpError', function (): void {
    $this->py->exec(<<<'PY'
        from pyhp import PhpError

        def safe(f):
            try:
                return f()
            except PhpError as e:
                return [e.php_class, e.php_message]
        PY);

    $result = $this->py->functions->safe(fn () => throw new RuntimeException('boom'));

    expect($result)->toBe(['RuntimeException', 'boom']);
});

test('a result PHP cannot send fails like any exception thrown in the callback', function (): void {
    $this->py->exec("def run(f):\n    return f()");

    expect(fn () => $this->py->functions->run(fn () => new DateTimeImmutable))
        ->toThrow(ConversionException::class, 'DateTimeImmutable');
});

test('callbacks can call back into Python, several levels deep', function (): void {
    $py = $this->py;
    $py->vars->base = 100;
    $py->exec("def through(f, n):\n    return f(n) + 1");

    $level3 = fn (int $n): int => $py->vars->base + $n;
    $level2 = fn (int $n): int => $py->functions->through($level3, $n * 2);
    $level1 = fn (int $n): int => $py->functions->through($level2, $n + 1);

    expect($py->functions->through($level1, 1))->toBe(100 + 4 + 1 + 1 + 1);
});

test('callbacks nested deeper than 32 levels fail with a RecursionError', function (): void {
    $py = $this->py;
    $py->exec("def down(n, f):\n    return 0 if n == 0 else f(n) + 1");
    $callback = function (int $n) use (&$callback, $py): int {
        return $py->functions->down($n - 1, $callback);
    };

    expect($py->functions->down(10, $callback))->toBe(10)
        ->and(fn () => $py->functions->down(40, $callback))
        ->toThrow(PythonException::class, 'nested more than 32 levels');
});

test('time spent in PHP callbacks does not count against the timeout', function (): void {
    $py = testSession(['timeout' => 0.5]);
    $py->exec("def run(f):\n    return f()");

    $result = $py->functions->run(function (): string {
        usleep(800_000);

        return 'slow PHP is fine';
    });

    expect($result)->toBe('slow PHP is fine')
        ->and($py->isOpen())->toBeTrue();
    $py->close();
});

test('callbacks called from another Python thread fail clearly', function (): void {
    $this->py->exec(<<<'PY'
        import threading

        def in_thread(f):
            out = []
            def run():
                try:
                    f()
                except RuntimeError as e:
                    out.append(str(e))
            t = threading.Thread(target=run)
            t.start()
            t.join()
            return out[0]
        PY);

    expect($this->py->functions->in_thread(fn () => 1))->toContain('thread');
});

test('callbacks stored in Python keep working in later calls', function (): void {
    $this->py->exec("handlers = []\ndef register(f):\n    handlers.append(f)\ndef fire(x):\n    return [h(x) for h in handlers]");
    $this->py->functions->register(fn (int $x): int => $x * 2);
    $this->py->functions->register(fn (int $x): int => $x + 1);

    expect($this->py->functions->fire(10))->toBe([20, 11]);
});

test('Python releases closures it no longer holds', function (): void {
    $closure = fn () => 1;
    $this->py->exec("def run(f):\n    return f()\nkept = []\ndef keep(f):\n    kept.append(f)");

    $this->py->functions->run($closure);
    $afterUse = $this->py->info()['callbacks'];
    $this->py->functions->keep($closure);
    $this->py->functions->keep($closure);
    $whileKept = $this->py->info()['callbacks'];
    $this->py->exec('kept.clear()');
    $this->py->info();

    expect($afterUse)->toBe(0)
        ->and($whileKept)->toBe(1)
        ->and($this->py->info()['callbacks'])->toBe(0);
});

test('closing the session inside a callback ends the call cleanly', function (): void {
    $py = testSession();
    $py->exec("def run(f):\n    return f()");

    expect(fn () => $py->functions->run(fn () => $py->close()))
        ->toThrow(SessionClosedException::class)
        ->and($py->isOpen())->toBeFalse();
});

test('pandas apply runs a PHP closure per value', function (): void {
    $pd = $this->py->import('pandas');
    $names = $pd->Series(['ahmed', 'sam']);

    expect($names->apply(ucfirst(...))->tolist())->toBe(['Ahmed', 'Sam'])
        ->and($pd->Series([1, 2, 3])->pipe(fn (PyObject $s) => $s->mul(10))->tolist())->toBe([10, 20, 30]);
})->skip(fn () => ! pythonHasModule('pandas'), 'pandas is not installed');
