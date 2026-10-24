<?php

declare(strict_types=1);

namespace Pig\Ai\Providers;

use Pig\Ai\Http\HttpClient;
use Pig\Ai\Http\Request;
use Pig\Ai\Model;
use Pig\Ai\Pricing;
use Pig\Ai\PricingTier;
use Pig\Async\AbortSignal;
use RuntimeException;

/**
 * The OpenRouter models a key may use — upstream's `personalizeOpenRouterCatalog()` and
 * `openRouterCost()` (`providers/openrouter.ts`, `providers/openrouter-api.ts`, #10672): "Restrict an
 * OpenRouter catalog to the chat models the key may use, with the key's limits and prices from
 * `GET /models/user`. … A missing limit keeps the catalog value. The request uses the provider's
 * configured base URL, so regional endpoints such as `https://us.openrouter.ai/api/v1` apply their
 * region's filtering. When the endpoint rejects the key (401/403) or does not exist (404), for
 * example with a proxy configured in models.json, the catalog is returned unchanged."
 *
 * `Pig\CodingAgent\ModelRefresh` asks for the list on each refresh, saves it in the models store
 * and lays it over the catalog (`Models::personalize()`), offline too.
 */
final class OpenRouterUserModels
{
    public const string BASE_URL = 'https://openrouter.ai/api/v1';

    private const float TIMEOUT_SECONDS = 15.0;

    /**
     * The key's models, each trimmed to the fields pig reads, by id — or null when the endpoint
     * rejects the key or does not exist, and the catalog stays as it is.
     *
     * @return list<array<string, mixed>>|null
     * @throws RuntimeException on any other failure: "OpenRouter model list request failed: <status>"
     */
    public static function fetch(string $key, ?string $baseUrl = null, ?HttpClient $http = null, ?AbortSignal $signal = null): ?array
    {
        $response = ($http ?? new HttpClient(timeout: self::TIMEOUT_SECONDS))->send(new Request(
            'GET',
            rtrim($baseUrl ?? self::BASE_URL, '/') . '/models/user',
            ['accept' => 'application/json', 'authorization' => "Bearer {$key}"],
        ), $signal);
        $text = $response->body->all();

        if (in_array($response->status, [401, 403, 404], true)) {
            return null;
        }

        if (!$response->isSuccessful()) {
            throw new RuntimeException("OpenRouter model list request failed: {$response->status}");
        }

        $body = json_decode($text, true);

        if (!is_array($body) || !is_array($body['data'] ?? null) || !array_is_list($body['data'])) {
            throw new RuntimeException('Invalid OpenRouter model list response');
        }

        $models = [];

        foreach ($body['data'] as $entry) {
            if (is_array($entry) && is_string($entry['id'] ?? null)) {
                $models[] = array_intersect_key($entry, ['id' => true, 'context_length' => true, 'top_provider' => true, 'pricing' => true]);
            }
        }

        return $models;
    }

    /**
     * Upstream's `personalizeOpenRouterCatalog()` over the chat models: those the key lists, with its
     * context size, max output and prices; the rest dropped.
     *
     * @param list<Model>                $catalog
     * @param list<array<string, mixed>> $allowed what `fetch()` answered
     * @return list<Model>
     */
    public static function personalize(array $catalog, array $allowed): array
    {
        $byId = [];

        foreach ($allowed as $entry) {
            if (is_string($entry['id'] ?? null)) {
                $byId[$entry['id']] = $entry;
            }
        }

        $personal = [];

        foreach ($catalog as $model) {
            $entry = $byId[$model->id] ?? null;

            if ($entry === null) {
                continue;
            }

            $top = is_array($entry['top_provider'] ?? null) ? $entry['top_provider'] : [];
            // `||`: "OpenRouter reports a missing limit as absent, null or 0, like the generator's fallback."
            $window = self::positiveInt($top['context_length'] ?? null) ?? self::positiveInt($entry['context_length'] ?? null) ?? $model->contextWindow;
            $maxTokens = self::positiveInt($top['max_completion_tokens'] ?? null) ?? $model->maxTokens;
            $pricing = is_array($entry['pricing'] ?? null) ? self::cost($entry['pricing']) : $model->pricing;

            $personal[] = new Model(
                $model->id,
                $model->name,
                $model->api,
                $model->provider,
                $model->baseUrl,
                $window,
                $maxTokens,
                $model->reasoning,
                $model->input,
                $pricing,
                $model->headers,
                $model->compat,
                $model->thinkingLevelMap,
                $model->inputLimits,
                $model->promptCache,
            );
        }

        return $personal;
    }

    /**
     * Upstream's `openRouterCost()`: "Convert OpenRouter's $/token prices to pi's $/1M tokens.
     * Missing, invalid and negative base prices are 0. Prompt-length overrides become request-wide
     * tiers; a rate a tier does not list keeps the base price. Time-of-day overrides are skipped
     * because ModelCost cannot express them."
     *
     * @param array<string, mixed> $pricing
     */
    public static function cost(array $pricing): Pricing
    {
        $input = self::perMillion($pricing['prompt'] ?? null, 0.0);
        $output = self::perMillion($pricing['completion'] ?? null, 0.0);
        $cacheRead = self::perMillion($pricing['input_cache_read'] ?? null, 0.0);
        $cacheWrite = self::perMillion($pricing['input_cache_write'] ?? null, 0.0);
        $tiers = [];

        foreach (is_array($pricing['overrides'] ?? null) ? $pricing['overrides'] : [] as $override) {
            if (!is_array($override) || !is_int($override['min_prompt_tokens'] ?? null)
                || array_key_exists('utc_start', $override) || array_key_exists('utc_end', $override) || array_key_exists('utc_days', $override)) {
                continue;
            }

            $tiers[] = new PricingTier(
                $override['min_prompt_tokens'],
                self::perMillion($override['prompt'] ?? null, $input),
                self::perMillion($override['completion'] ?? null, $output),
                self::perMillion($override['input_cache_read'] ?? null, $cacheRead),
                self::perMillion($override['input_cache_write'] ?? null, $cacheWrite),
            );
        }

        return new Pricing($input, $output, $cacheRead, $cacheWrite, $tiers);
    }

    /**
     * Upstream's `perMillion()`: "Convert one $/token price to $/1M tokens. 0 means free. Missing,
     * invalid and negative prices use the fallback: OpenRouter reports -1 for router models such as
     * `openrouter/auto`, whose price depends on the model they pick."
     */
    private static function perMillion(mixed $value, float $fallback): float
    {
        $text = is_string($value) ? trim($value) : (is_int($value) || is_float($value) ? (string) $value : '');

        if (preg_match('/^[+-]?(\d+\.?\d*|\.\d+)([eE][+-]?\d+)?/', $text, $match) !== 1) {
            return $fallback;
        }

        $perToken = (float) $match[0];

        return $perToken >= 0 ? round($perToken * 1_000_000, 6) : $fallback;
    }

    private static function positiveInt(mixed $value): ?int
    {
        return (is_int($value) || is_float($value)) && $value > 0 ? (int) $value : null;
    }
}
