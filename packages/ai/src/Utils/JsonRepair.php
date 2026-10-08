<?php

declare(strict_types=1);

namespace Pig\Ai\Utils;

use JsonException;

/**
 * Upstream's `repairJson()` and `parseJsonWithRepair()` (`utils/json-parse.ts`): a JSON text whose
 * string literals carry raw control characters or invalid escapes — which some providers stream —
 * is parsed after those are escaped, and only when the repair changed something.
 *
 * Byte by byte rather than by UTF-16 unit: every character the repair looks at (`"`, `\`, the
 * control characters, the escape letters) is ASCII, so the two walks agree on UTF-8 input.
 */
final class JsonRepair
{
    private const array VALID_JSON_ESCAPES = ['"', '\\', '/', 'b', 'f', 'n', 'r', 't', 'u'];

    /**
     * Upstream's `parseJsonWithRepair()`: `JSON.parse`, then the repaired text when it differs, else
     * the first error. Objects decode as arrays, as everywhere in pig's providers.
     *
     * @throws JsonException with V8's message (`JsJson`) — the repaired text's when the repair
     *         changed something, as upstream's second `JSON.parse` is the one that throws then
     */
    public static function parse(string $json): mixed
    {
        try {
            return JsJson::parse($json);
        } catch (JsonException $error) {
            $repaired = self::repair($json);

            if ($repaired !== $json) {
                return JsJson::parse($repaired);
            }

            throw $error;
        }
    }

    /** Upstream's `repairJson()`. */
    public static function repair(string $json): string
    {
        $repaired = '';
        $inString = false;
        $length = strlen($json);

        for ($index = 0; $index < $length; $index++) {
            $char = $json[$index];

            if (!$inString) {
                $repaired .= $char;

                if ($char === '"') {
                    $inString = true;
                }

                continue;
            }

            if ($char === '"') {
                $repaired .= $char;
                $inString = false;

                continue;
            }

            if ($char === '\\') {
                $nextChar = $json[$index + 1] ?? null;

                if ($nextChar === null) {
                    $repaired .= '\\\\';

                    continue;
                }

                if ($nextChar === 'u') {
                    $unicodeDigits = substr($json, $index + 2, 4);

                    if (preg_match('/^[0-9a-fA-F]{4}$/', $unicodeDigits) === 1) {
                        $repaired .= '\\u' . $unicodeDigits;
                        $index += 5;

                        continue;
                    }
                }

                if (in_array($nextChar, self::VALID_JSON_ESCAPES, true)) {
                    $repaired .= '\\' . $nextChar;
                    $index += 1;

                    continue;
                }

                $repaired .= '\\\\';

                continue;
            }

            $repaired .= ord($char) <= 0x1f ? self::escapeControlCharacter($char) : $char;
        }

        return $repaired;
    }

    private static function escapeControlCharacter(string $char): string
    {
        return match ($char) {
            "\x08" => '\\b',
            "\f" => '\\f',
            "\n" => '\\n',
            "\r" => '\\r',
            "\t" => '\\t',
            default => sprintf('\\u%04x', ord($char)),
        };
    }
}
