<?php

declare(strict_types=1);

// Copying NumPy arrays between PHP and Python: the JSON way (->tolist(), and a
// PHP array turned into an array by np.array()) against binary frames
// (Py::toPhp() and Py::ndarray()). Needs NumPy. Run WITHOUT coverage, e.g.:
//   php benchmarks/numpy-transfer.php C:\path\to\.venv\Scripts\python.exe

require __DIR__.'/../vendor/autoload.php';

use PyHP\Config;
use PyHP\OutputMode;
use PyHP\Py;
use PyHP\Session;

$python = $argv[1] ?? Config::defaultPython();
$py = new Session(new Config(python: $python, output: OutputMode::Capture, maxMessageBytes: 256 * 1024 * 1024));

printf("PHP %s on %s, Python %s, xdebug.mode=%s\n", PHP_VERSION, PHP_OS_FAMILY, $py->info()['python'], ini_get('xdebug.mode') ?: 'n/a');

/** Best of three, in milliseconds. */
$best = static function (Closure $run): float {
    $times = [];
    for ($i = 0; $i < 3; $i++) {
        $start = microtime(true);
        $run();
        $times[] = (microtime(true) - $start) * 1000;
    }

    return min($times);
};

$py->exec(<<<'PY'
import numpy as np
rng = np.random.default_rng(1)
arrays = {
    '1,000,000 float64': rng.random(1_000_000),
    '1,000,000 int64': np.arange(1_000_000),
    '1000 x 1000 float64': rng.random((1000, 1000)),
    '1000 x 1000 uint8': rng.integers(0, 255, (1000, 1000), dtype='uint8'),
}
PY);

printf("\nPython -> PHP          ->tolist()   Py::toPhp()\n");
foreach ($py->eval('list(arrays)') as $name) {
    $array = $py->eval('arrays')[$name];
    $json = $best(fn () => $array->tolist());
    $binary = $best(fn () => Py::toPhp($array));
    printf("%-20s %9.0f ms %10.0f ms  %5.1fx\n", $name, $json, $binary, $json / $binary);
}

$data = Py::toPhp($py->eval("arrays['1000 x 1000 float64']"));
$json = $best(function () use ($py, $data): void {
    $py->vars->x = $data;
    $py->exec('x = np.array(x)');
});
$binary = $best(function () use ($py, $data): void {
    $py->vars->x = Py::ndarray($data);
});
printf("\nPHP -> Python          np.array()   Py::ndarray()\n");
printf("%-20s %9.0f ms %10.0f ms  %5.1fx\n", '1000 x 1000 float64', $json, $binary, $json / $binary);

$py->close();
