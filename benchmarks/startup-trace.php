<?php

declare(strict_types=1);

// Times each step of starting a PyHP worker, to find where start-up time goes.
// Run without coverage:
//   php benchmarks/startup-trace.php C:\path\to\.venv\Scripts\python.exe

require __DIR__.'/../vendor/autoload.php';

use PyHP\Config;
use PyHP\OutputMode;
use PyHP\Session;

$python = $argv[1] ?? Config::defaultPython();
$runs = 5;
$nullDevice = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';

/** Median duration in ms of $runs calls of $step. */
function median(int $runs, Closure $step): float
{
    $times = [];
    for ($i = 0; $i < $runs; $i++) {
        $start = hrtime(true);
        $step();
        $times[] = (hrtime(true) - $start) / 1e6;
    }
    sort($times);

    return $times[intdiv($runs, 2)];
}

/** @param resource $process */
function waitForExit($process): void
{
    while (proc_get_status($process)['running']) {
        usleep(1000);
    }
    proc_close($process);
}

$row = static fn (string $label, float $ms) => printf("  %-58s %8.1f ms\n", $label, $ms);

printf("PHP %s on %s, Python: %s\n\n", PHP_VERSION, PHP_OS_FAMILY, $python);
echo "PHP-side steps (median of {$runs}):\n";

$row('open + close a listening socket in PHP (PyHP avoids this)', median($runs, function (): void {
    $server = stream_socket_server('tcp://127.0.0.1:0');
    fclose($server);
}));

$row('tempnam() for the log file', median($runs, function (): void {
    $path = tempnam(sys_get_temp_dir(), 'pyhp-');
    unlink($path);
}));

$row('getenv() (copy of the environment)', median($runs, fn () => getenv()));

$row('proc_open of a missing program (fails)', median($runs, function () use ($nullDevice): void {
    $process = @proc_open(['pyhp-no-such-program'], [0 => ['file', $nullDevice, 'r']], $pipes);
    if (is_resource($process)) {
        waitForExit($process);
    }
}));

echo "\nStarting Python (median of {$runs}, until the process exits):\n";

$spawn = function (array $extraArgs, ?array $env) use ($python, $nullDevice): void {
    $log = tempnam(sys_get_temp_dir(), 'pyhp-');
    $process = proc_open(
        [$python, ...$extraArgs],
        [0 => ['file', $nullDevice, 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
        $pipes,
        null,
        $env,
    );
    waitForExit($process);
    unlink($log);
};

$row('python -c pass (inherited environment)', median($runs, fn () => $spawn(['-c', 'pass'], null)));
$row('python -c pass (environment passed as array, like PyHP)', median($runs, fn () => $spawn(['-c', 'pass'], getenv())));
$row('python -S -E -c pass (no site-packages scan)', median($runs, fn () => $spawn(['-S', '-E', '-c', 'pass'], null)));
$row('python -u -c "import socket, json, ..." (worker imports)', median($runs, fn () => $spawn(
    ['-u', '-c', 'import base64, builtins, contextlib, importlib, io, json, linecache, math, os, re, socket, struct, sys, tokenize, traceback, types'],
    null,
)));

echo "\nFull PyHP session (median of {$runs}):\n";

$config = new Config(python: $python, output: OutputMode::Capture);
$starts = [];
$closes = [];
for ($i = 0; $i < $runs; $i++) {
    $start = hrtime(true);
    $session = new Session($config);
    $starts[] = (hrtime(true) - $start) / 1e6;
    $start = hrtime(true);
    $session->close();
    $closes[] = (hrtime(true) - $start) / 1e6;
}
sort($starts);
sort($closes);
$row('new Session() (until the handshake is done)', $starts[intdiv($runs, 2)]);
$row('close()', $closes[intdiv($runs, 2)]);

echo "\nSlowest imports inside the worker (python -X importtime):\n";

$log = tempnam(sys_get_temp_dir(), 'pyhp-trace-');
$session = new Session(new Config(python: $python, output: OutputMode::Capture, logFile: $log, env: ['PYTHONPROFILEIMPORTTIME' => '1']));
$session->close();
$imports = [];
foreach (file($log) ?: [] as $line) {
    if (preg_match('/^import time:\s+(\d+)\s+\|\s+(\d+)\s+\|\s(\s*)(\S+)/', $line, $m) === 1 && $m[3] === '') {
        $imports[$m[4]] = (int) $m[2] / 1000;
    }
}
arsort($imports);
foreach (array_slice($imports, 0, 8, true) as $module => $ms) {
    $row("import {$module}", $ms);
}
printf("  %-58s %8.1f ms\n", 'total of top-level imports', array_sum($imports));
unlink($log);
