<?php

declare(strict_types=1);

namespace Pig\CodingAgent;

use Pig\Ai\Models;

/**
 * Upstream's `preferred-providers.ts` (683a7a86): the preferred partner providers, and the order
 * provider lists show them in — the partners first, in their order, then every other provider
 * alphabetically by name. Free of UI code, as upstream keeps it.
 */
final class PreferredProviders
{
    /** Upstream's `PREFERRED_PROVIDER_IDS`, most preferred first. */
    public const array IDS = [Models::RADIUS, 'openrouter'];

    /** Upstream's `isPreferredProvider()`. */
    public static function isPreferred(string $providerId): bool
    {
        return in_array($providerId, self::IDS, true);
    }

    /**
     * Upstream's `compareProviders()`, for `usort()`. PHP's sort is stable too, so a provider's
     * account row stays above its API key row.
     *
     * @param object{id: string, name: string} $a
     * @param object{id: string, name: string} $b
     */
    public static function compare(object $a, object $b): int
    {
        $rank = static function (string $id): int {
            $index = array_search($id, self::IDS, true);

            return $index === false ? count(self::IDS) : $index;
        };

        return $rank($a->id) <=> $rank($b->id) ?: strcasecmp($a->name, $b->name);
    }
}
