<?php

declare(strict_types=1);

namespace Pig\Ai\Utils;

use Pig\Ai\ClassifierModel;
use Pig\Ai\ImageModel;
use Pig\Ai\Model;
use Pig\Ai\Usage;

/**
 * Upstream's `utils/openrouter-cost.ts` (#10286): OpenRouter adds its billing to the usage it
 * streams — `cost` (what it charged), `is_byok` (whether the user's own upstream key was used) and
 * `cost_details` — and that is the cost, where the catalog's rates are an estimate.
 */
final class OpenRouterCost
{
    /** Upstream's `isOpenRouterModel()`: by provider, or by host for a custom provider in front of it. */
    public static function isOpenRouterModel(Model|ClassifierModel|ImageModel $model): bool
    {
        return $model->provider === 'openrouter' || str_contains($model->baseUrl, 'openrouter.ai');
    }

    /**
     * Upstream's `applyOpenRouterReportedCost()`: "Replace the catalog total with OpenRouter's billed
     * cost when the response contains complete, valid accounting." Without BYOK that is `cost`; with
     * it, `cost` plus the upstream inference cost — `upstream_inference_cost`, or its prompt and
     * completion parts when it is null. Anything missing or invalid keeps the catalog total.
     *
     * @param array<string, mixed> $reported the usage object as it arrived
     */
    public static function apply(Usage $usage, array $reported): Usage
    {
        $cost = $reported['cost'] ?? null;

        if (!self::isValidCost($cost)) {
            return $usage;
        }

        $byok = $reported['is_byok'] ?? null;

        if ($byok === false) {
            return $usage->withProviderTotal((float) $cost);
        }

        if ($byok !== true) {
            return $usage;
        }

        $details = $reported['cost_details'] ?? null;

        if (!is_array($details)) {
            return $usage;
        }

        $upstream = $details['upstream_inference_cost'] ?? null;

        if ($upstream === null) {
            $prompt = $details['upstream_inference_prompt_cost'] ?? null;
            $completions = $details['upstream_inference_completions_cost'] ?? null;

            if (!self::isValidCost($prompt) || !self::isValidCost($completions)) {
                return $usage;
            }

            $upstream = $prompt + $completions;
        }

        if (!self::isValidCost($upstream)) {
            return $usage;
        }

        return $usage->withProviderTotal((float) $cost + (float) $upstream);
    }

    private static function isValidCost(mixed $value): bool
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value) && $value >= 0;
    }
}
