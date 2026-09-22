<?php

declare(strict_types=1);

namespace Pig\Tui\Test;

use PHPUnit\Framework\TestCase;
use Pig\Tui\Ansi;
use Pig\Tui\Components\DefaultTextStyle;
use Pig\Tui\Components\Markdown;
use Pig\Tui\Markdown\Blockquote;
use Pig\Tui\Markdown\CodeBlock;
use Pig\Tui\Markdown\CodeSpan;
use Pig\Tui\Markdown\Emphasis;
use Pig\Tui\Markdown\Heading;
use Pig\Tui\Markdown\Inline;
use Pig\Tui\Markdown\Lexer;
use Pig\Tui\Markdown\LineBreak;
use Pig\Tui\Markdown\Link;
use Pig\Tui\Markdown\ListBlock;
use Pig\Tui\Markdown\Paragraph;
use Pig\Tui\Markdown\Rule;
use Pig\Tui\Markdown\Strikethrough;
use Pig\Tui\Markdown\Strong;
use Pig\Tui\Markdown\Table;
use Pig\Tui\Markdown\Text;
use Pig\Tui\Style;
use Pig\Tui\Width;

final class MarkdownTest extends TestCase
{
    /** The rendered lines with escape codes taken out and trailing padding trimmed. */
    private function rows(string $markdown, int $width = 40): array
    {
        $lines = (new Markdown($markdown, paddingX: 0))->render($width);

        return array_map(static fn (string $line): string => rtrim(Ansi::strip($line)), $lines);
    }

    /** @return list<class-string> */
    private function types(string $markdown): array
    {
        return array_map(static fn (object $token): string => $token::class, Lexer::lex($markdown));
    }

    // ---- the block lexer -----------------------------------------------------------

    public function testHeadingsCarryTheirLevel(): void
    {
        $tokens = Lexer::lex("# One\n## Two\n###### Six");

        $this->assertSame([1, 2, 6], array_map(static fn (Heading $h): int => $h->level, $tokens));
    }

    public function testAHashWithNoSpaceIsNotAHeading(): void
    {
        // `#1` is an issue number, not a heading.
        $this->assertSame([Paragraph::class], $this->types('#1 is broken'));
    }

    public function testAFenceKeepsItsContentLiteral(): void
    {
        $tokens = Lexer::lex("```php\n# not a heading\n**not bold**\n```");

        $this->assertInstanceOf(CodeBlock::class, $tokens[0]);
        $this->assertSame('php', $tokens[0]->language);
        $this->assertSame("# not a heading\n**not bold**", $tokens[0]->code);
    }

    public function testAnUnclosedFenceRunsToTheEnd(): void
    {
        // A model that stopped mid-block still wrote code.
        $tokens = Lexer::lex("```\nstart\nmore");

        $this->assertInstanceOf(CodeBlock::class, $tokens[0]);
        $this->assertSame("start\nmore", $tokens[0]->code);
    }

    public function testRulesAndTheirNearMisses(): void
    {
        $this->assertSame([Rule::class], $this->types('---'));
        $this->assertSame([Rule::class], $this->types('* * *'));
        $this->assertSame([ListBlock::class], $this->types('- item'));
    }

    public function testListsNestByIndentation(): void
    {
        $tokens = Lexer::lex("- one\n- two\n  - nested\n- three");

        $this->assertInstanceOf(ListBlock::class, $tokens[0]);
        $this->assertCount(3, $tokens[0]->items);
        $this->assertInstanceOf(ListBlock::class, $tokens[0]->items[1]->children[1]);
    }

    public function testOrderedAndUnorderedListsAreToldApart(): void
    {
        $this->assertTrue(Lexer::lex('1. one')[0]->ordered);
        $this->assertFalse(Lexer::lex('- one')[0]->ordered);
    }

    public function testAQuoteHoldsWholeBlocks(): void
    {
        $tokens = Lexer::lex("> # Heading\n> and prose");

        $this->assertInstanceOf(Blockquote::class, $tokens[0]);
        $this->assertInstanceOf(Heading::class, $tokens[0]->children[0]);
    }

    public function testATableNeedsItsRowOfDashes(): void
    {
        $this->assertSame([Table::class], $this->types("| a | b |\n|---|---|\n| 1 | 2 |"));
        // Pipes alone are prose about pipes.
        $this->assertSame([Paragraph::class], $this->types('a | b'));
    }

    public function testAShortTableRowIsPaddedToTheHeader(): void
    {
        $tokens = Lexer::lex("| a | b | c |\n|---|---|---|\n| 1 |");

        $this->assertCount(3, $tokens[0]->rows[0]);
    }

    public function testAHeadingInterruptsAParagraph(): void
    {
        $this->assertSame([Paragraph::class, Heading::class], $this->types("prose\n# heading"));
    }

    // ---- the inline lexer ----------------------------------------------------------

    public function testEmphasisAndItsSpellings(): void
    {
        $this->assertInstanceOf(Strong::class, Inline::tokenize('**bold**')[0]);
        $this->assertInstanceOf(Emphasis::class, Inline::tokenize('*italic*')[0]);
        $this->assertInstanceOf(Strikethrough::class, Inline::tokenize('~~gone~~')[0]);
        $this->assertInstanceOf(CodeSpan::class, Inline::tokenize('`code`')[0]);
    }

    public function testNestedEmphasis(): void
    {
        $tokens = Inline::tokenize('***both***');

        $this->assertInstanceOf(Strong::class, $tokens[0]);
        $this->assertInstanceOf(Emphasis::class, $tokens[0]->children[0]);
    }

    public function testAnUnderscoreInsideAWordIsNotEmphasis(): void
    {
        // Models write snake_case_names constantly.
        $this->assertSame('some_long_name', Inline::plain('some_long_name'));
        $this->assertInstanceOf(Text::class, Inline::tokenize('some_long_name')[0]);
    }

    public function testALoneAsteriskIsJustAnAsterisk(): void
    {
        $this->assertSame('2 * 3 = 6', Inline::plain('2 * 3 = 6'));
    }

    public function testACodeSpanIsLiteral(): void
    {
        $tokens = Inline::tokenize('`**not bold**`');

        $this->assertInstanceOf(CodeSpan::class, $tokens[0]);
        $this->assertSame('**not bold**', $tokens[0]->code);
    }

    public function testBackslashEscapesAMarker(): void
    {
        $this->assertSame('*not emphasis*', Inline::plain('\\*not emphasis\\*'));
    }

    public function testLinks(): void
    {
        $tokens = Inline::tokenize('see [the docs](https://example.com) now');

        $this->assertInstanceOf(Link::class, $tokens[1]);
        $this->assertSame('https://example.com', $tokens[1]->href);
        $this->assertSame('the docs', $tokens[1]->label);
    }

    public function testABareUrlBecomesALink(): void
    {
        $tokens = Inline::tokenize('go to https://example.com/a?b=1 please');

        $this->assertInstanceOf(Link::class, $tokens[1]);
        $this->assertSame('https://example.com/a?b=1', $tokens[1]->href);
    }

    public function testTrailingPunctuationIsNotPartOfABareUrl(): void
    {
        $tokens = Inline::tokenize('see https://example.com.');

        $this->assertSame('https://example.com', $tokens[1]->href);
    }

    public function testAnAngleBracketedUrlBecomesALink(): void
    {
        $this->assertInstanceOf(Link::class, Inline::tokenize('<https://example.com>')[0]);
    }

    public function testTwoTrailingSpacesAreAHardBreak(): void
    {
        $tokens = Inline::tokenize("one  \ntwo");

        $this->assertInstanceOf(LineBreak::class, $tokens[1]);
        $this->assertSame('one', $tokens[0]->text);
    }

    public function testASoftNewlineBecomesASpace(): void
    {
        // The renderer does its own wrapping, so the author's line break is not kept.
        $this->assertSame('one two', Inline::plain("one\ntwo"));
    }

    // ---- rendering -----------------------------------------------------------------

    public function testEmptyTextDrawsNothing(): void
    {
        $this->assertSame([], (new Markdown(''))->render(40));
        $this->assertSame([], (new Markdown("  \n "))->render(40));
    }

    public function testAHeadingAboveThreeKeepsItsHashes(): void
    {
        $this->assertSame('### Deep', $this->rows('### Deep')[0]);
        // One and two are styled instead, because a title does not need its markup shown.
        $this->assertSame('Title', $this->rows('# Title')[0]);
    }

    public function testACodeBlockKeepsItsFenceAndIndentsItsBody(): void
    {
        $rows = $this->rows("```php\n\$x = 1;\n```");

        $this->assertSame(['```php', '  $x = 1;', '```', ''], $rows);
    }

    public function testAListIsDrawnWithBulletsAndNesting(): void
    {
        $rows = $this->rows("- one\n- two\n  - nested");

        $this->assertSame(['- one', '- two', '  - nested'], $rows);
    }

    public function testAnOrderedListIsNumbered(): void
    {
        $this->assertSame(['1. one', '2. two'], $this->rows("1. one\n2. two"));
    }

    public function testAQuoteGetsARuleDownItsLeft(): void
    {
        $rows = $this->rows('> quoted');

        $this->assertSame('│ quoted', $rows[0]);
    }

    public function testEveryLineOfAWrappedQuoteKeepsItsBorder(): void
    {
        $rows = $this->rows('> ' . str_repeat('quoted words ', 6), 30);

        // The border is a per-line prefix, so it has to go on after the wrap: added
        // before, only the first row would have it and the rest would hang in the margin.
        $this->assertGreaterThan(1, count($rows));

        foreach ($rows as $row) {
            if ($row !== '') {
                $this->assertStringStartsWith('│ ', $row);
            }
        }
    }

    public function testATableIsDrawnWithBorders(): void
    {
        $rows = $this->rows("| a | b |\n|---|---|\n| 1 | 2 |", 20);

        $this->assertSame('┌───┬───┐', $rows[0]);
        $this->assertSame('│ a │ b │', $rows[1]);
        $this->assertSame('├───┼───┤', $rows[2]);
        $this->assertSame('│ 1 │ 2 │', $rows[3]);
        $this->assertSame('└───┴───┘', $rows[4]);
    }

    public function testATableTooNarrowToDrawFallsBackToItsSource(): void
    {
        $markdown = "| aaa | bbb | ccc | ddd |\n|---|---|---|---|\n| 1 | 2 | 3 | 4 |";
        $rows = $this->rows($markdown, 8);

        // Borders wider than the content would be worse than the markdown itself.
        $this->assertStringContainsString('aaa', implode("\n", $rows));
        $this->assertStringNotContainsString('┌', implode("\n", $rows));
    }

    public function testATableShrinksItsColumnsToFit(): void
    {
        $markdown = "| a very long heading | b |\n|---|---|\n| some long content | 2 |";

        foreach ((new Markdown($markdown, paddingX: 0))->render(30) as $line) {
            $this->assertLessThanOrEqual(30, Width::visible($line));
        }
    }

    public function testEveryLineIsPaddedToTheFullWidth(): void
    {
        $markdown = "# Title\n\nSome **bold** prose with `code`.\n\n- a list\n\n> a quote";

        foreach ((new Markdown($markdown))->render(30) as $line) {
            $this->assertSame(30, Width::visible($line));
        }
    }

    public function testLongProseIsWrappedWithItsStylingIntact(): void
    {
        // Trimmed: a space before the closing `**` makes it not a closer, in CommonMark
        // and here, and the whole thing would come out as one long italic run instead.
        $markdown = '**' . trim(str_repeat('bold ', 10)) . '**';
        $lines = (new Markdown($markdown, paddingX: 0))->render(20);

        // Three rows of text, then the blank line a paragraph leaves after itself.
        $this->assertCount(4, $lines);
        // The wrapper reopens the style it broke across.
        $this->assertStringContainsString("\x1b[1m", $lines[1]);
    }

    public function testALinkShowsItsUrlUnlessTheTextAlreadyIsTheUrl(): void
    {
        $this->assertStringContainsString('(https://example.com)', $this->rows('[docs](https://example.com)')[0]);
        $this->assertSame(1, substr_count($this->rows('<https://example.com>')[0], 'example.com'));
    }

    public function testTheDefaultStyleIsPutBackAfterAStyledSpan(): void
    {
        $markdown = new Markdown(
            'plain **bold** plain',
            paddingX: 0,
            defaultStyle: new DefaultTextStyle(colour: Style::gray(...)),
        );

        $line = $markdown->render(40)[0];

        // Bold closes with `22m`, which turns bold off and leaves the grey alone, so the
        // rest of the paragraph is still grey. Nothing on this line fully resets: a
        // reset here would drop the default style on the floor for everything after it.
        $this->assertStringContainsString("\x1b[1m", $line);
        $this->assertStringContainsString("\x1b[22m", $line);
        $this->assertStringNotContainsString("\x1b[0m", $line);
    }

    public function testTheBackgroundReachesTheEndOfTheLine(): void
    {
        $markdown = new Markdown(
            'hi',
            paddingX: 0,
            defaultStyle: new DefaultTextStyle(background: static fn (string $s): string => "[{$s}]"),
        );

        $this->assertSame('[hi        ]', $markdown->render(10)[0]);
    }

    public function testRenderingIsCachedUntilSomethingChanges(): void
    {
        $markdown = new Markdown('# Title');

        $this->assertSame($markdown->render(40), $markdown->render(40));

        $markdown->setText('# Other');
        $this->assertStringContainsString('Other', implode('', $markdown->render(40)));
    }
}
