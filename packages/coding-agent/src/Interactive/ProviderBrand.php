<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Interactive;

use Closure;
use Pig\Ai\Models;
use Pig\Async\Loop;
use Pig\CodingAgent\Theme\Themes;
use Pig\Tui\Colors;
use Pig\Tui\TUI;

/**
 * Upstream's `provider-brand.ts` (683a7a86): animated brand styles for the names of the preferred
 * partner providers in selectors — Radius shimmers in its logo colors, OpenRouter glows in its brand
 * color. Selectors apply them to the selected row only.
 */
final class ProviderBrand
{
    /** The four colors of the Radius logo, in the order they stream across the text. */
    private const array RADIUS_COLORS = ['#4d9abf', '#83ccd2', '#f1be57', '#f09082'];

    /** Width of each color band, in characters. */
    private const int CHARS_PER_COLOR = 4;

    private const int CHARS_PER_SECOND = 10;

    /** OpenRouter's colors per appearance: lime on dark backgrounds, violet on light ones, where lime would be unreadable. */
    private const array OPENROUTER_GLOW = [
        'dark' => ['base' => '#c6f243', 'peak' => '#efffb0'],
        'light' => ['base' => '#5d32e1', 'peak' => '#8f6bff'],
    ];

    private const int GLOW_PERIOD_MS = 2400;

    private const float ANIMATION_FRAME_SECONDS = 0.05;

    /**
     * Upstream's `getProviderBrandStyle()`: `fn(string $text, float $elapsedMs): string`, or null for
     * a provider with none.
     *
     * @return (Closure(string, float): string)|null
     */
    public static function styleOf(string $providerId): ?Closure
    {
        return match ($providerId) {
            Models::RADIUS => self::radiusShimmer(...),
            'openrouter' => self::openRouterGlow(...),
            default => null,
        };
    }

    /** Upstream's `radiusShimmer()`: `$text` with the Radius logo colors flowing left to right. */
    public static function radiusShimmer(string $text, float $elapsedMs): string
    {
        $mode = Themes::theme()->getColorMode();
        $colors = array_map(Colors::parseColor(...), self::RADIUS_COLORS);
        $cycle = count($colors) * self::CHARS_PER_COLOR;
        $offset = ($elapsedMs / 1000) * self::CHARS_PER_SECOND;
        $result = '';

        foreach (mb_str_split($text) as $index => $char) {
            $position = fmod(fmod($index - $offset, $cycle) + $cycle, $cycle);
            $band = (int) floor($position / self::CHARS_PER_COLOR);
            $t = $position / self::CHARS_PER_COLOR - $band;
            // Smoothstep keeps each band recognizable while still blending into the next one.
            $amount = min(1.0, max(0.0, $t * $t * (3 - 2 * $t)));
            $result .= Colors::foregroundAnsi(Colors::mixColors($colors[$band], $colors[($band + 1) % count($colors)], $amount, 'srgb'), $mode) . $char;
        }

        return "{$result}\x1b[39m";
    }

    /** Upstream's `openRouterGlow()`: `$text` in OpenRouter's color, slowly brightening and dimming. */
    private static function openRouterGlow(string $text, float $elapsedMs): string
    {
        $theme = Themes::theme();
        $glow = self::OPENROUTER_GLOW[$theme->appearance()];
        $amount = min(1.0, max(0.0, (1 - cos((2 * M_PI * $elapsedMs) / self::GLOW_PERIOD_MS)) / 2));
        $color = Colors::mixColors(Colors::parseColor($glow['base']), Colors::parseColor($glow['peak']), $amount, 'oklch');

        return Colors::foregroundAnsi($color, $theme->getColorMode()) . "{$text}\x1b[39m";
    }

    /**
     * Upstream's `startBrandAnimation()`: `$tui` rendered again every frame while `$isAnimating()`
     * holds. Answers what stops it.
     *
     * @param Closure(): bool $isAnimating
     * @return Closure(): void
     */
    public static function startAnimation(TUI $tui, Closure $isAnimating): Closure
    {
        $timer = null;
        $tick = static function () use (&$tick, &$timer, $tui, $isAnimating): void {
            if ($isAnimating()) {
                $tui->requestRender();
            }

            $timer = Loop::get()->delay(self::ANIMATION_FRAME_SECONDS, $tick);
        };
        $timer = Loop::get()->delay(self::ANIMATION_FRAME_SECONDS, $tick);

        return static function () use (&$timer, &$tick): void {
            if ($timer !== null) {
                Loop::get()->cancel($timer);
                $timer = null;
            }

            $tick = static fn () => null;
        };
    }
}
