<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;
use Pig\Async\Loop;
use Pig\CodingAgent\Interactive\BranchSummaryStatusIndicator;
use Pig\CodingAgent\Interactive\CompactionStatusIndicator;
use Pig\CodingAgent\Interactive\RetryStatusIndicator;
use Pig\CodingAgent\Interactive\StatusIndicator;
use Pig\CodingAgent\Interactive\WorkingStatusIndicator;
use Pig\CodingAgent\Keybindings;
use Pig\Tui\Ansi;
use Pig\Tui\Test\FakeTerminal;
use Pig\Tui\TuiMainScreen;

/**
 * Upstream's `status-indicator.ts`: a loader that knows what kind of status it is, one class a
 * kind, with the labels upstream gives them.
 */
final class StatusIndicatorTest extends TestCase
{
    use GlobalThemeFixture;

    private TuiMainScreen $tui;

    #[\Override]
    protected function setUp(): void
    {
        Loop::reset();
        $this->setUpGlobalTheme();
        $this->tui = new TuiMainScreen(new FakeTerminal());
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->tearDownGlobalTheme();
    }

    private static function text(StatusIndicator $indicator): string
    {
        return trim(Ansi::strip(implode("\n", $indicator->render(120))));
    }

    public function testEachKindSaysWhatUpstreamSays(): void
    {
        $keys = new Keybindings([]);

        $working = new WorkingStatusIndicator($this->tui);
        $this->assertSame('working', $working->kind);
        $this->assertStringContainsString('Working', self::text($working));
        $working->dispose();

        $custom = new WorkingStatusIndicator($this->tui, 'Pondering', ['frames' => ['-', '='], 'intervalMs' => 50]);
        $this->assertStringContainsString('Pondering', self::text($custom));
        $custom->dispose();

        $manual = new CompactionStatusIndicator($this->tui, $keys, 'manual');
        $this->assertSame('compaction', $manual->kind);
        $this->assertStringEndsWith('Compacting context... (escape to cancel)', self::text($manual));
        $manual->dispose();

        $overflow = new CompactionStatusIndicator($this->tui, $keys, 'overflow');
        $this->assertStringEndsWith('Context overflow detected, Auto-compacting... (escape to cancel)', self::text($overflow));
        $overflow->dispose();

        $threshold = new CompactionStatusIndicator($this->tui, $keys, 'threshold');
        $this->assertStringEndsWith('Auto-compacting... (escape to cancel)', self::text($threshold));
        $this->assertStringNotContainsString('overflow', self::text($threshold));
        $threshold->dispose();

        $branch = new BranchSummaryStatusIndicator($this->tui, $keys);
        $this->assertSame('branchSummary', $branch->kind);
        $this->assertStringEndsWith('Summarizing branch... (escape to cancel)', self::text($branch));
        $branch->dispose();
    }

    public function testARetryCountsDownOnceASecondAndDisposingStopsTheCount(): void
    {
        // Upstream's `RetryStatusIndicator` with its `CountdownTimer`: `Math.ceil` of the delay,
        // then one less each second; `dispose()` cancels the tick with the spinner, so a retry
        // taken down early leaves nothing behind to redraw it.
        $retry = new RetryStatusIndicator($this->tui, new Keybindings([]), 2, 3, 2.4);
        $this->assertSame('retry', $retry->kind);
        $this->assertStringEndsWith('Retrying (2/3) in 3s... (escape to cancel)', self::text($retry));

        $this->assertFalse(Loop::get()->isIdle(), 'a tick is armed');
        $retry->dispose();
        // The spinner and the countdown both gone: once the render the loader asked for has run,
        // nothing keeps the loop awake.
        Loop::get()->tick();
        Loop::get()->tick();
        $this->assertTrue(Loop::get()->isIdle());
    }
}
