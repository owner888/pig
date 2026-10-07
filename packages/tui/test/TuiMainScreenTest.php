<?php

declare(strict_types=1);

namespace Pig\Tui\Test;

use PHPUnit\Framework\TestCase;
use Pig\Async\Loop;
use Pig\Tui\Component;
use Pig\Tui\Focusable;
use Pig\Tui\InputHandler;
use Pig\Tui\TUI;
use Pig\Tui\TuiMainScreen;
use Pig\Tui\Width;

/**
 * Upstream's `tui-render.test.ts` and `tui-shrink.test.ts`, read back through a VT emulator.
 * Left out: the 1 MiB write-splitting cases (no such limit in PHP), the debug-log directory, and
 * the Kitty image placement cases.
 */
final class TuiMainScreenTest extends TestCase
{
    #[\Override]
    protected function setUp(): void
    {
        Loop::reset();
        putenv('TERMUX_VERSION');
    }

    private static function lines(Component|null $unused = null): Component
    {
        return new class implements Component {
            /** @var list<string> */
            public array $lines = [];

            public int $renderCount = 0;

            #[\Override]
            public function render(int $width): array
            {
                $this->renderCount++;

                return $this->lines;
            }

            #[\Override]
            public function invalidate(): void
            {
            }
        };
    }

    /** @param list<string> $lines */
    private function start(VirtualTerminal $terminal, array $lines): array
    {
        $tui = new TuiMainScreen($terminal);
        $component = self::lines();
        $component->lines = $lines;
        $tui->addChild($component);
        $tui->start();
        $terminal->waitForRender();

        return [$tui, $component];
    }

    private static function range(int $count, string $prefix = 'Line '): array
    {
        return array_map(static fn (int $i): string => "{$prefix}{$i}", range(0, $count - 1));
    }

    public function testRendersKeyboardInputWithoutWaitingForAThrottledFrame(): void
    {
        $terminal = new VirtualTerminal(40, 10);
        $tui = new TuiMainScreen($terminal);
        $component = new class implements Component, InputHandler {
            /** @var list<string> */
            public array $lines = ['initial'];

            public int $renderCount = 0;

            #[\Override]
            public function render(int $width): array
            {
                $this->renderCount++;

                return $this->lines;
            }

            #[\Override]
            public function invalidate(): void
            {
            }

            #[\Override]
            public function handleInput(string $data): void
            {
                $this->lines = [$data];
            }
        };
        $tui->addChild($component);
        $tui->setFocus($component);
        $tui->start();
        $tui->renderNow();
        $before = $component->renderCount;

        // Queue a normal throttled render first. Keyboard input should preempt it.
        $component->lines = ['pending'];
        $tui->requestRender();
        $terminal->sendInput('first');
        $terminal->sendInput('second');
        $terminal->sendInput('typed');
        Loop::get()->tick();

        $this->assertSame($before + 1, $component->renderCount);
        $this->assertSame(['typed'], $component->lines);
        $tui->stop();
    }

    public function testTriggersFullReRenderWhenTerminalHeightChanges(): void
    {
        $terminal = new VirtualTerminal(40, 10);
        [$tui] = $this->start($terminal, ['Line 0', 'Line 1', 'Line 2']);
        $initial = $tui->fullRedraws();

        $terminal->resize(40, 15);
        $terminal->waitForRender();

        $this->assertGreaterThan($initial, $tui->fullRedraws());
        $this->assertStringContainsString('Line 0', $terminal->getViewport()[0]);
        $tui->stop();
    }

    public function testSkipsFullReRenderOnHeightChangesInTermux(): void
    {
        putenv('TERMUX_VERSION=1');
        try {
            $terminal = new VirtualTerminal(40, 10);
            [$tui] = $this->start($terminal, self::range(20));
            $terminal->clearWrites();
            $initial = $tui->fullRedraws();

            foreach ([15, 8, 14, 11] as $height) {
                $terminal->resize(40, $height);
                $terminal->waitForRender();
            }

            $this->assertSame($initial, $tui->fullRedraws());
            $this->assertStringNotContainsString("\x1b[2J", $terminal->output());
            $this->assertStringNotContainsString("\x1b[3J", $terminal->output());
            $this->assertStringContainsString('Line 19', implode("\n", $terminal->getViewport()));
            $tui->stop();
        } finally {
            putenv('TERMUX_VERSION');
        }
    }

    public function testTriggersFullReRenderWhenTerminalWidthChanges(): void
    {
        $terminal = new VirtualTerminal(40, 10);
        [$tui] = $this->start($terminal, ['Line 0', 'Line 1', 'Line 2']);
        $initial = $tui->fullRedraws();

        $terminal->resize(60, 10);
        $terminal->waitForRender();

        $this->assertGreaterThan($initial, $tui->fullRedraws());
        $tui->stop();
    }

    public function testClearsEmptyRowsWhenContentShrinksSignificantly(): void
    {
        $terminal = new VirtualTerminal(40, 10);
        $tui = new TuiMainScreen($terminal);
        $tui->setClearOnShrink(true);
        $component = self::lines();
        $component->lines = self::range(6);
        $tui->addChild($component);
        $tui->start();
        $terminal->waitForRender();
        $initial = $tui->fullRedraws();

        $component->lines = ['Line 0', 'Line 1'];
        $tui->requestRender();
        $terminal->waitForRender();

        $this->assertGreaterThan($initial, $tui->fullRedraws());
        $viewport = $terminal->getViewport();
        $this->assertSame(['Line 0', 'Line 1', '', ''], array_slice($viewport, 0, 4));
        $tui->stop();
    }

    public function testHandlesShrinkToSingleLine(): void
    {
        $terminal = new VirtualTerminal(40, 10);
        $tui = new TuiMainScreen($terminal);
        $tui->setClearOnShrink(true);
        $component = self::lines();
        $component->lines = self::range(4);
        $tui->addChild($component);
        $tui->start();
        $terminal->waitForRender();

        $component->lines = ['Only line'];
        $tui->requestRender();
        $terminal->waitForRender();

        $this->assertSame(['Only line', ''], array_slice($terminal->getViewport(), 0, 2));
        $tui->stop();
    }

    public function testHandlesShrinkToEmpty(): void
    {
        $terminal = new VirtualTerminal(40, 10);
        $tui = new TuiMainScreen($terminal);
        $tui->setClearOnShrink(true);
        $component = self::lines();
        $component->lines = self::range(3);
        $tui->addChild($component);
        $tui->start();
        $terminal->waitForRender();

        $component->lines = [];
        $tui->requestRender();
        $terminal->waitForRender();

        $this->assertSame(['', ''], array_slice($terminal->getViewport(), 0, 2));
        $tui->stop();
    }

    public function testTracksCursorCorrectlyWhenContentShrinksWithUnchangedRemainingLines(): void
    {
        $terminal = new VirtualTerminal(40, 10);
        [$tui, $component] = $this->start($terminal, self::range(5));

        $component->lines = self::range(3);
        $tui->requestRender();
        $terminal->waitForRender();

        $component->lines = ['Line 0', 'CHANGED', 'Line 2'];
        $tui->requestRender();
        $terminal->waitForRender();

        $this->assertSame('CHANGED', $terminal->getViewport()[1]);
        $tui->stop();
    }

    public function testRendersCorrectlyWhenOnlyAMiddleLineChanges(): void
    {
        $terminal = new VirtualTerminal(40, 10);
        [$tui, $component] = $this->start($terminal, ['Header', 'Working...', 'Footer']);

        foreach (['|', '/', '-', '\\'] as $frame) {
            $component->lines = ['Header', "Working {$frame}", 'Footer'];
            $tui->requestRender();
            $terminal->waitForRender();

            $this->assertSame(['Header', "Working {$frame}", 'Footer'], array_slice($terminal->getViewport(), 0, 3));
        }
        $tui->stop();
    }

    public function testResetsStylesAfterEachRenderedLine(): void
    {
        $terminal = new VirtualTerminal(20, 6);
        [$tui] = $this->start($terminal, ["\x1b[3mItalic", 'Plain']);

        // Every line ends by closing its styling, so the next one starts plain.
        $this->assertStringContainsString("\x1b[3mItalic" . Width::SEGMENT_RESET, $terminal->output());
        $tui->stop();
    }

    public function testRendersCorrectlyWhenFirstLineChangesButRestStaysSame(): void
    {
        $terminal = new VirtualTerminal(40, 10);
        [$tui, $component] = $this->start($terminal, self::range(4));

        $component->lines = ['CHANGED', 'Line 1', 'Line 2', 'Line 3'];
        $tui->requestRender();
        $terminal->waitForRender();

        $this->assertSame(['CHANGED', 'Line 1', 'Line 2', 'Line 3'], array_slice($terminal->getViewport(), 0, 4));
        $tui->stop();
    }

    public function testRendersCorrectlyWhenLastLineChangesButRestStaysSame(): void
    {
        $terminal = new VirtualTerminal(40, 10);
        [$tui, $component] = $this->start($terminal, self::range(4));

        $component->lines = ['Line 0', 'Line 1', 'Line 2', 'CHANGED'];
        $tui->requestRender();
        $terminal->waitForRender();

        $this->assertSame(['Line 0', 'Line 1', 'Line 2', 'CHANGED'], array_slice($terminal->getViewport(), 0, 4));
        $tui->stop();
    }

    public function testRendersCorrectlyWhenMultipleNonAdjacentLinesChange(): void
    {
        $terminal = new VirtualTerminal(40, 10);
        [$tui, $component] = $this->start($terminal, self::range(5));

        $component->lines = ['Line 0', 'CHANGED 1', 'Line 2', 'CHANGED 3', 'Line 4'];
        $tui->requestRender();
        $terminal->waitForRender();

        $this->assertSame(['Line 0', 'CHANGED 1', 'Line 2', 'CHANGED 3', 'Line 4'], array_slice($terminal->getViewport(), 0, 5));
        $tui->stop();
    }

    public function testHandlesTransitionFromContentToEmptyAndBackToContent(): void
    {
        $terminal = new VirtualTerminal(40, 10);
        [$tui, $component] = $this->start($terminal, self::range(3));
        $this->assertSame('Line 0', $terminal->getViewport()[0]);

        $component->lines = [];
        $tui->requestRender();
        $terminal->waitForRender();

        $component->lines = ['New Line 0', 'New Line 1'];
        $tui->requestRender();
        $terminal->waitForRender();

        $this->assertSame(['New Line 0', 'New Line 1'], array_slice($terminal->getViewport(), 0, 2));
        $tui->stop();
    }

    public function testFullReRendersWhenDeletedLinesMoveTheViewportUpward(): void
    {
        $terminal = new VirtualTerminal(20, 5);
        [$tui, $component] = $this->start($terminal, self::range(12));
        $initial = $tui->fullRedraws();

        $component->lines = self::range(7);
        $tui->requestRender();
        $terminal->waitForRender();

        $this->assertGreaterThan($initial, $tui->fullRedraws());
        $this->assertSame(['Line 2', 'Line 3', 'Line 4', 'Line 5', 'Line 6'], $terminal->getViewport());
        $tui->stop();
    }

    public function testAppendsAfterAShrinkWithoutAnotherFullRedrawOnceTheViewportIsReset(): void
    {
        $terminal = new VirtualTerminal(20, 5);
        [$tui, $component] = $this->start($terminal, self::range(8));
        $initial = $tui->fullRedraws();

        $component->lines = ['Line 0', 'Line 1'];
        $tui->requestRender();
        $terminal->waitForRender();
        $this->assertGreaterThan($initial, $tui->fullRedraws());
        $afterShrink = $tui->fullRedraws();

        $component->lines = ['Line 0', 'Line 1', 'Line 2'];
        $tui->requestRender();
        $terminal->waitForRender();

        $this->assertSame($afterShrink, $tui->fullRedraws());
        $this->assertSame(['Line 0', 'Line 1', 'Line 2', '', ''], $terminal->getViewport());
        $tui->stop();
    }

    public function testClearsStaleContentWhenMaxLinesRenderedWasInflatedByATransientComponent(): void
    {
        $terminal = new VirtualTerminal(40, 10);
        $tui = new TuiMainScreen($terminal);
        $chat = self::lines();
        $editor = self::lines();
        $tui->addChild($chat);
        $tui->addChild($editor);
        $editorLines = ['Editor 0', 'Editor 1', 'Editor 2'];

        $chat->lines = self::range(15, 'Chat ');
        $editor->lines = $editorLines;
        $tui->start();
        $terminal->waitForRender();

        $editor->lines = self::range(8, 'Selector ');
        $tui->requestRender();
        $terminal->waitForRender();

        $editor->lines = $editorLines;
        $tui->requestRender();
        $terminal->waitForRender();

        $before = $tui->fullRedraws();
        $chat->lines = self::range(12, 'Chat ');
        $tui->requestRender();
        $terminal->waitForRender();

        $this->assertGreaterThan($before, $tui->fullRedraws());
        $this->assertSame(
            ['Chat 5', 'Chat 6', 'Chat 7', 'Chat 8', 'Chat 9', 'Chat 10', 'Chat 11', 'Editor 0', 'Editor 1', 'Editor 2'],
            $terminal->getViewport(),
        );
        $tui->stop();
    }

    /** Upstream's `tui-shrink.test.ts`. */
    public function testClearsAllRenderedLinesWhenContentShrinksToZero(): void
    {
        $terminal = new VirtualTerminal(40, 10);
        [$tui] = $this->start($terminal, ['first', 'second', 'third']);
        $this->assertSame(['first', 'second', 'third'], array_slice($terminal->getViewport(), 0, 3));

        $tui->clear();
        $tui->requestRender();
        $terminal->waitForRender();

        $screen = implode("\n", $terminal->getViewport());
        $this->assertStringNotContainsString('first', $screen);
        $this->assertStringNotContainsString('second', $screen);
        $this->assertStringNotContainsString('third', $screen);
        $tui->stop();
    }

    public function testTheHardwareCursorGoesWhereTheFocusedComponentPutItsMarker(): void
    {
        $terminal = new VirtualTerminal(40, 10);
        $tui = new TuiMainScreen($terminal);
        $above = self::lines();
        $above->lines = ['above'];
        $editor = new TextComponent("one\nprompt 你好\nbelow", wrap: false);
        $editor->cursor = [1, 11];
        $tui->addChild($above);
        $tui->addChild($editor);
        $tui->setFocus($editor);
        $tui->start();
        $terminal->waitForRender();

        $this->assertTrue($editor->focused);
        $this->assertSame(['row' => 2, 'column' => 11], $terminal->cursor());
        $this->assertStringNotContainsString(TUI::CURSOR_MARKER, $terminal->output());
        $this->assertFalse($terminal->cursorVisible, 'hidden unless showHardwareCursor is on');

        // A spinner tick above leaves the cursor where the marker is.
        $above->lines = ['above *'];
        $tui->requestRender();
        $terminal->waitForRender();
        $this->assertSame(['row' => 2, 'column' => 11], $terminal->cursor());

        $tui->setShowHardwareCursor(true);
        $terminal->waitForRender();
        $this->assertTrue($terminal->cursorVisible);
        $tui->stop();
    }

    public function testMovingFocusTellsBothComponents(): void
    {
        $tui = new TuiMainScreen(new VirtualTerminal());
        $first = new TextComponent('a');
        $second = new TextComponent('b');

        $tui->setFocus($first);
        $tui->setFocus($second);

        $this->assertFalse($first->focused);
        $this->assertTrue($second->focused);
        $this->assertInstanceOf(Focusable::class, $second);
    }
}
