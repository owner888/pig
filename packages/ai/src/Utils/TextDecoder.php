<?php

declare(strict_types=1);

namespace Pig\Ai\Utils;

/**
 * `new TextDecoder()` (UTF-8, `fatal: false`, `ignoreBOM: false`) fed with `decode(bytes, {stream:
 * true})`, as upstream's stream readers use it: a byte-order mark at the very start of the stream is
 * dropped, malformed bytes become U+FFFD, and a character cut between two reads waits for the rest.
 * `decode()` with no bytes is the final `decoder.decode()`, which flushes whatever is held as U+FFFD.
 */
final class TextDecoder
{
    private string $pending = '';

    private bool $bomSeen = false;

    public function decode(string $bytes = '', bool $stream = true): string
    {
        $bytes = $this->pending . $bytes;
        $this->pending = '';

        if (!$this->bomSeen) {
            if ($stream && strlen($bytes) < 3 && str_starts_with("\u{FEFF}", $bytes)) {
                // Too few bytes yet to tell a mark from text.
                $this->pending = $bytes;

                return '';
            }

            $this->bomSeen = $bytes !== '';

            if (str_starts_with($bytes, "\u{FEFF}")) {
                $bytes = substr($bytes, 3);
            }
        }

        if (!$stream) {
            $this->bomSeen = false;

            return JsJson::decodeUtf8($bytes);
        }

        $length = strlen($bytes);

        // A lead byte in the last three whose sequence runs past the end is held back.
        for ($back = 1; $back <= min(3, $length); $back++) {
            $byte = ord($bytes[$length - $back]);

            if ($byte < 0x80) {
                break;
            }

            if ($byte >= 0xC0) {
                $needs = $byte >= 0xF0 ? 4 : ($byte >= 0xE0 ? 3 : 2);

                if ($needs > $back) {
                    $this->pending = substr($bytes, $length - $back);

                    return JsJson::decodeUtf8(substr($bytes, 0, $length - $back));
                }

                break;
            }
        }

        return JsJson::decodeUtf8($bytes);
    }
}
