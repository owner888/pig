<?php

declare(strict_types=1);

namespace Pig\Ai;

use Pig\Async\AbortSignal;

/**
 * Upstream's `cache-warming.ts` (#11857): "Prompt cache warming policy shared by pi's hosts: when a
 * refresh is due, whether a request can be replayed, and whether a refresh pays for itself. Hosts own
 * the scheduling; this module owns the numbers." Upstream moved it out of the coding agent's
 * `cache-warmer.ts` into `pi-ai` so its p2 app could warm as pi does; here it moved the same way out
 * of `CodingAgent\Session\CacheWarmer`, which keeps the scheduling, so the two read side by side.
 *
 * The names are upstream's, prefix included, for that reading.
 */
final class CacheWarming
{
    /** Streaming warming never continues past this long after the real request that started it. */
    public const int CACHE_WARMING_MAX_AGE_MS = 60 * 60_000;

    /** Idle warming uses a shorter horizon because continuation estimates become less reliable with age. */
    public const int CACHE_WARMING_MAX_IDLE_AGE_MS = 30 * 60_000;

    /** A refresh is sent only when it is expected to save at least this many dollars. */
    public const float CACHE_WARMING_MINIMUM_EXPECTED_SAVINGS = 0.05;

    /**
     * "Chance that a real request arrives before the cache entry expires while the agent sits idle.
     * Measured from our own usage; per-session estimates were not better than this constant."
     */
    public const float CACHE_WARMING_IDLE_CONTINUATION_PROBABILITY = 0.15;

    /** Refresh at 90% of the TTL while preserving at least ten seconds of margin. */
    public static function getCacheWarmingDelayMs(int $ttlMs): ?int
    {
        if ($ttlMs <= 10_000) {
            return null;
        }

        return max(1, (int) floor(min($ttlMs * 0.9, $ttlMs - 10_000)));
    }

    /**
     * Lifetime of the prompt cache entry a request writes, from the model's `promptCache` tier for
     * the retention the request used (`PI_CACHE_RETENTION` when it named none). Null when the model
     * has no lifetime for that tier or caching is off.
     */
    public static function getPromptCacheTtlMs(Model $model, ?SimpleStreamOptions $options): ?int
    {
        $retention = ($options ?? new SimpleStreamOptions())->resolvedCacheRetention();

        if ($retention === 'none') {
            return null;
        }

        $seconds = $model->promptCache[$retention] ?? null;

        return $seconds === null ? null : (int) ($seconds * 1000);
    }

    /**
     * Whether replaying the request with a one-token output cap leaves its cache entry untouched.
     * "Anthropic's budget-based thinking (Claude models without adaptive thinking) derives
     * `budget_tokens` from `max_tokens`; the replay would get a different budget, which Anthropic keys
     * the message cache on, and the model could still think for thousands of tokens."
     */
    public static function isCacheWarmingReplayable(Model $model, ?SimpleStreamOptions $options): bool
    {
        if ($options?->reasoning === null || $model->api !== Api::AnthropicMessages) {
            return true;
        }

        return $model->compat instanceof AnthropicMessagesCompat && $model->compat->forceAdaptiveThinking === true;
    }

    /**
     * The options of a refresh: the request's own, capped at one output token, without retries,
     * and with `disableContinuation`, so the refresh neither continues the conversation's provider
     * thread nor replaces where its next real request continues from.
     */
    public static function getCacheWarmingRequestOptions(SimpleStreamOptions $options, AbortSignal $signal): SimpleStreamOptions
    {
        return new SimpleStreamOptions(...[
            ...$options->baseArgs(),
            'reasoning' => $options->reasoning,
            'toolChoice' => $options->toolChoice,
            'thinkingBudgets' => $options->thinkingBudgets,
            'maxTokens' => 1,
            'disableContinuation' => true,
            'maxRetries' => 0,
            'signal' => $signal,
        ]);
    }

    /**
     * Decide whether refreshing the cache entry of a `$promptTokens`-token request pays for itself
     * in `$phase`.
     *
     * @param 'streaming'|'idle' $phase
     */
    public static function evaluateCacheWarming(Model $model, int $promptTokens, string $phase): CacheWarmingDecision
    {
        $cacheHitCost = self::price($model, new Usage(cacheRead: $promptTokens));
        $cacheMissCost = self::price($model, $model->pricing->cacheWrite > 0 ? new Usage(cacheWrite: $promptTokens) : new Usage(input: $promptTokens));
        $warmCost = self::price($model, new Usage(output: 1, cacheRead: $promptTokens));
        $missCost = max(0.0, $cacheMissCost - $cacheHitCost);
        $continuationProbability = $phase === 'idle' ? self::CACHE_WARMING_IDLE_CONTINUATION_PROBABILITY : 1.0;
        $economicsAvailable = $promptTokens > 0 && ($cacheHitCost > 0 || $cacheMissCost > 0);
        $expectedSavings = $continuationProbability * $missCost - $warmCost;

        return new CacheWarmingDecision(
            $phase,
            $warmCost,
            $missCost,
            $continuationProbability,
            $expectedSavings,
            $economicsAvailable,
            $expectedSavings >= self::CACHE_WARMING_MINIMUM_EXPECTED_SAVINGS ? 'warm' : 'stop',
        );
    }

    /** Upstream's `price()`: `calculateCost(model, usage).total` for these tokens alone. */
    private static function price(Model $model, Usage $usage): float
    {
        return $usage->withCost($model)->cost->total;
    }
}
