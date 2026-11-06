<?php

declare(strict_types=1);

use PyHP\Config;
use PyHP\OutputMode;
use PyHP\Session;

/*
 * Every test talks to a real Python interpreter. Choose which one with the
 * PYHP_TEST_PYTHON environment variable (for example a venv's python.exe);
 * otherwise the platform default from Config is used.
 */
function testPython(): string
{
    $configured = getenv('PYHP_TEST_PYTHON');

    return is_string($configured) && $configured !== '' ? $configured : Config::defaultPython();
}

/**
 * @param  array<string, mixed>  $options
 */
function testConfig(array $options = []): Config
{
    return Config::fromArray(array_merge([
        'python' => testPython(),
        'output' => OutputMode::Capture,
        'timeout' => 20,
    ], $options));
}

/**
 * @param  array<string, mixed>  $options
 */
function testSession(array $options = []): Session
{
    return new Session(testConfig($options));
}

function pythonHasModule(string $module): bool
{
    static $cache = [];
    if (! array_key_exists($module, $cache)) {
        $session = testSession();
        $cache[$module] = (bool) $session->eval(
            "__import__('importlib.util').util.find_spec('{$module}') is not None"
        );
        $session->close();
    }

    return $cache[$module];
}

function removeDirectory(string $dir): void
{
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($items as $item) {
        assert($item instanceof SplFileInfo);
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($dir);
}
