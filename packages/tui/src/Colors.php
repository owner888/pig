<?php

declare(strict_types=1);

namespace Pig\Tui;

use InvalidArgumentException;

/**
 * Upstream's `colors.ts`: colors, parsing, conversion, mixing, and ANSI styling. Its module
 * functions are static methods here; its types are `Color` (with `IndexedColor`, `RgbColorValue`,
 * `OklchColorValue`), `OklchChannels`, `OkhslChannels`, `TextAttributes` and `TextStyle`.
 *
 * Upstream's string-literal types stay strings, so call sites read as upstream's do:
 * `TerminalColorMode` is `'truecolor'|'256color'` and `ColorMixSpace` is `'oklch'|'srgb'`. An
 * unknown value throws rather than falling through to one of the branches.
 *
 * Upstream's `Error` is `InvalidArgumentException` here: every throw is a bad argument.
 */
final class Colors
{
    private const string NUMBER_PATTERN = '[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:e[+-]?\d+)?';
    private const string OKLCH_PATTERN = '/^oklch\(\s*(' . self::NUMBER_PATTERN . ')(%)?\s+(' . self::NUMBER_PATTERN . ')\s+(' . self::NUMBER_PATTERN . ')(?:deg)?\s*\)$/i';
    private const string OKHSL_PATTERN = '/^okhsl\(\s*(' . self::NUMBER_PATTERN . ')(?:deg)?\s+(' . self::NUMBER_PATTERN . ')(%)?\s+(' . self::NUMBER_PATTERN . ')(%)?\s*\)$/i';

    private const array BASIC_COLORS = [
        [0, 0, 0],
        [128, 0, 0],
        [0, 128, 0],
        [128, 128, 0],
        [0, 0, 128],
        [128, 0, 128],
        [0, 128, 128],
        [192, 192, 192],
        [128, 128, 128],
        [255, 0, 0],
        [0, 255, 0],
        [255, 255, 0],
        [0, 0, 255],
        [255, 0, 255],
        [0, 255, 255],
        [255, 255, 255],
    ];
    private const array CUBE_VALUES = [0, 95, 135, 175, 215, 255];

    private static function requireFinite(int|float $value, string $name): void
    {
        if (!is_finite((float) $value)) {
            throw new InvalidArgumentException("{$name} must be finite");
        }
    }

    /** JavaScript's `String(number)` for the error messages, which print the offending value. */
    private static function show(int|float $value): string
    {
        if (is_int($value) || (is_finite($value) && $value === floor($value) && abs($value) < 1e21)) {
            return (string) (int) $value;
        }

        return is_nan($value) ? 'NaN' : (is_infinite($value) ? ($value > 0 ? 'Infinity' : '-Infinity') : (string) $value);
    }

    /** @param int|float $index must be a whole number, as upstream's `Number.isInteger()` requires */
    public static function indexedColor(int|float $index): IndexedColor
    {
        if ((is_float($index) && (!is_finite($index) || $index !== floor($index))) || $index < 0 || $index > 255) {
            throw new InvalidArgumentException('ANSI color index must be an integer from 0 to 255: ' . self::show($index));
        }

        return new IndexedColor((int) $index);
    }

    public static function rgbColor(int|float $r, int|float $g, int|float $b): RgbColorValue
    {
        foreach (['r' => $r, 'g' => $g, 'b' => $b] as $name => $value) {
            self::requireFinite($value, $name);
            if ($value < 0 || $value > 255) {
                throw new InvalidArgumentException("{$name} must be between 0 and 255: " . self::show($value));
            }
        }

        return new RgbColorValue($r, $g, $b);
    }

    public static function oklchColor(float $l, float $c, float $h): OklchColorValue
    {
        self::requireFinite($l, 'l');
        self::requireFinite($c, 'c');
        self::requireFinite($h, 'h');
        if ($l < 0 || $l > 1) {
            throw new InvalidArgumentException('l must be between 0 and 1: ' . self::show($l));
        }
        if ($c < 0) {
            throw new InvalidArgumentException('c must not be negative: ' . self::show($c));
        }

        return new OklchColorValue($l, $c, fmod(fmod($h, 360) + 360, 360));
    }

    /**
     * An OKHSL color, converted to sRGB. Saturation is relative to the sRGB gamut at the hue and lightness,
     * so equal saturation looks equally colorful across hues and lightness.
     *
     * @param float $h Hue in degrees.
     * @param float $s Saturation, 0-1.
     * @param float $l Lightness, 0-1.
     */
    public static function okhslColor(float $h, float $s, float $l): RgbColorValue
    {
        self::requireFinite($h, 'h');
        self::requireFinite($s, 's');
        self::requireFinite($l, 'l');
        if ($s < 0 || $s > 1) {
            throw new InvalidArgumentException('s must be between 0 and 1: ' . self::show($s));
        }
        if ($l < 0 || $l > 1) {
            throw new InvalidArgumentException('l must be between 0 and 1: ' . self::show($l));
        }
        $rgb = Oklab::okhslToRgb($h, $s, $l);

        return self::rgbColor($rgb->r, $rgb->g, $rgb->b);
    }

    public static function colorToOkhsl(Color $color): OkhslChannels
    {
        return Oklab::rgbToOkhsl(self::colorToRgb($color));
    }

    /** @param string|int $value hex (`#rgb`, `#rrggbb`), `oklch(…)`, `okhsl(…)`, or an ANSI 256-color index */
    public static function parseColor(string|int $value): Color
    {
        if (is_int($value)) {
            return self::indexedColor($value);
        }

        if (preg_match('/^#([\da-f]{3}|[\da-f]{6})$/i', $value, $hex) === 1) {
            $digits = strlen($hex[1]) === 3
                ? implode('', array_map(static fn (string $digit): string => $digit . $digit, str_split($hex[1])))
                : $hex[1];

            return self::rgbColor(
                (int) hexdec(substr($digits, 0, 2)),
                (int) hexdec(substr($digits, 2, 2)),
                (int) hexdec(substr($digits, 4, 2)),
            );
        }

        if (preg_match(self::OKLCH_PATTERN, $value, $oklch) === 1) {
            $lightness = (float) $oklch[1] / ($oklch[2] !== '' ? 100 : 1);

            return self::oklchColor($lightness, (float) $oklch[3], (float) $oklch[4]);
        }

        if (preg_match(self::OKHSL_PATTERN, $value, $okhsl, PREG_UNMATCHED_AS_NULL) === 1) {
            $saturation = (float) $okhsl[2] / ($okhsl[3] !== null ? 100 : 1);
            $lightness = (float) $okhsl[4] / (($okhsl[5] ?? null) !== null ? 100 : 1);

            return self::okhslColor((float) $okhsl[1], $saturation, $lightness);
        }

        throw new InvalidArgumentException("Invalid color value: {$value}");
    }

    private static function indexedToRgb(int $index): RgbColor
    {
        if ($index < 16) {
            return new RgbColor(...self::BASIC_COLORS[$index]);
        }
        if ($index < 232) {
            $cubeIndex = $index - 16;

            return new RgbColor(
                self::CUBE_VALUES[intdiv($cubeIndex, 36)],
                self::CUBE_VALUES[intdiv($cubeIndex % 36, 6)],
                self::CUBE_VALUES[$cubeIndex % 6],
            );
        }
        $gray = 8 + ($index - 232) * 10;

        return new RgbColor($gray, $gray, $gray);
    }

    /** @param list<float> $linear */
    private static function isInSrgbGamut(array $linear): bool
    {
        $epsilon = 1e-7;
        foreach ($linear as $channel) {
            if ($channel < -$epsilon || $channel > 1 + $epsilon) {
                return false;
            }
        }

        return true;
    }

    private static function oklchToRgb(float $l, float $c, float $h): RgbColor
    {
        // Gamut mapping keeps the hue fixed, so its direction is computed once and scaled by chroma.
        $radians = ($h * M_PI) / 180;
        $cos = cos($radians);
        $sin = sin($radians);
        $atChroma = static fn (float $chroma): array => Oklab::oklabToLinearSrgb([$l, $chroma * $cos, $chroma * $sin]);

        $direct = $atChroma($c);
        if (self::isInSrgbGamut($direct)) {
            return Oklab::linearSrgbToRgb($direct);
        }

        // Reduce chroma until the color fits. The achromatic color is always in gamut, so it is the
        // fallback when no bisection step fits, e.g. `oklch(100% 0.3 150)` must map to white.
        $linear = $atChroma(0);
        $low = 0.0;
        $high = $c;
        for ($index = 0; $index < 20; $index++) {
            $chroma = ($low + $high) / 2;
            $candidate = $atChroma($chroma);
            if (self::isInSrgbGamut($candidate)) {
                $low = $chroma;
                $linear = $candidate;
            } else {
                $high = $chroma;
            }
        }

        return Oklab::linearSrgbToRgb($linear);
    }

    public static function colorToRgb(Color $color): RgbColor
    {
        return match (true) {
            $color instanceof IndexedColor => self::indexedToRgb($color->index),
            $color instanceof RgbColorValue => new RgbColor($color->r, $color->g, $color->b),
            $color instanceof OklchColorValue => self::oklchToRgb($color->l, $color->c, $color->h),
            default => throw new InvalidArgumentException('Unknown color kind: ' . $color::class),
        };
    }

    public static function colorToOklch(Color $color): OklchChannels
    {
        if ($color instanceof OklchColorValue) {
            return new OklchChannels($color->l, $color->c, $color->h);
        }
        [$l, $a, $b] = Oklab::rgbToOklab(self::colorToRgb($color));

        return new OklchChannels($l, hypot($a, $b), fmod((atan2($b, $a) * 180) / M_PI + 360, 360));
    }

    public static function colorToHex(Color $color): string
    {
        $rgb = self::colorToRgb($color);

        return sprintf('#%02x%02x%02x', (int) round($rgb->r), (int) round($rgb->g), (int) round($rgb->b));
    }

    /** @param 'oklch'|'srgb' $space upstream's `ColorMixSpace` */
    public static function mixColors(Color $first, Color $second, float $amount, string $space = 'oklch'): Color
    {
        self::requireFinite($amount, 'amount');
        if ($amount < 0 || $amount > 1) {
            throw new InvalidArgumentException('amount must be between 0 and 1: ' . self::show($amount));
        }

        if ($space === 'srgb') {
            $a = self::colorToRgb($first);
            $b = self::colorToRgb($second);

            return self::rgbColor(
                $a->r + ($b->r - $a->r) * $amount,
                $a->g + ($b->g - $a->g) * $amount,
                $a->b + ($b->b - $a->b) * $amount,
            );
        }
        if ($space !== 'oklch') {
            throw new InvalidArgumentException("Unknown color mix space: {$space}");
        }

        $a = self::colorToOklch($first);
        $b = self::colorToOklch($second);
        $firstHue = $a->c < 1e-7 ? $b->h : $a->h;
        $secondHue = $b->c < 1e-7 ? $firstHue : $b->h;
        $hueDelta = fmod($secondHue - $firstHue + 540, 360) - 180;

        return self::oklchColor(
            $a->l + ($b->l - $a->l) * $amount,
            $a->c + ($b->c - $a->c) * $amount,
            $firstHue + $hueDelta * $amount,
        );
    }

    /** @param list<int> $values */
    private static function findClosest(array $values, int|float $target): int
    {
        $closestIndex = 0;
        $closestDistance = INF;
        foreach ($values as $index => $value) {
            $distance = abs($target - $value);
            if ($distance < $closestDistance) {
                $closestIndex = $index;
                $closestDistance = $distance;
            }
        }

        return $closestIndex;
    }

    private static function colorDistance(RgbColor $first, RgbColor $second): float
    {
        $dr = $first->r - $second->r;
        $dg = $first->g - $second->g;
        $db = $first->b - $second->b;

        return $dr * $dr * 0.299 + $dg * $dg * 0.587 + $db * $db * 0.114;
    }

    private static function rgbToAnsi256(RgbColor $color): int
    {
        $rIndex = self::findClosest(self::CUBE_VALUES, $color->r);
        $gIndex = self::findClosest(self::CUBE_VALUES, $color->g);
        $bIndex = self::findClosest(self::CUBE_VALUES, $color->b);
        $cubeColor = new RgbColor(self::CUBE_VALUES[$rIndex], self::CUBE_VALUES[$gIndex], self::CUBE_VALUES[$bIndex]);
        $cubeIndex = 16 + 36 * $rIndex + 6 * $gIndex + $bIndex;

        $grayValues = array_map(static fn (int $index): int => 8 + $index * 10, range(0, 23));
        $gray = (int) round(0.299 * $color->r + 0.587 * $color->g + 0.114 * $color->b);
        $grayOffset = self::findClosest($grayValues, $gray);
        $grayValue = $grayValues[$grayOffset];
        $spread = max($color->r, $color->g, $color->b) - min($color->r, $color->g, $color->b);
        if (
            $spread < 10
            && self::colorDistance($color, new RgbColor($grayValue, $grayValue, $grayValue)) < self::colorDistance($color, $cubeColor)
        ) {
            return 232 + $grayOffset;
        }

        return $cubeIndex;
    }

    /** @param 'truecolor'|'256color' $mode */
    private static function colorAnsi(Color $color, string $mode, bool $background): string
    {
        $layer = $background ? 48 : 38;
        if ($color instanceof IndexedColor) {
            return "\x1b[{$layer};5;{$color->index}m";
        }

        $rgb = self::colorToRgb($color);
        if ($mode === 'truecolor') {
            return sprintf("\x1b[%d;2;%d;%d;%dm", $layer, (int) round($rgb->r), (int) round($rgb->g), (int) round($rgb->b));
        }
        if ($mode !== '256color') {
            throw new InvalidArgumentException("Unknown terminal color mode: {$mode}");
        }

        return "\x1b[{$layer};5;" . self::rgbToAnsi256($rgb) . 'm';
    }

    /** @param 'truecolor'|'256color' $mode upstream's `TerminalColorMode` */
    public static function foregroundAnsi(Color $color, string $mode): string
    {
        return self::colorAnsi($color, $mode, false);
    }

    /** @param 'truecolor'|'256color' $mode upstream's `TerminalColorMode` */
    public static function backgroundAnsi(Color $color, string $mode): string
    {
        return self::colorAnsi($color, $mode, true);
    }

    /** @param 'truecolor'|'256color' $mode upstream's `TerminalColorMode` */
    public static function styleText(string $text, TextStyle $options, string $mode): string
    {
        return self::styleTextWithAnsi(
            $text,
            $options->fg !== null ? self::foregroundAnsi($options->fg, $mode) : null,
            $options->bg !== null ? self::backgroundAnsi($options->bg, $mode) : null,
            $options,
        );
    }

    /**
     * Like `styleText()`, but with precomputed color escape sequences, e.g. cached theme colors.
     * Colors in `$options` are ignored.
     */
    public static function styleTextWithAnsi(string $text, ?string $fgAnsi, ?string $bgAnsi, TextAttributes $options): string
    {
        // Resets are prepended so they close in reverse order of the opening sequences.
        $prefix = '';
        $suffix = '';
        if ($fgAnsi !== null && $fgAnsi !== '') {
            $prefix .= $fgAnsi;
            $suffix = "\x1b[39m";
        }
        if ($bgAnsi !== null && $bgAnsi !== '') {
            $prefix .= $bgAnsi;
            $suffix = "\x1b[49m{$suffix}";
        }
        if ($options->bold) {
            $prefix .= "\x1b[1m";
        }
        if ($options->dim) {
            $prefix .= "\x1b[2m";
        }
        if ($options->bold || $options->dim) {
            $suffix = "\x1b[22m{$suffix}";
        }
        if ($options->italic) {
            $prefix .= "\x1b[3m";
            $suffix = "\x1b[23m{$suffix}";
        }
        if ($options->underline) {
            $prefix .= "\x1b[4m";
            $suffix = "\x1b[24m{$suffix}";
        }
        if ($options->inverse) {
            $prefix .= "\x1b[7m";
            $suffix = "\x1b[27m{$suffix}";
        }
        if ($options->strikethrough) {
            $prefix .= "\x1b[9m";
            $suffix = "\x1b[29m{$suffix}";
        }

        return "{$prefix}{$text}{$suffix}";
    }
}
