<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test\Mermaid;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pig\CodingAgent\Mermaid\Measure;
use Pig\CodingAgent\Mermaid\Mermaid;
use Pig\CodingAgent\Mermaid\SourceBox;
use Pig\CodingAgent\Mermaid\Span;

/**
 * The PHP port of lovely-mermaid against the real one.
 *
 * `fixtures/mermaid/cases.json` is upstream's golden files (`lovely-mermaid`'s `test/cases/**.md`,
 * v0.3.3): every ```mermaid block with the rows and warnings lovely-mermaid 0.3.3 draws for it, or
 * null where it refuses. pig's has to be the same to the character.
 *
 * `corpus.json` is upstream's fuzz corpus (`test/corpus.ts`): the hand-written sources plus the
 * headers and tokens its seeded generator strings together. Every source, and every prefix of
 * every golden source as it would stream in, must render without an error, each styled row
 * rebuilding its plain one and the width the widest row's.
 *
 * `pig-cases.json` is the kinds pig draws and lovely-mermaid does not (`journey`, `kanban`), each
 * pinned to what pig draws. `source-boxes.json` is source boxes at 40 columns as upstream frames
 * them.
 */
final class MermaidTest extends TestCase
{
    /** Upstream's folders of golden files, by the kind each holds. */
    private const array KINDS = [
        'class' => 'class', 'er' => 'er', 'flowchart' => 'flowchart', 'gitgraph' => 'gitgraph',
        'mindmap' => 'mindmap', 'pie' => 'pie', 'sequence' => 'sequence', 'state' => 'state',
        'timeline' => 'timeline',
    ];

    /** @return list<array<string, mixed>> */
    private static function fixture(string $name): array
    {
        return json_decode((string) file_get_contents(__DIR__ . "/../fixtures/mermaid/{$name}"), true);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function cases(): iterable
    {
        foreach (self::fixture('cases.json') as $i => $case) {
            yield "#{$i} {$case['file']}: " . strtok($case['src'], "\n") => [$case];
        }
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('cases')]
    public function testPigDrawsWhatUpstreamDraws(array $case): void
    {
        $folder = explode('/', $case['file'])[0];

        if (isset(self::KINDS[$folder]) && $case['plain'] !== null) {
            $this->assertSame(self::KINDS[$folder], Mermaid::kind($case['src']));
        }

        $art = Mermaid::render($case['src']);

        if ($case['plain'] === null) {
            $this->assertNull($art);

            return;
        }

        $this->assertNotNull($art);
        $this->assertSame($case['plain'], $art->plain);
        $this->assertSame($case['warnings'], $art->warnings);
        $this->assertInvariants($art->plain, $art->styled, $art->width, $case['src']);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function pigCases(): iterable
    {
        foreach (self::fixture('pig-cases.json') as $i => $case) {
            yield "#{$i} " . strtok($case['src'], "\n") => [$case];
        }
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('pigCases')]
    public function testPigDrawsItsOwnKinds(array $case): void
    {
        $this->assertSame($case['kind'], Mermaid::kind($case['src']));

        $art = Mermaid::render($case['src']);

        if ($case['art'] === null) {
            $this->assertNull($art);

            return;
        }

        $this->assertNotNull($art);
        $this->assertSame($case['art']['plain'], $art->plain);
        $this->assertSame($case['art']['styled'], array_map(
            static fn (array $row): array => array_map(static fn (Span $span): array => [$span->text, $span->role], $row),
            $art->styled,
        ));
        $this->assertSame($case['art']['width'], $art->width);
        $this->assertSame($case['art']['warnings'], $art->warnings);
    }

    public function testSourceBoxesFrameAsUpstreamFramesThem(): void
    {
        foreach (self::fixture('source-boxes.json') as $case) {
            $box = SourceBox::render($case['src'], 40);
            $this->assertSame($case['plain'], $box->plain, $case['src']);
            $this->assertSame($case['width'], $box->width, $case['src']);
            $this->assertInvariants($box->plain, $box->styled, $box->width, $case['src']);
        }
    }

    public function testEveryCorpusSourceRendersUnderTheInvariants(): void
    {
        $corpus = self::fixture('corpus.json');
        $drawn = 0;

        foreach (self::corpusSources($corpus) as $src) {
            $art = Mermaid::render($src);

            foreach ([1, 20, 80] as $w) {
                $this->assertNotSame([], SourceBox::render($src, $w)->plain);
            }

            if ($art !== null) {
                $drawn++;
                $this->assertInvariants($art->plain, $art->styled, $art->width, $src);
            }
        }

        // The sweep is only worth something while most of it draws.
        $this->assertGreaterThan(2000, $drawn);
    }

    public function testEveryPrefixOfEveryGoldenSourceRendersUnderTheInvariants(): void
    {
        foreach (self::fixture('cases.json') as $case) {
            $chars = mb_str_split($case['src'], 1, 'UTF-8');

            for ($end = 1; $end <= count($chars); $end++) {
                $prefix = implode('', array_slice($chars, 0, $end));
                $art = Mermaid::render($prefix);

                if ($art !== null) {
                    $this->assertInvariants($art->plain, $art->styled, $art->width, $prefix);
                }
            }
        }
    }

    /**
     * Upstream's `corpusSources()`: the hand-written sources, then 4,000 strung together from
     * headers and tokens by its seeded generator — the same 4,000, as JavaScript computes them.
     *
     * @param array{hand: list<string>, headers: list<string>, tokens: list<string>} $corpus
     * @return list<string>
     */
    private static function corpusSources(array $corpus): array
    {
        $seed = 987654321;
        // `(seed * 1103515245 + 12345) & 0x7fffffff` in doubles, as JavaScript reckons it.
        $rnd = static function () use (&$seed): float {
            $seed = ((int) fmod($seed * 1103515245.0 + 12345.0, 4294967296.0)) & 0x7fffffff;

            return $seed / 0x7fffffff;
        };
        $pick = static fn (array $a): string => $a[(int) floor($rnd() * count($a))];
        $out = $corpus['hand'];

        for ($i = 0; $i < 4000; $i++) {
            $n = 1 + (int) floor($rnd() * 20);
            $src = $pick($corpus['headers']) . "\n";

            for ($k = 0; $k < $n; $k++) {
                $src .= $pick($corpus['tokens']) . ($rnd() < 0.3 ? "\n" : ' ');
            }

            $out[] = $src;
        }

        return $out;
    }

    /**
     * Each styled row rebuilds its plain row, and the width is the widest row's.
     *
     * @param list<string> $plain
     * @param list<list<Span>> $styled
     */
    private function assertInvariants(array $plain, array $styled, int $width, string $src): void
    {
        $this->assertCount(count($plain), $styled, $src);

        foreach ($plain as $i => $row) {
            $this->assertSame($row, implode('', array_map(static fn (Span $span): string => $span->text, $styled[$i])), $src);
        }

        $this->assertSame(max(0, ...array_map(Measure::width(...), $plain)), $width, $src);
    }
}
