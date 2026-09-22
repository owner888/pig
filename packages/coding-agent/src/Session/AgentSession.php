<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Session;

use Closure;
use Pig\Agent\Agent;
use Pig\Agent\AgentError;
use Pig\Agent\AgentEvent;
use Pig\Agent\AgentState;
use Pig\Agent\MessageStartEvent;
use Pig\Agent\ThinkingLevel;
use Pig\Ai\AssistantMessage;
use Pig\Ai\ImageContent;
use Pig\Ai\Model;
use Pig\Ai\StopReason;
use Pig\Ai\TextContent;
use Pig\Ai\ToolCall;
use Pig\Ai\ToolResultMessage;
use Pig\Ai\UserMessage;
use Pig\Async\Future;

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

    public function __construct(public readonly Agent $agent)
    {
        $this->unsubscribeAgent = $this->agent->subscribe($this->onAgentEvent(...));
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
        // A queued message leaves the queue *before* the event goes out, so a listener
        // redrawing the "3 messages waiting" line sees three, not four.
        if ($event instanceof MessageStartEvent && $event->message instanceof UserMessage) {
            $this->dequeue(self::textOf($event->message));
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
