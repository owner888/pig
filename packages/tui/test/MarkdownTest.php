<?php

declare(strict_types=1);

namespace Pig\Tui\Test;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pig\Tui\Ansi;
use Pig\Tui\Components\DefaultTextStyle;
use Pig\Tui\Components\Markdown;
use Pig\Tui\Components\MarkdownTheme;
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

    public function testANumberedListKeepsItsOwnNumbers(): void
    {
        // Found by running pig's lexer against `marked` over a corpus. `ListBlock` had no idea
        // where a list started, so every ordered list was drawn from 1 — a model quoting steps
        // three and four of a procedure had them silently renamed one and two.
        $lines = implode("\n", $this->rows("3. three\n4. four\n"));

        $this->assertStringContainsString('3. three', $lines);
        $this->assertStringContainsString('4. four', $lines);
    }

    public function testABlankLineBetweenItemsIsOneLooseListAndNotThree(): void
    {
        // The commonest thing a model writes — a numbered list whose items are paragraphs — and
        // pig read each item as a list of its own. Every other markdown reader calls this one
        // *loose* list, and the difference shows in two places at once: the numbering, and whether
        // the blank lines the author put between the items survive.
        $this->assertSame(
            ['1. one', '', '2. two', '', '3. three', ''],
            $this->rows("1. one\n\n2. two\n\n3. three\n"),
        );
    }

    public function testALooseListNumberedAllOnesIsStillCountedUp(): void
    {
        // The idiom where you let the renderer do the counting. Read as three lists it came out
        // `1.` `1.` `1.`, and a `start` per list cannot save it — they each start at one.
        $this->assertSame(
            ['1. one', '', '2. two', '', '3. three', ''],
            $this->rows("1. one\n\n1. two\n\n1. three\n"),
        );
    }

    public function testATightListKeepsItsItemsTogether(): void
    {
        // The other half of the distinction: no blank lines in, none out.
        $this->assertSame(['1. one', '2. two', ''], $this->rows("1. one\n2. two\n"));
    }

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

    public function testEmphasisNextToCjkPunctuation(): void
    {
        // Upstream's #10154 cases, which marked needed `marked-cjk-friendly` for: CommonMark's
        // flanking rules refuse a closer between punctuation and a letter. This scanner never had
        // those rules — a closer only may not follow a space — so they already render; kept here
        // so a stricter scanner later does not lose them.
        foreach ([
            ['**这是测试。**后面', [Strong::class, Text::class]],
            ['**一句话说清：**一台机器', [Strong::class, Text::class]],
            ['**要点到此为止。**20 小时', [Strong::class, Text::class]],
            ['字**「引用」**字', [Text::class, Strong::class, Text::class]],
            ['*斜体。*后面', [Emphasis::class, Text::class]],
        ] as [$markdown, $expected]) {
            $this->assertSame($expected, array_map(static fn (object $token): string => $token::class, Inline::tokenize($markdown)), $markdown);
        }
    }

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

        $this->assertSame(['```php', '  $x = 1;', '```'], $rows);
    }

    public function testTheLastBlockLeavesNoBlankLineUnderItself(): void
    {
        // Upstream adds a block's trailing blank only when a next token exists. With one added
        // after the last block too, a one-line user message drew two empty rows under itself
        // where pi draws one, and every transcript entry carried an extra row of background.
        $this->assertSame(['one line'], $this->rows('one line'));
        $this->assertSame(['first', '', 'second'], $this->rows("first\n\nsecond"));
        $this->assertSame(['Title', '', 'body'], $this->rows("# Title\nbody"));
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

    public function testQuotesNestedPastTheWidthStopAddingBorders(): void
    {
        // Upstream #11272: past about 39 levels at 80 columns every further `>` wrapped each
        // child row at width 1 again, so the rows tripled per level — 60 of them never finished.
        $start = microtime(true);
        $rows = $this->rows(str_repeat('>', 60) . 'x', 80);

        $this->assertLessThan(2.0, microtime(true) - $start);
        $this->assertCount(1, $rows);
        $this->assertStringEndsWith('x', rtrim($rows[0]));
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

    /**
     * A table stays one table at every width it claims to be drawable at.
     *
     * The columns are shrunk proportionally, and `max(1, …)` on each one can push the total
     * back **over** the budget — nothing took the excess away, so every line came out one or
     * two columns too wide and the wrap in `lines()` broke each of them onto a second row.
     * The user-visible property is this one: the top border is one line and ends in a corner.
     *
     * @return array<string, array{string}>
     */
    public static function tables(): array
    {
        return [
            'one wide column and two narrow' => ["| " . str_repeat('y', 120) . " | b | c |\n|---|---|---|\n| 1 | 2 | 3 |"],
            'three narrow columns and prose' => ["| a | b | c | " . str_repeat('w', 150) . " |\n|---|---|---|---|\n| 1 | 2 | 3 | 4 |"],
            'a path beside two ticks' => ["| File | ok | n |\n|---|---|---|\n| packages/coding-agent/src/Interactive/InteractiveMode.php | y | 3 |"],
            'three prose columns' => ["| Flag | Default | What it does |\n|---|---|---|\n| `--tools` | read, bash, edit | Names the tools the model is given |"],
        ];
    }

    #[DataProvider('tables')]
    public function testATableIsOneTableAtEveryWidthItDrawsAt(string $markdown): void
    {
        for ($width = 12; $width <= 120; $width++) {
            $rows = $this->rows($markdown, $width);

            if (!str_contains($rows[0] ?? '', '┌')) {
                continue;   // Too narrow to draw: the source is shown instead.
            }

            $this->assertStringEndsWith('┐', $rows[0], "the top border wrapped at width {$width}");

            foreach ($rows as $index => $row) {
                $this->assertLessThanOrEqual($width, Width::visible($row), "row {$index} at width {$width}");
            }
        }
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

        // Three rows of text, and nothing after them: the last block leaves no blank.
        $this->assertCount(3, $lines);
        // The wrapper reopens the style it broke across.
        $this->assertStringContainsString("\x1b[1m", $lines[1]);
    }

    public function testALinkShowsItsUrlUnlessTheTextAlreadyIsTheUrl(): void
    {
        $this->assertStringContainsString('(https://example.com)', $this->rows('[docs](https://example.com)')[0]);
        $this->assertSame(1, substr_count($this->rows('<https://example.com>')[0], 'example.com'));
    }

    public function testACodeBlockIsIndentedWithWhatTheThemeSays(): void
    {
        $fence = "```\nx = 1\n```";
        $default = MarkdownTheme::default();
        $flush = new MarkdownTheme(...[...get_object_vars($default), 'codeBlockIndent' => '']);

        $codeLine = static fn (array $lines): string => array_values(array_filter(array_map(Ansi::strip(...), $lines), static fn (string $l): bool => str_contains($l, 'x = 1')))[0];

        $this->assertStringStartsWith('  x = 1', $codeLine((new Markdown($fence, paddingX: 0))->render(30)));
        $this->assertStringStartsWith('x = 1', $codeLine((new Markdown($fence, paddingX: 0, theme: $flush))->render(30)));
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

    public function testInvalidateSamplesTheDefaultStyleAgain(): void
    {
        // The closure reads its colour when called, as the coding agent's theme closures do; a
        // theme change is a different answer from the same closure, followed by `invalidate()`.
        $colour = '31';
        $markdown = new Markdown(
            'plain **bold** plain',
            paddingX: 0,
            defaultStyle: new DefaultTextStyle(colour: static function (string $s) use (&$colour): string {
                return "\x1b[{$colour}m{$s}\x1b[39m";
            }),
        );
        $this->assertStringContainsString("\x1b[31m", $markdown->render(40)[0]);

        $colour = '32';
        $markdown->invalidate();
        $line = $markdown->render(40)[0];

        // The text after the bold span is reopened with the sampled prefix, which is where the old
        // colour survived.
        $this->assertStringContainsString("\x1b[32m", $line);
        $this->assertStringNotContainsString("\x1b[31m", $line);
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

    // ---- LaTeX math (upstream's `markdown.test.ts`, "LaTeX math") ----------------------

    /** @return list<string> */
    private function mathRows(string $markdown, bool $renderLatex = true): array
    {
        $lines = (new Markdown($markdown, 0, 0, null, null, $renderLatex))->render(80);

        return array_map(static fn (string $line): string => rtrim(Ansi::strip($line)), $lines);
    }

    public function testRendersInlineDollarAndParenthesisDelimiters(): void
    {
        $this->assertSame(
            ['A map ℂ³ → ℂ³, xy, x-y, -x, 1/2, →, and s → ∞.'],
            $this->mathRows('A map $\mathbb{C}^3 \to \mathbb{C}^3$, $xy$, $x-y$, $-x$, $\frac{1}{2}$, $\rightarrow$, and \(s \to \infty\).'),
        );
    }

    public function testRendersDisplayDollarDelimitersWithoutMarkdownEscapeCorruption(): void
    {
        $this->assertSame(
            ['Before', '', '{3x+2y, x ∈ {0, ± 1}}', '', 'after'],
            $this->mathRows(<<<'MD'
                Before

                $$\{3x+2y,\; x \in \{0, \pm 1\}\}$$

                after
                MD),
        );
    }

    public function testRendersDisplayBracketDelimiters(): void
    {
        $this->assertSame(
            ['Before', '', '    0.1 lux', 'E ≈ ────────', '    100 lm/W', '', 'after'],
            $this->mathRows(<<<'MD'
                Before

                \[
                E \approx \frac{0.1\ \text{lux}}{100\ \text{lm/W}}
                \]

                after
                MD),
        );
    }

    public function testAlignsMatrixRowsWithTheOpeningDelimiter(): void
    {
        $this->assertSame(
            ['Consider the matrix', '', 'A = ⎛ π │ 0   ⎞', '    ⎝ 0 │ 1/π ⎠.'],
            $this->mathRows(<<<'MD'
                Consider the matrix

                \[
                A=
                \begin{pmatrix}
                \pi & 0\\
                0 & \frac{1}{\pi}
                \end{pmatrix}.
                \]
                MD),
        );
    }

    public function testRendersLowerLimitsBeneathDisplayOperators(): void
    {
        $this->assertSame(
            ['     (sin x)/x-1', 'lim  ─────────── = 0', 'x→0  (eˣ-1)/x-1'],
            $this->mathRows(<<<'MD'
                \[
                \lim_{x\to 0}\frac{\frac{\sin x}{x}-1}{\frac{e^x-1}{x}-1}=0
                \]
                MD),
        );
    }

    public function testRendersMathInsideListsAndTables(): void
    {
        $output = implode("\n", $this->mathRows(<<<'MD'
            - Formula: $F_1 = u^2$

            | Value |
            | --- |
            | $\mathbb{C}^3$ |
            MD));

        $this->assertStringContainsString('- Formula: F₁ = u²', $output);
        $this->assertStringContainsString('│ ℂ³', $output);
    }

    public function testDoesNotTreatCurrencyShellVariablesOrCodeSpansAsMath(): void
    {
        $this->assertSame(
            ['Costs $5 and $10 or $8k–$12k; use $x$, $HOME, and ${PATH}.'],
            $this->mathRows('Costs $5 and $10 or $8k–$12k; use `$x$`, $HOME, and ${PATH}.'),
        );

        $shellVariables = 'Paths: $HOME/$USER and $XDG_CONFIG_HOME/$APP_CONFIG';
        $this->assertSame([$shellVariables], $this->mathRows($shellVariables));
    }

    public function testPreservesUnsupportedAndIncompleteLatexExactly(): void
    {
        foreach (['Unknown $x + \unknown{y}$ after', 'Streaming $\mathbb{C}^3'] as $source) {
            $this->assertSame([$source], $this->mathRows($source));
        }
    }

    public function testPreservesIncompleteBackslashDelimitersWhileStreaming(): void
    {
        $this->assertSame(['Map \(\mathbb{C}^3'], $this->mathRows('Map \(\mathbb{C}^3'));
        $this->assertSame(['\[', 'x^2'], $this->mathRows("\\[\nx^2"));
    }

    public function testDoesNotRenderLatexInsideEscapedDelimitersOrCodeFences(): void
    {
        // `\$` is an escape here as it is to `marked`: `$` joined the escapable set with LaTeX.
        $source = implode("\n", ['Escaped \$x-y\$.', '', '```text', '$\mathbb{C}^3$', '```']);

        $this->assertSame(['Escaped $x-y$.', '', '```text', '  $\mathbb{C}^3$', '```'], $this->mathRows($source));
    }

    public function testAllowsLatexRenderingToBeDisabled(): void
    {
        $this->assertSame(
            ['$$', '\widetilde Y_{sf}', '=', '(1-w_{sf})\mu_{sf}^{\mathrm{MAR}}', '$$', '', 'Inline \(A_{sf}\)'],
            $this->mathRows(<<<'MD'
                $$
                \widetilde Y_{sf}
                =
                (1-w_{sf})\mu_{sf}^{\mathrm{MAR}}
                $$

                Inline \(A_{sf}\)
                MD, false),
        );
    }

    /** @return iterable<string, array{list<string>, list<string>}> */
    public static function unclosedDisplayMathBeforeTables(): iterable
    {
        $table = ['| A | B |', '|---|---|', '| 1 | 2 |'];
        $rendered = ['┌───┬───┐', '│ A │ B │', '├───┼───┤', '│ 1 │ 2 │', '└───┴───┘'];

        yield 'a table after an orphan $$ opener' => [
            ['Intro', '', '$$', '', ...$table, '', 'After'],
            ['Intro', '', '$$', '', ...$rendered, '', 'After'],
        ];
        yield 'a table after an orphan $$ opener with math-like content' => [
            ['$$', 'x = 1', '', ...$table],
            ['$$', 'x = 1', '', ...$rendered],
        ];
        yield 'an unclosed $$ not paired with a later $$ across a table' => [
            ['Intro', '', '$$', 'x = 1', '', ...$table, '', '$$', 'y^2', '$$'],
            ['Intro', '', '$$', 'x = 1', '', ...$rendered, '', 'y²'],
        ];
        yield 'a single-column table with a pipe-free aligned delimiter after an orphan $$' => [
            ['$$', '', '| A |', ':---:', '| 1 |'],
            ['$$', '', '┌───┐', '│ A │', '├───┤', '│ 1 │', '└───┘'],
        ];
        // Upstream draws `\\[` and `x^2` on lines of their own (and a stray blank line in front, from
        // `marked` cutting the paragraph one character in); pig joins a paragraph's soft line breaks
        // into spaces. What the case is about holds either way: the table is still a table.
        yield 'a table after an orphan \[ opener' => [
            ['\\\\[', 'x^2', '', ...$table],
            ['\\[ x^2', '', ...$rendered],
        ];
        yield 'an unclosed \[ not paired with a later \[ across a table' => [
            ['\\\\[', 'x = 1', '', ...$table, '', '\\\\[', 'y^2', '\\\\\\\\]'],
            ['\\[ x = 1', '', ...$rendered, '', '\\[ y^2 \\\\]'],
        ];
        // Upstream's lexer takes `Value` over `| --- |` as a table; pig's wants a pipe in the header
        // row, so here it is a paragraph — but the orphan `$$` still does not swallow it.
        yield 'a pipe-free header after an orphan $$' => [
            ['$$', 'x^2', '', 'Value', '| --- |', '1'],
            ['$$', 'x^2', '', 'Value | --- | 1'],
        ];
        yield 'closed multi-line display math followed by a table' => [
            ['$$', 'a', '+ b', '$$', '', ...$table],
            ['a + b', '', ...$rendered],
        ];
    }

    /**
     * Upstream's #11159: display math never spans a table.
     *
     * @param list<string> $source
     * @param list<string> $expected
     */
    #[DataProvider('unclosedDisplayMathBeforeTables')]
    public function testUnclosedDisplayMathDoesNotSwallowATable(array $source, array $expected): void
    {
        $this->assertSame($expected, $this->mathRows(implode("\n", $source)));
    }

    public function testSwitchesFromRawToRenderedMathWhenAStreamedDelimiterCloses(): void
    {
        $markdown = new Markdown('Map $\mathbb{C}^3', 0, 0);
        $strip = static fn (array $lines): array => array_map(static fn (string $line): string => rtrim(Ansi::strip($line)), $lines);

        $this->assertSame(['Map $\mathbb{C}^3'], $strip($markdown->render(80)));

        $markdown->setText('Map $\mathbb{C}^3$');

        $this->assertSame(['Map ℂ³'], $strip($markdown->render(80)));
    }
}
