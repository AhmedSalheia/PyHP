<?php

declare(strict_types=1);

namespace PyHP\Setup;

/**
 * Runs external programs for `pyhp setup`. An interface because it is the
 * boundary to the operating system, so the installer's tests can fake it.
 */
interface CommandRunner
{
    /** Full path of a program on the PATH (or the program itself if it's a path), or null. */
    public function find(string $program): ?string;

    /**
     * Runs a command with its output shown to the user and returns its exit code.
     *
     * @param  list<string>  $command  program followed by its arguments; no shell is involved
     */
    public function run(array $command, string $cwd): int;

    /**
     * Runs a command quietly and returns its trimmed standard output, or null if it failed.
     *
     * @param  list<string>  $command
     */
    public function capture(array $command, string $cwd): ?string;
}
