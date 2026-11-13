<?php

declare(strict_types=1);

use PyHP\Config;
use PyHP\Exception\ConfigException;
use PyHP\OutputMode;
use PyHP\Setup\PythonSource;
use PyHP\Setup\VirtualEnv;

test('defaults are sensible', function (): void {
    $config = new Config;

    expect($config->python)->toBe(Config::detectPython())
        ->and($config->timeout)->toBe(30.0)
        ->and($config->startupTimeout)->toBe(10.0)
        ->and($config->output)->toBe(OutputMode::Echo)
        ->and($config->maxMessageBytes)->toBe(64 * 1024 * 1024)
        ->and($config->inheritEnv)->toBeTrue();
});

test('fromArray accepts snake_case keys and enum strings', function (): void {
    $config = Config::fromArray([
        'python' => '/opt/venv/bin/python',
        'timeout' => 5,
        'startup_timeout' => 2.5,
        'cwd' => '/tmp',
        'env' => ['A' => '1'],
        'inherit_env' => false,
        'paths' => ['/lib'],
        'output' => 'capture',
        'max_message_bytes' => 2048,
        'log_file' => '/tmp/pyhp.log',
        'iter_batch_size' => 10,
    ]);

    expect($config->python)->toBe('/opt/venv/bin/python')
        ->and($config->timeout)->toBe(5.0)
        ->and($config->startupTimeout)->toBe(2.5)
        ->and($config->cwd)->toBe('/tmp')
        ->and($config->env)->toBe(['A' => '1'])
        ->and($config->inheritEnv)->toBeFalse()
        ->and($config->paths)->toBe(['/lib'])
        ->and($config->output)->toBe(OutputMode::Capture)
        ->and($config->maxMessageBytes)->toBe(2048)
        ->and($config->logFile)->toBe('/tmp/pyhp.log')
        ->and($config->iterBatchSize)->toBe(10);
});

test('a null timeout means no limit', function (): void {
    expect(Config::fromArray(['timeout' => null])->timeout)->toBeNull();
});

test('with() overrides only the given options', function (): void {
    $base = Config::fromArray(['python' => 'py', 'timeout' => 3]);
    $changed = $base->with(['timeout' => 9]);

    expect($changed->python)->toBe('py')
        ->and($changed->timeout)->toBe(9.0)
        ->and($base->timeout)->toBe(3.0);
});

test('unknown options are rejected with the list of valid ones', function (): void {
    expect(fn () => Config::fromArray(['pyhton' => 'x']))
        ->toThrow(ConfigException::class, 'Unknown PyHP option "pyhton"');
});

test('auto_restart is off by default and set by its option or constructor argument', function (): void {
    expect((new Config)->autoRestart)->toBeFalse()
        ->and(Config::fromArray(['auto_restart' => true])->autoRestart)->toBeTrue()
        ->and((new Config(autoRestart: true))->autoRestart)->toBeTrue();
});

test('the restart option from 0.4 is refused with its new name', function (): void {
    expect(fn () => Config::fromArray(['restart' => true]))
        ->toThrow(ConfigException::class, 'renamed to "auto_restart"');
});

test('invalid option values are rejected', function (array $options, string $message): void {
    expect(fn () => Config::fromArray($options))->toThrow(ConfigException::class, $message);
})->with([
    'empty python' => [['python' => ''], 'python'],
    'negative timeout' => [['timeout' => -1], 'timeout'],
    'zero startup timeout' => [['startup_timeout' => 0], 'startup_timeout'],
    'bad output mode' => [['output' => 'loud'], 'output'],
    'tiny message limit' => [['max_message_bytes' => 10], 'max_message_bytes'],
    'non-string env value' => [['env' => ['A' => 1]], 'env'],
    'non-string path' => [['paths' => [1]], 'paths'],
    'zero batch size' => [['iter_batch_size' => 0], 'iter_batch_size'],
    'wrong type' => [['timeout' => 'soon'], 'timeout'],
    'auto_restart not a bool' => [['auto_restart' => 'yes'], 'auto_restart'],
]);

test('the platform default interpreter is python on Windows and python3 elsewhere', function (): void {
    expect(Config::defaultPython())->toBe(PHP_OS_FAMILY === 'Windows' ? 'python' : 'python3');
});

test('detectPython prefers PYHP_PYTHON, then the project venv, then the platform default', function (): void {
    $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pyhp-detect-'.bin2hex(random_bytes(4));
    mkdir($root);
    $previous = getenv('PYHP_PYTHON');
    putenv('PYHP_PYTHON');

    try {
        expect(Config::detectPython($root))->toBe(Config::defaultPython());

        $venvPython = VirtualEnv::python($root.DIRECTORY_SEPARATOR.'.venv');
        mkdir(dirname($venvPython), 0777, true);
        touch($venvPython);
        expect(Config::detectPython($root))->toBe($venvPython);

        putenv('PYHP_PYTHON=/custom/python');
        expect(Config::detectPython($root))->toBe('/custom/python');
    } finally {
        putenv($previous === false ? 'PYHP_PYTHON' : 'PYHP_PYTHON='.$previous);
        removeDirectory($root);
    }
});

test('detectPython follows the venv folder set in composer.json', function (): void {
    $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pyhp-detect-'.bin2hex(random_bytes(4));
    mkdir($root);
    file_put_contents($root.'/composer.json', json_encode(['extra' => ['pyhp' => ['venv' => 'pyenv']]]));
    $venvPython = VirtualEnv::python($root.DIRECTORY_SEPARATOR.'pyenv');
    mkdir(dirname($venvPython), 0777, true);
    touch($venvPython);
    $previous = getenv('PYHP_PYTHON');
    putenv('PYHP_PYTHON');

    try {
        expect(Config::detectPython($root))->toBe($venvPython);
    } finally {
        putenv($previous === false ? 'PYHP_PYTHON' : 'PYHP_PYTHON='.$previous);
        removeDirectory($root);
    }
});

test('locatePython says why it picked the interpreter', function (): void {
    $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pyhp-locate-'.bin2hex(random_bytes(4));
    mkdir($root);
    $previous = getenv('PYHP_PYTHON');
    putenv('PYHP_PYTHON');

    try {
        $default = Config::locatePython($root);
        expect($default->python)->toBe(Config::defaultPython())
            ->and($default->source)->toBe(PythonSource::PlatformDefault)
            ->and($default->venv)->toBe($root.DIRECTORY_SEPARATOR.'.venv');

        $venvPython = VirtualEnv::python($root.DIRECTORY_SEPARATOR.'.venv');
        mkdir(dirname($venvPython), 0777, true);
        touch($venvPython);
        expect(Config::locatePython($root)->source)->toBe(PythonSource::ProjectVenv);

        putenv('PYHP_PYTHON=/custom/python');
        $fromEnv = Config::locatePython($root);
        expect($fromEnv->python)->toBe('/custom/python')
            ->and($fromEnv->source)->toBe(PythonSource::EnvironmentVariable)
            ->and($fromEnv->source->describe())->toContain('PYHP_PYTHON');
    } finally {
        putenv($previous === false ? 'PYHP_PYTHON' : 'PYHP_PYTHON='.$previous);
        removeDirectory($root);
    }
});
