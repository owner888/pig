<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Interactive;

use Closure;
use Pig\Agent\AgentEvent;
use Pig\Agent\AgentStartEvent;
use Pig\Agent\MessageEndEvent;
use Pig\Ai\AssistantMessage;
use Pig\Ai\StopReason;
use Pig\CodingAgent\Session\AgentSettledEvent;
use Pig\CodingAgent\Session\AutoCompactionEndEvent;
use Pig\CodingAgent\Session\AutoCompactionStartEvent;
use Pig\Tui\ProgramStatus;
use Pig\Tui\Terminal;

/**
 * Reports interactive-mode state to the terminal (OSC 7501): `working` during agent runs and
 * compaction, `blocked` while a dialog waits for the user, then `done`, `error`, or `idle` once the run
 * settles. Messages are limited to the session name, dialog titles, and the first line of errors; prompts
 * and assistant output are never reported.
 *
 * Upstream's `program-status-reporter.ts`. One difference in where the news comes from: upstream hears
 * every compaction as a `compaction_start`/`compaction_end` session event, while here only the overflow
 * one is an event — `/compact` and the threshold check run in the mode, which tells this class itself
 * through `compactionStart()` and `compactionEnd()`. Both roads end in the same state machine.
 */
final class ProgramStatusReporter
{
    private const string APP_NAME = 'pig';

    /** @var Closure(): Terminal */
    private readonly Closure $getTerminal;

    /** @var Closure(): ?string */
    private readonly Closure $getSessionName;

    private bool $runActive = false;

    private bool $compacting = false;

    /** Outcome of the current run, reported once it settles. */
    private ProgramStatus $runResult;

    /** Status while no run is active. */
    private ProgramStatus $restingStatus;

    /** Open dialogs by source, in the order they opened. The most recent one is reported. */
    private array $blocked = [];

    private ?string $lastReport = null;

    /**
     * @param Closure(): Terminal $getTerminal
     * @param Closure(): ?string $getSessionName
     */
    public function __construct(Closure $getTerminal, Closure $getSessionName)
    {
        $this->getTerminal = $getTerminal;
        $this->getSessionName = $getSessionName;
        $this->runResult = new ProgramStatus('done');
        $this->restingStatus = new ProgramStatus('idle');
    }

    public function handleEvent(AgentEvent $event): void
    {
        switch (true) {
            case $event instanceof AgentStartEvent:
                $this->runActive = true;
                $this->runResult = new ProgramStatus('done');
                break;
            case $event instanceof MessageEndEvent:
                // The latest response decides the outcome, so a retried error is replaced by its successful retry.
                if (!$event->message instanceof AssistantMessage) {
                    return;
                }
                $this->runResult = $event->message->stopReason === StopReason::Error
                    ? new ProgramStatus('error', message: self::firstLine($event->message->errorMessage))
                    : new ProgramStatus('done');
                break;
            case $event instanceof AutoCompactionStartEvent:
                $this->compacting = true;
                break;
            case $event instanceof AutoCompactionEndEvent:
                // The session's overflow compaction, always inside a run. A cancelled one is told as
                // an error here and becomes `idle` at the `agent_settled` that follows it.
                $this->compactionEnd('overflow', false, $event->succeeded ? null : $event->error);

                return;
            case $event instanceof AgentSettledEvent:
                $this->runActive = false;
                $this->restingStatus = $event->aborted ? new ProgramStatus('idle') : $this->runResult;
                break;
            default:
                return;
        }

        $this->report();
    }

    /** Upstream's `compaction_start`, for the compactions the mode runs itself. */
    public function compactionStart(): void
    {
        $this->compacting = true;
        $this->report();
    }

    /**
     * Upstream's `compaction_end`.
     *
     * @param 'manual'|'threshold'|'overflow' $reason
     */
    public function compactionEnd(string $reason, bool $aborted, ?string $errorMessage): void
    {
        $this->compacting = false;

        if ($this->runActive) {
            // A failed recovery compaction ends the run unless a later response succeeds.
            if ($aborted) {
                $this->runResult = new ProgramStatus('idle');
            } elseif ($errorMessage !== null) {
                $this->runResult = new ProgramStatus('error', message: self::firstLine($errorMessage));
            }
        } elseif ($aborted) {
            $this->restingStatus = new ProgramStatus('idle');
        } elseif ($reason === 'manual') {
            $this->restingStatus = $errorMessage !== null
                ? new ProgramStatus('error', message: self::firstLine($errorMessage))
                : new ProgramStatus('done');
        }

        $this->report();
    }

    /** Report `blocked` for a dialog until it is cleared with `null`. Reopening a source replaces it. */
    public function setBlocked(string $source, ?BlockedStatus $status): void
    {
        unset($this->blocked[$source]);

        if ($status !== null) {
            $this->blocked[$source] = $status;
        }

        $this->report();
    }

    /** Forget the previous session's run, for example after switching sessions. */
    public function reset(): void
    {
        $this->runActive = false;
        $this->compacting = false;
        $this->runResult = new ProgramStatus('done');
        $this->restingStatus = new ProgramStatus('idle');
        $this->report();
    }

    public function report(): void
    {
        $current = $this->currentStatus();
        $status = new ProgramStatus($current->state, self::APP_NAME, $current->kind, $current->message);
        $key = json_encode($status, JSON_THROW_ON_ERROR);

        if ($key === $this->lastReport) {
            return;
        }

        $this->lastReport = $key;
        ($this->getTerminal)()->setProgramStatus($status);
    }

    private function currentStatus(): ProgramStatus
    {
        $blocked = $this->blocked === [] ? null : $this->blocked[array_key_last($this->blocked)];

        if ($blocked !== null) {
            return new ProgramStatus('blocked', kind: $blocked->kind, message: $blocked->message);
        }

        if ($this->compacting) {
            return new ProgramStatus('working', message: 'Compacting context');
        }

        $status = $this->runActive ? new ProgramStatus('working') : $this->restingStatus;

        if ($status->state === 'working' || $status->state === 'done') {
            return new ProgramStatus($status->state, message: ($this->getSessionName)());
        }

        return $status;
    }

    private static function firstLine(?string $text): string
    {
        $line = trim(explode("\n", str_replace("\r\n", "\n", $text ?? ''), 2)[0]);

        return $line === '' ? 'Error' : $line;
    }
}
