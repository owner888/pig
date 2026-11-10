<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Interactive;

use Closure;
use Pig\Async\Loop;
use Pig\CodingAgent\Theme\Themes;
use Pig\Tui\Colors;
use Pig\Tui\TUI;

/**
 * Upstream's `provider-brand.ts` (683a7a86): animated brand styles for the names of the preferred
 * partner providers in selectors — OpenRouter glows in its brand color (upstream's Radius shimmer
 * is not here, as pig has no Radius). Selectors apply them to the selected row only.
 */
final class ProviderBrand
{
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
            'openrouter' => self::openRouterGlow(...),
            default => null,
        };
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
