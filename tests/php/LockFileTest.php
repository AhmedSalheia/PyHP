<?php

declare(strict_types=1);

use PyHP\Setup\LockFile;
use PyHP\Setup\ProjectRequirements;
use PyHP\Setup\SetupConfig;

beforeEach(function (): void {
    $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pyhp-lock-'.bin2hex(random_bytes(4));
    mkdir($this->root);
});

afterEach(function (): void {
    removeDirectory($this->root);
});

function fingerprintOf(string $root, SetupConfig $config): string
{
    return LockFile::fingerprint($root, $config, ProjectRequirements::collect($root, $config));
}

test('the fingerprint changes when anything that affects the resolution changes', function (): void {
    $base = fingerprintOf($this->root, new SetupConfig(requirements: ['pandas']));

    expect(fingerprintOf($this->root, new SetupConfig(requirements: ['pandas'])))->toBe($base)
        ->and(fingerprintOf($this->root, new SetupConfig(requirements: ['pandas>=2'])))->not->toBe($base)
        ->and(fingerprintOf($this->root, new SetupConfig(python: '3.12', requirements: ['pandas'])))->not->toBe($base)
        ->and(fingerprintOf($this->root, new SetupConfig(requirements: ['pandas'], overrides: ['numpy<2'])))->not->toBe($base);
});

test('the fingerprint follows the contents of a requirements file', function (): void {
    file_put_contents($this->root.'/requirements.txt', "pandas\n");
    $before = fingerprintOf($this->root, new SetupConfig(requirementsFile: 'requirements.txt'));
    file_put_contents($this->root.'/requirements.txt', "pandas\nnumpy\n");

    expect(fingerprintOf($this->root, new SetupConfig(requirementsFile: 'requirements.txt')))->not->toBe($before);
});

test('the header records the fingerprint and the resolver, and can be read back', function (): void {
    $text = LockFile::withHeader("six==1.17.0\n", 'abc123', 'uv, for every operating system');

    expect($text)->toStartWith('# pyhp.lock')
        ->and($text)->toContain('vendor/bin/pyhp lock')
        ->and($text)->toContain('uv, for every operating system')
        ->and($text)->toContain("six==1.17.0\n")
        ->and(LockFile::fingerprintIn($text))->toBe('abc123')
        ->and(LockFile::fingerprintIn("six==1.17.0\n"))->toBeNull();
});

test("pip's install report becomes sorted, pinned lines", function (): void {
    $report = json_encode(['install' => [
        ['metadata' => ['name' => 'six', 'version' => '1.17.0']],
        ['metadata' => ['name' => 'Idna', 'version' => '3.20']],
    ]]);

    expect(LockFile::fromPipReport((string) $report))->toBe("idna==3.20\nsix==1.17.0\n");
});

test('a broken pip report is rejected', function (string $report): void {
    expect(fn () => LockFile::fromPipReport($report))->toThrow(RuntimeException::class, 'pip');
})->with([
    'not JSON' => ['{'],
    'no install list' => ['{"version": "1"}'],
    'entry without a version' => ['{"install": [{"metadata": {"name": "six"}}]}'],
]);

test('the package names in a lock are read, ignoring comments, options and markers', function (): void {
    $lock = "# header\n--index-url https://example.com\nidna==3.20\nSix==1.17.0 ; python_version >= \"3.10\"\n    # via x\n";

    expect(LockFile::packageNames($lock))->toBe(['idna', 'six']);
});
