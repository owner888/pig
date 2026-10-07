<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Theme;

use InvalidArgumentException;

/**
 * Theme JSON validation — upstream's `theme-json.ts`, kept out of `Themes` as upstream keeps it
 * out of `theme.ts`.
 *
 * Upstream compiles a typebox schema (`ThemeJsonSchema`) and turns its errors into one message.
 * pig adds no third-party package, so the schema is checked by hand here, in the schema's own
 * order, and reported in the same shape: missing `colors` tokens gathered into one sorted list,
 * everything else as `  - <instance path>: <message>` under "Other errors". The messages are
 * typebox's (ajv-style) wording as closely as practical — `must be string`, `must have required
 * properties name`, `must match a schema in anyOf` — but a union failure reports only the union,
 * not each branch it tried. Like typebox's `Type.Object`, properties the schema does not name are
 * allowed. `theme-schema.json` beside this file is upstream's JSON schema, copied verbatim.
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

    private const array EXPORT_KEYS = ['pageBg', 'cardBg', 'infoBg'];

    /**
     * Validate one theme document, throwing a message that names the offending tokens.
     *
     * @return array<string, mixed> the document, upstream's `ValidatedThemeJson` (see the class docblock)
     * @throws InvalidArgumentException
     */
    public static function validateThemeJson(string $label, mixed $json): array
    {
        $missingColors = [];
        $otherErrors = [];
        $error = static function (string $path, string $message) use (&$otherErrors): void {
            $otherErrors[] = '  - ' . ($path === '' ? '/' : $path) . ": {$message}";
        };

        if (!self::isObject($json)) {
            $error('', 'must be object');
        } else {
            $missing = array_values(array_filter(['name', 'colors'], static fn (string $key): bool => !array_key_exists($key, $json)));
            if ($missing !== []) {
                $error('', 'must have required properties ' . implode(', ', $missing));
            }
            if (array_key_exists('$schema', $json) && !is_string($json['$schema'])) {
                $error('/$schema', 'must be string');
            }
            if (array_key_exists('name', $json) && !is_string($json['name'])) {
                $error('/name', 'must be string');
            }
            if (array_key_exists('appearance', $json) && !in_array($json['appearance'], ['dark', 'light'], true)) {
                $error('/appearance', 'must match a schema in anyOf');
            }
            if (array_key_exists('vars', $json)) {
                if (!self::isObject($json['vars'])) {
                    $error('/vars', 'must be object');
                } else {
                    foreach ($json['vars'] as $key => $value) {
                        if (!self::isColorValue($value)) {
                            $error('/vars/' . self::pointer((string) $key), 'must match a schema in anyOf');
                        }
                    }
                }
            }
            if (array_key_exists('colors', $json)) {
                if (!self::isObject($json['colors'])) {
                    $error('/colors', 'must be object');
                } else {
                    foreach (self::COLOR_TOKENS as $token => $required) {
                        if (!array_key_exists($token, $json['colors'])) {
                            if ($required) {
                                $missingColors[] = $token;
                            }
                        } elseif (!self::isColorValue($json['colors'][$token])) {
                            $error("/colors/{$token}", 'must match a schema in anyOf');
                        }
                    }
                }
            }
            if (array_key_exists('export', $json)) {
                if (!self::isObject($json['export'])) {
                    $error('/export', 'must be object');
                } else {
                    foreach (self::EXPORT_KEYS as $key) {
                        if (array_key_exists($key, $json['export']) && !self::isColorValue($json['export'][$key])) {
                            $error("/export/{$key}", 'must match a schema in anyOf');
                        }
                    }
                }
            }
        }

        if ($missingColors !== [] || $otherErrors !== []) {
            $errorMessage = "Invalid theme \"{$label}\":\n";
            if ($missingColors !== []) {
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
        if (str_contains($json['name'], '/')) {
            throw new InvalidArgumentException(
                "Invalid theme name \"{$json['name']}\": theme names cannot contain \"/\" because it is reserved for automatic light/dark theme settings.",
            );
        }

        return self::normalizeIntegers($json);
    }

    /** A JSON object, decoded as an array: string keys, or empty (`{}` and `[]` decode alike). */
    private static function isObject(mixed $value): bool
    {
        return is_array($value) && ($value === [] || !array_is_list($value));
    }

    /** Upstream's `ColorValueSchema`: any string, or an integer 0-255. */
    private static function isColorValue(mixed $value): bool
    {
        if (is_string($value)) {
            return true;
        }

        return self::isInteger($value) && $value >= 0 && $value <= 255;
    }

    /** JSON has one number type: `24.0` is an integer to typebox, as `Number.isInteger(24.0)` says. */
    private static function isInteger(mixed $value): bool
    {
        return is_int($value) || (is_float($value) && is_finite($value) && $value === floor($value));
    }

    /** JSON Pointer escaping for an instance path segment. */
    private static function pointer(string $key): string
    {
        return str_replace(['~', '/'], ['~0', '~1'], $key);
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
