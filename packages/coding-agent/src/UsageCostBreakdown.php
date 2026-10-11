<?php

declare(strict_types=1);

namespace Pig\CodingAgent;

use Pig\Ai\AssistantMessage;
use Pig\Ai\ToolResultMessage;
use Pig\Ai\Usage;
use Pig\CodingAgent\Session\BranchSummary;
use Pig\CodingAgent\Session\CompactionSummary;
use Pig\CodingAgent\Session\UsageEntry;

/**
 * Upstream's `getUsageCostBreakdown()` (`core/usage-totals.ts`): what a session cost, per physical
 * model — "Cost/token totals per provider/model actually used (e.g. OpenRouter `auto` resolves to a
 * concrete responseModel). Usage without model attribution is grouped separately so the breakdown
 * reconciles with the session total."
 *
 * The key is `provider/model` of each assistant message — the model the provider says answered when
 * it says one — and of each `usage` entry; a tool result's own usage and a summary's are
 * `Tools/summaries`. A virtual model never appears: its requests are recorded under the models it
 * routed them to, which is what `/session` lists for it.
 */
final class UsageCostBreakdown
{
    /**
     * @param list<mixed> $entries every message of the session file, in order — `SessionManager::everyMessage()`
     * @return list<array{key: string, cost: float, tokens: int}> most expensive first, empty ones left out
     */
    public static function of(array $entries): array
    {
        /** @var array<string, array{cost: float, tokens: int}> $totals */
        $totals = [];

        foreach ($entries as $entry) {
            [$key, $usage] = match (true) {
                $entry instanceof AssistantMessage => ["{$entry->provider}/" . ($entry->responseModel ?? $entry->model), $entry->usage],
                $entry instanceof UsageEntry => ["{$entry->provider}/{$entry->model}", $entry->usage],
                $entry instanceof ToolResultMessage, $entry instanceof CompactionSummary, $entry instanceof BranchSummary => ['Tools/summaries', $entry->usage],
                default => [null, null],
            };

            if ($key === null || !$usage instanceof Usage) {
                continue;
            }

            $totals[$key] ??= ['cost' => 0.0, 'tokens' => 0];
            $totals[$key]['cost'] += $usage->cost->total;
            $totals[$key]['tokens'] += $usage->input + $usage->output + $usage->cacheRead + $usage->cacheWrite;
        }

        $breakdown = [];

        foreach ($totals as $key => $total) {
            if ($total['cost'] > 0 || $total['tokens'] > 0) {
                $breakdown[] = ['key' => (string) $key, ...$total];
            }
        }

        // `Array.sort` is stable, as `usort` is since PHP 8.0: equal costs keep the file's order.
        usort($breakdown, static fn (array $a, array $b): int => $b['cost'] <=> $a['cost']);

        return $breakdown;
    }
}
