<?php

declare(strict_types=1);

namespace PyHP\Setup;

/**
 * Runs programs directly (no shell) with proc_open. Meant for the command line.
 *
 * @internal Part of the pyhp command line, not of the public API: use `vendor/bin/pyhp` instead.
 */
final class ProcessRunner implements CommandRunner
{
    /** Exit code reported when a program can't be started at all, as shells do. */
    public const NOT_STARTED = 127;

    private readonly string $path;

    private readonly string $pathExt;

    /**
     * @param  string|null  $path  directories to search, separated by PATH_SEPARATOR; null reads PATH
     * @param  string|null  $pathExt  Windows executable extensions, separated by ";"; null reads PATHEXT
     */
    public function __construct(
        ?string $path = null,
        ?string $pathExt = null,
        private readonly string $osFamily = PHP_OS_FAMILY,
    ) {
        $this->path = $path ?? self::env('PATH');
        $this->pathExt = $pathExt ?? (self::env('PATHEXT') ?: '.COM;.EXE;.BAT;.CMD');
    }

    public function find(string $program): ?string
    {
        if (str_contains($program, '/') || str_contains($program, '\\')) {
            return $this->isProgram($program) ? $program : null;
        }

        foreach (explode(PATH_SEPARATOR, $this->path) as $directory) {
            if ($directory === '') {
                continue;
            }
            foreach ($this->fileNames($program) as $name) {
                $candidate = rtrim($directory, '/\\').DIRECTORY_SEPARATOR.$name;
                if ($this->isProgram($candidate)) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    public function run(array $command, string $cwd): int
    {
        $process = $this->open($command, [0 => STDIN, 1 => STDOUT, 2 => STDERR], $cwd, $pipes);

        return $process === null ? self::NOT_STARTED : proc_close($process);
    }

    public function capture(array $command, string $cwd): ?string
    {
        $nullDevice = $this->osFamily === 'Windows' ? 'NUL' : '/dev/null';
        $process = $this->open(
            $command,
            [0 => ['file', $nullDevice, 'r'], 1 => ['pipe', 'w'], 2 => ['file', $nullDevice, 'w']],
            $cwd,
            $pipes,
        );
        if ($process === null) {
            return null;
        }

        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        return proc_close($process) === 0 && $output !== false ? trim($output) : null;
    }

    /**
     * proc_open() without the warning it raises on Windows when the program is missing.
     *
     * @param  list<string>  $command
     * @param  array<int, list<string>|resource>  $descriptors
     * @param  array<int, resource>|null  $pipes
     *
     * @param-out array<int, resource> $pipes
     *
     * @return resource|null
     */
    private function open(array $command, array $descriptors, string $cwd, ?array &$pipes)
    {
        set_error_handler(static fn (): bool => true);
        try {
            $process = proc_open($command, $descriptors, $pipes, $cwd);
        } finally {
            restore_error_handler();
        }

        return is_resource($process) ? $process : null;
    }

    /** @return list<string> */
    private function fileNames(string $program): array
    {
        if ($this->osFamily !== 'Windows') {
            return [$program];
        }

        $extensions = array_values(array_filter(
            array_map(static fn (string $ext): string => strtolower(trim($ext)), explode(';', $this->pathExt)),
            static fn (string $ext): bool => $ext !== '',
        ));
        if (in_array(strtolower((string) strrchr($program, '.')), $extensions, true)) {
            return [$program];
        }

        // A file without an executable extension can't be started on Windows, so don't try it.
        return array_map(static fn (string $ext): string => $program.$ext, $extensions);
    }

    private function isProgram(string $file): bool
    {
        return is_file($file) && ($this->osFamily === 'Windows' || is_executable($file));
    }

    private static function env(string $name): string
    {
        $value = getenv($name);

        return is_string($value) ? $value : '';
    }
}
