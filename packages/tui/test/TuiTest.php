<?php

declare(strict_types=1);

namespace Pig\Tui\Test;

use PHPUnit\Framework\TestCase;
use Pig\Async\Loop;
use Pig\Test\AssertsThrows;
use Pig\Tui\Components\Image;
use Pig\Tui\Images\Capabilities;
use Pig\Tui\Images\ImageProtocol;
use Pig\Tui\Images\TerminalImage;
use Pig\Tui\Keys;
use Pig\Tui\TuiBase;
use Pig\Tui\TuiMainScreen;
use Pig\Tui\TuiError;

final class TuiTest extends TestCase
{
    use AssertsThrows;

    private FakeTerminal $terminal;

    private TuiMainScreen $tui;

    #[\Override]
    protected function setUp(): void
    {
        Loop::reset();
        $this->terminal = new FakeTerminal(columns: 20, rows: 5);
        $this->tui = new TuiMainScreen($this->terminal);
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

    public function testALineChangingAboveThePromptLeavesThePromptAlone(): void
    {
        // Only the rows from the first change to the last are rewritten: a spinner tick above
        // the editor is one line, and the line somebody is composing into is left alone.
        $spinner = new TextComponent('-');
        $prompt = new TextComponent("╭────╮\n│ ni hao\n╰────╯");
        $footer = new TextComponent('sonnet');

        $this->tui->addChild($spinner);
        $this->tui->addChild($prompt);
        $this->tui->addChild($footer);
        $this->tui->start();
        $this->frame();

        $spinner->text = '\\';
        $this->tui->requestRender();
        $output = $this->frame();

        $this->assertStringContainsString('\\', $output);
        $this->assertStringNotContainsString('ni hao', $output);
        $this->assertStringNotContainsString('╭', $output);
        $this->assertStringNotContainsString('sonnet', $output);
        $this->assertSame(1, substr_count($output, "\x1b[2K"), 'more than the one line was erased');
    }

    public function testTheFirstOfTheVanishedLinesIsTheOneErasedFirst(): void
    {
        // Every change is beyond the new frame's end — a loader disappearing from the bottom,
        // which is every turn. The cursor goes to the new last row, then down and erases each
        // vanished row, then comes back.
        $top = new TextComponent("one\ntwo");
        $loader = new TextComponent("working\nstill working");
        $this->tui->addChild($top);
        $this->tui->addChild($loader);
        $this->tui->start();
        $this->frame();

        $loader->text = '';
        $this->tui->requestRender();
        $output = $this->frame();

        // Cursor on row 3, frame now ends at row 1: up two, then down a row and erase, twice,
        // then back up.
        $this->assertStringContainsString("\x1b[2A\r\x1b[1B\r\x1b[2K\x1b[1B\r\x1b[2K\x1b[2A", $output);
    }

    public function testErasingVanishedLinesMovesDownRatherThanScrolling(): void
    {
        // `\r\n` on the terminal's last row scrolls; `\e[B` does not. The vanished rows are
        // still on the screen, so the cursor is *moved* to them.
        $component = new TextComponent("one\ntwo\nthree");
        $this->tui->addChild($component);
        $this->tui->start();
        $this->frame();

        $component->text = 'one';
        $this->tui->requestRender();
        $output = $this->frame();

        $this->assertStringNotContainsString("\n", $output, 'nothing in a shrink may scroll');
        $this->assertSame(2, substr_count($output, "\x1b[1B\r\x1b[2K"));
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
        $this->assertStringContainsString("\x1b[1B\r\x1b[2K\x1b[1B\r\x1b[2K", $output);
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
        $this->assertStringContainsString("\x1b[2J\x1b[H\x1b[3J", $output, "screen, home, scrollback — upstream's order");
    }

    public function testAResizeToTheSameSizeDrawsNothing(): void
    {
        // A drag delivers a SIGWINCH per pixel, and most of them land on the same cell size: a
        // frame for each is a whole-screen redraw with the scrollback cleared, for nothing.
        $this->tui->addChild(new TextComponent('hello there'));
        $this->tui->start();
        $this->frame();

        $this->terminal->resize(20, 5);
        $output = $this->frame();

        $this->assertSame('', $output, 'same size, no frame');
    }

    public function testABurstOfResizesWithinTheIntervalIsOneFrame(): void
    {
        $this->tui->addChild(new TextComponent('hello there'));
        $this->tui->start();
        $this->frame();

        // Three sizes inside one interval: the first is deferred to the next turn as always; the
        // rest arrive while a render is already requested and join it.
        $this->terminal->resize(18, 5);
        $this->terminal->resize(16, 5);
        $this->terminal->resize(14, 5);
        $output = $this->frame();

        $this->assertSame(1, substr_count($output, "\x1b[3J"), 'one clear for three sizes');
        $this->assertStringContainsString('hello there', $output);
        $this->assertSame(14, $this->tui->renderedWidth(), 'and it is the latest size that was drawn');

        // Inside the interval, a fourth waits for the rest of it rather than drawing at once: the
        // tick that draws it blocks in `select()` until the timer, so the wait is visible as time.
        $start = microtime(true);
        $this->terminal->resize(12, 5);
        $output = $this->frame();

        $this->assertStringContainsString("\x1b[3J", $output, 'drawn once the interval is over');
        $this->assertGreaterThan(TuiBase::MIN_RENDER_INTERVAL * 0.5, microtime(true) - $start, 'and not before');
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
        $this->tui->onDebug = static function () use (&$seen): void {
            $seen++;
        };
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

    // ---- where the terminal's cursor is left ----------------------------------------

    public function testAForcedRenderClearsTheScreenItNoLongerOwns(): void
    {
        // `force` has one meaning left and it is not the resize the docblock used to claim: the
        // resize handler asks for a plain render and the width-changed path clears by itself. What
        // is left is coming back from something that owned the screen — `$VISUAL`, a suspend — and
        // there the old frame is still up there, because a full-screen editor restores what it
        // found on the way out. Emptying `previousLines` alone writes the new frame *under* it,
        // which is the first-frame path doing exactly what it is for and exactly the wrong thing.
        $this->tui->addChild(new TextComponent("one\ntwo"));
        $this->tui->start();
        $this->frame();

        $this->terminal->clearWrites();
        $this->tui->requestRender(true);
        $this->frame();

        $this->assertStringContainsString("\x1b[2J\x1b[H\x1b[3J", $this->terminal->output());
    }

    public function testTheVeryFirstFrameDoesNotClearWhatTheShellPrinted(): void
    {
        // The other half, and the reason the two facts cannot be one: an empty `previousLines` is
        // also true before anything has been drawn, and clearing there would wipe whatever was in
        // the terminal before pig started.
        $this->tui->addChild(new TextComponent("one\ntwo"));
        $this->tui->start();
        $this->frame();

        $this->assertStringNotContainsString("\x1b[2J", $this->terminal->output());
    }

    public function testTheCellSizeReplyDoesNotRedrawTheWholeFrameOverItself(): void
    {
        // The reply arrives as input on an image-capable terminal, and it used to force the
        // next render — which empties previousLines, which the renderer reads as "first frame
        // ever" and writes with no clear, starting wherever the cursor already was. Nothing
        // here holds a picture, so the answer changes nothing and nothing should be drawn.
        TerminalImage::reset(new Capabilities(ImageProtocol::Kitty, true, true));

        $this->tui->addChild(new TextComponent("one\ntwo\nthree"));
        $this->tui->start();
        $this->frame();

        $this->terminal->clearWrites();
        $this->terminal->type("\x1b[6;18;9t");
        Loop::get()->tick();

        $this->assertSame(9, TerminalImage::cellSize()->widthPx, 'the reply was still read');
        $this->assertSame('', $this->terminal->output(), 'the frame was drawn a second time');

        TerminalImage::reset();
    }

    public function testThePictureIsRedrawnWhenTheCellSizeArrives(): void
    {
        // The other half: a plain render still has to reach the images, or asking the
        // question was pointless. A tall picture at a different cell size is a different
        // number of rows, so the lines holding it change and only those are rewritten.
        TerminalImage::reset(new Capabilities(ImageProtocol::Kitty, true, true));

        // A 20×400 PNG: tall enough that the cell height decides how many rows it takes.
        // The terminal is tall enough to hold it, or the change would sit above the window
        // and a full clearing redraw would be the right answer rather than a differential one.
        $png = base64_encode("\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . pack('NN', 20, 400) . "\x08\x06\x00\x00\x00");
        $terminal = new FakeTerminal(columns: 20, rows: 200);
        $tui = new TuiMainScreen($terminal);
        $tui->addChild(new TextComponent('header'));
        $tui->addChild(new Image($png, 'image/png'));
        $tui->start();
        Loop::get()->tick();

        $this->assertStringContainsString('header', $terminal->output(), 'the first frame');

        $terminal->clearWrites();
        $terminal->type("\x1b[6;36;9t");
        Loop::get()->tick();

        $output = $terminal->output();

        $this->assertNotSame('', $output, 'the picture was never remeasured');
        $this->assertStringNotContainsString('header', $output, 'the unchanged line above was rewritten too');

        TerminalImage::reset();
    }
}
