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

    public function testALineChangingAboveThePromptLeavesThePromptAlone(): void
    {
        // The spinner ticks about twenty times a second and sits above the editor, so
        // rewriting from it *down* erased and rewrote the line somebody is composing into
        // at that rate. Measured on a real frame: one line changed, eight rewritten.
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
        // The change is *beyond* the new frame's end — a loader disappearing from the bottom,
        // which is every turn — so rewriting from it down wrote nothing and left the cursor a
        // row below the frame. The sweep then counted from there: the first dead line survived
        // and the last one ran off the end of the frame, scrolling the screen to erase a row
        // that was never ours.
        $top = new TextComponent("one\ntwo");
        $loader = new TextComponent("working\nstill working");
        $this->tui->addChild($top);
        $this->tui->addChild($loader);
        $this->tui->start();
        $this->frame();

        $loader->text = '';
        $this->tui->requestRender();
        $output = $this->frame();

        // Cursor on row 3, frame now ends at row 1: up two, then erase rows 2 and 3.
        // What it used to write was a single \e[1A before the same sweep.
        $this->assertStringContainsString("\x1b[2A\r\r\n\x1b[2K\r\n\x1b[2K\x1b[2A", $output);
    }

    public function testLinesTheFrameHasGrownByAreWrittenRatherThanAddressed(): void
    {
        // A row that does not exist yet cannot be reached with a cursor-down: at the bottom of
        // the screen that stays where it is, so the new line lands on top of the last one. A
        // \r\n is what makes the terminal scroll to make room.
        $top = new TextComponent("a\nb");
        $growing = new TextComponent('c');
        $this->tui->addChild($top);
        $this->tui->addChild($growing);
        $this->tui->start();
        $this->frame();

        $growing->text = "c\nd\ne";
        $this->tui->requestRender();
        $output = $this->frame();

        $this->assertStringContainsString("\r\n\x1b[2Kd\r\n\x1b[2Ke", $output);
        $this->assertStringNotContainsString("\x1b[1B", $output);
        $this->assertStringNotContainsString('c', $output, 'the line that did not change was rewritten');
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

    public function testWhereTheWindowStartsIsAFactAboutTheFrameAndNotAboutTheCursor(): void
    {
        // It used to be read off cursorRow, which was the frame's last line because that is
        // where writing one left the cursor. With only the changed lines rewritten the cursor
        // is wherever the last change was, which can be anywhere — and a window computed from
        // a cursor high in the frame is a window nearly the whole frame fits inside, so a
        // change that has scrolled into the scrollback is answered by moving the cursor to a
        // row the terminal no longer has.
        $terminal = new FakeTerminal(columns: 20, rows: 8);
        $tui = new Tui($terminal);
        $component = new TextComponent(implode("\n", array_map(static fn (int $n): string => "line {$n}", range(0, 9))));
        $tui->addChild($component);
        $tui->start();
        Loop::get()->tick();

        // Ten lines in an eight-row window, so rows 2 to 9 are on screen. Changing row 2 is
        // a differential draw, and it leaves the cursor up there.
        $component->text = str_replace('line 2', 'line two', $component->text);
        $tui->requestRender();
        Loop::get()->tick();

        $terminal->clearWrites();
        $component->text = str_replace('line 1', 'line one', $component->text);
        $tui->requestRender();
        Loop::get()->tick();

        $this->assertStringContainsString("\x1b[3J\x1b[2J\x1b[H", $terminal->output());
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

    // ---- where the terminal's cursor is left ----------------------------------------

    public function testTheCursorEndsUpAtTheFocusedComponentsCaret(): void
    {
        // An input method draws what is being composed, and its candidate list, wherever
        // the terminal's cursor is. Left at the bottom of the frame — where writing one
        // leaves it — typing Chinese puts the pinyin over the footer.
        $above = new TextComponent("a\nb");
        $editor = new TextComponent("cursor here\nsecond");
        $editor->caret = [1, 4];

        $this->tui->addChild($above);
        $this->tui->addChild($editor);
        $this->tui->addChild(new TextComponent('footer'));
        $this->tui->setFocus($editor);
        $this->tui->start();

        $output = $this->frame();

        // Five lines drawn, cursor at the last; the caret is on row 3, four columns in —
        // and the move is the last thing *inside* the synchronized-output wrapper, not the
        // first thing after it. Outside it the terminal paints the frame with the cursor at
        // the bottom and then moves it, and the candidate list goes to the bottom and back
        // on every frame, which is half of what this whole mechanism is for.
        $this->assertStringEndsWith("\x1b[1A\r\x1b[4C\x1b[?2026l", $output);
    }

    public function testAFrameThatLeavesTheCursorWhereItAlreadyIsMovesItNoFurther(): void
    {
        // A move away and back is still a move, and an input method follows it — so the
        // column is tracked as well as the row, and a frame that already ended where the
        // caret is addresses the cursor not at all.
        $editor = new TextComponent('a');
        $editor->caret = [0, 0];

        $this->tui->addChild($editor);
        $this->tui->setFocus($editor);
        $this->tui->start();

        $this->assertStringEndsWith("\x1b[?2026l", $this->frame(), 'the first frame moved it anyway');

        $editor->text = 'b';
        $this->tui->requestRender();
        $output = $this->frame();

        // One line, rewritten, and the \r that ends every frame leaves the cursor at the
        // column the caret is in — so there is nothing left to say.
        $this->assertStringEndsWith("\x1b[2Kb\r\x1b[?2026l", $output);
    }

    public function testAComponentWithNoCaretLeavesTheCursorWhereItWas(): void
    {
        $this->tui->addChild(new TextComponent("one\ntwo"));
        $this->tui->start();

        $this->assertStringEndsWith("\x1b[?2026l", $this->frame());
    }

    public function testTheNextFrameStillCountsRowsFromWhereTheCursorActuallyIs(): void
    {
        // placeCaret moves the cursor off the bottom line, so the differential draw that
        // follows has to count from the caret and not from the frame's last row.
        $editor = new TextComponent("one\ntwo");
        $editor->caret = [0, 0];

        $this->tui->addChild($editor);
        $this->tui->addChild(new TextComponent('tail'));
        $this->tui->setFocus($editor);
        $this->tui->start();
        $this->frame();

        $editor->text = "one\nchanged";
        $this->tui->requestRender();
        $output = $this->frame();

        // The caret left the cursor on row 0; the changed line is row 1, so down one.
        $this->assertStringContainsString("\x1b[1B\r", $output);
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

        $this->assertStringContainsString("\x1b[3J\x1b[2J\x1b[H", $this->terminal->output());
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
        $tui = new Tui($terminal);
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
