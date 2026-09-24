<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pig\CodingAgent\Cli\ModelList;

/** `--models`, and `--models <search>`. */
final class ModelListTest extends TestCase
{
    /** @return list<string> */
    private function lines(string $search = ''): array
    {
        return explode("\n", ModelList::render($search));
    }

    public function testTheFirstLineNamesTheColumns(): void
    {
        $this->assertSame(
            ['provider', 'model', 'context', 'max-out', 'thinking', 'images'],
            preg_split('/\s+/', trim($this->lines()[0])),
        );
    }

    public function testTheColumnsLineUpDownTheWholeTable(): void
    {
        $lines = $this->lines();
        $at = strpos($lines[0], 'model');

        foreach (array_slice($lines, 1) as $line) {
            // The widths are measured from the values, so one long provider name does not
            // push every row after it out of line.
            $this->assertSame($at, strpos($line, explode('  ', trim(substr($line, (int) $at)))[0]));
        }
    }

    public function testNoRowEndsInTrailingSpace(): void
    {
        foreach ($this->lines() as $line) {
            // The last column is padded like the others and then trimmed, which only shows
            // up when somebody pipes this into a diff.
            $this->assertSame($line, rtrim($line));
        }
    }

    public function testEveryRegisteredModelIsListed(): void
    {
        // Header plus one row each. A listing that quietly drops a model is a model nobody
        // can find the id of.
        $this->assertCount(count(\Pig\Ai\Models::all()) + 1, $this->lines());
    }

    // ---- searching -----------------------------------------------------------------------

    public function testASearchNarrowsToWhatWasAskedFor(): void
    {
        $rows = array_slice($this->lines('haiku'), 1);

        $this->assertLessThan(count($this->lines()) - 1, count($rows), 'fewer than everything');
        $this->assertNotSame([], preg_grep('/claude-haiku-4-5\s/', $rows), 'and the one meant by it');

        // Not "every row says haiku": `Fuzzy` matches a subsequence, so `moonshotai/kimi-k2-instruct`
        // matches it too (h·a·i·k·u are all in there, in order). That is upstream's algorithm
        // and upstream's `--list-models` behaves the same way.
    }

    public function testASearchIsOverProviderAndIdAsOneString(): void
    {
        $rows = array_slice($this->lines('google-gemini-cli'), 1);

        // Which is how a provider's name narrows a listing of ids several providers resell.
        $this->assertNotSame([], $rows);

        foreach ($rows as $row) {
            $this->assertStringStartsWith('google-gemini-cli', $row);
        }
    }

    public function testTheSearchIsFuzzyAndTakesItsPunctuationLiterally(): void
    {
        $rows = array_slice($this->lines('sonnet 4.5'), 1);

        // Worth pinning because it surprises twice over. `Fuzzy` matches a **subsequence**,
        // so a search finds more than a substring would — but a `.` is still a `.`, so this
        // finds Copilot's `claude-sonnet-4.5` and not Anthropic's `claude-sonnet-4-5`.
        $this->assertNotSame([], $rows);

        foreach ($rows as $row) {
            $this->assertStringContainsString('4.5', $row);
        }
    }

    public function testTheRowsAreOrderedForReadingAndNotByScore(): void
    {
        $rows = array_slice($this->lines('haiku'), 1);
        $sorted = $rows;
        sort($sorted);

        // `Fuzzy::filter` hands back best-match-first, which is the right order for a picker
        // and the wrong one for a table somebody scans by provider. Upstream re-sorts too.
        $this->assertSame($sorted, $rows);
    }

    public function testASearchThatMatchesNothingSaysSoAndQuotesIt(): void
    {
        $this->assertSame('No models matching "zzzz".', ModelList::render('zzzz'));
    }

    public function testAnEmptySearchIsNotASearch(): void
    {
        $this->assertSame(ModelList::render(), ModelList::render(''));
    }

    // ---- the token column ------------------------------------------------------------------

    /** @return list<array{0: int, 1: string}> */
    public static function counts(): array
    {
        return [
            [999, '999'],
            [1_000, '1K'],
            [4_096, '4.1K'],
            [8_192, '8.2K'],
            [64_000, '64K'],
            [65_536, '65.5K'],
            [200_000, '200K'],
            [1_000_000, '1M'],
            [1_048_576, '1.0M'],
            [1_500_000, '1.5M'],
        ];
    }

    #[DataProvider('counts')]
    public function testTokenCountsReadTheWayUpstreamWritesThem(int $count, string $expected): void
    {
        // A round number keeps no decimal. The obvious spelling of that test —
        // `$scaled === floor($scaled)` — is always false, because PHP's `/` returns an **int**
        // when it divides evenly and `floor()` always returns a float, so every one of these
        // came out as `200.0K` until the check became integer modulo.
        $tokens = new \ReflectionMethod(ModelList::class, 'tokens');

        $this->assertSame($expected, $tokens->invoke(null, $count));
    }
}
