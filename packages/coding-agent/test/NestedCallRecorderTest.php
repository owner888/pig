<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;
use Pig\Ai\Cost;
use Pig\Ai\ToolCall;
use Pig\Ai\Usage;
use Pig\CodingAgent\Session\NestedCallRecorder;

final class NestedCallRecorderTest extends TestCase
{
    public function testNothingRecordedIsNoRecordAtAll(): void
    {
        $this->assertNull((new NestedCallRecorder())->snapshot(), 'upstream leaves `nestedCalls` off a result that made no calls');
        $this->assertNull((new NestedCallRecorder())->totalUsage());
    }

    public function testACallIsRecordedAsItRunsAndFinishedWithItsOutcome(): void
    {
        $recorder = new NestedCallRecorder();
        $one = $recorder->start(new ToolCall('c/1', 'read', ['path' => 'a.php']));
        $two = $recorder->start(new ToolCall('c/2', 'bash', ['command' => 'id']));

        $running = $recorder->snapshot();
        $this->assertFalse($running->complete, 'a call still running makes the record incomplete');
        $this->assertSame(['unfinished', 'unfinished'], array_map(static fn ($c) => $c->status, $running->calls));

        $recorder->finish($one, false, 'file contents');
        $recorder->finish($two, true, str_repeat('x', 600));
        $recorder->addUsage(new Usage(10, 2, 0, 0, 12, new Cost(0.001, 0.002, 0.0, 0.0, 0.003)));
        $recorder->addUsage(new Usage(5, 1, 0, 0, 6, new Cost(0.0005, 0.001, 0.0, 0.0, 0.0015)));

        $done = $recorder->snapshot();
        $this->assertTrue($done->complete);
        $this->assertSame(['ok', 'error'], array_map(static fn ($c) => $c->status, $done->calls));
        $this->assertNull($done->calls[0]->error, 'only an error keeps its text');
        $this->assertSame(500, strlen($done->calls[1]->error), 'an error is cut to MAX_ERROR_CHARS');
        $this->assertSame(['path' => 'a.php'], $done->calls[0]->arguments);
        $this->assertIsInt($done->calls[0]->durationMs);
        $this->assertSame(18, $recorder->totalUsage()->totalTokens);
        $this->assertEqualsWithDelta(0.0045, $recorder->totalUsage()->cost->total, 1e-9);
        // The snapshot is a copy: finishing a call later does not rewrite what was taken.
        $this->assertSame('unfinished', $running->calls[0]->status);
    }

    public function testArgumentsOverTheLimitsAreLeftOutWithTheirSizeAndTheRecordSaysSo(): void
    {
        $recorder = new NestedCallRecorder();
        $big = $recorder->start(new ToolCall('c/1', 'write', ['content' => str_repeat('a', NestedCallRecorder::MAX_ARGUMENT_BYTES_PER_CALL)]));
        $recorder->finish($big, false, '');

        $snapshot = $recorder->snapshot();
        $this->assertNull($snapshot->calls[0]->arguments);
        $this->assertGreaterThan(NestedCallRecorder::MAX_ARGUMENT_BYTES_PER_CALL, $snapshot->calls[0]->argumentsBytes);
        $this->assertFalse($snapshot->complete);

        // The total: four calls of 7 KB each fit the per-call limit and run past the 32 KB total.
        $recorder = new NestedCallRecorder();
        for ($i = 1; $i <= 5; $i++) {
            $recorder->finish($recorder->start(new ToolCall("c/{$i}", 'write', ['content' => str_repeat('b', 7 * 1024)])), false, '');
        }
        $kept = array_map(static fn ($c): bool => $c->arguments !== null, $recorder->snapshot()->calls);
        $this->assertSame([true, true, true, true, false], $kept);
        $this->assertFalse($recorder->snapshot()->complete);
    }

    public function testCallsPastTheCountAreDroppedButStillBilled(): void
    {
        $recorder = new NestedCallRecorder();
        for ($i = 1; $i <= NestedCallRecorder::MAX_CALLS; $i++) {
            $recorder->finish($recorder->start(new ToolCall("c/{$i}", 'read', [])), false, '');
        }
        $this->assertTrue($recorder->snapshot()->complete);

        $dropped = $recorder->start(new ToolCall('c/257', 'read', []));
        $this->assertNull($dropped);
        $recorder->finish($dropped, false, '');
        $recorder->addUsage(new Usage(1, 1, 0, 0, 2));

        $snapshot = $recorder->snapshot();
        $this->assertCount(NestedCallRecorder::MAX_CALLS, $snapshot->calls);
        $this->assertFalse($snapshot->complete, 'a dropped call makes the record incomplete');
        $this->assertSame(2, $recorder->totalUsage()->totalTokens, 'usage counts calls the record dropped');
    }
}
