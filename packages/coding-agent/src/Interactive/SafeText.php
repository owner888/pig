<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Interactive;

use Pig\Ai\Utils\Utf8;
use Pig\Tui\Ansi;

/**
 * Text that came from outside, made safe to put in a frame.
 *
 * Three things in the transcript are not pig's own words: a tool's output, a diff — which is
 * built from a *file's* bytes — and a message a hook sent, which is usually a build log it
 * just captured. Each of the three could take the session down or corrupt the screen, each
 * in the same two ways, and each was handled differently: the tool view sanitised UTF-8, the
 * diff gained it a batch later, and a hook's message had neither. One rule, one place.
 *
 * What it does, and why each step is not tidiness:
 *
 * - **Escape sequences out.** A command that prints its own colours would otherwise paint
 *   over the component's, including the background that says whether it succeeded. A file
 *   with a captured log committed into it does the same through the diff.
 * - **Bytes that are not UTF-8 out.** Everything that measures a line goes through
 *   `Graphemes::split()`, which is `preg_match_all('/\X/u')` and answers **false** on
 *   malformed UTF-8 — so one stray byte threw out of `render()`, inside the loop's own input
 *   callback, and took the session with it. `cat` on a binary file reaches this, so does an
 *   `edit` to a latin-1 file, so does a hook that sends a build log.
 * - **Every other control character out.** This is the `Tui::checkWidth()` failure arriving
 *   by the one route that check cannot see: `\p{Cc}` is **zero columns wide**, so a line
 *   carrying a form feed measures exactly right, passes, and is written to a terminal that
 *   then drops a row — putting every later cursor move one row low, which is the silent
 *   screen corruption the check exists to make loud. A backspace leaves the padding
 *   measuring a column that is no longer there. A bell is worse in its own way: the
 *   transcript is redrawn as the conversation grows, so one `\x07` in a build log beeps
 *   again on every redraw.
 *
 * Upstream's `sanitizeBinaryOutput` is the last two steps, in `tool-execution.ts` only. Two
 * deliberate differences from it. `\r` goes here and stays there — a bare CR returns the
 * cursor to column 0 and the rest of the line overwrites what was drawn, which is the same
 * fault as the others, and upstream gets away with it only because its renderer is not
 * differential. And U+FFF9–FFFB stay, though upstream strips them: that is a crash in
 * `string-width`, where `Width` reads them as `\p{Cf}` and measures them at nothing, which
 * is what they are — the same reason `version_compare()` replaced upstream's arithmetic
 * rather than being ported as it stood.
 */
final class SafeText
{
    /**
     * The control characters a terminal acts on, which is all of them but tab and newline.
     *
     * Byte-oriented on purpose: every character in the class is below 0x80, so it cannot
     * appear inside a multi-byte sequence, and a pattern with no `/u` has no way to fail on
     * the input this class exists to handle.
     */
    private const string ACTED_ON = '/[\x00-\x08\x0b-\x1f]/';

    public static function of(string $text): string
    {
        return (string) preg_replace(self::ACTED_ON, '', Ansi::strip(Utf8::sanitize($text)));
    }
}
