<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Theme;

use Pig\Tui\Colors;
use Pig\Tui\OkhslChannels;
use Pig\Tui\Oklab;
use Pig\Tui\RgbColor;

/**
 * The `system` theme: pi's colors derived from the terminal's own theme — upstream's
 * `system-theme.ts`, its module functions as static methods.
 *
 * Every token belongs to a color family (its hue) and has contrast rules: it must reach a contrast level
 * on the background and on the panels it is drawn on. Hue and saturation come from the terminal's palette
 * color for the family's ANSI slot, or from the family's own hue when the terminal reports no palette.
 * Lightness comes from the rules alone. Colors are built in OKHSL, whose saturation is relative to the
 * sRGB gamut, and fade toward gray near black and white. A palette color never gains OKLCH chroma when it
 * moves to another lightness, so pastel palettes stay pastel.
 *
 * A contrast level is a target-lightness curve: the OKLab lightness a token needs, given the lightness of
 * the surface below it. The curves were fitted to the reference theme design from the "Pi themes: system
 * and light/dark" review. On dark backgrounds they aim for nearly fixed lightness; on light backgrounds the
 * required difference grows as the background darkens.
 *
 * Depending on what the terminal reports, the theme is generated in one of three tiers:
 * - background and palette: hues from the palette, lightness from the background;
 * - background only: the families' own hues, lightness from the background;
 * - nothing: ANSI palette indices and the default colors, which the terminal renders itself.
 *
 * Upstream's `Family`, `Curve` and `Rule` interfaces are plain arrays in the constants below.
 */
final class SystemTheme
{
    public const string SYSTEM_THEME_NAME = 'system';

    // ============================================================================
    // Recipe: color families and their tokens
    // ============================================================================

    /** A family's OKHSL hue and saturation: `max` at mid lightness, falling toward `min` at black and white; `slot` is the ANSI palette slot the family takes its hue and saturation from. */
    private const array FAMILIES = [
        'neutral' => ['hue' => 231.49, 'saturation' => ['min' => 0.02, 'max' => 0.08], 'slot' => 8],
        'blue' => ['hue' => 231.49, 'saturation' => ['min' => 0.1, 'max' => 0.68], 'slot' => 4],
        'green' => ['hue' => 158.68, 'saturation' => ['min' => 0.1, 'max' => 0.76], 'slot' => 2],
        'red' => ['hue' => 20, 'saturation' => ['min' => 0.1, 'max' => 0.92], 'slot' => 1],
        'yellow' => ['hue' => 82.36, 'saturation' => ['min' => 0.5, 'max' => 1], 'slot' => 3],
        'orange' => ['hue' => 52, 'saturation' => ['min' => 0.12, 'max' => 0.85], 'slot' => 3],
        'violet' => ['hue' => 295, 'saturation' => ['min' => 0.2, 'max' => 0.6], 'slot' => 5],
        'calamine' => ['hue' => 202.43, 'saturation' => ['min' => 0.1, 'max' => 0.74], 'slot' => 6],
        'thinkingSlate' => ['hue' => 231.49, 'saturation' => ['min' => 0.08, 'max' => 0.2], 'slot' => 4],
        'thinkingBlue' => ['hue' => 231.49, 'saturation' => ['min' => 0.2, 'max' => 0.45], 'slot' => 4],
        'thinkingPeriwinkle' => ['hue' => 263.25, 'saturation' => ['min' => 0.3, 'max' => 0.6], 'slot' => 6],
        'thinkingViolet' => ['hue' => 295, 'saturation' => ['min' => 0.4, 'max' => 0.75], 'slot' => 5],
        'thinkingMagenta' => ['hue' => 337.5, 'saturation' => ['min' => 0.5, 'max' => 0.85], 'slot' => 13],
        'thinkingRed' => ['hue' => 20, 'saturation' => ['min' => 0.95, 'max' => 1], 'slot' => 1],
    ];

    private const array TOKEN_FAMILIES = [
        'selectedBg' => 'blue',
        'searchMatchBg' => 'orange',
        'userMessageBg' => 'blue',
        'customMessageBg' => 'violet',
        'toolPendingBg' => 'neutral',
        'toolSuccessBg' => 'green',
        'toolErrorBg' => 'red',

        'text' => 'neutral',
        'userMessageText' => 'neutral',
        'customMessageText' => 'neutral',
        'toolTitle' => 'neutral',
        'syntaxOperator' => 'neutral',
        'syntaxPunctuation' => 'neutral',
        'muted' => 'neutral',
        'dim' => 'neutral',
        'thinkingText' => 'neutral',
        'toolOutput' => 'neutral',
        'mdLinkUrl' => 'neutral',
        'mdQuote' => 'neutral',
        'mdQuoteBorder' => 'neutral',
        'mdHr' => 'neutral',
        'mdCodeBlockBorder' => 'neutral',
        'toolDiffContext' => 'neutral',
        'syntaxComment' => 'neutral',
        'scrollbarTrack' => 'neutral',
        'scrollbarThumb' => 'neutral',
        'searchMatchText' => 'neutral',
        'borderMuted' => 'neutral',

        'accent' => 'violet',
        'borderAccent' => 'violet',
        'customMessageLabel' => 'violet',
        'mdCode' => 'violet',
        'mdListBullet' => 'violet',
        'syntaxType' => 'violet',
        'border' => 'blue',
        'mdLink' => 'blue',
        'syntaxKeyword' => 'blue',
        'syntaxVariable' => 'calamine',
        'success' => 'green',
        'mdCodeBlock' => 'green',
        'toolDiffAdded' => 'green',
        'bashMode' => 'green',
        'syntaxNumber' => 'green',
        'error' => 'red',
        'toolDiffRemoved' => 'red',
        'warning' => 'yellow',
        'mdHeading' => 'yellow',
        'syntaxFunction' => 'yellow',
        'syntaxString' => 'orange',

        'thinkingOff' => 'neutral',
        'thinkingMinimal' => 'thinkingSlate',
        'thinkingLow' => 'thinkingBlue',
        'thinkingMedium' => 'thinkingPeriwinkle',
        'thinkingHigh' => 'thinkingViolet',
        'thinkingXhigh' => 'thinkingMagenta',
        'thinkingMax' => 'thinkingRed',
    ];

    /** Palette slots for tokens that would otherwise share a hue with a similar token. */
    private const array TOKEN_SLOTS = ['syntaxString' => 2, 'syntaxNumber' => 5, 'searchMatchBg' => 3];

    // ============================================================================
    // Contrast levels and rules
    // ============================================================================

    /**
     * Target-lightness curves: a polynomial in the surface's OKLab lightness giving the OKLab lightness a token
     * needs on it. `reachable` is the range of surface lightness where the level can be reached; beyond it the
     * level is relaxed.
     */
    private const array LEVELS = [
        'panel' => [
            'dark' => ['coefficients' => [0.29131, -0.39746, 2.33185, -0.85524, -1.2076, 0.86276], 'reachable' => [0, 0.979]],
            'light' => ['coefficients' => [-3.74073, 27.94549, -78.44258, 112.6798, -79.60015, 22.11277], 'reachable' => [0.348, 1]],
        ],
        'track' => [
            'dark' => ['coefficients' => [0.39028, -0.23015, 0.83573, 2.43829, -4.38292, 2.01582], 'reachable' => [0, 0.946]],
            'light' => ['coefficients' => [-5.24921, 38.37322, -107.28833, 152.10005, -106.17127, 29.18061], 'reachable' => [0.368, 1]],
        ],
        'thinking0' => [
            'dark' => ['coefficients' => [0.52988, -0.05809, -0.30924, 4.63567, -6.52933, 2.89108], 'reachable' => [0, 0.873]],
            'light' => ['coefficients' => [-28.27749, 182.85284, -469.62416, 603.15916, -384.59976, 97.35147], 'reachable' => [0.51, 1]],
        ],
        'thinking1' => [
            'dark' => ['coefficients' => [0.55278, -0.03667, -0.45659, 4.95347, -6.90265, 3.0706], 'reachable' => [0, 0.858]],
            'light' => ['coefficients' => [-37.10484, 235.86282, -596.62344, 754.3633, -474.00763, 118.3551], 'reachable' => [0.535, 1]],
        ],
        'thinking2' => [
            'dark' => ['coefficients' => [0.57486, -0.01765, -0.58987, 5.25227, -7.27175, 3.25532], 'reachable' => [0, 0.842]],
            'light' => ['coefficients' => [-59.89653, 377.05024, -945.07843, 1182.03145, -734.96375, 181.68658], 'reachable' => [0.556, 1]],
        ],
        'thinking3' => [
            'dark' => ['coefficients' => [0.59621, -0.00062, -0.71148, 5.53588, -7.6392, 3.44606], 'reachable' => [0, 0.827]],
            'light' => ['coefficients' => [-72.07122, 445.84082, -1099.57352, 1353.88793, -829.53392, 202.26164], 'reachable' => [0.58, 1]],
        ],
        'thinking4' => [
            'dark' => ['coefficients' => [0.61691, 0.01462, -0.82288, 5.80651, -8.00641, 3.64333], 'reachable' => [0, 0.811]],
            'light' => ['coefficients' => [-110.14338, 674.21488, -1645.75941, 2004.32367, -1215.15899, 293.3183], 'reachable' => [0.6, 1]],
        ],
        'thinking5' => [
            'dark' => ['coefficients' => [0.63702, 0.02826, -0.92498, 6.06465, -8.37246, 3.84651], 'reachable' => [0, 0.795]],
            'light' => ['coefficients' => [-175.47701, 1063.54495, -2570.70594, 3098.80776, -1860.15527, 444.76392], 'reachable' => [0.62, 1]],
        ],
        'thinking6' => [
            'dark' => ['coefficients' => [0.65658, 0.04044, -1.01835, 6.30989, -8.73529, 4.05439], 'reachable' => [0, 0.779]],
            'light' => ['coefficients' => [-183.81712, 1094.70055, -2602.68539, 3088.71276, -1826.91131, 430.75931], 'reachable' => [0.643, 1]],
        ],
        'subtle' => [
            'dark' => ['coefficients' => [0.56762, -0.02475, -0.5383, 5.12628, -7.10931, 3.17324], 'reachable' => [0, 0.848]],
            'light' => ['coefficients' => [-232.85459, 1376.54473, -3249.11801, 3827.91186, -2248.29472, 526.55751], 'reachable' => [0.657, 1]],
        ],
        'thumb' => [
            'dark' => ['coefficients' => [0.60323, 0.00278, -0.73328, 5.57157, -7.68067, 3.46933], 'reachable' => [0, 0.823]],
            'light' => ['coefficients' => [-82.89897, 511.01355, -1255.98095, 1540.76821, -940.68087, 228.58523], 'reachable' => [0.586, 1]],
        ],
        'readable' => [
            'dark' => ['coefficients' => [0.66937, 0.04704, -1.06871, 6.43941, -8.9332, 4.17229], 'reachable' => [0, 0.77]],
            'light' => ['coefficients' => [-1554.52576, 8733.56817, -19604.93507, 21977.72696, -12300.99599, 2749.81288], 'reachable' => [0.751, 1]],
        ],
        'emphasis' => [
            'dark' => ['coefficients' => [0.7303, 0.07695, -1.31626, 7.1681, -10.14436, 4.92846], 'reachable' => [0, 0.712]],
            'light' => ['coefficients' => [-4948.31942, 26870.91986, -58334.48399, 63280.17197, -34298.01053, 7430.30146], 'reachable' => [0.811, 1]],
        ],
        'textOnPanel' => [
            'dark' => ['coefficients' => [0.86713, 0.05232, -0.89428, 4.79014, -5.5432, 1.75023], 'reachable' => [0, 0.542]],
            'light' => ['coefficients' => [-8570.89457, 43954.60805, -90084.00702, 92220.6791, -47152.15802, 9632.27113], 'reachable' => [0.867, 1]],
        ],
        'text' => [
            'dark' => ['coefficients' => [0.89242, 0.02311, -0.44862, 2.34417, -0.06084, -2.63844], 'reachable' => [0, 0.5]],
            'light' => ['coefficients' => [-2004.67048, 6664.47299, -6060.70202, -1792.61209, 5133.82359, -1939.85583], 'reachable' => [0.894, 1]],
        ],
    ];

    private const array TOOL_PANELS = ['toolPendingBg', 'toolSuccessBg', 'toolErrorBg'];
    private const array MESSAGE_PANELS = ['userMessageBg', 'customMessageBg'];
    private const array PANELS = [
        'userMessageBg',
        'toolPendingBg',
        'toolSuccessBg',
        'toolErrorBg',
        'selectedBg',
        'searchMatchBg',
        'customMessageBg',
    ];
    private const array THINKING = [
        'thinkingOff',
        'thinkingMinimal',
        'thinkingLow',
        'thinkingMedium',
        'thinkingHigh',
        'thinkingXhigh',
        'thinkingMax',
    ];
    private const array THINKING_LEVELS = [
        'thinking0',
        'thinking1',
        'thinking2',
        'thinking3',
        'thinking4',
        'thinking5',
        'thinking6',
    ];

    /** Relaxation compresses levels stronger than this one toward it before weakening all levels. */
    private const array READABLE_FLOOR = ['dark' => 'readable', 'light' => 'subtle'];

    /** Body text uses the terminal's foreground when it reaches this level, which is clearly stronger than muted. */
    private const string FOREGROUND_LEVEL = 'emphasis';

    /** Text-level tokens that take the terminal's foreground. */
    private const array FOREGROUND_TOKENS = ['text', 'userMessageText', 'toolTitle'];

    /** WCAG 2 contrast ratio that body text must reach on the surfaces it is drawn on. */
    private const float TEXT_MINIMUM_WCAG_CONTRAST = 4.5;

    /** @var list<array{token: string, on: list<string>, level: string}>|null */
    private static ?array $rules = null;

    /** @var list<string>|null */
    private static ?array $solveOrder = null;

    /**
     * Upstream's `RULES`, built once.
     *
     * @return list<array{token: string, on: list<string>, level: string}>
     */
    private static function rules(): array
    {
        if (self::$rules !== null) {
            return self::$rules;
        }
        $each = static fn (array $tokens, array $on, string $level): array => array_map(
            static fn (string $token): array => ['token' => $token, 'on' => $on, 'level' => $level],
            $tokens,
        );
        $toolPanels = self::TOOL_PANELS;
        $messagePanels = self::MESSAGE_PANELS;

        self::$rules = [
            ...$each(self::PANELS, ['background'], 'panel'),
            ['token' => 'text', 'on' => ['background'], 'level' => 'text'],
            ['token' => 'text', 'on' => ['selectedBg'], 'level' => 'textOnPanel'],
            ['token' => 'userMessageText', 'on' => ['userMessageBg'], 'level' => 'textOnPanel'],
            ['token' => 'toolTitle', 'on' => $toolPanels, 'level' => 'textOnPanel'],
            ...$each(['accent', 'success', 'error', 'warning'], ['background', 'selectedBg', ...$toolPanels], 'readable'),
            ['token' => 'muted', 'on' => ['background', 'selectedBg', 'customMessageBg', ...$toolPanels], 'level' => 'readable'],
            ['token' => 'dim', 'on' => ['background', 'selectedBg', 'customMessageBg', ...$toolPanels], 'level' => 'subtle'],
            ['token' => 'thinkingText', 'on' => ['background'], 'level' => 'readable'],
            ['token' => 'customMessageText', 'on' => ['customMessageBg', ...$toolPanels], 'level' => 'readable'],
            [
                'token' => 'customMessageLabel',
                'on' => ['background', 'customMessageBg', 'selectedBg', ...$toolPanels],
                'level' => 'readable',
            ],
            ['token' => 'toolOutput', 'on' => ['background', ...$toolPanels], 'level' => 'readable'],
            ...$each(
                ['mdHeading', 'mdLink', 'mdLinkUrl', 'mdCode', 'mdQuote', 'mdCodeBlockBorder', 'mdListBullet'],
                ['background', ...$messagePanels],
                'readable',
            ),
            ['token' => 'mdCodeBlock', 'on' => ['background', ...$messagePanels, ...$toolPanels], 'level' => 'readable'],
            ...$each(['toolDiffAdded', 'toolDiffRemoved', 'toolDiffContext'], ['background', ...$toolPanels], 'readable'),
            ...$each(
                [
                    'syntaxComment',
                    'syntaxKeyword',
                    'syntaxFunction',
                    'syntaxVariable',
                    'syntaxString',
                    'syntaxNumber',
                    'syntaxType',
                    'syntaxOperator',
                    'syntaxPunctuation',
                ],
                ['background', ...$messagePanels, ...$toolPanels],
                'readable',
            ),
            ['token' => 'searchMatchText', 'on' => ['searchMatchBg'], 'level' => 'readable'],
            ...$each(['bashMode', 'border', 'borderAccent'], ['background'], 'readable'),
            ['token' => 'borderMuted', 'on' => ['background'], 'level' => 'subtle'],
            ...$each(['mdQuoteBorder', 'mdHr'], ['background', ...$messagePanels, ...$toolPanels], 'readable'),
            ['token' => 'scrollbarTrack', 'on' => ['background'], 'level' => 'track'],
            ['token' => 'scrollbarThumb', 'on' => ['scrollbarTrack'], 'level' => 'thumb'],
            ...array_map(
                static fn (string $token, int $index): array => ['token' => $token, 'on' => ['background'], 'level' => self::THINKING_LEVELS[$index]],
                self::THINKING,
                array_keys(self::THINKING),
            ),
        ];

        return self::$rules;
    }

    /**
     * Tokens in dependency order: every surface before the tokens drawn on it.
     *
     * @return list<string>
     */
    private static function solveOrder(): array
    {
        if (self::$solveOrder !== null) {
            return self::$solveOrder;
        }
        $rules = self::rules();
        $order = [];
        $visit = static function (string $token) use (&$visit, &$order, $rules): void {
            if (in_array($token, $order, true)) {
                return;
            }
            foreach ($rules as $rule) {
                if ($rule['token'] !== $token) {
                    continue;
                }
                foreach ($rule['on'] as $surface) {
                    if ($surface !== 'background') {
                        $visit($surface);
                    }
                }
            }
            $order[] = $token;
        };
        foreach ($rules as $rule) {
            $visit($rule['token']);
        }

        return self::$solveOrder = $order;
    }

    // ============================================================================
    // Public API
    // ============================================================================

    /** OKLab lightness of an sRGB color, 0-1. */
    private static function oklabLightness(RgbColor $color): float
    {
        return Colors::colorToOklch(Colors::rgbColor($color->r, $color->g, $color->b))->l;
    }

    /** WCAG 2 relative luminance. */
    public static function relativeLuminance(RgbColor $color): float
    {
        $linear = static function (int|float $channel): float {
            $value = $channel / 255;

            return $value <= 0.04045 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        };

        return 0.2126 * $linear($color->r) + 0.7152 * $linear($color->g) + 0.0722 * $linear($color->b);
    }

    /** WCAG 2 contrast ratio, 1-21. */
    public static function wcagContrast(RgbColor $first, RgbColor $second): float
    {
        $a = self::relativeLuminance($first);
        $b = self::relativeLuminance($second);

        return (max($a, $b) + 0.05) / (min($a, $b) + 0.05);
    }

    /**
     * Whether a terminal is dark or light, from its reported colors: the direction of its own foreground
     * when text can be readable that way, otherwise dark when white text has more contrast on the
     * background than black text.
     *
     * @return 'dark'|'light' upstream's `ThemeAppearance`
     */
    public static function terminalAppearance(RgbColor $background, ?RgbColor $foreground = null): string
    {
        $white = new RgbColor(255, 255, 255);
        $black = new RgbColor(0, 0, 0);
        $whiteContrast = self::wcagContrast($white, $background);
        $blackContrast = self::wcagContrast($black, $background);
        if ($foreground !== null) {
            $foregroundL = self::oklabLightness($foreground);
            $backgroundL = self::oklabLightness($background);
            if (abs($foregroundL - $backgroundL) > 0.05) {
                $appearance = $foregroundL > $backgroundL ? 'dark' : 'light';
                $best = $appearance === 'dark' ? $whiteContrast : $blackContrast;
                if ($best >= self::TEXT_MINIMUM_WCAG_CONTRAST) {
                    return $appearance;
                }
            }
        }

        return $whiteContrast >= $blackContrast ? 'dark' : 'light';
    }

    // ============================================================================
    // Generation
    // ============================================================================

    private static function clamp(float $value, float $min, float $max): float
    {
        return min($max, max($min, $value));
    }

    private static function hexOf(RgbColor $color): string
    {
        return sprintf('#%02x%02x%02x', (int) round($color->r), (int) round($color->g), (int) round($color->b));
    }

    /** Saturation weight at a lightness: a Gaussian (center 0.5, sigma 0.25), 0 at black and white, 1 in the middle. */
    private static function bellWeight(float $lightness): float
    {
        $gaussian = static fn (float $x): float => exp(-(($x - 0.5) ** 2) / (2 * 0.25 ** 2));

        return ($gaussian($lightness) - $gaussian(0)) / (1 - $gaussian(0));
    }

    /**
     * A family's saturation curve relative to its maximum: 1 at mid lightness, `min / max` at black and white.
     *
     * @param array{hue: float|int, saturation: array{min: float|int, max: float|int}, slot: int} $family
     */
    private static function saturationCurve(array $family, float $lightness): float
    {
        ['min' => $min, 'max' => $max] = $family['saturation'];
        $floor = $max > 0 ? $min / $max : 1;

        return $floor + (1 - $floor) * self::bellWeight($lightness);
    }

    /**
     * The target lightness for a level on a surface, or null where the level cannot be reached.
     *
     * @param 'dark'|'light' $appearance
     */
    private static function levelTarget(string $level, string $appearance, float $surfaceL): ?float
    {
        $curve = self::LEVELS[$level][$appearance];
        if ($surfaceL < $curve['reachable'][0] || $surfaceL > $curve['reachable'][1]) {
            return null;
        }
        $sum = 0.0;
        foreach ($curve['coefficients'] as $power => $coefficient) {
            $sum += $coefficient * $surfaceL ** $power;
        }

        return $sum;
    }

    /** RGB channels of a color made by `Colors`, as upstream's structural `RgbColor`. */
    private static function rgbOf(\Pig\Tui\Color $color): RgbColor
    {
        return Colors::colorToRgb($color);
    }

    /**
     * Generate the system theme's colors from the terminal's reported colors.
     */
    public static function generateSystemThemeColors(SystemThemeInput $input): SystemThemeColors
    {
        $saturation = self::clamp($input->saturation ?? 1, 0, 1);
        $background = $input->background;
        $foreground = $input->foreground;
        if ($background === null) {
            return self::indexedColors($saturation, $input->appearanceHint);
        }
        $palette = $input->palette !== null && count($input->palette) === 16
            ? array_map(self::sourceOf(...), $input->palette)
            : null;

        $appearance = self::terminalAppearance($background, $foreground);
        $lighter = $appearance === 'dark';
        $extreme = $lighter ? 1 : 0;
        $backgroundL = self::oklabLightness($background);

        /*
         * A token's color at an OKLab lightness. With a palette, the palette color's saturation applies at its
         * own lightness and falls off toward black and white along the family's curve, never rising above it.
         */
        $paint = static function (string $token, float $oklabL) use ($palette, $saturation): RgbColor {
            $lightness = Oklab::oklabToOkhslLightness($oklabL);
            $family = self::FAMILIES[self::TOKEN_FAMILIES[$token]];
            if ($palette === null) {
                ['min' => $min, 'max' => $max] = $family['saturation'];

                return self::rgbOf(Colors::okhslColor($family['hue'], ($min + ($max - $min) * self::bellWeight($lightness)) * $saturation, $lightness));
            }

            return self::anchored($palette[self::TOKEN_SLOTS[$token] ?? $family['slot']], $family, $lightness, $saturation);
        };

        /*
         * The lightness a rule needs on a surface, relaxed by `t`: from 0 to 1, levels stronger than the readable
         * floor move toward it; from 1 to 2, all levels move toward the surface itself.
         */
        $target = static function (string $level, float $surfaceL, float $t) use ($appearance, $extreme): ?float {
            $reached = self::levelTarget($level, $appearance, $surfaceL);
            if ($reached === null && $t == 0) {
                return null;
            }
            $distance = ($reached ?? $extreme) - $surfaceL;
            $floor = (self::levelTarget(self::READABLE_FLOOR[$appearance], $appearance, $surfaceL) ?? $extreme) - $surfaceL;
            $compressed = abs($distance) > abs($floor) ? $distance - ($distance - $floor) * min($t, 1) : $distance;

            return $surfaceL + $compressed * (1 - max(0, $t - 1));
        };

        /*
         * Keep a panel light enough (or dark enough) that white (or black) text still reaches the body text
         * minimum on it. This only matters for backgrounds near mid-gray, where it barely does on the background.
         */
        $extremeText = $lighter ? new RgbColor(255, 255, 255) : new RgbColor(0, 0, 0);
        $readable = static fn (RgbColor $color): bool => self::wcagContrast($extremeText, $color) >= self::TEXT_MINIMUM_WCAG_CONTRAST;
        $limitPanel = static function (string $token, float $l) use ($paint, $readable, $backgroundL): RgbColor {
            $color = $paint($token, $l);
            if ($readable($color)) {
                return $color;
            }
            [$low, $high] = [$backgroundL, $l];
            for ($index = 0; $index < 20; $index++) {
                $middle = ($low + $high) / 2;
                if ($readable($paint($token, $middle))) {
                    $low = $middle;
                } else {
                    $high = $middle;
                }
            }

            return $paint($token, $low);
        };

        $rules = self::rules();
        $solve = static function (float $t) use ($background, $rules, $target, $lighter, $limitPanel, $paint): ?array {
            $colors = ['background' => $background];
            foreach (self::solveOrder() as $token) {
                $targets = [];
                foreach ($rules as $rule) {
                    if ($rule['token'] !== $token) {
                        continue;
                    }
                    foreach ($rule['on'] as $surface) {
                        $value = $target($rule['level'], self::oklabLightness($colors[$surface] ?? $background), $t);
                        if ($value === null || $value < 0 || $value > 1) {
                            return null;
                        }
                        $targets[] = $value;
                    }
                }
                $l = $lighter ? max($targets) : min($targets);
                $colors[$token] = in_array($token, self::PANELS, true) ? $limitPanel($token, $l) : $paint($token, $l);
            }

            return $colors;
        };

        $relaxation = 0.0;
        $colors = $solve(0);
        if ($colors === null) {
            // Mid-gray backgrounds cannot fit every level: relax as little as possible. Full relaxation always fits.
            [$low, $high] = [0.0, 2.0];
            $colors = $solve($high);
            for ($index = 0; $index < 20; $index++) {
                $middle = ($low + $high) / 2;
                $attempt = $solve($middle);
                if ($attempt !== null) {
                    [$high, $colors] = [$middle, $attempt];
                } else {
                    $low = $middle;
                }
            }
            $relaxation = $high;
        }
        $solved = $colors ?? [];
        $surfacesOf = static function (string $token) use ($rules, $solved, $background): array {
            $surfaces = [];
            foreach ($rules as $rule) {
                if ($rule['token'] !== $token) {
                    continue;
                }
                foreach ($rule['on'] as $surface) {
                    $surfaces[] = $solved[$surface] ?? $background;
                }
            }

            return $surfaces;
        };

        $result = [];
        foreach (array_keys(self::TOKEN_FAMILIES) as $token) {
            $color = $solved[$token] ?? null;
            $result[$token] = $color !== null ? self::hexOf($color) : '';
        }

        foreach (self::FOREGROUND_TOKENS as $token) {
            $surfaces = $surfacesOf($token);
            // Body text uses the terminal's own foreground where it is clearly stronger than muted text; otherwise
            // the foreground's hue at just enough lightness.
            $text = $solved[$token] ?? null;
            if ($foreground !== null) {
                $targets = array_map(
                    static fn (RgbColor $surface): ?float => $target(self::FOREGROUND_LEVEL, self::oklabLightness($surface), $relaxation),
                    $surfaces,
                );
                $allReachable = true;
                foreach ($targets as $value) {
                    if ($value === null || $value < 0 || $value > 1) {
                        $allReachable = false;
                        break;
                    }
                }
                if ($allReachable) {
                    $needed = $lighter ? max($targets) : min($targets);
                    $foregroundL = self::oklabLightness($foreground);
                    if ($lighter ? $foregroundL >= $needed : $foregroundL <= $needed) {
                        $result[$token] = '';
                        continue;
                    }
                    $text = self::anchored(self::sourceOf($foreground), self::FAMILIES['neutral'], Oklab::oklabToOkhslLightness($needed), $saturation);
                }
            }
            // Body text keeps at least 4.5:1 on the surfaces it is drawn on, even on relaxed mid-gray backgrounds.
            if ($text !== null) {
                $result[$token] = self::hexOf(self::withTextContrast($text, $surfaces, $lighter));
            }
        }

        return new SystemThemeColors($result, [], $appearance);
    }

    private static function okhslOf(RgbColor $color): OkhslChannels
    {
        return Colors::colorToOkhsl(Colors::rgbColor($color->r, $color->g, $color->b));
    }

    /**
     * A terminal color's OKHSL channels and its OKLCH chroma — upstream's `SourceColor`.
     *
     * @return array{h: float, s: float, l: float, chroma: float}
     */
    private static function sourceOf(RgbColor $color): array
    {
        $okhsl = self::okhslOf($color);

        return [
            'h' => $okhsl->h,
            's' => $okhsl->s,
            'l' => $okhsl->l,
            'chroma' => Colors::colorToOklch(Colors::rgbColor($color->r, $color->g, $color->b))->c,
        ];
    }

    /**
     * A source color's hue at another OKHSL lightness. Its saturation applies at its own lightness and falls off
     * toward black and white along the family's saturation curve, never rising above it.
     *
     * OKHSL saturation is relative to the most chroma sRGB allows at a lightness, so the same saturation can mean
     * more chroma elsewhere: Catppuccin Frappe's pink #f4b8e4 (chroma 0.089) would become #eb76d1 (0.180) at the
     * lightness the accent needs. Chroma is therefore also capped at the source's, with the same falloff.
     *
     * @param array{h: float, s: float, l: float, chroma: float} $source
     * @param array{hue: float|int, saturation: array{min: float|int, max: float|int}, slot: int} $family
     */
    private static function anchored(array $source, array $family, float $lightness, float $saturation): RgbColor
    {
        $anchor = self::saturationCurve($family, $source['l']);
        $falloff = $anchor > 0 ? min(1, self::saturationCurve($family, $lightness) / $anchor) : 1;
        $color = Colors::okhslColor($source['h'], $source['s'] * $falloff * $saturation, $lightness);
        $cap = $source['chroma'] * $falloff * $saturation;
        $oklch = Colors::colorToOklch($color);

        return $oklch->c <= $cap ? self::rgbOf($color) : Colors::colorToRgb(Colors::oklchColor($oklch->l, $cap, $source['h']));
    }

    /**
     * Move a text color toward white or black until it reaches the WCAG minimum on every surface.
     *
     * @param list<RgbColor> $surfaces
     */
    private static function withTextContrast(RgbColor $color, array $surfaces, bool $lighter): RgbColor
    {
        $meets = static function (RgbColor $candidate) use ($surfaces): bool {
            foreach ($surfaces as $surface) {
                if (self::wcagContrast($candidate, $surface) < self::TEXT_MINIMUM_WCAG_CONTRAST) {
                    return false;
                }
            }

            return true;
        };
        if ($meets($color)) {
            return $color;
        }
        $okhsl = self::okhslOf($color);
        $at = static fn (float $lightness): RgbColor => self::rgbOf(Colors::okhslColor($okhsl->h, $okhsl->s, $lightness));
        $extreme = $lighter ? 1.0 : 0.0;
        if (!$meets($at($extreme))) {
            return $at($extreme);
        }
        [$low, $high] = [$okhsl->l, $extreme];
        for ($index = 0; $index < 20; $index++) {
            $middle = ($low + $high) / 2;
            if ($meets($at($middle))) {
                $high = $middle;
            } else {
                $low = $middle;
            }
        }

        return $at($high);
    }

    /**
     * Colors for terminals that reported nothing: the terminal renders ANSI indices 0-15 and the default
     * colors with its own theme, so they fit any background. Neutral tokens below body text are faint (SGR 2)
     * instead of bright black, which some themes make nearly invisible. Panels have no background.
     *
     * @param 'dark'|'light'|null $appearance
     */
    private static function indexedColors(float $saturation, ?string $appearance): SystemThemeColors
    {
        $colors = [];
        $dim = [];
        foreach (self::TOKEN_FAMILIES as $token => $familyName) {
            if (in_array($token, self::PANELS, true)) {
                $colors[$token] = '';
                continue;
            }
            $neutral = $familyName === 'neutral';
            $colors[$token] = !$neutral && $saturation > 0 ? (self::TOKEN_SLOTS[$token] ?? self::FAMILIES[$familyName]['slot']) : '';
            if ($neutral && !in_array($token, self::FOREGROUND_TOKENS, true)) {
                $dim[] = $token;
            }
        }

        return new SystemThemeColors($colors, $dim, $appearance);
    }
}
