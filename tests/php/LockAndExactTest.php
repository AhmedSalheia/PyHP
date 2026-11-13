<?php

declare(strict_types=1);

use PyHP\Setup\Installer;
use PyHP\Setup\LockFile;
use PyHP\Setup\ProjectRequirements;
use PyHP\Setup\SetupConfig;
use PyHP\Setup\VirtualEnv;

beforeEach(function (): void {
    $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pyhp-lock-install-'.bin2hex(random_bytes(4));
    mkdir($this->root);
    $this->venv = $this->root.DIRECTORY_SEPARATOR.'.venv';
    $this->python = VirtualEnv::python($this->venv, 'Linux');
    $lines = new ArrayObject;
    $this->lines = $lines;
    $this->write = function (string $line) use ($lines): void {
        $lines->append($line);
    };
    $this->text = fn (): string => implode("\n", $lines->getArrayCopy());
    $this->makeVenv = function (): void {
        mkdir(dirname($this->python), 0777, true);
        touch($this->python);
    };
    // Plays uv's and pip's part: writes what `uv pip compile` or `pip install --report` would write.
    $root = $this->root;
    $this->resolver = function (array $command) use ($root): void {
        $output = array_search('--output-file', $command, true);
        if ($output !== false) {
            $file = $command[$output + 1];
            file_put_contents(str_starts_with($file, DIRECTORY_SEPARATOR) || preg_match('/^[A-Za-z]:/', $file) ? $file : $root.DIRECTORY_SEPARATOR.$file, "six==1.17.0\n");
        }
        $report = array_search('--report', $command, true);
        if ($report !== false) {
            file_put_contents($command[$report + 1], json_encode(['install' => [
                ['metadata' => ['name' => 'six', 'version' => '1.17.0']],
                ['metadata' => ['name' => 'idna', 'version' => '3.20']],
            ]]));
        }
    };
});

afterEach(function (): void {
    removeDirectory($this->root);
});

function lockProbe(string ...$python): string
{
    return implode(' ', [...$python, '-c', Installer::VERSION_PROBE]);
}

test('pyhp lock with uv resolves for every OS and writes pyhp.lock with a fingerprint', function (): void {
    installPackages($this->root, ['acme/nlp' => ['extra' => ['pyhp' => ['requirements' => ['six>=1.16']]]]]);
    $config = new SetupConfig(python: '3.12', requirements: ['idna'], overrides: ['six<2']);
    $seenInput = new ArrayObject;
    $runner = new FakeCommandRunner(programs: ['uv' => '/bin/uv'], onRun: function (array $command) use ($seenInput): void {
        $seenInput->append((string) file_get_contents($this->root.DIRECTORY_SEPARATOR.$command[3]));
        ($this->resolver)($command);
    });

    $code = (new Installer($runner, $this->write, 'Linux'))->lock($config, $this->root);

    $command = $runner->commandLines()[0];
    $lock = (string) file_get_contents($this->root.'/pyhp.lock');
    expect($code)->toBe(0)
        ->and($command)->toStartWith('/bin/uv pip compile .pyhp-requirements.in --universal --output-file pyhp.lock')
        ->and($command)->toContain('--python-version 3.12')
        ->and($command)->toContain('--override')
        ->and($seenInput->getArrayCopy())->toBe(["idna\nsix>=1.16\n"])
        ->and($lock)->toContain("six==1.17.0\n")
        ->and($lock)->toContain('every operating system')
        ->and(LockFile::fingerprintIn($lock))->toBe(LockFile::fingerprint($this->root, $config, ProjectRequirements::collect($this->root, $config)))
        ->and(is_file($this->root.'/.pyhp-requirements.in'))->toBeFalse()
        ->and(($this->text)())->toContain('Wrote pyhp.lock');
});

test('pyhp lock without uv uses pip\'s resolver and says the lock is for this OS only', function (): void {
    ($this->makeVenv)();
    $runner = new FakeCommandRunner(captures: [lockProbe($this->python) => '3.12'], onRun: $this->resolver);

    $code = (new Installer($runner, $this->write, 'Linux'))->lock(new SetupConfig(requirements: ['six', 'idna']), $this->root);

    $lock = (string) file_get_contents($this->root.'/pyhp.lock');
    expect($code)->toBe(0)
        ->and($runner->commandLines()[0])->toStartWith("{$this->python} -P -m pip install --dry-run --ignore-installed --quiet --report ")
        ->and($runner->commandLines()[0])->toEndWith(' six idna')
        ->and($lock)->toContain("idna==3.20\nsix==1.17.0\n")
        ->and($lock)->toContain('pip, for Linux with Python 3.12')
        ->and(($this->text)())->toContain('only for Linux');
});

test('pyhp lock without uv and without a venv resolves with a system Python', function (): void {
    $runner = new FakeCommandRunner(
        programs: ['python3' => '/usr/bin/python3'],
        captures: [lockProbe('/usr/bin/python3') => '3.11'],
        onRun: $this->resolver,
    );

    (new Installer($runner, $this->write, 'Linux'))->lock(new SetupConfig(requirements: ['six']), $this->root);

    expect($runner->commandLines()[0])->toStartWith('/usr/bin/python3 -P -m pip install --dry-run');
});

test('pyhp lock with nothing to lock writes nothing', function (): void {
    $runner = new FakeCommandRunner(programs: ['uv' => '/bin/uv']);

    $code = (new Installer($runner, $this->write, 'Linux'))->lock(new SetupConfig, $this->root);

    expect($code)->toBe(0)
        ->and($runner->ran)->toBe([])
        ->and(is_file($this->root.'/pyhp.lock'))->toBeFalse()
        ->and(($this->text)())->toContain('Nothing to lock');
});

test('pyhp lock with overrides needs uv', function (): void {
    $code = (new Installer(new FakeCommandRunner, $this->write, 'Linux'))
        ->lock(new SetupConfig(requirements: ['six'], overrides: ['six<2']), $this->root);

    expect($code)->toBe(1)
        ->and(($this->text)())->toContain('extra.pyhp.overrides needs uv');
});

test('a dry-run lock prints the command and writes nothing', function (): void {
    $runner = new FakeCommandRunner(programs: ['uv' => '/bin/uv']);

    $code = (new Installer($runner, $this->write, 'Linux'))->lock(new SetupConfig(requirements: ['six']), $this->root, dryRun: true);

    expect($code)->toBe(0)
        ->and($runner->ran)->toBe([])
        ->and(is_file($this->root.'/pyhp.lock'))->toBeFalse()
        ->and(($this->text)())->toContain('> /bin/uv pip compile');
});

test('a failing resolution leaves no lock and no input file behind', function (): void {
    $runner = new FakeCommandRunner(
        programs: ['uv' => '/bin/uv'],
        exitCodes: ['/bin/uv pip compile .pyhp-requirements.in --universal --output-file pyhp.lock --custom-compile-command vendor/bin/pyhp lock --quiet' => 1],
    );

    $code = (new Installer($runner, $this->write, 'Linux'))->lock(new SetupConfig(requirements: ['six']), $this->root);

    expect($code)->toBe(1)
        ->and(is_file($this->root.'/pyhp.lock'))->toBeFalse()
        ->and(is_file($this->root.'/.pyhp-requirements.in'))->toBeFalse();
});

test('setup installs the locked versions when pyhp.lock is up to date', function (): void {
    ($this->makeVenv)();
    $config = new SetupConfig(requirements: ['six']);
    file_put_contents($this->root.'/pyhp.lock', LockFile::withHeader("six==1.17.0\n", LockFile::fingerprint($this->root, $config, ProjectRequirements::collect($this->root, $config)), 'uv'));
    $runner = new FakeCommandRunner(programs: ['uv' => '/bin/uv']);

    $code = (new Installer($runner, $this->write, 'Linux'))->install($config, $this->root);

    expect($code)->toBe(0)
        ->and($runner->commandLines())->toBe(["/bin/uv pip install --python {$this->python} -r pyhp.lock"])
        ->and(($this->text)())->toContain('pyhp.lock')
        ->and(($this->text)())->not->toContain('out of date');
});

test('setup warns when pyhp.lock no longer matches the requirements', function (): void {
    ($this->makeVenv)();
    file_put_contents($this->root.'/pyhp.lock', LockFile::withHeader("six==1.17.0\n", 'old', 'uv'));
    $runner = new FakeCommandRunner(captures: [lockProbe($this->python) => '3.12']);

    (new Installer($runner, $this->write, 'Linux'))->install(new SetupConfig(requirements: ['six', 'idna']), $this->root);

    expect($runner->commandLines())->toBe(["{$this->python} -P -m pip install -r pyhp.lock"])
        ->and(($this->text)())->toContain('out of date')
        ->and(($this->text)())->toContain('vendor/bin/pyhp lock');
});

test('setup --exact with uv syncs the venv to the lock', function (): void {
    ($this->makeVenv)();
    file_put_contents($this->root.'/pyhp.lock', LockFile::withHeader("six==1.17.0\n", 'x', 'uv'));
    $runner = new FakeCommandRunner(programs: ['uv' => '/bin/uv']);

    (new Installer($runner, $this->write, 'Linux'))->install(new SetupConfig(requirements: ['six']), $this->root, exact: true);

    expect($runner->commandLines())->toBe(["/bin/uv pip sync --python {$this->python} pyhp.lock"]);
});

test('setup --exact without uv installs the lock and uninstalls everything else', function (): void {
    ($this->makeVenv)();
    file_put_contents($this->root.'/pyhp.lock', LockFile::withHeader("six==1.17.0\nidna==3.20\n", 'x', 'pip'));
    $listed = json_encode([
        ['name' => 'six', 'version' => '1.17.0'], ['name' => 'idna', 'version' => '3.20'],
        ['name' => 'pip', 'version' => '25.0'], ['name' => 'Colorama', 'version' => '0.4.6'], ['name' => 'setuptools', 'version' => '80'],
    ]);
    $runner = new FakeCommandRunner(captures: ["{$this->python} -P -m pip list --format=json" => (string) $listed]);

    $code = (new Installer($runner, $this->write, 'Linux'))->install(new SetupConfig(requirements: ['six']), $this->root, exact: true);

    expect($code)->toBe(0)
        ->and($runner->commandLines())->toBe([
            "{$this->python} -P -m pip install -r pyhp.lock",
            "{$this->python} -P -m pip uninstall -y colorama",
        ]);
});

test('setup --exact without a lock resolves into a temporary lock first', function (): void {
    ($this->makeVenv)();
    $runner = new FakeCommandRunner(programs: ['uv' => '/bin/uv'], onRun: $this->resolver);

    $code = (new Installer($runner, $this->write, 'Linux'))->install(new SetupConfig(requirements: ['six']), $this->root, exact: true);

    $commands = $runner->commandLines();
    expect($code)->toBe(0)
        ->and($commands[0])->toStartWith('/bin/uv pip compile .pyhp-requirements.in --universal --output-file ')
        ->and($commands[1])->toStartWith("/bin/uv pip sync --python {$this->python} ")
        ->and(is_file($this->root.'/pyhp.lock'))->toBeFalse();
});

test('setup --exact without uv and without a lock resolves with pip, then removes extras', function (): void {
    ($this->makeVenv)();
    $runner = new FakeCommandRunner(
        captures: [lockProbe($this->python) => '3.12', "{$this->python} -P -m pip list --format=json" => '[{"name": "six", "version": "1.17.0"}]'],
        onRun: $this->resolver,
    );

    $code = (new Installer($runner, $this->write, 'Linux'))->install(new SetupConfig(requirements: ['six']), $this->root, exact: true);

    expect($code)->toBe(0)
        ->and($runner->commandLines()[0])->toContain('-P -m pip install --dry-run')
        ->and($runner->commandLines()[1])->toStartWith("{$this->python} -P -m pip install -r ")
        ->and(count($runner->commandLines()))->toBe(2);
});

test('setup --exact with nothing required leaves the venv alone', function (): void {
    ($this->makeVenv)();
    $runner = new FakeCommandRunner(programs: ['uv' => '/bin/uv']);

    $code = (new Installer($runner, $this->write, 'Linux'))->install(new SetupConfig, $this->root, exact: true);

    expect($code)->toBe(0)
        ->and($runner->ran)->toBe([]);
});

test('a failing sync stops the setup', function (): void {
    ($this->makeVenv)();
    file_put_contents($this->root.'/pyhp.lock', LockFile::withHeader("six==1.17.0\n", 'x', 'uv'));
    $runner = new FakeCommandRunner(programs: ['uv' => '/bin/uv'], exitCodes: ["/bin/uv pip sync --python {$this->python} pyhp.lock" => 2]);

    $code = (new Installer($runner, $this->write, 'Linux'))->install(new SetupConfig(requirements: ['six']), $this->root, exact: true);

    expect($code)->toBe(1);
});

test('pip list output that is not JSON stops the exact setup', function (): void {
    ($this->makeVenv)();
    file_put_contents($this->root.'/pyhp.lock', LockFile::withHeader("six==1.17.0\n", 'x', 'pip'));
    $runner = new FakeCommandRunner(captures: ["{$this->python} -P -m pip list --format=json" => 'oops']);

    $code = (new Installer($runner, $this->write, 'Linux'))->install(new SetupConfig(requirements: ['six']), $this->root, exact: true);

    expect($code)->toBe(1)
        ->and(($this->text)())->toContain('pip list');
});

test('pyhp lock without uv and without any Python explains how to get one', function (): void {
    $code = (new Installer(new FakeCommandRunner, $this->write, 'Linux'))->lock(new SetupConfig(requirements: ['six']), $this->root);

    expect($code)->toBe(1)
        ->and(($this->text)())->toContain('No Python was found');
});

test('a failing pip resolution is reported and writes no lock', function (): void {
    ($this->makeVenv)();
    $runner = new FakeCommandRunner(
        captures: [lockProbe($this->python) => '3.12'],
        exitCodeFor: fn (array $command): ?int => in_array('--dry-run', $command, true) ? 1 : null,
    );

    $code = (new Installer($runner, $this->write, 'Linux'))->lock(new SetupConfig(requirements: ['six']), $this->root);

    expect($code)->toBe(1)
        ->and(is_file($this->root.'/pyhp.lock'))->toBeFalse();
});

test('a failing pip install during an exact setup stops before removing anything', function (): void {
    ($this->makeVenv)();
    file_put_contents($this->root.'/pyhp.lock', LockFile::withHeader("six==1.17.0\n", 'x', 'pip'));
    $runner = new FakeCommandRunner(exitCodes: ["{$this->python} -P -m pip install -r pyhp.lock" => 1]);

    $code = (new Installer($runner, $this->write, 'Linux'))->install(new SetupConfig(requirements: ['six']), $this->root, exact: true);

    expect($code)->toBe(1)
        ->and($runner->commandLines())->toBe(["{$this->python} -P -m pip install -r pyhp.lock"]);
});

test('an exact dry run without uv says what it would remove', function (): void {
    ($this->makeVenv)();
    file_put_contents($this->root.'/pyhp.lock', LockFile::withHeader("six==1.17.0\n", 'x', 'pip'));
    $runner = new FakeCommandRunner;

    $code = (new Installer($runner, $this->write, 'Linux'))->install(new SetupConfig(requirements: ['six']), $this->root, dryRun: true, exact: true);

    expect($code)->toBe(0)
        ->and($runner->ran)->toBe([])
        ->and(($this->text)())->toContain('uninstall every package not in the lock');
});
