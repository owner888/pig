<?php

declare(strict_types=1);

namespace Pig\Tui\Test;

use PHPUnit\Framework\TestCase;
use Pig\Tui\Fuzzy;

/**
 * Upstream's `fuzzy.test.ts`, case for case, plus the cases PHP needs and JavaScript does not.
 *
 * Every assertion about a score is a comparison and none is a constant: the numbers are upstream's
 * and could be tuned, but which of two matches wins is what a picker feels like to type into, and
 * that is what must not drift.
 */
final class FuzzyTest extends TestCase
{
    // ---- matching -----------------------------------------------------------------------

    public function testAnEmptyQueryMatchesEverythingAndRanksItAlike(): void
    {
        $this->assertSame([true, 0.0], Fuzzy::match('', 'anything'));
    }

    public function testAQueryLongerThanTheTextCannotMatch(): void
    {
        [$matched] = Fuzzy::match('longquery', 'short');

        $this->assertFalse($matched);
    }

    public function testAnExactMatchScoresWell(): void
    {
        [$matched, $score] = Fuzzy::match('test', 'test');

        $this->assertTrue($matched);
        $this->assertLessThan(0, $score);
    }

    public function testTheCharactersHaveToAppearInOrder(): void
    {
        [$inOrder] = Fuzzy::match('abc', 'aXbXc');
        [$outOfOrder] = Fuzzy::match('abc', 'cba');

        $this->assertTrue($inOrder);
        $this->assertFalse($outOfOrder);
    }

    public function testCaseIsIgnoredInBothDirections(): void
    {
        $this->assertTrue(Fuzzy::match('ABC', 'abc')[0]);
        $this->assertTrue(Fuzzy::match('abc', 'ABC')[0]);
    }

    public function testCharactersTogetherBeatCharactersScattered(): void
    {
        [$togetherMatched, $together] = Fuzzy::match('foo', 'foobar');
        [$scatteredMatched, $scattered] = Fuzzy::match('foo', 'f_o_o_bar');

        $this->assertTrue($togetherMatched);
        $this->assertTrue($scatteredMatched);
        $this->assertLessThan($scattered, $together);
    }

    public function testTheStartOfAWordBeatsTheMiddleOfOne(): void
    {
        [$boundaryMatched, $atBoundary] = Fuzzy::match('fb', 'foo-bar');
        [$insideMatched, $inside] = Fuzzy::match('fb', 'afbx');

        $this->assertTrue($boundaryMatched);
        $this->assertTrue($insideMatched);
        $this->assertLessThan($inside, $atBoundary);
    }

    public function testASpaceThatIsNotU0020StartsAWordToo(): void
    {
        // A conversation's text is whatever was pasted into it, so these arrive without anybody
        // typing them: a full-width space from a Chinese IME, a non-breaking space from a web
        // page, and a byte-order mark from the front of a file.
        foreach (["\u{3000}", "\u{00A0}", "\u{FEFF}", "\u{2009}"] as $separator) {
            $this->assertLessThan(
                Fuzzy::match('b', 'ab')[1],
                Fuzzy::match('b', 'a' . $separator . 'b')[1],
                sprintf('U+%04X should start a word', mb_ord($separator, 'UTF-8')),
            );
        }
    }

    public function testDigitsTypedOnTheWrongSideOfTheLettersStillMatch(): void
    {
        $this->assertTrue(Fuzzy::match('codex52', 'gpt-5.2-codex')[0]);
    }

    public function testTheSwapCostsTheItemItsPlaceAmongItsRivals(): void
    {
        // The penalty is charged against the *other items* for the same query, which is the only
        // comparison a score is good for: two different queries produce two different numbers of
        // matched characters and so two scales. A rival that matched `co52` as typed comes first.
        $items = ['gpt-5.2-codex', 'co52'];

        $this->assertSame(['co52', 'gpt-5.2-codex'], Fuzzy::filter($items, 'co52', static fn (string $x): string => $x));
    }

    public function testAQueryThatIsNeitherShapeGetsNoSecondChance(): void
    {
        $this->assertFalse(Fuzzy::match('52-codex-x', 'gpt-5.2-codex')[0]);
    }

    // ---- filtering ----------------------------------------------------------------------

    public function testAnEmptyQueryReturnsEverythingUntouched(): void
    {
        $items = ['apple', 'banana', 'cherry'];

        $this->assertSame($items, Fuzzy::filter($items, '', static fn (string $x): string => $x));
        $this->assertSame($items, Fuzzy::filter($items, '   ', static fn (string $x): string => $x));

        // And a space that is not U+0020 is still nothing typed. Before the split was `/u`, a
        // lone full-width space was a *character to match*, so the list came back holding
        // whichever item happened to contain one — usually none.
        foreach (["\u{3000}", "\u{00A0}", "\u{FEFF}", "\u{3000}\u{00A0} "] as $blank) {
            $this->assertSame($items, Fuzzy::filter($items, $blank, static fn (string $x): string => $x), sprintf('%s is still blank', bin2hex($blank)));
        }
    }

    public function testASpaceThatIsNotU0020SeparatesTokens(): void
    {
        // **The bug this pins, and it is not exotic**: pressing space on a Chinese keyboard in
        // full-width mode produces U+3000, and pasting from a web page brings U+00A0. The split
        // was ASCII-only, so `tls　handshake` was a single token with a character in the middle
        // that no conversation contains — and the `/resume` search box went empty on the one
        // keystroke somebody uses to narrow it.
        $items = ['debug the tls handshake', 'the markdown lexer'];

        foreach (["tls\u{3000}handshake", "tls\u{00A0}handshake", "tls\u{2009}handshake"] as $query) {
            $this->assertSame(['debug the tls handshake'], Fuzzy::filter($items, $query, static fn (string $x): string => $x), bin2hex($query));
        }

        // A mark on the front of a pasted query is not part of the first token either.
        $this->assertSame(['debug the tls handshake'], Fuzzy::filter($items, "\u{FEFF}tls", static fn (string $x): string => $x));
    }

    public function testWhatDoesNotMatchIsLeftOut(): void
    {
        $result = Fuzzy::filter(['apple', 'banana', 'cherry'], 'an', static fn (string $x): string => $x);

        $this->assertSame(['banana'], $result);
    }

    public function testTheBestMatchComesFirst(): void
    {
        $result = Fuzzy::filter(['a_p_p', 'app', 'application'], 'app', static fn (string $x): string => $x);

        $this->assertSame('app', $result[0]);
    }

    public function testTheWholeTextBeatsAMerePrefixOfIt(): void
    {
        $result = Fuzzy::filter(['clone', 'cl'], 'cl', static fn (string $x): string => $x);

        $this->assertSame(['cl', 'clone'], $result);
    }

    public function testTheTextToMatchOnIsTheCallersToChoose(): void
    {
        $items = [['name' => 'foo'], ['name' => 'bar'], ['name' => 'foobar']];

        $result = Fuzzy::filter($items, 'foo', static fn (array $item): string => $item['name']);

        $this->assertSame([['name' => 'foo'], ['name' => 'foobar']], $result);
    }

    public function testEveryTokenHasToMatchAndSlashesSeparateThemToo(): void
    {
        // What makes `openai-codex/gpt-5.5` find a model whose text names the two the other way
        // round — and `provider nonsense` find nothing.
        $item = ['id' => 'gpt-5.5', 'provider' => 'openai-codex'];
        $text = static fn (array $model): string => $model['id'] . ' ' . $model['provider'];

        $this->assertSame([$item], Fuzzy::filter([$item], 'openai-codex/gpt-5.5', $text));
        $this->assertSame([$item], Fuzzy::filter([$item], 'openai gpt', $text));
        $this->assertSame([], Fuzzy::filter([$item], 'openai nonsense', $text));
    }

    public function testItemsThatScoreAlikeKeepTheOrderTheyCameIn(): void
    {
        // The model picker hands its list in sorted: current model first, then the default. A
        // query they both match equally must not reshuffle them.
        $items = ['claude-x', 'claude-y', 'claude-z'];

        $this->assertSame($items, Fuzzy::filter($items, 'claude-', static fn (string $x): string => $x));
    }

    // ---- pinned against the original ----------------------------------------------------

    /**
     * Upstream's own numbers, taken by running its `fuzzy.ts` and recording what came back.
     *
     * The comparisons above say the ranking is sane; this says it is *the same*, which is the
     * thing a port can lose without any test noticing. If one of these moves, the scoring was
     * changed — deliberately or not — and every picker in the program now feels different.
     *
     * @return iterable<string, array{string, string, bool, float}>
     */
    public static function upstreamScores(): iterable
    {
        yield 'empty query' => ['', 'anything', true, 0.0];
        yield 'the whole text' => ['test', 'test', true, -159.4];
        yield 'in order with gaps' => ['abc', 'aXbXc', true, -10.4];
        yield 'out of order' => ['abc', 'cba', false, 0.0];
        yield 'together' => ['foo', 'foobar', true, -39.7];
        yield 'scattered' => ['foo', 'f_o_o_bar', true, -30.4];
        yield 'at word starts' => ['fb', 'foo-bar', true, -18.6];
        yield 'inside words' => ['fb', 'afbx', true, -4.7];
        yield 'digits swapped' => ['codex52', 'gpt-5.2-codex', true, -65.0];
        yield 'digits swapped, shorter' => ['co52', 'gpt-5.2-codex', true, -23.3];
        yield 'neither shape' => ['52-codex-x', 'gpt-5.2-codex', false, 0.0];
        yield 'text is the query' => ['cl', 'cl', true, -124.9];
        yield 'query is a prefix' => ['cl', 'clone', true, -24.9];
        yield 'a model id in a search line' => ['gpt', 'openai gpt-5.5 openai/gpt-5.5', true, -22.6];
        yield 'a provider in a search line' => ['anthropic', 'anthropic anthropic/claude-opus-4-5 anthropic claude-opus-4-5', true, -231.4];
        yield 'a late match scores above zero' => ['opus', 'anthropic anthropic/claude-opus-4-5 anthropic claude-opus-4-5', true, 45.4];
        yield 'a version number' => ['3.8', 'google google/gemini-3.8-flash google gemini-3.8-flash Gemini 3.8 Flash', true, -28.4];
        yield 'a name and a version, swapped' => ['flash38', 'google google/gemini-3.8-flash google gemini-3.8-flash Gemini 3.8 Flash', true, -25.3];
        yield 'multibyte, late' => ['项目', '构建中的项目', true, -4.1];
        yield 'multibyte, at the start' => ['项目', '项目构建', true, -24.9];
        yield 'multibyte against ascii' => ['项目', 'project', false, 0.0];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('upstreamScores')]
    public function testTheScoreIsTheOneUpstreamGives(string $query, string $text, bool $expected, float $score): void
    {
        [$matched, $actual] = Fuzzy::match($query, $text);

        $this->assertSame($expected, $matched);
        $this->assertSame($score, round($matched ? $actual : 0.0, 6) + 0.0);
    }

    // ---- what PHP has to be told and JavaScript does not --------------------------------

    public function testAMultibyteLabelIsMatchedByCharacterAndNotByByte(): void
    {
        // `strpos()` looking for one byte of a three-byte character finds the middle of the
        // character before it, and the index it returns then poisons every gap and boundary
        // sum that follows. Three separate scores here, all of which have to be real numbers.
        $items = ['构建中的项目', '项目构建', '别的东西'];

        $result = Fuzzy::filter($items, '项目', static fn (string $x): string => $x);

        $this->assertSame(['项目构建', '构建中的项目'], $result);
    }

    public function testPositionIsCountedInCharactersNotBytes(): void
    {
        // `好` is the second character of `你好` and the fourth, fifth and sixth *bytes* of it —
        // so a byte walk finds three consecutive matches near the end of the string and scores
        // this about -13.8 instead of one match at position 1.
        $this->assertSame(0.1, Fuzzy::match('好', '你好')[1]);
    }

    public function testAChineseQueryMatchesChineseText(): void
    {
        $this->assertTrue(Fuzzy::match('握手', '调试 tls 握手')[0]);
        $this->assertFalse(Fuzzy::match('手握', '调试 tls 握手')[0]);
    }

    public function testAQueryInOneScriptDoesNotMatchTextInAnother(): void
    {
        $this->assertFalse(Fuzzy::match('项目', 'project')[0]);
        $this->assertFalse(Fuzzy::match('project', '项目')[0]);
    }
}
