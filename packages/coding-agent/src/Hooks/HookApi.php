<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Hooks;

use Closure;
use InvalidArgumentException;
use Pig\Ai\ImageContent;
use Pig\Ai\TextContent;
use Pig\CodingAgent\CustomTools\RenderOptions;
use Pig\CodingAgent\Session\HookMessage;
use Pig\CodingAgent\Theme\Theme;
use Pig\Tui\Process;

/**
 * What a hook file is handed, and the whole of what it may do.
 *
 * A hook is a PHP file that returns a callable; the callable is given one of these and
 * registers what it wants:
 *
 * ```php
 * <?php // ~/.pig/agent/hooks/no-force-push.php
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
 * `on()` overloads exist to give each event its own handler type. PHP has no overloads,
 * so the names live in `EVENTS` and an unknown one is a loading error rather than a
 * subscription that silently never fires — which is what a typo costs upstream.
 */
class HookApi
{
    /** Every event a hook may subscribe to. */
    public const array EVENTS = [
        'session_start',
        'session_before_switch',
        'session_before_fork',
        'session_before_compact',
        'session_compact',
        'session_before_tree',
        'session_tree',
        'session_shutdown',
        'session_info_changed',
        'context',
        'context_with_system',
        'before_agent_start',
        'agent_start',
        'agent_end',
        'agent_settled',
        'turn_start',
        'turn_end',
        'message_start',
        'message_update',
        'message_end',
        'tool_call',
        'tool_result',
        'before_provider_request',
        'before_provider_headers',
        'after_provider_response',
        'provider_stream_event',
        'model_select',
        'thinking_level_select',
        'tool_execution_start',
        'tool_execution_update',
        'tool_execution_end',
        'session_compact_failed',
        'user_bash',
        'input',
        'ui_prompt_start',
        'ui_prompt_end',
        'mcp_servers_change',
        'project_trust',
        'resources_discover',
        'agent_before_settle',
    ];

    /** How long a command a hook runs may take before it is killed. */
    private const float EXEC_TIMEOUT = 30.0;

    /** @var array<string, list<Closure>> */
    private array $handlers = [];

    /** @var array<string, RegisteredCommand> */
    private array $commands = [];

    /** @var array<string, Closure> one renderer per custom message type */
    private array $renderers = [];

    /** @var array<string, Closure> one renderer per custom entry type */
    private array $entryRenderers = [];

    /** Handed in once the session exists; null while the hook file is being read. */
    private ?Closure $send = null;

    private ?Closure $note = null;

    private ?Closure $setSessionName = null;

    private ?Closure $getSessionName = null;

    /** @var Closure(): HookContext|null */
    private ?Closure $context = null;

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
     * theme, and returns a `Pig\Tui\Component` — or null for "draw it normally", which
     * is not a failure. Same shape as a custom tool's `renderResult`, and the same reason:
     * a message whose content is a table should not be squeezed through formatting meant
     * for prose.
     *
     * One per type, last registration wins, because two renderers for one message is a
     * question with no answer.
     *
     * @param callable(HookMessage, RenderOptions, Theme): mixed $renderer
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
     * Draw this hook's custom session entries in the transcript.
     * Upstream's `registerEntryRenderer`. Custom entries do not participate in LLM context.
     *
     * @param callable(CustomEntry, RenderOptions, Theme): mixed $renderer
     */
    public function registerEntryRenderer(string $customType, callable $renderer): void
    {
        $this->entryRenderers[self::customType($customType)] = Closure::fromCallable($renderer);
    }

    /** @return array<string, Closure> */
    public function entryRenderers(): array
    {
        return $this->entryRenderers;
    }

    /**
     * Where to read the session from, once there is one.
     *
     * The provider rather than a `HookContext`, because the runner builds one fresh per emit:
     * what `exec()` reads off it has to be the turn running now.
     *
     * @param Closure(): HookContext $context
     * @internal called by `HookRunner::initialize()`
     */
    /** The working directory the hook was loaded for. */
    public function cwd(): string
    {
        return $this->cwd;
    }

    /** The file this hook or extension was loaded from, which is what a registration is owned by. */
    public function path(): string
    {
        return $this->path;
    }

    public function withContext(Closure $context): void
    {
        $this->context = $context;
    }

    /** The context as it stands now, or null before a mode has wired one. For subclasses. */
    protected function contextNow(): ?HookContext
    {
        return $this->context === null ? null : ($this->context)();
    }

    /** The friendly session name, or null if unset. */
    public function getSessionName(): ?string
    {
        if ($this->getSessionName !== null) {
            return ($this->getSessionName)();
        }

        return $this->context === null ? null : ($this->context)()->sessionName();
    }

    /** Set or update the session display name. */
    public function setSessionName(string $name): void
    {
        if ($this->setSessionName !== null) {
            ($this->setSessionName)($name);

            return;
        }

        if ($this->context !== null) {
            ($this->context)()->setSessionName($name);
        }
    }

    /**
     * Wire the writers, once there is a session to write to.
     *
     * Either on its own: they are two independent facts, and one missing must not take the
     * other with it. Whichever is not given stays null, which is what `sendMessage()` and
     * `appendEntry()` already have an answer for.
     *
     * @param Closure(HookMessage, bool): void|null $send
     * @param Closure(string, mixed): void|null     $note
     * @internal called by `HookRunner::initialize()`
     */
    public function writesTo(?Closure $send, ?Closure $note): void
    {
        $this->send = $send ?? $this->send;
        $this->note = $note ?? $this->note;
    }

    public function bindSessionNames(?Closure $setSessionName, ?Closure $getSessionName): void
    {
        $this->setSessionName = $setSessionName ?? $this->setSessionName;
        $this->getSessionName = $getSessionName ?? $this->getSessionName;
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

        if ($name === '' || preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]*$/', $name) !== 1) {
            throw new InvalidArgumentException(
                "'{$name}' cannot be a command name: letters, digits, dots, dashes and underscores only.",
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
     * Three differences from upstream's `execCommand`, and the third is the one to know:
     *
     * - **There is a timeout by default.** Upstream's is opt-in, so a hook that forgets it
     *   parks the turn for as long as its command wants. Thirty seconds is long enough for a
     *   test run and short enough to be survivable.
     * - **`killed` is `ExecResult::stopped()`**, which is upstream's field under pig's
     *   `Process::STOPPED`. It is wider by one case and says so on itself: a program that is
     *   not on this machine answers the same way as one that ran too long, where upstream
     *   reports that as `code: 1, killed: false`.
     * - **Escape stops it**, and the screen stays alive while it runs: the command goes on the
     *   loop (`Process::runAsync()`) and parks on the turn's own `AbortSignal`, so it comes
     *   back as `stopped()` the moment somebody interrupts. **Upstream cannot do this** —
     *   its `ExecOptions` has a `signal` field and its `HookContext` has no signal to put in
     *   it, so a hook there can only be waited out. The signal is not a parameter here for the
     *   same reason: one a hook had to remember to pass is one whose author did not. A handler
     *   that waits on something of its own reads `$ctx->signal` and parks on that.
     *
     * @param list<string> $command the program and its arguments, unquoted
     * @param string|null  $cwd     defaults to the directory pig is working in
     */
    public function exec(array $command, ?string $cwd = null, float $timeout = self::EXEC_TIMEOUT): ExecResult
    {
        [$exit, $stdout, $stderr] = Process::runAsync(
            $command,
            $timeout,
            $cwd ?? $this->cwd,
            // The turn's signal, without the hook having to pass it: escape means stop, and a
            // hook that had to remember to opt in is a hook whose author did not.
            $this->context === null ? null : ($this->context)()->signal,
        );

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
