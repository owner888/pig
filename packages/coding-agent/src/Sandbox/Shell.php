<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Sandbox;

/**
 * Somewhere an account's tools run that is not this process: its Docker container
 * (`DockerContainer`) or a machine reached over SSH (`SshTarget`). Seen from the host, each is a
 * command that runs another command there (docs/dev/design/storage.md, phase 4 steps 3b and 6).
 */
interface Shell
{
    /**
     * The command that runs `$inside` there: in `$cwd` there when given, with `$env` set there
     * (the host's own environment never goes in), reading standard input when `$stdin`, on a
     * terminal when `$tty` (the web terminal's shell).
     *
     * @param list<string> $inside
     * @param array<string, string> $env
     * @return list<string>
     */
    public function exec(array $inside, ?string $cwd = null, array $env = [], bool $stdin = false, bool $tty = false): array;

    /**
     * Run `$inside` there to the end, `$input` on its standard input: its exit code, what it wrote,
     * and what it said on standard error. Synchronous, as a local file read is: these are short (a
     * `cat`, a `stat`, a `mkdir`).
     *
     * @param list<string> $inside
     * @return array{0: int, 1: string, 2: string}
     */
    public function run(array $inside, ?string $input = null, ?string $cwd = null): array;

    /**
     * What a child `pig` is told to reach the same place with (`bin/pig` reads it back with
     * `Shells::fromEnvironment()`).
     *
     * @return array<string, string>
     */
    public function environment(): array;
}
