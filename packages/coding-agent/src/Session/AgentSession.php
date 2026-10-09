<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Session;

use Closure;
use Pig\Agent\Agent;
use Pig\Agent\AgentError;
use Pig\Agent\AgentEndEvent;
use Pig\Agent\AgentEvent;
use Pig\Agent\AgentLoopTurnUpdate;
use Pig\Agent\AgentStartEvent;
use Pig\Agent\AgentState;
use Pig\Agent\AgentToolResult;
use Pig\Agent\MessageEndEvent;
use Pig\Agent\MessageStartEvent;
use Pig\Agent\MessageUpdateEvent;
use Pig\Agent\PrepareNextTurnContext;
use Pig\Agent\PrepareRequestContext;
use Pig\Agent\QueueMode;
use Pig\Agent\ThinkingLevel;
use Pig\Agent\TurnEndEvent;
use Pig\Agent\TurnStartEvent;
use Pig\Agent\ToolExecutionStartEvent;
use Pig\Agent\ToolExecutionUpdateEvent;
use Pig\Agent\ToolExecutionEndEvent;
use Pig\Ai\AssistantMessage;
use Pig\Ai\Context;
use Pig\Ai\ImageContent;
use Pig\Ai\Model;
use Pig\Ai\Models;
use Pig\Ai\SimpleStreamOptions;
use Pig\Ai\StopReason;
use Pig\Ai\Stream;
use Pig\Ai\SystemMessage;
use Pig\Ai\TextContent;
use Pig\Ai\Timestamp;
use Pig\Ai\Tool;
use Pig\Ai\ToolCall;
use Pig\Ai\ToolResultMessage;
use Pig\Ai\TranscriptContext;
use Pig\Ai\UserMessage;
use Pig\Ai\Utils\Overflow;
use Pig\Ai\Utils\Retry;
use Pig\Ai\Utils\Text;
use Pig\Ai\Utils\Transcript;
use Pig\Async\AbortController;
use Pig\Async\AbortSignal;
use Pig\Async\Async;
use Pig\Async\Deferred;
use Pig\Async\Future;
use Pig\Async\Loop;
use Pig\CodingAgent\Auth;
use Pig\CodingAgent\Hooks\Events\AgentEndEvent as HookAgentEnd;
use Pig\CodingAgent\Hooks\Events\AgentSettledEvent as HookAgentSettled;
use Pig\CodingAgent\Hooks\Events\AgentStartEvent as HookAgentStart;
use Pig\CodingAgent\Hooks\Events\MessageEndEvent as HookMessageEnd;
use Pig\CodingAgent\Hooks\Events\MessageStartEvent as HookMessageStart;
use Pig\CodingAgent\Hooks\Events\MessageUpdateEvent as HookMessageUpdate;
use Pig\CodingAgent\Hooks\Events\SessionBeforeCompactEvent;
use Pig\CodingAgent\Hooks\Events\SessionBeforeSwitchEvent;
use Pig\CodingAgent\Hooks\Events\SessionShutdownEvent;
use Pig\CodingAgent\Hooks\Events\SessionStartEvent;
use Pig\CodingAgent\Hooks\Events\SessionBeforeTreeEvent;
use Pig\CodingAgent\Hooks\Events\SessionCompactEvent;
use Pig\CodingAgent\Hooks\Events\SessionCompactFailedEvent;
use Pig\CodingAgent\Hooks\Events\InputEvent;
use Pig\CodingAgent\Hooks\Events\UserBashEvent;
use Pig\CodingAgent\Hooks\Events\ToolExecutionStartEvent as HookToolExecutionStart;
use Pig\CodingAgent\Hooks\Events\ToolExecutionUpdateEvent as HookToolExecutionUpdate;
use Pig\CodingAgent\Hooks\Events\ToolExecutionEndEvent as HookToolExecutionEnd;
use Pig\CodingAgent\Hooks\Events\SessionInfoChangedEvent;
use Pig\CodingAgent\Hooks\Events\SessionTreeEvent;
use Pig\CodingAgent\Hooks\Events\TurnEndEvent as HookTurnEnd;
use Pig\CodingAgent\Hooks\Events\AfterProviderResponseEvent;
use Pig\CodingAgent\Hooks\Events\ProviderStreamEvent;
use Pig\CodingAgent\Hooks\Events\ModelSelectEvent;
use Pig\CodingAgent\Hooks\Events\ThinkingLevelSelectEvent;
use Pig\CodingAgent\Hooks\Events\TurnStartEvent as HookTurnStart;
use Pig\CodingAgent\Hooks\Boundary\BoundaryContextPreview;
use Pig\CodingAgent\Hooks\Boundary\CompactionDraft;
use Pig\CodingAgent\Hooks\Boundary\ContextEditDraft;
use Pig\CodingAgent\Hooks\Boundary\CustomEntryDraft;
use Pig\CodingAgent\Hooks\Boundary\CustomMessageDraft;
use Pig\CodingAgent\Hooks\Boundary\SessionBoundaryDraft;
use Pig\CodingAgent\Hooks\Events\AgentBeforeSettleEvent;
use Pig\CodingAgent\VirtualModels\ModelRoute;
use Pig\CodingAgent\VirtualModels\ModelRouteRequest;
use Pig\CodingAgent\VirtualModels\RoutedModel;
use Pig\CodingAgent\VirtualModels\VirtualModelRegistry;
use Pig\CodingAgent\CodingAgent;
use Pig\CodingAgent\Hooks\HookError;
use Pig\CodingAgent\Hooks\HookRunner;
use Pig\CodingAgent\ModelChoice;
use Pig\CodingAgent\ModelResolver;
use Pig\CodingAgent\Prompt\FileCommand;
use Pig\CodingAgent\Prompt\Skills;
use Pig\CodingAgent\Prompt\SlashCommands;
use Pig\CodingAgent\Prompt\SystemPrompt;
use Pig\CodingAgent\Prompt\SystemPromptOptions;
use Pig\CodingAgent\Theme\Themes;
use Pig\CodingAgent\Settings;
use Pig\CodingAgent\Tools\ToolLoadout;
use Pig\Agent\AgentTool;
use Pig\Agent\ToolArguments;
use Pig\CodingAgent\Tools\Run;
use Pig\CodingAgent\Tools\Truncate;
use Throwable;

/**
 * A conversation with a UI attached to it.
 *
 * `Agent` runs the conversation; this sits between it and the interactive mode, holding
 * what the UI needs and nothing the UI does not: the events, the messages waiting to be
 * sent, the thinking level, and what the session has cost. The UI never touches the
 * agent directly, so there is one place that knows how a keystroke becomes a prompt.
 *
 * Ported from upstream's `agent-session.ts`, which is 1901 lines. Most of that is
 * persistence, compaction, auto-retry, branching, hooks, custom tools, HTML export and
 * the model registry — every one of which needs a subsystem that is not ported yet, and
 * each of which can arrive on its own when it is. What is here is what makes a session
 * a session.
 *
 * **Upstream does not compile at the anchor commit.** `d0a4c37` split the agent's one
 * queue into `steer()` and `followUp()` and did not update this file, which still calls
 * `agent.queueMessage()`, `clearMessageQueue()` and `getQueueMode()` — none of which
 * exist any more. So there is no literal thing to port here, and this is written against
 * the split API that `Pig\Agent\Agent` actually has. See CLAUDE.md.
 */
final class AgentSession
{
    /** @var array<int, Closure(AgentEvent): void> */
    private array $listeners = [];

    private int $nextListenerId = 0;

    /** @var list<string> steering messages, in the order they were typed */
    private array $steering = [];

    /** @var list<string> follow-ups, likewise */
    private array $followUps = [];

    private ?Closure $unsubscribeAgent = null;

    private ?AbortController $bash = null;

    /** @var list<BashExecution> run while the agent was working, waiting for it to stop */
    private array $pendingBash = [];

    /** How many turns this run has had, for the turn events. */
    private int $turnIndex = 0;

    /** Which retry we are on, or 0 when nothing is being retried. */
    private int $attempt = 0;

    /** Set while a retry is sleeping, so escape can call it off. */
    private ?AbortController $retrying = null;

    /**
     * A prompt is in progress, from the top of `runAgentPrompt()` to its `agent_settled`: the runs,
     * and the retry sleeps, summaries and queued messages between them. Upstream's
     * `_isAgentRunActive`, and what `isStreaming()` answers.
     */
    private bool $runActive = false;

    /** Escape reached the prompt in progress, so its post-run loop stops. Upstream's `_agentRunAbortRequested`. */
    private bool $runAbortRequested = false;

    /** @var 'completed'|'aborted'|'error' how the last turn ended, for `agent_before_settle` */
    private string $lastOutcome = 'completed';

    /** Set while the hooks hear `agent_settled`. Upstream's `_isEmittingAgentSettled`. */
    private bool $emittingSettled = false;

    /** @var list<Closure(): void> prompts asked for during `agent_settled`, run after it. Upstream's `_deferredSettledActions`. */
    private array $deferredSettledActions = [];

    /** Completed when the session is next idle, for whoever waits on that. Upstream's `_idleWaitPromise`. */
    private ?Deferred $idle = null;

    /**
     * Set while a summariser is running, so escape can call it off.
     *
     * Both compactions, the one somebody typed and the one an overflow started, because there is
     * one `compact()` here where upstream has two paths and two controllers. A caller may pass a
     * signal of its own as well; `compact()` forwards it into this one, so what goes downstream is
     * a single signal that either end can raise.
     */
    private ?AbortController $compacting = null;

    /**
     * What the `before_agent_start` handlers made of the prompt for the run in progress —
     * upstream's `_runSystemPromptOptions`: "Prompt options after before_agent_start mutations for
     * the active run." Their sections are diffed with the loadout's before every request of the run;
     * a forced prompt is projected onto the request and never recorded. Null between runs.
     */
    private ?SystemPromptOptions $runSystemPromptOptions = null;

    /**
     * Upstream's `_failedResponse`: the response a retry is sending again, for the virtual
     * model's router (`reason: retry`, with the failed request). Set when the retry is decided,
     * taken by the next request's routing, and cleared with the prompt.
     */
    private ?AssistantMessage $failedResponse = null;

    /**
     * Prompts kept as files, so `/review foo.php` is the prompt in `review.md` with `$1` filled in.
     *
     * Here rather than only in the terminal, which is where they used to live: a stored prompt
     * that only expands in front of a person is a stored prompt `pig -p "/review foo.php"` sends
     * to the model as the six characters `/review`. See `prompt()`.
     *
     * @var list<FileCommand>
     */
    private array $fileCommands;

    /**
     * What `--models` narrowed this session to, or empty for the whole registry.
     *
     * Upstream's `scopedModels`. Each entry carries a thinking level, because
     * `--models sonnet:high,haiku:low` says one per model — which is the reason cycling lives
     * here rather than in each mode: a caller that had to remember to apply the level is a
     * caller that will forget in one of the two places.
     *
     * @var list<ModelChoice>
     */
    private array $modelScope;

    /**
     * @param list<FileCommand> $fileCommands
     * @param list<ModelChoice> $modelScope
     */
    public function __construct(
        public readonly Agent $agent,
        private readonly string $cwd = '.',
        private ?SessionManager $store = null,
        private readonly ?Settings $settings = null,
        private ?HookRunner $hooks = null,
        array $fileCommands = [],
        array $modelScope = [],
        public readonly ?Auth $auth = null,
        private ?ToolLoadout $loadout = null,
        private bool $projectTrusted = true,
    ) {
        $this->fileCommands = $fileCommands;
        $this->modelScope = $modelScope;
        $this->unsubscribeAgent = $this->agent->subscribe($this->onAgentEvent(...));
        // Upstream's agent carries the session manager's id as its `sessionId`; pig's session
        // identity is the file's, so a session that is not written down has none.
        $this->agent->sessionId = $store?->id;

        // Upstream's `createAgentSession()` builds the agent with the extension provider hooks as
        // its `onPayload` / `onResponse` / `onProviderStreamEvent`, `maxRetryDelayMs` from the
        // provider retry settings, and a `streamFn` that adds the request options
        // (`buildRequestOptions()`). pig's agent is built before the session, so the session puts
        // them on it here. Each reads `$this->hooks` when it runs, so a `/reload` that swaps the
        // runner is picked up.
        $this->agent->onPayload = fn (mixed $payload, Model $model): mixed => $this->hooks?->hasHandlers('before_provider_request') === true
            ? $this->hooks->emitBeforeProviderRequest($payload)
            : $payload;
        $this->agent->onResponse = function (array $response, Model $model): void {
            if ($this->hooks?->hasHandlers('after_provider_response') === true) {
                $this->hooks->emit(new AfterProviderResponseEvent($response['status'], $response['headers']));
            }
        };
        $this->agent->onProviderStreamEvent = function (mixed $data, Model $model): void {
            if ($this->hooks?->hasHandlers('provider_stream_event') === true) {
                $this->hooks->emit(new ProviderStreamEvent($model->provider, $model->api->value, $model->id, $data));
            }
        };
        $this->agent->maxRetryDelayMs = $this->settings?->providerRetrySettings()['maxRetryDelayMs'] ?? 60_000;
        $inner = $this->agent->streamFunction;
        $this->agent->streamFunction = function (Model $model, TranscriptContext $context, ?SimpleStreamOptions $options) use ($inner): mixed {
            $requestOptions = $this->buildRequestOptions($options ?? new SimpleStreamOptions());

            return $inner !== null ? $inner($model, $context, $requestOptions) : Stream::simple($model, $context, $requestOptions);
        };

        // Upstream's `_installAgentNextTurnRefresh()`: before each turn after the first, the prompt
        // sections the loadout wants now are diffed against the ones the transcript has, and a
        // change goes in as a system message before the next request — the prompt follows a tool
        // that `tool_search` loaded mid-run, or a `setActiveTools()`, without waiting for the next
        // prompt. The tool declarations themselves are the loop's (`declareToolChanges()`). A
        // previous hook on the agent is asked first, as upstream chains it.
        $previousPrepareNextTurn = $this->agent->prepareNextTurnWithContext;
        $previousPrepareNextTurnWithoutContext = $this->agent->prepareNextTurn;
        $this->agent->prepareNextTurnWithContext = function (PrepareNextTurnContext $turn, ?AbortSignal $signal) use ($previousPrepareNextTurn, $previousPrepareNextTurnWithoutContext): ?AgentLoopTurnUpdate {
            $previous = $previousPrepareNextTurn !== null
                ? $previousPrepareNextTurn($turn, $signal)
                : ($previousPrepareNextTurnWithoutContext !== null ? $previousPrepareNextTurnWithoutContext($signal) : null);
            $context = $previous?->context ?? $turn->context;
            $update = $this->preparePromptUpdate($context->messages, $this->runSystemPromptOptions);

            if ($update === null) {
                return $previous;
            }

            return new AgentLoopTurnUpdate(
                $previous?->context,
                [...($previous?->messages ?? []), $update],
                $previous?->model,
                $previous?->thinkingLevel,
            );
        };

        // Upstream's `_installAgentForcedPromptProjection()`: "Send a forced prompt as the provider's
        // leading system prompt without recording it. A `before_agent_start` handler that returns
        // `systemPrompt` needs that exact text at the head of the request; a mid-conversation system
        // message would leave the original prompt in place. The forced text is a rendering of the
        // current prompt, so the transcript keeps its structured sections and the request is
        // projected instead: the system messages collapse into one head holding the forced text and
        // the current tools. Runs after the `context` extension handlers."
        $previousTransformContext = $this->agent->transformContext;
        $this->agent->transformContext = function (array $messages, ?AbortSignal $signal = null) use ($previousTransformContext): array {
            $transformed = $previousTransformContext !== null ? $previousTransformContext($messages, $signal) : $messages;
            $forced = $this->runSystemPromptOptions?->forceSystemPrompt;

            if ($forced === null) {
                return $transformed;
            }

            $current = Transcript::getCurrentSystemMessage($transformed);
            $head = new SystemMessage($forced, null, $current?->toolsAdded, null, $current?->timestamp ?? Timestamp::nowMs());

            return [$head, ...array_values(array_filter($transformed, static fn (mixed $message): bool => !$message instanceof SystemMessage))];
        };

        // Upstream's `_installAgentRequestProjection()`, the routing half: before every request,
        // a virtual selection is routed to the physical model and thinking level the request goes
        // out with. The selection stays in the agent's state; only the request uses the routed
        // model, so each request routes again. A previous hook on the agent is asked first.
        $previousPrepareRequest = $this->agent->prepareRequest;
        $this->agent->prepareRequest = function (PrepareRequestContext $request, ?AbortSignal $signal) use ($previousPrepareRequest): ?AgentLoopTurnUpdate {
            $failed = $this->failedResponse;
            $this->failedResponse = null;
            $previous = $previousPrepareRequest !== null
                ? $previousPrepareRequest(new PrepareRequestContext($request->context, $this->agent->state->model ?? $request->model, $this->agent->state->thinkingLevel), $signal)
                : null;
            $context = $previous?->context ?? $request->context;
            $model = $previous?->model ?? $this->agent->state->model ?? $request->model;
            $thinkingLevel = $previous?->thinkingLevel ?? $this->agent->state->thinkingLevel;

            if (!Models::isVirtual($model)) {
                return $previous;
            }

            $route = $this->route($model, $thinkingLevel, $context->messages, $failed, $signal);

            return new AgentLoopTurnUpdate($previous?->context, null, $route->model, $route->thinkingLevel);
        };

        // What was chosen last time, applied here rather than by whoever built the agent: this
        // is the class that holds both the agent and the settings, and `setQueueMode()` right
        // below writes the setting through the same pair. A caller passing it into
        // `AgentOptions` instead would mean two routes for one fact, and the one route that
        // every mode and every test already goes through is this constructor.
        if ($settings !== null) {
            $this->agent->setQueueMode($settings->queueMode());
        }

        // `HookRunner::setSession()` existed and nothing called it, so `$ctx->session` was null
        // in every handler — a `HookContext` field documented and wired at one end only. The
        // session is the thing that holds the runner, so it is the thing that tells the runner,
        // through `setHooks()`, which a later `/reload` goes through too.
        $this->setHooks($this->hooks);
    }

    /** The hooks this session fires at, if any. */
    public function hooks(): ?HookRunner
    {
        return $this->hooks;
    }

    public function setHooks(?HookRunner $hooks): void
    {
        $this->hooks = $hooks;
        $hooks?->setSession($this);
    }

    /** @param list<FileCommand> $fileCommands */
    public function setFileCommands(array $fileCommands): void
    {
        $this->fileCommands = $fileCommands;
    }

    /**
     * Upstream's `extendResourcesFromExtensions()`: after `session_start`, the directories the
     * extensions add through `resources_discover` are loaded — skills onto the loadout, and so
     * into the system prompt; prompt templates beside the file commands; theme directories into
     * `Themes`. Null when nobody listened or nobody added anything, so a caller can leave its
     * own lists alone. A skill that could not be read is reported against the extension that
     * named its directory.
     *
     * @param 'startup'|'reload' $reason
     */
    public function discoverResources(string $reason): ?ExtensionResources
    {
        if ($this->hooks === null || !$this->hooks->listensTo('resources_discover')) {
            return null;
        }

        $found = $this->hooks->emitResourcesDiscover($this->cwd, $reason);

        if ($found->isEmpty()) {
            return null;
        }

        $paths = static fn (array $entries): array => array_values(array_unique(array_column($entries, 'path')));

        [$skills, $warnings] = Skills::fromDirectories($paths($found->skillPaths));

        foreach ($warnings as $warning) {
            $extensionPath = '';

            foreach ($found->skillPaths as $entry) {
                if (str_starts_with($warning->path, rtrim($entry['path'], '/') . '/')) {
                    $extensionPath = $entry['extensionPath'];
                }
            }

            $this->hooks->emitError(new HookError($extensionPath, 'resources_discover', "skill {$warning->path}: {$warning->message}"));
        }

        $commands = SlashCommands::fromDirectories($paths($found->promptPaths));
        Themes::setExtensionThemeDirs($paths($found->themePaths));

        $this->fileCommands = [
            ...array_values(array_filter($this->fileCommands, static fn (FileCommand $c): bool => !str_starts_with($c->source, '(extension'))),
            ...$commands,
        ];
        $this->loadout?->addSkills($skills);

        return new ExtensionResources($skills, $commands);
    }

    /** Where this session is being written, if it is. */
    public function store(): ?SessionManager
    {
        return $this->store;
    }

    /** The command to resume this session, if it was persisted to disk. */
    public function resumeCommand(): ?string
    {
        return $this->store?->resumeCommand();
    }

    /** The project directory, so a mode holding a session need not be handed it twice. */
    public function cwd(): string
    {
        return $this->cwd;
    }

    /** The settings this session reads, for an extension that has a key of its own in them — upstream's `pi.getSettings()`. */
    public function settings(): ?Settings
    {
        return $this->settings;
    }

    /**
     * Write somewhere else from now on.
     *
     * Not a setter for its own sake: this was `readonly`, and that was a data-losing bug.
     * `/resume` opened another session, put its messages into the agent, and then carried
     * on appending to the file pig had created at startup — so that file ended up holding
     * its own opening plus the continuation of a different conversation, and the resumed
     * one stopped growing at the moment it was resumed. `/new` did the same thing in one
     * file: the fresh conversation was appended as a continuation of the old one, and the
     * new session never existed as a session at all.
     *
     * Upstream's `AgentSession` owns its `sessionManager` and replaces it in
     * `switchSession()` and `newSession()`, so this is a missing piece of the port rather
     * than an addition. Null for a session that is not being written down.
     *
     * The conversation is not touched: a caller switching files decides separately what
     * the agent should be holding, because `/new` wants nothing and `/resume` wants
     * whatever was in the file.
     */
    public function writeTo(?SessionManager $store): void
    {
        $this->store = $store;
        $this->agent->sessionId = $store?->id;
        $this->hooks?->setStore($store);
    }

    /**
     * Pick a saved session up where it was left.
     *
     * The messages go straight into the agent: they are the conversation, and the model
     * is handed them on the next turn exactly as if they had just happened.
     *
     * @param list<mixed> $messages
     */
    public function restore(array $messages): void
    {
        $this->agent->replaceMessages($messages);
        $this->restoreToolsFromTranscript();
    }

    /**
     * Restore the active tool loadout declared by the session transcript, if it declares one —
     * upstream's `_restoreToolsFromTranscript()`, which it runs when a session opens and after tree
     * navigation (both reach `restore()` here). "Tools reachable only from other tools are never
     * declared, but they do not depend on the active set, so the transcript's declarations are the
     * whole loadout." The declared tools that are not registered yet — an MCP server still
     * connecting, a tool `tool_search` had loaded — are pending until they are.
     */
    private function restoreToolsFromTranscript(): void
    {
        if ($this->loadout === null) {
            return;
        }

        $this->loadout->clearPending();
        $current = Transcript::getCurrentSystemMessage($this->agent->state->messages);

        if ($current === null) {
            return;
        }

        $this->loadout->restore(array_map(static fn (Tool $tool): string => $tool->name, $current->toolsAdded ?? []));
    }

    /**
     * Whether $name is a tool the restored loadout declared that has not been registered yet. An
     * extension that holds a tool back until something asks for it — the MCP extension's
     * `deferred` tools — registers it when the session is waiting for it, which is what upstream's
     * registry of inactive deferred tools does by itself.
     */
    public function isToolPending(string $name): bool
    {
        return $this->loadout?->isPending($name) ?? false;
    }

    /**
     * Come back to the model and thinking level this conversation was being had with.
     *
     * Called when a session is resumed, after `restore()`. Silent about anything it cannot
     * honour: a session recorded on a model this machine has no key for, or a thinking level
     * this pig has no name for, is not a reason to refuse to open the conversation — and the
     * model it falls back to is said on the footer where anybody can see it.
     *
     * A model named on the command line wins, which is why this takes a flag rather than
     * deciding for itself: `--model sonnet` is someone saying what they want *now*, and the
     * file is saying what was true last time.
     */
    public function restoreSettings(bool $modelWasAskedFor = false): void
    {
        $recorded = $this->store?->settings();

        if ($recorded === null) {
            return;
        }

        if (!$modelWasAskedFor && $recorded['model'] !== null) {
            $model = Models::find($recorded['model']->provider, $recorded['model']->modelId)
                ?? Models::get($recorded['model']->modelId);

            // A key as well as a model. Upstream's `restoreModelFromSession()` checks both and
            // falls back on either — an afternoon on a borrowed `--api-key` comes back tomorrow
            // with no key for that provider, and restoring it would mean a conversation that
            // reopens onto a model whose every turn fails.
            $hasKey = $model !== null && ($this->auth !== null
                ? $this->auth->hasKeyFor($model->provider)
                : $this->keyFor($model) !== null);

            if ($hasKey) {
                $this->agent->setModel($model);
            }
        }

        // After the model, because which levels exist depends on it. Clamped rather than
        // taken on trust for the same reason `setModel()` clamps: a level the model cannot
        // do is a request the provider rejects, and the person resuming did not ask for it
        // — the file did.
        $level = $recorded['thinking']?->known() ?? $this->thinkingLevel();

        $this->agent->setThinkingLevel($this->clampThinking($level));
    }

    // ---- events ------------------------------------------------------------------

    /**
     * @param Closure(AgentEvent): void $listener
     * @return Closure(): void call it to stop listening
     */
    public function subscribe(Closure $listener): Closure
    {
        $id = $this->nextListenerId++;
        $this->listeners[$id] = $listener;

        return function () use ($id): void {
            unset($this->listeners[$id]);
        };
    }

    /** Drop every listener and let go of the agent. */
    public function dispose(): void
    {
        try {
            $this->abortRetry();
            $this->abortCompaction();
            $this->abortBash();
            $this->agent->abort();
            $this->clearQueue();
        } catch (Throwable) {
            // Dispose must succeed even if an abort hook throws.
        }

        if ($this->unsubscribeAgent !== null) {
            ($this->unsubscribeAgent)();
            $this->unsubscribeAgent = null;
        }

        $this->listeners = [];
    }

    private function onAgentEvent(AgentEvent $event): void
    {
        $this->tellHooks($event);

        // A queued message leaves the queue *before* the event goes out, so a listener
        // redrawing the "3 messages waiting" line sees three, not four.
        if ($event instanceof MessageStartEvent && $event->message instanceof UserMessage) {
            $this->dequeue(self::textOf($event->message));
        }

        // Written when the message is finished, not when it starts: a streamed answer is
        // re-sent complete on every update, and only the last one is the whole of it.
        if ($event instanceof MessageEndEvent) {
            $this->store?->append($event->message);
        }

        // Upstream emits to extensions first and then, to its own listeners, `agent_end` with
        // `willRetry` filled in (`_willRetryAfterAgentEnd()`).
        $forListeners = $event instanceof AgentEndEvent
            ? new AgentEndEvent($event->messages, $this->willRetryAfterAgentEnd($event))
            : $event;

        foreach ($this->listeners as $listener) {
            $listener($forListeners);
        }

        // Upstream: "Reset retry counter immediately on successful assistant response. This
        // prevents accumulation across multiple LLM calls within a turn."
        if ($event instanceof MessageEndEvent
            && $event->message instanceof AssistantMessage
            && $event->message->stopReason !== StopReason::Error
            && $this->attempt > 0) {
            $attempts = $this->attempt;
            $this->attempt = 0;
            $this->announce(new RetryEndEvent(true, $attempts));
        }

        // Nothing is decided here about what comes after the run — retry, summary, the queue.
        // That is `runAgentPrompt()`'s loop, after `agent->prompt()` has returned, as upstream
        // has it. Deciding it here, inside the run's own fan-out, is what made `agent_settled` a
        // per-*run* event: it went out on every end that did not start a retry, including the
        // ones with a queued message or a hook's turn about to run.
    }

    /**
     * Announce something of the session's own on the same stream.
     *
     * Retries and self-started compaction are not the agent loop's events — the loop is not
     * running when they happen — but they are the same listeners' business, so they go the
     * same way. See `AgentEvent`'s docblock.
     */
    private function announce(AgentEvent $event): void
    {
        foreach ($this->listeners as $listener) {
            $listener($event);
        }
    }

    /**
     * The four run events, translated for the hooks.
     *
     * The agent's own events and the hooks' events are not the same set and are not
     * meant to be: the agent reports every message and every delta, and a hook is
     * offered the four moments upstream chose. The turn counter is reset by
     * `agent_start` rather than kept per session, because upstream's `turnIndex` is
     * "which turn of this run", which is what a hook watching a long run wants.
     */
    private function tellHooks(AgentEvent $event): void
    {
        if ($this->hooks === null) {
            return;
        }

        if ($event instanceof AgentStartEvent) {
            $this->turnIndex = 0;
            $this->hooks->emit(new HookAgentStart());

            return;
        }

        if ($event instanceof TurnStartEvent) {
            $this->hooks->emit(new HookTurnStart($this->turnIndex));

            return;
        }

        if ($event instanceof TurnEndEvent) {
            // Upstream's `_lastActivityOutcome`, for `agent_before_settle`'s `outcome`.
            $this->lastOutcome = match ($event->message->stopReason ?? null) {
                StopReason::Aborted => 'aborted',
                StopReason::Error => 'error',
                default => 'completed',
            };
            $this->hooks->emit(new HookTurnEnd($event->message, $event->toolResults, $this->turnIndex));
            $this->turnIndex++;

            return;
        }

        if ($event instanceof MessageStartEvent) {
            $this->hooks->emit(new HookMessageStart($event->message));

            return;
        }

        if ($event instanceof MessageUpdateEvent) {
            $this->hooks->emit(new HookMessageUpdate($event->message, $event->assistantMessageEvent));

            return;
        }

        if ($event instanceof MessageEndEvent) {
            $this->hooks->emit(new HookMessageEnd($event->message));

            return;
        }

        if ($event instanceof ToolExecutionStartEvent) {
            $this->hooks->emit(new HookToolExecutionStart($event->toolCallId, $event->toolName, $event->arguments));

            return;
        }

        if ($event instanceof ToolExecutionUpdateEvent) {
            $this->hooks->emit(new HookToolExecutionUpdate($event->toolCallId, $event->toolName, $event->arguments, $event->partialResult));

            return;
        }

        if ($event instanceof ToolExecutionEndEvent) {
            $this->hooks->emit(new HookToolExecutionEnd($event->toolCallId, $event->toolName, $event->result, $event->isError));

            return;
        }

        if ($event instanceof AgentEndEvent) {
            $this->hooks->emit(new HookAgentEnd($event->messages));
        }

        // `agent_settled` is **not** emitted here. `AgentEndEvent` is the end of a *run*, and a
        // prompt can be several runs: each failed attempt before a retry ends one, so does the
        // run an auto-compaction summarises, and so does a run with a message queued behind it.
        // Upstream emits `agent_settled` once per prompt, from `_emitAgentSettled()` in the
        // `finally` of `_runAgentPrompt()`; pig does the same from `emitAgentSettled()`.
    }

    // ---- what an extension can ask of the session ------------------------------------

    /** `tui`, `print`, `json` or `rpc` — set by whichever mode is driving. Upstream's `ctx.mode`. */
    private string $mode = 'print';

    /** @var (Closure(): void)|null what the mode does when an extension asks pig to quit */
    private ?Closure $onShutdown = null;

    private bool $shutdownRequested = false;

    /** @param 'tui'|'print'|'json'|'rpc' $mode */
    public function setMode(string $mode): void
    {
        $this->mode = $mode;
    }

    public function mode(): string
    {
        return $this->mode;
    }

    /** What the session's tools are, for the mode that rebuilds them on `/reload`. */
    public function loadout(): ?ToolLoadout
    {
        return $this->loadout;
    }

    public function useLoadout(?ToolLoadout $loadout): void
    {
        $this->loadout = $loadout;
    }

    /** Whether `<cwd>/.pig/` was allowed to load. Upstream's `ctx.isProjectTrusted()`. */
    public function isProjectTrusted(): bool
    {
        return $this->projectTrusted;
    }

    public function setProjectTrusted(bool $trusted): void
    {
        $this->projectTrusted = $trusted;
    }

    /** @param (Closure(): void)|null $handler */
    public function onShutdownRequest(?Closure $handler): void
    {
        $this->onShutdown = $handler;
    }

    /**
     * An extension asked pig to quit. Upstream's `ctx.shutdown()`.
     *
     * Now if nothing is running, otherwise once the prompt has settled — upstream's rule, and
     * the reason is the same: a quit requested from a `turn_end` handler must not cut off the
     * retry or the compaction that turn is about to start, or the answer the person asked for.
     */
    public function requestShutdown(): void
    {
        if ($this->onShutdown === null) {
            return;
        }

        if ($this->isIdle()) {
            ($this->onShutdown)();

            return;
        }

        $this->shutdownRequested = true;
    }

    /** Nothing running and nothing about to: no turn, no retry, no summary. Upstream's `isIdle`. */
    public function isIdle(): bool
    {
        return !$this->isStreaming() && $this->compacting === null;
    }

    /**
     * The current effective system prompt, including changes not yet sent to the model —
     * upstream's `session.systemPrompt` (`ctx.getSystemPrompt()`): what the loadout would have the
     * prompt be, or, for a session with no loadout, what the transcript's system messages replay to.
     */
    public function systemPrompt(): string
    {
        return $this->renderSystemPrompt($this->runSystemPromptOptions);
    }

    /**
     * Upstream's `buildSystemPrompt(options)`: a forced prompt as it is, otherwise the sections —
     * the loadout's and the handlers' — rendered as the transcript replays them.
     */
    private function renderSystemPrompt(?SystemPromptOptions $options): string
    {
        if ($options?->forceSystemPrompt !== null) {
            return $options->forceSystemPrompt;
        }

        if ($this->loadout === null) {
            return $this->agent->state->systemPrompt();
        }

        return Text::getSystemMessageText(new SystemMessage('', SystemPrompt::withSections($this->loadout->systemPromptSections(), $options?->sections ?? [])));
    }

    /**
     * The system message that brings the transcript's prompt in line with the loadout's, or null
     * when it already is — upstream's `_preparePromptAndToolLoadout()`: "returns a system message
     * patching the prompt sections the model currently has (replayed from `messages`), or
     * undefined when the prompt is unchanged. Tool changes are declared by the agent loop before
     * the request." The first one a session sends declares every section, so it is the
     * transcript's leading system message and the session file's first.
     *
     * Null for a session with no loadout: nothing here knows what its prompt should be, and the
     * transcript's own system messages stand.
     *
     * @param list<mixed>|null         $messages the transcript to diff against; the agent's when null
     * @param SystemPromptOptions|null $options  the run's prompt options, as the handlers left them
     */
    private function preparePromptUpdate(?array $messages, ?SystemPromptOptions $options): ?SystemMessage
    {
        if ($this->loadout === null) {
            return null;
        }

        // The run's options as well as the loadout's, as upstream's next-turn refresh builds from
        // `_runSystemPromptOptions ?? _baseSystemPromptOptions`: a handler's section stays for every
        // request of the run it was added for. A forced prompt does not change what is recorded.
        $sections = SystemPrompt::diffSections(
            Transcript::getCurrentSystemMessage($messages ?? $this->agent->state->messages)?->sections ?? [],
            SystemPrompt::withSections($this->loadout->systemPromptSections(), $options?->sections ?? []),
        );

        return $sections !== null ? new SystemMessage('', $sections) : null;
    }

    /**
     * How full the context window is. Upstream's `getContextUsage()`.
     *
     * Null with no model, or a model that declares no window. `tokens` is null right after a
     * compaction until a turn has come back, because the last reading describes the conversation
     * before it was summarised and nothing has measured the one after — upstream's rule, and the
     * same one `Compaction::lastUsage()` is written to. Otherwise it is the last turn's own count
     * plus an estimate for whatever was said since, which is what the next request will carry.
     */
    public function contextUsage(): ?ContextUsage
    {
        $model = $this->limitsModel();

        if ($model === null || $model->contextWindow <= 0) {
            return null;
        }

        $messages = $this->messages();
        $usage = Compaction::lastUsage($messages);
        $compacted = array_filter($messages, static fn ($m): bool => $m instanceof CompactionSummary) !== [];

        if ($usage === null && $compacted) {
            return new ContextUsage(null, $model->contextWindow, null);
        }

        $tokens = 0;
        $since = $messages;

        if ($usage !== null) {
            $tokens = Compaction::contextTokens($usage);

            for ($i = count($messages) - 1; $i >= 0; $i--) {
                if ($messages[$i] instanceof AssistantMessage && $messages[$i]->usage === $usage) {
                    $since = array_slice($messages, $i + 1);
                    break;
                }
            }
        }

        foreach ($since as $message) {
            $tokens += Compaction::estimateTokens($message);
        }

        return new ContextUsage($tokens, $model->contextWindow, $tokens / $model->contextWindow * 100);
    }

    /** @return list<string> the tools the model is offered now. Upstream's `getActiveTools()`. */
    public function activeTools(): array
    {
        return $this->loadout?->activeNames()
            ?? array_map(static fn (AgentTool $tool): string => $tool->definition()->name, $this->agent->state->tools);
    }

    /**
     * Every registered tool, active or not. Upstream's `getAllTools()`.
     *
     * @return list<array{name: string, description: string, parameters: array<string, mixed>, active: bool}>
     */
    public function allTools(): array
    {
        return $this->loadout?->describe() ?? array_map(static fn (AgentTool $tool): array => [
            'name' => $tool->definition()->name,
            'description' => $tool->definition()->description,
            'parameters' => $tool->definition()->parameters,
            'active' => true,
        ], $this->agent->state->tools);
    }

    /**
     * Offer the model only these, from its next request. Upstream's `setActiveTools()`. Names
     * nothing registered are ignored.
     *
     * @param list<string> $names
     */
    public function setActiveTools(array $names): void
    {
        if ($this->loadout === null) {
            throw new AgentError('This session has no tool loadout to narrow.');
        }

        // Upstream's `setActiveToolsByName()`: "A loadout that deactivates a tool replaces the
        // restored one, whose pending tools are dropped. One that only adds tools, like activating
        // tool_search, keeps them."
        $previous = $this->loadout->activeNames();
        $this->loadout->setActive($names);
        $active = $this->loadout->activeNames();

        if (array_diff($previous, $active) !== []) {
            $this->loadout->clearPending();
        }
    }

    private int $extensionCalls = 0;

    /**
     * Run one of the model's tools on an extension's behalf. Upstream's `ctx.executeTool()`.
     *
     * Through the same wrapped tool the model would get — so the arguments are checked against
     * the schema with the message the model would read, and a `tool_call` hook (the permission
     * gate) is asked exactly as it would be: an extension is not a way round a guard. Only an
     * active tool, for the same reason: a tool an extension switched off is off.
     *
     * Upstream records such a call on its caller's result as `nestedCalls` with a
     * `<parent>/<n>` id; pig has no parent to hang it on when the caller is a command or an
     * event handler, so it is not recorded, and the id says who made it.
     *
     * @param array<string, mixed> $arguments
     * @throws AgentError when no active tool has that name; whatever the tool throws otherwise
     */
    public function executeTool(string $name, array $arguments, ?\Pig\Async\AbortSignal $signal = null): AgentToolResult
    {
        foreach ($this->agent->state->tools as $tool) {
            if ($tool->definition()->name !== $name) {
                continue;
            }

            $id = 'extension-' . (++$this->extensionCalls);
            $arguments = ToolArguments::validate($tool->definition(), new ToolCall($id, $name, $arguments));

            return $tool->execute($id, $arguments, $signal ?? $this->signal());
        }

        throw new AgentError("No active tool called '{$name}'.");
    }

    // ---- state -------------------------------------------------------------------

    public function state(): AgentState
    {
        return $this->agent->state;
    }

    private ?string $sessionName = null;

    /** @var list<Closure(string): void> */
    private array $nameListeners = [];

    public function model(): ?Model
    {
        return $this->agent->state->model;
    }

    /**
     * Under a virtual selection, the physical model of the latest successful response — upstream's
     * `routedModel`. Null under a physical selection, and before the first response.
     */
    public function routedModel(): ?RoutedModel
    {
        $model = $this->model();

        if ($model === null || !Models::isVirtual($model)) {
            return null;
        }

        $latest = VirtualModelRegistry::latestResponse($this->messages());
        $physical = $latest === null ? null : VirtualModelRegistry::physical($latest->provider, $latest->model);

        return $physical === null ? null : new RoutedModel($physical);
    }

    /** The model whose limits apply to the conversation — upstream's `_limitsModel()`. */
    private function limitsModel(): ?Model
    {
        return $this->routedModel()?->model ?? $this->model();
    }

    /**
     * The model whose limits apply to $message, or null when it came from another model —
     * upstream's `_modelForMessage()`. Under a virtual selection, the physical model that produced it.
     */
    private function modelForMessage(AssistantMessage $message): ?Model
    {
        $model = $this->model();

        if ($model !== null && Models::isVirtual($model)) {
            return VirtualModelRegistry::physical($message->provider, $message->model);
        }

        return $model?->provider === $message->provider && $model->id === $message->model ? $model : null;
    }

    /**
     * Route a virtual selection for one request of the agent loop. Upstream's reasons: `retry`
     * when a failed response is being sent again, `user` when a message the person wrote follows
     * the last response (extension messages can follow it too), `continuation` otherwise. The
     * router's state comes off the branch and a new one goes back on it as a `STATE_ENTRY` note.
     *
     * @param list<mixed> $messages
     */
    private function route(Model $model, ThinkingLevel $thinkingLevel, array $messages, ?AssistantMessage $failed, ?AbortSignal $signal): ModelRoute
    {
        $lastResponse = -1;

        for ($i = count($messages) - 1; $i >= 0; $i--) {
            if ($messages[$i] instanceof AssistantMessage) {
                $lastResponse = $i;
                break;
            }
        }

        $userTurn = array_filter(array_slice($messages, $lastResponse + 1), static fn (mixed $message): bool => $message instanceof UserMessage) !== [];
        $state = $this->store === null
            ? null
            : VirtualModelRegistry::stateIn($this->store->customEntries(VirtualModelRegistry::STATE_ENTRY), $model->provider, $model->id);

        $route = VirtualModelRegistry::current()->resolve(
            $model,
            $messages,
            $failed !== null ? ModelRouteRequest::RETRY : ($userTurn ? ModelRouteRequest::USER : ModelRouteRequest::CONTINUATION),
            $thinkingLevel,
            fn (Model $physical): bool => $this->keyFor($physical) !== null,
            $signal,
            $failed,
            $state,
        );

        if ($route->state !== null && $route->state !== $state) {
            $this->store?->appendCustomEntry(VirtualModelRegistry::STATE_ENTRY, [
                'provider' => $model->provider,
                'modelId' => $model->id,
                'state' => $route->state,
            ]);
        }

        return $route;
    }

    /**
     * The model and level a request outside the agent loop goes to — a summary. A virtual
     * selection is routed with reason `direct`, which carries no state; a physical one is itself.
     */
    private function directModel(Model $model, ?AbortSignal $signal): ModelRoute
    {
        if (!Models::isVirtual($model)) {
            return new ModelRoute($model, $this->thinkingLevel());
        }

        return VirtualModelRegistry::current()->resolve(
            $model,
            $this->messages(),
            ModelRouteRequest::DIRECT,
            $this->thinkingLevel(),
            fn (Model $physical): bool => $this->keyFor($physical) !== null,
            $signal,
        );
    }

    /**
     * Record the selection on the branch when the branch implies another one, so a resume
     * restores it — upstream's `_recordSelection()`. Tree navigation can leave the latest
     * `model_change` on another branch, and responses cannot record a virtual selection because
     * they name physical models. Responses do record a physical selection unless the branch holds
     * a virtual one; checking a physical selection against responses would record it on every
     * prompt while the routing redirects to another model.
     */
    private function recordSelection(): void
    {
        $model = $this->model();
        $recorded = $this->store?->settings()['model'];

        if ($model === null || $recorded === null || ($recorded->provider === $model->provider && $recorded->modelId === $model->id)) {
            return;
        }

        $recordedModel = Models::find($recorded->provider, $recorded->modelId);

        if (!Models::isVirtual($model) && !($recordedModel !== null && Models::isVirtual($recordedModel))) {
            return;
        }

        $this->store?->appendModelChange($model->provider, $model->id);
    }

    public function sessionName(): ?string
    {
        return $this->sessionName ?? $this->store?->sessionName();
    }

    public function getSessionName(): ?string
    {
        return $this->sessionName();
    }

    public function onSessionNameChanged(Closure $listener): void
    {
        $this->nameListeners[] = $listener;
    }

    /** @var list<Closure(string, ?string): void> */
    private array $switchListeners = [];

    /**
     * Told after `startNew()` or `switchTo()` has finished, with the reason and the file left.
     *
     * The hook events (`session_shutdown` then `session_start`, with the reason) are for hooks; this is for a front end that has to redraw
     * — the Web UI's tab bar, which otherwise cannot know the terminal just moved to another
     * conversation underneath it.
     *
     * @param Closure('new'|'resume', ?string): void $listener
     */
    public function onSessionSwitched(Closure $listener): void
    {
        $this->switchListeners[] = $listener;
    }

    private function tellSwitched(string $reason, ?string $previous): void
    {
        foreach ($this->switchListeners as $listener) {
            $listener($reason, $previous);
        }
    }

    public function setSessionName(string $name): void
    {
        $name = trim($name);
        $this->sessionName = $name !== '' ? $name : null;
        $this->store?->setSessionName($name);
        $event = new SessionInfoChangedEvent($name);
        $this->hooks?->emit($event);
        $this->announce($event);

        foreach ($this->nameListeners as $listener) {
            $listener($name);
        }
    }

    /**
     * What `--models` narrowed this session to, empty when nothing did.
     *
     * For a caller that needs to say *that* there is a scope — the terminal's "only one model in
     * the scope" — rather than to draw one. Drawing goes through `modelsOnOffer()`.
     *
     * @return list<ModelChoice>
     */
    public function modelScope(): array
    {
        return $this->modelScope;
    }

    /**
     * The models this session offers: the scope when there is one, the whole list otherwise.
     *
     * One home for "which models are on offer", which is what the cycling, the `/model` picker,
     * its completions and `/model <pattern>` all need — and the first draft had this rule twice,
     * once here and once in the terminal, so the mutation check found a scope that could be
     * ignored in `cycleModel()` with only the RPC test noticing. Two answers to one question is
     * the shape every other entry in `CLAUDE.md`'s traps has.
     *
     * The full list is a parameter because `Auth` lives with the modes, and it is deliberately
     * not reached from here: `Auth::apiKey()` renews an expiring token on the way past, and a
     * list being drawn is not a turn being sent.
     *
     * **A host's `get_available_models` is not narrowed**, as upstream does not narrow it: a
     * scope is what the keys in front of somebody walk through, not a restriction — `set_model`
     * takes any model a key reaches, here and upstream both.
     *
     * @param list<Model> $available
     * @return list<Model>
     */
    public function modelsOnOffer(array $available): array
    {
        return $this->modelScope === []
            ? $available
            : array_map(static fn (ModelChoice $choice): Model => $choice->model, $this->modelScope);
    }

    /**
     * The next model along, applied.
     *
     * Upstream's `cycleModel()`, and it is on the session for the reason its docblock on
     * `ModelResolver::next()` used to deny: with `--models sonnet:high,haiku:low` the answer is
     * not only *which* model but *how hard it thinks*, so the two have to be applied together.
     * ctrl+p, shift+ctrl+p and RPC's `cycle_model` all come through here.
     *
     * The list is the scope when there is one and everything a key reaches otherwise — which is
     * why the available models are a parameter: `Auth` is deliberately not asked from in here,
     * because `Auth::apiKey()` renews an expiring token on the way past and a list being drawn
     * is not a turn being sent.
     *
     * @param list<Model> $available in the order a list of them would show
     * @return ModelChoice|null null when there is nowhere to go: one model in the scope, or none
     * @throws AgentError when no key reaches the model that comes next
     */
    public function cycleModel(array $available, bool $backward = false): ?ModelChoice
    {
        $scope = [];

        foreach ($this->modelScope as $choice) {
            $scope["{$choice->model->provider}/{$choice->model->id}"] = $choice;
        }

        $next = ModelResolver::next($this->modelsOnOffer($available), $this->model(), $backward);

        if ($next === null) {
            return null;
        }

        // A scope entry's own level, and the current one outside a scope — where ctrl+p means
        // "the same question of a different model" and re-choosing the level is not part of it.
        $this->setModel($next, $scope["{$next->provider}/{$next->id}"]->thinking ?? null, persistAsDefault: false);

        return new ModelChoice($next, $this->thinkingLevel());
    }

    /**
     * Switch models, and keep the thinking level honest about the new one.
     *
     * A level the new model cannot do is not carried over: leaving `high` set on a model
     * without reasoning sends a request the provider rejects, and the person who changed
     * model would have no idea why. Dropped silently to what the model can do, because
     * they asked for the model, not for the level.
     */
    public function setModel(Model $model, ?ThinkingLevel $thinking = null, bool $persistAsDefault = false): void
    {
        // Upstream's first two lines, and they were missing: switching to a model this machine
        // has no key for used to succeed, be written into the session file as the model this
        // conversation is on, and then fail on the next turn with `Stream`'s "No API key for
        // provider" — from inside a turn, where it looks like the provider's fault. Refused here
        // instead, before anything is recorded.
        if ($this->keyFor($model) === null) {
            throw new AgentError("No API key for {$model->provider}/{$model->id}");
        }

        $previous = $this->model();
        $changed = $previous?->id !== $model->id || $previous?->provider !== $model->provider;

        $this->agent->setModel($model);

        // Written down, so `--continue` comes back to the model this conversation was being
        // had with rather than to whatever a new one would open with. Only when it actually
        // changed: `setModel()` is also how the thinking level is clamped, and a line per
        // clamp would be a file full of a model changing to itself.
        if ($changed) {
            $this->store?->appendModelChange($model->provider, $model->id);
            $this->hooks?->emit(new ModelSelectEvent($model, $previous));
        }

        // Remembered for the next run when enabled (e.g. CLI interactive mode).
        // Web UI and lightweight switches pass persistAsDefault: false so they don't overwrite settings.json.
        if ($persistAsDefault) {
            $this->settings?->setDefaultModel($model->id, $model->provider);
        }

        $this->setThinkingLevel($this->clampThinking($thinking ?? $this->thinkingLevel()), persistAsDefault: $persistAsDefault);
    }

    /**
     * $level if this model has it, otherwise the nearest one it does have.
     *
     * Was `in_array(…) ? … : Off` at both call sites, which is the same thing only when every
     * reasoning model has every level — true while the list was hardcoded and false the moment a
     * `thinkingLevelMap` can take one away. Upstream searches up and then down, so a model
     * missing `low` lands on `medium` rather than having thinking switched off.
     */
    private function clampThinking(ThinkingLevel $level): ThinkingLevel
    {
        $model = $this->model();

        return $model === null ? $level : ThinkingLevel::clampedFor($model, $level);
    }

    /**
     * The key this model would be used with, or null when there is none.
     *
     * The agent's own answer, asked the way a turn asks it: the closure `CodingAgent` wires to
     * `Auth::apiKey()`, then the one key a caller handed in. Upstream asks its model registry
     * here; pig's `AgentSession` is given a closure instead, which is the same fact from the
     * same place — and asking it means an expiring token is renewed rather than pronounced
     * missing.
     */
    public function keyFor(Model $model): ?string
    {
        $options = $this->agent->options();

        return $options->getApiKey !== null
            ? ($options->getApiKey)($model->provider) ?? $options->apiKey
            : $options->apiKey;
    }

    /**
     * Whether a prompt is in progress. Upstream's `isStreaming`, which is `_isAgentRunActive`.
     *
     * The whole prompt and not just the run in flight: the agent is not streaming during a retry's
     * sleep, an overflow summary, or the moment between a run and the queued message that carries
     * it on, and something sent in those gaps used to become a prompt of its own — which settled
     * this one early, so the "task complete" notification went out over a screen still Working.
     * Asked here, it is queued into the prompt in progress instead, as upstream queues it.
     */
    public function isStreaming(): bool
    {
        return $this->runActive;
    }

    /**
     * The signal for the turn in progress, or null between turns.
     *
     * What a hook's own waiting parks on, so escape cuts it short rather than the person
     * waiting out its timeout. `Agent::signal()`'s docblock says why it is null when idle.
     */
    public function signal(): ?AbortSignal
    {
        return $this->agent->signal();
    }

    /** @return list<mixed> the conversation, app messages included */
    public function messages(): array
    {
        return $this->agent->state->messages;
    }

    // ---- prompting ---------------------------------------------------------------

    /**
     * Send something and run until the agent is done.
     *
     * A slash command is dealt with here rather than by whoever is drawing a screen, which is
     * upstream's arrangement and was the one gap left in `agent-session.ts`' surface map. A
     * **hook's** command is code and is run; a command kept as a **file** is a stored prompt and
     * is expanded into the text that gets sent. Both used to happen in `InteractiveMode`, so both
     * worked in the terminal and nowhere else: `pig -p "/deploy"` and RPC's `prompt` handed the
     * model the line as text and left it to guess.
     *
     * The hook half goes **before** the streaming check, because a hook's command is not a
     * message and there is nothing for it to wait behind — upstream's own comment says so
     * ("Hook commands always run immediately, even during streaming") while its code throws
     * there, and pig takes the half with the reason attached, as it does elsewhere when
     * upstream disagrees with itself.
     *
     * There is no `expandSlashCommands: false` here. Upstream declares that option and nothing
     * in either tree passes it, so it would be a branch existing for a caller that does not
     * exist; a caller that ever needs to send a line beginning with a slash verbatim is what
     * decides its shape.
     *
     * @param list<ImageContent> $images
     * @throws AgentError if the agent is already working, or there is no model
     */
    public function prompt(string $text, array $images = [], string $source = 'interactive'): void
    {
        if ($this->runHookCommand($text)) {
            return;
        }

        // Between two runs of the prompt in progress — a retry sleeping, a summary being written —
        // wait it out rather than refusing: it is seconds, and what was sent goes after whatever
        // the retry was rescuing. pig's own difference: upstream refuses here as it does mid-run.
        // The screen never gets here (it asks `isStreaming()` and queues), so this is for a
        // caller without a queue — RPC's `prompt`, `pig -p` with several messages.
        if ($this->runActive && !$this->agent->state->isStreaming) {
            $this->waitForIdle();
        }

        if ($this->isStreaming()) {
            throw new AgentError('Agent is already working. Use steer() or followUp().');
        }

        if ($this->model() === null) {
            throw new AgentError('No model selected.');
        }

        // Before the stored prompt is expanded, as upstream has it: an `input` handler sees
        // `/review foo.php`, which is what was typed, and not forty lines of template.
        $input = $this->runInputHandlers($text, $images, $source, null);

        if ($input === null) {
            return;
        }

        [$text, $images] = $input;
        $text = $this->expandFileCommand($text);

        // A hook may put a note in front of the prompt. It goes in as its own user
        // message rather than being pasted onto the front of theirs, so the transcript
        // still shows what the person actually typed — and it goes in *through* the
        // prompt rather than onto the state, so the session file records it and a
        // resumed conversation still has it.
        // The handlers get the prompt options to change — upstream's `systemPromptOptions` — and
        // what they leave is this run's (`$runSystemPromptOptions`).
        $options = new SystemPromptOptions();
        $note = $this->hooks?->emitBeforeAgentStart($text, $images, $options, $this->renderSystemPrompt(...));

        // Held-back `!` commands go in before the prompt, as upstream's `prompt()` flushes them.
        $this->flushBash();

        // Upstream's `updateMessage`, put in front of the prompt (`messages.unshift(updateMessage)`):
        // the prompt sections that changed since the transcript last said them, in the transcript
        // and the session file before what the person typed.
        $update = $this->preparePromptUpdate(null, $options);
        $this->runSystemPromptOptions = $options;
        $messages = $note?->text === null || trim($note->text) === ''
            ? [new UserMessage([new TextContent($text), ...$images])]
            : [new UserMessage($note->text), new UserMessage([new TextContent($text), ...$images])];

        // Returns when the prompt has settled — after its retries, its summary and whatever was
        // queued behind it — so `bin/pig -p` exits with the answer and not with the 503 before it.
        $this->runAgentPrompt(fn () => $this->agent->prompt($update !== null ? [$update, ...$messages] : $messages));
    }

    /**
     * Run a hook's slash command, and say whether there was one.
     *
     * A handler that throws is reported as a hook error and still counts as handled: the command
     * was found and it ran, and sending the line to the model afterwards would ask it to make
     * sense of `/deploy`. Not fatal, because a command that failed is one command and the session
     * it failed in is still a session.
     */
    private function runHookCommand(string $text): bool
    {
        if ($this->hooks === null || !str_starts_with($text, '/')) {
            return false;
        }

        $name = strtok(substr($text, 1), " \t") ?: '';
        $command = $this->hooks->command($name);

        if ($command === null) {
            return false;
        }

        try {
            ($command->handler)(trim(substr($text, strlen($name) + 1)), $this->hooks->context());
        } catch (Throwable $error) {
            $this->hooks->emitError(new HookError($command->hookPath, "/{$name}", $error->getMessage()));
        }

        return true;
    }

    /**
     * A stored prompt in place of its own name, or the text unchanged.
     *
     * `SlashCommands::expand()` answers null for a line that names no file command, where
     * upstream's hands back the text it was given — so the `??` here is the translation and not
     * a fallback papering over a failure.
     */
    /**
     * What the `input` handlers made of a message, or null when one of them took it.
     * Upstream's `_runInputHandlers()`.
     *
     * @param list<ImageContent>      $images
     * @param 'steer'|'followUp'|null $streaming
     * @return array{0: string, 1: list<ImageContent>}|null
     */
    private function runInputHandlers(string $text, array $images, string $source, ?string $streaming): ?array
    {
        if ($this->hooks === null) {
            return [$text, $images];
        }

        $result = $this->hooks->emitInput(new InputEvent($text, $images, $source, $streaming));

        return match ($result->action) {
            'handled' => null,
            'transform' => [(string) $result->text, $result->images ?? $images],
            default => [$text, $images],
        };
    }

    private function expandFileCommand(string $text): string
    {
        return SlashCommands::expand($text, $this->fileCommands) ?? $text;
    }

    /**
     * Put a hook's message in the conversation.
     *
     * Three ways it can go, and the agent's state decides which:
     *
     * - **Working**: queued as a follow-up. A message between a tool call and its result is
     *   a request every provider rejects, so it waits — and `$triggerTurn` is beside the
     *   point, because a turn is already happening.
     * - **Idle, and asked to trigger a turn**: appended, then the agent runs from it. This
     *   is a hook driving the agent, which is the interesting case — a `session_start` hook
     *   that says "carry on where the last session left off" needs the turn as well as the
     *   message.
     * - **Idle, not asked**: appended and left there, for the next thing anyone says.
     *
     * Ported from upstream's `sendHookMessage()`.
     */
    public function sendHookMessage(HookMessage $message, bool $triggerTurn = false): void
    {
        // Working — which is the whole prompt, the gaps between its runs included. A message
        // queued here is carried on by `runAgentPrompt()` when the run it arrived during has
        // ended, as upstream's post-run loop does on `agent.hasQueuedMessages()`.
        if ($this->isStreaming()) {
            // Handed to the agent, which is the whole of what queuing means. This used to add the
            // text to `$this->followUps` and stop there — this session's own list of what is
            // waiting, which the agent never reads: the model never saw the message, and the
            // footer counted it as pending until somebody pressed escape.
            //
            // As a follow-up rather than steering: steering interrupts the tools that are
            // queued behind the current one, and a hook's note is not a change of mind.
            //
            // And *not* added to `$this->followUps`, which is upstream's choice too: that list is
            // what a person typed, it is what `clearQueue()` hands back to the editor, and
            // handing somebody a hook's sentence to re-send is not putting their text back.
            $this->agent->followUp($message);

            return;
        }

        $this->agent->appendMessage($message);
        $this->store?->append($message);

        foreach ($this->listeners as $listener) {
            $listener(new MessageEndEvent($message));
        }

        if (!$triggerTurn || $message->isEmpty()) {
            return;
        }

        // Asked for while the hooks hear `agent_settled`: after them, as upstream defers it —
        // its own prompt, with its own settle, once the one settling now has finished settling.
        if ($this->emittingSettled) {
            $this->deferredSettledActions[] = fn () => $this->runAgentPrompt(fn () => $this->agent->continue());

            return;
        }

        // Spawned, because the caller is a hook's handler and `sendMessage()` does not wait for a
        // turn. Busy from now rather than from when the fiber starts, as upstream's flag is set
        // before its first `await`: a prompt sent in that tick would race this one into the agent.
        $this->runActive = true;
        $this->idle ??= new Deferred();

        Async::spawn(function (): void {
            try {
                $this->runAgentPrompt(fn () => $this->agent->continue());
            } catch (Throwable $problem) {
                // Nobody awaits this fiber, so the hook that asked is told — the same channel
                // as a handler that threw. Without hooks there is no one to tell, and the throw
                // goes to the spawned future as it is.
                if ($this->hooks === null) {
                    throw $problem;
                }

                $this->hooks->emitError(new HookError('<session>', 'sendMessage', $problem->getMessage()));
            }
        });
    }

    /**
     * Write a hook's note into the session file, outside the conversation.
     *
     * Nothing happens when the session is not being saved: the whole point of one of these
     * is that it is still there next time, and there is no next time for `--no-save`.
     */
    public function appendHookEntry(string $customType, mixed $data = null): void
    {
        $this->store?->appendCustomEntry($customType, $data);
    }

    /**
     * Cut in while the agent is working.
     *
     * Delivered after the tool that is running now, which is what someone means by
     * typing "no, the other file" mid-run.
     *
     * A file command is expanded on the way to the agent and **kept as it was typed** in the
     * queue: the list is what `clearQueue()` hands back to the editor, and putting a forty-line
     * stored prompt in front of somebody who typed `/review foo.php` is not putting their text
     * back. Upstream queues the raw line at both ends, so the model there is handed the literal
     * `/review foo.php` whenever the command was typed during a turn.
     */
    public function steer(string $text, string $source = 'interactive'): void
    {
        $input = $this->runInputHandlers($text, [], $source, 'steer');

        if ($input === null) {
            return;
        }

        $text = $input[0];
        $this->steering[] = $text;
        $this->agent->steer(new UserMessage($this->expandFileCommand($text)));
    }

    /**
     * Whether queued messages go over one at a time or all together.
     *
     * Upstream's pair, and the settings write is upstream's too: `agent-session.ts` tells the
     * agent and the settings manager in the same method, so the choice survives the session it
     * was made in.
     */
    public function queueMode(): QueueMode
    {
        return $this->agent->queueMode();
    }

    public function setQueueMode(QueueMode $mode): void
    {
        $this->agent->setQueueMode($mode);
        $this->settings?->setQueueMode($mode);
    }

    /** Queue something for after the agent has finished the request it is on. `steer()` on the expansion. */
    public function followUp(string $text, string $source = 'interactive'): void
    {
        $input = $this->runInputHandlers($text, [], $source, 'followUp');

        if ($input === null) {
            return;
        }

        $text = $input[0];
        $this->followUps[] = $text;
        $this->agent->followUp(new UserMessage($this->expandFileCommand($text)));
    }

    /** @return list<string> everything waiting, steering first */
    public function queued(): array
    {
        return [...$this->steering, ...$this->followUps];
    }

    /**
     * The same, kept apart — upstream's `getSteeringMessages()` / `getFollowUpMessages()`.
     *
     * The screen labels the two differently (`Steering:` cuts in after the current tool,
     * `Follow-up:` waits for the turn to end), so it needs to know which is which.
     *
     * @return array{steering: list<string>, followUp: list<string>}
     */
    public function queuedByKind(): array
    {
        return ['steering' => $this->steering, 'followUp' => $this->followUps];
    }

    /**
     * Throw the queue away and hand it back.
     *
     * Returned rather than dropped so the editor can put the text back in front of the
     * person who typed it — losing what someone wrote because they pressed Escape is
     * the kind of thing that stops people trusting the queue at all.
     *
     * @return list<string>
     */
    public function clearQueue(): array
    {
        $queued = $this->queued();
        $this->steering = [];
        $this->followUps = [];
        $this->agent->clearAllQueues();

        return $queued;
    }

    /** Stop the prompt in progress; resolves once the session is idle. Upstream's `abort()`. */
    public function abort(): Future
    {
        // The prompt's post-run loop stops too: escape is not "this run", it is "this request",
        // and a queued message or a retry must not carry it on afterwards.
        if ($this->runActive) {
            $this->runAbortRequested = true;
        }

        // Before the agent, because neither of these has an agent to interrupt: a retry that is
        // sleeping and a summariser that is running both happen *between* runs, with the last one
        // over and the next one not started. Escape has to reach all three, and reaching them from
        // here rather than from each caller is what stops a caller reaching two and stopping.
        // Upstream's escape handler calls `abortCompaction()` itself, beside `abort()`.
        $this->abortRetry();
        $this->abortCompaction();
        $this->agent->abort();

        return $this->idleFuture();
    }

    /** Drop one copy of $text, steering first — the order it would be sent in. */
    private function dequeue(string $text): void
    {
        $at = array_search($text, $this->steering, true);

        if ($at !== false) {
            array_splice($this->steering, (int) $at, 1);

            return;
        }

        $at = array_search($text, $this->followUps, true);

        if ($at !== false) {
            array_splice($this->followUps, (int) $at, 1);
        }
    }

    // ---- running a command yourself ---------------------------------------------------

    /** Whether a `!` command is running right now. */
    public function isBashRunning(): bool
    {
        return $this->bash !== null;
    }

    /**
     * Run a command the person typed, and give them the result.
     *
     * @param bool $remember whether it joins the conversation — `!` does, `!!` does not
     * @param Closure(string): void|null $onOutput called as output arrives
     */
    public function executeBash(string $command, bool $remember = true, ?Closure $onOutput = null): BashExecution
    {
        if ($this->bash !== null) {
            throw new AgentError('A command is already running. Press esc to stop it first.');
        }

        // A hook may run it somewhere else — a container, a remote box — and hand back what
        // came of it. A hook that throws stops it here: falling back to running the command on
        // this machine is exactly what such a hook exists to prevent. Upstream's rule.
        $handled = $this->hooks?->emitUserBash(new UserBashEvent($command, !$remember, $this->cwd));

        if ($handled !== null) {
            if ($remember) {
                $this->append($handled->result);
            }

            return $handled->result;
        }

        $this->bash = new AbortController();

        try {
            // Run hands its callback the partial output as a tool result, which is what
            // its other caller wants; here the text is enough.
            $onUpdate = $onOutput === null ? null : static function (AgentToolResult $partial) use ($onOutput): void {
                $block = $partial->content[0] ?? null;
                $onOutput($block instanceof TextContent ? $block->text : '');
            };

            $run = new Run($this->cwd, $command, $onUpdate);
            $run->start();
            $exit = $run->wait($this->bash->signal, null);
            $truncation = Truncate::tail($run->output());

            $execution = new BashExecution(
                $command,
                $truncation->content,
                $run->aborted ? null : $exit,
                $run->aborted,
                $truncation->truncated,
                $run->spillPath,
            );
        } finally {
            $this->bash = null;
        }

        if ($remember) {
            $this->append($execution);
        }

        return $execution;
    }

    /** Stop the running command. Does nothing when none is. */
    public function abortBash(): void
    {
        $this->bash?->abort('Cancelled');
    }

    /**
     * Put it in the conversation — now, or once the agent has finished.
     *
     * A message added mid-run lands between a tool call and its result, and a provider
     * that sees those two separated rejects the whole request. So anything that arrives
     * while the agent is working waits for it to stop.
     */
    private function append(BashExecution $execution): void
    {
        if ($this->isStreaming()) {
            $this->pendingBash[] = $execution;

            return;
        }

        $this->agent->appendMessage($execution);

        // Appended directly rather than through a turn, so there is no message_end to
        // carry it to the file.
        $this->store?->append($execution);
    }

    /** @return list<BashExecution> what was held back, now in the conversation */
    private function flushBash(): array
    {
        $held = $this->pendingBash;
        $this->pendingBash = [];

        foreach ($held as $execution) {
            $this->agent->appendMessage($execution);
            $this->store?->append($execution);
        }

        return $held;
    }

    // ---- leaving this conversation ----------------------------------------------------

    /**
     * Throw this conversation away and start another.
     *
     * The checks live here rather than in the modes because that is what having them in one
     * mode and not the other cost: `/new` in the terminal asked the hooks first, and RPC's
     * `new_session` did not, so a hook that refused to leave a conversation worked for a
     * person and was ignored by a host. Neither of them emptied the queue, so text typed
     * into the conversation being thrown away was still waiting to be sent in the one that
     * replaced it. `goTo()` below already put its guards inside for the same reason: a rule
     * a caller has to remember is a rule the next caller will not.
     *
     * The new file is created before anything is thrown away, which upstream does the other
     * way round. If the directory is unwritable, the throw leaves this session exactly as it
     * was rather than aborted, emptied and still writing to the old file.
     *
     * Not the UI's share of the work: clearing the screen, saying "New session", and telling
     * the custom tools stay with the caller, which is where the screen and the tools are.
     *
     * @throws \Throwable when a new session file cannot be created
     */
    public function startNew(): SessionSwitch
    {
        $previous = $this->store?->path;

        // `'new'` rather than `'resume'`: a hook that refuses to leave a conversation usually
        // cares *why* it is being left, and upstream passes the same two words.
        $refusal = $this->hooks?->emitBeforeSwitch(new SessionBeforeSwitchEvent('new'));

        if ($refusal !== null && $refusal->cancel) {
            return new SessionSwitch(switched: false, previous: $previous);
        }

        // A new file, not just an empty screen. Keeping the old one would append this
        // conversation onto the last one as if they were the same, and the new session would
        // never exist as a session — which is what this used to do. Only when this session
        // was being written at all: `--no-save` means no file, and `/new` should not start
        // saving.
        $fresh = $previous === null ? null : SessionManager::create($this->cwd);

        // Unconditionally, as upstream does, because `isStreaming()` is the narrower question:
        // a retry that is sleeping is not streaming, and `abort()`'s own docblock says why it
        // has to reach both. Asked only when streaming, a `/new` pressed during those seconds
        // left the retry running — and what it woke up to rescue was a turn from the
        // conversation just walked away from, in an agent that had since been emptied.
        $this->abort()->await();
        $this->clearQueue();

        // Upstream tears the whole runtime down and builds a new one, so its hooks hear
        // `session_shutdown` with the target and then `session_start` with the previous file.
        // pig keeps the same hooks across the switch and tells them the same two things.
        $this->hooks?->emit(new SessionShutdownEvent('new', $fresh?->path));

        if ($fresh !== null) {
            $this->writeTo($fresh);
        }

        // Upstream's `_refreshFinalizedContext()` reads the new, empty file: nothing, not even the
        // prompt — `reset()` alone keeps the replayed prompt and tool baseline. The next prompt
        // declares both again, so the new file starts with them as the old one did.
        $this->agent->reset();
        $this->agent->clearMessages();
        $this->hooks?->emit(new SessionStartEvent('new', $previous));
        $this->tellSwitched('new', $previous);

        return new SessionSwitch(switched: true, previous: $previous);
    }

    /**
     * Open another conversation and carry on in it.
     *
     * The file is opened before the turn in flight is stopped, so a path that is not a
     * session costs nothing: nothing has moved when it throws. After that the order is
     * upstream's — whatever was in flight belongs to the conversation being left, and so
     * does whatever was queued, so both end here rather than crossing over.
     *
     * Aborted rather than refused, which is also upstream's choice: someone who asks to
     * switch has decided. The terminal never had to abort because `/resume` is unreachable
     * while streaming; a host has no such guard, and that asymmetry is exactly why this is
     * one method now.
     *
     * @throws \Throwable when there is no such session file
     */
    public function switchTo(string $path): SessionSwitch
    {
        $previous = $this->store?->path;

        $refusal = $this->hooks?->emitBeforeSwitch(new SessionBeforeSwitchEvent('resume', $path));

        if ($refusal !== null && $refusal->cancel) {
            return new SessionSwitch(switched: false, previous: $previous);
        }

        $opened = SessionManager::open($path);

        // Unconditional, for `startNew()`'s reason: a sleeping retry is not streaming either.
        $this->abort()->await();
        $this->clearQueue();
        $this->hooks?->emit(new SessionShutdownEvent('resume', $opened->path));

        // The file that was opened is the one written to from here on. Without this the
        // conversation on screen is the resumed one while everything said next is appended
        // to the file pig started with — two files, neither of them what happened.
        if ($previous !== null) {
            $this->writeTo($opened);
        }

        $this->restore($opened->messages());

        // And what it was being had with. Nothing was typed here — resuming takes no model —
        // so the file wins outright, which is what "resume" means.
        $this->restoreSettings();
        $this->hooks?->emit(new SessionStartEvent('resume', $previous));
        $this->tellSwitched('resume', $previous);

        // The messages somebody would count: a system message is prompt state, not something said.
        $said = array_filter($opened->messages(), static fn (mixed $message): bool => !$message instanceof SystemMessage);

        return new SessionSwitch(switched: true, previous: $previous, messages: count($said));
    }

    /**
     * Go back to an earlier point in the conversation.
     *
     * The session file keeps every branch, so this is a move rather than a deletion: the
     * road not taken is still there, and going back to it is the same call again.
     *
     * **Going back to something somebody said goes back to before it.** The leaf lands on that
     * message's parent, so the message leaves the conversation, and its words come back in
     * `TreeJump::$editorText` for the caller to put in the prompt — which is what going back to a
     * question is for: asking it differently. Upstream's `navigateTree()` does this and pig did
     * not: it moved the leaf *onto* the message, so the old wording stayed in the conversation and
     * the only way to re-ask was to type it again. Any other point — an answer, a summary, a
     * command's output — is where the leaf lands, as before.
     *
     * @throws AgentError when there is no session on disk, no such point, or a summary was asked
     *                    for with no model to write it
     */
    public function goTo(
        ?string $entryId,
        bool $summarise = false,
        ?string $instructions = null,
        ?AbortSignal $signal = null,
    ): TreeJump {
        if ($this->store === null) {
            throw new AgentError('This session is not being saved, so there is nowhere to go back to.');
        }

        if ($this->isStreaming()) {
            throw new AgentError('Agent is working. Let it finish, or press esc, then go back.');
        }

        $oldLeaf = $this->store->leaf();

        [$target, $editorText] = $this->target($entryId);

        // Already here: nothing to leave behind, nothing to summarise, and no hook to bother with
        // it. Upstream's first guard, and `moved: false` with `aborted: false` is the answer that
        // says so — a caller must not read it as the summary having been called off.
        if ($target === $oldLeaf && $editorText === null) {
            return new TreeJump(moved: false);
        }

        // Refused rather than moved without one: somebody who asked for the branch to be written
        // down and got the move without the summary has lost the branch. Upstream throws here too.
        if ($summarise && $this->model() === null) {
            throw new AgentError('No model to write the summary with.');
        }

        // Read before the move, obviously, and read even when nothing asked for a summary:
        // a hook is told what is being left behind whether or not anyone is writing it down.
        $leaving = $this->store->abandoning($entryId);
        $answer = $this->hooks?->emitBeforeTree(
            new SessionBeforeTreeEvent($entryId, $oldLeaf, $leaving, $summarise),
        );

        if ($answer !== null && $answer->cancel) {
            throw new AgentError('A hook stopped the jump.');
        }

        $summary = $this->branchSummary($answer?->summary, $summarise, $leaving, $oldLeaf, $instructions, $signal);

        // Cancelled part-way through summarising: nothing has moved, and the caller is
        // meant to put the person back where they were rather than jump without the
        // summary they asked for.
        if ($summary === false) {
            return new TreeJump(moved: false, aborted: true);
        }

        $this->store->goTo($target);
        $this->restore($this->store->messages());

        // After the move, so it lands on the branch being joined — which is the whole
        // point: it is context for carrying on here, not a note on the branch it describes.
        if ($summary !== null) {
            $this->agent->appendMessage($summary);
            $this->store->append($summary);
        }

        $this->hooks?->emit(new SessionTreeEvent($this->store->leaf(), $oldLeaf, $summary));

        return new TreeJump(moved: true, summary: $summary, editorText: $editorText);
    }

    /**
     * Where the leaf lands for a chosen point, and what goes back into the prompt.
     *
     * A message somebody said — theirs, or a hook's — resolves to the point *before* it, because
     * going back to a question means being able to ask it differently: the message leaves the
     * conversation and its words are handed back. Everything else resolves to itself.
     *
     * A hook's message is treated as upstream treats it, which is the same way: nothing is sent
     * from here, so the text is offered for editing rather than re-sent as the person's own — and
     * dropping the message while keeping nothing of it would lose it for no reason.
     *
     * An id this file has not got is passed through untouched, so `SessionManager::goTo()` is the
     * one place that refuses it by name.
     *
     * @return array{0: string|null, 1: string|null}
     */
    private function target(?string $entryId): array
    {
        $entry = $entryId === null ? null : $this->store?->entry($entryId);

        if ($entry === null) {
            return [$entryId, null];
        }

        $message = $entry['message'];

        if (!$message instanceof UserMessage && !$message instanceof HookMessage) {
            return [$entryId, null];
        }

        return [
            // A root's parent is written as its own id in a pi file, and the point before a root
            // is the empty conversation.
            $entry['parent'] === $entryId ? null : $entry['parent'],
            $message instanceof UserMessage ? self::textOf($message) : $message->toText(),
        ];
    }

    /**
     * The summary to write down, if any.
     *
     * @param list<mixed> $leaving
     * @return BranchSummary|null|false false when summarising was called off
     */
    private function branchSummary(
        ?string $fromHook,
        bool $summarise,
        array $leaving,
        ?string $oldLeaf,
        ?string $instructions,
        ?AbortSignal $signal,
    ): BranchSummary|null|false {
        // A hook that wrote one has done the work, and the model is not asked. Its file
        // lists are pig's own reading of the branch rather than the hook's claim about it:
        // the prose is the hook's, the facts are not its to get wrong.
        //
        // Only when a summary was asked for, which is upstream's condition and was missing here:
        // the hook is *told* whether anyone wants one (`SessionBeforeTreeEvent`'s last argument),
        // so one that answers with prose anyway has misread the event, and writing that prose into
        // the conversation is a branch summary nobody asked for.
        if ($fromHook !== null && $summarise) {
            [$read, $modified] = Compaction::files($leaving);

            return new BranchSummary($fromHook, $read, $modified, $oldLeaf, fromHook: true);
        }

        $model = $this->model();

        if (!$summarise || $leaving === [] || $model === null) {
            return null;
        }

        // Routed first: a summary sizes its input and output from the model it gets.
        $model = $this->directModel($model, $signal)->model;

        [$messages, $read, $modified] = BranchSummarization::prepare(
            $leaving,
            BranchSummarization::budget($model->contextWindow, $this->reserveTokens()),
        );

        if ($messages === []) {
            return null;
        }

        $text = $this->summarise(
            $model,
            BranchSummarization::request($messages, $instructions),
            $signal,
            BranchSummarization::MAX_TOKENS,
            source: 'branchSummary',
        );

        return $text === null ? false : new BranchSummary($text, $read, $modified, $oldLeaf);
    }

    // ---- making room -------------------------------------------------------------------

    /** What the last completed turn carried, or 0 when nothing has been answered yet. */
    public function contextTokens(): int
    {
        $usage = Compaction::lastUsage($this->messages());

        return $usage === null ? 0 : Compaction::contextTokens($usage);
    }

    /** Whether the next turn would be pushing against the model's window. */
    public function shouldCompact(): bool
    {
        $model = $this->limitsModel();

        if ($model === null || !($this->settings?->compactionEnabled() ?? true)) {
            return false;
        }

        return Compaction::shouldCompact($this->contextTokens(), $model->contextWindow, $this->reserveTokens());
    }

    private function reserveTokens(): int
    {
        return $this->settings?->compactionReserveTokens(Compaction::RESERVE_TOKENS) ?? Compaction::RESERVE_TOKENS;
    }

    // ---- one prompt, from its first run to `agent_settled` -------------------------------

    /**
     * Run a prompt to the end: the run, then whatever it leaves to do, then `agent_settled`, once.
     *
     * Upstream's `_runAgentPrompt()`, and the shape is upstream's:
     *
     *     agent.prompt(...)
     *     while not aborted:
     *         if _handlePostAgentRun():          retry, overflow summary, or a queued message
     *             agent.continue(); continue
     *         if not _runBeforeSettleBoundary(): break
     *         agent.continue()
     *     finally: flush held-back commands; _emitAgentSettled()
     *
     * Everything happens in the caller's fiber, after `$firstRun` has returned — not in the run's
     * own event fan-out, which is where pig used to decide it, spawning the retry and settling on
     * every `AgentEndEvent` that did not start one. That made `agent_settled` a per-*run* event:
     * a hook's turn started from `agent_end`, a message queued as the run ended, a prompt sent
     * during a retry's sleep — each settled the prompt and then ran more of it, so the system
     * notification said "task complete" over a screen still saying Working.
     *
     * @param Closure(): void $firstRun `agent->prompt(...)` or `agent->continue()`
     */
    private function runAgentPrompt(Closure $firstRun): void
    {
        $this->runAbortRequested = false;
        // Compaction before the prompt may have scheduled a retry; the new prompt replaces it.
        $this->failedResponse = null;
        $this->recordSelection();
        // "The run records the loadout in the transcript; restored tools that did not register by
        // now are dropped, so a tool that never registers does not stay pending."
        $this->loadout?->clearPending();
        $this->runActive = true;
        $this->idle ??= new Deferred();

        try {
            $firstRun();

            while (!$this->runAbortRequested) {
                if ($this->handlePostAgentRun()) {
                    if ($this->runAbortRequested) {
                        break;
                    }

                    $this->agent->continue();

                    continue;
                }

                if ($this->runAbortRequested || !$this->runBeforeSettleBoundary()) {
                    break;
                }

                if ($this->runAbortRequested) {
                    break;
                }

                $this->agent->continue();
            }
        } finally {
            if ($this->runAbortRequested) {
                $this->finishCancelledRetry();
            }

            $this->failedResponse = null;
            $this->runSystemPromptOptions = null;
            $this->flushBash();
            $this->emitAgentSettled();
        }
    }

    /**
     * The run is over. Is there more of this prompt to run? Upstream's `_handlePostAgentRun()`, in
     * its order.
     *
     * Three things can carry a prompt on, told apart by how the run ended. A transient failure
     * (`isRetryableError()`) means try again, after a sleep. "Prompt is too long" means the request
     * itself was the problem — never retryable, it is summarised instead. And a message queued
     * after the loop last looked — by an `agent_end` handler, or a key pressed as the run was
     * ending — is the rest of the same request.
     *
     * @return bool true to `continue()` the agent
     */
    private function handlePostAgentRun(): bool
    {
        if ($this->runAbortRequested) {
            $this->finishCancelledRetry();

            return false;
        }

        $messages = $this->messages();
        $last = $messages === [] ? null : $messages[count($messages) - 1];

        if (!$last instanceof AssistantMessage) {
            return $this->agent->hasQueuedMessages();
        }

        if ($this->isRetryableError($last) && $this->prepareRetry($last)) {
            if ($this->runAbortRequested) {
                $this->finishCancelledRetry();
            }

            $this->failedResponse = $last;

            return !$this->runAbortRequested;
        }

        if ($this->runAbortRequested) {
            $this->finishCancelledRetry();

            return false;
        }

        // The retrying's failure: the last attempt failed in a way that is not retried again, or
        // the budget ran out.
        if ($last->stopReason === StopReason::Error && $this->attempt > 0) {
            $attempts = $this->attempt;
            $this->attempt = 0;
            $this->announce(new RetryEndEvent(false, $attempts, $last->errorMessage));
        }

        // Upstream's `_checkCompaction()` for the overflow case.
        if (Overflow::happened($last, $this->modelForMessage($last)?->contextWindow)) {
            return $this->compactForOverflow($last) && !$this->runAbortRequested;
        }

        // The loop drains both queues before `agent_end`; anything queued after that needs a
        // fresh run, and it is still this prompt's.
        return !$this->runAbortRequested && $this->agent->hasQueuedMessages();
    }

    /**
     * Upstream's `_isRetryableError()`: "Context overflow is handled by compaction, not retry", and
     * otherwise `isRetryableAssistantError()` — the error's text against upstream's pattern lists.
     */
    /**
     * Whether the post-run step will send this run's failed turn again — upstream's
     * `_willRetryAfterAgentEnd()`: not after escape, not with retries off or used up, and otherwise
     * whether the run's last assistant message is a retryable error.
     */
    private function willRetryAfterAgentEnd(AgentEndEvent $event): bool
    {
        if ($this->runAbortRequested) {
            return false;
        }

        $settings = $this->retrySettings();

        if (!$settings['enabled'] || $this->attempt >= $settings['maxRetries']) {
            return false;
        }

        for ($i = count($event->messages) - 1; $i >= 0; $i--) {
            if ($event->messages[$i] instanceof AssistantMessage) {
                return $this->isRetryableError($event->messages[$i]);
            }
        }

        return false;
    }

    private function isRetryableError(AssistantMessage $message): bool
    {
        if (Overflow::happened($message, $this->modelForMessage($message)?->contextWindow)) {
            return false;
        }

        return Retry::isRetryableAssistantError($message);
    }

    /**
     * Wait, then say to send the same turn again. Upstream's `_prepareRetry()`, line for line.
     *
     * The settings decide (`retry.enabled`, `retry.maxRetries`, `retry.baseDelayMs`,
     * `retry.maxAgentDelayMs`): an attempt past the budget is not counted, so the post-run step can
     * report the attempts that were made; the wait is `retryDelayMs()` — doubling from the base,
     * capped. The failed message is taken off the agent's state before the wait (upstream's
     * `_omitRecoveryAttempt()`) and stays in the session file, because it happened.
     *
     * @return bool true when the sleep ran out and the turn should go again
     */
    private function prepareRetry(AssistantMessage $failed): bool
    {
        $settings = $this->retrySettings();

        if (!$settings['enabled']) {
            return false;
        }

        $this->attempt++;

        if ($this->attempt > $settings['maxRetries']) {
            // "Preserve the completed attempt count so post-run handling can emit the final failure."
            $this->attempt--;

            return false;
        }

        $delayMs = Retry::retryDelayMs($settings['baseDelayMs'], $this->attempt, $settings['maxAgentDelayMs']);

        $this->announce(new RetryStartEvent(
            $this->attempt,
            $settings['maxRetries'],
            $delayMs / 1000,
            $failed->errorMessage !== null && $failed->errorMessage !== '' ? $failed->errorMessage : 'Unknown error',
        ));

        $this->dropLastAssistantMessage();

        // Wait with exponential backoff (abortable).
        $this->retrying = new AbortController();

        try {
            if (!$this->sleep($delayMs / 1000, $this->retrying->signal)) {
                // "Aborted during sleep - emit end event so UI can clean up".
                $this->finishCancelledRetry();

                return false;
            }
        } finally {
            $this->retrying = null;
        }

        return true;
    }

    /**
     * Upstream's `_finishCancelledRetry()`: whoever drew "Retrying (2/3)" hears that it is over.
     */
    private function finishCancelledRetry(): void
    {
        if ($this->attempt === 0) {
            return;
        }

        $attempts = $this->attempt;
        $this->attempt = 0;
        $this->announce(new RetryEndEvent(false, $attempts, 'Retry cancelled'));
    }

    /**
     * Upstream's `getRetrySettings()`, with its defaults when there is no settings file.
     *
     * @return array{enabled: bool, maxRetries: int, baseDelayMs: float, maxAgentDelayMs: float}
     */
    private function retrySettings(): array
    {
        return $this->settings?->retrySettings()
            ?? ['enabled' => true, 'maxRetries' => 3, 'baseDelayMs' => 2000.0, 'maxAgentDelayMs' => (float) Retry::DEFAULT_MAX_AGENT_RETRY_DELAY_MS];
    }

    /**
     * Upstream's `buildRequestOptions()` (`createAgentSession()`), which every request of the agent
     * goes through: `timeoutMs` (the request's, else `retry.provider.timeoutMs`, else
     * `httpIdleTimeoutMs` — 0 meaning `2147483647`), `maxRetries` (the request's, else
     * `retry.provider.maxRetries`), `maxRetryDelayMs` (the request's, else
     * `retry.provider.maxRetryDelayMs`), and the request's headers through the
     * `before_provider_headers` handlers when there are any (`transformHeaders`).
     *
     * Not ported: upstream's provider attribution headers (`mergeProviderAttributionHeaders()`,
     * OpenRouter/NVIDIA/Cloudflare/OpenCode identification sent behind its install-telemetry
     * setting) and `websocketConnectTimeoutMs` (no WebSocket transport).
     */
    private function buildRequestOptions(SimpleStreamOptions $options): SimpleStreamOptions
    {
        $provider = $this->settings?->providerRetrySettings() ?? ['timeoutMs' => null, 'maxRetries' => null, 'maxRetryDelayMs' => 60_000];
        $httpIdleTimeoutMs = $this->settings?->httpIdleTimeoutMs() ?? 300_000;
        $effectiveTimeoutMs = $httpIdleTimeoutMs === 0 ? 2_147_483_647 : $httpIdleTimeoutMs;
        $headers = $options->headers;

        if ($this->hooks?->hasHandlers('before_provider_headers') === true) {
            $headers = $this->hooks->emitBeforeProviderHeaders($headers ?? []);
        }

        return new SimpleStreamOptions(...[
            ...$options->baseArgs(),
            'reasoning' => $options->reasoning,
            'toolChoice' => $options->toolChoice,
            'timeoutMs' => $options->timeoutMs ?? $provider['timeoutMs'] ?? $effectiveTimeoutMs,
            'maxRetries' => $options->maxRetries ?? $provider['maxRetries'],
            'maxRetryDelayMs' => $options->maxRetryDelayMs ?? $provider['maxRetryDelayMs'],
            'headers' => $headers,
        ]);
    }

    /**
     * Summarise, then say to send the same turn again.
     *
     * The turn failed because the conversation outgrew the window, so the summary is the fix
     * and the retry is the point of doing it. A summary that fails or is cancelled ends it —
     * sending the same oversized request again would fail the same way.
     *
     * @return bool true when there is a summary to carry on from
     */
    private function compactForOverflow(AssistantMessage $failed): bool
    {
        $error = $failed->errorMessage ?? 'The conversation outgrew the context window.';

        $this->announce(new AutoCompactionStartEvent($error));
        $this->dropLastAssistantMessage();

        try {
            $summary = $this->compactNow(null, null, 'overflow');
        } catch (Throwable $problem) {
            $this->announce(new AutoCompactionEndEvent(false, false, null, $problem->getMessage()));

            return false;
        }

        if ($summary === null) {
            $this->announce(new AutoCompactionEndEvent(false, false, null, 'Summarising was cancelled.'));

            return false;
        }

        $this->announce(new AutoCompactionEndEvent(true, true, $summary));

        return true;
    }

    /**
     * Take the failed turn off the agent's state, leaving it in the session file — and write
     * down that it was taken off.
     *
     * The model must not be shown its own error as though it were part of the conversation:
     * the next request would carry "Anthropic returned 503" in the transcript, and the model
     * would try to make sense of it. The `context_edit` is upstream's `_omitRecoveryAttempt()`
     * and the half pig was missing: without it the message came back into the agent's state on
     * the next `--resume`, which is the one time nobody is there to see it being retried.
     */
    private function dropLastAssistantMessage(): void
    {
        $messages = $this->agent->state->messages;
        $last = $messages === [] ? null : $messages[count($messages) - 1];

        if (!$last instanceof AssistantMessage) {
            return;
        }

        $this->agent->replaceMessages(array_slice($messages, 0, -1));

        // The same object `append()` was handed on `message_end`, so identity finds it. A
        // failed turn in a session that was never written has nothing to edit, which is fine:
        // there is no file for a resume to put it back from.
        $entryId = $this->store?->entryOf($last);

        if ($entryId !== null) {
            $this->store?->appendContextEdit($entryId, null);
        }
    }

    /**
     * Wait, unless somebody says not to.
     *
     * `Async::delay()` cannot be interrupted, and eight seconds that escape cannot reach is
     * eight seconds of a terminal that will not answer. So: a timer and an abort listener
     * racing to complete the same `Deferred`, whichever gets there first. The timer is
     * cancelled on an abort rather than left to fire into nothing, because a pending timer
     * keeps `Loop::isIdle()` false and `bin/pig` would not exit.
     *
     * @return bool false if it was interrupted
     */
    private function sleep(float $seconds, AbortSignal $signal): bool
    {
        if ($signal->aborted()) {
            return false;
        }

        $done = new Deferred();
        $timer = Loop::get()->delay($seconds, static function () use ($done): void {
            if (!$done->isComplete()) {
                $done->complete(true);
            }
        });

        $listener = $signal->onAbort(static function () use ($done, $timer): void {
            Loop::get()->cancel($timer);

            if (!$done->isComplete()) {
                $done->complete(false);
            }
        });

        try {
            return $done->future->await() === true;
        } finally {
            $signal->removeListener($listener);
        }
    }

    /**
     * Whether a retry is being waited out right now.
     *
     * The controller, which exists from the `RetryStartEvent` to the end of the sleep — upstream's
     * `_retryAbortController`. A summarisation is not a retry and does not make this true.
     */
    public function isRetrying(): bool
    {
        return $this->retrying !== null;
    }

    /**
     * Stop retrying. Upstream's `abortRetry()`.
     *
     * Safe to call when nothing is being retried, because that is how `abort()` calls it:
     * escape means stop, and whether there was a sleep to interrupt is not the caller's
     * business. The sleeping `prepareRetry()` wakes, says the retrying was cancelled, and the
     * prompt settles — or carries on with what was queued, if only the retry was called off.
     */
    public function abortRetry(): void
    {
        $this->retrying?->abort();
    }

    /** Resolves when the session is next idle — at once, if it is. Upstream's `waitForIdle()`. */
    private function idleFuture(): Future
    {
        if ($this->isIdle()) {
            return Future::complete(null);
        }

        return ($this->idle ??= new Deferred())->future;
    }

    private function waitForIdle(): void
    {
        $this->idleFuture()->await();
    }

    /**
     * Upstream's `_runBeforeSettleBoundary()`: with nobody listening, the prompt carries on only
     * for what is queued; with handlers, they are asked (`HookRunner::emitBoundary()`), what they
     * drafted is appended, and the prompt carries on if they said so or something is queued —
     * unless the context as it then stands could not be sent, which is reported and refused.
     *
     * @return bool true to `continue()` the agent
     */
    private function runBeforeSettleBoundary(): bool
    {
        if ($this->hooks === null || !$this->hooks->listensTo('agent_before_settle')) {
            return $this->agent->hasQueuedMessages();
        }

        $result = $this->hooks->emitBoundary(
            fn (array $entries, bool $continue, BoundaryContextPreview $context): AgentBeforeSettleEvent => new AgentBeforeSettleEvent($entries, $continue, $context, $this->lastOutcome),
            fn (array $entries): BoundaryContextPreview => $this->buildBoundaryContext($entries),
        );

        $this->commitBoundaryDrafts($result->entries);

        if ($this->runAbortRequested) {
            return false;
        }

        $final = $this->buildBoundaryContext([]);
        $shouldContinue = $result->continue || $this->agent->hasQueuedMessages();

        if ($shouldContinue && !$final->canContinue) {
            if ($result->continue) {
                $this->hooks->emitError(new HookError('<boundary>', 'agent_before_settle', 'agent_before_settle requested continuation without runnable model context'));
            }

            return false;
        }

        return $shouldContinue;
    }

    /**
     * Upstream's `_buildBoundaryContext()`: the session with the drafts applied, as the model
     * would be sent it. Against a copy of the store (`SessionManager::preview()`), so a draft that
     * cannot be applied — an edit of an entry that is not there — throws here, into
     * `emitBoundary()`'s report, and changes nothing.
     *
     * @param list<SessionBoundaryDraft> $drafts
     */
    private function buildBoundaryContext(array $drafts): BoundaryContextPreview
    {
        $preview = $this->store?->preview() ?? SessionManager::previewOf($this->cwd, $this->messages());
        $this->applyBoundaryDrafts($preview, $drafts);
        $messages = $preview->messages();
        $llm = CodingAgent::toLlm($messages);
        $endsOnAssistant = $llm !== [] && $llm[count($llm) - 1] instanceof AssistantMessage;
        $hasConversation = false;

        foreach ($llm as $message) {
            if (!$message instanceof SystemMessage) {
                $hasConversation = true;

                break;
            }
        }

        return new BoundaryContextPreview(
            $preview->branch(),
            $messages,
            $llm,
            $this->agent->peekQueuedMessages(),
            ($hasConversation && !$endsOnAssistant) || ($endsOnAssistant && $this->agent->hasQueuedMessages()),
        );
    }

    /**
     * Upstream's `_applyBoundaryDrafts()`, against the store or a preview of it.
     *
     * @param list<SessionBoundaryDraft> $drafts
     * @return list<HookMessage> the messages among them, for the agent's own state
     */
    private function applyBoundaryDrafts(SessionManager $manager, array $drafts): array
    {
        $messages = [];

        foreach ($drafts as $draft) {
            if ($draft instanceof CustomEntryDraft) {
                $manager->appendCustomEntry($draft->customType, $draft->data);
            } elseif ($draft instanceof CustomMessageDraft) {
                $message = new HookMessage(
                    $draft->customType,
                    is_string($draft->content) ? [new TextContent($draft->content)] : $draft->content,
                    $draft->display,
                    $draft->details,
                );
                $manager->append($message);
                $messages[] = $message;
            } elseif ($draft instanceof ContextEditDraft) {
                $manager->appendContextEdit($draft->targetId, $draft->replacement);
            } elseif ($draft instanceof CompactionDraft) {
                $current = $manager->messages();
                $tokensBefore = 0;

                foreach ($current as $message) {
                    $tokensBefore += Compaction::estimateTokens($message);
                }

                $manager->append(new CompactionSummary(
                    $draft->summary,
                    tokensBefore: $tokensBefore,
                    firstKeptEntryId: $draft->firstKeptEntryId,
                    fromHook: true,
                    systemMessage: Transcript::getCurrentSystemMessage($current),
                ));
            } else {
                throw new AgentError('Not a boundary draft: ' . get_debug_type($draft));
            }
        }

        return $messages;
    }

    /**
     * Upstream's `_commitBoundaryDrafts()`: appended to the store for good, and the agent's state
     * brought up to what the store now projects — a context edit or a compaction changes what the
     * model is shown, which `restore()` is also what does on a resume.
     *
     * @param list<SessionBoundaryDraft> $drafts
     */
    private function commitBoundaryDrafts(array $drafts): void
    {
        if ($drafts === []) {
            return;
        }

        if ($this->store === null) {
            // Nothing is written down, so only what reaches the agent's state exists: its messages.
            foreach ($this->applyBoundaryDrafts(SessionManager::previewOf($this->cwd, []), $drafts) as $message) {
                $this->agent->appendMessage($message);
                $this->announce(new MessageEndEvent($message));
            }

            return;
        }

        $messages = $this->applyBoundaryDrafts($this->store, $drafts);
        $this->agent->replaceMessages($this->store->messages());

        foreach ($messages as $message) {
            $this->announce(new MessageEndEvent($message));
        }
    }

    /**
     * The prompt is over: tell the hooks, run what they asked for, release whoever is waiting.
     * Upstream's `_emitAgentSettled()`.
     *
     * The hooks first, as upstream emits before the idle wait resolves, so a `-p` that exits when
     * `prompt()` returns has already let the notification hook run. Reached exactly once per
     * prompt, from `runAgentPrompt()`'s `finally` — whichever way the prompt ended — and that is
     * the property `agent_settled` promises: final, and once.
     */
    private function emitAgentSettled(): void
    {
        $this->runActive = false;
        $this->emittingSettled = true;
        $aborted = $this->runAbortRequested;

        try {
            $this->hooks?->emit(new HookAgentSettled($this->messages(), $aborted));
            $this->announce(new AgentSettledEvent($aborted));
        } finally {
            $this->emittingSettled = false;
        }

        $deferred = $this->deferredSettledActions;
        $this->deferredSettledActions = [];

        try {
            foreach ($deferred as $action) {
                $action();
            }
        } finally {
            $this->resolveIdleWaitIfIdle();
        }
    }

    /** Upstream's `_resolveIdleWaitIfIdle()`, plus the quit an extension asked for while busy. */
    private function resolveIdleWaitIfIdle(): void
    {
        if (!$this->isIdle()) {
            return;
        }

        $waiting = $this->idle;
        $this->idle = null;

        if ($waiting !== null && !$waiting->isComplete()) {
            $waiting->complete(null);
        }

        // A quit an extension asked for while this was running. After the waiter is released, so
        // a `-p` exiting on `prompt()`'s return has already had its answer.
        if ($this->shutdownRequested && $this->onShutdown !== null) {
            $this->shutdownRequested = false;
            ($this->onShutdown)();
        }
    }

    /**
     * Summarise the older half of the conversation and carry on from the summary.
     *
     * The summary is a message like any other, so it is appended to the file and the
     * messages it replaced are not removed from it — a session log is a record of what
     * happened, and what happened is that these messages were said and then summarised.
     * Replaying it puts the conversation back the way compaction left it.
     *
     * @param string|null $instructions what to pay particular attention to, from `/compact foo`
     * @return CompactionSummary|null null when it was called off part-way
     * @throws AgentError if the agent is working, there is no model, there is nothing to
     *                    compact, or the model fails
     */
    public function compact(?string $instructions = null, ?AbortSignal $signal = null, string $reason = 'manual'): ?CompactionSummary
    {
        if ($this->isStreaming()) {
            throw new AgentError('Agent is working. Let it finish, or press esc, then compact.');
        }

        return $this->compactNow($instructions, $signal, $reason);
    }

    /**
     * `compact()` without the "is a prompt in progress" check, for the one caller that is the
     * prompt in progress: the overflow summary between a failed run and the one that retries it.
     */
    private function compactNow(?string $instructions, ?AbortSignal $signal, string $reason): ?CompactionSummary
    {

        // One controller for both doors, so `isCompacting()` and `abortCompaction()` answer for a
        // summarisation whoever started it. A signal the caller brought is *forwarded* into it
        // rather than carried alongside: what goes downstream then has one `aborted()` to ask, and
        // pig has no combinator for two — see the note on `Future` in CLAUDE.md.
        $this->compacting = new AbortController();

        if ($signal?->aborted() === true) {
            $this->compacting->abort('Cancelled');
        }

        $listener = $signal?->onAbort(fn () => $this->compacting?->abort('Cancelled'));

        $this->hookCancelledCompaction = false;

        try {
            $summary = $this->summariseAndSwapIn($instructions, $this->compacting->signal, $reason);
        } catch (Throwable $problem) {
            // Upstream's `session_compact_failed`, beside `session_compact` so a hook reacting to
            // one is not left guessing about the other. A hook's own cancel is an abort, as
            // escape is: nothing went wrong, somebody decided.
            $this->hooks?->emit(new SessionCompactFailedEvent(
                $reason,
                aborted: $this->hookCancelledCompaction,
                errorMessage: $this->hookCancelledCompaction ? null : $problem->getMessage(),
                willRetry: $reason === 'overflow',
            ));

            throw $problem;
        } finally {
            $this->compacting = null;

            if ($listener !== null) {
                $signal?->removeListener($listener);
            }

            // A summary is part of "busy" (`isIdle()`), so its end can be the moment somebody
            // waiting on `abort()` is let go. Inside a prompt this does nothing: still busy.
            $this->resolveIdleWaitIfIdle();
        }

        if ($summary === null) {
            $this->hooks?->emit(new SessionCompactFailedEvent($reason, aborted: true, willRetry: $reason === 'overflow'));
        }

        return $summary;
    }

    /** Set when the last compaction was stopped by a `session_before_compact` handler, which is an abort and not an error. */
    private bool $hookCancelledCompaction = false;

    /** Whether a summariser is running right now, from either door. */
    public function isCompacting(): bool
    {
        return $this->compacting !== null;
    }

    /**
     * Stop the summariser. Does nothing when none is running.
     *
     * Upstream's `abortCompaction()`, which its escape handler calls beside `abort()`; here
     * `abort()` calls this itself, so one call reaches everything escape has to stop.
     */
    public function abortCompaction(): void
    {
        $this->compacting?->abort('Cancelled');
    }

    /** The body of `compact()`, with the signal already settled. */
    private function summariseAndSwapIn(?string $instructions, AbortSignal $signal, string $reason = 'manual'): ?CompactionSummary
    {
        $model = $this->model();

        if ($model === null) {
            throw new AgentError('No model selected.');
        }

        // Routed first: a summary sizes its input and output from the model it gets.
        $direct = $this->directModel($model, $signal);
        $model = $direct->model;

        $messages = $this->messages();

        if (($messages[count($messages) - 1] ?? null) instanceof CompactionSummary) {
            throw new AgentError('Already compacted');
        }

        $cut = Compaction::cutPoint(
            $messages,
            $this->settings?->compactionKeepRecentTokens(Compaction::KEEP_RECENT_TOKENS)
                ?? Compaction::KEEP_RECENT_TOKENS,
        );

        // Upstream's wording, because it is the wording someone will search for. The
        // conversation being smaller than the recent window compaction always keeps is
        // the usual reason, and the footer does not show it — that percentage is the
        // provider's count of a whole request, tool schemas and system prompt included.
        if ($cut <= 0) {
            throw new AgentError('Nothing to compact (session too small)');
        }

        // Upstream's `getMessagesFromProjectedEntryForCompaction()`: "System messages are prompt
        // state, not conversation; the compaction entry carries their replay." So the summariser
        // never reads the prompt, the kept part loses its system messages, and the state they all
        // replay to goes on the compaction (`systemMessage`), in front of the summary.
        $notSystem = static fn (mixed $message): bool => !$message instanceof SystemMessage;
        $older = array_values(array_filter(array_slice($messages, 0, $cut), $notSystem));
        $kept = array_values(array_filter(array_slice($messages, $cut), $notSystem));

        // Upstream's `prepareCompaction()` answers undefined when there is nothing to summarise —
        // which, with the prompt leading the transcript, is a cut that leaves only system messages
        // behind it.
        if ($older === []) {
            throw new AgentError('Nothing to compact (session too small)');
        }
        $compactedAt = Timestamp::nowMs();
        $systemState = Transcript::getCurrentSystemMessage($messages);
        // pi's `appendCompaction()`: `{...systemMessage, timestamp: <the entry's>}`.
        $systemMessage = $systemState === null ? null : new SystemMessage(
            $systemState->content,
            $systemState->sections,
            $systemState->toolsAdded,
            $systemState->toolsRemoved,
            $compactedAt,
        );
        [$read, $modified] = Compaction::files($older);
        $request = Compaction::request($older, Compaction::previousSummary($older), $instructions);

        $answer = $this->hooks?->emitBeforeCompact(
            new SessionBeforeCompactEvent($older, $request, $instructions, $signal),
        );

        if ($answer !== null && $answer->cancel) {
            $this->hookCancelledCompaction = true;

            throw new AgentError('A hook stopped the compaction.');
        }

        // Where the kept part starts, as an entry id, which is what the file records and
        // what replaying it uses. Asked of the store rather than worked out from `$cut`:
        // the cut is an index into the resolved conversation and the file is a tree of
        // entries, and those stop being the same numbering the moment one compaction has
        // already happened.
        $firstKept = $this->store?->entryAt($cut);

        // A hook that supplied its own summary has done the work, so the model is not
        // asked. Where the kept part starts is still the session's, not the hook's: it is
        // what the file needs to replay correctly, and it is not the hook's to get wrong.
        if ($answer?->compaction !== null) {
            $summary = new CompactionSummary(
                $answer->compaction->summary,
                $answer->compaction->readFiles,
                $answer->compaction->modifiedFiles,
                $answer->compaction->tokensBefore,
                $firstKept,
                count($older),
                $compactedAt,
                // Marked as the hook's, which is pi's own field on this entry. It keeps the next
                // compaction from carrying these file lists forward as though pig had found them,
                // and it is what tells pi — reading the same file — who wrote this summary.
                fromHook: true,
                systemMessage: $systemMessage,
            );

            $this->agent->replaceMessages([...($systemMessage !== null ? [$systemMessage] : []), $summary, ...$kept]);
            $this->store?->append($summary);
            $this->hooks?->emit(new SessionCompactEvent($summary, fromHook: true));

            return $summary;
        }

        $text = $this->summarise(
            $model,
            $request,
            $signal,
            (int) floor(0.8 * $this->reserveTokens()),
            source: 'compaction',
            reason: $reason,
            thinkingLevel: $direct->thinkingLevel,
        );

        if ($text === null) {
            return null;
        }

        // The cut as two different facts: which entry the kept part starts at, which is what the
        // file stores, and how many messages that came to, which is only ever a line on a screen.
        // Reading the file back derives the second from the first.
        $summary = new CompactionSummary($text, $read, $modified, $this->contextTokens(), $firstKept, count($older), $compactedAt, systemMessage: $systemMessage);

        $this->agent->replaceMessages([...($systemMessage !== null ? [$systemMessage] : []), $summary, ...$kept]);
        $this->store?->append($summary);
        $this->hooks?->emit(new SessionCompactEvent($summary));

        return $summary;
    }

    /**
     * One request, outside the agent loop, with no tools and nothing to steer — upstream's
     * `completeSummarization()`, the one choke point for compaction and branch summaries.
     *
     * Through the agent's own stream function, as upstream passes `this.agent.streamFunction`, so
     * the request carries the session's request options (`buildRequestOptions()`: the timeout, the
     * provider retries, the header hooks). "Avoid cache writes for one-off summaries":
     * `cacheRetention: none`, and a routing id of its own each time. And wrapped in
     * `Retry::retryAssistantCall()` with the session's retry settings, "so transient stream drops
     * honor the configured retry policy instead of failing the whole compaction on the first
     * attempt"; each retry is announced (`SummarizationRetryEvent`).
     *
     * The reasoning is upstream's `createSummarizationOptions()`: a compaction asks at the
     * session's thinking level when the model reasons and the level is not `off`, and a branch
     * summary (`generateBranchSummary()`) passes no reasoning at all. The output budget is capped
     * by the model's own `maxTokens` when it declares one: `Math.min(budget, model.maxTokens > 0
     * ? model.maxTokens : Infinity)`.
     *
     * @param int         $maxTokens  the budget before the model's cap
     * @param string      $source     `compaction` or `branchSummary`, for the retry events
     * @param string|null $reason     a compaction's `manual`, `threshold` or `overflow`
     * @param ThinkingLevel|null $thinkingLevel the level to ask at; null asks for no reasoning
     * @return string|null null when it was cancelled
     */
    private function summarise(Model $model, string $request, ?AbortSignal $signal, int $maxTokens, string $source = 'compaction', ?string $reason = null, ?ThinkingLevel $thinkingLevel = null): ?string
    {
        $stream = new SimpleStreamOptions(
            maxTokens: $model->maxTokens > 0 ? min($maxTokens, $model->maxTokens) : $maxTokens,
            signal: $signal,
            apiKey: $this->keyFor($model),
            reasoning: $model->reasoning && $thinkingLevel !== null ? $thinkingLevel->toReasoning() : null,
            cacheRetention: 'none',
            sessionId: self::routingId(),
        );

        // Normalized here, as upstream's `completeSimple()` does on its way in: the stream function
        // is the agent's, and it is handed transcripts (`TranscriptContext`).
        $context = Transcript::normalizeContext(new Context([new UserMessage($request)], Compaction::SYSTEM_PROMPT));
        $streamFunction = $this->agent->streamFunction;

        $produce = static function () use ($model, $context, $stream, $streamFunction): mixed {
            $response = $streamFunction !== null
                ? $streamFunction($model, $context, $stream)
                : Stream::simple($model, $context, $stream);

            foreach ($response as $ignored) {
                // Nothing streams anywhere: a summary is only useful whole.
            }

            $message = $response->result()->await();

            if (!$message instanceof AssistantMessage) {
                throw new AgentError('The summariser answered with something that was not a message.');
            }

            return $message;
        };

        $message = Retry::retryAssistantCall($produce, $this->retrySettings(), $signal, [
            'onRetryScheduled' => fn (int $attempt, int $maxAttempts, float $delayMs, string $error) => $this->announce(new SummarizationRetryEvent(
                SummarizationRetryEvent::SCHEDULED,
                $source,
                $reason,
                $attempt,
                $maxAttempts,
                $delayMs / 1000,
                $error,
            )),
            'onRetryAttemptStart' => fn () => $this->announce(new SummarizationRetryEvent(SummarizationRetryEvent::ATTEMPT_START, $source, $reason)),
            'onRetryFinished' => fn () => $this->announce(new SummarizationRetryEvent(SummarizationRetryEvent::FINISHED, $source, $reason)),
        ]);

        if (!$message instanceof AssistantMessage) {
            throw new AgentError('The summariser answered with something that was not a message.');
        }

        if ($message->stopReason === StopReason::Aborted) {
            return null;
        }

        if ($message->stopReason === StopReason::Error) {
            throw new AgentError('Could not summarise the conversation: ' . ($message->errorMessage ?? 'unknown error'));
        }

        $text = '';

        foreach ($message->content as $block) {
            if ($block instanceof TextContent) {
                $text .= $block->text;
            }
        }

        if (trim($text) === '') {
            throw new AgentError('The summariser said nothing, so there is nothing to carry forward.');
        }

        return trim($text);
    }

    /** A fresh routing id for a one-off request — upstream's `uuidv7()` in `completeSummarization()`. */
    private static function routingId(): string
    {
        // 48 bits of Unix milliseconds, then randomness, with the version (7) and variant bits set.
        $millis = (int) (microtime(true) * 1000);
        $bytes = substr(pack('J', $millis), 2, 6) . random_bytes(10);
        $bytes[6] = chr(ord($bytes[6]) & 0x0F | 0x70);
        $bytes[8] = chr(ord($bytes[8]) & 0x3F | 0x80);

        return implode('-', [
            bin2hex(substr($bytes, 0, 4)),
            bin2hex(substr($bytes, 4, 2)),
            bin2hex(substr($bytes, 6, 2)),
            bin2hex(substr($bytes, 8, 2)),
            bin2hex(substr($bytes, 10, 6)),
        ]);
    }

    // ---- thinking ----------------------------------------------------------------

    public function thinkingLevel(): ThinkingLevel
    {
        return $this->agent->state->thinkingLevel;
    }

    public function setThinkingLevel(ThinkingLevel $level, bool $persistAsDefault = false): void
    {
        $previous = $this->thinkingLevel();
        $changed = $previous !== $level;

        $this->agent->setThinkingLevel($level);

        if ($changed) {
            $this->store?->appendThinkingLevelChange($level->value);
            $this->hooks?->emit(new ThinkingLevelSelectEvent($level, $previous));
        }

        // Remembered for the next run when enabled.
        // Web UI passes persistAsDefault: false so it does not overwrite settings.json.
        if ($persistAsDefault) {
            $this->settings?->setDefaultThinkingLevel($level);
        }
    }

    /**
     * The next level up, wrapping round at the top.
     *
     * @return ThinkingLevel|null null when the model cannot think at all
     */
    public function cycleThinkingLevel(): ?ThinkingLevel
    {
        $levels = $this->availableThinkingLevels();

        if ($levels === []) {
            return null;
        }

        $at = array_search($this->thinkingLevel(), $levels, true);

        // A level the model does not offer — xhigh on a model without it — is not a
        // position in this list, so the cycle starts over rather than guessing one.
        $next = $at === false ? $levels[0] : $levels[($at + 1) % count($levels)];
        $this->setThinkingLevel($next, persistAsDefault: false);

        return $next;
    }

    /** @return list<ThinkingLevel> empty when the model does not support thinking */
    public function availableThinkingLevels(): array
    {
        $model = $this->model();

        // **The empty list is pig's way of saying "no thinking here at all"**, and it is load
        // bearing: `cycleThinkingLevel()` answers null on it, and the settings list uses it to
        // decide whether there is a thinking row to draw. Upstream's `supportedBy()` answers
        // `[off]` for a model that cannot reason — one level, which is true and is a different
        // statement. Handing that to these callers turns "no row" into "a row with one choice",
        // which is what seven tests said the first time this was written without the guard.
        //
        // So the rule about *which* levels a reasoning model has lives on the enum, and the rule
        // about whether there are any at all stays here.
        return $model === null || !$model->reasoning ? [] : ThinkingLevel::supportedBy($model);
    }

    // ---- what it has cost ---------------------------------------------------------

    public function stats(): SessionStats
    {
        $users = $assistants = $results = $calls = 0;
        $input = $output = $cacheRead = $cacheWrite = 0;
        $cost = 0.0;

        foreach ($this->messages() as $message) {
            if ($message instanceof UserMessage) {
                $users++;

                continue;
            }

            if ($message instanceof ToolResultMessage) {
                $results++;

                continue;
            }

            if (!$message instanceof AssistantMessage) {
                continue;
            }

            $assistants++;

            foreach ($message->content as $block) {
                if ($block instanceof ToolCall) {
                    $calls++;
                }
            }
        }

        // **The counts are about the conversation and the money is about the session**, which are
        // two questions and were one sum. Summed over `messages()`, a compaction erased the bill:
        // it replaces what it summarised with one summary carrying no usage, so the footer and
        // `/session` both dropped back towards zero at exactly the point a conversation has been
        // long enough to be expensive. The file is what was paid for — every assistant message in
        // it came back from a provider that charged, including on a branch later abandoned, and
        // neither compacting nor walking away is a refund. Upstream's footer sums the file too;
        // its own `/session` sums the messages, which is two answers to one question in one tool.
        //
        // With no file there is nothing else to count, and then the two questions share an answer.
        foreach ($this->store?->everyMessage() ?? $this->messages() as $message) {
            if (!$message instanceof AssistantMessage) {
                continue;
            }

            $input += $message->usage->input;
            $output += $message->usage->output;
            $cacheRead += $message->usage->cacheRead;
            $cacheWrite += $message->usage->cacheWrite;
            $cost += $message->usage->cost->total;
        }

        return new SessionStats(
            $users,
            $assistants,
            $calls,
            $results,
            count($this->messages()),
            $input,
            $output,
            $cacheRead,
            $cacheWrite,
            $cost,
        );
    }

    /**
     * The last thing the assistant actually said, for `/copy`.
     *
     * An aborted message with nothing in it is skipped: someone who interrupts and then
     * copies means the answer before the interruption, not the empty shell of it.
     */
    public function lastAssistantText(): ?string
    {
        foreach (array_reverse($this->messages()) as $message) {
            if (!$message instanceof AssistantMessage) {
                continue;
            }

            if ($message->stopReason === StopReason::Aborted && $message->content === []) {
                continue;
            }

            $text = '';

            foreach ($message->content as $block) {
                if ($block instanceof TextContent) {
                    $text .= $block->text;
                }
            }

            return trim($text) === '' ? null : trim($text);
        }

        return null;
    }

    private static function textOf(UserMessage $message): string
    {
        $text = '';

        foreach ($message->content as $block) {
            if ($block instanceof TextContent) {
                $text .= $block->text;
            }
        }

        return $text;
    }
}
