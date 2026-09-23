<?php

declare(strict_types=1);

namespace Pig\CodingAgent\CustomTools;

use Pig\CodingAgent\Hooks\ExecResult;
use Pig\CodingAgent\Hooks\HookUi;
use Pig\CodingAgent\Hooks\NoUi;
use Pig\Tui\Process;

/**
 * What a tool file's factory is handed.
 *
 * Deliberately thin, and deliberately not the hook API: a custom tool that called
 * `$pi->on('tool_call', …)` would be registering a handler nothing fires, and a factory
 * that can only do what it is for cannot make that mistake. The factory runs once at
 * startup, so this is the place for setup — reading a config file, finding a binary — and
 * whatever it closes over is what the tool has at execute time.
 *
 * The session is *not* here for the same reason it is not in upstream's `CustomToolAPI`:
 * at load time there is no session yet. It arrives as the `HookContext` passed to
 * `execute`, which is built fresh per call — and carries `ui` too, so a tool that wants to
 * ask the person something has it in both places.
 *
 * `ui` here is the one upstream has: it is set once the mode is running rather than at
 * load, so a factory that grabs it keeps something that works later rather than a no-op
 * captured too early. Before that it answers as `NoUi` does.
 */
final class CustomToolApi
{
    /** How long a command a tool runs at load time may take. */
    private const float EXEC_TIMEOUT = 30.0;

    private ?HookUi $ui = null;

    public function __construct(public readonly string $cwd = '.')
    {
    }

    /**
     * Hand the tools the real UI, once a mode has one.
     *
     * Upstream's `setUIContext` on the load result, in the place the shared API actually
     * lives. The object is shared by every tool loaded in this session, which is what
     * makes one call enough.
     */
    public function withUi(HookUi $ui): void
    {
        $this->ui = $ui;
    }

    /** Asking the person something. `NoUi`'s answers until a mode says otherwise. */
    public function ui(): HookUi
    {
        return $this->ui ?? new NoUi();
    }

    /** Whether asking will reach anybody. */
    public function hasUi(): bool
    {
        return $this->ui !== null;
    }

    /**
     * Run a command and wait for it.
     *
     * The argv array and the timeout are why this is here rather than left to
     * `shell_exec`: nothing needs quoting, and a command that hangs does not hang pig.
     *
     * @param list<string> $command the program and its arguments, unquoted
     * @param string|null  $cwd     defaults to the directory pig is working in
     */
    public function exec(array $command, ?string $cwd = null, float $timeout = self::EXEC_TIMEOUT): ExecResult
    {
        [$exit, $stdout, $stderr] = Process::run($command, $timeout, $cwd ?? $this->cwd);

        return new ExecResult($exit, $stdout, $stderr);
    }
}
