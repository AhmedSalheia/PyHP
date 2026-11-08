<?php

declare(strict_types=1);

/*
 * Real-world check of `pyhp setup`, run by CI on Linux, Windows and macOS.
 *
 * Builds a throwaway Composer project that installs PyHP from this checkout,
 * plus a local Composer package that declares its own Python requirement, then:
 * runs the real `pyhp setup` (twice, to prove it can be repeated) and `pyhp info`,
 * checks that a PyHP session really runs in the new venv with both packages,
 * writes `pyhp.lock`, and checks that `pyhp setup --exact` removes a stray package.
 *
 * Usage, from the repository root:
 *   php tests/smoke/setup-smoke.php            uses uv (must be installed)
 *   php tests/smoke/setup-smoke.php --no-uv    uses the system Python with venv and pip
 */

$repository = dirname(__DIR__, 2);
$noUv = in_array('--no-uv', $argv, true);
$project = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pyhp-smoke-'.bin2hex(random_bytes(4));

function step(string $title): void
{
    echo PHP_EOL."== {$title}".PHP_EOL;
}

function fail(string $message): never
{
    fwrite(STDERR, PHP_EOL."SMOKE TEST FAILED: {$message}".PHP_EOL);
    exit(1);
}

/** Runs a shell command in $cwd, showing its output; fails the test on a non-zero exit. */
function run(string $command, string $cwd): void
{
    echo "> {$command}".PHP_EOL;
    $previous = getcwd();
    chdir($cwd);
    passthru($command, $code);
    chdir($previous === false ? $cwd : $previous);
    if ($code !== 0) {
        fail("`{$command}` exited with code {$code}");
    }
}

/** Runs a shell command in $cwd and returns its output (stdout and stderr). */
function capture(string $command, string $cwd): string
{
    $previous = getcwd();
    chdir($cwd);
    exec($command.' 2>&1', $lines, $code);
    chdir($previous === false ? $cwd : $previous);
    $output = implode(PHP_EOL, $lines);
    echo $output.PHP_EOL;
    if ($code !== 0) {
        fail("`{$command}` exited with code {$code}");
    }

    return $output;
}

step("Creating a project in {$project}");
mkdir($project.'/packages/text-tools', 0777, true);
file_put_contents($project.'/packages/text-tools/composer.json', json_encode([
    'name' => 'pyhp-smoke/text-tools',
    'version' => '1.0.0',
    'extra' => ['pyhp' => ['requirements' => ['idna']]],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
file_put_contents($project.'/composer.json', json_encode([
    'name' => 'pyhp/smoke-test',
    'type' => 'project',
    'repositories' => [['type' => 'path', 'url' => $repository], ['type' => 'path', 'url' => 'packages/text-tools']],
    'require' => ['pyhp/pyhp' => '*@dev', 'pyhp-smoke/text-tools' => '*'],
    'extra' => ['pyhp' => ['python' => '3.12', 'requirements' => ['six']]],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
file_put_contents($project.'/check.php', <<<'PHP'
    <?php
    require __DIR__.'/vendor/autoload.php';
    $py = PyHP\Py::session();
    $prefix = (string) $py->eval('__import__("sys").prefix');
    $version = (string) $py->eval('"%d.%d" % __import__("sys").version_info[:2]');
    $six = (string) $py->eval('__import__("six").__version__');
    $idna = (string) $py->eval('__import__("idna").__version__'); // from pyhp-smoke/text-tools
    $py->close();
    $venv = realpath(__DIR__.DIRECTORY_SEPARATOR.'.venv');
    echo "Session prefix: {$prefix}\nPython {$version}, six {$six}, idna {$idna}\n";
    if ($venv === false || realpath($prefix) !== $venv) {
        fwrite(STDERR, "The session does not run in {$venv}\n");
        exit(1);
    }
    PHP);

$php = escapeshellarg(PHP_BINARY);
$setup = "{$php} vendor/bin/pyhp setup".($noUv ? ' --no-uv' : '');

step('composer install');
run('composer install --no-interaction --no-progress', $project);

step('pyhp setup');
run($setup, $project);

step('pyhp info');
run("{$php} vendor/bin/pyhp info", $project);

step('A session uses the new venv');
run("{$php} check.php", $project);

step('pyhp setup again reuses the venv');
if (! str_contains(capture($setup, $project), 'already exists')) {
    fail('the second setup did not reuse the existing venv');
}

step('pyhp lock writes exact versions of every package');
run("{$php} vendor/bin/pyhp lock".($noUv ? ' --no-uv' : ''), $project);
$lock = (string) @file_get_contents($project.'/pyhp.lock');
if (! str_contains($lock, 'six==') || ! str_contains($lock, 'idna==')) {
    fail("pyhp.lock doesn't pin six and idna:\n{$lock}");
}

step('pyhp setup --exact removes a package nobody asked for');
$venvPython = escapeshellarg($project.DIRECTORY_SEPARATOR.'.venv'.DIRECTORY_SEPARATOR
    .(PHP_OS_FAMILY === 'Windows' ? 'Scripts'.DIRECTORY_SEPARATOR.'python.exe' : 'bin'.DIRECTORY_SEPARATOR.'python'));
run($noUv ? "{$venvPython} -m pip install --quiet colorama" : "uv pip install --python {$venvPython} colorama", $project);
run("{$venvPython} -c \"import colorama\"", $project);
run("{$setup} --exact", $project);
exec("{$venvPython} -c \"import colorama\" 2>&1", $ignored, $stillThere);
if ($stillThere === 0) {
    fail('colorama is still installed after pyhp setup --exact');
}
run("{$venvPython} -c \"import six, idna\"", $project);

echo PHP_EOL.'Smoke test passed ('.($noUv ? 'system Python with venv and pip' : 'uv').').'.PHP_EOL;
