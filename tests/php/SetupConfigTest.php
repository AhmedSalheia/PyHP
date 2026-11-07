<?php

declare(strict_types=1);

use PyHP\Exception\ConfigException;
use PyHP\Setup\SetupConfig;

beforeEach(function (): void {
    $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pyhp-setup-config-'.bin2hex(random_bytes(4));
    mkdir($this->root);
});

afterEach(function (): void {
    removeDirectory($this->root);
});

/**
 * @param  array<string, mixed>  $composer
 */
function writeComposerJson(string $root, array $composer): void
{
    file_put_contents($root.'/composer.json', json_encode($composer, JSON_THROW_ON_ERROR));
}

test('a project without any settings gets a .venv and no requirements', function (): void {
    $config = SetupConfig::fromProject($this->root);

    expect($config->python)->toBeNull()
        ->and($config->venv)->toBe('.venv')
        ->and($config->requirements)->toBe([])
        ->and($config->requirementsFile)->toBeNull();
});

test('settings are read from extra.pyhp in composer.json', function (): void {
    writeComposerJson($this->root, ['name' => 'acme/app', 'extra' => ['pyhp' => [
        'python' => '3.12',
        'venv' => 'python-env',
        'requirements' => ['pandas>=2', 'numpy'],
    ]]]);

    $config = SetupConfig::fromProject($this->root);

    expect($config->python)->toBe('3.12')
        ->and($config->venv)->toBe('python-env')
        ->and($config->requirements)->toBe(['pandas>=2', 'numpy'])
        ->and($config->requirementsFile)->toBeNull();
});

test('requirements can name a requirements file instead of listing packages', function (): void {
    writeComposerJson($this->root, ['extra' => ['pyhp' => ['requirements' => 'python/requirements.txt']]]);

    $config = SetupConfig::fromProject($this->root);

    expect($config->requirements)->toBe([])
        ->and($config->requirementsFile)->toBe('python/requirements.txt');
});

test('a requirements.txt in the project root is used when nothing else is configured', function (): void {
    file_put_contents($this->root.'/requirements.txt', "pandas\n");
    writeComposerJson($this->root, ['name' => 'acme/app']);

    expect(SetupConfig::fromProject($this->root)->requirementsFile)->toBe('requirements.txt');
});

test('requirements listed in composer.json win over a requirements.txt', function (): void {
    file_put_contents($this->root.'/requirements.txt', "pandas\n");
    writeComposerJson($this->root, ['extra' => ['pyhp' => ['requirements' => ['sympy']]]]);

    $config = SetupConfig::fromProject($this->root);

    expect($config->requirements)->toBe(['sympy'])
        ->and($config->requirementsFile)->toBeNull();
});

test('command line options override the project settings', function (): void {
    writeComposerJson($this->root, ['extra' => ['pyhp' => ['python' => '3.11', 'venv' => 'env']]]);

    $config = SetupConfig::fromProject($this->root)->with(python: '3.13', venv: null);

    expect($config->python)->toBe('3.13')
        ->and($config->venv)->toBe('env');
});

test('invalid settings are rejected with a clear message', function (array $pyhp, string $message): void {
    writeComposerJson($this->root, ['extra' => ['pyhp' => $pyhp]]);

    expect(fn () => SetupConfig::fromProject($this->root))->toThrow(ConfigException::class, $message);
})->with([
    'python not a string' => [['python' => 3.12], 'extra.pyhp.python'],
    'python not a version' => [['python' => 'latest'], 'extra.pyhp.python'],
    'empty venv' => [['venv' => ''], 'extra.pyhp.venv'],
    'requirements of the wrong type' => [['requirements' => 5], 'extra.pyhp.requirements'],
    'non-string requirement' => [['requirements' => ['pandas', 2]], 'extra.pyhp.requirements'],
    'unknown key' => [['packages' => ['pandas']], 'packages'],
]);

test('extra.pyhp must be an object', function (): void {
    writeComposerJson($this->root, ['extra' => ['pyhp' => 'pandas']]);

    expect(fn () => SetupConfig::fromProject($this->root))->toThrow(ConfigException::class, 'extra.pyhp');
});

test('a broken composer.json is reported, not ignored', function (): void {
    file_put_contents($this->root.'/composer.json', '{ not json');

    expect(fn () => SetupConfig::fromProject($this->root))->toThrow(ConfigException::class, 'composer.json');
});

test('the venv folder resolves against the project root unless it is absolute', function (): void {
    $relative = new SetupConfig(venv: 'env');
    $absolute = new SetupConfig(venv: $this->root.DIRECTORY_SEPARATOR.'elsewhere');

    expect($relative->venvPath('/project'))->toBe('/project'.DIRECTORY_SEPARATOR.'env')
        ->and($absolute->venvPath('/project'))->toBe($this->root.DIRECTORY_SEPARATOR.'elsewhere');
});

test('the constructor validates the version and the venv folder', function (): void {
    expect(fn () => new SetupConfig(python: '3'))->toThrow(ConfigException::class, 'must look like 3.12')
        ->and(fn () => new SetupConfig(venv: ''))->toThrow(ConfigException::class, 'must not be empty');
});
