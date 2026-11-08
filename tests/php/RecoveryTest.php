<?php

declare(strict_types=1);

use PyHP\Exception\PyHPException;
use PyHP\Exception\SessionClosedException;
use PyHP\Exception\StaleProxyException;
use PyHP\Exception\TimeoutException;
use PyHP\Exception\WorkerCrashedException;
use PyHP\Py;
use PyHP\Session;

/** Kills the worker the way a segfault in a C extension would. */
function crash(Session $py): void
{
    try {
        $py->exec("import os\nos._exit(9)");
    } catch (WorkerCrashedException) {
        return;
    }
    throw new LogicException('the worker did not crash');
}

afterEach(function (): void {
    Py::closeShared();
});

test('without the restart option a crashed session stays dead', function (): void {
    $py = testSession();
    crash($py);

    expect(fn () => $py->eval('1'))->toThrow(SessionClosedException::class);
});

test('with restart, the call that crashes still fails but the next one gets a fresh worker', function (): void {
    $py = testSession(['restart' => true]);
    $py->vars->kept = 'gone after the crash';
    $firstPid = $py->info()['pid'];
    crash($py);

    expect($py->eval('1 + 1'))->toBe(2)
        ->and($py->info()['pid'])->not->toBe($firstPid)
        ->and(isset($py->vars->kept))->toBeFalse();
    $py->close();
});

test('onStart hooks run immediately and again after every restart', function (): void {
    $py = testSession(['restart' => true]);
    $starts = new ArrayObject;
    $py->onStart(function (Session $session) use ($starts): void {
        $starts->append(true);
        $session->exec('import math');
        $session->vars->ready = true;
    });

    crash($py);

    expect($py->eval('math.sqrt(16)'))->toBe(4.0)
        ->and($py->vars->ready)->toBeTrue()
        ->and(count($starts))->toBe(2);
    $py->close();
});

test('a timed-out call kills the worker; with restart the session carries on', function (): void {
    $py = testSession(['restart' => true, 'timeout' => 0.5]);

    expect(fn () => $py->exec('import time; time.sleep(5)'))->toThrow(TimeoutException::class);
    expect($py->eval('"alive"'))->toBe('alive');
    $py->close();
});

test('proxies from before a restart are refused instead of hitting other objects', function (): void {
    $py = testSession(['restart' => true]);
    $old = $py->eval('[1, 2, 3].__iter__()');
    crash($py);
    $new = $py->eval('{"fresh"}');

    expect(fn () => $old->__next__())->toThrow(StaleProxyException::class, 'restarted')
        ->and(fn () => $py->functions->len($old))->toThrow(StaleProxyException::class)
        ->and(fn () => Py::len($old))->toThrow(StaleProxyException::class);

    unset($old); // its release must not free anything in the new worker
    expect(Py::len($new))->toBe(1)
        ->and($py->info()['objects'])->toBe(1);
    $py->close();
});

test('restart() resets the session on demand and runs the hooks', function (): void {
    $py = testSession();
    $py->onStart(fn (Session $s) => $s->vars->hooked = 'yes');
    $py->vars->temporary = 1;
    $proxy = $py->eval('object()');

    $py->restart();

    expect(isset($py->vars->temporary))->toBeFalse()
        ->and($py->vars->hooked)->toBe('yes')
        ->and(fn () => Py::type($proxy))->toThrow(StaleProxyException::class);
    $py->close();
});

test('an explicitly closed session is never restarted', function (): void {
    $py = testSession(['restart' => true]);
    $py->close();

    expect(fn () => $py->eval('1'))->toThrow(SessionClosedException::class);
});

test('ping() tells whether the worker answers', function (): void {
    $py = testSession(['restart' => true]);

    expect($py->ping())->toBeTrue();
    crash($py);
    expect($py->ping())->toBeTrue(); // restarted to answer
    $py->close();
    expect($py->ping())->toBeFalse();

    $plain = testSession();
    crash($plain);
    expect($plain->ping())->toBeFalse();
});

test('restarting from inside a PHP callback is refused', function (): void {
    $py = testSession();
    $py->vars->reset = fn () => $py->restart();

    expect(fn () => $py->eval('reset()'))->toThrow(PyHPException::class, 'while a call is running');
    $py->close();
});

test('Py::shared returns one long-lived, self-healing session per name', function (): void {
    Py::config(['python' => testPython(), 'output' => 'capture']);
    $setups = new ArrayObject;
    $setup = function (Session $py) use ($setups): void {
        $setups->append(true);
        $py->exec('import statistics');
    };

    $a = Py::shared('reports', $setup);
    $again = Py::shared('reports', $setup);
    $other = Py::shared('other');
    crash($a);

    expect($again)->toBe($a)
        ->and($other)->not->toBe($a)
        ->and($a->eval('statistics.mean([1, 2, 3])'))->toBe(2)
        ->and(count($setups))->toBe(2);

    Py::closeShared('reports');
    expect(Py::shared('reports'))->not->toBe($a);
    Py::reset(forgetConfig: true);
});
