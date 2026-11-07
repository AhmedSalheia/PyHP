<?php

declare(strict_types=1);

use PyHP\Setup\ProcessRunner;

beforeEach(function (): void {
    $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pyhp-runner-'.bin2hex(random_bytes(4));
    mkdir($this->dir);
});

afterEach(function (): void {
    removeDirectory($this->dir);
});

test('find looks a program up on the PATH it was given', function (): void {
    $windows = PHP_OS_FAMILY === 'Windows';
    $file = $this->dir.DIRECTORY_SEPARATOR.($windows ? 'fakeprog.bat' : 'fakeprog');
    file_put_contents($file, $windows ? "@echo off\r\n" : "#!/bin/sh\n");
    chmod($file, 0755);

    $runner = new ProcessRunner(path: '/nowhere'.PATH_SEPARATOR.$this->dir, pathExt: '.EXE;.BAT');

    expect($runner->find('fakeprog'))->toBe($file)
        ->and($runner->find('no-such-program-pyhp'))->toBeNull();
});

test('find ignores directories and, outside Windows, files that are not executable', function (): void {
    mkdir($this->dir.DIRECTORY_SEPARATOR.'adir');
    file_put_contents($this->dir.DIRECTORY_SEPARATOR.'plain', 'x');
    chmod($this->dir.DIRECTORY_SEPARATOR.'plain', 0644);

    $runner = new ProcessRunner(path: $this->dir, pathExt: '');

    expect($runner->find('adir'))->toBeNull();
    if (PHP_OS_FAMILY !== 'Windows') {
        expect($runner->find('plain'))->toBeNull();
    }
});

test('find accepts a path to a program', function (): void {
    expect((new ProcessRunner)->find(PHP_BINARY))->toBe(PHP_BINARY)
        ->and((new ProcessRunner)->find($this->dir.DIRECTORY_SEPARATOR.'missing'))->toBeNull();
});

test('run returns the exit code of the command', function (): void {
    expect((new ProcessRunner)->run([PHP_BINARY, '-r', 'exit(3);'], $this->dir))->toBe(3)
        ->and((new ProcessRunner)->run([PHP_BINARY, '-r', 'exit(0);'], $this->dir))->toBe(0);
});

test('run starts the command in the given folder', function (): void {
    (new ProcessRunner)->run([PHP_BINARY, '-r', 'file_put_contents("here.txt", "x");'], $this->dir);

    expect(is_file($this->dir.DIRECTORY_SEPARATOR.'here.txt'))->toBeTrue();
});

test('capture returns standard output, or null when the command fails or cannot start', function (): void {
    $runner = new ProcessRunner;

    expect($runner->capture([PHP_BINARY, '-r', 'echo "3.12";'], $this->dir))->toBe('3.12')
        ->and($runner->capture([PHP_BINARY, '-r', 'echo "x"; exit(1);'], $this->dir))->toBeNull()
        ->and($runner->capture([$this->dir.DIRECTORY_SEPARATOR.'missing-program'], $this->dir))->toBeNull();
});

test('on Windows find tries the PATHEXT extensions and skips files without one', function (): void {
    file_put_contents($this->dir.DIRECTORY_SEPARATOR.'uv', '');
    file_put_contents($this->dir.DIRECTORY_SEPARATOR.'uv.exe', '');

    $runner = new ProcessRunner(path: PATH_SEPARATOR.$this->dir, pathExt: '.COM; .EXE', osFamily: 'Windows');

    expect($runner->find('uv'))->toBe($this->dir.DIRECTORY_SEPARATOR.'uv.exe')
        ->and($runner->find('uv.exe'))->toBe($this->dir.DIRECTORY_SEPARATOR.'uv.exe')
        ->and($runner->find('uv.bat'))->toBeNull();
});
