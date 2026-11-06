<?php

declare(strict_types=1);

use PyHP\Config;
use PyHP\Exception\ConfigException;
use PyHP\OutputMode;

test('defaults are sensible', function (): void {
    $config = new Config;

    expect($config->python)->toBe(PHP_OS_FAMILY === 'Windows' ? 'python' : 'python3')
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
]);
