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
use Pig\CodingAgent\Hooks\Events\SessionBeforeTreeEvent;
use Pig\CodingAgent\Hooks\Events\SessionCompactEvent;
use Pig\CodingAgent\Hooks\Events\SessionTreeEvent;
use Pig\CodingAgent\Hooks\Events\TurnEndEvent as HookTurnEnd;
use Pig\CodingAgent\Hooks\Events\TurnStartEvent as HookTurnStart;
use Pig\CodingAgent\Hooks\HookRunner;
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

    public function __construct(
        public readonly Agent $agent,
        private readonly string $cwd = '.',
        private ?SessionManager $store = null,
        private readonly ?Settings $settings = null,
        private readonly ?HookRunner $hooks = null,
    ) {
        $this->unsubscribeAgent = $this->agent->subscribe($this->onAgentEvent(...));
    }

    /** The hooks this session fires at, if any. */
    public function hooks(): ?HookRunner
    {
        return $this->hooks;
    }

    /** Where this session is being written, if it is. */
    public function store(): ?SessionManager
    {
        return $this->store;
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

            if ($model !== null) {
                $this->agent->setModel($model);
            }
        }

        // After the model, because which levels exist depends on it. Clamped rather than
        // taken on trust for the same reason `setModel()` clamps: a level the model cannot
        // do is a request the provider rejects, and the person resuming did not ask for it
        // — the file did.
        $levels = $this->availableThinkingLevels();
        $level = $recorded['thinking']?->known() ?? $this->thinkingLevel();

        $this->agent->setThinkingLevel(in_array($level, $levels, true) ? $level : ThinkingLevel::Off);
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
     * Switch models, and keep the thinking level honest about the new one.
     *
     * A level the new model cannot do is not carried over: leaving `high` set on a model
     * without reasoning sends a request the provider rejects, and the person who changed
     * model would have no idea why. Dropped silently to what the model can do, because
     * they asked for the model, not for the level.
     */
    public function setModel(Model $model, ?ThinkingLevel $thinking = null): void
    {
        $changed = $this->model()?->id !== $model->id || $this->model()?->provider !== $model->provider;

        $this->agent->setModel($model);

        // Written down, so `--continue` comes back to the model this conversation was being
        // had with rather than to whatever a new one would open with. Only when it actually
        // changed: `setModel()` is also how the thinking level is clamped, and a line per
        // clamp would be a file full of a model changing to itself.
        if ($changed) {
            $this->store?->appendModelChange($model->provider, $model->id);
        }

        $wanted = $thinking ?? $this->thinkingLevel();
        $levels = $this->availableThinkingLevels();

        $this->setThinkingLevel(in_array($wanted, $levels, true) ? $wanted : ThinkingLevel::Off);
    }

    public function isStreaming(): bool
    {
        return $this->agent->state->isStreaming;
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
     * @param list<ImageContent> $images
     * @throws AgentError if the agent is already working, or there is no model
     */
    public function prompt(string $text, array $images = []): void
    {
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

        // A hook may put a note in front of the prompt. It goes in as its own user
        // message rather than being pasted onto the front of theirs, so the transcript
        // still shows what the person actually typed — and it goes in *through* the
        // prompt rather than onto the state, so the session file records it and a
        // resumed conversation still has it.
        $note = $this->hooks?->emitBeforeAgentStart($text, $images);

        if ($note === null || trim($note->text) === '') {
            $this->agent->prompt($text, $images);

            return;
        }

        $this->agent->prompt([
            new UserMessage($note->text),
            new UserMessage([new TextContent($text), ...$images]),
        ]);
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
            // As a follow-up rather than steering: steering interrupts the tools that are
            // queued behind the current one, and a hook's note is not a change of mind.
            $this->followUps[] = $message->toText();

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
     */
    public function steer(string $text): void
    {
        $this->steering[] = $text;
        $this->agent->steer(new UserMessage($text));
    }

    /** Queue something for after the agent has finished the request it is on. */
    public function followUp(string $text): void
    {
        $this->followUps[] = $text;
        $this->agent->followUp(new UserMessage($text));
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
        // Before the agent, because a retry that is sleeping has no agent to interrupt: the
        // run is already over and the next one has not started. Escape has to reach both.
        $this->abortRetry();
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

    /**
     * Go back to an earlier point in the conversation.
     *
     * The session file keeps every branch, so this is a move rather than a deletion: the
     * road not taken is still there, and going back to it is the same call again.
     *
     * @throws AgentError when there is no session on disk, or no such point
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

        $this->store->goTo($entryId);
        $this->restore($this->store->messages());

        // After the move, so it lands on the branch being joined — which is the whole
        // point: it is context for carrying on here, not a note on the branch it describes.
        if ($summary !== null) {
            $this->agent->appendMessage($summary);
            $this->store->append($summary);
        }

        $this->hooks?->emit(new SessionTreeEvent($this->store->leaf(), $oldLeaf, $summary));

        return new TreeJump(moved: true, summary: $summary);
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
        if ($fromHook !== null) {
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
            return;
        }

        $window = $this->model()?->contextWindow;

        if (Overflow::happened($last, $window)) {
            $this->inTheBackground(fn () => $this->compactAndCarryOn($last));

            return;
        }

        if ($this->retryEnabled() && Retry::worthRetrying($last, $window)) {
            $this->inTheBackground(fn () => $this->waitAndCarryOn($last));

            return;
        }

        // A run that ended without failing, after one that did: the retrying worked.
        if ($this->attempt > 0) {
            $attempts = $this->attempt;
            $this->attempt = 0;
            $this->announce(new RetryEndEvent(true, $attempts));
            $this->finishRetrying();
        }
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
                $this->finishRetrying();
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
        $this->attempt++;

        // Both together, and for the whole retry rather than just the sleep. Created only
        // around the sleep, there was a window — after `isRetrying()` became true and before
        // the controller existed — where `abortRetry()` had nothing to abort: it said the
        // retrying was cancelled and the retrying carried on anyway.
        if ($this->settled === null) {
            $this->settled = new Deferred();
            $this->retrying = new AbortController();
        }

        $max = $this->settings?->retryMaxAttempts(Retry::MAX_ATTEMPTS) ?? Retry::MAX_ATTEMPTS;
        $error = $failed->errorMessage ?? 'Unknown error';

        if ($this->attempt > $max) {
            $this->attempt = 0;
            $this->announce(new RetryEndEvent(false, $max, $error));
            $this->finishRetrying();

            return;
        }

        $delay = Retry::delayFor(
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

            return;
        }

        if ($summary === null) {
            $this->announce(new AutoCompactionEndEvent(false, false, null, 'Summarising was cancelled.'));

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
            $this->finishRetrying();
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

    /** Whether a retry is being waited out right now. */
    public function isRetrying(): bool
    {
        return $this->settled !== null;
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
        if ($this->settled === null) {
            return;
        }

        $attempts = $this->attempt;
        $this->attempt = 0;
        $this->retrying?->abort();
        $this->announce(new RetryEndEvent(false, $attempts, 'Retrying was cancelled.'));
        $this->finishRetrying();
    }

    /** Whether escape has reached the retry that is running. */
    private function retryAborted(): bool
    {
        return $this->retrying?->signal->aborted() ?? false;
    }

    private function finishRetrying(): void
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
            apiKey: $options->getApiKey !== null
                ? ($options->getApiKey)($model->provider) ?? $options->apiKey
                : $options->apiKey,
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

        if ($model === null || !$model->reasoning) {
            return [];
        }

        $levels = [
            ThinkingLevel::Off,
            ThinkingLevel::Minimal,
            ThinkingLevel::Low,
            ThinkingLevel::Medium,
            ThinkingLevel::High,
        ];

        return $model->supportsXhigh() ? [...$levels, ThinkingLevel::Xhigh] : $levels;
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
