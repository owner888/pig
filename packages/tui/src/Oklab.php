<?php

declare(strict_types=1);

namespace Pig\Tui;

/**
 * Oklab and OKHSL <-> sRGB conversion — upstream's `oklab.ts`, its module functions as static
 * methods. `Colors` builds its OKLCH, OKHSL, and color mixing on it.
 *
 * Oklab and OKHSL are Björn Ottosson's color spaces; OKHSL's saturation is relative to the sRGB gamut at
 * each hue and lightness. This is a port of his reference implementation (https://bottosson.github.io/posts/colorpicker/),
 * Copyright (c) 2021 Björn Ottosson, used under the MIT license:
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy of this software and
 * associated documentation files (the "Software"), to deal in the Software without restriction, including
 * without limitation the rights to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is furnished to do so, subject to the
 * following conditions: The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software. THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY
 * KIND, EXPRESS OR IMPLIED.
 *
 * Upstream's `Vector` (`[number, number, number]`) is a three-element list of floats here.
 */
final class Oklab
{
    // ============================================================================
    // OKHSL <-> sRGB
    // ============================================================================

    private const array LINEAR_SRGB_TO_LMS = [
        [0.4122214694707629, 0.5363325372617349, 0.0514459932675022],
        [0.2119034958178251, 0.6806995506452344, 0.1073969535369405],
        [0.0883024591900564, 0.2817188391361215, 0.6299787016738222],
    ];
    private const array LMS_TO_LAB = [
        [0.210454268309314, 0.793617774702305, -0.0040720430116193],
        [1.9779985324311684, -2.42859224204858, 0.450593709617411],
        [0.0259040424655478, 0.7827717124575296, -0.8086757549230774],
    ];
    private const array LAB_TO_LMS = [
        [1, 0.3963377773761749, 0.2158037573099136],
        [1, -0.1055613458156586, -0.0638541728258133],
        [1, -0.0894841775298119, -1.2914855480194092],
    ];
    private const array LMS_TO_LINEAR_SRGB = [
        [4.0767416360759583, -3.3077115392580629, 0.2309699031821043],
        [-1.2684379732850315, 2.6097573492876882, -0.341319376002657],
        [-0.0041960761386756, -0.7034186179359362, 1.7076146940746117],
    ];
    /**
     * Per sRGB channel (red, green, blue): the (a, b) half-plane where that channel clips
     * first, and the polynomial approximating the maximum saturation there.
     */
    private const array SATURATION_FIT = [
        [
            [-1.8817031, -0.80936501],
            [1.19086277, 1.76576728, 0.59662641, 0.75515197, 0.56771245],
        ],
        [
            [1.8144408, -1.19445267],
            [0.73956515, -0.45954404, 0.08285427, 0.12541073, -0.14503204],
        ],
        [
            [0.13110758, 1.81333971],
            [1.35733652, -0.00915799, -1.1513021, -0.50559606, 0.00692167],
        ],
    ];
    private const float K1 = 0.206;
    private const float K2 = 0.03;
    private const float K3 = (1 + self::K1) / (1 + self::K2);

    /**
     * @param array<int, array<int, float|int>> $m
     * @param list<float> $v
     * @return list<float>
     */
    private static function multiply(array $m, array $v): array
    {
        [$x, $y, $z] = $v;

        return array_map(static fn (array $row): float => $row[0] * $x + $row[1] * $y + $row[2] * $z, $m);
    }

    /** JavaScript's `Math.cbrt()`: PHP's `**` gives NAN for a negative base with a fractional exponent. */
    private static function cbrt(float $value): float
    {
        return $value < 0 ? -((-$value) ** (1 / 3)) : $value ** (1 / 3);
    }

    /** Oklab lightness to OKHSL lightness. */
    public static function oklabToOkhslLightness(float $x): float
    {
        return 0.5 * (self::K3 * $x - self::K1 + sqrt((self::K3 * $x - self::K1) ** 2 + 4 * self::K2 * self::K3 * $x));
    }

    /** OKHSL lightness to Oklab lightness. */
    private static function okhslToOklabLightness(float $x): float
    {
        return ($x * $x + self::K1 * $x) / (self::K3 * ($x + self::K2));
    }

    /** sRGB transfer function: linear to encoded channel, both 0-1. */
    private static function linearToSrgb(float $value): float
    {
        return $value > 0.0031308 ? 1.055 * $value ** (1 / 2.4) - 0.055 : 12.92 * $value;
    }

    /** Inverse sRGB transfer function: encoded to linear channel, both 0-1. */
    private static function srgbToLinear(float $value): float
    {
        return $value <= 0.04045 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
    }

    /**
     * Oklab [L, a, b] to linear sRGB [r, g, b] (0-1, may leave the gamut).
     *
     * @param list<float> $lab
     * @return list<float>
     */
    public static function oklabToLinearSrgb(array $lab): array
    {
        return self::multiply(
            self::LMS_TO_LINEAR_SRGB,
            array_map(static fn (float $value): float => $value ** 3, self::multiply(self::LAB_TO_LMS, $lab)),
        );
    }

    /**
     * Linear sRGB [r, g, b] (0-1) to Oklab [L, a, b].
     *
     * @param list<float> $rgb
     * @return list<float>
     */
    private static function linearSrgbToOklab(array $rgb): array
    {
        return self::multiply(
            self::LMS_TO_LAB,
            array_map(self::cbrt(...), self::multiply(self::LINEAR_SRGB_TO_LMS, $rgb)),
        );
    }

    /**
     * sRGB channels (0-255) to Oklab [L, a, b].
     *
     * @return list<float>
     */
    public static function rgbToOklab(RgbColor $color): array
    {
        return self::linearSrgbToOklab(array_map(
            self::srgbToLinear(...),
            [$color->r / 255, $color->g / 255, $color->b / 255],
        ));
    }

    /**
     * Linear sRGB [r, g, b] to sRGB channels (0-255, rounded), clipping out-of-gamut channels.
     *
     * @param list<float> $linear
     */
    public static function linearSrgbToRgb(array $linear): RgbColor
    {
        [$r, $g, $b] = array_map(
            static fn (float $value): int => (int) round(min(1, max(0, self::linearToSrgb($value))) * 255),
            $linear,
        );

        return new RgbColor($r, $g, $b);
    }

    /**
     * Rate of change of each cube-root LMS component along a chroma direction (a, b).
     *
     * @return list<float>
     */
    private static function lmsSlopes(float $a, float $b): array
    {
        return array_map(static fn (array $row): float => $row[1] * $a + $row[2] * $b, self::LAB_TO_LMS);
    }

    /** Largest saturation (C/L) inside sRGB for hue (a, b): polynomial fit plus one Halley step. */
    private static function maxSaturation(float $a, float $b): float
    {
        $channel = 2;
        foreach (self::SATURATION_FIT as $index => [[$x, $y]]) {
            if ($index === 2 || $x * $a + $y * $b > 1) {
                $channel = $index;
                break;
            }
        }
        [$k0, $k1, $k2, $k3, $k4] = self::SATURATION_FIT[$channel][1];
        $weights = self::LMS_TO_LINEAR_SRGB[$channel];
        $saturation = $k0 + $k1 * $a + $k2 * $b + $k3 * $a * $a + $k4 * $a * $b;

        $slopes = self::lmsSlopes($a, $b);
        $base = array_map(static fn (float $k): float => 1 + $saturation * $k, $slopes);
        $dot = static function (array $values) use ($weights): float {
            $sum = 0.0;
            foreach ($values as $index => $value) {
                $sum += $weights[$index] * $value;
            }

            return $sum;
        };
        $f = $dot(array_map(static fn (float $value): float => $value ** 3, $base));
        $f1 = $dot(array_map(static fn (float $value, int $index): float => 3 * $slopes[$index] * $value ** 2, $base, array_keys($base)));
        $f2 = $dot(array_map(static fn (float $value, int $index): float => 6 * $slopes[$index] ** 2 * $value, $base, array_keys($base)));

        return $saturation - ($f * $f1) / ($f1 * $f1 - 0.5 * $f * $f2);
    }

    /**
     * Oklab lightness and chroma of the most saturated sRGB color of hue (a, b).
     *
     * @return array{0: float, 1: float}
     */
    private static function cusp(float $a, float $b): array
    {
        $saturation = self::maxSaturation($a, $b);
        $lightness = self::cbrt(1 / max(self::oklabToLinearSrgb([1.0, $saturation * $a, $saturation * $b])));

        return [$lightness, $lightness * $saturation];
    }

    /**
     * Chroma where the constant-lightness line at `lightness` leaves the sRGB gamut.
     *
     * @param array{0: float, 1: float} $cusp
     */
    private static function maxChroma(float $a, float $b, float $lightness, array $cusp): float
    {
        [$cuspL, $cuspC] = $cusp;
        if ($lightness <= $cuspL) {
            return ($cuspC * $lightness) / $cuspL;
        }
        // Upper half: triangle edge, then one Halley step against each channel reaching 1.
        $t = ($cuspC * ($lightness - 1)) / ($cuspL - 1);
        $slopes = self::lmsSlopes($a, $b);
        $lms = array_map(static fn (float $k): float => $lightness + $t * $k, $slopes);
        $cubes = array_map(static fn (float $value): float => $value ** 3, $lms);
        $first = array_map(static fn (float $value, int $index): float => 3 * $slopes[$index] * $value ** 2, $lms, array_keys($lms));
        $second = array_map(static fn (float $value, int $index): float => 6 * $slopes[$index] ** 2 * $value, $lms, array_keys($lms));
        $dot = static fn (array $row, array $values): float => $row[0] * $values[0] + $row[1] * $values[1] + $row[2] * $values[2];
        $steps = array_map(static function (array $row) use ($dot, $cubes, $first, $second): float {
            $f = $dot($row, $cubes) - 1;
            $f1 = $dot($row, $first);
            $f2 = $dot($row, $second);
            $u = $f1 / ($f1 * $f1 - 0.5 * $f * $f2);

            return $u >= 0 ? -$f * $u : PHP_FLOAT_MAX;
        }, self::LMS_TO_LINEAR_SRGB);

        return $t + min($steps);
    }

    /**
     * OKHSL's chroma reference points at lightness L and hue (a, b): [c0, cMid, cMax].
     *
     * @return array{0: float, 1: float, 2: float}
     */
    private static function chromaStops(float $L, float $a, float $b): array
    {
        $peak = self::cusp($a, $b);
        $cMax = self::maxChroma($a, $b, $L, $peak);
        $k = $cMax / min($L * ($peak[1] / $peak[0]), (1 - $L) * ($peak[1] / (1 - $peak[0])));
        $midS = 0.11516993
            + 1
            / (7.4477897
                + 4.1590124 * $b
                + $a
                * (-2.19557347
                    + 1.75198401 * $b
                    + $a * (-2.13704948 - 10.02301043 * $b + $a * (-4.24894561 + 5.38770819 * $b + 4.69891013 * $a))));
        $midT = 0.11239642
            + 1
            / (1.6132032
                - 0.68124379 * $b
                + $a
                * (0.40370612
                    + 0.90148123 * $b
                    + $a * (-0.27087943 + 0.6122399 * $b + $a * (0.00299215 - 0.45399568 * $b - 0.14661872 * $a))));
        $cMid = 0.9 * $k * sqrt(sqrt(1 / (1 / ($L * $midS) ** 4 + 1 / ((1 - $L) * $midT) ** 4)));
        $c0 = sqrt(1 / (1 / ($L * 0.4) ** 2 + 1 / ((1 - $L) * 0.8) ** 2));

        return [$c0, $cMid, $cMax];
    }

    /**
     * Convert OKHSL to sRGB channels (0-255, rounded), clipping out-of-gamut channels.
     *
     * @param float $hue Hue in degrees.
     * @param float $saturation Saturation, 0-1.
     * @param float $lightness Lightness, 0-1.
     */
    public static function okhslToRgb(float $hue, float $saturation, float $lightness): RgbColor
    {
        $L = self::okhslToOklabLightness($lightness);
        $lab = [$L, 0.0, 0.0];
        if ($L > 0 && $L < 1 && $saturation > 0) {
            $angle = (2 * M_PI * fmod(fmod($hue, 360) + 360, 360)) / 360;
            $a = cos($angle);
            $b = sin($angle);
            [$c0, $cMid, $cMax] = self::chromaStops($L, $a, $b);
            // Chroma rises from 0 through cMid at s = 0.8 to cMax at s = 1.
            if ($saturation < 0.8) {
                $t = 1.25 * $saturation;
                $k1 = 0.8 * $c0;
                $chroma = ($t * $k1) / (1 - (1 - $k1 / $cMid) * $t);
            } else {
                $t = 5 * ($saturation - 0.8);
                $k1 = (0.2 * $cMid ** 2 * 1.25 ** 2) / $c0;
                $chroma = $cMid + ($t * $k1) / (1 - (1 - $k1 / ($cMax - $cMid)) * $t);
            }
            $lab = [$L, $chroma * $a, $chroma * $b];
        }

        return self::linearSrgbToRgb(self::oklabToLinearSrgb($lab));
    }

    /**
     * Convert sRGB channels (0-255) to OKHSL: hue `h` in degrees (0 for grays), saturation `s` and
     * lightness `l` 0-1.
     */
    public static function rgbToOkhsl(RgbColor $rgb): OkhslChannels
    {
        [$L, $labA, $labB] = self::rgbToOklab($rgb);
        $chroma = hypot($labA, $labB);
        $lightness = self::oklabToOkhslLightness($L);
        if ($chroma < 1e-9 || $lightness <= 0 || $lightness >= 1) {
            return new OkhslChannels(0.0, 0.0, $lightness);
        }

        $hue = fmod((atan2($labB, $labA) * 180) / M_PI + 360, 360);
        [$c0, $cMid, $cMax] = self::chromaStops($L, $labA / $chroma, $labB / $chroma);
        if ($chroma < $cMid) {
            $k1 = 0.8 * $c0;
            $saturation = 0.8 * ($chroma / ($k1 + (1 - $k1 / $cMid) * $chroma));
        } else {
            $k1 = (0.2 * $cMid ** 2 * 1.25 ** 2) / $c0;
            $offset = $chroma - $cMid;
            $saturation = 0.8 + 0.2 * ($offset / ($k1 + (1 - $k1 / ($cMax - $cMid)) * $offset));
        }

        return new OkhslChannels($hue, min(1, max(0, $saturation)), $lightness);
    }
}
