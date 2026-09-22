<?php

declare(strict_types=1);

namespace Pig\Ai\Utils;

/**
 * Makes text safe to put in a JSON request body.
 *
 * Upstream's `sanitizeSurrogates` strips unpaired UTF-16 surrogates, which is the shape
 * this problem takes in JavaScript. PHP strings are UTF-8 bytes and have no surrogate
 * pairs at all; here the same failure arrives as malformed UTF-8 — a truncated multi-byte
 * sequence, or a surrogate code point encoded as UTF-8, which is illegal. Either one makes
 * json_encode() fail outright and the request never leaves.
 */
final class Utf8
{
    /** Drop malformed bytes, leaving valid text — emoji and other astral characters included. */
    public static function sanitize(string $text): string
    {
        if (mb_check_encoding($text, 'UTF-8')) {
            return $text;
        }

        // mb_scrub substitutes rather than deletes, and what it substitutes is a global
        // setting. Upstream deletes, and a '?' appearing inside a model's own words is
        // worse than a gap, so switch the setting for the length of the call.
        $substitute = mb_substitute_character();
        mb_substitute_character('none');

        try {
            return mb_scrub($text, 'UTF-8');
        } finally {
            mb_substitute_character($substitute);
        }
    }
}
