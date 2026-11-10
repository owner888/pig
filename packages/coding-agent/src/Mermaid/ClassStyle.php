<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Mermaid;

/**
 * lovely-mermaid's `class-style.ts`: a best-effort reading of `classDef` styles for a cell grid. A
 * terminal cell can express a foreground, a background and boldness — nothing else. `fill` is the
 * node background, `stroke` its border, `color` its text; every other property is ignored.
 *
 * A style is an array with any of `fill`, `stroke`, `color` (each `#rrggbb`) and `bold`.
 *
 * Ported from lovely-mermaid 0.3.3 (Apache-2.0, see LICENSE in this directory).
 */
final class ClassStyle
{
    /** `#rgb`, `#rrggbb`, `rgb(r,g,b)` or a CSS colour name as `#rrggbb`; else null. */
    public static function normalizeColor(string $v): ?string
    {
        $s = mb_strtolower(Labels::trim($v));

        if (preg_match('/^#[0-9a-f]{6}$/D', $s) === 1) {
            return $s;
        }

        if (preg_match('/^#[0-9a-f]{3}$/D', $s) === 1) {
            return "#{$s[1]}{$s[1]}{$s[2]}{$s[2]}{$s[3]}{$s[3]}";
        }

        if (preg_match('/^rgba?\(' . Labels::SPACE . '*(\d+)' . Labels::SPACE . '*,' . Labels::SPACE . '*(\d+)' . Labels::SPACE . '*,' . Labels::SPACE . '*(\d+)/u', $s, $rgb) === 1) {
            $hex = static fn (string $n): string => str_pad(dechex((int) min(255, (float) $n)), 2, '0', STR_PAD_LEFT);

            return '#' . $hex($rgb[1]) . $hex($rgb[2]) . $hex($rgb[3]);
        }

        return CssColors::NAMED_COLORS[$s] ?? null;
    }

    /**
     * The merged style of a span's classes (later classes win), or null when nothing a terminal
     * can show was declared.
     *
     * @param list<string>|null $classes
     * @param array<string, array<string, string>> $classDefs
     * @return array{fill?: string, stroke?: string, color?: string, bold?: bool}|null
     */
    public static function resolve(?array $classes, array $classDefs): ?array
    {
        if ($classes === null) {
            return null;
        }

        $out = [];

        foreach ($classes as $name) {
            $props = $classDefs[$name] ?? null;

            if ($props === null) {
                continue;
            }

            foreach ($props as $k => $v) {
                $k = (string) $k;

                if ($k === 'fill' || $k === 'stroke' || $k === 'color') {
                    $c = self::normalizeColor($v);

                    if ($c !== null) {
                        $out[$k] = $c;
                    }
                } elseif ($k === 'font-weight') {
                    $out['bold'] = Labels::trim($v) === 'bold' || Labels::trim($v) === 'bolder';
                }
            }
        }

        return $out === [] ? null : $out;
    }

    /**
     * Black or white, whichever reads on the given `#rrggbb` background — what keeps `fill:#eee`
     * legible on a dark terminal theme.
     */
    public static function contrastOn(string $fill): string
    {
        $ch = static fn (int $i): int => (int) hexdec(substr($fill, $i, 2));
        $yiq = ($ch(1) * 299 + $ch(3) * 587 + $ch(5) * 114) / 1000;

        return $yiq >= 128 ? '#000000' : '#ffffff';
    }
}
