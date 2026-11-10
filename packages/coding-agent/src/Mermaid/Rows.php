<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Mermaid;

/**
 * Rows of classed text runs made into a `Canvas` — the shape of the diagrams pig draws as a
 * listing rather than as boxes and edges (`Journey`). A run is `[text, role]`,
 * the roles those of `Span`; a blank row is `[]`.
 *
 * pig's own, not part of the lovely-mermaid port.
 */
final class Rows
{
    private const int MAX_CANVAS_CELLS = 1 << 21;

    /**
     * @param list<list<array{0: string, 1: string}>> $rows
     */
    public static function canvas(array $rows): ?Canvas
    {
        $w = 0;

        foreach ($rows as $row) {
            $w = max($w, self::width($row));
        }

        $h = count($rows);

        if ($w === 0 || $h === 0 || $w * $h > self::MAX_CANVAS_CELLS) {
            return null;
        }

        $canvas = new Canvas($w, $h);

        foreach ($rows as $y => $row) {
            $x = 0;

            foreach ($row as [$text, $cls]) {
                Canvas::drawText($canvas, $text, $x, $y, $cls);
                $x += Measure::width($text);
            }
        }

        return $canvas;
    }

    /** @param list<array{0: string, 1: string}> $row */
    public static function width(array $row): int
    {
        $w = 0;

        foreach ($row as [$text]) {
            $w += Measure::width($text);
        }

        return $w;
    }

    /**
     * A title centred over `width` columns.
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function title(string $text, int $width): array
    {
        $text = Labels::fitLabel($text, max(1, $width));
        $pad = intdiv(max(0, $width - Measure::width($text)), 2);

        return $pad > 0 ? [[str_repeat(' ', $pad), 'none'], [$text, 'title']] : [[$text, 'title']];
    }

    /**
     * A section rule, `─ label ────`, filling `width` columns.
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function divider(string $label, int $width): array
    {
        $label = ' ' . Labels::fitLabel($label, max(1, $width - 4)) . ' ';
        $rule = str_repeat('─', max(1, $width - 1 - Measure::width($label)));

        return [['─', 'edge'], [$label, 'edgeLabel'], [$rule, 'edge']];
    }

    /** `n` spaces, as a run — none at all for zero. @return list<array{0: string, 1: string}> */
    public static function gap(int $n): array
    {
        return $n > 0 ? [[str_repeat(' ', $n), 'none']] : [];
    }

    /**
     * Statements of a block after its header, less the `accTitle` / `accDescr` accessibility
     * lines every Mermaid grammar allows.
     *
     * @param list<string> $statements
     * @return list<string>
     */
    public static function body(array $statements): array
    {
        $out = [];

        foreach (array_slice($statements, 1) as $st) {
            $first = Labels::asciiLower(Labels::words($st)[0] ?? '');

            if (str_starts_with($first, 'acctitle') || str_starts_with($first, 'accdescr')) {
                continue;
            }

            $out[] = $st;
        }

        return $out;
    }

    /** `title X` read off a statement, or null when it is not one. */
    public static function titleOf(string $st): ?string
    {
        if (preg_match('/^title(?:' . Labels::SPACE . '+(.*))?$/iu', $st, $m) !== 1) {
            return null;
        }

        return Labels::cleanLabel($m[1] ?? '');
    }

    /** `section X` read off a statement, or null when it is not one. */
    public static function sectionOf(string $st): ?string
    {
        if (preg_match('/^section(?:' . Labels::SPACE . '+(.*))?$/iu', $st, $m) !== 1) {
            return null;
        }

        return Labels::cleanLabel($m[1] ?? '');
    }
}
