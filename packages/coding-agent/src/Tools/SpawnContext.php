<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Tools;

/**
 * What a shell tool is about to run, where, and with which environment — upstream's
 * `BashSpawnContext` (`PowerShellSpawnContext` is the same type).
 *
 * A `spawnHook` is handed one and answers one: rewrite the command, move it to another directory,
 * add or take away variables. The session's `PIG_*` variables are already in `$env` when the hook
 * sees it, which is upstream's order — "Injection happens before `spawnHook`".
 */
final readonly class SpawnContext
{
    /** @param array<string, string> $env the whole environment the command gets, not additions to it */
    public function __construct(
        public string $command,
        public string $cwd,
        public array $env,
    ) {
    }

    /** @param array<string, string> $env */
    public function withEnv(array $env): self
    {
        return new self($this->command, $this->cwd, $env);
    }

    public function withCommand(string $command): self
    {
        return new self($command, $this->cwd, $this->env);
    }

    public function withCwd(string $cwd): self
    {
        return new self($this->command, $cwd, $this->env);
    }
}
