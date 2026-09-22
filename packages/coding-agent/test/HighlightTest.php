<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pig\CodingAgent\Theme\Highlight;
use Pig\CodingAgent\Theme\HighlightTheme;
use Pig\Tui\Ansi;

/**
 * The hand-written syntax highlighter that stands in for `cli-highlight`.
 *
 * Two kinds of test here. One says the colours land where they should. The other — the
 * round trip — says nothing was lost or invented, which matters more: a highlighter that
 * eats a character is a highlighter that shows the model's code wrong.
 */
final class HighlightTest extends TestCase
{
    /** The text back out of a coloured line. */
    private function plain(string $line): string
    {
        return Ansi::strip($line);
    }

    /** @return list<string> the kinds present on a line, by their colour */
    private function kinds(string $line): array
    {
        preg_match_all('/\e\[(\d+)m/', $line, $matches);

        return $matches[1];
    }

    private function first(string $code, string $language): string
    {
        return Highlight::lines($code, $language)[0];
    }

    // ---- nothing is lost ------------------------------------------------------------

    /**
     * @param string $code
     */
    #[DataProvider('everyLanguage')]
    public function testTheCodeSurvivesBeingColoured(string $language, string $code): void
    {
        $back = implode("\n", array_map($this->plain(...), Highlight::lines($code, $language)));

        $this->assertSame($code, $back);
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function everyLanguage(): iterable
    {
        yield 'php' => ['php', "<?php\n#[Override]\nfinal class A\n{\n    // note\n    public int \$n = 0x1f;\n}\n"];
        yield 'javascript' => ['javascript', "const t = `a\nb`;\n/* block\n   comment */\nexport default 1_000;\n"];
        yield 'python' => ['python', "def f(x):\n    \"\"\"doc\n    more\n    \"\"\"\n    return x  # ok\n"];
        yield 'go' => ['go', "func main() {\n\ts := `raw`\n\tfmt.Println(s, 3.14)\n}\n"];
        yield 'rust' => ['rust', "fn main() {\n    let v: Vec<u32> = vec![1, 2];\n}\n"];
        yield 'shell' => ['shell', "#!/bin/sh\nset -e\necho \"# not a comment\"\n"];
        yield 'sql' => ['sql', "-- pick\nSELECT id FROM t WHERE a LIKE '%x%';\n"];
        yield 'json' => ['json', "{\n  \"if\": true,\n  \"n\": 1.5e3\n}\n"];
        yield 'unicode' => ['php', "<?php\n// 中文注释 — with an em dash\n\$s = '日本語';\n"];
        yield 'unterminated string' => ['php', "<?php\n\$s = 'never closed\n\$t = 1;\n"];
        yield 'unterminated comment' => ['javascript', "/* never closed\nstill going\n"];
        yield 'empty' => ['php', ''];
        yield 'no grammar' => ['brainfuck', "+++[->+<]\n"];
    }

    public function testAnEmptyBlockIsOneEmptyLine(): void
    {
        $this->assertSame([''], Highlight::lines('', 'php'));
    }

    public function testALanguageWithNoGrammarIsLeftAlone(): void
    {
        $lines = Highlight::lines("one\ntwo", 'cobol');

        $this->assertSame(['one', 'two'], $lines);
    }

    // ---- a string is a string --------------------------------------------------------

    public function testAKeywordInsideAStringStaysAString(): void
    {
        // The reason this is a scanner and not a pile of preg_replace calls.
        $line = $this->first("\$s = 'if class return';", 'php');

        $this->assertStringContainsString("\e[32m'if class return'", $line);
    }

    public function testACommentMarkerInsideAStringIsNotAComment(): void
    {
        $line = $this->first('echo "# not a comment"', 'shell');

        $this->assertStringNotContainsString("\e[2m", $line);
        $this->assertStringContainsString("\e[32m\"# not a comment\"", $line);
    }

    public function testAKeywordInsideACommentStaysAComment(): void
    {
        $line = $this->first('// if for while', 'javascript');

        $this->assertSame("\e[2m// if for while\e[0m", $line);
    }

    public function testAnUnclosedQuoteEndsWithItsLine(): void
    {
        // Otherwise one apostrophe in a shell script paints the rest of the file green,
        // which is the classic way a regex highlighter falls over.
        $lines = Highlight::lines("echo 'don\nset -e\n", 'shell');

        $this->assertStringContainsString("\e[32m'don", $lines[0]);
        $this->assertStringContainsString("\e[34mset", $lines[1]);
    }

    public function testATripleQuotedStringDoesSpanLines(): void
    {
        $lines = Highlight::lines("\"\"\"doc\nif here\n\"\"\"\nreturn 1", 'python');

        // Every line of it is green, and the code after it is not.
        $this->assertStringContainsString("\e[32m", $lines[1]);
        $this->assertStringNotContainsString("\e[34m", $lines[1]);
        $this->assertStringContainsString("\e[34mreturn", $lines[3]);
    }

    public function testEachLineClosesItsOwnStyles(): void
    {
        // The renderer compares lines, so a style left open at the end of one line is a
        // style the next line never asked for.
        foreach (Highlight::lines("/* one\ntwo\nthree */", 'javascript') as $line) {
            $this->assertSame("\e[2m" . $this->plain($line) . "\e[0m", $line);
        }
    }

    // ---- the languages ---------------------------------------------------------------

    public function testAPhpAttributeIsNotAComment(): void
    {
        // `#` opens a comment in PHP and `#[` opens an attribute, and modern PHP is full
        // of the second — reading one as the first greys out a line of real code.
        $line = $this->first('#[Override]', 'php');

        $this->assertStringNotContainsString("\e[2m", $line);
        $this->assertStringContainsString("\e[36mOverride", $line);
    }

    public function testAPhpHashCommentStillWorks(): void
    {
        $this->assertSame("\e[2m# a comment\e[0m", $this->first('# a comment', 'php'));
    }

    public function testSqlKeywordsAreMatchedWhateverTheirCase(): void
    {
        foreach (['SELECT id FROM t', 'select id from t'] as $code) {
            $this->assertStringContainsString("\e[34m", $this->first($code, 'sql'), $code);
        }
    }

    public function testACallBeatsTheCapitalisedWordGuess(): void
    {
        // Go exports with a capital, so `Println(` would otherwise come out as a type.
        $this->assertStringContainsString("\e[33mPrintln", $this->first('fmt.Println(s)', 'go'));

        // Without the bracket the guess stands.
        $this->assertStringContainsString("\e[36mVec", $this->first('let v: Vec<u32>', 'rust'));
    }

    public function testAnExplicitTypeBeatsBoth(): void
    {
        // `int(` is a cast, not a function, and the type list is the one thing here
        // that is not a guess.
        $this->assertStringContainsString("\e[36mint", $this->first('$n = (int) $x;', 'php'));
    }

    public function testNumbersAreFoundInEveryNotation(): void
    {
        foreach (['0x1f', '1_000', '3.14', '1.5e3'] as $number) {
            $this->assertStringContainsString("\e[35m{$number}\e[0m", $this->first("x = {$number};", 'javascript'), $number);
        }
    }

    public function testATrailingDotBelongsToWhatFollowsIt(): void
    {
        // `1..5` is a range and `2.toString()` a call; neither dot is part of the number.
        $this->assertStringContainsString("\e[35m1\e[0m..", $this->first('let r = 1..5;', 'rust'));
    }

    // ---- which language --------------------------------------------------------------

    public function testTheLanguageCanComeFromAFileName(): void
    {
        $this->assertSame('php', Highlight::languageFromPath('/a/b/Thing.php'));
        $this->assertSame('javascript', Highlight::languageFromPath('app.tsx'));
        $this->assertSame('shell', Highlight::languageFromPath('~/.config/setup.zsh'));
        $this->assertNull(Highlight::languageFromPath('notes.txt'));
    }

    public function testAliasesLandOnTheSameGrammar(): void
    {
        $code = 'const x = 1;';

        foreach (['js', 'ts', 'jsx', 'JavaScript'] as $alias) {
            $this->assertStringContainsString("\e[34mconst", $this->first($code, $alias), $alias);
        }
    }

    // ---- the theme -------------------------------------------------------------------

    public function testTheThemeDecidesTheColours(): void
    {
        $theme = new HighlightTheme(
            comment: static fn (string $t): string => "<c>{$t}</c>",
            string: static fn (string $t): string => "<s>{$t}</s>",
            number: static fn (string $t): string => $t,
            keyword: static fn (string $t): string => "<k>{$t}</k>",
            type: static fn (string $t): string => $t,
            function: static fn (string $t): string => $t,
            variable: static fn (string $t): string => "<v>{$t}</v>",
            plain: static fn (string $t): string => $t,
        );

        $this->assertSame(
            "<k>return</k> <v>\$x</v>; <c>// done</c>",
            Highlight::lines('return $x; // done', 'php', $theme)[0],
        );
    }
}
