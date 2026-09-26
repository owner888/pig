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
        // dark's toolDiffRemoved is red, toolDiffAdded green, context grey.
        $this->assertStringContainsString("\e[38;2;204;102;102m", $lines[1]);
        $this->assertStringContainsString("\e[38;2;181;189;104m", $lines[2]);
        $this->assertStringContainsString("\e[38;2;128;128;128m", $lines[0]);
    }

    public function testOneLineForOneLineMarksTheWordsThatChanged(): void
    {
        $lines = $this->lines("call(a, b)\n", "call(a, c)\n");

        // Only the part that differs, not the whole line — the point of the mark is to
        // show where to look.
        $this->assertSame('b)', $this->inverted($lines[0]));
        $this->assertSame('c)', $this->inverted($lines[1]));
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
        // because the indentation is not what changed.
        $this->assertSame('2;', $this->inverted($lines[1]));
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
