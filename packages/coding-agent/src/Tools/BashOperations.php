<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Tools;

use Closure;
use Pig\Agent\AgentError;
use Pig\Async\AbortSignal;

/**
 * Upstream's `BashOperations` (`tools/bash.ts`): where `bash` and `powershell` run a command, so a
 * host can run it somewhere other than this machine — over SSH, in a container. `LocalShellOperations`
 * is this machine's shell, the default. `Run` keeps what the command prints; this only runs it.
 *
 * Two differences from upstream's shape, both pig's:
 * - **Synchronous**, and the timeout in seconds: a tool runs in a fiber, and an implementation that
 *   waits suspends it rather than returning a promise.
 * - **`$onData` is told which stream a chunk came from** (1 stdout, 2 stderr), so an escape
 *   sequence or a UTF-8 character one read cut short is completed by the next read *of the same
 *   stream* rather than by whatever the other printed in between. An implementation with one stream
 *   passes 1.
 */
interface BashOperations
{
    /**
     * Run `$command` in `$cwd` and hand each piece of its output to `$onData` as it arrives.
     *
     * @param Closure(string, int): void $onData a chunk of output, and the stream it came from
     * @param float|null $timeout seconds before the command is stopped; null for none
     * @param array<string, string>|null $env the command's whole environment; null inherits this process's
     * @return int|null the exit code, signal terminations as 128 + the signal; null when there is
     *         none to report, which counts as a failed command
     * @throws CommandStopped when `$signal` aborted it or `$timeout` ran out — it was stopped, with
     *         everything it started
     * @throws AgentError when it could not be started
     */
    public function exec(string $command, string $cwd, Closure $onData, ?AbortSignal $signal = null, ?float $timeout = null, ?array $env = null): ?int;
}
