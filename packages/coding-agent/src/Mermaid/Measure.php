<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Mermaid;

use Pig\Tui\Graphemes;
use Pig\Tui\Width;

/**
 * grok-mermaid's `width.ts`: display width measured in grapheme clusters, the unit both of
 * measuring and of painting, so a box is always sized for exactly what gets drawn into it.
 *
 * The clusters are `Graphemes::split()` (PCRE's `\X`, UAX #29) and a cluster's width is
 * `Width::visible()` — the measure the rest of pig's screen uses, where upstream carries its own
 * table from the `unicode-width` crate. Box-drawing and ASCII, which is nearly every diagram,
 * measure the same either way; a diagram is drawn to the width the terminal will give it.
 *
 * Ported from grok-mermaid 0.2.3 (Apache-2.0, see LICENSE in this directory).
 */
final class Measure
{
    /** @return list<string> */
    public static function clusters(string $text): array
    {
        return Graphemes::split($text);
    }

    /** @return list<array{0: string, 1: int}> each cluster with its width */
    public static function measured(string $text): array
    {
        return array_map(static fn (string $cluster): array => [$cluster, Width::visible($cluster)], Graphemes::split($text));
    }

    public static function width(string $text): int
    {
        return Width::visible($text);
    }
}
