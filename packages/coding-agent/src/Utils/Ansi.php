<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Utils;

/**
 * Escape sequences out of what a tool printed — upstream's `utils/ansi.ts`, which is chalk's
 * `strip-ansi` pattern plus the private-parameter CSI fix (#11377).
 *
 * Separate from `Pig\Tui\Ansi` as upstream's is separate from pi-tui's: that one strips what the
 * renderer itself writes and agrees with `Ansi::at()`, which `Width` and every column-slicing
 * function walk; widening it would make the stripped width and the walked width disagree. This
 * one cleans arbitrary program output, where any sequence a terminal knows can turn up.
 *
 * The patterns work on bytes rather than with `/u`: the C1 introducers U+009B and U+009C are
 * spelled as their UTF-8 bytes, so a read that is not valid UTF-8 yet (`Run` holds a split
 * character back separately) still matches instead of failing the whole regex.
 */
final class Ansi
{
    /** Upstream's `MAX_PENDING_ANSI_LENGTH`: the longest unfinished sequence held back while streaming. */
    public const int MAX_PENDING_ANSI_LENGTH = 256;

    /** BEL, ESC \ or U+009C. */
    private const string ST = '(?:\x07|\x1b\\\\|\xc2\x9c)';

    private const string OSC_START = '\x1b\]';

    /**
     * ESC or U+009B, optional intermediates, optional parameters (`;` and `:`). A private prefix
     * (`<`, `=`, `>`) only right after a real CSI introducer (ESC [ or U+009B), so `ESC[<35;59;1M`
     * goes whole, while after a bare ESC they stay final bytes and `ESC=` keeps the digits after it.
     */
    private const string CSI_START = '(?:(?:\x1b\[|\xc2\x9b)[<=>]|(?:\x1b|\xc2\x9b)[[\]()#;?]*)(?:[0-9]{1,4}(?:[;:][0-9]{0,4})*)?';

    private const string CSI_FINAL = '[0-9A-PR-TZcf-nq-uy=><~]';

    /** Upstream's `stripAnsi()`. */
    public static function strip(string $text): string
    {
        if (!str_contains($text, "\x1b") && !str_contains($text, "\u{9b}")) {
            return $text;
        }

        $stripped = preg_replace(
            '/(?:' . self::OSC_START . '[\s\S]*?' . self::ST . ')|' . self::CSI_START . self::CSI_FINAL . '/',
            '',
            $text,
        );

        if ($stripped === null) {
            throw new \RuntimeException('Stripping escape codes failed: ' . preg_last_error_msg());
        }

        return $stripped;
    }

    /**
     * Upstream's `splitIncompleteAnsiSuffix()`: the part safe to strip now, and an unfinished
     * sequence at the end to put in front of the next read — an OSC without its ST (a trailing
     * ESC may start ESC \), or a CSI without its final byte.
     *
     * @return array{0: string, 1: string} complete, pending
     */
    public static function splitIncompleteSuffix(string $text): array
    {
        if (!str_contains($text, "\x1b") && !str_contains($text, "\u{9b}")) {
            return [$text, ''];
        }

        $windowStart = max(0, strlen($text) - self::MAX_PENDING_ANSI_LENGTH);
        $unfinished = '/(?:' . self::OSC_START . '(?:[^\x07\x1b\xc2]|\xc2(?!\x9c)|\x1b(?!\\\\))*|' . self::CSI_START . ')\z/';
        $found = preg_match($unfinished, substr($text, $windowStart), $match, PREG_OFFSET_CAPTURE);

        if ($found === false) {
            throw new \RuntimeException('Finding an unfinished escape code failed: ' . preg_last_error_msg());
        }

        if ($found === 0) {
            return [$text, ''];
        }

        $splitAt = $windowStart + $match[0][1];

        return [substr($text, 0, $splitAt), substr($text, $splitAt)];
    }
}
