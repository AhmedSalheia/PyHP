<?php

declare(strict_types=1);

// Measures PyHP's own overhead on this machine.
// Run WITHOUT coverage (Xdebug mode off), e.g.:
//   php benchmarks/overhead.php C:\path\to\.venv\Scripts\python.exe

require __DIR__.'/../vendor/autoload.php';

use PyHP\Config;
use PyHP\OutputMode;
use PyHP\Session;

$python = $argv[1] ?? Config::defaultPython();
$config = new Config(python: $python, output: OutputMode::Capture);

$ms = static fn (float $start): float => (microtime(true) - $start) * 1000;

printf("PHP %s on %s, xdebug.mode=%s\n", PHP_VERSION, PHP_OS_FAMILY, ini_get('xdebug.mode') ?: 'n/a');

$starts = [];
for ($i = 0; $i < 5; $i++) {
    $t = microtime(true);
    $s = new Session($config);
    $starts[] = $ms($t);
    $s->close();
}
sort($starts);
printf("Session start-up (median of 5):  %7.1f ms\n", $starts[2]);

$py = new Session($config);
printf("Python %s\n", $py->info()['python']);

$n = 2000;
$t = microtime(true);
for ($i = 0; $i < $n; $i++) {
    $py->eval('1');
}
printf("Round trip, eval('1'):            %7.1f us\n", $ms($t) * 1000 / $n);

$py->exec("class O:\n    x = 1\no = O()");
$o = $py->vars->o;
$t = microtime(true);
for ($i = 0; $i < $n; $i++) {
    $o->x;
}
printf("Attribute read on a proxy:        %7.1f us\n", $ms($t) * 1000 / $n);

$hasPandas = $py->eval("__import__('importlib.util').util.find_spec('pandas') is not None");
if ($hasPandas === true) {
    $t = microtime(true);
    $py->import('pandas', as: 'pd');
    printf("import pandas:                    %7.1f ms\n", $ms($t));

    $py->exec("import numpy as np\ndf = pd.DataFrame({'g': np.random.randint(0, 10, 1_000_000), 'v': np.random.rand(1_000_000)})");
    $df = $py->vars->df;
    $t = microtime(true);
    $df->groupby('g')['v']->mean()->to_dict();
    printf("1M-row groupby via proxies:       %7.1f ms\n", $ms($t));
    $t = microtime(true);
    $py->eval("df.groupby('g')['v'].mean().to_dict()");
    printf("Same groupby in one eval:         %7.1f ms\n", $ms($t));
    $t = microtime(true);
    $py->eval("df.head(100_000).to_dict('records')");
    printf("Copy 100k rows to PHP:            %7.1f ms\n", $ms($t));
} else {
    echo "pandas not installed: skipping pandas timings\n";
}

$t = microtime(true);
$py->close();
printf("close():                          %7.1f ms\n", $ms($t));
