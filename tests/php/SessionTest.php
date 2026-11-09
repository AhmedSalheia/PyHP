<?php

declare(strict_types=1);

use PyHP\Exception\MessageTooLargeException;
use PyHP\Exception\PythonException;
use PyHP\Exception\SessionClosedException;
use PyHP\Exception\TimeoutException;
use PyHP\Exception\WorkerCrashedException;
use PyHP\Exception\WorkerStartException;
use PyHP\OutputMode;

test('exec keeps Python state between calls', function (): void {
    $py = testSession();
    $py->exec('total = 40');
    $py->exec('total += 2');

    expect($py->eval('total'))->toBe(42);
    $py->close();
});

test('eval returns plain PHP values', function (): void {
    $py = testSession();

    expect($py->eval('1 + 1'))->toBe(2)
        ->and($py->eval('0.5 * 3'))->toBe(1.5)
        ->and($py->eval('"ünïcode ✓"'))->toBe('ünïcode ✓')
        ->and($py->eval('None'))->toBeNull()
        ->and($py->eval('[1, (2, 3)]'))->toBe([1, [2, 3]])
        ->and($py->eval('{"a": 1, "b": [True]}'))->toBe(['a' => 1, 'b' => [true]]);
    $py->close();
});

test('printed output is captured in capture mode', function (): void {
    $py = testSession(['output' => OutputMode::Capture]);
    $py->exec('print("one")');
    $py->exec('print("two")');

    expect($py->takeOutput())->toBe("one\ntwo\n")
        ->and($py->takeOutput())->toBe('');
    $py->close();
});

test('printed output is echoed in echo mode', function (): void {
    $py = testSession(['output' => OutputMode::Echo]);
    ob_start();
    $py->exec('print("hello from python")');
    $echoed = ob_get_clean();

    expect($echoed)->toBe("hello from python\n");
    $py->close();
});

test('output printed before an error is still delivered', function (): void {
    $py = testSession();
    try {
        $py->exec("print('before')\nraise RuntimeError('x')");
    } catch (PythonException) {
    }

    expect($py->takeOutput())->toBe("before\n");
    $py->close();
});

test('python exceptions become PythonException with details and the session survives', function (): void {
    $py = testSession();

    try {
        $py->exec("def explode():\n    raise ValueError('boom')\nexplode()");
        $this->fail('expected an exception');
    } catch (PythonException $error) {
        expect($error->pythonType)->toBe('ValueError')
            ->and($error->pythonMessage)->toBe('boom')
            ->and($error->getMessage())->toBe('ValueError: boom')
            ->and($error->pythonTraceback)->toContain("raise ValueError('boom')");
    }

    expect($py->eval('1'))->toBe(1);
    $py->close();
});

test('execFile runs a script in the shared namespace', function (): void {
    $script = tempnam(sys_get_temp_dir(), 'pyhp').'.py';
    file_put_contents($script, "def double(x):\n    return x * 2\n");
    $py = testSession();
    $py->execFile($script);

    expect($py->functions->double(21))->toBe(42);
    $py->close();
    unlink($script);
});

test('execFile of a missing file raises FileNotFoundError', function (): void {
    $py = testSession();

    expect(fn () => $py->execFile('/definitely/not/here.py'))
        ->toThrow(PythonException::class, 'FileNotFoundError');
    $py->close();
});

test('info reports the interpreter', function (): void {
    $py = testSession();
    $info = $py->info();

    expect($info['protocol'])->toBe(3)
        ->and($info['python'])->toMatch('/^3\.\d+/')
        ->and($info['objects'])->toBe(0);
    $py->close();
});

test('a closed session refuses further calls', function (): void {
    $py = testSession();
    $py->close();
    $py->close(); // closing twice is harmless

    expect($py->isOpen())->toBeFalse()
        ->and(fn () => $py->eval('1'))->toThrow(SessionClosedException::class);
});

test('a missing interpreter fails to start with a clear message', function (): void {
    expect(fn () => testSession(['python' => 'pyhp-no-such-python-binary']))
        ->toThrow(WorkerStartException::class, 'pyhp-no-such-python-binary');
});

test('a call that exceeds the timeout stops the worker', function (): void {
    $py = testSession(['timeout' => 0.5]);

    expect(fn () => $py->exec('import time; time.sleep(5)'))
        ->toThrow(TimeoutException::class, '0.5')
        ->and($py->isOpen())->toBeFalse();
});

test('a crashed worker is reported with its log', function (): void {
    $py = testSession();

    expect(fn () => $py->exec("import os, sys\nprint('last words', file=sys.stderr)\nos._exit(7)"))
        ->toThrow(WorkerCrashedException::class, 'last words')
        ->and($py->isOpen())->toBeFalse();
});

test('stderr and low-level output go to the log', function (): void {
    $log = tempnam(sys_get_temp_dir(), 'pyhp-log');
    $py = testSession(['log_file' => $log]);
    $py->exec("import sys\nprint('warned', file=sys.stderr)");

    expect($py->log())->toContain('warned');
    $py->close();
    expect(file_get_contents($log))->toContain('warned');
    unlink($log);
});

test('oversized results are refused without killing the session', function (): void {
    $py = testSession(['max_message_bytes' => 2000]);

    expect(fn () => $py->eval("'x' * 10000"))->toThrow(MessageTooLargeException::class)
        ->and($py->eval('2'))->toBe(2);
    $py->close();
});

test('oversized requests are refused before sending', function (): void {
    $py = testSession(['max_message_bytes' => 2000]);

    expect(fn () => $py->vars->big = str_repeat('y', 5000))->toThrow(MessageTooLargeException::class)
        ->and($py->eval('3'))->toBe(3);
    $py->close();
});

test('env, cwd and extra paths reach the worker', function (): void {
    $dir = sys_get_temp_dir().'/pyhp-test-'.bin2hex(random_bytes(4));
    mkdir($dir);
    file_put_contents($dir.'/pyhp_local_module.py', "VALUE = 'local'\n");
    $py = testSession(['cwd' => $dir, 'env' => ['PYHP_TEST_FLAG' => 'on'], 'paths' => [$dir]]);

    expect($py->eval("__import__('os').environ['PYHP_TEST_FLAG']"))->toBe('on')
        ->and($py->eval("__import__('os').path.realpath(__import__('os').getcwd())"))->toBe(realpath($dir))
        ->and($py->eval("__import__('pyhp_local_module').VALUE"))->toBe('local');
    $py->close();
    removeDirectory($dir);
});

test('modules in the working directory are not importable unless it is in paths', function (): void {
    $dir = sys_get_temp_dir().'/pyhp-test-'.bin2hex(random_bytes(4));
    mkdir($dir);
    file_put_contents($dir.'/pyhp_cwd_module.py', "VALUE = 'shadow'\n");
    $py = testSession(['cwd' => $dir]);
    $withPaths = testSession(['cwd' => $dir, 'paths' => [$dir]]);

    expect($py->eval("__import__('importlib.util').util.find_spec('pyhp_cwd_module') is None"))->toBeTrue()
        ->and($withPaths->eval("__import__('pyhp_cwd_module').VALUE"))->toBe('shadow');
    $py->close();
    $withPaths->close();
    removeDirectory($dir);
});

test('a failed start leaves no temporary log file behind', function (): void {
    $pattern = sys_get_temp_dir().DIRECTORY_SEPARATOR.(PHP_OS_FAMILY === 'Windows' ? 'pyh*.tmp' : 'pyhp-*');
    $before = glob($pattern) ?: [];

    try {
        testSession(['python' => 'pyhp-no-such-python-binary']);
    } catch (WorkerStartException) {
    }

    expect(glob($pattern) ?: [])->toBe($before);
});

test('starting a session pays no fixed delay', function (): void {
    // PHP on Windows waits 500 ms whenever it closes a listening socket. PyHP must
    // never own one, so a session should start in roughly Python's own start-up time.
    $start = hrtime(true);
    $py = testSession();
    $elapsedMs = (hrtime(true) - $start) / 1e6;
    $py->close();

    expect($elapsedMs)->toBeLessThan(450.0);
});
