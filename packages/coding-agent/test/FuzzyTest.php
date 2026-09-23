<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;
use Pig\CodingAgent\Utils\Fuzzy;

/**
 * Matching a query against text the way a search box wants it matched.
 *
 * Most of these assert an *ordering* rather than a number, because the numbers are upstream's
 * weights and the thing that matters is which of two candidates comes out in front. The two
 * that do assert a number are the ones where the arithmetic itself is the claim.
 */
final class FuzzyTest extends TestCase
{
    // ---- matching -----------------------------------------------------------------------

    public function testAnEmptyQueryMatchesEverything(): void
    {
        // Not a filter yet. Answering false here would show an empty list before a key is hit.
        $match = Fuzzy::match('', 'anything at all');

        $this->assertTrue($match->matches);
        $this->assertSame(0.0, $match->score);
    }

    public function testEveryCharacterHasToBeThereInOrder(): void
    {
        $this->assertTrue(Fuzzy::match('tls', 'the tls handshake')->matches);
        $this->assertTrue(Fuzzy::match('tlshk', 'the tls handshake')->matches);

        // The same letters, the wrong way round.
        $this->assertFalse(Fuzzy::match('slt', 'the tls handshake')->matches);
    }

    public function testAQueryLongerThanTheTextCannotMatch(): void
    {
        $this->assertFalse(Fuzzy::match('handshake', 'tls')->matches);
    }

    public function testCaseIsIgnoredOnBothSides(): void
    {
        $this->assertTrue(Fuzzy::match('TLS', 'the tls handshake')->matches);
        $this->assertTrue(Fuzzy::match('tls', 'The TLS Handshake')->matches);
    }

    public function testCharactersTogetherBeatCharactersScattered(): void
    {
        // What makes typing `foo` useful: `foobar` before `f_o_o`.
        $this->assertLessThan(
            Fuzzy::match('foo', 'f_o_o')->score,
            Fuzzy::match('foo', 'foobar')->score,
        );
    }

    public function testTheStartOfAWordBeatsTheMiddleOfOne(): void
    {
        $this->assertLessThan(
            Fuzzy::match('agent', 'management')->score,
            Fuzzy::match('agent', 'the agent loop')->score,
        );
    }

    public function testAMatchEarlyInTheTextBeatsOneLate(): void
    {
        $this->assertLessThan(
            Fuzzy::match('x', 'aaaaaaaaax')->score,
            Fuzzy::match('x', 'xaaaaaaaaa')->score,
        );
    }

    public function testALongerRunIsWorthMoreThanTwoShortOnes(): void
    {
        // The reward grows along the run — `$consecutive * 5`, not a flat 5 — so four
        // together outscore two and two.
        $this->assertLessThan(
            Fuzzy::match('abcd', 'ab_cd')->score,
            Fuzzy::match('abcd', 'abcd')->score,
        );
    }

    public function testAWordBoundaryIsAnyOfUpstreamsFiveSeparators(): void
    {
        foreach ([' ', '-', '_', '.', '/'] as $separator) {
            $this->assertLessThan(
                Fuzzy::match('b', 'ab')->score,
                Fuzzy::match('b', 'a' . $separator . 'b')->score,
                "'{$separator}' should start a word",
            );
        }
    }

    // ---- characters, not bytes ----------------------------------------------------------

    public function testPositionIsCountedInCharactersNotBytes(): void
    {
        // The one deliberate difference from upstream, and the reason for it. `好` is the
        // second character of `你好` and the fourth, fifth and sixth *bytes* of it — so a
        // byte walk finds three consecutive matches near the end of the string and scores
        // this about -13.8 instead of one match at position 1.
        $this->assertSame(0.1, Fuzzy::match('好', '你好')->score);
    }

    public function testAChineseQueryMatchesChineseText(): void
    {
        $this->assertTrue(Fuzzy::match('握手', '调试 tls 握手')->matches);
        $this->assertFalse(Fuzzy::match('手握', '调试 tls 握手')->matches);
    }

    // ---- filtering ----------------------------------------------------------------------

    /** @param list<string> $items */
    private function filter(array $items, string $query): array
    {
        return Fuzzy::filter($items, $query, static fn (string $item): string => $item);
    }

    public function testABlankQueryGivesTheListBackInItsOwnOrder(): void
    {
        // Which for sessions is newest first, and is the answer somebody who has typed
        // nothing is expecting to see.
        $items = ['third', 'second', 'first'];

        $this->assertSame($items, $this->filter($items, ''));
        $this->assertSame($items, $this->filter($items, '   '));
    }

    public function testOnlyTheMatchesComeBack(): void
    {
        $this->assertSame(['tls handshake'], $this->filter(['tls handshake', 'the markdown lexer'], 'tls'));
    }

    public function testEveryTokenHasToMatch(): void
    {
        $items = ['the tls handshake', 'tls somewhere else', 'a handshake'];

        // `tls handshake` is two separate searches over the same text, both of which have
        // to hit — which is what makes a long haystack narrowable at all.
        $this->assertSame(['the tls handshake'], $this->filter($items, 'tls handshake'));
    }

    public function testTheBestMatchComesFirst(): void
    {
        $matches = $this->filter(['xxxxxxxxtls', 'tls first'], 'tls');

        $this->assertSame(['tls first', 'xxxxxxxxtls'], $matches);
    }

    public function testTwoItemsScoringTheSameKeepTheOrderTheyArrivedIn(): void
    {
        // `usort` is stable from PHP 8.0, as JavaScript's sort is, and a list of sessions
        // arrives here sorted by time. Ties coming back shuffled would read as the list
        // having lost its order.
        $this->assertSame(
            ['tls one', 'tls two', 'tls three'],
            $this->filter(['tls one', 'tls two', 'tls three'], 'tls'),
        );
    }

    public function testNothingMatchingIsAnEmptyList(): void
    {
        $this->assertSame([], $this->filter(['tls handshake'], 'zzz'));
    }
}
