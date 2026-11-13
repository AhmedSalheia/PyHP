<?php

declare(strict_types=1);

use PyHP\Console\InfoCommand;
use PyHP\Setup\LockFile;
use PyHP\Setup\ProjectRequirements;
use PyHP\Setup\SetupConfig;
use PyHP\Setup\VirtualEnv;

beforeEach(function (): void {
    $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pyhp-info-'.bin2hex(random_bytes(4));
    mkdir($this->root);
    $this->previousEnv = getenv('PYHP_PYTHON');
    putenv('PYHP_PYTHON');
    $lines = new ArrayObject;
    $this->lines = $lines;
    $this->out = function (string $text) use ($lines): void {
        $lines->append($text);
    };
    $this->text = fn (): string => implode('', $lines->getArrayCopy());
    $this->venvPython = function (): string {
        $python = VirtualEnv::python($this->root.DIRECTORY_SEPARATOR.'.venv');
        mkdir(dirname($python), 0777, true);
        touch($python);

        return $python;
    };
    $this->workerOk = fn (string $python): ?string => null;
});

afterEach(function (): void {
    putenv($this->previousEnv === false ? 'PYHP_PYTHON' : 'PYHP_PYTHON='.$this->previousEnv);
    removeDirectory($this->root);
});

/**
 * The probe command InfoCommand runs, as FakeCommandRunner keys it.
 *
 * @param  list<string>  $packages
 */
function infoProbe(string $python, array $packages): string
{
    return implode(' ', [$python, '-c', InfoCommand::PYTHON_PROBE, json_encode($packages, JSON_THROW_ON_ERROR)]);
}

/** @param array<string, string|null> $packages */
function probeAnswer(string $version = '3.12.7', array $packages = []): string
{
    return json_encode([
        'version' => $version,
        'implementation' => 'CPython',
        'executable' => '/somewhere/python',
        'prefix' => '/somewhere',
        'packages' => (object) $packages,
    ], JSON_THROW_ON_ERROR);
}

test('a healthy project reports PHP, Python, the venv, packages and uv, and exits 0', function (): void {
    file_put_contents($this->root.'/composer.json', json_encode(['extra' => ['pyhp' => ['requirements' => ['pandas>=2', 'numpy']]]]));
    $python = ($this->venvPython)();
    $runner = new FakeCommandRunner(
        programs: ['uv' => '/bin/uv'],
        captures: [
            infoProbe($python, ['pandas', 'numpy']) => probeAnswer(packages: ['pandas' => '3.1.1', 'numpy' => '2.4.6']),
            '/bin/uv --version' => 'uv 0.9.5',
        ],
    );

    $code = (new InfoCommand($runner, $this->out, $this->workerOk))->run($this->root);

    $text = ($this->text)();
    expect($code)->toBe(0)
        ->and($text)->toContain(PHP_VERSION)
        ->and($text)->toContain(PHP_BINARY)
        ->and($text)->toContain($python)
        ->and($text)->toContain("the project's virtual environment")
        ->and($text)->toContain('3.12.7 (CPython)')
        ->and($text)->toContain('pandas 3.1.1')
        ->and($text)->toContain('numpy 2.4.6')
        ->and($text)->toContain('uv 0.9.5')
        ->and($text)->toContain('Worker')
        ->and($text)->toContain('Everything looks fine');
});

test('missing packages and a missing venv are reported as problems with the fix', function (): void {
    file_put_contents($this->root.'/requirements.txt', "# comment\npandas>=2  # inline\n\n-r other.txt\nscikit-learn[alldeps]==1.9\nen_core_web_sm @ https://example.com/x.whl\n");
    $python = PHP_OS_FAMILY === 'Windows' ? 'python' : 'python3';
    $runner = new FakeCommandRunner(
        programs: [],
        captures: [
            infoProbe($python, ['pandas', 'scikit-learn', 'en_core_web_sm']) => probeAnswer(packages: [
                'pandas' => '3.1.1', 'scikit-learn' => null, 'en_core_web_sm' => null,
            ]),
        ],
    );

    $code = (new InfoCommand($runner, $this->out, $this->workerOk))->run($this->root);

    $text = ($this->text)();
    expect($code)->toBe(1)
        ->and($text)->toContain('scikit-learn missing')
        ->and($text)->toContain('Not installed: scikit-learn, en_core_web_sm')
        ->and($text)->toContain('No virtual environment')
        ->and($text)->toContain('pyhp setup')
        ->and($text)->toContain('not installed (optional');
});

test('PYHP_PYTHON is reported as the reason', function (): void {
    putenv('PYHP_PYTHON=/opt/python3.13');
    $runner = new FakeCommandRunner(captures: [infoProbe('/opt/python3.13', []) => probeAnswer('3.13.1')]);

    (new InfoCommand($runner, $this->out, $this->workerOk))->run($this->root);

    expect(($this->text)())->toContain('PYHP_PYTHON')
        ->and(($this->text)())->toContain('3.13.1');
});

test('a Python that cannot start is a problem, and the worker is not tried', function (): void {
    $python = ($this->venvPython)();
    $tried = new ArrayObject;
    $worker = function (string $python) use ($tried): ?string {
        $tried->append($python);

        return null;
    };

    $code = (new InfoCommand(new FakeCommandRunner, $this->out, $worker))->run($this->root);

    expect($code)->toBe(1)
        ->and(($this->text)())->toContain("could not be started: {$python}")
        ->and($tried->getArrayCopy())->toBe([]);
});

test('Python older than 3.11 is a problem', function (): void {
    $python = ($this->venvPython)();
    $runner = new FakeCommandRunner(captures: [infoProbe($python, []) => probeAnswer('3.10.19')]);

    $code = (new InfoCommand($runner, $this->out, $this->workerOk))->run($this->root);

    expect($code)->toBe(1)
        ->and(($this->text)())->toContain('needs Python 3.11');
});

test('a worker that fails to start is a problem with its message', function (): void {
    $python = ($this->venvPython)();
    $runner = new FakeCommandRunner(captures: [infoProbe($python, []) => probeAnswer()]);
    $broken = fn (string $python): ?string => 'the worker did not connect within 10 s';

    $code = (new InfoCommand($runner, $this->out, $broken))->run($this->root);

    expect($code)->toBe(1)
        ->and(($this->text)())->toContain('the worker did not connect within 10 s');
});

test('a requirements file that does not exist is a problem', function (): void {
    file_put_contents($this->root.'/composer.json', json_encode(['extra' => ['pyhp' => ['requirements' => 'python/req.txt']]]));
    $python = ($this->venvPython)();
    $runner = new FakeCommandRunner(captures: [infoProbe($python, []) => probeAnswer()]);

    $code = (new InfoCommand($runner, $this->out, $this->workerOk))->run($this->root);

    expect($code)->toBe(1)
        ->and(($this->text)())->toContain('python/req.txt');
});

test('the real worker check starts a session with the interpreter', function (): void {
    expect(InfoCommand::checkWorker(testPython()))->toBeNull()
        ->and(InfoCommand::checkWorker($this->root.DIRECTORY_SEPARATOR.'no-python'))->toBeString();
});

test('pyhp info runs through the application and the bin script', function (): void {
    $script = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR.'pyhp';
    putenv('PYHP_PYTHON='.testPython());
    $output = [];
    exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($script).' info', $output, $code);

    expect(implode("\n", $output))->toContain('Python')
        ->and(implode("\n", $output))->toContain('PYHP_PYTHON')
        ->and($code)->toBeIn([0, 1]);
});

test('output from the interpreter that is not the expected JSON counts as not started', function (string $answer): void {
    $python = ($this->venvPython)();
    $runner = new FakeCommandRunner(captures: [infoProbe($python, []) => $answer]);

    $code = (new InfoCommand($runner, $this->out, $this->workerOk))->run($this->root);

    expect($code)->toBe(1)
        ->and(($this->text)())->toContain('could not be started');
})->with([
    'not JSON' => ['Python 2.7.18'],
    'wrong shape' => ['{"version": 3}'],
]);

test('requirements from Composer packages, ignored packages and overrides are shown', function (): void {
    file_put_contents($this->root.'/composer.json', json_encode(['extra' => ['pyhp' => [
        'requirements' => ['pandas'],
        'ignore-packages' => ['acme/legacy'],
        'overrides' => ['numpy<2'],
    ]]]));
    installPackages($this->root, [
        'acme/nlp' => ['extra' => ['pyhp' => ['requirements' => ['spacy>=3.8']]]],
        'acme/legacy' => ['extra' => ['pyhp' => ['requirements' => ['tensorflow']]]],
    ]);
    $python = ($this->venvPython)();
    $runner = new FakeCommandRunner(
        programs: ['uv' => '/bin/uv'],
        captures: [
            infoProbe($python, ['pandas', 'spacy']) => probeAnswer(packages: ['pandas' => '3.1.1', 'spacy' => '3.8.18']),
            '/bin/uv --version' => 'uv 0.13.7',
        ],
    );

    $code = (new InfoCommand($runner, $this->out, $this->workerOk))->run($this->root);

    $text = ($this->text)();
    expect($code)->toBe(0)
        ->and($text)->toContain('acme/nlp: spacy>=3.8')
        ->and($text)->toContain('acme/legacy')
        ->and($text)->toContain('numpy<2')
        ->and($text)->toContain('spacy 3.8.18');
});

test('overrides without uv are a problem', function (): void {
    file_put_contents($this->root.'/composer.json', json_encode(['extra' => ['pyhp' => ['overrides' => ['numpy<2']]]]));
    $python = ($this->venvPython)();
    $runner = new FakeCommandRunner(captures: [infoProbe($python, []) => probeAnswer()]);

    $code = (new InfoCommand($runner, $this->out, $this->workerOk))->run($this->root);

    expect($code)->toBe(1)
        ->and(($this->text)())->toContain('extra.pyhp.overrides needs uv');
});

test('the lock file is reported as missing, up to date or out of date', function (): void {
    file_put_contents($this->root.'/composer.json', json_encode(['extra' => ['pyhp' => ['requirements' => ['six']]]]));
    $python = ($this->venvPython)();
    $runner = new FakeCommandRunner(captures: [infoProbe($python, ['six']) => probeAnswer(packages: ['six' => '1.17.0'])]);
    $config = SetupConfig::fromProject($this->root);
    $fresh = LockFile::fingerprint($this->root, $config, ProjectRequirements::collect($this->root, $config));

    $none = (new InfoCommand($runner, $this->out, $this->workerOk))->run($this->root);
    $noneText = ($this->text)();
    $this->lines->exchangeArray([]);

    file_put_contents($this->root.'/pyhp.lock', LockFile::withHeader("six==1.17.0\n", $fresh, 'uv'));
    $upToDate = (new InfoCommand($runner, $this->out, $this->workerOk))->run($this->root);
    $upToDateText = ($this->text)();
    $this->lines->exchangeArray([]);

    file_put_contents($this->root.'/pyhp.lock', LockFile::withHeader("six==1.17.0\n", 'stale', 'uv'));
    $stale = (new InfoCommand($runner, $this->out, $this->workerOk))->run($this->root);

    expect($none)->toBe(0)
        ->and($noneText)->toContain('none (optional')
        ->and($upToDate)->toBe(0)
        ->and($upToDateText)->toContain('pyhp.lock (up to date')
        ->and($stale)->toBe(1)
        ->and(($this->text)())->toContain('out of date');
});

/** @return array<string, mixed> */
function infoJson(string $text): array
{
    $data = json_decode($text, true, 32, JSON_THROW_ON_ERROR);
    expect($data)->toBeArray();

    return $data;
}

test('--json reports the same facts as one JSON document, and the same exit code', function (): void {
    file_put_contents($this->root.'/composer.json', json_encode(['extra' => ['pyhp' => ['python' => '3.12', 'requirements' => ['pandas>=2']]]]));
    $python = ($this->venvPython)();
    $runner = new FakeCommandRunner(
        programs: ['uv' => '/bin/uv'],
        captures: [
            infoProbe($python, ['pandas']) => probeAnswer(packages: ['pandas' => '3.1.1']),
            '/bin/uv --version' => 'uv 0.9.5',
        ],
    );

    $code = (new InfoCommand($runner, $this->out, $this->workerOk))->run($this->root, json: true);
    $data = infoJson(($this->text)());

    expect($code)->toBe(0)
        ->and($data['format'])->toBe(1)
        ->and($data['ok'])->toBeTrue()
        ->and($data['problems'])->toBe([])
        ->and($data['php']['version'])->toBe(PHP_VERSION)
        ->and($data['php']['binary'])->toBe(PHP_BINARY)
        ->and($data['php']['bits'])->toBe(PHP_INT_SIZE * 8)
        ->and($data['php']['proc_open'])->toBeTrue()
        ->and($data['project']['root'])->toBe($this->root)
        ->and($data['project']['python'])->toBe('3.12')
        ->and($data['project']['requirements'])->toBe([['spec' => 'pandas>=2', 'origin' => 'your project']])
        ->and($data['project']['lock'])->toBe(['file' => null, 'status' => 'none', 'packages' => 0])
        ->and($data['python']['interpreter'])->toBe($python)
        ->and($data['python']['chosen_by'])->toBe('venv')
        ->and($data['python']['venv'])->toBe(['path' => $this->root.DIRECTORY_SEPARATOR.'.venv', 'exists' => true])
        ->and($data['python']['uv'])->toBe(['path' => '/bin/uv', 'version' => 'uv 0.9.5'])
        ->and($data['python']['started'])->toBeTrue()
        ->and($data['python']['version'])->toBe('3.12.7')
        ->and($data['python']['implementation'])->toBe('CPython')
        ->and($data['python']['executable'])->toBe('/somewhere/python')
        ->and($data['python']['packages'])->toBe(['pandas' => '3.1.1'])
        ->and($data['python']['worker']['ok'])->toBeTrue()
        ->and($data['python']['worker']['error'])->toBeNull()
        ->and($data['python']['worker']['ms'])->toBeInt();
});

test('--json lists problems, missing packages and a missing uv', function (): void {
    file_put_contents($this->root.'/composer.json', json_encode(['extra' => ['pyhp' => ['requirements' => ['pandas', 'numpy']]]]));
    $python = PHP_OS_FAMILY === 'Windows' ? 'python' : 'python3';
    $runner = new FakeCommandRunner(captures: [
        infoProbe($python, ['pandas', 'numpy']) => probeAnswer(packages: ['pandas' => '3.1.1', 'numpy' => null]),
    ]);

    $code = (new InfoCommand($runner, $this->out, $this->workerOk))->run($this->root, json: true);
    $data = infoJson(($this->text)());

    expect($code)->toBe(1)
        ->and($data['ok'])->toBeFalse()
        ->and($data['python']['chosen_by'])->toBe('default')
        ->and($data['python']['venv']['exists'])->toBeFalse()
        ->and($data['python']['uv'])->toBeNull()
        ->and($data['python']['packages'])->toBe(['pandas' => '3.1.1', 'numpy' => null])
        ->and(implode("\n", $data['problems']))->toContain('Not installed: numpy');
});

test('--json says when Python could not be started and skips the worker', function (): void {
    $python = ($this->venvPython)();

    $code = (new InfoCommand(new FakeCommandRunner, $this->out, $this->workerOk))->run($this->root, json: true);
    $data = infoJson(($this->text)());

    expect($code)->toBe(1)
        ->and($data['python']['interpreter'])->toBe($python)
        ->and($data['python']['started'])->toBeFalse()
        ->and($data['python'])->not->toHaveKey('version')
        ->and($data['python'])->not->toHaveKey('worker');
});

test('--json reports a lock file that is out of date', function (): void {
    file_put_contents($this->root.'/composer.json', json_encode(['extra' => ['pyhp' => ['requirements' => ['six']]]]));
    file_put_contents($this->root.'/pyhp.lock', LockFile::withHeader("six==1.17.0\n", 'stale', 'uv'));
    $python = ($this->venvPython)();
    $runner = new FakeCommandRunner(captures: [infoProbe($python, ['six']) => probeAnswer(packages: ['six' => '1.17.0'])]);

    (new InfoCommand($runner, $this->out, $this->workerOk))->run($this->root, json: true);
    $data = infoJson(($this->text)());

    expect($data['project']['lock'])->toBe(['file' => 'pyhp.lock', 'status' => 'out_of_date', 'packages' => 1]);
});
