<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Tools;

use Closure;
use Pig\Agent\AgentError;
use Pig\Async\AbortSignal;

/**
 * Upstream's `createLocalShellOperations(shellName, resolveShellConfig)`: a command run by this
 * machine's shell, on the event loop, with a watcher per pipe — the process half of what `Run` did
 * by itself before `BashOperations` existed, moved here unchanged.
 *
 * One instance per tool; each `exec()` is a run of its own.
 */
final readonly class LocalShellOperations implements BashOperations
{
    /**
     * @param string $shellName upstream's `shellName`, `bash` or `PowerShell`, in the error a
     *        missing working directory gets
     * @param Closure(): ShellConfig $resolveShellConfig the shell to run with, asked as each command
     *        starts, because the setting can change in between
     */
    public function __construct(private string $shellName, private Closure $resolveShellConfig)
    {
    }

    /** The bash `bash` runs: `Shell::config()`, or `$shellPath` instead (upstream's `createLocalBashOperations()`). */
    public static function bash(?string $shellPath = null): self
    {
        return new self('bash', static fn (): ShellConfig => Shell::config($shellPath));
    }

    #[\Override]
    public function exec(string $command, string $cwd, Closure $onData, ?AbortSignal $signal = null, ?float $timeout = null, ?array $env = null): ?int
    {
        // Upstream's check, before anything is started.
        if (!is_dir($cwd)) {
            throw new AgentError("Working directory does not exist: {$cwd}\nCannot execute {$this->shellName} commands.");
        }

        $state = new LocalShellRun($onData);
        $state->start($command, $cwd, ($this->resolveShellConfig)(), $env);

        $exit = $state->wait($signal, $timeout);

        if ($state->aborted || $state->timedOut) {
            throw new CommandStopped($state->timedOut, $exit);
        }

        return $exit;
    }
}
