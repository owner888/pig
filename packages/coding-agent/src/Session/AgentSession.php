<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Session;

use Closure;
use Pig\Agent\Agent;
use Pig\Agent\AgentError;
use Pig\Agent\AgentEndEvent;
use Pig\Agent\AgentEvent;
use Pig\Agent\AgentState;
use Pig\Agent\AgentToolResult;
use Pig\Agent\MessageEndEvent;
use Pig\Agent\MessageStartEvent;
use Pig\Agent\ThinkingLevel;
use Pig\Ai\AssistantMessage;
use Pig\Ai\Context;
use Pig\Ai\ImageContent;
use Pig\Ai\Model;
use Pig\Ai\ReasoningEffort;
use Pig\Ai\SimpleStreamOptions;
use Pig\Ai\StopReason;
use Pig\Ai\Stream;
use Pig\Ai\TextContent;
use Pig\Ai\ToolCall;
use Pig\Ai\ToolResultMessage;
use Pig\Ai\UserMessage;
use Pig\Async\AbortController;
use Pig\Async\AbortSignal;
use Pig\Async\Future;
use Pig\CodingAgent\Tools\Run;
use Pig\CodingAgent\Tools\Truncate;

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

    public function __construct(
        public readonly Agent $agent,
        private readonly string $cwd = '.',
        private readonly ?SessionManager $store = null,
    ) {
        $this->unsubscribeAgent = $this->agent->subscribe($this->onAgentEvent(...));
    }

    /** Where this session is being written, if it is. */
    public function store(): ?SessionManager
    {
        return $this->store;
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
        $this->agent->setModel($model);

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
        if ($this->isStreaming()) {
            throw new AgentError('Agent is already working. Use steer() or followUp().');
        }

        if ($this->model() === null) {
            throw new AgentError('No model selected.');
        }

        $this->agent->prompt($text, $images);
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

        return $model !== null && Compaction::shouldCompact($this->contextTokens(), $model->contextWindow);
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

        $cut = Compaction::cutPoint($messages);

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

        $text = $this->summarise(
            $model,
            Compaction::request($older, Compaction::previousSummary($older), $instructions),
            $signal,
        );

        if ($text === null) {
            return null;
        }

        $summary = new CompactionSummary($text, $read, $modified, $this->contextTokens(), $cut);

        $this->agent->replaceMessages([$summary, ...$kept]);
        $this->store?->append($summary);

        return $summary;
    }

    /**
     * One request, outside the agent loop, with no tools and nothing to steer.
     *
     * @return string|null null when it was cancelled
     */
    private function summarise(Model $model, string $request, ?AbortSignal $signal): ?string
    {
        $options = $this->agent->options();

        $stream = new SimpleStreamOptions(
            maxTokens: (int) (0.8 * Compaction::RESERVE_TOKENS),
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
        $this->agent->setThinkingLevel($level);
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
