<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Hooks;

use Closure;
use InvalidArgumentException;
use Pig\Tui\Process;

/**
 * What a hook file is handed, and the whole of what it may do.
 *
 * A hook is a PHP file that returns a callable; the callable is given one of these and
 * registers what it wants:
 *
 * ```php
 * <?php // ~/.pig/hooks/no-force-push.php
 *
 * use Pig\CodingAgent\Hooks\HookApi;
 * use Pig\CodingAgent\Hooks\Results\ToolCallEventResult;
 *
 * return function (HookApi $pi): void {
 *     $pi->on('tool_call', function ($event) {
 *         if ($event->toolName === 'bash' && str_contains($event->input['command'] ?? '', '--force')) {
 *             return new ToolCallEventResult(block: true, reason: 'No force pushes from here.');
 *         }
 *
 *         return null;
 *     });
 * };
 * ```
 *
 * Ported from the `HookAPI` interface in upstream's `core/hooks/types.ts`, whose
 * eighteen `on()` overloads exist to give each event its own handler type. PHP has no
 * overloads, so the names live in `EVENTS` and an unknown one is a loading error rather
 * than a subscription that silently never fires — which is what a typo costs upstream.
 *
 * Not ported: `sendMessage()`, `appendEntry()` and `registerMessageRenderer()`, all three
 * of which need custom message types the session can store and the UI can draw. See
 * CLAUDE.md.
 */
final class HookApi
{
    /** Every event a hook may subscribe to. */
    public const array EVENTS = [
        'session_start',
        'session_before_switch',
        'session_switch',
        'session_before_compact',
        'session_compact',
        'session_before_tree',
        'session_tree',
        'session_shutdown',
        'context',
        'before_agent_start',
        'agent_start',
        'agent_end',
        'turn_start',
        'turn_end',
        'tool_call',
        'tool_result',
    ];

    /**
     * Upstream events pig does not fire, and why, so subscribing to one says so.
     *
     * `session_before_branch` and `session_branch` are about forking a conversation into
     * a second session file. pig branches inside one file instead — `/tree` — which is
     * `session_before_tree` and `session_tree`.
     */
    private const array UNPORTED = [
        'session_before_branch' => 'pig branches inside one session file; use session_before_tree',
        'session_branch' => 'pig branches inside one session file; use session_tree',
    ];

    /** How long a command a hook runs may take before it is killed. */
    private const float EXEC_TIMEOUT = 30.0;

    /** @var array<string, list<Closure>> */
    private array $handlers = [];

    /** @var array<string, RegisteredCommand> */
    private array $commands = [];

    public function __construct(
        private readonly string $cwd = '.',
        private readonly string $path = '',
    ) {
    }

    /**
     * Run $handler whenever $event happens.
     *
     * Several handlers for one event run in the order they were registered, and several
     * hooks run in the order they were loaded.
     *
     * @param callable(mixed, HookContext): mixed $handler returns a result object, or null
     * @throws InvalidArgumentException when there is no such event
     */
    public function on(string $event, callable $handler): void
    {
        if (isset(self::UNPORTED[$event])) {
            throw new InvalidArgumentException("No event called '{$event}': " . self::UNPORTED[$event]);
        }

        if (!in_array($event, self::EVENTS, true)) {
            throw new InvalidArgumentException(
                "No event called '{$event}'. There is: " . implode(', ', self::EVENTS),
            );
        }

        $this->handlers[$event][] = Closure::fromCallable($handler);
    }

    /**
     * Add a slash command.
     *
     * A built-in of the same name wins, so a hook cannot take `/compact` away from the
     * person using it.
     *
     * @param callable(string, HookContext): void $handler
     * @throws InvalidArgumentException when the name is unusable or already taken
     */
    public function registerCommand(string $name, callable $handler, string $description = ''): void
    {
        $name = ltrim($name, '/');

        if ($name === '' || preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_-]*$/', $name) !== 1) {
            throw new InvalidArgumentException(
                "'{$name}' cannot be a command name: letters, digits, dashes and underscores only.",
            );
        }

        if (isset($this->commands[$name])) {
            throw new InvalidArgumentException("This hook already registered /{$name}.");
        }

        $this->commands[$name] = new RegisteredCommand(
            $name,
            $description,
            Closure::fromCallable($handler),
            $this->path,
        );
    }

    /**
     * Run a command and wait for it.
     *
     * Here because the timeout and the argv array are worth having by default: a hook
     * that reaches for `shell_exec` gets neither, and a hook that hangs hangs pig.
     *
     * @param list<string> $command the program and its arguments, unquoted
     * @param string|null  $cwd     defaults to the directory pig is working in
     */
    public function exec(array $command, ?string $cwd = null, float $timeout = self::EXEC_TIMEOUT): ExecResult
    {
        [$exit, $stdout, $stderr] = Process::run($command, $timeout, $cwd ?? $this->cwd);

        return new ExecResult($exit, $stdout, $stderr);
    }

    /** @return list<Closure> */
    public function handlers(string $event): array
    {
        return $this->handlers[$event] ?? [];
    }

    /** @return array<string, RegisteredCommand> */
    public function commands(): array
    {
        return $this->commands;
    }
}
