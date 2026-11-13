<?php

declare(strict_types=1);

use PyHP\Exception\ProtocolException;
use PyHP\Exception\WorkerStartException;
use PyHP\Session;

/*
 * How PHP handles a worker that fails while starting, or doesn't speak the protocol.
 * tests/php/fixtures/fake-worker/sitecustomize.py plays the broken worker: Python
 * runs it before the worker script when its folder is on PYTHONPATH.
 */

/** A session whose worker acts out $mode (see the fixture); returns the exception it fails with. */
function startFakeWorker(string $mode, array $options = []): Throwable
{
    $env = ['PYTHONPATH' => __DIR__.DIRECTORY_SEPARATOR.'fixtures'.DIRECTORY_SEPARATOR.'fake-worker', 'PYHP_FAKE_WORKER' => $mode];
    try {
        $session = new Session(testConfig(['env' => $env + ($options['env'] ?? [])] + $options));
        $session->close();
    } catch (Throwable $error) {
        return $error;
    }
    throw new LogicException("The fake worker ({$mode}) started without an error.");
}

function processIsRunning(int $pid): bool
{
    if (function_exists('posix_kill')) {
        return posix_kill($pid, 0);
    }

    return is_dir("/proc/{$pid}");
}

test('a worker that exits while starting is reported with its exit code and log', function (): void {
    $error = startFakeWorker('exit:3:boom');

    expect($error)->toBeInstanceOf(WorkerStartException::class)
        ->and($error->getMessage())->toBe(
            'The Python worker started with "'.testPython().'" exited before it was ready (exit code 3).'
            ."\nWorker log:".PHP_EOL.'boom'
        );
});

test('exit code 127 means the interpreter was not found, and says what to do', function (): void {
    $error = startFakeWorker('exit:127:');

    expect($error->getMessage())->toBe(
        'The Python worker started with "'.testPython().'" exited before it was ready (exit code 127).'
        .' The interpreter was not found; set the "python" option to its full path.'
    );
});

test('only the end of a long worker log is shown', function (): void {
    $message = startFakeWorker('log:6000')->getMessage();

    expect($message)->toEndWith('Worker log:'.PHP_EOL.str_repeat('A', 1000).str_repeat('B', 3000))
        ->and($message)->not->toContain(str_repeat('A', 1001));
});

test('a worker that never gets ready is stopped after startup_timeout', function (): void {
    $pidFile = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pyhp-fake-pid-'.bin2hex(random_bytes(4));

    $error = startFakeWorker('hang', ['startup_timeout' => 1.5, 'env' => ['PYHP_FAKE_PID_FILE' => $pidFile]]);

    $pid = (int) @file_get_contents($pidFile);
    @unlink($pidFile);
    expect($error)->toBeInstanceOf(WorkerStartException::class)
        ->and($error->getMessage())->toBe('The Python worker started with "'.testPython().'" was not ready within 1.5 seconds.')
        ->and($pid)->toBeGreaterThan(0)
        ->and(PHP_OS_FAMILY === 'Windows' || ! processIsRunning($pid))->toBeTrue();
});

test('a worker that speaks another protocol version is refused with a fix', function (): void {
    $error = startFakeWorker('hello:{"op": "hello", "token": "TOKEN", "protocol": 99, "python": "3.13.0", "pid": 1}');

    expect($error)->toBeInstanceOf(ProtocolException::class)
        ->and($error->getMessage())->toBe('The Python worker speaks protocol 99 but this PHP code expects 4. Reinstall PyHP so both halves match.');
});

test('a handshake that is not PyHP\'s is refused', function (string $mode, string $message): void {
    $error = startFakeWorker($mode);

    expect($error)->toBeInstanceOf(ProtocolException::class)
        ->and($error->getMessage())->toBe($message);
})->with([
    'unreadable JSON' => ['hello:{nope', 'The PyHP worker sent an unreadable handshake.'],
    'another token' => ['hello:{"op": "hello", "token": "someone else", "protocol": 4}', 'The process on the PyHP socket is not the worker PyHP started.'],
    'not a hello' => ['hello:{"op": "ping", "token": "TOKEN", "protocol": 4}', 'The process on the PyHP socket is not the worker PyHP started.'],
    'empty frame' => ['raw:00000000', 'The process on the PyHP socket is not a PyHP worker.'],
    'frame too big for a hello' => ['raw:00010001', 'The process on the PyHP socket is not a PyHP worker.'],
]);

test('a frame that announces an impossible size stops the worker', function (): void {
    $env = ['PYTHONPATH' => __DIR__.DIRECTORY_SEPARATOR.'fixtures'.DIRECTORY_SEPARATOR.'fake-worker', 'PYHP_FAKE_WORKER' => 'bad-frame'];
    $py = new Session(testConfig(['env' => $env]));

    expect(fn () => $py->eval('1'))->toThrow(ProtocolException::class, 'The worker announced an invalid message size')
        ->and($py->isOpen())->toBeFalse();
});

test('broken binary frames from the worker are protocol errors', function (string $reply, string $message): void {
    $env = ['PYTHONPATH' => __DIR__.DIRECTORY_SEPARATOR.'fixtures'.DIRECTORY_SEPARATOR.'fake-worker', 'PYHP_FAKE_WORKER' => 'reply:'.bin2hex($reply)];
    $py = new Session(testConfig(['env' => $env]));

    expect(fn () => $py->eval('1'))->toThrow(ProtocolException::class, $message);
})->with([
    'negative count' => [fakeFrame(['ok' => true, 'value' => 1, 'out' => '', 'bin' => -1]), 'The worker announced an invalid number of binary frames.'],
    'count not an int' => [fakeFrame(['ok' => true, 'value' => 1, 'out' => '', 'bin' => '1']), 'The worker announced an invalid number of binary frames.'],
    'frame too large' => [fakeFrame(['ok' => true, 'value' => 1, 'out' => '', 'bin' => 1]).pack('N', 2 ** 31), 'The worker announced an invalid message size'],
    'missing buffer' => [fakeFrame(['ok' => true, 'value' => fakeArray('f8', [1], 1), 'out' => '', 'bin' => 1]).pack('N', 8).pack('e', 1.0), 'The worker referred to array buffer #1, which it did not send.'],
    'broken tag' => [fakeFrame(['ok' => true, 'value' => fakeArray('f8', [-1], 0), 'out' => '', 'bin' => 1]).pack('N', 0), 'Invalid array on the wire.'],
    'wrong size' => [fakeFrame(['ok' => true, 'value' => fakeArray('f8', [2], 0), 'out' => '', 'bin' => 1]).pack('N', 8).pack('e', 1.0), 'The worker sent 8 bytes for an array of shape [2] and dtype f8, which needs 16.'],
]);

/** @param array<string, mixed> $message */
function fakeFrame(array $message): string
{
    $body = (string) json_encode($message);

    return pack('N', strlen($body)).$body;
}

/** @param list<int> $shape @return array<string, mixed> */
function fakeArray(string $dtype, array $shape, int $buffer): array
{
    return ['$t' => 'ndarray', 'dtype' => $dtype, 'shape' => $shape, 'buf' => $buffer];
}
