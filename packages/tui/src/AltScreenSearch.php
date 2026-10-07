<?php

declare(strict_types=1);

namespace Pig\Tui;

/**
 * The module functions of upstream's `alt-screen-search.ts`: build a searchable corpus from
 * rendered lines and map matches back to rows and cells.
 *
 * Offsets into the corpus are bytes here where upstream's are UTF-16 units; both sides use
 * them only to map a match back onto the spans, so the unit does not show.
 *
 * @phpstan-type SearchSourceSpan array{textStart: int, textEnd: int, row: int, startCol: int, endCol: int, linearColumns: bool}
 * @phpstan-type SearchCorpus array{text: string, spans: list<SearchSourceSpan>}
 */
final class AltScreenSearch
{
    private const string PRINTABLE_ASCII = '/^[\x20-\x7e]*$/';

    /**
     * @param list<string> $lines
     *
     * @return SearchCorpus
     */
    public static function buildSearchCorpus(array $lines): array
    {
        $text = '';
        $spans = [];
        $pendingSeparator = false;

        foreach ($lines as $row => $source) {
            $line = Ansi::strip($source);
            $column = 0;

            // Rendered transcripts are overwhelmingly ASCII. Index complete non-space
            // runs at once instead of segmenting and allocating one mapping per cell.
            if (preg_match(self::PRINTABLE_ASCII, $line) === 1) {
                $index = 0;
                $length = strlen($line);
                while ($index < $length) {
                    if ($line[$index] === ' ') {
                        if ($text !== '') {
                            $pendingSeparator = true;
                        }
                        $column++;
                        $index++;
                        continue;
                    }
                    $end = $index + 1;
                    while ($end < $length && $line[$end] !== ' ') {
                        $end++;
                    }
                    if ($pendingSeparator) {
                        $text .= ' ';
                        $pendingSeparator = false;
                    }
                    $chunk = substr($line, $index, $end - $index);
                    $spans[] = [
                        'textStart' => strlen($text),
                        'textEnd' => strlen($text) + strlen($chunk),
                        'row' => $row,
                        'startCol' => $column,
                        'endCol' => $column + strlen($chunk),
                        'linearColumns' => true,
                    ];
                    $text .= $chunk;
                    $column += strlen($chunk);
                    $index = $end;
                }
            } else {
                foreach (Graphemes::split($line) as $grapheme) {
                    $width = Width::visible($grapheme);
                    if (preg_match('/^\s+$/u', $grapheme) === 1) {
                        if ($text !== '') {
                            $pendingSeparator = true;
                        }
                        $column += $width;
                        continue;
                    }
                    if ($pendingSeparator) {
                        $text .= ' ';
                        $pendingSeparator = false;
                    }
                    $spans[] = [
                        'textStart' => strlen($text),
                        'textEnd' => strlen($text) + strlen($grapheme),
                        'row' => $row,
                        'startCol' => $column,
                        'endCol' => $column + $width,
                        'linearColumns' => false,
                    ];
                    $text .= $grapheme;
                    $column += $width;
                }
            }
            if ($text !== '') {
                $pendingSeparator = true;
            }
        }

        return ['text' => $text, 'spans' => $spans];
    }

    public static function normalizeQuery(string $query): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $query));
    }

    /**
     * @param SearchCorpus $corpus
     *
     * @return list<AltScreenSearchMatch>
     */
    public static function findSearchCorpusMatches(array $corpus, string $normalizedQuery): array
    {
        if ($normalizedQuery === '') {
            return [];
        }
        $found = preg_match_all('/' . preg_quote($normalizedQuery, '/') . '/iu', $corpus['text'], $all, PREG_OFFSET_CAPTURE);
        if ($found === false) {
            throw new TuiError('Transcript search failed: ' . preg_last_error_msg());
        }

        $spans = $corpus['spans'];
        $spanCount = count($spans);
        $matches = [];
        $spanIndex = 0;
        foreach ($all[0] as [$matched, $start]) {
            $end = $start + strlen($matched);
            while ($spanIndex < $spanCount && $spans[$spanIndex]['textEnd'] <= $start) {
                $spanIndex++;
            }

            /** @var list<AltScreenSearchSegment> $segments */
            $segments = [];
            for ($index = $spanIndex; $index < $spanCount; $index++) {
                $span = $spans[$index];
                if ($span['textStart'] >= $end) {
                    break;
                }
                if ($span['textEnd'] <= $start) {
                    continue;
                }
                $startCol = $span['linearColumns']
                    ? $span['startCol'] + max($start, $span['textStart']) - $span['textStart']
                    : $span['startCol'];
                $endCol = $span['linearColumns'] ? $span['startCol'] + min($end, $span['textEnd']) - $span['textStart'] : $span['endCol'];
                $previous = $segments[count($segments) - 1] ?? null;
                if ($previous !== null && $previous->row === $span['row'] && $startCol <= $previous->endCol) {
                    $previous->endCol = max($previous->endCol, $endCol);
                } else {
                    $segments[] = new AltScreenSearchSegment($span['row'], $startCol, $endCol);
                }
            }
            while ($spanIndex < $spanCount && $spans[$spanIndex]['textEnd'] <= $end) {
                $spanIndex++;
            }
            if ($segments !== []) {
                $matches[] = new AltScreenSearchMatch($segments);
            }
        }

        return $matches;
    }

    /**
     * @param list<string> $lines
     *
     * @return list<AltScreenSearchMatch>
     */
    public static function findAltScreenSearchMatches(array $lines, string $query): array
    {
        $normalizedQuery = self::normalizeQuery($query);

        return $normalizedQuery !== '' ? self::findSearchCorpusMatches(self::buildSearchCorpus($lines), $normalizedQuery) : [];
    }

    public static function getAltScreenSearchMatchKey(AltScreenSearchMatch $match): string
    {
        $first = $match->segments[0] ?? null;
        $last = $match->segments[count($match->segments) - 1] ?? null;

        return $first !== null && $last !== null ? "{$first->row}:{$first->startCol}:{$last->row}:{$last->endCol}" : '';
    }
}
