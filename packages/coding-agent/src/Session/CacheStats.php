<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Session;

use Pig\Ai\AssistantMessage;
use Pig\Ai\Models;
use SplObjectStorage;

/**
 * Upstream's `core/cache-stats.ts`: prompt tokens that were in the previous request's prompt and
 * were billed again rather than read from the cache.
 *
 * The entries are `SessionManager::everyMessage()` — the file, in file order — so a compaction and
 * a branch summary reset the scan (the context legitimately changed) and a cache-warming refresh
 * counts as the request that last refreshed the cache. A model switch is *not* exempt: it re-bills
 * the whole prompt and is worth saying.
 */
final class CacheStats
{
    /** Prompt-cache TTL: an idle gap longer than this is worth naming as the likely cause of a miss. */
    public const int CACHE_TTL_MS = 5 * 60 * 1000;

    /** A per-turn miss at or below this is cache breakpoint granularity noise. */
    private const int NOISE_FLOOR_TOKENS = 1024;

    /**
     * Upstream's `computeCacheWaste()`.
     *
     * @param list<mixed> $entries
     * @return array{missedTokens: int, missedCost: float, missCount: int}
     */
    public static function waste(array $entries): array
    {
        return self::scan($entries)[1];
    }

    /**
     * Upstream's `collectCacheMisses()`: every counted miss, keyed by the message that paid for it,
     * to draw the notices again when a transcript is rebuilt from the file.
     *
     * @param list<mixed> $entries
     * @return SplObjectStorage<AssistantMessage, CacheMiss>
     */
    public static function collect(array $entries): SplObjectStorage
    {
        return self::scan($entries)[2];
    }

    /**
     * Upstream's `detectCacheMiss()`: the miss on a just-finished message. The scan stops at the
     * message itself when the file already holds it, which upstream's does not yet at that moment.
     *
     * @param list<mixed> $entries
     */
    public static function detect(array $entries, AssistantMessage $message): ?CacheMiss
    {
        $before = [];

        foreach ($entries as $entry) {
            if ($entry === $message) {
                break;
            }

            $before[] = $entry;
        }

        return self::miss(self::scan($before)[0], $message);
    }

    /**
     * @param list<mixed> $entries
     * @return array{0: ?array{promptTokens: int, modelKey: string, timestamp: int, reportedCache: bool}, 1: array{missedTokens: int, missedCost: float, missCount: int}, 2: SplObjectStorage<AssistantMessage, CacheMiss>}
     */
    private static function scan(array $entries): array
    {
        $previous = null;
        $totals = ['missedTokens' => 0, 'missedCost' => 0.0, 'missCount' => 0];
        /** @var SplObjectStorage<AssistantMessage, CacheMiss> $misses */
        $misses = new SplObjectStorage();

        foreach ($entries as $entry) {
            if ($entry instanceof CompactionSummary || $entry instanceof BranchSummary) {
                $previous = null;

                continue;
            }

            if ($entry instanceof UsageEntry && $entry->kind === 'cache_warm') {
                $promptTokens = $entry->usage->input + $entry->usage->cacheRead + $entry->usage->cacheWrite;

                if ($promptTokens > 0) {
                    $previous = [
                        'promptTokens' => $promptTokens,
                        'modelKey' => "{$entry->provider}/{$entry->model}",
                        'timestamp' => $entry->timestamp,
                        'reportedCache' => true,
                    ];
                }

                continue;
            }

            if (!$entry instanceof AssistantMessage) {
                continue;
            }

            $miss = self::miss($previous, $entry);

            if ($miss !== null) {
                $totals['missedTokens'] += $miss->missedTokens;
                $totals['missedCost'] += $miss->missedCost;
                $totals['missCount']++;
                $misses[$entry] = $miss;
            }

            $previous = self::asPrevious($entry, $previous['reportedCache'] ?? false) ?? $previous;
        }

        return [$previous, $totals, $misses];
    }

    /** @param array{promptTokens: int, modelKey: string, timestamp: int, reportedCache: bool}|null $previous */
    private static function miss(?array $previous, AssistantMessage $message): ?CacheMiss
    {
        $usage = $message->usage;
        $promptTokens = $usage->input + $usage->cacheRead + $usage->cacheWrite;

        // A turn with no cache traffic counts only when some was reported before: on a provider
        // that reports reads alone that is a total miss, on one that never reports caching it
        // means nothing.
        if ($previous === null || $promptTokens <= 0 || ($usage->cacheRead + $usage->cacheWrite === 0 && !$previous['reportedCache'])) {
            return null;
        }

        $missedTokens = min($previous['promptTokens'], $promptTokens) - $usage->cacheRead;

        if ($missedTokens <= self::NOISE_FLOOR_TOKENS) {
            return null;
        }

        // The missed tokens can only have landed in input or cache write, so the rate paid comes
        // from this message's own cost breakdown.
        $paidTokens = $usage->input + $usage->cacheWrite;
        $paidPerToken = $paidTokens > 0 ? ($usage->cost->input + $usage->cost->cacheWrite) / $paidTokens : 0.0;
        $readPerToken = $usage->cacheRead > 0
            ? $usage->cost->cacheRead / $usage->cacheRead
            : (Models::find($message->provider, $message->model)?->pricing->cacheRead ?? 0.0) / 1_000_000;

        return new CacheMiss(
            $missedTokens,
            $missedTokens * max(0.0, $paidPerToken - $readPerToken),
            max(0, $message->timestamp - $previous['timestamp']),
            "{$message->provider}/{$message->model}" !== $previous['modelKey'],
        );
    }

    /** @return array{promptTokens: int, modelKey: string, timestamp: int, reportedCache: bool}|null */
    private static function asPrevious(AssistantMessage $message, bool $reportedCache): ?array
    {
        $usage = $message->usage;
        $promptTokens = $usage->input + $usage->cacheRead + $usage->cacheWrite;

        if ($promptTokens <= 0) {
            return null;
        }

        return [
            'promptTokens' => $promptTokens,
            'modelKey' => "{$message->provider}/{$message->model}",
            'timestamp' => $message->timestamp,
            'reportedCache' => $reportedCache || $usage->cacheRead + $usage->cacheWrite > 0,
        ];
    }
}
