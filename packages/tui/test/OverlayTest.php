<?php

declare(strict_types=1);

namespace Pig\Tui\Test;

use PHPUnit\Framework\TestCase;
use Pig\Async\Loop;
use Pig\Tui\Component;
use Pig\Tui\OverlayOptions;
use Pig\Tui\TuiMainScreen;

/**
 * Overlay sizing, placement, stacking and compositing — upstream's `overlay-options.test.ts`,
 * `overlay-short-content.test.ts` and `tui-overlay-style-leak.test.ts`, read back through
 * `VirtualTerminal` (italic per cell stands in for xterm's `cell.isItalic()`).
 */
final class OverlayTest extends TestCase
{
    #[\Override]
    protected function setUp(): void
    {
        Loop::reset();
    }

    private static function renderAndFlush(TuiMainScreen $tui, VirtualTerminal $terminal): void
    {
        $tui->requestRender(true);
        $terminal->waitForRender();
    }

    private static function empty(): Component
    {
        return new StaticOverlay([]);
    }

    /**
     * Start with empty content and the given overlays shown, render, and hand back the viewport.
     *
     * @param list<array{0: Component, 1: ?OverlayOptions}> $overlays
     * @return list<string>
     */
    private static function show(VirtualTerminal $terminal, array $overlays, ?Component $content = null): array
    {
        $tui = new TuiMainScreen($terminal);
        $tui->addChild($content ?? self::empty());
        foreach ($overlays as [$overlay, $options]) {
            $tui->showOverlay($overlay, $options);
        }
        $tui->start();
        self::renderAndFlush($tui, $terminal);
        $viewport = $terminal->getViewport();
        $tui->stop();

        return $viewport;
    }

    // overlay-options.test.ts — width overflow protection

    public function testTruncatesOverlayLinesThatExceedDeclaredWidth(): void
    {
        $terminal = new VirtualTerminal(80, 24);
        $viewport = self::show($terminal, [[new StaticOverlay([str_repeat('X', 100)]), new OverlayOptions(width: 20)]]);

        // Should not crash; the renderer throws on a line wider than the terminal.
        $this->assertCount(24, $viewport);
    }

    public function testHandlesOverlayWithComplexAnsiSequencesWithoutCrashing(): void
    {
        $terminal = new VirtualTerminal(80, 24);
        $complexLine = "\x1b[48;2;40;50;40m \x1b[38;2;128;128;128mSome styled content\x1b[39m\x1b[49m"
            . "\x1b]8;;http://example.com\x07link\x1b]8;;\x07"
            . str_repeat(' more content ', 10);
        $viewport = self::show($terminal, [[new StaticOverlay([$complexLine, $complexLine, $complexLine]), new OverlayOptions(width: 60)]]);

        $this->assertNotEmpty($viewport);
    }

    public function testHandlesOverlayCompositedOnStyledBaseContent(): void
    {
        $terminal = new VirtualTerminal(80, 24);
        $styled = new class implements Component {
            #[\Override]
            public function render(int $width): array
            {
                $line = "\x1b[1m\x1b[38;2;255;0;0m" . str_repeat('X', $width) . "\x1b[0m";

                return [$line, $line, $line];
            }

            #[\Override]
            public function invalidate(): void
            {
            }
        };
        $viewport = self::show($terminal, [[new StaticOverlay(['OVERLAY']), new OverlayOptions(width: 20, anchor: 'center')]], $styled);

        $hasOverlay = array_filter($viewport, static fn (string $line): bool => str_contains($line, 'OVERLAY')) !== [];
        $this->assertTrue($hasOverlay, 'Overlay should be visible');
    }

    public function testHandlesWideCharactersAtOverlayBoundary(): void
    {
        $terminal = new VirtualTerminal(80, 24);
        $viewport = self::show($terminal, [[new StaticOverlay(['中文日本語한글テスト漢字']), new OverlayOptions(width: 15)]]);

        $this->assertNotEmpty($viewport);
    }

    public function testHandlesOverlayPositionedAtTerminalEdge(): void
    {
        $terminal = new VirtualTerminal(80, 24);
        // Col 60 with width 20 fits exactly at the right edge.
        $viewport = self::show($terminal, [[new StaticOverlay([str_repeat('X', 50)]), new OverlayOptions(col: 60, width: 20)]]);

        $this->assertNotEmpty($viewport);
    }

    public function testHandlesOverlayOnBaseContentWithOscSequences(): void
    {
        $terminal = new VirtualTerminal(80, 24);
        $hyperlinks = new class implements Component {
            #[\Override]
            public function render(int $width): array
            {
                $link = "\x1b]8;;file:///path/to/file.ts\x07file.ts\x1b]8;;\x07";
                $line = "See {$link} for details " . str_repeat('X', $width - 30);

                return [$line, $line, $line];
            }

            #[\Override]
            public function invalidate(): void
            {
            }
        };
        $viewport = self::show($terminal, [[new StaticOverlay(['OVERLAY-TEXT']), new OverlayOptions(anchor: 'center', width: 20)]], $hyperlinks);

        $this->assertNotEmpty($viewport);
    }

    // width percentage

    public function testRendersOverlayAtPercentageOfTerminalWidth(): void
    {
        $terminal = new VirtualTerminal(100, 24);
        $overlay = new StaticOverlay(['test']);
        self::show($terminal, [[$overlay, new OverlayOptions(width: '50%')]]);

        $this->assertSame(50, $overlay->requestedWidth);
    }

    public function testRespectsMinWidthWhenWidthPercentResultsInSmallerWidth(): void
    {
        $terminal = new VirtualTerminal(100, 24);
        $overlay = new StaticOverlay(['test']);
        self::show($terminal, [[$overlay, new OverlayOptions(width: '10%', minWidth: 30)]]);

        $this->assertSame(30, $overlay->requestedWidth);
    }

    // anchor positioning

    public function testPositionsOverlayAtTopLeft(): void
    {
        $terminal = new VirtualTerminal(80, 24);
        $viewport = self::show($terminal, [[new StaticOverlay(['TOP-LEFT']), new OverlayOptions(anchor: 'top-left', width: 10)]]);

        $this->assertStringStartsWith('TOP-LEFT', $viewport[0], "Expected TOP-LEFT at start, got: {$viewport[0]}");
    }

    public function testPositionsOverlayAtBottomRight(): void
    {
        $terminal = new VirtualTerminal(80, 24);
        $viewport = self::show($terminal, [[new StaticOverlay(['BTM-RIGHT']), new OverlayOptions(anchor: 'bottom-right', width: 10)]]);

        $lastRow = $viewport[23];
        $this->assertStringContainsString('BTM-RIGHT', $lastRow, "Expected BTM-RIGHT on last row, got: {$lastRow}");
        $this->assertStringEndsWith('BTM-RIGHT', rtrim($lastRow), "Expected BTM-RIGHT at end, got: {$lastRow}");
    }

    public function testPositionsOverlayAtTopCenter(): void
    {
        $terminal = new VirtualTerminal(80, 24);
        $viewport = self::show($terminal, [[new StaticOverlay(['CENTERED']), new OverlayOptions(anchor: 'top-center', width: 10)]]);

        $firstRow = $viewport[0];
        $this->assertStringContainsString('CENTERED', $firstRow, "Expected CENTERED on first row, got: {$firstRow}");
        $colIndex = strpos($firstRow, 'CENTERED');
        $this->assertTrue($colIndex >= 30 && $colIndex <= 40, "Expected centered, got col {$colIndex}");
    }

    // margin

    public function testClampsNegativeMarginsToZero(): void
    {
        $terminal = new VirtualTerminal(80, 24);
        $viewport = self::show($terminal, [[
            new StaticOverlay(['NEG-MARGIN']),
            new OverlayOptions(anchor: 'top-left', width: 12, margin: ['top' => -5, 'left' => -10, 'right' => 0, 'bottom' => 0]),
        ]]);

        $this->assertStringStartsWith('NEG-MARGIN', $viewport[0], "Expected NEG-MARGIN at start of row 0, got: {$viewport[0]}");
    }

    public function testRespectsMarginAsNumber(): void
    {
        $terminal = new VirtualTerminal(80, 24);
        $viewport = self::show($terminal, [[new StaticOverlay(['MARGIN']), new OverlayOptions(anchor: 'top-left', width: 10, margin: 5)]]);

        $this->assertStringNotContainsString('MARGIN', $viewport[0], 'Should not be on row 0');
        $this->assertStringNotContainsString('MARGIN', $viewport[4], 'Should not be on row 4');
        $this->assertStringContainsString('MARGIN', $viewport[5], "Expected MARGIN on row 5, got: {$viewport[5]}");
        $this->assertSame(5, strpos($viewport[5], 'MARGIN'));
    }

    public function testRespectsMarginObject(): void
    {
        $terminal = new VirtualTerminal(80, 24);
        $viewport = self::show($terminal, [[
            new StaticOverlay(['MARGIN']),
            new OverlayOptions(anchor: 'top-left', width: 10, margin: ['top' => 2, 'left' => 3, 'right' => 0, 'bottom' => 0]),
        ]]);

        $this->assertStringContainsString('MARGIN', $viewport[2], "Expected MARGIN on row 2, got: {$viewport[2]}");
        $this->assertSame(3, strpos($viewport[2], 'MARGIN'));
    }

    // offset

    public function testAppliesOffsetXAndOffsetYFromAnchorPosition(): void
    {
        $terminal = new VirtualTerminal(80, 24);
        $viewport = self::show($terminal, [[new StaticOverlay(['OFFSET']), new OverlayOptions(anchor: 'top-left', width: 10, offsetX: 10, offsetY: 5)]]);

        $this->assertStringContainsString('OFFSET', $viewport[5], "Expected OFFSET on row 5, got: {$viewport[5]}");
        $this->assertSame(10, strpos($viewport[5], 'OFFSET'));
    }

    // percentage positioning

    public function testPositionsWithRowPercentAndColPercent(): void
    {
        $terminal = new VirtualTerminal(80, 24);
        $viewport = self::show($terminal, [[new StaticOverlay(['PCT']), new OverlayOptions(width: 10, row: '50%', col: '50%')]]);

        $foundRow = -1;
        foreach ($viewport as $index => $line) {
            if (str_contains($line, 'PCT')) {
                $foundRow = $index;
                break;
            }
        }
        $this->assertTrue($foundRow >= 10 && $foundRow <= 13, "Expected centered row, got {$foundRow}");
    }

    public function testRowPercentZeroPositionsAtTop(): void
    {
        $terminal = new VirtualTerminal(80, 24);
        $viewport = self::show($terminal, [[new StaticOverlay(['TOP']), new OverlayOptions(width: 10, row: '0%')]]);

        $this->assertStringContainsString('TOP', $viewport[0], "Expected TOP on row 0, got: {$viewport[0]}");
    }

    public function testRowPercentHundredPositionsAtBottom(): void
    {
        $terminal = new VirtualTerminal(80, 24);
        $viewport = self::show($terminal, [[new StaticOverlay(['BOTTOM']), new OverlayOptions(width: 10, row: '100%')]]);

        $this->assertStringContainsString('BOTTOM', $viewport[23], "Expected BOTTOM on last row, got: {$viewport[23]}");
    }

    // maxHeight

    public function testTruncatesOverlayToMaxHeight(): void
    {
        $terminal = new VirtualTerminal(80, 24);
        $viewport = self::show($terminal, [[new StaticOverlay(['Line 1', 'Line 2', 'Line 3', 'Line 4', 'Line 5']), new OverlayOptions(maxHeight: 3)]]);

        $content = implode("\n", $viewport);
        $this->assertStringContainsString('Line 1', $content);
        $this->assertStringContainsString('Line 2', $content);
        $this->assertStringContainsString('Line 3', $content);
        $this->assertStringNotContainsString('Line 4', $content);
        $this->assertStringNotContainsString('Line 5', $content);
    }

    public function testTruncatesOverlayToMaxHeightPercent(): void
    {
        $terminal = new VirtualTerminal(80, 10);
        // 10 lines in a 10-row terminal at 50% show 5 lines.
        $lines = array_map(static fn (int $i): string => "L{$i}", range(1, 10));
        $viewport = self::show($terminal, [[new StaticOverlay($lines), new OverlayOptions(maxHeight: '50%')]]);

        $content = implode("\n", $viewport);
        $this->assertStringContainsString('L1', $content);
        $this->assertStringContainsString('L5', $content);
        $this->assertStringNotContainsString('L6', $content);
    }

    // absolute positioning

    public function testRowAndColOverrideAnchor(): void
    {
        $terminal = new VirtualTerminal(80, 24);
        $viewport = self::show($terminal, [[new StaticOverlay(['ABSOLUTE']), new OverlayOptions(anchor: 'bottom-right', row: 3, col: 5, width: 10)]]);

        $this->assertStringContainsString('ABSOLUTE', $viewport[3], "Expected ABSOLUTE on row 3, got: {$viewport[3]}");
        $this->assertSame(5, strpos($viewport[3], 'ABSOLUTE'));
    }

    // stacked overlays

    public function testRendersMultipleOverlaysWithLaterOnesOnTop(): void
    {
        $terminal = new VirtualTerminal(80, 24);
        $viewport = self::show($terminal, [
            [new StaticOverlay(['FIRST-OVERLAY']), new OverlayOptions(anchor: 'top-left', width: 20)],
            [new StaticOverlay(['SECOND']), new OverlayOptions(anchor: 'top-left', width: 10)],
        ]);

        $this->assertStringContainsString('SECOND', $viewport[0], "Expected SECOND on row 0, got: {$viewport[0]}");
    }

    public function testHandlesOverlaysAtDifferentPositionsWithoutInterference(): void
    {
        $terminal = new VirtualTerminal(80, 24);
        $viewport = self::show($terminal, [
            [new StaticOverlay(['TOP-LEFT']), new OverlayOptions(anchor: 'top-left', width: 15)],
            [new StaticOverlay(['BTM-RIGHT']), new OverlayOptions(anchor: 'bottom-right', width: 15)],
        ]);

        $this->assertStringContainsString('TOP-LEFT', $viewport[0], "Expected TOP-LEFT on row 0, got: {$viewport[0]}");
        $this->assertStringContainsString('BTM-RIGHT', $viewport[23], "Expected BTM-RIGHT on row 23, got: {$viewport[23]}");
    }

    public function testProperlyHidesOverlaysInStackOrder(): void
    {
        $terminal = new VirtualTerminal(80, 24);
        $tui = new TuiMainScreen($terminal);
        $tui->addChild(self::empty());
        $tui->showOverlay(new StaticOverlay(['FIRST']), new OverlayOptions(anchor: 'top-left', width: 10));
        $tui->showOverlay(new StaticOverlay(['SECOND']), new OverlayOptions(anchor: 'top-left', width: 10));
        $tui->start();
        self::renderAndFlush($tui, $terminal);

        $this->assertStringContainsString('SECOND', $terminal->getViewport()[0], 'SECOND should be visible initially');

        $tui->hideOverlay();
        self::renderAndFlush($tui, $terminal);

        $this->assertStringContainsString('FIRST', $terminal->getViewport()[0], 'FIRST should be visible after hiding SECOND');
        $tui->stop();
    }

    // hiding after stop — upstream issue #10026

    public function testHideOverlayAfterStopLeavesTheCursorVisible(): void
    {
        $terminal = new VirtualTerminal(80, 24);
        $tui = new TuiMainScreen($terminal);
        $tui->start();
        $tui->showOverlay(new StaticOverlay(['OVERLAY']), new OverlayOptions(nonCapturing: true));

        $tui->stop();
        $tui->hideOverlay();

        $this->assertTrue($terminal->cursorVisible);
    }

    public function testOverlayHandleHideAfterStopLeavesTheCursorVisible(): void
    {
        $terminal = new VirtualTerminal(80, 24);
        $tui = new TuiMainScreen($terminal);
        $tui->start();
        $handle = $tui->showOverlay(new StaticOverlay(['OVERLAY']), new OverlayOptions(nonCapturing: true));

        $tui->stop();
        $handle->hide();

        $this->assertTrue($terminal->cursorVisible);
    }

    // overlay-short-content.test.ts

    public function testRendersOverlayWhenContentIsShorterThanTerminalHeight(): void
    {
        $terminal = new VirtualTerminal(80, 24);
        $tui = new TuiMainScreen($terminal);
        $tui->addChild(new StaticOverlay(['Line 1', 'Line 2', 'Line 3']));
        $tui->showOverlay(new StaticOverlay(['OVERLAY_TOP', 'OVERLAY_MID', 'OVERLAY_BOT']));
        $tui->start();
        $terminal->waitForRender();

        $viewport = $terminal->getViewport();
        $hasOverlay = array_filter($viewport, static fn (string $line): bool => str_contains($line, 'OVERLAY')) !== [];
        $this->assertTrue($hasOverlay, "Overlay should be visible when content is shorter than terminal:\n" . implode("\n", $viewport));
        $tui->stop();
    }

    // tui-overlay-style-leak.test.ts

    public function testDoesNotLeakStylesWhenATrailingResetSitsBeyondTheLastVisibleColumnWithoutOverlay(): void
    {
        $width = 20;
        $baseLine = "\x1b[3m" . str_repeat('X', $width) . "\x1b[23m";
        $terminal = new VirtualTerminal($width, 6);
        $tui = new TuiMainScreen($terminal);
        $tui->addChild(new StaticOverlay([$baseLine, 'INPUT']));
        $tui->start();
        self::renderAndFlush($tui, $terminal);

        $this->assertTrue($terminal->isItalic(0, 0), 'the base line itself is italic');
        $this->assertFalse($terminal->isItalic(1, 0));
        $tui->stop();
    }

    public function testDoesNotLeakStylesWhenOverlaySlicingDropsTrailingSgrResets(): void
    {
        $width = 20;
        $baseLine = "\x1b[3m" . str_repeat('X', $width) . "\x1b[23m";
        $terminal = new VirtualTerminal($width, 6);
        $tui = new TuiMainScreen($terminal);
        $tui->addChild(new StaticOverlay([$baseLine, 'INPUT']));
        $tui->showOverlay(new StaticOverlay(['OVR']), new OverlayOptions(row: 0, col: 5, width: 3));
        $tui->start();
        self::renderAndFlush($tui, $terminal);

        $this->assertFalse($terminal->isItalic(1, 0));
        $tui->stop();
    }
}
