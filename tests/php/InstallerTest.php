<?php

declare(strict_types=1);

use PyHP\Setup\Installer;
use PyHP\Setup\SetupConfig;
use PyHP\Setup\VirtualEnv;

beforeEach(function (): void {
    $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pyhp-installer-'.bin2hex(random_bytes(4));
    mkdir($this->root);
    $this->venv = $this->root.DIRECTORY_SEPARATOR.'.venv';
    $lines = new ArrayObject;
    $this->lines = $lines;
    $this->write = function (string $line) use ($lines): void {
        $lines->append($line);
    };
    // Running "create a venv" in the fake makes the venv's python appear, like the real thing.
    $this->createsVenv = function (array $command): void {
        if (in_array('venv', $command, true)) {
            $python = VirtualEnv::python($this->venv, 'Linux');
            mkdir(dirname($python), 0777, true);
            touch($python);
        }
    };
});

afterEach(function (): void {
    removeDirectory($this->root);
});

function probe(string ...$python): string
{
    return implode(' ', [...$python, '-c', Installer::VERSION_PROBE]);
}

test('with uv it creates the venv for the requested Python and installs the packages', function (): void {
    $runner = new FakeCommandRunner(programs: ['uv' => '/bin/uv'], onRun: $this->createsVenv);
    $config = new SetupConfig(python: '3.12', requirements: ['pandas>=2', 'numpy']);

    $code = (new Installer($runner, $this->write, 'Linux'))->install($config, $this->root);

    $python = VirtualEnv::python($this->venv, 'Linux');
    expect($code)->toBe(0)
        ->and($runner->commandLines())->toBe([
            "/bin/uv venv {$this->venv} --python 3.12",
            "/bin/uv pip install --python {$python} pandas>=2 numpy",
        ])
        ->and($runner->ran[0]['cwd'])->toBe($this->root)
        ->and(implode("\n", $this->lines->getArrayCopy()))->toContain($python);
});

test('with uv and no version requested, uv picks the Python', function (): void {
    $runner = new FakeCommandRunner(programs: ['uv' => '/bin/uv'], onRun: $this->createsVenv);

    (new Installer($runner, $this->write, 'Linux'))->install(new SetupConfig(requirements: ['sympy']), $this->root);

    expect($runner->commandLines()[0])->toBe("/bin/uv venv {$this->venv}");
});

test('an existing venv is reused, so running setup again only installs', function (): void {
    ($this->createsVenv)(['venv']);
    $runner = new FakeCommandRunner(programs: ['uv' => '/bin/uv']);

    $code = (new Installer($runner, $this->write, 'Linux'))->install(new SetupConfig(requirements: ['pandas']), $this->root);

    expect($code)->toBe(0)
        ->and($runner->commandLines())->toHaveCount(1)
        ->and($runner->commandLines()[0])->toStartWith('/bin/uv pip install')
        ->and(implode("\n", $this->lines->getArrayCopy()))->toContain('already exists');
});

test('a requirements file is passed with -r', function (): void {
    $runner = new FakeCommandRunner(programs: ['uv' => '/bin/uv'], onRun: $this->createsVenv);
    $config = new SetupConfig(requirementsFile: 'requirements.txt');

    (new Installer($runner, $this->write, 'Linux'))->install($config, $this->root);

    $python = VirtualEnv::python($this->venv, 'Linux');
    expect($runner->commandLines()[1])->toBe("/bin/uv pip install --python {$python} -r requirements.txt");
});

test('without requirements only the venv is created, with a hint', function (): void {
    $runner = new FakeCommandRunner(programs: ['uv' => '/bin/uv'], onRun: $this->createsVenv);

    $code = (new Installer($runner, $this->write, 'Linux'))->install(new SetupConfig, $this->root);

    expect($code)->toBe(0)
        ->and($runner->commandLines())->toHaveCount(1)
        ->and(implode("\n", $this->lines->getArrayCopy()))->toContain('extra.pyhp.requirements');
});

test('without uv it uses a system Python with venv and pip', function (): void {
    $runner = new FakeCommandRunner(
        programs: ['python3' => '/usr/bin/python3'],
        captures: [probe('/usr/bin/python3') => "3.12\n"],
        onRun: $this->createsVenv,
    );

    $code = (new Installer($runner, $this->write, 'Linux'))->install(new SetupConfig(requirements: ['pandas']), $this->root);

    $python = VirtualEnv::python($this->venv, 'Linux');
    expect($code)->toBe(0)
        ->and($runner->commandLines())->toBe([
            "/usr/bin/python3 -m venv {$this->venv}",
            "{$python} -m pip install pandas",
        ])
        ->and(implode("\n", $this->lines->getArrayCopy()))->toContain('uv');
});

test('without uv a requested version is looked for first', function (): void {
    $runner = new FakeCommandRunner(
        programs: ['python3.12' => '/usr/bin/python3.12', 'python3' => '/usr/bin/python3'],
        captures: [probe('/usr/bin/python3.12') => '3.12', probe('/usr/bin/python3') => '3.10'],
        onRun: $this->createsVenv,
    );

    (new Installer($runner, $this->write, 'Linux'))->install(new SetupConfig(python: '3.12'), $this->root);

    expect($runner->commandLines()[0])->toBe("/usr/bin/python3.12 -m venv {$this->venv}");
});

test('on Windows the py launcher is used to pick the version', function (): void {
    $runner = new FakeCommandRunner(
        programs: ['py' => 'C:\Windows\py.exe'],
        captures: [probe('C:\Windows\py.exe', '-3.12') => '3.12'],
        onRun: $this->createsVenv,
    );

    (new Installer($runner, $this->write, 'Windows'))->install(new SetupConfig(python: '3.12'), $this->root);

    expect($runner->commandLines()[0])->toBe("C:\\Windows\\py.exe -3.12 -m venv {$this->venv}");
});

test('a different version is used, with a warning, when the requested one is missing', function (): void {
    $runner = new FakeCommandRunner(
        programs: ['python3' => '/usr/bin/python3'],
        captures: [probe('/usr/bin/python3') => '3.11'],
        onRun: $this->createsVenv,
    );

    $code = (new Installer($runner, $this->write, 'Linux'))->install(new SetupConfig(python: '3.13'), $this->root);

    expect($code)->toBe(0)
        ->and($runner->commandLines()[0])->toBe("/usr/bin/python3 -m venv {$this->venv}")
        ->and(implode("\n", $this->lines->getArrayCopy()))->toContain('3.13')
        ->and(implode("\n", $this->lines->getArrayCopy()))->toContain('3.11');
});

test('Python older than 3.10 is refused', function (): void {
    $runner = new FakeCommandRunner(
        programs: ['python3' => '/usr/bin/python3'],
        captures: [probe('/usr/bin/python3') => '3.9'],
    );

    $code = (new Installer($runner, $this->write, 'Linux'))->install(new SetupConfig, $this->root);

    expect($code)->toBe(1)
        ->and($runner->ran)->toBe([])
        ->and(implode("\n", $this->lines->getArrayCopy()))->toContain('3.10');
});

test('with neither uv nor Python it explains how to get one', function (): void {
    $code = (new Installer(new FakeCommandRunner, $this->write, 'Linux'))->install(new SetupConfig, $this->root);

    expect($code)->toBe(1)
        ->and(implode("\n", $this->lines->getArrayCopy()))->toContain('https://docs.astral.sh/uv/');
});

test('useUv false skips uv even when it is installed', function (): void {
    $runner = new FakeCommandRunner(
        programs: ['uv' => '/bin/uv', 'python3' => '/usr/bin/python3'],
        captures: [probe('/usr/bin/python3') => '3.12'],
        onRun: $this->createsVenv,
    );

    (new Installer($runner, $this->write, 'Linux'))->install(new SetupConfig, $this->root, useUv: false);

    expect($runner->commandLines()[0])->toStartWith('/usr/bin/python3 -m venv');
});

test('a failing step stops the setup and reports the command', function (): void {
    $runner = new FakeCommandRunner(
        programs: ['uv' => '/bin/uv'],
        exitCodes: ["/bin/uv venv {$this->venv}" => 2],
    );

    $code = (new Installer($runner, $this->write, 'Linux'))->install(new SetupConfig(requirements: ['pandas']), $this->root);

    expect($code)->toBe(1)
        ->and($runner->ran)->toHaveCount(1)
        ->and(implode("\n", $this->lines->getArrayCopy()))->toContain("/bin/uv venv {$this->venv}")
        ->and(implode("\n", $this->lines->getArrayCopy()))->toContain('exit code 2');
});

test('a dry run prints the commands without running them', function (): void {
    $runner = new FakeCommandRunner(programs: ['uv' => '/bin/uv']);

    $code = (new Installer($runner, $this->write, 'Linux'))->install(new SetupConfig(requirements: ['pandas']), $this->root, dryRun: true);

    $python = VirtualEnv::python($this->venv, 'Linux');
    expect($code)->toBe(0)
        ->and($runner->ran)->toBe([])
        ->and($this->lines->getArrayCopy())->toContain("> /bin/uv venv {$this->venv}")
        ->and($this->lines->getArrayCopy())->toContain("> /bin/uv pip install --python {$python} pandas");
});

test('the venv python lives in Scripts on Windows and bin elsewhere', function (): void {
    expect(VirtualEnv::python('env', 'Windows'))->toBe('env'.DIRECTORY_SEPARATOR.'Scripts'.DIRECTORY_SEPARATOR.'python.exe')
        ->and(VirtualEnv::python('env', 'Linux'))->toBe('env'.DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR.'python')
        ->and(VirtualEnv::python('env', 'Darwin'))->toBe('env'.DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR.'python');
});

test('a failing package install is reported', function (): void {
    ($this->createsVenv)(['venv']);
    $python = VirtualEnv::python($this->venv, 'Linux');
    $runner = new FakeCommandRunner(
        programs: ['uv' => '/bin/uv'],
        exitCodes: ["/bin/uv pip install --python {$python} no-such-package" => 1],
    );

    $code = (new Installer($runner, $this->write, 'Linux'))
        ->install(new SetupConfig(requirements: ['no-such-package']), $this->root);

    expect($code)->toBe(1)
        ->and(implode("\n", $this->lines->getArrayCopy()))->toContain('exit code 1');
});

test('a Python that cannot report its version is skipped', function (): void {
    $runner = new FakeCommandRunner(
        programs: ['python3' => '/usr/bin/python3', 'python' => '/usr/bin/python'],
        captures: [probe('/usr/bin/python') => '3.13'],
        onRun: $this->createsVenv,
    );

    (new Installer($runner, $this->write, 'Linux'))->install(new SetupConfig, $this->root);

    expect($runner->commandLines()[0])->toBe("/usr/bin/python -m venv {$this->venv}");
});

test('requirements of installed Composer packages are listed and installed', function (): void {
    installPackages($this->root, [
        'acme/nlp' => ['extra' => ['pyhp' => ['requirements' => ['spacy>=3.8']]]],
        'acme/legacy' => ['extra' => ['pyhp' => ['requirements' => ['tensorflow']]]],
    ]);
    $runner = new FakeCommandRunner(programs: ['uv' => '/bin/uv'], onRun: $this->createsVenv);
    $config = new SetupConfig(requirements: ['pandas'], ignorePackages: ['acme/legacy']);

    $code = (new Installer($runner, $this->write, 'Linux'))->install($config, $this->root);

    $python = VirtualEnv::python($this->venv, 'Linux');
    $text = implode("\n", $this->lines->getArrayCopy());
    expect($code)->toBe(0)
        ->and($runner->commandLines()[1])->toBe("/bin/uv pip install --python {$python} pandas spacy>=3.8")
        ->and($text)->toContain('acme/nlp: spacy>=3.8')
        ->and($text)->toContain('Skipping the Python requirements of acme/legacy');
});

test('overrides are passed to uv in a file', function (): void {
    ($this->createsVenv)(['venv']);
    $seen = new ArrayObject;
    $runner = new FakeCommandRunner(programs: ['uv' => '/bin/uv'], onRun: function (array $command) use ($seen): void {
        $at = array_search('--override', $command, true);
        if ($at !== false) {
            $seen->append((string) file_get_contents($command[$at + 1]));
        }
    });
    $config = new SetupConfig(requirements: ['pandas'], overrides: ['numpy<2', 'pillow==10.4.0']);

    $code = (new Installer($runner, $this->write, 'Linux'))->install($config, $this->root);

    expect($code)->toBe(0)
        ->and($runner->commandLines()[0])->toContain('--override')
        ->and($seen->getArrayCopy())->toBe(["numpy<2\npillow==10.4.0\n"])
        ->and(implode("\n", $this->lines->getArrayCopy()))->toContain('numpy<2');
});

test('overrides without uv stop the setup before anything runs', function (): void {
    $runner = new FakeCommandRunner(
        programs: ['python3' => '/usr/bin/python3'],
        captures: [probe('/usr/bin/python3') => '3.12'],
    );

    $code = (new Installer($runner, $this->write, 'Linux'))->install(new SetupConfig(overrides: ['numpy<2']), $this->root);

    expect($code)->toBe(1)
        ->and($runner->ran)->toBe([])
        ->and(implode("\n", $this->lines->getArrayCopy()))->toContain('extra.pyhp.overrides needs uv');
});

test('a failed install explains which packages asked for the same Python package', function (): void {
    ($this->createsVenv)(['venv']);
    installPackages($this->root, ['acme/old-report' => ['extra' => ['pyhp' => ['requirements' => ['pandas<2']]]]]);
    $python = VirtualEnv::python($this->venv, 'Linux');
    $runner = new FakeCommandRunner(
        programs: ['uv' => '/bin/uv'],
        exitCodes: ["/bin/uv pip install --python {$python} pandas>=2 pandas<2" => 1],
    );

    $code = (new Installer($runner, $this->write, 'Linux'))->install(new SetupConfig(requirements: ['pandas>=2']), $this->root);

    $text = implode("\n", $this->lines->getArrayCopy());
    expect($code)->toBe(1)
        ->and($text)->toContain('pandas: pandas>=2 (your project), pandas<2 (acme/old-report)')
        ->and($text)->toContain('ignore-packages');
});
