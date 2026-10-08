<?php

declare(strict_types=1);

namespace Pig\Ai\Test\Utils;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pig\Ai\Utils\ShortHash;

final class ShortHashTest extends TestCase
{
    /**
     * Each expected value is what upstream's `shortHash()` (`utils/hash.ts`) returns for the
     * same input under Node — so an id pig rebuilds for another provider is the id pi would
     * have built. The non-ASCII cases are the point: JavaScript hashes UTF-16 code units, and an
     * emoji is two of them.
     */
    #[DataProvider('upstreamValues')]
    public function testItGivesWhatUpstreamGives(string $input, string $expected): void
    {
        $this->assertSame($expected, ShortHash::of($input));
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function upstreamValues(): iterable
    {
        yield 'empty' => ['', 'k4n83c7h0j2b'];
        yield 'one letter' => ['a', 'm8735310ae7sx'];
        yield 'a responses pair' => ['call_abc|fc_1234567890', '16jluw21axyth2'];
        yield 'a reasoning id' => ['rs_xyz', '1igrxkqpju9g7'];
        yield 'chinese' => ['你好世界', 'tv7rq2jx1on'];
        yield 'an emoji, which is a surrogate pair' => ['emoji😀x', 'mxq7yw1q1mz3g'];
        yield 'long' => [str_repeat('z', 300), 'd21pq6a0vy02'];
    }
}
