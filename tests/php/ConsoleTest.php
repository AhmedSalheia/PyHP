<?php

declare(strict_types=1);

use PyHP\Console\Application;
use PyHP\Setup\Installer;
use PyHP\Setup\VirtualEnv;

beforeEach(function (): void {
    $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pyhp-console-'.bin2hex(random_bytes(4));
    mkdir($this->root);
    $out = new ArrayObject;
    $err = new ArrayObject;
    $this->output = $out;
    $this->errors = $err;
    $this->app = fn (FakeCommandRunner $runner): Application => new Application(
        $runner,
        function (string $text) use ($out): void {
            $out->append($text);
        },
        function (string $text) use ($err): void {
            $err->append($text);
        },
        'Linux',
    );
});

afterEach(function (): void {
    removeDirectory($this->root);
});

test('help is shown without arguments and with help or --help', function (array $args): void {
    $code = ($this->app)(new FakeCommandRunner)->run($args, $this->root);

    expect($code)->toBe(0)
        ->and(implode('', $this->output->getArrayCopy()))->toContain('pyhp setup')
        ->and(implode('', $this->output->getArrayCopy()))->toContain('--dry-run');
})->with([
    'no arguments' => [[]],
    'help' => [['help']],
    '--help' => [['--help']],
    'setup --help' => [['setup', '--help']],
]);

test('setup reads composer.json and applies command line options', function (): void {
    file_put_contents($this->root.'/composer.json', json_encode(['extra' => ['pyhp' => [
        'python' => '3.11', 'requirements' => ['pandas'],
    ]]]));
    $runner = new FakeCommandRunner(programs: ['uv' => '/bin/uv']);

    $code = ($this->app)($runner)->run(['setup', '--python=3.13', '--venv=env', '--dry-run'], $this->root);

    $venv = $this->root.DIRECTORY_SEPARATOR.'env';
    expect($code)->toBe(0)
        ->and(implode('', $this->output->getArrayCopy()))->toContain("> /bin/uv venv {$venv} --python 3.13")
        ->and(implode('', $this->output->getArrayCopy()))->toContain('> /bin/uv pip install --python '.VirtualEnv::python($venv, 'Linux').' pandas');
});

test('setup --no-uv uses the system Python', function (): void {
    $runner = new FakeCommandRunner(
        programs: ['uv' => '/bin/uv', 'python3' => '/usr/bin/python3'],
        captures: ['/usr/bin/python3 -c '.Installer::VERSION_PROBE => '3.12'],
    );

    ($this->app)($runner)->run(['setup', '--no-uv', '--dry-run'], $this->root);

    expect(implode('', $this->output->getArrayCopy()))->toContain('> /usr/bin/python3 -m venv');
});

test('wrong usage exits with 2 and a message on stderr', function (array $args, string $message): void {
    $code = ($this->app)(new FakeCommandRunner)->run($args, $this->root);

    expect($code)->toBe(2)
        ->and(implode('', $this->errors->getArrayCopy()))->toContain($message);
})->with([
    'unknown command' => [['install'], 'Unknown command "install"'],
    'unknown option' => [['setup', '--fast'], 'Unknown option "--fast"'],
    'option without a value' => [['setup', '--python'], '--python needs a value'],
    'bad version' => [['setup', '--python=new'], '--python must be a version like 3.12'],
]);

test('a broken composer.json is reported on stderr', function (): void {
    file_put_contents($this->root.'/composer.json', '{');

    $code = ($this->app)(new FakeCommandRunner)->run(['setup'], $this->root);

    expect($code)->toBe(1)
        ->and(implode('', $this->errors->getArrayCopy()))->toContain('composer.json');
});

test('the bin/pyhp script runs the application', function (): void {
    $script = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR.'pyhp';
    $output = [];
    exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($script).' --help', $output, $code);

    expect($code)->toBe(0)
        ->and(implode("\n", $output))->toContain('pyhp setup');
});

test('info reports a broken composer.json on stderr', function (): void {
    file_put_contents($this->root.'/composer.json', '{');

    $code = ($this->app)(new FakeCommandRunner)->run(['info'], $this->root);

    expect($code)->toBe(1)
        ->and(implode('', $this->errors->getArrayCopy()))->toContain('composer.json');
});

test('pyhp lock runs the resolver with the command line options', function (): void {
    file_put_contents($this->root.'/composer.json', json_encode(['extra' => ['pyhp' => ['requirements' => ['six']]]]));
    $runner = new FakeCommandRunner(programs: ['uv' => '/bin/uv']);

    $code = ($this->app)($runner)->run(['lock', '--python=3.13', '--dry-run'], $this->root);

    expect($code)->toBe(0)
        ->and(implode('', $this->output->getArrayCopy()))->toContain('> /bin/uv pip compile .pyhp-requirements.in --universal')
        ->and(implode('', $this->output->getArrayCopy()))->toContain('--python-version 3.13');
});

test('pyhp lock rejects setup-only options', function (): void {
    $code = ($this->app)(new FakeCommandRunner)->run(['lock', '--exact'], $this->root);

    expect($code)->toBe(2)
        ->and(implode('', $this->errors->getArrayCopy()))->toContain('Unknown option "--exact" for lock');
});

test('setup --exact syncs instead of installing', function (): void {
    file_put_contents($this->root.'/composer.json', json_encode(['extra' => ['pyhp' => ['requirements' => ['six']]]]));
    file_put_contents($this->root.'/pyhp.lock', "six==1.17.0\n");
    $runner = new FakeCommandRunner(programs: ['uv' => '/bin/uv']);

    ($this->app)($runner)->run(['setup', '--exact', '--dry-run'], $this->root);

    expect(implode('', $this->output->getArrayCopy()))->toContain('pip sync --python');
});

test('the help mentions lock and --exact', function (): void {
    ($this->app)(new FakeCommandRunner)->run(['help'], $this->root);

    expect(implode('', $this->output->getArrayCopy()))->toContain('pyhp lock')
        ->and(implode('', $this->output->getArrayCopy()))->toContain('--exact');
});
