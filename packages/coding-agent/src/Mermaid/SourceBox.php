<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Mermaid;

/**
 * grok-mermaid's `sourceBox()`: the raw source in a titled frame, hard-wrapped to `maxWidth` —
 * what to show when `Mermaid::render()` has nothing, or something too wide. The body wraps to
 * `max(8, maxWidth - 4)` and the ` mermaid: <kind> ` title is never cut, so a long first token
 * sets a floor.
 *
 * Ported from grok-mermaid 0.2.3 (Apache-2.0, see LICENSE in this directory).
 */
final class SourceBox
{
    public static function render(string $src, ?int $maxWidth = null): MermaidArt
    {
        $src = Labels::stripControls($src);
        $header = Labels::words($src)[0] ?? 'diagram';
        $title = " mermaid: {$header} ";
        $limit = $maxWidth === null ? null : max(8, max(0, $maxWidth - 4));
        $body = [];
        $started = false;

        foreach (Labels::srcLines($src) as $line) {
            $line = Labels::trimEnd($line);

            if (!$started && $line === '') {
                continue;
            }

            $started = true;
            array_push($body, ...self::chunkLine($line, $limit));
        }

        $contentW = max(Measure::width($title), 0, ...array_map(Measure::width(...), $body));
        $inner = $contentW + 2;
        $rule = str_repeat('─', max(0, $inner - Measure::width($title)));
        $plain = ["╭{$title}{$rule}╮"];
        $styled = [[new Span('╭', 'border'), new Span($title, 'title'), new Span("{$rule}╮", 'border')]];

        foreach ($body as $line) {
            $pad = str_repeat(' ', max(0, $contentW - Measure::width($line)));
            $plain[] = "│ {$line}{$pad} │";
            $styled[] = [new Span('│ ', 'border'), new Span($line, 'text'), new Span("{$pad} │", 'border')];
        }

        $bottom = '╰' . str_repeat('─', $inner) . '╯';
        $plain[] = $bottom;
        $styled[] = [new Span($bottom, 'border')];

        return new MermaidArt($plain, $styled, $inner + 2);
    }

    /** @return list<string> the line hard-broken at `limit` columns, never through a wide glyph */
    private static function chunkLine(string $line, ?int $limit): array
    {
        if ($limit === null || Measure::width($line) <= $limit) {
            return [$line];
        }

        $out = [];
        $cur = '';
        $curW = 0;

        foreach (Measure::measured($line) as [$c, $cw]) {
            if ($curW + $cw > $limit && $cur !== '') {
                $out[] = $cur;
                $cur = '';
                $curW = 0;
            }

            $cur .= $c;
            $curW += $cw;
        }

        if ($cur !== '') {
            $out[] = $cur;
        }

        return $out;
    }
}
