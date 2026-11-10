<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Mermaid\Diagrams;

use Pig\Ai\Utils\JsJson;
use Pig\CodingAgent\Mermaid\Canvas;
use Pig\CodingAgent\Mermaid\Graph;
use Pig\CodingAgent\Mermaid\Labels;
use Pig\CodingAgent\Mermaid\Layout;
use Pig\CodingAgent\Mermaid\Measure;
use Pig\CodingAgent\Mermaid\Statements;

/**
 * lovely-mermaid's `diagrams/pie.ts`: `pie` — proportions as a labelled bar list. A terminal has no
 * circle worth drawing; bars carry the same information in less space. Lenient: an unreadable
 * statement is dropped and recorded in `warnings`.
 *
 * Ported from lovely-mermaid 0.3.3 (Apache-2.0, see LICENSE in the parent directory).
 */
final class Pie implements Diagram
{
    /** Columns of the full-scale bar; eighth blocks refine below one cell. */
    private const int BAR_W = 20;

    private const array EIGHTHS = ['', '▏', '▎', '▍', '▌', '▋', '▊', '▉'];

    #[\Override]
    public function kind(): string
    {
        return 'pie';
    }

    #[\Override]
    public function headers(): array
    {
        return ['pie'];
    }

    #[\Override]
    public function render(string $src): ?array
    {
        $parsed = self::parsePie($src);

        if ($parsed === null) {
            return null;
        }

        ['title' => $title, 'slices' => $slices, 'showData' => $showData, 'warnings' => $warnings] = $parsed;

        $labelW = max(array_map(static fn (array $s): int => Measure::width($s['label']), $slices));
        $total = array_sum(array_column($slices, 'value'));
        $rows = array_map(static function (array $s) use ($total, $showData): array {
            $share = $total == 0 ? 0 : $s['value'] / $total;
            $pct = str_pad(Layout::jsRound($share * 100) . '%', 4, ' ', STR_PAD_LEFT);
            $data = $showData ? '  (' . JsJson::toString($s['value']) . ')' : '';

            return [...$s, 'share' => $share, 'suffix' => $pct . $data];
        }, $slices);

        $barX = $labelW + 2;
        $suffixX = $barX + self::BAR_W + 1;
        $width = $suffixX + max(array_map(static fn (array $r): int => Measure::width($r['suffix']), $rows));
        $top = $title === null ? 0 : 1;
        $canvas = new Canvas($width, $top + count($rows));

        if ($title !== null) {
            Canvas::drawText($canvas, $title, max(0, (int) floor(($width - Measure::width($title)) / 2)), 0, 'title');
        }

        foreach ($rows as $i => $r) {
            $y = $top + $i;
            Canvas::drawText($canvas, $r['label'], 0, $y, 'text');
            $eighths = Layout::jsRound($r['share'] * self::BAR_W * 8);
            $bar = str_repeat('█', intdiv($eighths, 8)) . self::EIGHTHS[$eighths % 8];

            // A nonzero slice always shows at least a sliver.
            if ($bar === '' && $r['value'] > 0) {
                $bar = '▏';
            }

            Canvas::drawText($canvas, $bar, $barX, $y, 'edge');
            // The unfilled remainder is a track, so every bar shows its full scale.
            Canvas::drawText($canvas, str_repeat('░', self::BAR_W - Measure::width($bar)), $barX + Measure::width($bar), $y, 'border');
            Canvas::drawText($canvas, $r['suffix'], $suffixX, $y, 'edgeLabel');
        }

        return ['canvas' => $canvas, 'warnings' => $warnings, 'classDefs' => []];
    }

    /** @return array{title: ?string, slices: list<array{label: string, value: int|float}>, showData: bool, warnings: list<string>}|null */
    private static function parsePie(string $src): ?array
    {
        $statements = Statements::statementsOf($src);

        if (Statements::headerKind($statements) !== 'pie') {
            return null;
        }

        // The header line may carry `showData` and an inline `title ...`.
        $head = Labels::words($statements[0]);
        $lower = array_map('mb_strtolower', $head);
        $showData = in_array('showdata', $lower, true);
        $inlineTitle = array_search('title', $lower, true);
        $title = $inlineTitle === false ? null : Statements::nonEmpty(implode(' ', array_slice($head, $inlineTitle + 1)));

        $slices = [];
        $warnings = [];
        $truncated = false;

        foreach (array_slice($statements, 1) as $st) {
            $first = Labels::words($st)[0] ?? null;

            if ($first !== null && mb_strtolower($first) === 'title') {
                $title = Statements::nonEmpty(Labels::trim(substr($st, (int) stripos($st, 'title') + 5)));

                continue;
            }

            $slice = self::parseSlice($st);

            if ($slice === null) {
                $warnings[] = "dropped, unreadable statement: \"{$st}\"";

                continue;
            }

            if (count($slices) >= Graph::MAX_NODES) {
                $truncated = true;

                break;
            }

            $slices[] = $slice;
        }

        if ($truncated) {
            $warnings[] = 'diagram truncated: slice cap (' . Graph::MAX_NODES . ') reached';
        }

        return $slices === [] ? null : ['title' => $title, 'slices' => $slices, 'showData' => $showData, 'warnings' => $warnings];
    }

    /**
     * `"Label" : 42.5` — the label may be unquoted as long as it has no colon.
     *
     * @return array{label: string, value: int|float}|null
     */
    private static function parseSlice(string $st): ?array
    {
        $chars = mb_str_split($st, 1, 'UTF-8');
        $quoted = Statements::quoteMask($chars);
        $colon = -1;

        foreach ($chars as $i => $c) {
            if ($c === ':' && !$quoted[$i]) {
                $colon = $i;

                break;
            }
        }

        if ($colon === -1) {
            return null;
        }

        $label = Labels::fitLabel(Labels::cleanLabel(implode('', array_slice($chars, 0, $colon))), 24);
        $value = self::jsNumber(implode('', array_slice($chars, $colon + 1)));

        if ($label === '' || $value === null || !is_finite((float) $value) || $value < 0) {
            return null;
        }

        return ['label' => $label, 'value' => $value];
    }

    /**
     * JavaScript's `Number(text)`, or null where it gives NaN: surrounding white space ignored,
     * blank as 0, a decimal with an optional sign, exponent and leading or trailing dot,
     * `Infinity`, and the unsigned `0x`/`0o`/`0b` forms. An int when it is a plain whole number.
     */
    private static function jsNumber(string $text): int|float|null
    {
        $text = JsJson::trim($text);

        if ($text === '') {
            return 0;
        }

        if (preg_match('/^([+-]?)Infinity$/', $text, $match) === 1) {
            return $match[1] === '-' ? -INF : INF;
        }

        if (preg_match('/^0(?:x([0-9a-f]+)|o([0-7]+)|b([01]+))$/i', $text, $match) === 1) {
            [$digits, $base] = match (true) {
                ($match[1] ?? '') !== '' => [$match[1], 16],
                ($match[2] ?? '') !== '' => [$match[2], 8],
                default => [$match[3], 2],
            };
            $number = 0.0;

            foreach (str_split(strtolower($digits)) as $digit) {
                $number = $number * $base + (float) hexdec($digit);
            }

            return $number < PHP_INT_MAX ? (int) $number : $number;
        }

        if (preg_match('/^[+-]?(\d+\.?\d*|\.\d+)(e[+-]?\d+)?$/i', $text) !== 1) {
            return null;
        }

        return preg_match('/^[+-]?\d+$/', $text) === 1 && abs((float) $text) < PHP_INT_MAX ? (int) $text : (float) $text;
    }
}
