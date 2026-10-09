<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;
use Pig\Agent\AgentEvent;
use Pig\Agent\AgentStartEvent;
use Pig\Agent\MessageEndEvent;
use Pig\Agent\TurnStartEvent;
use Pig\Ai\Api;
use Pig\Ai\AssistantMessage;
use Pig\Ai\StopReason;
use Pig\Ai\TextContent;
use Pig\Ai\Usage;
use Pig\CodingAgent\Interactive\BlockedStatus;
use Pig\CodingAgent\Interactive\ProgramStatusReporter;
use Pig\CodingAgent\Session\AgentSettledEvent;
use Pig\CodingAgent\Session\AutoCompactionEndEvent;
use Pig\CodingAgent\Session\AutoCompactionStartEvent;
use Pig\Tui\ProgramStatus;
use Pig\Tui\Test\FakeTerminal;

/**
 * Upstream's `program-status-reporter.test.ts` (#10607), with the mode's own compactions told
 * through `compactionStart()`/`compactionEnd()` where upstream sends `compaction_*` events.
 */
final class ProgramStatusReporterTest extends TestCase
{
    private FakeTerminal $terminal;

    private ?string $sessionName = null;

    private ProgramStatusReporter $reporter;

    private function arrange(?string $sessionName = null): void
    {
        $this->terminal = new FakeTerminal();
        $this->sessionName = $sessionName;
        $this->reporter = new ProgramStatusReporter(
            fn (): FakeTerminal => $this->terminal,
            fn (): ?string => $this->sessionName,
        );
    }

    /** @return list<ProgramStatus> */
    private function reports(): array
    {
        return $this->terminal->programStatuses;
    }

    private function send(AgentEvent ...$events): void
    {
        foreach ($events as $event) {
            $this->reporter->handleEvent($event);
        }
    }

    /** The last report without `app`, as upstream's `last()` compares it. */
    private function last(): array
    {
        $status = $this->reports()[array_key_last($this->reports())];

        return array_filter(
            ['state' => $status->state, 'kind' => $status->kind, 'message' => $status->message],
            static fn (?string $value): bool => $value !== null,
        );
    }

    private static function assistantEnd(StopReason $stopReason, ?string $errorMessage = null): MessageEndEvent
    {
        return new MessageEndEvent(new AssistantMessage(
            [new TextContent('secret assistant output')],
            Api::AnthropicMessages,
            'anthropic',
            'test-model',
            new Usage(),
            $stopReason,
            $errorMessage,
        ));
    }

    private static function settled(bool $aborted = false): AgentSettledEvent
    {
        return new AgentSettledEvent($aborted);
    }

    public function testReportsIdleWorkingDuringARunAndDoneOnceItSettles(): void
    {
        $this->arrange('Fix login');
        $this->reporter->report();
        $last = $this->reports()[array_key_last($this->reports())];
        $this->assertSame(['idle', 'pig', null, null], [$last->state, $last->app, $last->kind, $last->message]);

        $this->send(new AgentStartEvent());
        $this->assertSame(['state' => 'working', 'message' => 'Fix login'], $this->last());

        $this->send(self::assistantEnd(StopReason::ToolUse), self::assistantEnd(StopReason::Stop));
        $this->assertSame(['state' => 'working', 'message' => 'Fix login'], $this->last());

        $this->send(self::settled());
        $this->assertSame(['state' => 'done', 'message' => 'Fix login'], $this->last());
        $this->assertStringNotContainsString('secret assistant output', json_encode($this->reports(), JSON_THROW_ON_ERROR));
    }

    public function testReportsOnlyTheOutcomeOfTheRunRetriedErrorsFinalErrorsAndAborts(): void
    {
        $this->arrange();
        $this->send(new AgentStartEvent(), self::assistantEnd(StopReason::Error, 'overloaded'), self::assistantEnd(StopReason::Stop), self::settled());
        $this->assertSame(['state' => 'done'], $this->last());

        $this->send(new AgentStartEvent(), self::assistantEnd(StopReason::Error, "Invalid API key\n{details}"), self::settled());
        $this->assertSame(['state' => 'error', 'message' => 'Invalid API key'], $this->last());

        $this->send(new AgentStartEvent(), self::assistantEnd(StopReason::Aborted), self::settled(aborted: true));
        $this->assertSame(['state' => 'idle'], $this->last());

        // Aborted after a successful response, for example in an agent_before_settle hook or a retry delay.
        $this->send(new AgentStartEvent(), self::assistantEnd(StopReason::Stop), self::settled(aborted: true));
        $this->assertSame(['state' => 'idle'], $this->last());
    }

    public function testReportsAFailedRecoveryCompactionAsTheRunsErrorUnlessALaterResponseSucceeds(): void
    {
        $this->arrange();
        $this->send(
            new AgentStartEvent(),
            self::assistantEnd(StopReason::Length),
            new AutoCompactionStartEvent('too long'),
            new AutoCompactionEndEvent(false, false, null, "Compaction failed\nstack"),
            self::settled(),
        );
        $this->assertSame(['state' => 'error', 'message' => 'Compaction failed'], $this->last());

        $this->send(new AgentStartEvent());
        $this->reporter->compactionStart();
        $this->reporter->compactionEnd('threshold', false, 'Compaction failed');
        $this->send(self::assistantEnd(StopReason::Stop), self::settled());
        $this->assertSame(['state' => 'done'], $this->last());
    }

    public function testReportsCompactionInsideARunAndTheResultOfAManualCompaction(): void
    {
        $this->arrange('Session');
        $this->send(new AgentStartEvent());
        $this->reporter->compactionStart();
        $this->assertSame(['state' => 'working', 'message' => 'Compacting context'], $this->last());
        $this->reporter->compactionEnd('threshold', false, null);
        $this->assertSame(['state' => 'working', 'message' => 'Session'], $this->last());
        $this->send(self::assistantEnd(StopReason::Stop), self::settled());

        $this->reporter->compactionStart();
        $this->reporter->compactionEnd('manual', false, null);
        $this->assertSame(['state' => 'done', 'message' => 'Session'], $this->last());
        $this->reporter->compactionStart();
        $this->reporter->compactionEnd('manual', false, 'No model');
        $this->assertSame(['state' => 'error', 'message' => 'No model'], $this->last());
        $this->reporter->compactionStart();
        $this->reporter->compactionEnd('manual', true, null);
        $this->assertSame(['state' => 'idle'], $this->last());
    }

    public function testACancelledOverflowCompactionIsIdleOnceTheRunSettlesAborted(): void
    {
        $this->arrange();
        $this->send(
            new AgentStartEvent(),
            new AutoCompactionStartEvent('too long'),
            new AutoCompactionEndEvent(false, false, null, 'Summarising was cancelled.'),
            self::settled(aborted: true),
        );
        $this->assertSame(['state' => 'idle'], $this->last());
    }

    public function testReportsTheMostRecentOpenDialogAndTheUnderlyingStateOnceAllClose(): void
    {
        $this->arrange();
        $this->send(new AgentStartEvent());
        $this->reporter->setBlocked('extension-selector', new BlockedStatus('permission', 'Allow bash?'));
        $this->reporter->setBlocked('login', new BlockedStatus('auth', 'Log in to Anthropic'));
        $this->assertSame(['state' => 'blocked', 'kind' => 'auth', 'message' => 'Log in to Anthropic'], $this->last());

        // The run settles while the selector is still open.
        $this->reporter->setBlocked('login', null);
        $this->send(self::assistantEnd(StopReason::Stop), self::settled());
        $this->assertSame(['state' => 'blocked', 'kind' => 'permission', 'message' => 'Allow bash?'], $this->last());

        // Reopening a source replaces its dialog instead of stacking a second one.
        $this->reporter->setBlocked('extension-selector', new BlockedStatus('question', 'Pick one'));
        $this->assertSame(['state' => 'blocked', 'kind' => 'question', 'message' => 'Pick one'], $this->last());
        $this->reporter->setBlocked('extension-selector', null);
        $this->assertSame(['state' => 'done'], $this->last());
    }

    public function testSendsEachStatusOnceAndFollowsSessionNameChanges(): void
    {
        $this->arrange('Old');
        $this->send(new AgentStartEvent(), new TurnStartEvent(), self::assistantEnd(StopReason::ToolUse));
        $this->reporter->report();
        $this->assertCount(1, $this->reports());

        $this->sessionName = 'New';
        $this->reporter->report();
        $this->assertSame(['state' => 'working', 'message' => 'New'], $this->last());
    }

    public function testReturnsToIdleWhenTheSessionIsReplaced(): void
    {
        $this->arrange();
        $this->send(new AgentStartEvent(), self::assistantEnd(StopReason::Stop), self::settled());
        $this->reporter->reset();
        $this->assertSame(['state' => 'idle'], $this->last());
    }
}
