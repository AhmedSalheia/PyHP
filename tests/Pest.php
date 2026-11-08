<?php

declare(strict_types=1);

use PyHP\Config;
use PyHP\OutputMode;
use PyHP\Session;
use PyHP\Setup\CommandRunner;

/*
 * Every test talks to a real Python interpreter. Choose which one with the
 * PYHP_TEST_PYTHON environment variable (for example a venv's python.exe);
 * otherwise the one PyHP itself would pick (Config::detectPython()), which is
 * this repository's .venv after `php bin/pyhp setup`.
 */
function testPython(): string
{
    $configured = getenv('PYHP_TEST_PYTHON');

    return is_string($configured) && $configured !== '' ? $configured : Config::detectPython();
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

/**
 * Stands in for real processes in the setup tests: records every command and
 * answers find()/capture() from what the test configured.
 */
final class FakeCommandRunner implements CommandRunner
{
    /** @var list<array{command: list<string>, cwd: string}> */
    public array $ran = [];

    /**
     * @param  array<string, string>  $programs  program name => path that find() reports
     * @param  array<string, string>  $captures  command line (joined with spaces) => its output
     * @param  array<string, int>  $exitCodes  command line => exit code (default 0)
     * @param  (Closure(list<string>): void)|null  $onRun  side effect of running a command, e.g. creating the venv
     * @param  (Closure(list<string>): ?int)|null  $exitCodeFor  exit code for commands not in $exitCodes (null: 0)
     */
    public function __construct(
        private readonly array $programs = [],
        private readonly array $captures = [],
        private readonly array $exitCodes = [],
        private readonly ?Closure $onRun = null,
        private readonly ?Closure $exitCodeFor = null,
    ) {}

    public function find(string $program): ?string
    {
        return $this->programs[$program] ?? null;
    }

    public function run(array $command, string $cwd): int
    {
        $this->ran[] = ['command' => $command, 'cwd' => $cwd];
        $code = $this->exitCodes[implode(' ', $command)]
            ?? ($this->exitCodeFor !== null ? ($this->exitCodeFor)($command) : null)
            ?? 0;
        if ($code === 0 && $this->onRun !== null) {
            ($this->onRun)($command);
        }

        return $code;
    }

    public function capture(array $command, string $cwd): ?string
    {
        return $this->captures[implode(' ', $command)] ?? null;
    }

    /** @return list<string> every command run, joined with spaces */
    public function commandLines(): array
    {
        return array_map(fn (array $run): string => implode(' ', $run['command']), $this->ran);
    }
}

/**
 * Installs fake Composer packages: writes vendor/composer/installed.json the way
 * Composer 2 does, plus each package's folder and extra files.
 *
 * @param  array<string, array{extra?: array<string, mixed>, files?: array<string, string>}>  $packages
 */
function installPackages(string $root, array $packages, string $vendor = 'vendor'): void
{
    $entries = [];
    foreach ($packages as $name => $package) {
        $dir = $root.DIRECTORY_SEPARATOR.$vendor.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $name);
        mkdir($dir, 0777, true);
        foreach ($package['files'] ?? [] as $file => $contents) {
            file_put_contents($dir.DIRECTORY_SEPARATOR.$file, $contents);
        }
        $entries[] = ['name' => $name, 'extra' => $package['extra'] ?? [], 'install-path' => '../'.$name];
    }
    @mkdir($root.DIRECTORY_SEPARATOR.$vendor.DIRECTORY_SEPARATOR.'composer', 0777, true);
    file_put_contents(
        $root.DIRECTORY_SEPARATOR.$vendor.DIRECTORY_SEPARATOR.'composer'.DIRECTORY_SEPARATOR.'installed.json',
        json_encode(['packages' => $entries, 'dev' => true, 'dev-package-names' => []], JSON_THROW_ON_ERROR),
    );
}
