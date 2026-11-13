<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use PyHP\Console\InfoCommand;
use PyHP\Setup\LockFile;
use PyHP\Setup\ProjectRequirements;
use PyHP\Setup\SetupConfig;
use PyHP\Setup\VirtualEnv;

/*
 * The whole output of `pyhp info`, line by line, in text and JSON, for a few
 * fixed projects. The other InfoCommand tests check facts; these pin the report
 * people actually read (and scripts parse), so a changed label, layout or field
 * doesn't slip through. Only what depends on this machine is computed.
 */

beforeEach(function (): void {
    $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pyhp-info-out-'.bin2hex(random_bytes(4));
    mkdir($this->root);
    $this->previousEnv = getenv('PYHP_PYTHON');
    putenv('PYHP_PYTHON');
    $output = new ArrayObject;
    $this->output = $output;
    $this->info = function (FakeCommandRunner $runner, ?string $workerError = null, bool $json = false) use ($output): array {
        $output->exchangeArray([]);
        $command = new InfoCommand($runner, function (string $text) use ($output): void {
            $output->append($text);
        }, static fn (string $python): ?string => $workerError);
        $code = $command->run($this->root, $json);

        // Lines end with PHP_EOL, which is "\r\n" on Windows.
        $text = str_replace("\r\n", "\n", implode('', $output->getArrayCopy()));

        return [$code, preg_replace('/answered in \d+ ms/', 'answered in N ms', $text)];
    };
    $this->defaultPython = PHP_OS_FAMILY === 'Windows' ? 'python' : 'python3';
    $this->venv = $this->root.DIRECTORY_SEPARATOR.'.venv';
});

afterEach(function (): void {
    putenv($this->previousEnv === false ? 'PYHP_PYTHON' : 'PYHP_PYTHON='.$this->previousEnv);
    removeDirectory($this->root);
});

function goldenProbe(string $python, array $packages): string
{
    return implode(' ', [$python, '-c', InfoCommand::PYTHON_PROBE, json_encode($packages, JSON_THROW_ON_ERROR)]);
}

/** @param array<string, ?string> $packages */
function goldenAnswer(string $version, array $packages): string
{
    return json_encode([
        'version' => $version, 'implementation' => 'CPython', 'executable' => '/somewhere/python',
        'prefix' => '/somewhere', 'packages' => (object) $packages,
    ], JSON_THROW_ON_ERROR);
}

function goldenPyhpVersion(): string
{
    if (class_exists(InstalledVersions::class) && InstalledVersions::isInstalled('pyhp/pyhp')) {
        return InstalledVersions::getPrettyVersion('pyhp/pyhp') ?? 'unknown version';
    }

    return 'unknown version';
}

/** The first part of every text report: PyHP's version and this PHP. */
function goldenHeader(): string
{
    $ini = php_ini_loaded_file();
    $disabled = array_map(trim(...), explode(',', (string) ini_get('disable_functions')));
    $procOpen = function_exists('proc_open') && ! in_array('proc_open', $disabled, true);

    return "\nPyHP ".goldenPyhpVersion()."\n"
        ."\nPHP\n"
        .sprintf("  Version        %s (%s, %s, %d-bit)\n", PHP_VERSION, PHP_SAPI, PHP_ZTS ? 'ZTS' : 'NTS', PHP_INT_SIZE * 8)
        .'  Binary         '.PHP_BINARY."\n"
        .sprintf("  OS             %s %s (%s)\n", PHP_OS_FAMILY, php_uname('r'), php_uname('m'))
        .'  php.ini        '.($ini === false ? 'none loaded' : $ini)."\n"
        .'  proc_open      '.($procOpen ? 'available' : 'DISABLED')."\n";
}

/** The `php` and `pyhp` sections of the JSON report. */
function goldenJsonPhp(): array
{
    $ini = php_ini_loaded_file();
    $disabled = array_map(trim(...), explode(',', (string) ini_get('disable_functions')));

    return [
        'pyhp' => ['version' => goldenPyhpVersion()],
        'php' => [
            'version' => PHP_VERSION,
            'sapi' => PHP_SAPI,
            'zts' => PHP_ZTS === 1,
            'bits' => PHP_INT_SIZE * 8,
            'binary' => PHP_BINARY,
            'os' => PHP_OS_FAMILY,
            'os_release' => php_uname('r'),
            'machine' => php_uname('m'),
            'ini' => $ini === false ? null : $ini,
            'proc_open' => function_exists('proc_open') && ! in_array('proc_open', $disabled, true),
        ],
    ];
}

function goldenVenvPython(string $venv): string
{
    $python = VirtualEnv::python($venv);
    mkdir(dirname($python), 0777, true);
    touch($python);

    return $python;
}

const GOLDEN_NOTE = "\n  An application can still choose another interpreter with Py::config(['python' => ...]).\n";

test('a healthy project, in text', function (): void {
    file_put_contents($this->root.'/composer.json', json_encode(['extra' => ['pyhp' => ['python' => '3.12', 'requirements' => ['pandas>=2']]]]));
    $python = goldenVenvPython($this->venv);
    $runner = new FakeCommandRunner(programs: ['uv' => '/bin/uv'], captures: [
        goldenProbe($python, ['pandas']) => goldenAnswer('3.12.7', ['pandas' => '3.1.1']),
        '/bin/uv --version' => 'uv 0.9.5',
    ]);

    [$code, $text] = ($this->info)($runner);

    expect($code)->toBe(0)
        ->and($text)->toBe(goldenHeader()
            ."\nProject\n"
            ."  Root           {$this->root}\n"
            ."  Python wanted  3.12\n"
            ."  Requirements   pandas>=2\n"
            ."  From packages  none\n"
            ."  Lock file      none (optional; vendor/bin/pyhp lock writes one with exact versions)\n"
            ."\nPython\n"
            ."  Interpreter    {$python}\n"
            ."  Chosen by      the project's virtual environment\n"
            ."  Project venv   {$this->venv}\n"
            ."  uv             uv 0.9.5 at /bin/uv\n"
            ."  Version        3.12.7 (CPython)\n"
            ."  Executable     /somewhere/python\n"
            ."  Packages       pandas 3.1.1\n"
            ."  Worker         started and answered in N ms\n"
            .GOLDEN_NOTE
            ."\nEverything looks fine.\n");
});

test('a healthy project, in JSON', function (): void {
    file_put_contents($this->root.'/composer.json', json_encode(['extra' => ['pyhp' => ['python' => '3.12', 'requirements' => ['pandas>=2']]]]));
    $python = goldenVenvPython($this->venv);
    $runner = new FakeCommandRunner(programs: ['uv' => '/bin/uv'], captures: [
        goldenProbe($python, ['pandas']) => goldenAnswer('3.12.7', ['pandas' => '3.1.1']),
        '/bin/uv --version' => 'uv 0.9.5',
    ]);

    [$code, $text] = ($this->info)($runner, json: true);
    $data = json_decode($text, true, 32, JSON_THROW_ON_ERROR);
    $data['python']['worker']['ms'] = 0;

    expect($code)->toBe(0)
        ->and($data)->toBe([
            'format' => 1,
            'ok' => true,
            ...goldenJsonPhp(),
            'project' => [
                'root' => $this->root,
                'python' => '3.12',
                'requirements_file' => null,
                'requirements' => [['spec' => 'pandas>=2', 'origin' => 'your project']],
                'ignored' => [],
                'overrides' => [],
                'lock' => ['file' => null, 'status' => 'none', 'packages' => 0],
            ],
            'python' => [
                'interpreter' => $python,
                'chosen_by' => 'venv',
                'venv' => ['path' => $this->venv, 'exists' => true],
                'uv' => ['path' => '/bin/uv', 'version' => 'uv 0.9.5'],
                'started' => true,
                'version' => '3.12.7',
                'implementation' => 'CPython',
                'executable' => '/somewhere/python',
                'packages' => ['pandas' => '3.1.1'],
                'worker' => ['ok' => true, 'error' => null, 'ms' => 0],
            ],
            'problems' => [],
        ]);
});

test('the JSON is one pretty-printed document with readable slashes and characters', function (): void {
    file_put_contents($this->root.'/composer.json', json_encode(['extra' => ['pyhp' => ['requirements' => 'reqs-é/req.txt']]]));
    $runner = new FakeCommandRunner;

    [, $text] = ($this->info)($runner, json: true);

    expect($text)->toStartWith("{\n    \"format\": 1,\n")
        ->and($text)->toEndWith("\n}\n")
        ->and($text)->toContain('"reqs-é/req.txt"')
        ->and($text)->not->toContain('\\/')
        ->and($text)->not->toContain('\\u00e9');
});

test('a project with problems, in text', function (): void {
    file_put_contents($this->root.'/composer.json', json_encode(['extra' => ['pyhp' => ['requirements' => 'req.txt', 'overrides' => ['numpy<2']]]]));
    $runner = new FakeCommandRunner(captures: [goldenProbe($this->defaultPython, []) => goldenAnswer('3.10.19', [])]);

    [$code, $text] = ($this->info)($runner, 'the worker did not connect');

    expect($code)->toBe(1)
        ->and($text)->toBe(goldenHeader()
            ."\nProject\n"
            ."  Root           {$this->root}\n"
            ."  Python wanted  any (extra.pyhp.python not set)\n"
            ."  Requirements   req.txt (not found)\n"
            ."  From packages  none\n"
            ."  Overrides      numpy<2\n"
            ."  Lock file      none (optional; vendor/bin/pyhp lock writes one with exact versions)\n"
            ."\nPython\n"
            ."  Interpreter    {$this->defaultPython}\n"
            ."  Chosen by      the default command on PATH (no PYHP_PYTHON, no project venv)\n"
            ."  Project venv   {$this->venv} (not created yet)\n"
            ."  uv             not installed (optional; makes pyhp setup faster and can download Python)\n"
            ."  Version        3.10.19 (CPython)\n"
            ."  Executable     /somewhere/python\n"
            ."  Packages       none required\n"
            ."  Worker         FAILED\n"
            .GOLDEN_NOTE
            ."\nProblems:\n"
            ."  - The requirements file req.txt doesn't exist.\n"
            ."  - extra.pyhp.overrides needs uv, which is not installed: https://docs.astral.sh/uv/getting-started/installation/\n"
            ."  - PyHP needs Python 3.11 or newer; this is 3.10.19.\n"
            ."  - The PyHP worker failed: the worker did not connect\n");
});

test('a project with problems, in JSON', function (): void {
    file_put_contents($this->root.'/composer.json', json_encode(['extra' => ['pyhp' => ['requirements' => 'req.txt', 'overrides' => ['numpy<2']]]]));
    $runner = new FakeCommandRunner(captures: [goldenProbe($this->defaultPython, []) => goldenAnswer('3.10.19', [])]);

    [$code, $text] = ($this->info)($runner, 'the worker did not connect', json: true);
    $data = json_decode($text, true, 32, JSON_THROW_ON_ERROR);
    $data['python']['worker']['ms'] = 0;

    expect($code)->toBe(1)
        ->and($data['ok'])->toBeFalse()
        ->and($data['project'])->toBe([
            'root' => $this->root,
            'python' => null,
            'requirements_file' => ['path' => 'req.txt', 'exists' => false],
            'requirements' => [],
            'ignored' => [],
            'overrides' => ['numpy<2'],
            'lock' => ['file' => null, 'status' => 'none', 'packages' => 0],
        ])
        ->and($data['python'])->toBe([
            'interpreter' => $this->defaultPython,
            'chosen_by' => 'default',
            'venv' => ['path' => $this->venv, 'exists' => false],
            'uv' => null,
            'started' => true,
            'version' => '3.10.19',
            'implementation' => 'CPython',
            'executable' => '/somewhere/python',
            'packages' => [],
            'worker' => ['ok' => false, 'error' => 'the worker did not connect', 'ms' => 0],
        ])
        ->and($data['problems'])->toBe([
            "The requirements file req.txt doesn't exist.",
            'extra.pyhp.overrides needs uv, which is not installed: https://docs.astral.sh/uv/getting-started/installation/',
            'PyHP needs Python 3.11 or newer; this is 3.10.19.',
            'The PyHP worker failed: the worker did not connect',
        ])
        ->and($text)->toContain('"packages": {}');
});

test('Composer package requirements, an ignored package, an old lock, missing packages and no venv', function (): void {
    file_put_contents($this->root.'/composer.json', json_encode(['extra' => ['pyhp' => ['requirements' => ['pandas'], 'ignore-packages' => ['acme/legacy']]]]));
    installPackages($this->root, [
        'acme/nlp' => ['extra' => ['pyhp' => ['requirements' => ['spacy>=3.8']]]],
        'acme/legacy' => ['extra' => ['pyhp' => ['requirements' => ['tensorflow']]]],
    ]);
    file_put_contents($this->root.'/pyhp.lock', LockFile::withHeader("pandas==3.1.1\nspacy==3.8.18\n", 'stale', 'uv'));
    $runner = new FakeCommandRunner(programs: ['uv' => '/bin/uv'], captures: [
        goldenProbe($this->defaultPython, ['pandas', 'spacy']) => goldenAnswer('3.13.1', ['pandas' => '3.1.1', 'spacy' => null]),
    ]);

    [$code, $text] = ($this->info)($runner);

    expect($code)->toBe(1)
        ->and($text)->toBe(goldenHeader()
            ."\nProject\n"
            ."  Root           {$this->root}\n"
            ."  Python wanted  any (extra.pyhp.python not set)\n"
            ."  Requirements   pandas\n"
            ."  From packages  acme/nlp: spacy>=3.8\n"
            ."  Ignored        acme/legacy (extra.pyhp.ignore-packages)\n"
            ."  Lock file      pyhp.lock (out of date)\n"
            ."\nPython\n"
            ."  Interpreter    {$this->defaultPython}\n"
            ."  Chosen by      the default command on PATH (no PYHP_PYTHON, no project venv)\n"
            ."  Project venv   {$this->venv} (not created yet)\n"
            ."  uv             found at /bin/uv\n"
            ."  Version        3.13.1 (CPython)\n"
            ."  Executable     /somewhere/python\n"
            ."  Packages       pandas 3.1.1, spacy missing\n"
            ."  Worker         started and answered in N ms\n"
            .GOLDEN_NOTE
            ."\nProblems:\n"
            ."  - pyhp.lock is out of date: the Python requirements changed since it was written. Run: vendor/bin/pyhp lock\n"
            ."  - No virtual environment at {$this->venv}.\n"
            ."  - Not installed: spacy. Run: vendor/bin/pyhp setup\n");
});

test('a requirements file and an up-to-date lock', function (): void {
    file_put_contents($this->root.'/composer.json', json_encode(['extra' => ['pyhp' => ['requirements' => 'req.txt']]]));
    file_put_contents($this->root.'/req.txt', "six\n# a comment\nidna>=3\n");
    $setup = SetupConfig::fromProject($this->root);
    file_put_contents($this->root.'/pyhp.lock', LockFile::withHeader(
        "idna==3.10\nsix==1.17.0\n",
        LockFile::fingerprint($this->root, $setup, ProjectRequirements::collect($this->root, $setup)),
        'uv',
    ));
    $python = goldenVenvPython($this->venv);
    $runner = new FakeCommandRunner(captures: [
        goldenProbe($python, ['six', 'idna']) => goldenAnswer('3.13.1', ['six' => '1.17.0', 'idna' => '3.10']),
    ]);

    [$code, $text] = ($this->info)($runner);

    expect($code)->toBe(0)
        ->and($text)->toContain("\n  Requirements   req.txt (2 packages)\n")
        ->and($text)->toContain("\n  Lock file      pyhp.lock (up to date, 2 packages)\n")
        ->and($text)->toContain("\n  Packages       six 1.17.0, idna 3.10\n");
});

test('PYHP_PYTHON chooses the interpreter', function (): void {
    file_put_contents($this->root.'/composer.json', json_encode(['extra' => ['pyhp' => ['requirements' => ['six']]]]));
    putenv('PYHP_PYTHON=/opt/py/bin/python3');
    $runner = new FakeCommandRunner(captures: [
        goldenProbe('/opt/py/bin/python3', ['six']) => goldenAnswer('3.13.1', ['six' => '1.17.0']),
    ]);

    [$code, $text] = ($this->info)($runner);

    expect($code)->toBe(0)
        ->and($text)->toContain("\nPython\n"
            ."  Interpreter    /opt/py/bin/python3\n"
            ."  Chosen by      the PYHP_PYTHON environment variable\n"
            ."  Project venv   {$this->venv} (not created yet)\n"
            ."  uv             not installed (optional; makes pyhp setup faster and can download Python)\n"
            ."  Version        3.13.1 (CPython)\n")
        ->and($text)->not->toContain('Note');
});

test('packages from the system Python get a note instead of a problem', function (): void {
    file_put_contents($this->root.'/composer.json', json_encode(['extra' => ['pyhp' => ['requirements' => ['six']]]]));
    $runner = new FakeCommandRunner(captures: [
        goldenProbe($this->defaultPython, ['six']) => goldenAnswer('3.13.1', ['six' => '1.17.0']),
    ]);

    [$code, $text] = ($this->info)($runner);

    expect($code)->toBe(0)
        ->and($text)->toContain("  Packages       six 1.17.0\n"
            ."  Note           the packages come from the system Python; run vendor/bin/pyhp setup to give the project its own venv.\n"
            ."  Worker         started and answered in N ms\n")
        ->and($text)->toEndWith("\nEverything looks fine.\n");
});

test('a Python that cannot be started, in text', function (): void {
    file_put_contents($this->root.'/composer.json', json_encode(['extra' => ['pyhp' => ['requirements' => ['six']]]]));
    $python = goldenVenvPython($this->venv);

    [$code, $text] = ($this->info)(new FakeCommandRunner);

    expect($code)->toBe(1)
        ->and($text)->toEndWith("\nPython\n"
            ."  Interpreter    {$python}\n"
            ."  Chosen by      the project's virtual environment\n"
            ."  Project venv   {$this->venv}\n"
            ."  uv             not installed (optional; makes pyhp setup faster and can download Python)\n"
            ."  Status         could not be started\n"
            ."\nProblems:\n"
            ."  - Python could not be started: {$python}\n");
});
