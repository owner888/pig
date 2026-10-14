<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;
use Pig\Agent\AgentToolResult;
use Pig\Ai\TextContent;
use Pig\Async\Loop;
use Pig\CodingAgent\Session\ToolProgress;

final class ToolProgressTest extends TestCase
{
    private string $session;

    #[\Override]
    protected function setUp(): void
    {
        Loop::reset();
        $this->session = sys_get_temp_dir() . '/tool-progress-' . bin2hex(random_bytes(4)) . '.jsonl';
    }

    #[\Override]
    protected function tearDown(): void
    {
        $sidecar = ToolProgress::pathOf($this->session);

        if (is_file($sidecar)) {
            unlink($sidecar);
        }

        Loop::reset();
    }

    public function testItLivesBesideTheSessionFile(): void
    {
        $this->assertSame('/s/2026_abc.running.json', ToolProgress::pathOf('/s/2026_abc.jsonl'));
    }

    public function testTheFirstReportIsWrittenAtOnceAndReadBack(): void
    {
        $progress = new ToolProgress(fn (): string => $this->session);

        $progress->update('c1', new AgentToolResult([new TextContent('line 1')]));

        $this->assertSame(['toolCallId' => 'c1', 'output' => 'line 1'], ToolProgress::read($this->session));
        // A command's output can be a `.env`.
        $this->assertSame(0600, fileperms(ToolProgress::pathOf($this->session)) & 0777);
    }

    public function testReportsInQuickSuccessionAreWrittenOnceTheIntervalIsUp(): void
    {
        $progress = new ToolProgress(fn (): string => $this->session);

        $progress->update('c1', new AgentToolResult([new TextContent('one')]));
        $progress->update('c1', new AgentToolResult([new TextContent('two')]));
        $progress->update('c1', new AgentToolResult([new TextContent('three')]));

        $this->assertSame('one', ToolProgress::read($this->session)['output'] ?? null, 'held back, not written three times');

        for ($tick = 0; $tick < 50 && !Loop::get()->isIdle(); $tick++) {
            Loop::get()->tick();
        }

        $this->assertSame('three', ToolProgress::read($this->session)['output'] ?? null, 'the last one is not lost');
    }

    public function testOnlyTheTailIsKept(): void
    {
        $progress = new ToolProgress(fn (): string => $this->session);

        $progress->update('c1', new AgentToolResult([new TextContent(implode("\n", range(1, 5000)))]));

        $output = ToolProgress::read($this->session)['output'] ?? '';
        $this->assertStringEndsWith("\n5000", $output);
        $this->assertStringNotContainsString("\n10\n", $output);
    }

    public function testClearRemovesItAndDropsWhatWasWaiting(): void
    {
        $progress = new ToolProgress(fn (): string => $this->session);

        $progress->update('c1', new AgentToolResult([new TextContent('one')]));
        $progress->update('c1', new AgentToolResult([new TextContent('two')]));
        $progress->clear();

        $this->assertTrue(Loop::get()->isIdle(), 'the held-back write is cancelled');
        $this->assertNull(ToolProgress::read($this->session));
    }

    public function testNothingIsWrittenForASessionThatIsNotSaved(): void
    {
        $progress = new ToolProgress(static fn (): ?string => null);

        $progress->update('c1', new AgentToolResult([new TextContent('one')]));
        $progress->clear();

        $this->assertNull(ToolProgress::read($this->session));
    }
}
