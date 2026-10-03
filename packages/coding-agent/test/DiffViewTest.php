<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;
use Pig\CodingAgent\Interactive\DiffView;
use Pig\CodingAgent\Theme\Palette;
use Pig\CodingAgent\Tools\EditDiff;
use Pig\Tui\Ansi;

/** An edit drawn as a diff, with the words that changed picked out. */
final class DiffViewTest extends TestCase
{
    private Palette $palette;

    #[\Override]
    protected function setUp(): void
    {
        $this->palette = Palette::dark(true);
    }

    /** @return list<string> */
    private function lines(string $old, string $new): array
    {
        [$diff] = EditDiff::render($old, $new);

        return explode("\n", DiffView::render($diff, $this->palette));
    }

    /** The part of a line that is inverted, if any. */
    private function inverted(string $line): string
    {
        return preg_match("/\\e\\[7m(.*?)\\e\\[27m/", $line, $match) === 1 ? $match[1] : '';
    }

    public function testEachKindOfLineGetsItsOwnColour(): void
    {
        $lines = $this->lines("keep\nold\n", "keep\nnew\n");
        $plain = array_map(Ansi::strip(...), $lines);

        $this->assertSame([' 1 keep', '-2 old', '+2 new', ' 3 '], $plain);
        // dark's toolDiffRemoved is red (#ea7f81 = 234, 127, 129), toolDiffAdded green (#68b78d = 104, 183, 141), context muted (#9da5a9 = 157, 165, 169).
        $this->assertStringContainsString("\e[38;2;234;127;129m", $lines[1]);
        $this->assertStringContainsString("\e[38;2;104;183;141m", $lines[2]);
        $this->assertStringContainsString("\e[38;2;157;165;169m", $lines[0]);
    }

    public function testOneLineForOneLineMarksTheWordsThatChanged(): void
    {
        $lines = $this->lines("call(a, b)\n", "call(a, c)\n");

        // Only the part that differs, not the whole line — the point of the mark is to show where
        // to look. These used to read `b)` and `c)`, because the split kept each word with the
        // punctuation and spaces around it; the closing paren did not change and is not marked.
        $this->assertSame('b', $this->inverted($lines[0]));
        $this->assertSame('c', $this->inverted($lines[1]));
    }

    public function testTheSpaceBeforeAChangedWordIsNotPartOfTheChange(): void
    {
        $lines = $this->lines("a b c d e\n", "a b X d e\n");

        // The highlight starts where the change does. Whitespace beginning a changed run moves
        // out of the inverted part wherever that run starts, which used to happen only at the
        // start of the line — so a word swapped in the middle was marked one column early.
        $this->assertSame('c', $this->inverted($lines[0]));
        $this->assertSame('X', $this->inverted($lines[1]));
    }

    public function testWhitespaceBetweenTwoWordsIsNotMarkedEither(): void
    {
        $lines = $this->lines("a  b\n", "a   b\n");

        // The same rule as the indent, one gap along: inverting whitespace draws a block and says
        // nothing about what changed. Both lines still show their own spacing, which is how a
        // reader sees that anything happened at all — `testAChangedIndentIsStillShown` says the
        // same thing for column 1. The strip used to apply only at the start of the line, and this
        // is the case that told the two apart.
        $this->assertSame('', $this->inverted($lines[0]));
        $this->assertSame('', $this->inverted($lines[1]));
        $this->assertNotSame(Ansi::strip($lines[0]), Ansi::strip($lines[1]));
    }

    public function testEachLineIsDrawnWithItsOwnText(): void
    {
        // The invariant a diff has to keep, and the one upstream does not: `diffWords` ignores
        // whitespace, so a whitespace-only change comes back as a single unchanged part holding
        // the *new* string — and upstream appends that to both lines. Measured against the
        // package over 436 edited line pairs: 106 of upstream's removed lines are drawn with text
        // the file does not have, and the worst of them is this one, where the `-` line shows the
        // indentation of the `+` line and nothing is marked at all. Somebody approving an edit
        // from that diff is reading a line that is not in the file.
        $lines = $this->lines("    return 1;\n", "        return 1;\n");

        $this->assertStringStartsWith('-1     return 1;', Ansi::strip($lines[0]));
        $this->assertStringStartsWith('+1         return 1;', Ansi::strip($lines[1]));
    }

    public function testABlockRewriteIsNotMarkedWordByWord(): void
    {
        $lines = $this->lines("one\ntwo\n", "three\nfour\nfive\n");

        // Two lines out, three in: nothing lines up, so word marks would be noise on
        // every row.
        foreach ($lines as $line) {
            $this->assertSame('', $this->inverted($line));
        }
    }

    public function testAnIndentIsNeverMarked(): void
    {
        $lines = $this->lines("    return 1;\n", "    return 2;\n");

        // Inverting an indent paints a solid block down the left and says nothing,
        // because the indentation is not what changed. `2` rather than `2;`, for the reason above.
        $this->assertSame('2', $this->inverted($lines[1]));
        $this->assertStringStartsWith('+1     return ', Ansi::strip($lines[1]));
    }

    public function testAChangedIndentIsStillShown(): void
    {
        $lines = $this->lines("\treturn 1;\n", "        return 1;\n");

        // The line did change, so something has to be visible even though the only
        // difference is leading whitespace.
        $this->assertNotSame(Ansi::strip($lines[0]), Ansi::strip($lines[1]));
    }

    public function testTabsBecomeSpacesSoColumnsLineUp(): void
    {
        $lines = $this->lines("\tif (a) {\n", "\tif (b) {\n");

        foreach ($lines as $line) {
            $this->assertStringNotContainsString("\t", $line);
        }
    }

    public function testALineThatIsNotPartOfADiffIsLeftAsContext(): void
    {
        // EditDiff writes a bare `...` row where it skipped unchanged lines.
        $rendered = DiffView::render(" 1 kept\n ... \n", $this->palette);

        $this->assertStringContainsString('...', Ansi::strip($rendered));
    }

    public function testAnEmptyDiffRendersToNothingVisible(): void
    {
        $this->assertSame('', Ansi::strip(DiffView::render('', $this->palette)));
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function badBytes(): iterable
    {
        yield 'stray continuation byte' => ["\x80"];
        yield 'truncated sequence' => ["\xe4\xbd"];
        yield 'lone 0xff' => ["\xff"];
        yield 'surrogate as utf-8' => ["\xed\xa0\x80"];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badBytes')]
    public function testADiffOfAFileThatIsNotUtf8IsDrawnRatherThanFatal(string $bad): void
    {
        // `edit` is the one tool whose display text is built from the file's own bytes, so
        // it is the one that can hand the renderer something that is not UTF-8 without any
        // tool *output* being involved. Anything downstream of here measures with
        // `Graphemes::split()`, which answers false on malformed UTF-8 and throws.
        [$diff] = EditDiff::render("keep\n{$bad}old\n", "keep\n{$bad}new\n");
        $rendered = DiffView::render($diff, $this->palette);

        $this->assertTrue(mb_check_encoding($rendered, 'UTF-8'));
        $this->assertStringContainsString('keep', Ansi::strip($rendered));
    }

    public function testADiffOfAFileWithAFormFeedInItDoesNotMoveTheCursor(): void
    {
        // A form feed is legal in C and ordinary in Emacs-era source, and it measures as zero
        // columns — so the line passes every width check and the terminal drops a row anyway,
        // putting each later cursor move one row low.
        [$diff] = EditDiff::render("head\n\x0cpage\ntail\n", "head\n\x0cPAGE\x00\ntail\n");
        $rendered = DiffView::render($diff, $this->palette);

        $this->assertStringNotContainsString("\x0c", $rendered);
        $this->assertStringNotContainsString("\x00", $rendered);
        $this->assertStringContainsString('PAGE', Ansi::strip($rendered));
    }
}
