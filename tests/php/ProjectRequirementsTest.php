<?php

declare(strict_types=1);

use PyHP\Exception\ConfigException;
use PyHP\Setup\ProjectRequirements;
use PyHP\Setup\Requirement;
use PyHP\Setup\SetupConfig;

beforeEach(function (): void {
    $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pyhp-requirements-'.bin2hex(random_bytes(4));
    mkdir($this->root);
});

afterEach(function (): void {
    removeDirectory($this->root);
});

/** @return list<string> "origin: spec" for every requirement */
function described(ProjectRequirements $requirements): array
{
    return array_map(fn ($r): string => "{$r->origin}: {$r->spec}", $requirements->all());
}

test('without installed packages only the project requirements count', function (): void {
    $requirements = ProjectRequirements::collect($this->root, new SetupConfig(requirements: ['pandas>=2']));

    expect(described($requirements))->toBe(['your project: pandas>=2'])
        ->and($requirements->installArguments())->toBe(['pandas>=2'])
        ->and($requirements->fromPackages())->toBe([])
        ->and($requirements->isEmpty())->toBeFalse();
});

test('requirements declared by installed Composer packages are collected with their origin', function (): void {
    installPackages($this->root, [
        'acme/nlp' => ['extra' => ['pyhp' => ['requirements' => ['spacy>=3.8']]]],
        'acme/charts' => ['extra' => ['pyhp' => ['requirements' => 'python/requirements.txt']], 'files' => []],
        'acme/plain' => [],
        'other/extra-only' => ['extra' => ['laravel' => ['providers' => []]]],
    ]);
    mkdir($this->root.'/vendor/acme/charts/python');
    file_put_contents($this->root.'/vendor/acme/charts/python/requirements.txt', "# charts\nmatplotlib>=3.8\n\npillow  # images\n");

    $requirements = ProjectRequirements::collect($this->root, new SetupConfig(requirements: ['pandas']));

    expect(described($requirements))->toBe([
        'your project: pandas',
        'acme/nlp: spacy>=3.8',
        'acme/charts: matplotlib>=3.8',
        'acme/charts: pillow',
    ])->and($requirements->installArguments())->toBe(['pandas', 'spacy>=3.8', 'matplotlib>=3.8', 'pillow'])
        ->and(count($requirements->fromPackages()))->toBe(3);
});

test('a project requirements file is passed with -r and its packages are still known by name', function (): void {
    file_put_contents($this->root.'/requirements.txt', "pandas>=2\nnumpy\n");
    installPackages($this->root, ['acme/nlp' => ['extra' => ['pyhp' => ['requirements' => ['spacy']]]]]);

    $requirements = ProjectRequirements::collect($this->root, new SetupConfig(requirementsFile: 'requirements.txt'));

    expect($requirements->installArguments())->toBe(['-r', 'requirements.txt', 'spacy'])
        ->and($requirements->names())->toBe(['pandas', 'numpy', 'spacy']);
});

test('ignore-packages skips a package entirely', function (): void {
    installPackages($this->root, [
        'acme/nlp' => ['extra' => ['pyhp' => ['requirements' => ['spacy']]]],
        'acme/charts' => ['extra' => ['pyhp' => ['requirements' => ['matplotlib']]]],
    ]);

    $requirements = ProjectRequirements::collect($this->root, new SetupConfig(ignorePackages: ['acme/nlp']));

    expect(described($requirements))->toBe(['acme/charts: matplotlib'])
        ->and($requirements->ignored())->toBe(['acme/nlp']);
});

test("PyHP's own development requirements are never collected", function (): void {
    installPackages($this->root, ['pyhp/pyhp' => ['extra' => ['pyhp' => ['requirements' => 'requirements-dev.txt']]]]);

    expect(ProjectRequirements::collect($this->root, new SetupConfig)->isEmpty())->toBeTrue();
});

test('identical requirements are installed once, different ones are all kept', function (): void {
    installPackages($this->root, [
        'acme/a' => ['extra' => ['pyhp' => ['requirements' => ['pandas>=2', 'numpy']]]],
        'acme/b' => ['extra' => ['pyhp' => ['requirements' => ['pandas<3', 'numpy']]]],
    ]);

    $requirements = ProjectRequirements::collect($this->root, new SetupConfig(requirements: ['pandas>=2']));

    expect($requirements->installArguments())->toBe(['pandas>=2', 'numpy', 'pandas<3']);
});

test('shared() lists the Python packages that more than one origin asks for', function (): void {
    installPackages($this->root, [
        'acme/a' => ['extra' => ['pyhp' => ['requirements' => ['Pandas>=2', 'numpy']]]],
        'acme/b' => ['extra' => ['pyhp' => ['requirements' => ['pandas<2']]]],
    ]);

    $shared = ProjectRequirements::collect($this->root, new SetupConfig(requirements: ['scikit_learn']))->shared();

    expect(array_keys($shared))->toBe(['pandas'])
        ->and(array_map(fn ($r): string => "{$r->origin}: {$r->spec}", $shared['pandas']))
        ->toBe(['acme/a: Pandas>=2', 'acme/b: pandas<2']);
});

test('overrides are kept for uv', function (): void {
    $requirements = ProjectRequirements::collect($this->root, new SetupConfig(overrides: ['numpy<2']));

    expect($requirements->overrides())->toBe(['numpy<2']);
});

test('a custom vendor folder is found', function (): void {
    installPackages($this->root, ['acme/nlp' => ['extra' => ['pyhp' => ['requirements' => ['spacy']]]]], 'lib');

    $requirements = ProjectRequirements::collect($this->root, new SetupConfig(vendorDir: 'lib'));

    expect(described($requirements))->toBe(['acme/nlp: spacy']);
});

test('the Composer 1 installed.json format (a plain list) is understood', function (): void {
    installPackages($this->root, ['acme/nlp' => ['extra' => ['pyhp' => ['requirements' => ['spacy']]]]]);
    $file = $this->root.'/vendor/composer/installed.json';
    $data = json_decode((string) file_get_contents($file), true);
    file_put_contents($file, json_encode($data['packages']));

    expect(described(ProjectRequirements::collect($this->root, new SetupConfig)))->toBe(['acme/nlp: spacy']);
});

test('a package with invalid Python requirements is reported by name, with the way out', function (array $pyhp, string $message): void {
    installPackages($this->root, ['acme/broken' => ['extra' => ['pyhp' => $pyhp]]]);

    expect(fn () => ProjectRequirements::collect($this->root, new SetupConfig))
        ->toThrow(ConfigException::class, $message);
})->with([
    'empty entry' => [['requirements' => ['spacy', '']], 'every requirement must be a string'],
    'blank entry' => [['requirements' => ['spacy', '   ']], 'every requirement must be a string'],
    'wrong type' => [['requirements' => 5], 'acme/broken'],
    'non-string entry' => [['requirements' => ['spacy', 3]], 'ignore-packages'],
    'missing file' => [['requirements' => 'nope.txt'], 'nope.txt'],
    'not an object' => [['spacy'], 'acme/broken'],
]);

test('pip options in a package requirements file are refused', function (): void {
    installPackages($this->root, ['acme/opts' => [
        'extra' => ['pyhp' => ['requirements' => 'req.txt']],
        'files' => ['req.txt' => "-r other.txt\nspacy\n"],
    ]]);

    expect(fn () => ProjectRequirements::collect($this->root, new SetupConfig))
        ->toThrow(ConfigException::class, 'acme/opts');
});

test('a broken installed.json is reported', function (): void {
    mkdir($this->root.'/vendor/composer', 0777, true);
    file_put_contents($this->root.'/vendor/composer/installed.json', '{');

    expect(fn () => ProjectRequirements::collect($this->root, new SetupConfig))
        ->toThrow(ConfigException::class, 'installed.json');
});

test('odd installed.json entries are skipped and a missing install-path falls back to the vendor folder', function (): void {
    mkdir($this->root.'/vendor/composer', 0777, true);
    mkdir($this->root.'/vendor/acme/nlp', 0777, true);
    file_put_contents($this->root.'/vendor/acme/nlp/req.txt', "spacy\n");
    file_put_contents($this->root.'/vendor/composer/installed.json', json_encode(['packages' => [
        'not a package',
        ['extra' => []],
        ['name' => 'acme/nlp', 'extra' => ['pyhp' => ['requirements' => 'req.txt']]],
    ]]));

    expect(described(ProjectRequirements::collect($this->root, new SetupConfig)))->toBe(['acme/nlp: spacy']);
});

test('packages without Python requirements before others are skipped, not the end of the list', function (): void {
    installPackages($this->root, [
        'acme/plain' => [],
        'pyhp/pyhp' => ['extra' => ['pyhp' => ['requirements' => ['pytest']]]],
        'acme/nlp' => ['extra' => ['pyhp' => ['requirements' => ['spacy']]]],
        'acme/legacy' => ['extra' => ['pyhp' => ['requirements' => ['tensorflow']]]],
        'acme/charts' => ['extra' => ['pyhp' => ['requirements' => ['matplotlib']]]],
    ]);

    $requirements = ProjectRequirements::collect($this->root, new SetupConfig(requirements: ['pandas'], ignorePackages: ['acme/legacy']));

    expect(described($requirements))->toBe(['your project: pandas', 'acme/nlp: spacy', 'acme/charts: matplotlib'])
        ->and(array_map(fn ($r): string => $r->spec, $requirements->fromPackages()))->toBe(['spacy', 'matplotlib'])
        ->and($requirements->ignored())->toBe(['acme/legacy']);
});

test('the first spelling of a package name is the one reported', function (): void {
    installPackages($this->root, ['acme/a' => ['extra' => ['pyhp' => ['requirements' => ['pandas<3']]]]]);

    $requirements = ProjectRequirements::collect($this->root, new SetupConfig(requirements: ['Pandas>=2']));

    expect($requirements->names())->toBe(['Pandas']);
});

test('a package asked for twice by the same origin is not reported as shared', function (): void {
    $requirements = ProjectRequirements::collect($this->root, new SetupConfig(requirements: ['pandas>=2', 'pandas<3']));

    expect($requirements->shared())->toBe([]);
});

test('install-path from installed.json is used to find a package requirements file', function (): void {
    mkdir($this->root.'/vendor/composer', 0777, true);
    mkdir($this->root.'/packages/nlp', 0777, true);
    file_put_contents($this->root.'/packages/nlp/req.txt', "spacy\n");
    file_put_contents($this->root.'/vendor/composer/installed.json', json_encode(['packages' => [
        ['name' => 'acme/nlp', 'install-path' => '../../packages/nlp', 'extra' => ['pyhp' => ['requirements' => 'req.txt']]],
    ]]));

    expect(described(ProjectRequirements::collect($this->root, new SetupConfig)))->toBe(['acme/nlp: spacy']);
});

test('installed.json entries without a name or with a non-object extra are skipped', function (): void {
    mkdir($this->root.'/vendor/composer', 0777, true);
    file_put_contents($this->root.'/vendor/composer/installed.json', json_encode(['packages' => [
        ['extra' => ['pyhp' => ['requirements' => ['nameless']]]],
        ['name' => 'acme/odd', 'extra' => 'not an object'],
        ['name' => 'acme/fine', 'extra' => ['pyhp' => ['requirements' => ['six']]]],
    ]]));

    expect(described(ProjectRequirements::collect($this->root, new SetupConfig)))->toBe(['acme/fine: six']);
});

test('the error about invalid requirements starts with the package and ends with the way out', function (): void {
    installPackages($this->root, ['acme/broken' => ['extra' => ['pyhp' => ['requirements' => 5]]]]);

    try {
        ProjectRequirements::collect($this->root, new SetupConfig);
        $message = '';
    } catch (ConfigException $error) {
        $message = $error->getMessage();
    }

    expect($message)->toStartWith('The Composer package acme/broken declares invalid Python requirements in its extra.pyhp: ')
        ->and($message)->toEndWith('To skip them, add "acme/broken" to extra.pyhp.ignore-packages in your composer.json.');
});

test('a requirement name is read without leading spaces or extras', function (string $spec, ?string $name): void {
    expect(Requirement::rawName($spec))->toBe($name);
})->with([
    'plain' => ['spacy', 'spacy'],
    'leading spaces' => ['  spacy>=3', 'spacy'],
    'extras' => ['spacy[ja]>=3.8', 'spacy'],
    'not a name' => ['>=3', null],
]);
