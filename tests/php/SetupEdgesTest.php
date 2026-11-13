<?php

declare(strict_types=1);

use PyHP\Console\InfoCommand;
use PyHP\Setup\Installer;
use PyHP\Setup\LockFile;
use PyHP\Setup\ProjectRequirements;
use PyHP\Setup\SetupConfig;
use PyHP\Setup\VirtualEnv;

/*
 * Edge cases of `pyhp setup`, `pyhp lock` and `pyhp info` that mutation testing
 * showed were unchecked: a too-old Python before a good one, pip-only locks,
 * odd `pip list` entries, half-valid probe answers and the JSON lock report.
 */

beforeEach(function (): void {
    $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pyhp-edges-'.bin2hex(random_bytes(4));
    mkdir($this->root);
    $this->venv = $this->root.DIRECTORY_SEPARATOR.'.venv';
    $this->python = VirtualEnv::python($this->venv, 'Linux');
    $this->previousEnv = getenv('PYHP_PYTHON');
    putenv('PYHP_PYTHON');
    $lines = new ArrayObject;
    $this->lines = $lines;
    $this->write = function (string $line) use ($lines): void {
        $lines->append($line);
    };
    $this->createsVenv = function (array $command): void {
        if (in_array('venv', $command, true)) {
            mkdir(dirname($this->python), 0777, true);
            touch($this->python);
        }
    };
    // Plays pip's part in a resolution: writes the report it was asked for.
    $this->pipReport = function (array $command): void {
        $report = array_search('--report', $command, true);
        if ($report !== false) {
            file_put_contents($command[$report + 1], json_encode(['install' => [['metadata' => ['name' => 'six', 'version' => '1.17.0']]]]));
        }
    };
    // Runs `pyhp info` against the fake runner; returns [exit code, output].
    $this->info = function (FakeCommandRunner $runner, bool $json = false, ?Closure $worker = null): array {
        $output = new ArrayObject;
        $command = new InfoCommand($runner, function (string $text) use ($output): void {
            $output->append($text);
        }, $worker ?? fn (string $python): ?string => null);
        $code = $command->run($this->root, $json);

        // Lines end with PHP_EOL, which is "\r\n" on Windows.
        return [$code, str_replace("\r\n", "\n", implode('', $output->getArrayCopy()))];
    };
});

afterEach(function (): void {
    putenv($this->previousEnv === false ? 'PYHP_PYTHON' : 'PYHP_PYTHON='.$this->previousEnv);
    removeDirectory($this->root);
});

function edgeVersionProbe(string ...$python): string
{
    return implode(' ', [...$python, '-c', Installer::VERSION_PROBE]);
}

/** @param list<string> $packages */
function edgeInfoProbe(string $python, array $packages): string
{
    return implode(' ', [$python, '-c', InfoCommand::PYTHON_PROBE, json_encode($packages, JSON_THROW_ON_ERROR)]);
}

/** @param array<string, mixed> $fields replace or (with null) remove fields of a valid answer */
function edgeProbeAnswer(array $fields = [], array $packages = ['six' => '1.17.0']): string
{
    $answer = ['version' => '3.12.7', 'implementation' => 'CPython', 'executable' => '/x/python', 'prefix' => '/x', 'packages' => (object) $packages];
    foreach ($fields as $name => $value) {
        if ($value === null) {
            unset($answer[$name]);
        } else {
            $answer[$name] = $value;
        }
    }

    return json_encode($answer, JSON_THROW_ON_ERROR);
}

/** The project's venv Python, created so `pyhp info` picks it. */
function edgeVenvPython(string $venv): string
{
    $python = VirtualEnv::python($venv);
    mkdir(dirname($python), 0777, true);
    touch($python);

    return $python;
}

// -- setup and lock ------------------------------------------------------------- //

test('a Python that is too old is skipped for a later one that is new enough', function (): void {
    $runner = new FakeCommandRunner(
        programs: ['python3' => '/usr/bin/python3', 'python' => '/usr/bin/python'],
        captures: [edgeVersionProbe('/usr/bin/python3') => '3.10', edgeVersionProbe('/usr/bin/python') => '3.12'],
        onRun: $this->createsVenv,
    );

    $code = (new Installer($runner, $this->write, 'Linux'))->install(new SetupConfig(requirements: ['six']), $this->root, useUv: false);

    expect($code)->toBe(0)
        ->and($runner->commandLines()[0])->toBe("/usr/bin/python -P -m venv {$this->venv}");
});

test('a lock without uv says it only fits this system and records how it was made', function (): void {
    $runner = new FakeCommandRunner(
        programs: ['python3' => '/usr/bin/python3'],
        captures: [edgeVersionProbe('/usr/bin/python3') => '3.12'],
        onRun: $this->pipReport,
    );

    $code = (new Installer($runner, $this->write, 'Linux'))->lock(new SetupConfig(requirements: ['six']), $this->root);

    $lines = $this->lines->getArrayCopy();
    expect($code)->toBe(0)
        ->and(array_slice($lines, -2))->toBe([
            'Without uv the lock is resolved only for Linux and Python 3.12; install uv to get one lock for every operating system.',
            'Wrote pyhp.lock (1 packages). Commit it; pyhp setup installs exactly these versions.',
        ])
        ->and((string) file_get_contents($this->root.'/pyhp.lock'))->toContain('pip, for Linux with Python 3.12')
        ->and(LockFile::packageNames((string) file_get_contents($this->root.'/pyhp.lock')))->toBe(['six']);
});

test('a failed lock without uv explains conflicts and writes nothing', function (): void {
    installPackages($this->root, ['acme/a' => ['extra' => ['pyhp' => ['requirements' => ['six<1']]]]]);
    $runner = new FakeCommandRunner(
        programs: ['python3' => '/usr/bin/python3'],
        captures: [edgeVersionProbe('/usr/bin/python3') => '3.12'],
        exitCodeFor: fn (array $command): int => 1,
    );

    $code = (new Installer($runner, $this->write, 'Linux'))->lock(new SetupConfig(requirements: ['six>=1']), $this->root);

    $lines = $this->lines->getArrayCopy();
    expect($code)->toBe(1)
        ->and(array_slice($lines, -3))->toBe([
            'These Python packages are requested by more than one source; check that their version rules can all be met:',
            '  six: six>=1 (your project), six<1 (acme/a)',
            'To resolve it, tighten your own requirement, force a version with extra.pyhp.overrides (uv), or skip a package\'s requirements with extra.pyhp.ignore-packages.',
        ])
        ->and(implode("\n", $lines))->not->toContain('Without uv')
        ->and(file_exists($this->root.'/pyhp.lock'))->toBeFalse()
        ->and(count($runner->ran))->toBe(1);
});

test('setup --exact without uv ignores pip list entries that are not packages', function (): void {
    mkdir(dirname($this->python), 0777, true);
    touch($this->python);
    file_put_contents($this->root.'/pyhp.lock', LockFile::withHeader("six==1.17.0\n", 'x', 'pip'));
    $listed = json_encode([5, 'six', ['version' => '1.0'], ['name' => 7], ['name' => 'six'], ['name' => 'Extra_Thing']]);
    $runner = new FakeCommandRunner(captures: ["{$this->python} -P -m pip list --format=json" => (string) $listed]);

    $code = (new Installer($runner, $this->write, 'Linux'))->install(new SetupConfig(requirements: ['six']), $this->root, exact: true);

    expect($code)->toBe(0)
        ->and($runner->commandLines())->toBe([
            "{$this->python} -P -m pip install -r pyhp.lock",
            "{$this->python} -P -m pip uninstall -y extra-thing",
        ]);
});

// -- info --------------------------------------------------------------------- //

test('a probe answer with a missing or mistyped field means Python could not be started', function (array $fields): void {
    file_put_contents($this->root.'/composer.json', json_encode(['extra' => ['pyhp' => ['requirements' => ['six']]]]));
    $python = edgeVenvPython($this->venv);
    $runner = new FakeCommandRunner(captures: [edgeInfoProbe($python, ['six']) => edgeProbeAnswer($fields)]);

    [$code, $text] = ($this->info)($runner);

    expect($code)->toBe(1)
        ->and($text)->toContain("\n  Status         could not be started\n");
})->with([
    'no version' => [['version' => null]],
    'version not a string' => [['version' => 3.12]],
    'no implementation' => [['implementation' => null]],
    'implementation not a string' => [['implementation' => 1]],
    'no executable' => [['executable' => null]],
    'executable not a string' => [['executable' => ['/x']]],
    'no packages' => [['packages' => null]],
    'packages not an object' => [['packages' => 'six']],
]);

test('package versions that are neither a string nor null are dropped from the answer', function (): void {
    file_put_contents($this->root.'/composer.json', json_encode(['extra' => ['pyhp' => ['requirements' => ['six', 'idna', 'attrs']]]]));
    $python = edgeVenvPython($this->venv);
    $runner = new FakeCommandRunner(captures: [
        edgeInfoProbe($python, ['six', 'idna', 'attrs']) => edgeProbeAnswer(packages: ['six' => '1.17.0', 'idna' => 3, 'attrs' => null]),
    ]);

    [, $json] = ($this->info)($runner, json: true);

    expect(json_decode($json, true)['python']['packages'])->toBe(['six' => '1.17.0', 'attrs' => null]);
});

test('the worker check reports how long it took', function (): void {
    file_put_contents($this->root.'/composer.json', json_encode(['extra' => ['pyhp' => ['requirements' => ['six']]]]));
    $python = edgeVenvPython($this->venv);
    $runner = new FakeCommandRunner(captures: [edgeInfoProbe($python, ['six']) => edgeProbeAnswer()]);
    $slowWorker = function (string $python): ?string {
        // Wait on the clock PyHP measures with: usleep() alone can come back early by
        // a timer tick (about 15 ms on Windows), and the test would see 47 ms.
        $until = microtime(true) + 0.06;
        while (microtime(true) < $until) {
            usleep(1_000);
        }

        return null;
    };

    [, $json] = ($this->info)($runner, json: true, worker: $slowWorker);

    $worker = json_decode($json, true)['python']['worker'];
    expect($worker['ok'])->toBeTrue()
        ->and($worker['ms'])->toBeGreaterThanOrEqual(50)
        ->and($worker['ms'])->toBeLessThan(5000);
});

test('a requirements file counts only its own packages, not those of Composer packages', function (): void {
    file_put_contents($this->root.'/composer.json', json_encode(['extra' => ['pyhp' => ['requirements' => 'req.txt']]]));
    file_put_contents($this->root.'/req.txt', "six\nidna>=3\n");
    installPackages($this->root, ['acme/nlp' => ['extra' => ['pyhp' => ['requirements' => ['spacy>=3.8', 'attrs']]]]]);

    [, $text] = ($this->info)(new FakeCommandRunner);

    expect($text)->toContain("\n  Requirements   req.txt (2 packages)\n");
});

test('an up-to-date lock in JSON', function (): void {
    file_put_contents($this->root.'/composer.json', json_encode(['extra' => ['pyhp' => ['requirements' => ['six', 'idna']]]]));
    $setup = SetupConfig::fromProject($this->root);
    file_put_contents($this->root.'/pyhp.lock', LockFile::withHeader(
        "idna==3.10\nsix==1.17.0\n",
        LockFile::fingerprint($this->root, $setup, ProjectRequirements::collect($this->root, $setup)),
        'uv',
    ));

    [, $json] = ($this->info)(new FakeCommandRunner, json: true);

    expect(json_decode($json, true)['project']['lock'])->toBe(['file' => 'pyhp.lock', 'status' => 'up_to_date', 'packages' => 2]);
});
