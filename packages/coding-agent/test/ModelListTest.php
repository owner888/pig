<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pig\Ai\Api;
use Pig\Ai\Model;
use Pig\Ai\Models;
use Pig\Ai\Pricing;
use Pig\CodingAgent\Auth;
use Pig\CodingAgent\Cli\ModelList;
use Pig\Test\WithoutProviderKeys;
use Pig\Tui\Ansi;
use Pig\Tui\Width;

/** `--list-models`, and `--list-models <search>`. */
final class ModelListTest extends TestCase
{
    use WithoutProviderKeys;

    #[\Override]
    protected function setUp(): void
    {
        // The two cases that hand `render()` an `Auth` are about which providers have a key, and
        // the machine running the suite has opinions about that.
        $this->forgetProviderKeys();
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->restoreProviderKeys();
    }

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

    public function testTheColumnsLineUpWhenAModelIsNamedInAnotherAlphabet(): void
    {
        // `models.json` is a file somebody writes by hand, so a provider or a model can be called
        // whatever they call it. The widths were measured with `strlen()` and the cells padded with
        // `str_pad()` — self-consistent, and both wrong about what a terminal gives: 本地模型 is 12
        // bytes and 8 columns, so its row's `model` column was four to the left of every other's
        // and the whole table read as broken.
        Models::register([
            new Model('本地模型', '本地模型', Api::OpenAiCompletions, '我的机器', 'http://127.0.0.1:8080/v1', 8192, 2048),
        ]);

        try {
            $columns = [];

            foreach ($this->lines() as $line) {
                // Where the second field starts, in columns — which is what the eye reads.
                preg_match('/^(\S+\s+)/', Ansi::strip($line), $match);
                $columns[Width::visible($match[1])][] = trim(Ansi::strip($line));
            }

            $this->assertCount(1, $columns, 'the model column starts in ' . count($columns) . ' places');
        } finally {
            Models::forgetRegistered();
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
        $rows = array_slice($this->lines('github-copilot'), 1);

        // Which is how a provider's name narrows a listing of ids several providers resell —
        // `zzp-alpha` is both Anthropic's and this one's.
        $this->assertNotSame([], $rows);

        foreach ($rows as $row) {
            $this->assertStringStartsWith('github-copilot', $row);
        }
    }

    public function testTheSearchIsFuzzyAndTakesItsPunctuationLiterally(): void
    {
        // Worth pinning because it surprises twice over: `Fuzzy` matches a **subsequence**, so a
        // search finds more than a substring would — but a `.` is still a `.`, so a dotted
        // version number does not find a hyphenated one.
        //
        // The two models are registered rather than named. This case used to search `sonnet 4.5`
        // and rely on Copilot selling `claude-sonnet-4.5` while Anthropic sold
        // `claude-sonnet-4-5`; the first regeneration of the registry from models.dev retired
        // Copilot's, so the search found nothing and a rule about punctuation failed for a reason
        // that had nothing to do with punctuation.
        Models::register([
            new Model('zzp-dotted-4.5', 'Probe Dotted', Api::OpenAiResponses, 'openai',
                'https://probe.invalid', 200_000, 64_000, true, ['text'], new Pricing(1.0, 2.0)),
            new Model('zzp-hyphened-4-5', 'Probe Hyphened', Api::AnthropicMessages, 'anthropic',
                'https://probe.invalid', 200_000, 64_000, true, ['text'], new Pricing(1.0, 2.0)),
        ]);

        try {
            $rows = preg_grep('/zzp-/', array_slice($this->lines('zzp 4.5'), 1)) ?: [];

            $this->assertNotSame([], $rows);

            foreach ($rows as $row) {
                $this->assertStringContainsString('zzp-dotted-4.5', $row);
                $this->assertStringNotContainsString('zzp-hyphened', $row);
            }
        } finally {
            Models::forgetRegistered();
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

    // ---- with the keys in hand ---------------------------------------------------------------

    public function testGivenTheKeysItListsTheModelsThoseKeysReach(): void
    {
        $auth = Auth::inMemory();
        $auth->setRuntimeApiKey('anthropic', 'a-key');

        $table = ModelList::render('', $auth);

        // Upstream's `listModels()` lists `getAvailable()`, and the point of the table is to find
        // an id for `--model`: a row for a provider this machine cannot reach is a row that sends
        // somebody to a failing turn.
        $this->assertStringContainsString('claude-sonnet-4-5', $table);
        $this->assertStringNotContainsString('openai', $table);
    }

    public function testGivenTheKeysAndNoneOfThemItSaysWhatToDo(): void
    {
        $this->assertSame(
            'No model has a key here. Sign in with `pig-ai login`, or set a provider key in the environment.',
            ModelList::render('', Auth::inMemory()),
        );
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
