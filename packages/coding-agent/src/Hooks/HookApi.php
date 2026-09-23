<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Hooks;

use Closure;
use InvalidArgumentException;
use Pig\Ai\ImageContent;
use Pig\Ai\TextContent;
use Pig\CodingAgent\CustomTools\RenderOptions;
use Pig\CodingAgent\Session\HookMessage;
use Pig\CodingAgent\Theme\Palette;
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
 * Nothing of upstream's `HookAPI` is left out.
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

    /** @var array<string, Closure> one renderer per custom message type */
    private array $renderers = [];

    /** Handed in once the session exists; null while the hook file is being read. */
    private ?Closure $send = null;

    private ?Closure $note = null;

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
     * Put something in the conversation, from the hook.
     *
     * The model sees it, as a user message: this is for telling it something it had no way
     * to find out — a build that just failed, a file that changed underneath it, a rule
     * about this repository. `display` decides whether a person sees it too, and `details`
     * is the hook's own metadata, kept in the session file and never sent to the model.
     *
     * `$triggerTurn` starts a turn if the agent is idle. While it is working the message is
     * queued as a follow-up instead and the flag is ignored — an extra message between a
     * tool call and its result is a request every provider rejects.
     *
     * @param string|list<TextContent|ImageContent> $content
     * @throws InvalidArgumentException when the type is unusable
     */
    public function sendMessage(
        string $customType,
        string|array $content,
        bool $display = true,
        mixed $details = null,
        bool $triggerTurn = false,
    ): void {
        $message = new HookMessage(
            self::customType($customType),
            is_string($content) ? [new TextContent($content)] : array_values($content),
            $display,
            $details,
        );

        // Before the session exists there is nothing to send to: a hook file is read at
        // startup, and its factory runs before a mode has wired anything up. Saying so
        // beats a message that silently goes nowhere.
        if ($this->send === null) {
            throw new InvalidArgumentException(
                'sendMessage() needs a session — call it from a handler, not while the hook file is being read.',
            );
        }

        ($this->send)($message, $triggerTurn);
    }

    /**
     * Write something down that the model will never see.
     *
     * For hook state that should survive a restart: "this session was granted full
     * permissions". It is in the session file and not in the conversation, so it costs no
     * context — the opposite trade from `sendMessage()`. Read it back on the next
     * `session_start` from `$ctx->store?->customEntries('your-type')`.
     *
     * @throws InvalidArgumentException when the type is unusable
     */
    public function appendEntry(string $customType, mixed $data = null): void
    {
        $type = self::customType($customType);

        if ($this->note === null) {
            throw new InvalidArgumentException(
                'appendEntry() needs a session — call it from a handler, not while the hook file is being read.',
            );
        }

        ($this->note)($type, $data);
    }

    /**
     * Draw this hook's own messages your own way.
     *
     * The renderer is handed the message, whether the transcript is expanded, and the
     * palette, and returns a `Pig\Tui\Component` — or null for "draw it normally", which
     * is not a failure. Same shape as a custom tool's `renderResult`, and the same reason:
     * a message whose content is a table should not be squeezed through formatting meant
     * for prose.
     *
     * One per type, last registration wins, because two renderers for one message is a
     * question with no answer.
     *
     * @param callable(HookMessage, RenderOptions, Palette): mixed $renderer
     */
    public function registerMessageRenderer(string $customType, callable $renderer): void
    {
        $this->renderers[self::customType($customType)] = Closure::fromCallable($renderer);
    }

    /** @return array<string, Closure> */
    public function renderers(): array
    {
        return $this->renderers;
    }

    /**
     * Wire the two writers, once there is a session to write to.
     *
     * @param Closure(HookMessage, bool): void $send
     * @param Closure(string, mixed): void     $note
     * @internal called by `HookRunner::initialize()`
     */
    public function writesTo(Closure $send, Closure $note): void
    {
        $this->send = $send;
        $this->note = $note;
    }

    /**
     * A usable name for a hook's own kind of message.
     *
     * The same rule as a command's, because it is used the same way — a hook filters its own
     * messages out of a resumed session by matching on it, and a type with a quote or a
     * newline in it is one nobody can match reliably.
     */
    private static function customType(string $customType): string
    {
        if (preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_-]*$/', $customType) !== 1) {
            throw new InvalidArgumentException(
                "'{$customType}' cannot be a custom type: letters, digits, dashes and underscores only.",
            );
        }

        return $customType;
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
