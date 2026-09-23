<?php

declare(strict_types=1);

namespace Pig\Tui\Test;

use PHPUnit\Framework\TestCase;
use Pig\Tui\Ansi;
use Pig\Tui\Components\Input;
use Pig\Tui\Width;

final class InputTest extends TestCase
{
    private Input $input;

    #[\Override]
    protected function setUp(): void
    {
        $this->input = new Input();
    }

    /** @param list<string> $keys */
    private function type(array|string $keys): void
    {
        foreach ((array) $keys as $key) {
            $this->input->handleInput($key);
        }
    }

    /** Set the value and put the cursor at the end, the way typing would leave it. */
    private function given(string $value): void
    {
        $this->input->setValue($value);
        $this->input->handleInput("\x05");
    }

    /** The rendered line with escape codes taken out. */
    private function visible(int $width = 40): string
    {
        return rtrim(Ansi::strip($this->input->render($width)[0]));
    }

    public function testTypingBuildsTheValue(): void
    {
        $this->type(['h', 'i']);

        $this->assertSame('hi', $this->input->value());
        $this->assertSame('> hi', $this->visible());
    }

    public function testControlBytesAreNotInserted(): void
    {
        $this->type(["\x00", "\x1b", "\x7f", 'a']);

        // A stray escape byte in the buffer is text the user can neither see nor delete.
        $this->assertSame('a', $this->input->value());
    }

    public function testEnterSubmitsTheValueAndLeavesIt(): void
    {
        $submitted = null;
        $this->input->setSubmitHandler(static function (string $value) use (&$submitted): void {
            $submitted = $value;
        });

        $this->type(['h', 'i', "\r"]);

        $this->assertSame('hi', $submitted);
        $this->assertSame('hi', $this->input->value());
    }

    public function testBackspaceRemovesAWholeGrapheme(): void
    {
        $this->given("ab👨‍👩‍👧‍👦");
        $this->type("\x7f");

        // One press, one family — not one of its four people and half a joiner.
        $this->assertSame('ab', $this->input->value());
    }

    public function testArrowsStepByGraphemeNotByByte(): void
    {
        $this->given('中文');
        $this->type("\x1b[D");

        $this->assertSame(3, $this->input->cursor());

        $this->type("\x1b[D");
        $this->assertSame(0, $this->input->cursor());

        // Off the left edge is a no-op, not a negative offset.
        $this->type("\x1b[D");
        $this->assertSame(0, $this->input->cursor());
    }

    public function testDeleteRemovesForwards(): void
    {
        $this->given('abc');
        $this->type(["\x1b[D", "\x1b[3~"]);

        $this->assertSame('ab', $this->input->value());
    }

    public function testCtrlAAndCtrlEJumpToTheEnds(): void
    {
        $this->given('hello');
        $this->type("\x01");
        $this->assertSame(0, $this->input->cursor());

        $this->type("\x05");
        $this->assertSame(5, $this->input->cursor());
    }

    public function testCtrlUAndCtrlKCutEitherSideOfTheCursor(): void
    {
        $this->given('hello world');
        $this->type(["\x01", "\x1b[C", "\x1b[C", "\x1b[C", "\x1b[C", "\x1b[C", "\x0b"]);
        $this->assertSame('hello', $this->input->value());

        $this->type("\x15");
        $this->assertSame('', $this->input->value());
    }

    public function testCtrlWStopsWhereTheKindOfCharacterChanges(): void
    {
        $this->given('cd src/pig');
        $this->type("\x17");
        $this->assertSame('cd src/', $this->input->value());

        // The separator is its own run, so a path comes off a piece at a time.
        $this->type("\x17");
        $this->assertSame('cd src', $this->input->value());
    }

    public function testCtrlWSkipsTrailingSpacesFirst(): void
    {
        $this->given('hello world   ');
        $this->type("\x17");

        $this->assertSame('hello ', $this->input->value());
    }

    public function testWordMovementGoesBothWays(): void
    {
        $this->given('one two three');
        $this->type("\x1b[1;5D");
        $this->assertSame(8, $this->input->cursor());

        $this->type("\x1b[1;5D");
        $this->assertSame(4, $this->input->cursor());

        $this->type("\x1b[1;5C");
        $this->assertSame(7, $this->input->cursor());
    }

    public function testAPasteArrivesAsOneInsertion(): void
    {
        $this->type("\x1b[200~pasted text\x1b[201~");

        $this->assertSame('pasted text', $this->input->value());
    }

    public function testAPasteSplitAcrossChunksIsStillOneInsertion(): void
    {
        $this->type(["\x1b[200~one ", 'two ', "three\x1b[201~"]);

        $this->assertSame('one two three', $this->input->value());
    }

    public function testNewlinesInAPasteDoNotSubmit(): void
    {
        $submitted = 0;
        $this->input->setSubmitHandler(static function () use (&$submitted): void {
            $submitted++;
        });

        $this->type("\x1b[200~line one\nline two\x1b[201~");

        $this->assertSame('line oneline two', $this->input->value());
        $this->assertSame(0, $submitted);
    }

    public function testInputAfterAPasteIsStillRead(): void
    {
        $this->type("\x1b[200~pasted\x1b[201~!");

        $this->assertSame('pasted!', $this->input->value());
    }

    public function testTheLineIsAlwaysExactlyTheTerminalWidth(): void
    {
        $this->given(str_repeat('中', 30));

        foreach ([10, 21, 40] as $width) {
            $this->assertSame($width, Width::visible($this->input->render($width)[0]), "at width {$width}");
        }
    }

    public function testLongTextScrollsToKeepTheCursorInView(): void
    {
        $this->given(str_repeat('a', 50) . 'END');

        $line = $this->visible(20);

        // The cursor is at the end, so the end is what has to be on screen.
        $this->assertStringEndsWith('END', $line);
    }

    public function testScrollingBacksUpWhenTheCursorMovesLeft(): void
    {
        $this->input->setValue('START' . str_repeat('a', 50));
        $this->type("\x01");

        $this->assertStringContainsString('START', $this->visible(20));
    }

    public function testTheCursorIsDrawnAsAReversedCell(): void
    {
        $this->input->setValue('ab');
        $this->type("\x01");

        // Reverse video, not a real cursor: the terminal's own cursor is hidden, because
        // one cursor that the renderer controls is easier than two that disagree.
        $this->assertStringContainsString("\x1b[7ma\x1b[27m", $this->input->render(20)[0]);
    }

    public function testSetValueLeavesTheCursorWhereItWas(): void
    {
        $this->input->setValue('hello');

        // Upstream clamps rather than jumping to the end, and so does this.
        $this->assertSame(0, $this->input->cursor());
    }

    public function testACursorPastTheEndStillGetsACell(): void
    {
        $this->given('ab');

        $this->assertStringContainsString("\x1b[7m \x1b[27m", $this->input->render(20)[0]);
    }

    // ---- the way out -------------------------------------------------------------------

    public function testEscapeCancels(): void
    {
        $cancelled = 0;
        $this->input->setCancelHandler(static function () use (&$cancelled): void {
            $cancelled++;
        });

        $this->type("\x1b");

        $this->assertSame(1, $cancelled);
    }

    public function testCtrlCCancelsToo(): void
    {
        $cancelled = 0;
        $this->input->setCancelHandler(static function () use (&$cancelled): void {
            $cancelled++;
        });

        $this->type("\x03");

        $this->assertSame(1, $cancelled);
    }

    /** Cancelling is not submitting: whoever asked gets no value, not an empty one. */
    public function testCancellingDoesNotSubmit(): void
    {
        $submitted = null;
        $this->input->setCancelHandler(static fn () => null);
        $this->input->setSubmitHandler(static function (string $value) use (&$submitted): void {
            $submitted = $value;
        });

        $this->type(['a', "\x1b"]);

        $this->assertNull($submitted);
        $this->assertSame('a', $this->input->value());
    }

    /**
     * With nobody listening, escape is not text.
     *
     * Every input pig opened before this was one somebody had asked for, so there was no
     * handler and no escape key; what there must not be is an escape character inserted
     * into the value.
     */
    public function testWithNoHandlerEscapeIsIgnoredRatherThanTyped(): void
    {
        $this->type(['a', "\x1b", 'b']);

        $this->assertSame('ab', $this->input->value());
    }

    public function testAnArrowKeyStillMovesRatherThanCancelling(): void
    {
        $cancelled = 0;
        $this->input->setCancelHandler(static function () use (&$cancelled): void {
            $cancelled++;
        });

        $this->type(['a', 'b', "\x1b[D"]);

        $this->assertSame(0, $cancelled);
        $this->assertSame(1, $this->input->cursor());
    }

    public function testAWidthWithNoRoomForTextIsJustThePrompt(): void
    {
        $this->given('hello');

        $this->assertSame(['> '], $this->input->render(2));
    }

    // ---- where the terminal's own cursor goes -------------------------------------------

    public function testTheCaretIsAfterThePromptWhenNothingHasBeenTyped(): void
    {
        // Row 0 always: this component is one line. The column is the prompt's width, which
        // is where an input method has to draw for the composing text to appear in the box.
        $this->assertSame([0, 2], $this->input->caret(40));
    }

    public function testTheCaretFollowsTheText(): void
    {
        $this->given('hello');

        $this->assertSame([0, 7], $this->input->caret(40));

        $this->type("\x1b[D");

        $this->assertSame([0, 6], $this->input->caret(40));
    }

    public function testTheCaretIsMeasuredInColumnsNotCharacters(): void
    {
        // Two characters, four columns. Reporting 2 would put the candidate list half way
        // back along what was already typed — which is the whole bug the interface exists
        // for, one component over.
        $this->given('你好');

        $this->assertSame([0, 6], $this->input->caret(40));
    }

    public function testTheCaretStaysInsideAScrolledWindow(): void
    {
        $this->given('0123456789abcdef');

        [$row, $column] = $this->input->caret(10);

        $this->assertSame(0, $row);
        $this->assertLessThan(10, $column);
        $this->assertGreaterThanOrEqual(2, $column);
    }

    public function testThereIsNoCaretWhenThereIsNoRoomForText(): void
    {
        // The same width at which `render()` gives up and draws the prompt alone.
        $this->assertNull($this->input->caret(2));
    }
}
