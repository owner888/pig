<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Mermaid;

/**
 * lovely-mermaid's `ansi.ts`: art rendered to ANSI-coloured lines. A theme is an SGR parameter per
 * role — `'2'` for dim, `'36'` for cyan, `'38;5;244'` for a 256-colour index — and a role left
 * out is printed unstyled.
 *
 * Ported from lovely-mermaid 0.3.3 (Apache-2.0, see LICENSE in this directory).
 */
final class Ansi
{
    private const string ESC = "\x1b";

    private const string OSC8 = self::ESC . ']8;;';

    private const string ST = self::ESC . '\\';

    /** Dim frame, plain labels, cyan connectors. Readable on light and dark. */
    public const array DEFAULT_THEME = [
        'border' => '2',
        'edge' => '36',
        'edgeLabel' => '2;36',
        'title' => '1',
    ];

    private static function rgb(string $hex, int $sgr): string
    {
        return "{$sgr};2;" . implode(';', array_map(static fn (int $i): int => (int) hexdec(substr($hex, $i, 2)), [1, 3, 5]));
    }

    /**
     * The truecolor SGR a class style gives a span of the given role, or null when the style says
     * nothing about it (the theme's applies). `stroke` colours borders, `color` text; `fill` backs
     * every painted cell, with a black or white foreground picked for contrast when none was
     * declared. A style that colours nothing for this role keeps `fallback`, the theme's SGR, so
     * a bold-only class bolds the themed look instead of replacing it.
     *
     * @param array{fill?: string, stroke?: string, color?: string, bold?: bool} $st
     */
    public static function classSgr(array $st, string $role, ?string $fallback = null): ?string
    {
        $p = [];
        $fg = $role === 'border' ? ($st['stroke'] ?? $st['color'] ?? null) : ($role === 'edge' ? null : ($st['color'] ?? null));
        $backed = $fg ?? (isset($st['fill']) ? ClassStyle::contrastOn($st['fill']) : null);

        if ($backed !== null) {
            $p[] = self::rgb($backed, 38);
        }

        if (isset($st['fill'])) {
            $p[] = self::rgb($st['fill'], 48);
        }

        if ($p === [] && $fallback !== null) {
            $p[] = $fallback;
        }

        if (($st['bold'] ?? false) === true) {
            array_unshift($p, '1');
        }

        return $p !== [] ? implode(';', $p) : null;
    }

    /**
     * The art as ANSI-coloured lines. Spans that carry author classes are styled from
     * `art->classDefs` (best effort — see `ClassStyle::resolve()`), over the role theme;
     * everything else follows `theme`. A span with a link is wrapped in an OSC 8 hyperlink,
     * which terminals without support ignore.
     *
     * @param array<string, string> $theme
     * @return list<string>
     */
    public static function toAnsi(MermaidArt $art, array $theme = self::DEFAULT_THEME): array
    {
        return array_map(static fn (array $row): string => implode('', array_map(static function (Span $span) use ($art, $theme): string {
            $cls = ClassStyle::resolve($span->classes, $art->classDefs);
            $sgr = $cls !== null ? self::classSgr($cls, $span->role, $theme[$span->role] ?? null) : ($theme[$span->role] ?? null);
            $text = $sgr === null ? $span->text : self::ESC . "[{$sgr}m{$span->text}" . self::ESC . '[0m';

            return $span->href === null ? $text : self::OSC8 . $span->href . self::ST . $text . self::OSC8 . self::ST;
        }, $row)), $art->styled);
    }
}
