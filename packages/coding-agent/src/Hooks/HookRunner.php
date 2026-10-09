<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Hooks;

use Closure;
use Pig\Ai\ImageContent;
use Pig\Async\AbortSignal;
use Pig\Async\Async;
use Pig\Ai\Model;
use Pig\Ai\SystemMessage;
use Pig\Ai\Utils\Transcript;
use Pig\CodingAgent\Hooks\Events\BeforeAgentStartEvent;
use Pig\CodingAgent\Hooks\Events\ContextEvent;
use Pig\CodingAgent\Hooks\Events\McpServersChangeEvent;
use Pig\CodingAgent\Hooks\Events\ContextWithSystemEvent;
use Pig\CodingAgent\Hooks\Events\BeforeProviderRequestEvent;
use Pig\CodingAgent\Hooks\Events\BeforeProviderHeadersEvent;
use Pig\CodingAgent\Hooks\Events\SessionBeforeCompactEvent;
use Pig\CodingAgent\Hooks\Boundary\BoundaryContextPreview;
use Pig\CodingAgent\Hooks\Boundary\BoundaryDispatch;
use Pig\CodingAgent\Hooks\Boundary\SessionBoundaryDraft;
use Pig\CodingAgent\Hooks\Events\AgentBeforeSettleEvent;
use Pig\CodingAgent\Hooks\Events\ProjectTrustEvent;
use Pig\CodingAgent\Hooks\Events\ResourcesDiscoverEvent;
use Pig\CodingAgent\Hooks\Events\SessionBeforeSwitchEvent;
use Pig\CodingAgent\Hooks\Events\SessionBeforeTreeEvent;
use Pig\CodingAgent\Hooks\Events\ToolCallEvent;
use Pig\CodingAgent\Hooks\Events\ToolResultEvent;
use Pig\CodingAgent\Hooks\Events\InputEvent;
use Pig\CodingAgent\McpServerRegistry;
use Pig\CodingAgent\Hooks\Events\UserBashEvent;
use Pig\CodingAgent\Hooks\Results\InputEventResult;
use Pig\CodingAgent\Hooks\Results\UserBashEventResult;
use Pig\CodingAgent\Hooks\Results\BeforeAgentStartEventResult;
use Pig\CodingAgent\Hooks\Results\ContextEventResult;
use Pig\CodingAgent\Hooks\Results\BeforeProviderRequestResult;
use Pig\CodingAgent\Hooks\Results\SessionBeforeCompactResult;
use Pig\CodingAgent\Hooks\Results\BoundaryResult;
use Pig\CodingAgent\Hooks\Results\ProjectTrustEventResult;
use Pig\CodingAgent\Hooks\Results\ResourcesDiscoverResult;
use Pig\CodingAgent\Hooks\Results\SessionBeforeSwitchResult;
use Pig\CodingAgent\Hooks\Results\SessionBeforeTreeResult;
use Pig\CodingAgent\Hooks\Results\ToolCallEventResult;
use Pig\CodingAgent\Hooks\Results\ToolResultEventResult;
use Pig\CodingAgent\Prompt\SystemPromptOptions;
use Pig\CodingAgent\Session\HookMessage;
use Pig\CodingAgent\Session\SessionManager;
use Pig\CodingAgent\Tools\Paths;
use Throwable;

/**
 * Firing events at the hooks that asked for them.
 *
 * Upstream has one `emit()` returning a union of every result type, plus three special
 * cases beside it. Here each event that can answer back has its own method with its own
 * return type, because a union is what TypeScript has instead of that — and a caller
 * that has to cast the result of `emit()` to know what it got is one rename away from
 * casting it to the wrong thing.
 *
 * **What happens when a handler throws** depends on what the event is for:
 *
 * - Something that only reports — `agent_end`, `tool_result`, `session_compact` — is
 *   caught and reported. A hook that watches is not allowed to stop the thing it watches.
 * - `tool_call` is not caught here. A hook that was asked whether a tool may run and
 *   answered with an exception has not said yes, and the call is blocked. `HookedTool`
 *   is where that is turned into an error the model reads.
 *
 * A handler that returns the wrong kind of object is reported and ignored: silently
 * treating it as "no opinion" would turn a typo into a hook that appears to work.
 */
final class HookRunner
{
    /** @var list<LoadedHook> */
    private array $hooks;

    /** @var array<int, Closure(HookError): void> */
    private array $listeners = [];

    private int $nextListenerId = 0;

    private ?Closure $getModel = null;

    private ?Closure $isIdle = null;

    private ?Closure $abort = null;

    private ?Closure $hasPendingMessages = null;

    private ?Closure $getSignal = null;

    private ?Closure $getApiKey = null;

    private ?\Pig\CodingAgent\Session\AgentSession $session = null;

    private ?HookUi $ui = null;

    /** @var array<string, true> servers already reported as connected by nobody */
    private array $reportedMcpServers = [];

    private readonly HookState $state;

    /** @param list<LoadedHook> $hooks in the order they were loaded, which is the order they run */
    public function __construct(
        array $hooks = [],
        private readonly string $cwd = '.',
        private ?SessionManager $store = null,
    ) {
        $this->hooks = $hooks;
        $this->state = new HookState();
    }

    public function setStore(?SessionManager $store): void
    {
        $this->store = $store;
    }

    public function setSession(?\Pig\CodingAgent\Session\AgentSession $session): void
    {
        $this->session = $session;
    }

    /**
     * Tell the runner how to answer the questions a handler's context can ask.
     *
     * Separate from the constructor because the session does not exist yet when the
     * hooks are loaded: the loader runs before `bin/pig` has a model or an agent, and a
     * hook that asks for the model at startup would be told there is none for the rest
     * of the session if these were captured values rather than closures.
     *
     * @param Closure(): (Model|null) $getModel
     * @param Closure(): bool|null    $isIdle
     * @param Closure(): void|null    $abort
     * @param Closure(): bool|null    $hasPendingMessages
     * @param Closure(): (AbortSignal|null)|null $signal the turn in progress's
     * @param HookUi|null                     $ui   the terminal's, when there is one
     * @param Closure(HookMessage, bool): void|null $send `$pi->sendMessage()`
     * @param Closure(string, mixed): void|null     $note `$pi->appendEntry()`
     */
    public function initialize(
        Closure $getModel,
        ?Closure $isIdle = null,
        ?Closure $abort = null,
        ?Closure $hasPendingMessages = null,
        ?Closure $signal = null,
        ?HookUi $ui = null,
        ?Closure $send = null,
        ?Closure $note = null,
        ?Closure $getApiKey = null,
        ?Closure $setSessionName = null,
        ?Closure $getSessionName = null,
    ): void {
        $this->getModel = $getModel;
        $this->isIdle = $isIdle;
        $this->abort = $abort;
        $this->hasPendingMessages = $hasPendingMessages;
        $this->getSignal = $signal;
        $this->getApiKey = $getApiKey;

        // Servers registered from now on reach the extension that connects them right away; the
        // ones registered during loading are read with `getMcpServers()` on `session_start`.
        // On the loop rather than inside the registering call, as upstream's `void this.emit()`
        // is: a handler that connects a server awaits, and a factory registering one at load time
        // is not in a fiber.
        $registry = McpServerRegistry::current();
        $registry->setChangeListener(function () use ($registry): void {
            $servers = $registry->list();
            Async::spawn(function () use ($servers): void {
                $this->emit(new McpServersChangeEvent($servers));
                $this->reportUnhandledMcpServers();
            });
        });

        // `NoUi` answers without asking anybody, so there is no prompt to report around it.
        $this->ui = $ui === null || $ui instanceof NoUi ? $ui : new PromptingUi($ui, $this->emit(...));

        // Handed to each hook's own API object rather than kept here, because that is the
        // object the hook closed over — `$pi->sendMessage()` inside a handler is a call on
        // the thing the factory was given at load time, long before a session existed.
        //
        // One at a time, as upstream wires them: `$send !== null && $note !== null` was one
        // condition for two independent facts, so a mode that offered only one got neither —
        // and what a hook would then read is `sendMessage() needs a session — call it from a
        // handler`, which is an explanation of something that did not happen.
        foreach ($this->hooks as $hook) {
            $hook->api->writesTo($send, $note);
            $hook->api->bindSessionNames($setSessionName, $getSessionName);
            // The context rather than the signal itself: it is built fresh per emit, so what
            // `$pi->exec()` reads off it is the signal for the turn running *now* rather than
            // whichever one existed when the hooks were wired.
            $hook->api->withContext($this->context(...));
        }
    }

    /**
     * Every renderer the hooks registered, by the type it draws.
     *
     * Later hooks win a clash, which is the same rule `registerMessageRenderer()` applies
     * within one hook — and unlike a command clash it is not worth complaining about: a
     * renderer only ever draws its own hook's messages, so two hooks claiming one type
     * means one of them is drawing messages it did not send and would not recognise.
     *
     * @return array<string, Closure>
     */
    public function renderers(): array
    {
        $renderers = [];

        foreach ($this->hooks as $hook) {
            foreach ($hook->api->renderers() as $type => $renderer) {
                $renderers[$type] = $renderer;
            }
        }

        return $renderers;
    }

    /** @return array<string, Closure> */
    public function entryRenderers(): array
    {
        $renderers = [];

        foreach ($this->hooks as $hook) {
            foreach ($hook->api->entryRenderers() as $type => $renderer) {
                $renderers[$type] = $renderer;
            }
        }

        return $renderers;
    }

    /** @return list<Closure(string, array{role: string, isStreaming: bool}): string> */
    public function markdownTransformers(): array
    {
        $transformers = [];

        foreach ($this->hooks as $hook) {
            if ($hook->api instanceof \Pig\CodingAgent\Extensions\ExtensionApi) {
                foreach ($hook->api->markdownTransformers() as $t) {
                    $transformers[] = $t;
                }
            }
        }

        return $transformers;
    }

    /** @return array<string, array{key: string, handler: Closure, description: string, hookPath: string}> */
    public function shortcuts(): array
    {
        $shortcuts = [];

        foreach ($this->hooks as $hook) {
            if ($hook->api instanceof \Pig\CodingAgent\Extensions\ExtensionApi) {
                foreach ($hook->api->shortcuts() as $key => $spec) {
                    $shortcuts[$key] = [...$spec, 'hookPath' => $hook->path];
                }
            }
        }

        return $shortcuts;
    }

    /**
     * Resolve custom tool renderers through the registered resolvers onion chain.
     * Upstream's `resolveToolRenderers()`.
     *
     * @return array{renderCall?: Closure, renderResult?: Closure}|null
     */
    public function resolveToolRenderers(string $toolName, ?Closure $base = null): ?array
    {
        $resolvers = [];

        foreach ($this->hooks as $hook) {
            if ($hook->api instanceof \Pig\CodingAgent\Extensions\ExtensionApi) {
                foreach ($hook->api->toolRenderers() as $r) {
                    $resolvers[] = $r;
                }
            }
        }

        if ($resolvers === []) {
            return $base !== null ? $base() : null;
        }

        $chain = static function (int $index) use (&$chain, $resolvers, $toolName, $base): ?array {
            if (!isset($resolvers[$index])) {
                return $base !== null ? $base() : null;
            }

            $resolver = $resolvers[$index];

            return $resolver($toolName, static fn (): ?array => $chain($index + 1));
        };

        return $chain(0);
    }

    /** Where every hook that loaded came from. @return list<string> */
    public function paths(): array
    {
        return array_map(static fn (LoadedHook $hook): string => $hook->path, $this->hooks);
    }

    public function isEmpty(): bool
    {
        return $this->hooks === [];
    }

    /** Whether firing this event would reach anyone. */
    /**
     * Report registered MCP servers when no hook handles `mcp_servers_change`, which means nothing
     * connects them (for example when another MCP extension replaced the built-in one).
     */
    public function reportUnhandledMcpServers(): void
    {
        if ($this->hasHandlers('mcp_servers_change')) {
            return;
        }

        foreach (McpServerRegistry::current()->list() as $server) {
            if (isset($this->reportedMcpServers[$server->name])) {
                continue;
            }

            $this->reportedMcpServers[$server->name] = true;
            $this->emitError(new HookError(
                $server->extensionPath,
                'register_mcp_server',
                "MCP server \"{$server->name}\" is registered, but no loaded extension connects MCP servers; another extension may have replaced the built-in MCP support",
            ));
        }
    }

    public function hasHandlers(string $event): bool
    {
        foreach ($this->hooks as $hook) {
            if ($hook->api->handlers($event) !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every slash command the hooks added, by name.
     *
     * First one wins, so two hooks registering `/deploy` leaves the earlier one working
     * and the later one reported — rather than the two silently taking turns.
     *
     * @return array{0: array<string, RegisteredCommand>, 1: list<HookError>}
     */
    public function commands(): array
    {
        $commands = [];
        $clashes = [];

        foreach ($this->hooks as $hook) {
            foreach ($hook->api->commands() as $name => $command) {
                if (isset($commands[$name])) {
                    $clashes[] = new HookError(
                        $hook->path,
                        'register_command',
                        "/{$name} was already registered by {$commands[$name]->hookPath}",
                    );

                    continue;
                }

                $commands[$name] = $command;
            }
        }

        return [$commands, $clashes];
    }

    /**
     * The command that answers to this name, or null.
     *
     * Upstream's `getCommand()`, and it is `commands()` asked one question rather than a second
     * walk over the hooks: the first-one-wins rule has one implementation, so a lookup and a
     * listing cannot come to disagree about which of two `/deploy`s is the live one.
     */
    public function command(string $name): ?RegisteredCommand
    {
        [$commands] = $this->commands();

        return $commands[$name] ?? null;
    }

    /**
     * @param Closure(HookError): void $listener
     * @return Closure(): void call it to stop listening
     */
    public function onError(Closure $listener): Closure
    {
        $id = $this->nextListenerId++;
        $this->listeners[$id] = $listener;

        return function () use ($id): void {
            unset($this->listeners[$id]);
        };
    }

    public function emitError(HookError $error): void
    {
        foreach ($this->listeners as $listener) {
            $listener($error);
        }
    }

    /** The session as a handler sees it, built fresh each time so nothing is stale. */
    public function context(): HookContext
    {
        return new HookContext(
            $this->cwd,
            $this->store,
            $this->getModel === null ? null : ($this->getModel)(),
            $this->isIdle,
            $this->abort,
            $this->hasPendingMessages,
            $this->ui,
            // `NoUi` is not a UI. It answers every question without asking anybody, which is
            // exactly what a hook checking `hasUi` is trying to find out before it asks —
            // `$this->ui !== null` said yes to it, so a hook in `--mode text` was told there
            // was somebody there and then heard nothing back.
            $this->ui !== null && !$this->ui instanceof NoUi,
            $this->getSignal === null ? null : ($this->getSignal)(),
            $this->state,
            $this->getApiKey,
            $this->session,
        );
    }

    /**
     * Fire an event nobody can answer.
     *
     * Every handler runs even if an earlier one threw, because they belong to different
     * hooks and one person's broken hook is not another's.
     */
    public function emit(HookEvent $event): void
    {
        $context = $this->context();

        foreach ($this->hooks as $hook) {
            foreach ($hook->api->handlers($event->type()) as $handler) {
                try {
                    $handler($event, $context);
                } catch (Throwable $error) {
                    $this->fail($hook, $event->type(), $error);
                }
            }
        }
    }

    /**
     * Ask the hooks whether a tool may run.
     *
     * The first block stops the rest: the answer is already no, and running the others
     * would only give them a chance to disagree with it.
     *
     * Throwables are **not** caught — see the class docblock.
     */
    public function emitToolCall(ToolCallEvent $event): ?ToolCallEventResult
    {
        $context = $this->context();

        foreach ($this->hooks as $hook) {
            foreach ($hook->api->handlers('tool_call') as $handler) {
                $result = $handler($event, $context);

                if ($result === null) {
                    continue;
                }

                if (!$result instanceof ToolCallEventResult) {
                    $this->wrongType($hook, 'tool_call', ToolCallEventResult::class, $result);

                    continue;
                }

                if ($result->block) {
                    return $result;
                }
            }
        }

        return null;
    }

    /**
     * Show the hooks what a tool produced, and let them change it.
     *
     * Last one wins, and each sees what the tool produced rather than what the hook
     * before it said — chaining is `context`'s job, and doing it here would mean a hook
     * could not tell the tool's own output from another hook's edit of it.
     */
    public function emitToolResult(ToolResultEvent $event): ?ToolResultEventResult
    {
        $context = $this->context();
        $last = null;

        foreach ($this->hooks as $hook) {
            foreach ($hook->api->handlers('tool_result') as $handler) {
                try {
                    $result = $handler($event, $context);
                } catch (Throwable $error) {
                    $this->fail($hook, 'tool_result', $error);

                    continue;
                }

                if ($result === null) {
                    continue;
                }

                if (!$result instanceof ToolResultEventResult) {
                    $this->wrongType($hook, 'tool_result', ToolResultEventResult::class, $result);

                    continue;
                }

                $last = $result;
            }
        }

        return $last;
    }

    /**
     * Run the conversation past the hooks on its way to the model — upstream's `emitContext()`, in
     * its two phases.
     *
     * The `context` handlers are chained: each is given what the last one returned, so two hooks
     * can both edit the context without either having to know about the other. They see the
     * conversation only; "the system messages belong to Pi", so after each handler they are put
     * back (`restoreSystemMessages()`). Then the `context_with_system` handlers see the whole
     * transcript and their output is used as returned.
     *
     * @param list<mixed> $messages
     * @return list<mixed>
     */
    public function emitContext(array $messages): array
    {
        $context = $this->context();
        $current = $messages;

        foreach ($this->hooks as $hook) {
            foreach ($hook->api->handlers('context') as $handler) {
                $visible = array_values(array_filter($current, static fn (mixed $message): bool => !$message instanceof SystemMessage));

                try {
                    $result = $handler(new ContextEvent($visible), $context);
                } catch (Throwable $error) {
                    $this->fail($hook, 'context', $error);

                    continue;
                }

                if ($result === null) {
                    continue;
                }

                if (!$result instanceof ContextEventResult) {
                    $this->wrongType($hook, 'context', ContextEventResult::class, $result);

                    continue;
                }

                $current = self::restoreSystemMessages($current, $visible, $result->messages);
            }
        }

        foreach ($this->hooks as $hook) {
            foreach ($hook->api->handlers('context_with_system') as $handler) {
                $hadLeadingSystemMessage = ($current[0] ?? null) instanceof SystemMessage;

                try {
                    $result = $handler(new ContextWithSystemEvent($current), $context);
                } catch (Throwable $error) {
                    $this->fail($hook, 'context_with_system', $error);

                    continue;
                }

                if ($result !== null && !$result instanceof ContextEventResult) {
                    $this->wrongType($hook, 'context_with_system', ContextEventResult::class, $result);

                    continue;
                }

                $current = $result?->messages ?? $current;

                // "Providers read the prompt and initial tools from the leading system message.
                // Losing it is never intended; report it but honor the handler's output."
                if ($hadLeadingSystemMessage && !(($current[0] ?? null) instanceof SystemMessage)) {
                    $this->emitError(new HookError(
                        $hook->path,
                        'context_with_system',
                        'Handler removed the leading system message; the request has no prompt or initial tool declarations. Keep it at index 0 or replace a dropped prefix with getCurrentSystemMessage().',
                    ));
                }
            }
        }

        return $current;
    }

    /**
     * Re-attach the prompt and tool state after a `context` handler — upstream's
     * `restoreSystemMessages()`: "An unchanged conversation keeps every system message in place,
     * so models with mid-conversation support keep their cached prefix. A changed one gets the
     * replayed prompt sections and tool declarations as one leading system message, so pruning,
     * windowing, or slicing from a compaction summary cannot drop them."
     *
     * Unchanged is upstream's `sameMessages()`: the same objects in the same order.
     *
     * @param list<mixed> $current
     * @param list<mixed> $visible
     * @param list<mixed> $returned
     * @return list<mixed>
     */
    private static function restoreSystemMessages(array $current, array $visible, array $returned): array
    {
        if (array_values($returned) === $visible) {
            return $current;
        }

        $head = Transcript::getCurrentSystemMessage($current);

        return $head !== null ? [$head, ...array_values($returned)] : array_values($returned);
    }

    /**
     * Offer the hooks a word before the prompt goes out.
     *
     * First note wins. Upstream does the same, and for the same reason: two notes in
     * front of one prompt is a conversation nobody wrote. A `systemPrompt` answer is not a note:
     * every one is applied to the options in turn, so the last handler's is the one sent.
     *
     * @param list<ImageContent>                          $images
     * @param SystemPromptOptions|null                    $systemPromptOptions mutated in place; a fresh one when null
     * @param (Closure(SystemPromptOptions): string)|null $renderSystemPrompt  what `$event->systemPrompt()` renders
     */
    public function emitBeforeAgentStart(string $prompt, array $images = [], ?SystemPromptOptions $systemPromptOptions = null, ?Closure $renderSystemPrompt = null): ?BeforeAgentStartEventResult
    {
        // One options object for every handler, as upstream's `currentOptions`: a section one
        // handler adds is there for the next, and a `systemPrompt` answer becomes
        // `forceSystemPrompt`, which later handlers observe.
        $options = $systemPromptOptions ?? new SystemPromptOptions();
        $render = $renderSystemPrompt === null ? null : static fn (): string => $renderSystemPrompt($options);
        $event = new BeforeAgentStartEvent($prompt, $images, $options, $render);
        $context = $this->context();
        $first = null;

        foreach ($this->hooks as $hook) {
            foreach ($hook->api->handlers('before_agent_start') as $handler) {
                try {
                    $result = $handler($event, $context);
                } catch (Throwable $error) {
                    $this->fail($hook, 'before_agent_start', $error);

                    continue;
                }

                if ($result === null) {
                    continue;
                }

                if (!$result instanceof BeforeAgentStartEventResult) {
                    $this->wrongType($hook, 'before_agent_start', BeforeAgentStartEventResult::class, $result);

                    continue;
                }

                if ($result->systemPrompt !== null) {
                    $options->forceSystemPrompt = $result->systemPrompt;
                }

                if ($result->text !== null) {
                    $first ??= $result;
                }
            }
        }

        return $first;
    }

    /**
     * Upstream's `emitBeforeProviderRequest(payload)`: each handler given the payload the last one
     * left, a non-null answer replacing it. A handler that throws is reported and skipped.
     */
    public function emitBeforeProviderRequest(mixed $payload): mixed
    {
        $context = $this->context();
        $currentPayload = $payload;

        foreach ($this->hooks as $hook) {
            foreach ($hook->api->handlers('before_provider_request') as $handler) {
                try {
                    $result = $handler(new BeforeProviderRequestEvent($currentPayload), $context);
                } catch (Throwable $error) {
                    $this->fail($hook, 'before_provider_request', $error);

                    continue;
                }

                if ($result === null) {
                    continue;
                }

                if (!$result instanceof BeforeProviderRequestResult) {
                    $this->wrongType($hook, 'before_provider_request', BeforeProviderRequestResult::class, $result);

                    continue;
                }

                $currentPayload = $result->payload;
            }
        }

        return $currentPayload;
    }

    /**
     * Upstream's `emitBeforeProviderHeaders(headers)`: one event for every handler, which mutate its
     * `headers` in place; what is left is the answer. A handler that throws is reported and skipped.
     *
     * @param array<string, string|null> $headers
     * @return array<string, string|null>
     */
    public function emitBeforeProviderHeaders(array $headers): array
    {
        $context = $this->context();
        $event = new BeforeProviderHeadersEvent($headers);

        foreach ($this->hooks as $hook) {
            foreach ($hook->api->handlers('before_provider_headers') as $handler) {
                try {
                    $handler($event, $context);
                } catch (Throwable $error) {
                    $this->fail($hook, 'before_provider_headers', $error);
                }
            }
        }

        return $event->headers;
    }

    /**
     * Ask whether a hook will run a typed `!command` itself. Upstream's `emitUserBash()`.
     *
     * The first handler with an answer decides. A handler that **throws** is reported and the
     * throw goes on to the caller, which is upstream's rule and the reason is worth keeping: a
     * hook that exists to send commands somewhere else (a container, a remote machine) and
     * fails must not fall back to running the command here, on the machine the person was
     * keeping it away from.
     */
    public function emitUserBash(UserBashEvent $event): ?UserBashEventResult
    {
        $context = $this->context();

        foreach ($this->hooks as $hook) {
            foreach ($hook->api->handlers('user_bash') as $handler) {
                try {
                    $result = $handler($event, $context);
                } catch (Throwable $error) {
                    $this->fail($hook, 'user_bash', $error);

                    throw $error;
                }

                if ($result === null) {
                    continue;
                }

                if (!$result instanceof UserBashEventResult) {
                    $this->wrongType($hook, 'user_bash', UserBashEventResult::class, $result);

                    continue;
                }

                return $result;
            }
        }

        return null;
    }

    /**
     * Show the hooks what is about to be sent, and let them change it or take it.
     * Upstream's `emitInput()`.
     *
     * Chained, like `context`: each handler is given the text the one before it transformed,
     * because two extensions that each rewrite input — one expanding an abbreviation, one
     * redacting a key — have to compose. `handled` stops the walk and nothing is sent. A
     * handler that throws is reported and skipped; the input goes on as it was.
     *
     * Answers `continue` when nothing changed, `transform` with the final text when something
     * did, and `handled` when a handler took it.
     */
    public function emitInput(InputEvent $event): InputEventResult
    {
        if (!$this->listensTo('input')) {
            return InputEventResult::continue();
        }

        $context = $this->context();
        $text = $event->text;
        $images = $event->images;

        foreach ($this->hooks as $hook) {
            foreach ($hook->api->handlers('input') as $handler) {
                try {
                    $result = $handler(new InputEvent($text, $images, $event->source, $event->streamingBehavior), $context);
                } catch (Throwable $error) {
                    $this->fail($hook, 'input', $error);

                    continue;
                }

                if ($result === null) {
                    continue;
                }

                if (!$result instanceof InputEventResult) {
                    $this->wrongType($hook, 'input', InputEventResult::class, $result);

                    continue;
                }

                if ($result->action === 'handled') {
                    return $result;
                }

                if ($result->action === 'transform') {
                    $text = (string) $result->text;
                    $images = $result->images ?? $images;
                }
            }
        }

        return $text !== $event->text || $images !== $event->images
            ? InputEventResult::transform($text, $images)
            : InputEventResult::continue();
    }

    /** Whether any hook subscribed to this event, so a caller can skip building one for nobody. */
    public function listensTo(string $event): bool
    {
        foreach ($this->hooks as $hook) {
            if ($hook->api->handlers($event) !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * Ask the loaded extensions whether this project may be trusted, before its own `.pig/` is
     * loaded. The first yes or no wins; `undecided` falls through. Upstream's
     * `emitProjectTrustEvent()`, over the extensions loaded before trust — so the runner this is
     * called on is a pre-trust one, built for the question.
     */
    public function emitProjectTrust(ProjectTrustEvent $event): ?ProjectTrustEventResult
    {
        return $this->ask($event, ProjectTrustEventResult::class, static fn (object $r): bool => $r->decided());
    }

    /**
     * Collect the resource directories every `resources_discover` handler adds. Nothing stops
     * the rest: each extension's directories are its own, and a handler that threw is reported
     * and the others still answer. A relative path is resolved against the extension's directory.
     */
    public function emitResourcesDiscover(string $cwd, string $reason): DiscoveredResources
    {
        $event = new ResourcesDiscoverEvent($cwd, $reason);
        $context = $this->context();
        $skills = [];
        $prompts = [];
        $themes = [];

        foreach ($this->hooks as $hook) {
            foreach ($hook->api->handlers($event->type()) as $handler) {
                try {
                    $result = $handler($event, $context);
                } catch (Throwable $error) {
                    $this->fail($hook, $event->type(), $error);

                    continue;
                }

                if ($result === null) {
                    continue;
                }

                if (!$result instanceof ResourcesDiscoverResult) {
                    $this->wrongType($hook, $event->type(), ResourcesDiscoverResult::class, $result);

                    continue;
                }

                $base = dirname($hook->path);
                $found = static fn (string $path): array => ['path' => Paths::resolve($path, $base), 'extensionPath' => $hook->path];
                $skills = [...$skills, ...array_map($found, $result->skillPaths)];
                $prompts = [...$prompts, ...array_map($found, $result->promptPaths)];
                $themes = [...$themes, ...array_map($found, $result->themePaths)];
            }
        }

        return new DiscoveredResources($skills, $prompts, $themes);
    }

    /**
     * Upstream's `emitBoundary()`: every `agent_before_settle` handler in turn, each handed the
     * entries and the `continue` the ones before it settled on and a fresh preview of the context
     * with those entries applied. A handler's answer replaces either field it sets; a throw is
     * reported and leaves them as they were. A preview that cannot be built from the entries is
     * reported too, and the dispatch comes back `valid: false` — nothing of it is then appended.
     *
     * @param Closure(list<SessionBoundaryDraft>, bool, BoundaryContextPreview): AgentBeforeSettleEvent $event
     * @param Closure(list<SessionBoundaryDraft>): BoundaryContextPreview $buildContext
     */
    public function emitBoundary(Closure $event, Closure $buildContext): BoundaryDispatch
    {
        $context = $this->context();
        $entries = [];
        $continue = false;
        $preview = $buildContext($entries);
        $valid = true;
        $type = $event($entries, $continue, $preview)->type();

        foreach ($this->hooks as $hook) {
            foreach ($hook->api->handlers($type) as $handler) {
                try {
                    $result = $handler($event($entries, $continue, $preview), $context);

                    if ($result instanceof BoundaryResult) {
                        $entries = $result->entries ?? $entries;
                        $continue = $result->continue ?? $continue;
                    } elseif ($result !== null) {
                        $this->wrongType($hook, $type, BoundaryResult::class, $result);
                    }
                } catch (Throwable $error) {
                    $this->fail($hook, $type, $error);
                }

                try {
                    $preview = $buildContext($entries);
                    $valid = true;
                } catch (Throwable $error) {
                    $valid = false;
                    $this->emitError(new HookError($hook->path, $type, 'Invalid boundary entries: ' . $error->getMessage()));
                }
            }
        }

        return $valid
            ? new BoundaryDispatch($entries, $continue, $preview, true)
            : new BoundaryDispatch([], false, $preview, false);
    }

    /** Ask whether to leave this conversation. The first refusal stops the rest. */
    public function emitBeforeSwitch(SessionBeforeSwitchEvent $event): ?SessionBeforeSwitchResult
    {
        return $this->ask($event, SessionBeforeSwitchResult::class, static fn (object $r): bool => $r->cancel);
    }

    /**
     * Ask whether to jump, and whether a hook would rather write the handover itself.
     *
     * Stops at the first handler that either refuses or supplies a summary — both mean the
     * model is not going to be asked, exactly as with `emitBeforeCompact()`. Checking only
     * `cancel` here was a bug: a hook that wrote a summary was walked straight past and the
     * model was asked anyway.
     */
    public function emitBeforeTree(SessionBeforeTreeEvent $event): ?SessionBeforeTreeResult
    {
        return $this->ask(
            $event,
            SessionBeforeTreeResult::class,
            static fn (object $r): bool => $r->cancel || $r->summary !== null,
        );
    }

    /**
     * Ask whether to compact, and whether a hook would rather do it itself.
     *
     * Stops at the first handler that either refuses or supplies a summary, since both
     * mean the model is not going to be asked.
     */
    public function emitBeforeCompact(SessionBeforeCompactEvent $event): ?SessionBeforeCompactResult
    {
        return $this->ask(
            $event,
            SessionBeforeCompactResult::class,
            static fn (object $r): bool => $r->cancel || $r->compaction !== null,
        );
    }

    /**
     * The shape the three cancellable events share.
     *
     * @template T of object
     * @param class-string<T>     $expected
     * @param Closure(T): bool    $decisive whether this answer ends the question
     * @return T|null
     */
    private function ask(HookEvent $event, string $expected, Closure $decisive): ?object
    {
        $context = $this->context();

        foreach ($this->hooks as $hook) {
            foreach ($hook->api->handlers($event->type()) as $handler) {
                try {
                    $result = $handler($event, $context);
                } catch (Throwable $error) {
                    $this->fail($hook, $event->type(), $error);

                    continue;
                }

                if ($result === null) {
                    continue;
                }

                if (!$result instanceof $expected) {
                    $this->wrongType($hook, $event->type(), $expected, $result);

                    continue;
                }

                if ($decisive($result)) {
                    return $result;
                }
            }
        }

        return null;
    }

    private function fail(LoadedHook $hook, string $event, Throwable $error): void
    {
        $this->emitError(new HookError($hook->path, $event, $error::class . ': ' . $error->getMessage()));
    }

    private function wrongType(LoadedHook $hook, string $event, string $expected, mixed $got): void
    {
        $this->emitError(new HookError(
            $hook->path,
            $event,
            'handler returned ' . get_debug_type($got) . ', expected ' . $expected . ' or null',
        ));
    }
}
