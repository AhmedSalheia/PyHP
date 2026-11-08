<?php

declare(strict_types=1);

use PyHP\Exception\PythonException;
use PyHP\OutputMode;

test('onOutput receives what Python prints while the call is still running', function (): void {
    $py = testSession();
    $chunks = new ArrayObject;
    $py->onOutput(function (string $text) use ($chunks): void {
        $chunks->append($text);
    });
    // The PHP closure runs in the middle of the Python call: the first line must already be here.
    $py->vars->seenSoFar = fn (): string => implode('', $chunks->getArrayCopy());

    $py->exec("print('step 1')\nduring = seenSoFar()\nprint('step 2')\nprint('done', end='')");

    expect($py->vars->during)->toBe("step 1\n")
        ->and(implode('', $chunks->getArrayCopy()))->toBe("step 1\nstep 2\ndone")
        ->and($py->takeOutput())->toBe('');
    $py->close();
});

test('echo mode prints live, line by line', function (): void {
    $py = testSession(['output' => OutputMode::Echo]);
    $py->vars->echoedSoFar = fn (): string => (string) ob_get_contents();

    ob_start();
    try {
        $py->exec("print('loading...')\nduring = echoedSoFar()\nprint('ready')");
    } finally {
        $echoed = (string) ob_get_clean();
    }

    expect($py->vars->during)->toBe("loading...\n")
        ->and($echoed)->toBe("loading...\nready\n");
    $py->close();
});

test('capture mode still collects everything for takeOutput()', function (): void {
    $py = testSession();

    $py->exec("print('a')\nprint('b')");

    expect($py->takeOutput())->toBe("a\nb\n");
    $py->close();
});

test('a failing output callback is rethrown after the call has finished', function (): void {
    $py = testSession();
    $py->onOutput(function (string $text): void {
        throw new RuntimeException("rejected: {$text}");
    });

    expect(fn () => $py->exec("print('one')\nfinished = True"))->toThrow(RuntimeException::class, 'rejected: one');

    $py->onOutput(null);
    expect($py->vars->finished)->toBeTrue()
        ->and($py->eval('1 + 1'))->toBe(2);
    $py->close();
});

test('onOutput(null) goes back to the session output mode', function (): void {
    $py = testSession();
    $py->onOutput(fn (string $text) => null);
    $py->onOutput(null);

    $py->exec("print('captured again')");

    expect($py->takeOutput())->toBe("captured again\n");
    $py->close();
});

test('output of a failing Python call reaches the callback before the exception', function (): void {
    $py = testSession();
    $chunks = new ArrayObject;
    $py->onOutput(fn (string $text) => $chunks->append($text));

    expect(fn () => $py->exec("print('working')\nprint('partial', end='')\nraise ValueError('boom')"))
        ->toThrow(PythonException::class, 'boom');
    expect(implode('', $chunks->getArrayCopy()))->toBe("working\npartial");
    $py->close();
});
