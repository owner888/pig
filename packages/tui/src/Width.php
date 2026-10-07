<?php

declare(strict_types=1);

namespace Pig\Tui;

use Closure;

/**
 * How many columns a string occupies once the terminal has drawn it.
 *
 * The renderer compares this against the terminal's width on every line it emits, so a
 * wrong answer here is not a cosmetic bug: one column too many and the terminal wraps a
 * line the differential renderer believes is one line, and every cursor move after that
 * lands in the wrong place.
 *
 * Escape codes take no columns, CJK and emoji take two, and combining marks take none.
 */
final class Width
{
    /** Codepoints whose own width is zero wherever they appear. */
    private const string ZERO = '\p{Cf}\p{Cc}\p{M}\p{Cs}\x{200D}';

    /** Halfwidth and Fullwidth Forms, which add their own width after the base. */
    private const int FULLWIDTH_FROM = 0xFF00;
    private const int FULLWIDTH_TO = 0xFFEF;

    private const int REGIONAL_FROM = 0x1F1E6;
    private const int REGIONAL_TO = 0x1F1FF;

    private const int CACHE_SIZE = 2048;

    /** @var array<string, int> */
    private static array $cache = [];

    /** The visible width of a string, escape codes and all. */
    public static function visible(string $text): int
    {
        if ($text === '') {
            return 0;
        }

        // Almost every line is printable ASCII, where one byte is one column.
        //
        // `\z` and not `$`: PCRE's `$` matches *before* a trailing newline, so `"abc\n"` took this
        // path and got `strlen()` — four columns for three. The slow path below disagreed, because
        // a newline is `\p{Cc}` and therefore zero, so one string measured differently depending on
        // whether anything followed the newline. Anything that pads a line to a width was then a
        // space short of it.
        if (preg_match('/^[\x20-\x7e]*\z/', $text) === 1) {
            return strlen($text);
        }

        if (isset(self::$cache[$text])) {
            return self::$cache[$text];
        }

        $clean = str_contains($text, "\t") ? str_replace("\t", '   ', $text) : $text;
        $clean = Ansi::strip($clean);

        // A **styled** line of printable ASCII is the other common case — every line of a
        // transcript carries a colour — and it takes this path with the codes gone, as upstream's
        // `asciiVisibleWidth` does. Without it, every line the renderer checks before writing went
        // through grapheme segmentation: on a 12,800-line frame that was 236ms of a 560ms resize,
        // spent measuring lines whose every character is one column wide. The cache above cannot
        // help, because a frame at a new width is 12,800 strings it has never seen.
        if (preg_match('/^[\x20-\x7e]*\z/', $clean) === 1) {
            return strlen($clean);
        }

        $width = 0;

        foreach (Graphemes::split($clean) as $grapheme) {
            $width += self::grapheme($grapheme);
        }

        if (count(self::$cache) >= self::CACHE_SIZE) {
            unset(self::$cache[array_key_first(self::$cache)]);
        }

        self::$cache[$text] = $width;

        return $width;
    }

    /**
     * The width of one grapheme cluster.
     *
     * Upstream asks whether the cluster matches `\p{RGI_Emoji}`, a JavaScript regex property
     * that matches whole emoji sequences. PCRE has no sequence properties, so the question is
     * asked of the cluster's first codepoint instead: a pictograph drawn in emoji presentation
     * is two columns wide however many codepoints follow it.
     */
    private static function grapheme(string $grapheme): int
    {
        if ($grapheme === '') {
            return 0;
        }

        if (preg_match('/^[' . self::ZERO . ']+$/u', $grapheme) === 1) {
            return 0;
        }

        $first = Graphemes::firstCodepoint($grapheme);

        if ($first === null) {
            return 0;
        }

        // A flag is two regional indicators; one on its own is a lone letter.
        if ($first >= self::REGIONAL_FROM && $first <= self::REGIONAL_TO) {
            return mb_strlen($grapheme, 'UTF-8') > 1 ? 2 : 1;
        }

        if (self::isEmojiPresentation($grapheme, $first)) {
            return 2;
        }

        $base = preg_replace('/^[' . self::ZERO . ']+/u', '', $grapheme);

        if ($base === null) {
            throw new TuiError('Stripping non-printing codepoints failed: ' . preg_last_error_msg());
        }

        if ($base === '') {
            return 0;
        }

        $codepoints = mb_str_split($base, 1, 'UTF-8');
        $width = mb_strwidth($codepoints[0], 'UTF-8');

        // A halfwidth or fullwidth form trailing the base adds its own columns.
        foreach (array_slice($codepoints, 1) as $codepoint) {
            $value = mb_ord($codepoint, 'UTF-8');

            if ($value >= self::FULLWIDTH_FROM && $value <= self::FULLWIDTH_TO) {
                $width += mb_strwidth($codepoint, 'UTF-8');
            }
        }

        return $width;
    }

    /**
     * Whether the cluster is drawn as an emoji rather than as text.
     *
     * `⚠` is one column and `⚠️` — the same codepoint plus U+FE0F — is two, so the
     * variation selector has to be looked for even though it is itself zero width.
     */
    private static function isEmojiPresentation(string $grapheme, int $first): bool
    {
        $char = mb_chr($first, 'UTF-8');

        if ($char === false || preg_match('/^\p{Extended_Pictographic}$/u', $char) !== 1) {
            return false;
        }

        return preg_match('/^\p{Emoji_Presentation}$/u', $char) === 1
            || str_contains($grapheme, "\u{FE0F}");
    }

    /**
     * Cut to at most $maxWidth columns, ending in $ellipsis when anything was dropped.
     *
     * The reset before the ellipsis is deliberate: the cut can land inside a coloured run,
     * and without it the "..." inherits a colour that the text it replaced was wearing.
     */
    public static function truncate(string $text, int $maxWidth, string $ellipsis = '...'): string
    {
        if (self::visible($text) <= $maxWidth) {
            return $text;
        }

        $target = $maxWidth - self::visible($ellipsis);

        if ($target <= 0) {
            return substr($ellipsis, 0, max(0, $maxWidth));
        }

        $result = '';
        $width = 0;

        foreach (Ansi::segment($text) as [$isCode, $value]) {
            if ($isCode) {
                $result .= $value;

                continue;
            }

            $graphemeWidth = self::visible($value);

            if ($width + $graphemeWidth > $target) {
                break;
            }

            $result .= $value;
            $width += $graphemeWidth;
        }

        return $result . "\x1b[0m" . $ellipsis;
    }

    /**
     * Spaces after $text until it is $width columns wide.
     *
     * **`str_pad()` counts bytes**, so a name in an alphabet that spends more than one byte per
     * character is padded short and whatever follows it starts early: 代码審查 is 12 bytes and 8
     * columns, so `str_pad($name, 24)` leaves 20 columns of it and the next column lands four to
     * the left of every other row's. A name with a combining mark or an emoji in it is off by its
     * own amount. That is the same correction `SelectList` and `SettingsList` already make to
     * upstream's `.length` for their label columns; this is it in one place.
     *
     * Nothing is cut, exactly as `str_pad()` cuts nothing — text already wider than $width comes
     * back whole. A caller that needs it to fit asks `truncate()` first, which is what the two
     * lists do.
     */
    public static function pad(string $text, int $width): string
    {
        return $text . str_repeat(' ', max(0, $width - self::visible($text)));
    }

    /**
     * Pad to $width and run the result through $background.
     *
     * The padding goes inside the background, which is the whole point: a background that
     * stops where the text stops leaves a ragged right edge.
     *
     * @param Closure(string): string $background
     */
    public static function background(string $line, int $width, Closure $background): string
    {
        return $background(self::pad($line, $width));
    }

    /** @internal Tests measure the same strings repeatedly; the cache would hide a change. */
    public static function clearCache(): void
    {
        self::$cache = [];
    }

    /**
     * Extract a range of visible columns from an ANSI line — upstream's `sliceByColumn()`.
     *
     * `$strict` drops a wide grapheme that would straddle the end of the range instead of
     * letting it spill one column past it; anything composited next to the slice needs that.
     */
    public static function sliceByColumn(string $line, int $startCol, ?int $length = null, bool $strict = false): string
    {
        if ($length !== null && $length <= 0) {
            return '';
        }

        $endCol = $length === null ? PHP_INT_MAX : $startCol + $length;
        $result = '';
        $currentCol = 0;
        $pendingAnsi = '';

        foreach (Ansi::segment($line) as [$isCode, $value]) {
            if ($isCode) {
                if ($currentCol >= $startCol && $currentCol < $endCol) {
                    $result .= $pendingAnsi . $value;
                    $pendingAnsi = '';
                } elseif ($currentCol < $startCol) {
                    $pendingAnsi .= $value;
                }
                continue;
            }

            foreach (Graphemes::split($value) as $segment) {
                $w = self::grapheme($segment);
                $inRange = $currentCol >= $startCol && $currentCol < $endCol;
                $fits = !$strict || $currentCol + $w <= $endCol;
                if ($inRange && $fits) {
                    if ($pendingAnsi !== '') {
                        $result .= $pendingAnsi;
                        $pendingAnsi = '';
                    }
                    $result .= $segment;
                }
                $currentCol += $w;
                if ($currentCol >= $endCol) {
                    break;
                }
            }

            if ($currentCol >= $endCol) {
                break;
            }
        }

        return $result;
    }

    /**
     * The "before" and "after" parts of a line around an overlay, in one pass — upstream's
     * `extractSegments()`. A grapheme that would cross `$beforeEnd` is left out of "before", and
     * "after" opens with whatever styling was active where it starts.
     *
     * @return array{before: string, beforeWidth: int, after: string, afterWidth: int}
     */
    public static function extractSegments(string $line, int $beforeEnd, int $afterStart, int $afterLen, bool $strictAfter = false): array
    {
        $before = '';
        $beforeWidth = 0;
        $after = '';
        $afterWidth = 0;
        $currentCol = 0;
        $pendingAnsiBefore = '';
        $afterStarted = false;
        $afterEnd = $afterStart + $afterLen;
        $tracker = new AnsiTracker();
        $done = static fn (int $col): bool => $afterLen <= 0 ? $col >= $beforeEnd : $col >= $afterEnd;

        foreach (Ansi::segment($line) as [$isCode, $value]) {
            if ($isCode) {
                $tracker->process($value);
                if ($currentCol < $beforeEnd) {
                    $pendingAnsiBefore .= $value;
                } elseif ($currentCol >= $afterStart && $currentCol < $afterEnd && $afterStarted) {
                    $after .= $value;
                }
                continue;
            }

            foreach (Graphemes::split($value) as $segment) {
                $w = self::grapheme($segment);

                if ($currentCol < $beforeEnd && $currentCol + $w <= $beforeEnd) {
                    if ($pendingAnsiBefore !== '') {
                        $before .= $pendingAnsiBefore;
                        $pendingAnsiBefore = '';
                    }
                    $before .= $segment;
                    $beforeWidth += $w;
                } elseif ($currentCol >= $afterStart && $currentCol < $afterEnd) {
                    if (!$strictAfter || $currentCol + $w <= $afterEnd) {
                        if (!$afterStarted) {
                            $after .= $tracker->activeCodes();
                            $afterStarted = true;
                        }
                        $after .= $segment;
                        $afterWidth += $w;
                    }
                }

                $currentCol += $w;
                if ($done($currentCol)) {
                    break;
                }
            }

            if ($done($currentCol)) {
                break;
            }
        }

        return ['before' => $before, 'beforeWidth' => $beforeWidth, 'after' => $after, 'afterWidth' => $afterWidth];
    }

    /**
     * The terminal-cell range occupied by the grapheme at a visible column — upstream's
     * `getGraphemeCellRange()`. Null when the column is past the end of the line.
     *
     * @return array{start: int, end: int}|null
     */
    public static function graphemeCellRange(string $line, int $column): ?array
    {
        $currentCol = 0;

        foreach (Ansi::segment($line) as [$isCode, $value]) {
            if ($isCode) {
                continue;
            }

            foreach (Graphemes::split($value) as $segment) {
                $w = self::grapheme($segment);
                if ($w > 0 && $column >= $currentCol && $column < $currentCol + $w) {
                    return ['start' => $currentCol, 'end' => $currentCol + $w];
                }
                $currentCol += $w;
            }
        }

        return null;
    }

    /** The background colour active at the end of `$text`, as one sequence — upstream's `getActiveBackgroundAnsi()`. */
    public static function activeBackgroundAnsi(string $text): string
    {
        $tracker = new AnsiTracker();
        $tracker->processText($text);

        return $tracker->activeBackgroundCode();
    }

    /** Closes an overlay's styling and any hyperlink it opened — upstream's `SEGMENT_RESET`. */
    private const string SEGMENT_RESET = "\x1b[0m\x1b]8;;\x07";

    /**
     * Composite an overlay string into a line at a specific column — upstream's
     * `compositeTuiLine()`. The result is never wider than `$totalWidth`: a wide grapheme that
     * straddles either edge of the overlay is dropped and the gap padded, rather than kept and
     * pushing the line a column over (which `TuiBase::checkWidth()` turns into a crash).
     */
    public static function composite(
        string $baseLine,
        string $overlay,
        int $startCol,
        int $overlayWidth,
        int $totalWidth,
    ): string {
        $afterStart = $startCol + $overlayWidth;
        $base = self::extractSegments($baseLine, $startCol, $afterStart, $totalWidth - $afterStart, true);
        $overlayText = self::sliceByColumn($overlay, 0, $overlayWidth, true);
        $overlayTextWidth = self::visible($overlayText);
        $beforePad = max(0, $startCol - $base['beforeWidth']);
        $overlayPad = max(0, $overlayWidth - $overlayTextWidth);
        $actualBeforeWidth = max($startCol, $base['beforeWidth']);
        $actualOverlayWidth = max($overlayWidth, $overlayTextWidth);
        $afterTarget = max(0, $totalWidth - $actualBeforeWidth - $actualOverlayWidth);
        $afterPad = max(0, $afterTarget - $base['afterWidth']);
        $result = $base['before']
            . str_repeat(' ', $beforePad)
            . self::SEGMENT_RESET
            . $overlayText
            . str_repeat(' ', $overlayPad)
            . self::SEGMENT_RESET
            . $base['after']
            . str_repeat(' ', $afterPad);

        return self::visible($result) <= $totalWidth ? $result : self::sliceByColumn($result, 0, $totalWidth, true);
    }
}
