<?php

declare(strict_types=1);

use PyHP\Setup\Installer;
use PyHP\Setup\LockFile;
use PyHP\Setup\ProjectRequirements;
use PyHP\Setup\SetupConfig;
use PyHP\Setup\VirtualEnv;

/*
 * Everything `pyhp setup` and `pyhp lock` print, line by line, for the main paths,
 * plus the branches whose only visible effect is in that output or in leftover
 * files: which Python is picked as a fallback, Windows' py launcher, quoting in
 * dry runs, the lock's input file and the cleanup of temporary files.
 */

beforeEach(function (): void {
    $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pyhp-setup-out-'.bin2hex(random_bytes(4));
    mkdir($this->root);
    $this->venv = $this->root.DIRECTORY_SEPARATOR.'.venv';
    $this->python = VirtualEnv::python($this->venv, 'Linux');
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
    $root = $this->root;
    // Plays uv's part in `pyhp lock`: writes the lock it was asked for.
    $this->resolver = function (array $command) use ($root): void {
        $output = array_search('--output-file', $command, true);
        if ($output !== false) {
            $file = $command[$output + 1];
            file_put_contents(preg_match('#^([A-Za-z]:)?[/\\\\]#', $file) === 1 ? $file : $root.DIRECTORY_SEPARATOR.$file, "idna==3.10\nsix==1.17.0\n");
        }
    };
});

afterEach(function (): void {
    removeDirectory($this->root);
});

function setupProbe(string ...$python): string
{
    return implode(' ', [...$python, '-c', Installer::VERSION_PROBE]);
}

const UV_TIP = 'https://docs.astral.sh/uv/getting-started/installation/';

test('setup with uv, Composer package requirements and overrides', function (): void {
    installPackages($this->root, ['acme/nlp' => ['extra' => ['pyhp' => ['requirements' => ['spacy>=3.8']]]]]);
    $runner = new FakeCommandRunner(programs: ['uv' => '/bin/uv'], onRun: $this->createsVenv);

    $code = (new Installer($runner, $this->write, 'Linux'))
        ->install(new SetupConfig(requirements: ['pandas'], overrides: ['numpy<2']), $this->root);

    $lines = $this->lines->getArrayCopy();
    $overrides = explode(' --override ', $lines[4])[1] ?? '';
    expect($code)->toBe(0)
        ->and($lines[0])->toBe('Python packages requested by your Composer packages:')
        ->and($lines[1])->toBe('  - acme/nlp: spacy>=3.8')
        ->and($lines[2])->toBe('Overriding version rules (extra.pyhp.overrides): numpy<2')
        ->and($lines[3])->toBe("> /bin/uv venv {$this->venv}")
        ->and($lines[4])->toStartWith("> /bin/uv pip install --python {$this->python} pandas spacy>=3.8 --override ")
        ->and($lines[5])->toBe("Done. PyHP will use {$this->python} automatically.")
        ->and(count($lines))->toBe(6)
        ->and($overrides)->not->toBe('')
        ->and(file_exists($overrides))->toBeFalse(); // the temporary overrides file is gone
});

test('setup without uv falls back to the first working Python and suggests uv', function (): void {
    $runner = new FakeCommandRunner(
        programs: ['python3.13' => '/usr/bin/python3.13', 'python3' => '/usr/bin/python3', 'python' => '/usr/bin/python'],
        // python3.13 can't report its version; python3 is 3.12 and python 3.11: the first one wins.
        captures: [setupProbe('/usr/bin/python3') => '3.12', setupProbe('/usr/bin/python') => '3.11'],
        onRun: $this->createsVenv,
    );

    $code = (new Installer($runner, $this->write, 'Linux'))
        ->install(new SetupConfig(python: '3.13', requirements: ['six']), $this->root);

    expect($code)->toBe(0)
        ->and($this->lines->getArrayCopy())->toBe([
            'Python 3.13 was not found, so Python 3.12 is used instead. With uv installed, setup can download the exact version.',
            "> /usr/bin/python3 -m venv {$this->venv}",
            "> {$this->python} -m pip install six",
            'Tip: install uv ('.UV_TIP.') for much faster installs and any Python version on demand.',
            "Done. PyHP will use {$this->python} automatically.",
        ]);
});

test('setup without uv and without a version request uses the first Python silently', function (): void {
    $runner = new FakeCommandRunner(
        programs: ['python3' => '/usr/bin/python3', 'python' => '/usr/bin/python'],
        captures: [setupProbe('/usr/bin/python3') => '3.12', setupProbe('/usr/bin/python') => '3.11'],
        onRun: $this->createsVenv,
    );

    (new Installer($runner, $this->write, 'Linux'))->install(new SetupConfig(requirements: ['six']), $this->root, useUv: false);

    expect($this->lines->getArrayCopy()[0])->toBe("> /usr/bin/python3 -m venv {$this->venv}")
        ->and($runner->commandLines()[0])->toBe("/usr/bin/python3 -m venv {$this->venv}");
});

test('a dry run prints every command, quoting paths with spaces, and changes nothing', function (): void {
    $root = $this->root.DIRECTORY_SEPARATOR.'my app';
    mkdir($root);
    $venv = $root.DIRECTORY_SEPARATOR.'.venv';
    $runner = new FakeCommandRunner(programs: ['uv' => '/bin/uv']);

    $code = (new Installer($runner, $this->write, 'Linux'))->install(new SetupConfig(requirements: ['six']), $root, dryRun: true);

    expect($code)->toBe(0)
        ->and($this->lines->getArrayCopy())->toBe([
            "> /bin/uv venv \"{$venv}\"",
            '> /bin/uv pip install --python "'.VirtualEnv::python($venv, 'Linux').'" six',
            'Dry run: nothing was changed.',
        ])
        ->and($runner->ran)->toBe([]);
});

test('setup without requirements only creates the venv and explains how to add some', function (): void {
    $runner = new FakeCommandRunner(programs: ['uv' => '/bin/uv'], onRun: $this->createsVenv);

    (new Installer($runner, $this->write, 'Linux'))->install(new SetupConfig, $this->root);

    expect($this->lines->getArrayCopy())->toBe([
        "> /bin/uv venv {$this->venv}",
        'No Python packages to install. List them in composer.json under extra.pyhp.requirements (or in requirements.txt), then run pyhp setup again.',
        "Done. PyHP will use {$this->python} automatically.",
    ]);
});

test('without uv and without Python, setup says where to get either', function (): void {
    $code = (new Installer(new FakeCommandRunner, $this->write, 'Linux'))->install(new SetupConfig(requirements: ['six']), $this->root);

    expect($code)->toBe(1)
        ->and($this->lines->getArrayCopy())->toBe([
            'No Python was found. Install uv ('.UV_TIP.') and run pyhp setup again; it downloads Python for you. Or install Python 3.11+ from https://www.python.org/downloads/.',
        ]);
});

test('with only Pythons that are too old, setup lists each version once', function (): void {
    $runner = new FakeCommandRunner(
        programs: ['python3' => '/usr/bin/python3', 'python' => '/usr/bin/python'],
        captures: [setupProbe('/usr/bin/python3') => '3.10', setupProbe('/usr/bin/python') => '3.10'],
    );

    $code = (new Installer($runner, $this->write, 'Linux'))->install(new SetupConfig(requirements: ['six']), $this->root);

    expect($code)->toBe(1)
        ->and($this->lines->getArrayCopy())->toBe([
            'PyHP needs Python 3.11 or newer, but only found 3.10. Install uv ('.UV_TIP.') and run pyhp setup again; it downloads a suitable Python.',
        ])
        ->and($runner->ran)->toBe([]);
});

test('overrides without uv stop both setup and lock with the same explanation', function (string $action): void {
    $runner = new FakeCommandRunner(programs: ['python3' => '/usr/bin/python3'], captures: [setupProbe('/usr/bin/python3') => '3.12']);
    $config = new SetupConfig(requirements: ['six'], overrides: ['numpy<2']);
    $installer = new Installer($runner, $this->write, 'Linux');

    $code = $action === 'setup' ? $installer->install($config, $this->root, useUv: false) : $installer->lock($config, $this->root, useUv: false);

    expect($code)->toBe(1)
        ->and($this->lines->getArrayCopy())->toBe([
            "extra.pyhp.overrides needs uv: pip has no way to override other packages' version rules. Install uv (".UV_TIP.') or remove extra.pyhp.overrides from composer.json.',
        ])
        ->and($runner->ran)->toBe([]);
})->with(['setup', 'lock']);

test('lock with uv shows where requirements come from and how many packages it pinned', function (): void {
    installPackages($this->root, ['acme/nlp' => ['extra' => ['pyhp' => ['requirements' => ['idna']]]]]);
    $runner = new FakeCommandRunner(programs: ['uv' => '/bin/uv'], onRun: $this->resolver);

    $code = (new Installer($runner, $this->write, 'Linux'))->lock(new SetupConfig(requirements: ['six']), $this->root);

    expect($code)->toBe(0)
        ->and($this->lines->getArrayCopy())->toBe([
            'Python packages requested by your Composer packages:',
            '  - acme/nlp: idna',
            '> /bin/uv pip compile .pyhp-requirements.in --universal --output-file pyhp.lock --custom-compile-command "vendor/bin/pyhp lock" --quiet',
            'Wrote pyhp.lock (2 packages). Commit it; pyhp setup installs exactly these versions.',
        ])
        ->and(scandir($this->root))->toBe(['.', '..', 'pyhp.lock', 'vendor']); // the input file is gone
});

test('lock keeps the project requirements file as an -r line in its input', function (): void {
    file_put_contents($this->root.'/req.txt', "six\n");
    $input = new ArrayObject;
    $resolver = $this->resolver;
    $runner = new FakeCommandRunner(programs: ['uv' => '/bin/uv'], onRun: function (array $command) use ($input, $resolver): void {
        $input->append((string) file_get_contents($this->root.DIRECTORY_SEPARATOR.'.pyhp-requirements.in'));
        $resolver($command);
    });

    (new Installer($runner, $this->write, 'Linux'))->lock(new SetupConfig(requirementsFile: 'req.txt', requirements: ['idna']), $this->root);

    expect($input->getArrayCopy())->toBe(["-r req.txt\nidna\n"]);
});

test('setup installs from an up-to-date lock, and warns about an old one', function (string $fingerprint, ?string $warning): void {
    $config = new SetupConfig(requirements: ['six']);
    $fresh = LockFile::fingerprint($this->root, $config, ProjectRequirements::collect($this->root, $config));
    file_put_contents($this->root.'/pyhp.lock', LockFile::withHeader("six==1.17.0\n", $fingerprint === 'fresh' ? $fresh : $fingerprint, 'uv'));
    $runner = new FakeCommandRunner(programs: ['uv' => '/bin/uv'], onRun: $this->createsVenv);

    (new Installer($runner, $this->write, 'Linux'))->install($config, $this->root);

    expect($this->lines->getArrayCopy())->toBe([
        "> /bin/uv venv {$this->venv}",
        $warning ?? 'Installing the exact versions from pyhp.lock.',
        "> /bin/uv pip install --python {$this->python} -r pyhp.lock",
        "Done. PyHP will use {$this->python} automatically.",
    ]);
})->with([
    'up to date' => ['fresh', null],
    'out of date' => ['stale', 'pyhp.lock is out of date: your Python requirements changed since it was written. Installing the locked versions anyway; run vendor/bin/pyhp lock to update it.'],
]);

test('a failed install explains conflicting rules line by line', function (): void {
    installPackages($this->root, ['acme/a' => ['extra' => ['pyhp' => ['requirements' => ['pandas<2']]]]]);
    $runner = new FakeCommandRunner(
        programs: ['uv' => '/bin/uv'],
        onRun: $this->createsVenv,
        exitCodeFor: fn (array $command): ?int => in_array('install', $command, true) ? 1 : null,
    );

    $code = (new Installer($runner, $this->write, 'Linux'))->install(new SetupConfig(requirements: ['pandas>=2']), $this->root);

    $install = "/bin/uv pip install --python {$this->python} pandas>=2 pandas<2";
    expect($code)->toBe(1)
        ->and($this->lines->getArrayCopy())->toBe([
            'Python packages requested by your Composer packages:',
            '  - acme/a: pandas<2',
            "> /bin/uv venv {$this->venv}",
            "> {$install}",
            "That command failed with exit code 1: {$install}",
            'These Python packages are requested by more than one source; check that their version rules can all be met:',
            '  pandas: pandas>=2 (your project), pandas<2 (acme/a)',
            "To resolve it, tighten your own requirement, force a version with extra.pyhp.overrides (uv), or skip a package's requirements with extra.pyhp.ignore-packages.",
        ]);
});

test('on Windows without a version request, the py launcher comes first, then python', function (array $programs, string $expected): void {
    $runner = new FakeCommandRunner(
        programs: $programs,
        captures: [setupProbe('C:\py.exe', '-3') => '3.13', setupProbe('C:\python.exe') => '3.12'],
        onRun: function (array $command): void {
            if (in_array('venv', $command, true)) {
                $python = VirtualEnv::python($this->venv, 'Windows');
                mkdir(dirname($python), 0777, true);
                touch($python);
            }
        },
    );

    (new Installer($runner, $this->write, 'Windows'))->install(new SetupConfig(requirements: ['six']), $this->root, useUv: false);

    expect($runner->commandLines()[0])->toBe("{$expected} -m venv {$this->venv}");
})->with([
    'both' => [['py' => 'C:\py.exe', 'python' => 'C:\python.exe'], 'C:\py.exe -3'],
    'only python' => [['python' => 'C:\python.exe'], 'C:\python.exe'],
]);

test('setup --exact without a lock removes its temporary lock afterwards', function (): void {
    $runner = new FakeCommandRunner(programs: ['uv' => '/bin/uv'], onRun: function (array $command): void {
        ($this->createsVenv)($command);
        ($this->resolver)($command);
    });

    $code = (new Installer($runner, $this->write, 'Linux'))->install(new SetupConfig(requirements: ['six']), $this->root, exact: true);

    $compile = array_values(array_filter($runner->commandLines(), fn (string $line): bool => str_contains($line, 'pip compile')))[0];
    $temporary = explode(' --output-file ', $compile)[1];
    $temporary = explode(' ', $temporary)[0];
    expect($code)->toBe(0)
        ->and($temporary)->not->toBe('')
        ->and(file_exists($temporary))->toBeFalse()
        ->and(file_exists($this->root.'/pyhp.lock'))->toBeFalse();
});

test('setup --exact without uv resolves with the venv\'s own Python and removes pip\'s report', function (): void {
    $runner = new FakeCommandRunner(
        programs: ['python3' => '/usr/bin/python3'],
        captures: [setupProbe('/usr/bin/python3') => '3.12'],
        onRun: function (array $command): void {
            ($this->createsVenv)($command);
            $report = array_search('--report', $command, true);
            if ($report !== false) {
                file_put_contents($command[$report + 1], json_encode(['install' => [['metadata' => ['name' => 'six', 'version' => '1.17.0']]]]));
            }
        },
    );

    (new Installer($runner, $this->write, 'Linux'))->install(new SetupConfig(requirements: ['six']), $this->root, useUv: false, exact: true);

    $resolve = $runner->commandLines()[1];
    $report = explode(' ', explode(' --report ', $resolve)[1])[0];
    expect($resolve)->toStartWith("{$this->python} -m pip install --dry-run --ignore-installed --quiet --report ")
        ->and($resolve)->toEndWith(' six')
        ->and(file_exists($report))->toBeFalse();
});

test('lock with overrides removes its temporary overrides file', function (): void {
    $runner = new FakeCommandRunner(programs: ['uv' => '/bin/uv'], onRun: $this->resolver);

    (new Installer($runner, $this->write, 'Linux'))->lock(new SetupConfig(requirements: ['six'], overrides: ['numpy<2']), $this->root);

    $compile = $runner->commandLines()[0];
    $overrides = explode(' ', explode(' --override ', $compile)[1])[0];
    expect($overrides)->not->toBe('')
        ->and(file_exists($overrides))->toBeFalse();
});

test('a failing lock resolution removes the half-written lock and explains conflicts', function (): void {
    installPackages($this->root, ['acme/a' => ['extra' => ['pyhp' => ['requirements' => ['six<1']]]]]);
    $root = $this->root;
    $runner = new FakeCommandRunner(
        programs: ['uv' => '/bin/uv'],
        exitCodeFor: function (array $command) use ($root): int {
            file_put_contents($root.DIRECTORY_SEPARATOR.'pyhp.lock', 'half written');

            return 1;
        },
    );

    $code = (new Installer($runner, $this->write, 'Linux'))->lock(new SetupConfig(requirements: ['six>=1']), $this->root);

    expect($code)->toBe(1)
        ->and(file_exists($this->root.'/pyhp.lock'))->toBeFalse()
        ->and(implode("\n", $this->lines->getArrayCopy()))->toContain('  six: six>=1 (your project), six<1 (acme/a)');
});
