<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Theme;

use InvalidArgumentException;
use Pig\CodingAgent\Schemas\ConfigSchemas;
use Pig\CodingAgent\Schemas\SchemaErrors;

/**
 * Theme JSON validation — upstream's `theme-schema.ts`, kept out of `Themes` as upstream keeps it
 * out of `theme.ts`.
 *
 * Upstream compiles `ThemeJsonSchema` with typebox and turns its errors into one message; here the
 * schema is pig's published `theme.schema.json` (`ConfigSchemas`) and the errors are `SchemaErrors`,
 * typebox's checker written out, so the message is upstream's to the word and in its order: a name
 * with `/` refused before anything else, missing `colors` tokens gathered into one sorted list, and
 * everything else as `  - <instance path>: <message>` under "Other errors" — at most eight of them,
 * a union's branch errors before its `must match a schema in anyOf`. Like upstream since #9880 the
 * schema is strict: a top-level key, a `colors` token or an `export` key it does not name is
 * refused (`schema is false` at the key, then `must not have additional properties`).
 *
 * Documents are decoded JSON as PHP arrays (`json_decode(…, true)`): a JSON object is an array
 * with string keys, or an empty one; a JSON list is a non-empty list.
 *
 * A validated document is upstream's `ValidatedThemeJson`:
 * `array{'$schema'?: string, name: string, appearance?: 'dark'|'light',
 *   vars?: array<string, string|int>, colors: array<string, string|int>,
 *   export?: array{pageBg?: string|int, cardBg?: string|int, infoBg?: string|int}}`.
 * A color value (upstream's `ThemeColorValue`) is a string — hex, OKLCH, OKHSL, a var reference,
 * or `""` for the terminal default — or a 256-color index 0-255.
 */
final class ThemeJson
{
    /** The `colors` tokens in schema order; `false` marks the optional ones. */
    public const array COLOR_TOKENS = [
        // Core UI (11 colors)
        'accent' => true,
        'border' => true,
        'borderAccent' => true,
        'borderMuted' => true,
        'success' => true,
        'error' => true,
        'warning' => true,
        'muted' => true,
        'dim' => true,
        'text' => true,
        'thinkingText' => true,
        // Scrollbar (2 optional colors)
        'scrollbarTrack' => false,
        'scrollbarThumb' => false,
        // Backgrounds & Content Text (11 required, 2 optional)
        'selectedBg' => true,
        'searchMatchBg' => false,
        'searchMatchText' => false,
        'userMessageBg' => true,
        'userMessageText' => true,
        'customMessageBg' => true,
        'customMessageText' => true,
        'customMessageLabel' => true,
        'toolPendingBg' => true,
        'toolSuccessBg' => true,
        'toolErrorBg' => true,
        'toolTitle' => true,
        'toolOutput' => true,
        // Markdown (10 colors)
        'mdHeading' => true,
        'mdLink' => true,
        'mdLinkUrl' => true,
        'mdCode' => true,
        'mdCodeBlock' => true,
        'mdCodeBlockBorder' => true,
        'mdQuote' => true,
        'mdQuoteBorder' => true,
        'mdHr' => true,
        'mdListBullet' => true,
        // Tool Diffs (3 colors)
        'toolDiffAdded' => true,
        'toolDiffRemoved' => true,
        'toolDiffContext' => true,
        // Syntax Highlighting (9 colors)
        'syntaxComment' => true,
        'syntaxKeyword' => true,
        'syntaxFunction' => true,
        'syntaxVariable' => true,
        'syntaxString' => true,
        'syntaxNumber' => true,
        'syntaxType' => true,
        'syntaxOperator' => true,
        'syntaxPunctuation' => true,
        // Thinking Level Borders (6 colors)
        'thinkingOff' => true,
        'thinkingMinimal' => true,
        'thinkingLow' => true,
        'thinkingMedium' => true,
        'thinkingHigh' => true,
        'thinkingXhigh' => true,
        'thinkingMax' => false,
        // Bash Mode (1 color)
        'bashMode' => true,
    ];

    /**
     * Validate one theme document, throwing a message that names the offending tokens.
     *
     * @return array<string, mixed> the document, upstream's `ValidatedThemeJson` (see the class docblock)
     * @throws InvalidArgumentException
     */
    public static function validateThemeJson(string $label, mixed $json): array
    {
        if (self::isObject($json) && is_string($json['name'] ?? null) && str_contains($json['name'], '/')) {
            throw new InvalidArgumentException(
                "Invalid theme name \"{$json['name']}\": theme names cannot contain \"/\" because it is reserved for automatic light/dark theme settings.",
            );
        }

        $errors = SchemaErrors::of(ConfigSchemas::load('theme'), $json, assoc: true);

        if ($errors !== []) {
            $missingColors = [];
            $otherErrors = [];

            foreach ($errors as $error) {
                if ($error['keyword'] === 'required' && $error['instancePath'] === '/colors') {
                    foreach ($error['params']['requiredProperties'] as $token) {
                        $missingColors[$token] = true;
                    }

                    continue;
                }

                $otherErrors[] = '  - ' . ($error['instancePath'] === '' ? '/' : $error['instancePath']) . ": {$error['message']}";
            }

            $errorMessage = "Invalid theme \"{$label}\":\n";
            if ($missingColors !== []) {
                $missingColors = array_map(strval(...), array_keys($missingColors));
                sort($missingColors, SORT_STRING);
                $errorMessage .= "\nMissing required color tokens:\n";
                $errorMessage .= implode("\n", array_map(static fn (string $color): string => "  - {$color}", $missingColors));
                $errorMessage .= "\n\nPlease add these colors to your theme's \"colors\" object.";
                $errorMessage .= "\nSee the built-in themes (dark.json, light.json) for reference values.";
            }
            if ($otherErrors !== []) {
                $errorMessage .= "\n\nOther errors:\n" . implode("\n", $otherErrors);
            }

            throw new InvalidArgumentException($errorMessage);
        }

        /** @var array<string, mixed> $json */
        return self::normalizeIntegers($json);
    }

    /** A JSON object, decoded as an array: string keys, or empty (`{}` and `[]` decode alike). */
    private static function isObject(mixed $value): bool
    {
        return is_array($value) && ($value === [] || !array_is_list($value));
    }

    /** JSON has one number type: `24.0` is an integer to typebox, as `Number.isInteger(24.0)` says. */
    private static function isInteger(mixed $value): bool
    {
        return is_int($value) || (is_float($value) && is_finite($value) && $value === floor($value));
    }

    /**
     * Whole-number floats as ints, so `Colors::parseColor()` sees an index where JSON wrote `24.0`.
     *
     * @param array<string, mixed> $json
     * @return array<string, mixed>
     */
    private static function normalizeIntegers(array $json): array
    {
        foreach (['vars', 'colors', 'export'] as $section) {
            if (!isset($json[$section])) {
                continue;
            }
            foreach ($json[$section] as $key => $value) {
                if (self::isInteger($value)) {
                    $json[$section][$key] = (int) $value;
                }
            }
        }

        return $json;
    }
}
