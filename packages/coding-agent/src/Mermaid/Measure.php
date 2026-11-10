<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Mermaid;

use Pig\Tui\Graphemes;
use Pig\Tui\Width;

/**
 * lovely-mermaid's `width.ts`: display width measured in grapheme clusters, the unit both of
 * measuring and of painting, so a box is always sized for exactly what gets drawn into it.
 *
 * The clusters are `Graphemes::split()` (PCRE's `\X`, UAX #29) and a cluster's width is
 * `Width::visible()` — the measure the rest of pig's screen uses, where upstream carries its own
 * table from the `unicode-width` crate. Box-drawing and ASCII, which is nearly every diagram,
 * measure the same either way; a diagram is drawn to the width the terminal will give it.
 *
 * Ported from lovely-mermaid 0.3.3 (Apache-2.0, see LICENSE in this directory).
 */
final class Measure
{
    /** @return list<string> */
    public static function clusters(string $text): array
    {
        return Graphemes::split($text);
    }

    /**
     * Each cluster with its width. A tab is the three spaces `Width::visible()` counts it as, so it
     * paints as wide as it measures; upstream measures and paints it as one space.
     *
     * @return list<array{0: string, 1: int}>
     */
    public static function measured(string $text): array
    {
        $out = [];

        foreach (Graphemes::split($text) as $cluster) {
            if ($cluster === "\t") {
                array_push($out, [' ', 1], [' ', 1], [' ', 1]);
            } else {
                $out[] = [$cluster, Width::visible($cluster)];
            }
        }

        return $out;
    }

    public static function width(string $text): int
    {
        return Width::visible($text);
    }
}
