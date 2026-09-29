<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Session;

use Closure;
use Pig\Agent\Agent;
use Pig\Agent\AgentError;
use Pig\Agent\AgentEndEvent;
use Pig\Agent\AgentEvent;
use Pig\Agent\AgentStartEvent;
use Pig\Agent\AgentState;
use Pig\Agent\AgentToolResult;
use Pig\Agent\MessageEndEvent;
use Pig\Agent\MessageStartEvent;
use Pig\Agent\QueueMode;
use Pig\Agent\ThinkingLevel;
use Pig\Agent\TurnEndEvent;
use Pig\Agent\TurnStartEvent;
use Pig\Ai\AssistantMessage;
use Pig\Ai\Context;
use Pig\Ai\ImageContent;
use Pig\Ai\Model;
use Pig\Ai\Models;
use Pig\Ai\ReasoningEffort;
use Pig\Ai\SimpleStreamOptions;
use Pig\Ai\StopReason;
use Pig\Ai\Stream;
use Pig\Ai\TextContent;
use Pig\Ai\ToolCall;
use Pig\Ai\ToolResultMessage;
use Pig\Ai\UserMessage;
use Pig\Ai\Utils\Overflow;
use Pig\Async\AbortController;
use Pig\Async\AbortSignal;
use Pig\Async\Async;
use Pig\Async\Deferred;
use Pig\Async\Future;
use Pig\Async\Loop;
use Pig\CodingAgent\Hooks\Events\AgentEndEvent as HookAgentEnd;
use Pig\CodingAgent\Hooks\Events\AgentStartEvent as HookAgentStart;
use Pig\CodingAgent\Hooks\Events\SessionBeforeCompactEvent;
use Pig\CodingAgent\Hooks\Events\SessionBeforeSwitchEvent;
use Pig\CodingAgent\Hooks\Events\SessionBeforeTreeEvent;
use Pig\CodingAgent\Hooks\Events\SessionCompactEvent;
use Pig\CodingAgent\Hooks\Events\SessionSwitchEvent;
use Pig\CodingAgent\Hooks\Events\SessionTreeEvent;
use Pig\CodingAgent\Hooks\Events\TurnEndEvent as HookTurnEnd;
use Pig\CodingAgent\Hooks\Events\TurnStartEvent as HookTurnStart;
use Pig\CodingAgent\Hooks\HookError;
use Pig\CodingAgent\Hooks\HookRunner;
use Pig\CodingAgent\ModelChoice;
use Pig\CodingAgent\ModelResolver;
use Pig\CodingAgent\Prompt\FileCommand;
use Pig\CodingAgent\Prompt\SlashCommands;
use Pig\CodingAgent\Settings;
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

    /** Completed when the retrying is over, so `prompt()` can wait for it. */
    private ?Deferred $settled = null;

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
    ) {
        $this->fileCommands = $fileCommands;
        $this->modelScope = $modelScope;
        $this->unsubscribeAgent = $this->agent->subscribe($this->onAgentEvent(...));

        // What was chosen last time, applied here rather than by whoever built the agent: this
        // is the class that holds both the agent and the settings, and `setQueueMode()` right
        // below writes the setting through the same pair. A caller passing it into
        // `AgentOptions` instead would mean two routes for one fact, and the one route that
        // every mode and every test already goes through is this constructor.
        if ($settings !== null) {
            $this->agent->setQueueMode($settings->queueMode());
        }
    }

    /** The hooks this session fires at, if any. */
    public function hooks(): ?HookRunner
    {
        return $this->hooks;
    }

    public function setHooks(?HookRunner $hooks): void
    {
        $this->hooks = $hooks;
    }

    /** @param list<FileCommand> $fileCommands */
    public function setFileCommands(array $fileCommands): void
    {
        $this->fileCommands = $fileCommands;
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
            if ($model !== null && $this->keyFor($model) !== null) {
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
        // Held-back commands join the conversation before the listeners see the end of
        // the run, so a UI redrawing on that event already has them.
        if ($event instanceof AgentEndEvent) {
            $this->flushBash();
        }

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

        foreach ($this->listeners as $listener) {
            $listener($event);
        }

        // Last, after the listeners have seen the end: whatever this decides to do next is a
        // new run, and a UI redrawing on `AgentEndEvent` should have finished drawing the old
        // one before the next one starts arriving.
        if ($event instanceof AgentEndEvent) {
            $this->afterTheRun();
        }
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
            $this->hooks->emit(new HookTurnEnd($event->message, $event->toolResults, $this->turnIndex));
            $this->turnIndex++;

            return;
        }

        if ($event instanceof AgentEndEvent) {
            $this->hooks->emit(new HookAgentEnd($event->messages));
        }
    }

    // ---- state -------------------------------------------------------------------

    public function state(): AgentState
    {
        return $this->agent->state;
    }

    public function model(): ?Model
    {
        return $this->agent->state->model;
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
        $this->setModel($next, $scope["{$next->provider}/{$next->id}"]->thinking ?? null);

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
    public function setModel(Model $model, ?ThinkingLevel $thinking = null): void
    {
        // Upstream's first two lines, and they were missing: switching to a model this machine
        // has no key for used to succeed, be written into the session file as the model this
        // conversation is on, and then fail on the next turn with `Stream`'s "No API key for
        // provider" — from inside a turn, where it looks like the provider's fault. Refused here
        // instead, before anything is recorded.
        if ($this->keyFor($model) === null) {
            throw new AgentError("No API key for {$model->provider}/{$model->id}");
        }

        $changed = $this->model()?->id !== $model->id || $this->model()?->provider !== $model->provider;

        $this->agent->setModel($model);

        // Written down, so `--continue` comes back to the model this conversation was being
        // had with rather than to whatever a new one would open with. Only when it actually
        // changed: `setModel()` is also how the thinking level is clamped, and a line per
        // clamp would be a file full of a model changing to itself.
        if ($changed) {
            $this->store?->appendModelChange($model->provider, $model->id);
        }

        // And remembered for the next run, which upstream does from here for the reason
        // `setQueueMode()` gives: this is the class that holds both the session and the
        // settings. It used to be the terminal's line, so `/model haiku` was remembered and a
        // host's `set_model` was forgotten — the same operation in two modes with the rule on
        // only one of them, which is this audit's recurring find.
        $this->settings?->setDefaultModel($model->id, $model->provider);

        $this->setThinkingLevel($this->clampThinking($thinking ?? $this->thinkingLevel()));
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
    private function keyFor(Model $model): ?string
    {
        $options = $this->agent->options();

        return $options->getApiKey !== null
            ? ($options->getApiKey)($model->provider) ?? $options->apiKey
            : $options->apiKey;
    }

    public function isStreaming(): bool
    {
        return $this->agent->state->isStreaming;
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
    public function prompt(string $text, array $images = []): void
    {
        if ($this->runHookCommand($text)) {
            return;
        }

        // A retry that is sleeping is not "streaming", so nothing above would have stopped
        // this — and sending now would race the retry into the same agent. Wait it out: it is
        // seconds, and what the person typed goes after whatever the retry was rescuing.
        $this->settled?->future->await();

        if ($this->isStreaming()) {
            throw new AgentError('Agent is already working. Use steer() or followUp().');
        }

        if ($this->model() === null) {
            throw new AgentError('No model selected.');
        }

        $text = $this->expandFileCommand($text);

        // A hook may put a note in front of the prompt. It goes in as its own user
        // message rather than being pasted onto the front of theirs, so the transcript
        // still shows what the person actually typed — and it goes in *through* the
        // prompt rather than onto the state, so the session file records it and a
        // resumed conversation still has it.
        $note = $this->hooks?->emitBeforeAgentStart($text, $images);

        if ($note === null || trim($note->text) === '') {
            $this->agent->prompt($text, $images);
        } else {
            $this->agent->prompt([
                new UserMessage($note->text),
                new UserMessage([new TextContent($text), ...$images]),
            ]);
        }

        // And again on the way out, which is upstream's `await this.waitForRetry()` and is the
        // half pig was missing: a turn that ended in a 503 has a retry spawned behind it, so a
        // `prompt()` that returns here returns *before the answer exists*. `bin/pig -p` printed
        // the 503 and exited 1 — and exiting took the retry with it — where the second attempt
        // a second later would have worked. The three modes that stay running were only
        // cosmetically wrong: the answer arrived, just after the call that asked for it.
        $this->settled?->future->await();
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

        // Spawned, because this is almost always called from inside a handler that is itself
        // inside a run's event fan-out — the same reason the retry spawns.
        $this->inTheBackground(function (): void {
            $this->agent->continue();
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
    public function steer(string $text): void
    {
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
    public function followUp(string $text): void
    {
        $this->followUps[] = $text;
        $this->agent->followUp(new UserMessage($this->expandFileCommand($text)));
    }

    /** @return list<string> everything waiting, steering first */
    public function queued(): array
    {
        return [...$this->steering, ...$this->followUps];
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

    /** Stop the current run; resolves once the agent is idle. */
    public function abort(): Future
    {
        // Before the agent, because neither of these has an agent to interrupt: a retry that is
        // sleeping and a summariser that is running both happen *between* runs, with the last one
        // over and the next one not started. Escape has to reach all three, and reaching them from
        // here rather than from each caller is what stops a caller reaching two and stopping.
        // Upstream's escape handler calls `abortCompaction()` itself, beside `abort()`.
        $this->abortRetry();
        $this->abortCompaction();
        $this->agent->abort();

        return $this->agent->waitForIdle();
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

        if ($fresh !== null) {
            $this->writeTo($fresh);
        }

        $this->agent->reset();
        $this->hooks?->emit(new SessionSwitchEvent('new', $previous));

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
        $this->hooks?->emit(new SessionSwitchEvent('resume', $previous));

        return new SessionSwitch(switched: true, previous: $previous, messages: count($opened->messages()));
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
        $model = $this->model();

        if ($model === null || !($this->settings?->compactionEnabled() ?? true)) {
            return false;
        }

        return Compaction::shouldCompact($this->contextTokens(), $model->contextWindow, $this->reserveTokens());
    }

    private function reserveTokens(): int
    {
        return $this->settings?->compactionReserveTokens(Compaction::RESERVE_TOKENS) ?? Compaction::RESERVE_TOKENS;
    }

    // ---- picking a failed turn back up ------------------------------------------------

    /**
     * The run is over. Was it over because something went wrong that can be undone?
     *
     * Two things can be, and they are told apart by what the provider said. A 503 means try
     * again; "prompt is too long" means the request itself was the problem, and sending it
     * again unchanged is the one thing guaranteed not to work — that one is summarised first.
     *
     * Everything here spawns rather than runs. This is called from inside the agent's own
     * event fan-out, and `continue()` starts another run: doing that here would re-enter the
     * agent from inside its own notification, and the sleeping cannot happen in a callback at
     * all. Upstream reaches for `setTimeout(..., 0)` "to break out of the event handler
     * chain"; `Async::spawn` is the same idea with a name that says why.
     */
    private function afterTheRun(): void
    {
        $messages = $this->messages();
        $last = $messages === [] ? null : $messages[count($messages) - 1];

        if (!$last instanceof AssistantMessage) {
            $this->finishBackgroundWork();

            return;
        }

        $window = $this->model()?->contextWindow;

        if (Overflow::happened($last, $window)) {
            $this->startBackgroundWork();
            $this->inTheBackground(fn () => $this->compactAndCarryOn($last));

            return;
        }

        if ($this->retryEnabled() && Retry::worthRetrying($last, $window)) {
            // Created here rather than one line into `waitAndCarryOn()`, because that runs a
            // tick later and `prompt()` awaits this on its way out of *this* call: a `Deferred`
            // that does not exist yet cannot be waited for. It also closes the same window the
            // controller's own move closed — the moment after a retry is decided and before
            // anything can be told it is happening.
            $this->startRetrying();
            $this->inTheBackground(fn () => $this->waitAndCarryOn($last));

            return;
        }

        // A run that ended without failing, after one that did: the retrying worked.
        if ($this->attempt > 0) {
            $attempts = $this->attempt;
            $this->attempt = 0;
            $this->announce(new RetryEndEvent(true, $attempts));
        }

        // Nothing more is coming, so this is where a `prompt()` still waiting is let go —
        // including after an auto-compaction that worked, whose own carry-on never touches the
        // retry counter above and so has no other ending to be released by.
        $this->finishBackgroundWork();
    }

    /**
     * Run it in a fiber, and do not let it fail silently.
     *
     * `Async::spawn` hands a throw to the future it returns, and nothing awaits this one — so
     * without the catch, a bug in here is a session that simply stops, with the listeners
     * left holding a `RetryStartEvent` that never ends. Which is exactly what happened:
     * `Throwable` was not imported in this file, every `catch (Throwable)` in it was catching
     * a class that does not exist, and the fibers died without a word. See the trap in
     * CLAUDE.md about missing imports — this is the shape of it.
     */
    private function inTheBackground(Closure $work): void
    {
        Async::spawn(function () use ($work): void {
            try {
                $work();
            } catch (Throwable $problem) {
                $attempts = $this->attempt;
                $this->attempt = 0;
                $this->announce(new RetryEndEvent(false, $attempts, $problem->getMessage()));
                $this->finishBackgroundWork();
            }
        });
    }

    /**
     * Wait, then send the same turn again.
     *
     * The failed message is taken off the agent's state before the retry — it is an error, not
     * an answer, and leaving it there would have the model reading its own failure as the
     * conversation. It stays in the session file, because it happened.
     */
    private function waitAndCarryOn(AssistantMessage $failed): void
    {
        // Called off between the decision to retry and this fiber's first tick, which is a real
        // window: `afterTheRun()` decides synchronously and this runs a tick later, and
        // `abortRetry()` in between clears both halves. Making a fresh pair here would start a
        // retry with a controller nobody holds — a sleep that escape has already been answered
        // for and cannot reach again.
        if ($this->retrying === null) {
            return;
        }

        $this->attempt++;

        $max = $this->settings?->retryMaxAttempts(Retry::MAX_ATTEMPTS) ?? Retry::MAX_ATTEMPTS;
        $error = $failed->errorMessage ?? 'Unknown error';

        if ($this->attempt > $max) {
            $this->attempt = 0;
            $this->announce(new RetryEndEvent(false, $max, $error));
            $this->finishBackgroundWork();

            return;
        }

        // What the provider asked for, when it said so, and the doubling otherwise. A 429 whose
        // body names the moment its quota resets is the one case where guessing is strictly
        // worse: the guess is too early three times over and then the turn is gone.
        $delay = Retry::statedDelay($error) ?? Retry::delayFor(
            $this->attempt,
            $this->settings?->retryBaseDelay(Retry::BASE_DELAY) ?? Retry::BASE_DELAY,
        );

        $this->announce(new RetryStartEvent($this->attempt, $max, $delay, $error));
        $this->dropLastAssistantMessage();

        $signal = $this->retrying?->signal;

        if ($signal === null || !$this->sleep($delay, $signal)) {
            // Escape, during the sleep. `abortRetry()` has already reset the counter and told
            // everyone; there is nothing left to do but not send the request.
            return;
        }

        $this->carryOn();
    }

    /**
     * Summarise, then send the same turn again.
     *
     * The turn failed because the conversation outgrew the window, so the summary is the fix
     * and the retry is the point of doing it. A summary that fails or is cancelled ends it —
     * sending the same oversized request again would fail the same way.
     *
     * Those two endings call `finishRetrying()`, which looks like a no-op and is not: a retry
     * whose second attempt overflows arrives here with `settled` already pending, and whoever
     * is waiting on it — `prompt()`, on its way out — would wait for a run that is not coming.
     */
    private function compactAndCarryOn(AssistantMessage $failed): void
    {
        $error = $failed->errorMessage ?? 'The conversation outgrew the context window.';

        $this->announce(new AutoCompactionStartEvent($error));
        $this->dropLastAssistantMessage();

        try {
            $summary = $this->compact();
        } catch (Throwable $problem) {
            $this->announce(new AutoCompactionEndEvent(false, false, null, $problem->getMessage()));
            $this->finishBackgroundWork();

            return;
        }

        if ($summary === null) {
            $this->announce(new AutoCompactionEndEvent(false, false, null, 'Summarising was cancelled.'));
            $this->finishBackgroundWork();

            return;
        }

        $this->announce(new AutoCompactionEndEvent(true, true, $summary));
        $this->carryOn();
    }

    /** Run again from the conversation as it stands. A failure lands back in `afterTheRun()`. */
    private function carryOn(): void
    {
        if ($this->retryAborted()) {
            return;
        }

        try {
            $this->agent->continue();
        } catch (Throwable $problem) {
            // `continue()` refuses when the agent is already working, which here means
            // somebody typed while the retry was sleeping. Their turn is the one that should
            // happen; this one is over.
            $this->attempt = 0;
            $this->announce(new RetryEndEvent(false, $this->attempt, $problem->getMessage()));
            $this->finishBackgroundWork();
        }
    }

    /**
     * Take the failed turn off the agent's state, leaving it in the session file.
     *
     * The model must not be shown its own error as though it were part of the conversation:
     * the next request would carry "Anthropic returned 503" in the transcript, and the model
     * would try to make sense of it.
     */
    private function dropLastAssistantMessage(): void
    {
        $messages = $this->agent->state->messages;
        $last = $messages === [] ? null : $messages[count($messages) - 1];

        if ($last instanceof AssistantMessage) {
            $this->agent->replaceMessages(array_slice($messages, 0, -1));
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

    private function retryEnabled(): bool
    {
        return $this->settings?->retryEnabled() ?? true;
    }

    /**
     * Whether a retry is being waited out right now.
     *
     * The controller and not `$settled`, which the two were interchangeable for until the waiting
     * handle came to cover auto-compaction as well. A summarisation is not a retry, and a field
     * that answered for both would have this method lying on the overflow path.
     */
    public function isRetrying(): bool
    {
        return $this->retrying !== null;
    }

    /**
     * Stop retrying.
     *
     * Safe to call when nothing is being retried, because that is how `abort()` calls it:
     * escape means stop, and whether there was a sleep to interrupt is not the caller's
     * business.
     */
    public function abortRetry(): void
    {
        // The controller, not the waiting handle: that is pending for an auto-compaction too, and
        // escape during one must not announce a retry ending that no retry was having.
        if ($this->retrying === null) {
            return;
        }

        $attempts = $this->attempt;
        $this->attempt = 0;
        $this->retrying?->abort();
        $this->announce(new RetryEndEvent(false, $attempts, 'Retrying was cancelled.'));
        $this->finishBackgroundWork();
    }

    /** Whether escape has reached the retry that is running. */
    private function retryAborted(): bool
    {
        return $this->retrying?->signal->aborted() ?? false;
    }

    /**
     * `afterTheRun()` has started something, and `prompt()` may now wait for it.
     *
     * One handle for both kinds — a retry and an auto-compaction — because to whoever is waiting
     * they are the same fact: the turn is not over yet. `bin/pig -p` is the caller that has to
     * know, since when it stops waiting the process exits.
     */
    private function startBackgroundWork(): void
    {
        $this->settled ??= new Deferred();
    }

    /**
     * The retry is on, and both halves of it exist from this moment.
     *
     * Both together, and for the whole retry rather than just the sleep. Created only around
     * the sleep, there was a window — after `isRetrying()` became true and before the
     * controller existed — where `abortRetry()` had nothing to abort: it said the retrying was
     * cancelled and the retrying carried on anyway.
     */
    private function startRetrying(): void
    {
        $this->startBackgroundWork();
        $this->retrying ??= new AbortController();
    }

    /** Nothing more is coming from `afterTheRun()`, so release whoever is waiting. */
    private function finishBackgroundWork(): void
    {
        $waiting = $this->settled;
        $this->settled = null;
        $this->retrying = null;

        if ($waiting !== null && !$waiting->isComplete()) {
            $waiting->complete(null);
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
    public function compact(?string $instructions = null, ?AbortSignal $signal = null): ?CompactionSummary
    {
        if ($this->isStreaming()) {
            throw new AgentError('Agent is working. Let it finish, or press esc, then compact.');
        }

        // One controller for both doors, so `isCompacting()` and `abortCompaction()` answer for a
        // summarisation whoever started it. A signal the caller brought is *forwarded* into it
        // rather than carried alongside: what goes downstream then has one `aborted()` to ask, and
        // pig has no combinator for two — see the note on `Future` in CLAUDE.md.
        $this->compacting = new AbortController();

        if ($signal?->aborted() === true) {
            $this->compacting->abort('Cancelled');
        }

        $listener = $signal?->onAbort(fn () => $this->compacting?->abort('Cancelled'));

        try {
            return $this->summariseAndSwapIn($instructions, $this->compacting->signal);
        } finally {
            $this->compacting = null;

            if ($listener !== null) {
                $signal?->removeListener($listener);
            }
        }
    }

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
    private function summariseAndSwapIn(?string $instructions, AbortSignal $signal): ?CompactionSummary
    {
        $model = $this->model();

        if ($model === null) {
            throw new AgentError('No model selected.');
        }

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

        $older = array_slice($messages, 0, $cut);
        $kept = array_slice($messages, $cut);
        [$read, $modified] = Compaction::files($older);
        $request = Compaction::request($older, Compaction::previousSummary($older), $instructions);

        $answer = $this->hooks?->emitBeforeCompact(
            new SessionBeforeCompactEvent($older, $request, $instructions, $signal),
        );

        if ($answer !== null && $answer->cancel) {
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
                $cut,
                // Marked as the hook's, which is pi's own field on this entry. It keeps the next
                // compaction from carrying these file lists forward as though pig had found them,
                // and it is what tells pi — reading the same file — who wrote this summary.
                fromHook: true,
            );

            $this->agent->replaceMessages([$summary, ...$kept]);
            $this->store?->append($summary);
            $this->hooks?->emit(new SessionCompactEvent($summary, fromHook: true));

            return $summary;
        }

        $text = $this->summarise($model, $request, $signal);

        if ($text === null) {
            return null;
        }

        // `$cut` twice over, as two different facts: which entry the kept part starts at,
        // which is what the file stores, and how many messages that came to, which is only
        // ever a line on a screen. Reading the file back derives the second from the first.
        $summary = new CompactionSummary($text, $read, $modified, $this->contextTokens(), $firstKept, $cut);

        $this->agent->replaceMessages([$summary, ...$kept]);
        $this->store?->append($summary);
        $this->hooks?->emit(new SessionCompactEvent($summary));

        return $summary;
    }

    /**
     * One request, outside the agent loop, with no tools and nothing to steer.
     *
     * @return string|null null when it was cancelled
     */
    private function summarise(Model $model, string $request, ?AbortSignal $signal, ?int $maxTokens = null): ?string
    {
        $options = $this->agent->options();

        $stream = new SimpleStreamOptions(
            maxTokens: $maxTokens ?? (int) (0.8 * $this->reserveTokens()),
            signal: $signal,
            apiKey: $this->keyFor($model),
            reasoning: ReasoningEffort::High,
        );

        $context = new Context([new UserMessage($request)], Compaction::SYSTEM_PROMPT);

        $response = $options->streamFn !== null
            ? ($options->streamFn)($model, $context, $stream)
            : Stream::simple($model, $context, $stream);

        foreach ($response as $ignored) {
            // Nothing streams anywhere: a summary is only useful whole.
        }

        $message = $response->result()->await();

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

    // ---- thinking ----------------------------------------------------------------

    public function thinkingLevel(): ThinkingLevel
    {
        return $this->agent->state->thinkingLevel;
    }

    public function setThinkingLevel(ThinkingLevel $level): void
    {
        $changed = $this->thinkingLevel() !== $level;

        $this->agent->setThinkingLevel($level);

        if ($changed) {
            $this->store?->appendThinkingLevelChange($level->value);
        }

        // Remembered for the next run, here rather than in each mode — see `setModel()`. Written
        // even when the level did not change, as upstream does: a level the settings have never
        // heard of is the case this is for, and it is not a change.
        $this->settings?->setDefaultThinkingLevel($level);
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
        $this->setThinkingLevel($next);

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
