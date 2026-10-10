<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test\Mermaid;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pig\CodingAgent\Mermaid\Mermaid;
use Pig\CodingAgent\Mermaid\SourceBox;
use Pig\CodingAgent\Mermaid\Span;

/**
 * The PHP port of grok-mermaid against the real one.
 *
 * `fixtures/mermaid/cases.json` is every ```mermaid block in the hand-written cases of upstream's
 * repository (`lovely-mermaid`'s `test/cases/*.md`), each drawn by grok-mermaid 0.2.3 — the
 * package pi uses — under Node: the kind, the art (rows, spans, width, warnings) and the source
 * box at 40 columns. pig's has to be the same to the character.
 *
 * Upstream's fuzz corpus was run the same way while porting: every one of its 4,486 sources
 * matches too, but for the ones holding a tab, a soft hyphen, a keycap or a run of emoji, which
 * upstream measures with its own `unicode-width` table and pig with `Width::visible()` — the
 * measure the rest of the screen is drawn with (see `Mermaid\Measure`).
 */
final class MermaidTest extends TestCase
{
    /** @return iterable<string, array{array<string, mixed>}> */
    public static function cases(): iterable
    {
        $cases = json_decode((string) file_get_contents(__DIR__ . '/../fixtures/mermaid/cases.json'), true);

        foreach ($cases as $i => $case) {
            yield "#{$i} " . strtok($case['src'], "\n") => [$case];
        }
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('cases')]
    public function testPigDrawsWhatUpstreamDrew(array $case): void
    {
        $this->assertSame($case['kind'], Mermaid::kind($case['src']));

        $art = Mermaid::render($case['src']);

        if ($case['art'] === null) {
            $this->assertNull($art);
        } else {
            $this->assertNotNull($art);
            $this->assertSame($case['art']['plain'], $art->plain);
            $this->assertSame($case['art']['styled'], array_map(
                static fn (array $row): array => array_map(static fn (Span $span): array => [$span->text, $span->cls], $row),
                $art->styled,
            ));
            $this->assertSame($case['art']['width'], $art->width);
            $this->assertSame($case['art']['warnings'], $art->warnings);
        }

        $box = SourceBox::render($case['src'], 40);
        $this->assertSame($case['box']['plain'], $box->plain);
        $this->assertSame($case['box']['width'], $box->width);
    }
}
