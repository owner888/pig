<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Hooks;

use Closure;
use Pig\Ai\ImageContent;
use Pig\Ai\Model;
use Pig\CodingAgent\Hooks\Events\BeforeAgentStartEvent;
use Pig\CodingAgent\Hooks\Events\ContextEvent;
use Pig\CodingAgent\Hooks\Events\SessionBeforeCompactEvent;
use Pig\CodingAgent\Hooks\Events\SessionBeforeSwitchEvent;
use Pig\CodingAgent\Hooks\Events\SessionBeforeTreeEvent;
use Pig\CodingAgent\Hooks\Events\ToolCallEvent;
use Pig\CodingAgent\Hooks\Events\ToolResultEvent;
use Pig\CodingAgent\Hooks\Results\BeforeAgentStartEventResult;
use Pig\CodingAgent\Hooks\Results\ContextEventResult;
use Pig\CodingAgent\Hooks\Results\SessionBeforeCompactResult;
use Pig\CodingAgent\Hooks\Results\SessionBeforeSwitchResult;
use Pig\CodingAgent\Hooks\Results\SessionBeforeTreeResult;
use Pig\CodingAgent\Hooks\Results\ToolCallEventResult;
use Pig\CodingAgent\Hooks\Results\ToolResultEventResult;
use Pig\CodingAgent\Session\SessionManager;
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

    private ?Closure $hasQueuedMessages = null;

    /** @param list<LoadedHook> $hooks in the order they were loaded, which is the order they run */
    public function __construct(
        array $hooks = [],
        private readonly string $cwd = '.',
        private readonly ?SessionManager $store = null,
    ) {
        $this->hooks = $hooks;
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
     * @param Closure(): bool|null    $hasQueuedMessages
     */
    public function initialize(
        Closure $getModel,
        ?Closure $isIdle = null,
        ?Closure $abort = null,
        ?Closure $hasQueuedMessages = null,
    ): void {
        $this->getModel = $getModel;
        $this->isIdle = $isIdle;
        $this->abort = $abort;
        $this->hasQueuedMessages = $hasQueuedMessages;
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
            $this->hasQueuedMessages,
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
     * Run the conversation past the hooks on its way to the model.
     *
     * The one chained event: each handler is given what the last one returned, so two
     * hooks can both edit the context without either having to know about the other.
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
                try {
                    $result = $handler(new ContextEvent($current), $context);
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

                $current = $result->messages;
            }
        }

        return $current;
    }

    /**
     * Offer the hooks a word before the prompt goes out.
     *
     * First answer wins. Upstream does the same, and for the same reason: two notes in
     * front of one prompt is a conversation nobody wrote.
     *
     * @param list<ImageContent> $images
     */
    public function emitBeforeAgentStart(string $prompt, array $images = []): ?BeforeAgentStartEventResult
    {
        $event = new BeforeAgentStartEvent($prompt, $images);
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

                $first ??= $result;
            }
        }

        return $first;
    }

    /** Ask whether to leave this conversation. The first refusal stops the rest. */
    public function emitBeforeSwitch(SessionBeforeSwitchEvent $event): ?SessionBeforeSwitchResult
    {
        return $this->ask($event, SessionBeforeSwitchResult::class, static fn (object $r): bool => $r->cancel);
    }

    /** Ask whether to jump. The first refusal stops the rest. */
    public function emitBeforeTree(SessionBeforeTreeEvent $event): ?SessionBeforeTreeResult
    {
        return $this->ask($event, SessionBeforeTreeResult::class, static fn (object $r): bool => $r->cancel);
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
