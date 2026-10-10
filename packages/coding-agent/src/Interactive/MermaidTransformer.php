<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Interactive;

use Closure;
use Pig\CodingAgent\Mermaid\Mermaid;
use Pig\CodingAgent\Mermaid\MermaidArt;
use Pig\CodingAgent\Theme\Themes;

/**
 * Upstream's `components/mermaid.ts`: a Markdown transformer that draws each top-level ```mermaid
 * block as a Unicode diagram (`Mermaid\Mermaid`), each row a code span so Markdown keeps its
 * spacing, the rows joined by hard breaks.
 *
 * The block is left as written when the mode is `off`, while the message streams unless the mode
 * is `streaming`, when nothing can be drawn, and when the drawing is wider than the room there is.
 * A finished message whose source did not all parse gets the block and one warning line instead.
 *
 * **Top level is a fence at column 0.** Upstream finds the blocks with `marked`'s lexer, which pig's
 * does not keep the raw text of; a fence at column 0 is never inside a list item, a quote or an
 * indented code block, so it is a top-level one. A top-level fence indented one to three spaces is
 * left as written.
 */
final class MermaidTransformer
{
    /**
     * @param Closure(): string $mode `off`, `final` or `streaming`
     * @param Closure(): int $availableWidth the columns a message's text has, asked each time
     * @return Closure(string, array<string, mixed>): string
     */
    public static function create(Closure $mode, Closure $availableWidth): Closure
    {
        return static function (string $markdown, array $context) use ($mode, $availableWidth): string {
            $current = $mode();
            $streaming = (bool) ($context['isStreaming'] ?? false);

            // Upstream also skips `assistant-thinking`; pig hands thinking to no transformer.
            if ($current === 'off' || ($streaming && $current !== 'streaming')) {
                return $markdown;
            }

            return self::transform($markdown, $streaming, $availableWidth());
        };
    }

    /** Every top-level mermaid block drawn, the rest of the Markdown as it was. */
    public static function transform(string $markdown, bool $streaming, int $availableWidth): string
    {
        if (!str_contains($markdown, 'mermaid')) {
            return $markdown;
        }

        $lines = explode("\n", $markdown);
        $out = [];
        $count = count($lines);
        $i = 0;

        while ($i < $count) {
            $open = self::fence($lines[$i]);

            if ($open === null) {
                $out[] = $lines[$i];
                $i++;

                continue;
            }

            // The block runs to a closing fence of the same character, at least as long and with
            // nothing after it — or, unclosed, to the end of the message, as `marked` reads it.
            $start = $i;
            $end = $i + 1;

            while ($end < $count && preg_match('/^ {0,3}' . preg_quote($open['char'], '/') . '{' . $open['length'] . ',}[ \t]*$/', $lines[$end]) !== 1) {
                $end++;
            }

            $closed = $end < $count;
            $last = $closed ? $end : $count - 1;
            $raw = array_slice($lines, $start, $last - $start + 1);
            $i = $last + 1;

            if (!$open['mermaid']) {
                array_push($out, ...$raw);

                continue;
            }

            $body = implode("\n", array_slice($lines, $start + 1, ($closed ? $end : $count) - $start - 1));
            $out[] = self::block(implode("\n", $raw), $body, $streaming, $availableWidth);
        }

        return implode("\n", $out);
    }

    /** @return array{char: string, length: int, mermaid: bool}|null an opening fence at column 0 */
    private static function fence(string $line): ?array
    {
        if (preg_match('/^(`{3,}|~{3,})(.*)$/', $line, $match) !== 1) {
            return null;
        }

        $info = trim($match[2]);

        // CommonMark: a backtick fence's info string has no backtick in it.
        if ($match[1][0] === '`' && str_contains($info, '`')) {
            return null;
        }

        // Upstream's `isMermaid()`: the info string's first word, without case.
        $language = strtolower(preg_split('/\s+/', $info, 2)[0] ?? '');

        return ['char' => $match[1][0], 'length' => strlen($match[1]), 'mermaid' => $language === 'mermaid'];
    }

    private static function block(string $raw, string $source, bool $streaming, int $availableWidth): string
    {
        $art = Mermaid::render($source);

        if ($art === null || $art->width > $availableWidth) {
            return $raw;
        }

        if (!$streaming && $art->warnings !== []) {
            $suffix = count($art->warnings) > 1 ? ' (+' . (count($art->warnings) - 1) . ' more)' : '';
            $warning = Themes::theme()->fg('warning', "Mermaid diagram not rendered: {$art->warnings[0]}{$suffix}");

            return "{$raw}\n\n" . self::codeSpan($warning) . '  ';
        }

        return implode("  \n", array_map(self::codeSpan(...), self::themedLines($art)));
    }

    /** @return list<string> each row coloured by its spans' classes, as upstream's `styleSpan()` */
    private static function themedLines(MermaidArt $art): array
    {
        $theme = Themes::theme();

        return array_map(static fn (array $row): string => implode('', array_map(static fn ($span): string => match ($span->cls) {
            'border' => $theme->fg('borderMuted', $span->text),
            'text' => $theme->fg('text', $span->text),
            'edge' => $theme->fg('accent', $span->text),
            'edgeLabel' => $theme->fg('muted', $span->text),
            'title' => $theme->fg('accent', $theme->bold($span->text)),
            default => $span->text,
        }, $row)), $art->styled);
    }

    /**
     * Upstream's `codeSpan()`: a row as inline code, fenced by one backtick more than its longest
     * run, padded when it starts or ends with one, and a no-break space for a blank row — an empty
     * code span has no height.
     */
    private static function codeSpan(string $line): string
    {
        $content = $line !== '' ? $line : "\u{a0}";
        preg_match_all('/`+/', $content, $runs);
        $fence = str_repeat('`', max([0, ...array_map('strlen', $runs[0])]) + 1);
        $padding = str_starts_with($content, '`') || str_ends_with($content, '`') ? ' ' : '';

        return "{$fence}{$padding}{$content}{$padding}{$fence}";
    }
}
