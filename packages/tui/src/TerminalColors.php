<?php

declare(strict_types=1);

namespace Pig\Tui;

/**
 * Colors the terminal reports for its current theme — upstream's `TerminalColors`, with the
 * module functions of `terminal-colors.ts` (`parseOscColorResponse()`,
 * `parseTerminalColorSchemeReport()`) as static methods.
 */
final readonly class TerminalColors
{
    private const string OSC_COLOR_RESPONSE_PATTERN = '/^\x1b\](?:(1[01])|4;(\d{1,3}));([^\x07\x1b]*)(?:\x07|\x1b\\\\)$/i';
    private const string COLOR_SCHEME_REPORT_PATTERN = '/^(?:\x1b\[\?997;(1|2)n)+$/';

    /**
     * @param RgbColor|null $foreground default foreground (OSC 10)
     * @param RgbColor|null $background default background (OSC 11)
     * @param list<RgbColor>|null $palette ANSI colors 0-15 (OSC 4); only set when the terminal reported all 16
     */
    public function __construct(
        public ?RgbColor $foreground = null,
        public ?RgbColor $background = null,
        public ?array $palette = null,
    ) {
    }

    private static function hexToRgb(string $hex): RgbColor
    {
        $normalized = str_starts_with($hex, '#') ? substr($hex, 1) : $hex;

        return new RgbColor(
            (int) hexdec(substr($normalized, 0, 2)),
            (int) hexdec(substr($normalized, 2, 2)),
            (int) hexdec(substr($normalized, 4, 2)),
        );
    }

    private static function parseOscHexChannel(string $channel): ?int
    {
        if (preg_match('/^[0-9a-f]+$/i', $channel) !== 1) {
            return null;
        }
        $max = 16 ** strlen($channel) - 1;
        if ($max <= 0) {
            return null;
        }

        return (int) round(hexdec($channel) / $max * 255);
    }

    /**
     * Parse an OSC 10, 11, or 4 color reply. Null when `$data` is not such a reply; `rgb` is null
     * when it is a reply with an unparseable color.
     *
     * @return array{target: 'foreground'|'background'|int, rgb: ?RgbColor}|null
     */
    public static function parseOscColorResponse(string $data): ?array
    {
        if (preg_match(self::OSC_COLOR_RESPONSE_PATTERN, $data, $match) !== 1) {
            return null;
        }
        $target = match ($match[1]) {
            '10' => 'foreground',
            '11' => 'background',
            default => (int) $match[2],
        };

        return ['target' => $target, 'rgb' => self::parseOscColorValue($match[3])];
    }

    private static function parseOscColorValue(string $rawValue): ?RgbColor
    {
        $value = trim($rawValue);
        if (str_starts_with($value, '#')) {
            $hex = substr($value, 1);
            if (preg_match('/^[0-9a-f]{6}$/i', $hex) === 1) {
                return self::hexToRgb($value);
            }
            if (preg_match('/^[0-9a-f]{12}$/i', $hex) === 1) {
                $r = self::parseOscHexChannel(substr($hex, 0, 4));
                $g = self::parseOscHexChannel(substr($hex, 4, 4));
                $b = self::parseOscHexChannel(substr($hex, 8, 4));

                return $r !== null && $g !== null && $b !== null ? new RgbColor($r, $g, $b) : null;
            }

            return null;
        }

        $parts = explode('/', (string) preg_replace('/^rgba?:/i', '', $value));
        if (count($parts) < 3) {
            return null;
        }
        $r = self::parseOscHexChannel($parts[0]);
        $g = self::parseOscHexChannel($parts[1]);
        $b = self::parseOscHexChannel($parts[2]);

        return $r !== null && $g !== null && $b !== null ? new RgbColor($r, $g, $b) : null;
    }

    /** @return 'dark'|'light'|null upstream's `TerminalColorScheme` */
    public static function parseTerminalColorSchemeReport(string $data): ?string
    {
        if (preg_match(self::COLOR_SCHEME_REPORT_PATTERN, $data, $match) !== 1) {
            return null;
        }

        return $match[1] === '2' ? 'light' : 'dark';
    }
}
