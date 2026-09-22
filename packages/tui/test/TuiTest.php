<?php

declare(strict_types=1);

namespace Pig\Tui\Test;

use PHPUnit\Framework\TestCase;
use Pig\Async\Loop;
use Pig\Test\AssertsThrows;
use Pig\Tui\Keys;
use Pig\Tui\Tui;
use Pig\Tui\TuiError;

final class TuiTest extends TestCase
{
    use AssertsThrows;

    private FakeTerminal $terminal;

    private Tui $tui;

    #[\Override]
    protected function setUp(): void
    {
        Loop::reset();
        $this->terminal = new FakeTerminal(columns: 20, rows: 5);
        $this->tui = new Tui($this->terminal);
    }

    /** Rendering is deferred, so a test has to let the loop run before looking. */
    private function frame(): string
    {
        $this->terminal->clearWrites();
        Loop::get()->tick();

        return $this->terminal->output();
    }

    public function testStartingDrawsEverythingWithoutClearingTheScreen(): void
    {
        $this->tui->addChild(new TextComponent("one\ntwo"));
        $this->tui->start();

        $output = $this->frame();

        $this->assertStringContainsString('one', $output);
        $this->assertStringContainsString('two', $output);
        // Nothing above belongs to us on the first frame, so nothing above is cleared.
        $this->assertStringNotContainsString("\x1b[2J", $output);
        $this->assertFalse($this->terminal->cursorVisible);
    }

    public function testEveryFrameIsWrappedInSynchronizedOutput(): void
    {
        $this->tui->addChild(new TextComponent('hello'));
        $this->tui->start();

        $output = $this->frame();

        // Without this the terminal can paint a half-written frame.
        $this->assertStringStartsWith("\x1b[?2026h", $output);
        $this->assertStringEndsWith("\x1b[?2026l", $output);
    }

    public function testAnUnchangedFrameWritesNothingAtAll(): void
    {
        $this->tui->addChild(new TextComponent('steady'));
        $this->tui->start();
        $this->frame();

        $this->tui->requestRender();

        $this->assertSame('', $this->frame());
    }

    public function testOnlyTheLinesThatChangedAreRewritten(): void
    {
        $top = new TextComponent('unchanged');
        $bottom = new TextComponent('before');
        $this->tui->addChild($top);
        $this->tui->addChild($bottom);
        $this->tui->start();
        $this->frame();

        $bottom->text = 'after';
        $this->tui->requestRender();
        $output = $this->frame();

        $this->assertStringContainsString('after', $output);
        $this->assertStringNotContainsString('unchanged', $output);
        // Cursor was on line 1 and the change is on line 1: no vertical move needed.
        $this->assertStringNotContainsString("\x1b[1A", $output);
    }

    public function testTheCursorMovesUpToReachAnEarlierChangedLine(): void
    {
        $top = new TextComponent('before');
        $this->tui->addChild($top);
        $this->tui->addChild(new TextComponent("filler\nfiller"));
        $this->tui->start();
        $this->frame();

        $top->text = 'after';
        $this->tui->requestRender();
        $output = $this->frame();

        // Three lines drawn, cursor left on line 2, change on line 0.
        $this->assertStringContainsString("\x1b[2A", $output);
        $this->assertStringContainsString('after', $output);
    }

    public function testLinesThatVanishedAreErased(): void
    {
        $component = new TextComponent("one\ntwo\nthree");
        $this->tui->addChild($component);
        $this->tui->start();
        $this->frame();

        $component->text = 'one';
        $this->tui->requestRender();
        $output = $this->frame();

        // Two lines dropped: each is cleared, then the cursor comes back up over them.
        $this->assertStringContainsString("\r\n\x1b[2K\r\n\x1b[2K", $output);
        $this->assertStringContainsString("\x1b[2A", $output);
    }

    public function testAResizeRedrawsEverythingAndClearsTheScrollback(): void
    {
        $this->tui->addChild(new TextComponent('hello there'));
        $this->tui->start();
        $this->frame();

        $this->terminal->resize(10, 5);
        $output = $this->frame();

        // The old frame was laid out for a different width; leaving it in the scrollback
        // would leave the user scrolling back into a broken copy of what they just read.
        $this->assertStringContainsString("\x1b[3J\x1b[2J\x1b[H", $output);
    }

    public function testAChangeAboveTheWindowForcesAFullRedraw(): void
    {
        // Ten lines in a five-row window: line 0 has scrolled out of reach.
        $component = new TextComponent(implode("\n", array_map(static fn (int $n): string => "line {$n}", range(0, 9))));
        $this->tui->addChild($component);
        $this->tui->start();
        $this->frame();

        $component->text = str_replace('line 0', 'line X', $component->text);
        $this->tui->requestRender();
        $output = $this->frame();

        $this->assertStringContainsString("\x1b[3J\x1b[2J\x1b[H", $output);
        $this->assertStringContainsString('line X', $output);
    }

    public function testSeveralRequestsInOneTurnCostOneFrame(): void
    {
        $component = new TextComponent('a');
        $this->tui->addChild($component);
        $this->tui->start();
        $this->frame();

        $component->text = 'b';
        $this->tui->requestRender();
        $this->tui->requestRender();
        $this->tui->requestRender();

        $output = $this->frame();

        $this->assertSame(1, substr_count($output, "\x1b[?2026h"));
    }

    public function testInputGoesToTheFocusedComponent(): void
    {
        $focused = new TextComponent('focused');
        $other = new TextComponent('other');
        $this->tui->addChild($focused);
        $this->tui->addChild($other);
        $this->tui->start();
        $this->tui->setFocus($focused);

        $this->terminal->type('x');

        $this->assertSame(['x'], $focused->typed);
        $this->assertSame([], $other->typed);
    }

    public function testCtrlCReachesTheComponentRatherThanBeingSwallowed(): void
    {
        $focused = new TextComponent('focused');
        $this->tui->addChild($focused);
        $this->tui->start();
        $this->tui->setFocus($focused);

        $this->terminal->type("\x03");

        // What Ctrl+C means depends on what has focus, so the TUI does not decide.
        $this->assertSame(["\x03"], $focused->typed);
    }

    public function testTheDebugKeyIsTakenBeforeTheComponentSeesIt(): void
    {
        $focused = new TextComponent('focused');
        $seen = 0;
        $this->tui->addChild($focused);
        $this->tui->setDebugHandler(static function () use (&$seen): void {
            $seen++;
        });
        $this->tui->start();
        $this->tui->setFocus($focused);

        $this->terminal->type(Keys::kitty(ord('d'), 1 + 4));

        $this->assertSame(1, $seen);
        $this->assertSame([], $focused->typed);
    }

    public function testStoppingGivesTheCursorBack(): void
    {
        $this->tui->start();
        $this->tui->stop();

        $this->assertTrue($this->terminal->cursorVisible);
        $this->assertFalse($this->terminal->started);
    }

    public function testInvalidateReachesEveryChild(): void
    {
        $child = new TextComponent('x');
        $this->tui->addChild($child);

        $this->tui->invalidate();

        $this->assertSame(1, $child->invalidated);
    }

    public function testALineWiderThanTheTerminalIsRefused(): void
    {
        $component = new TextComponent('short', wrap: false);
        $this->tui->addChild($component);
        $this->tui->start();
        $this->frame();

        // A component that ignores the width it was given corrupts every cursor move
        // after it, so the renderer stops rather than drawing it.
        $component->text = str_repeat('x', 50);
        $this->tui->requestRender();

        $error = $this->assertThrows(
            TuiError::class,
            fn () => Loop::get()->tick(),
            'columns wide',
        );

        $this->assertStringContainsString('terminal is 20', $error->getMessage());
    }

    public function testAFirstFrameThatIsTooWideIsRefusedToo(): void
    {
        // The first frame goes down a different path, and it is the worse one to miss:
        // a differential redraw never revisits the top of the screen, so a wrapped line
        // up there pushes every later cursor move a row low with nothing to point at.
        $this->tui->addChild(new TextComponent(str_repeat('x', 50), wrap: false));
        $this->tui->start();

        $this->assertThrows(
            TuiError::class,
            fn () => Loop::get()->tick(),
            'columns wide',
        );
    }
}
