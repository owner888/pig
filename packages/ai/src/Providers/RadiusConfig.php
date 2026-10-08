<?php

declare(strict_types=1);

namespace Pig\Ai\Providers;

use Pig\Ai\Api;
use Pig\Ai\Http\HttpClient;
use Pig\Ai\Http\Request;
use Pig\Ai\Model;
use Pig\Ai\Pricing;
use Pig\Ai\PricingTier;
use Pig\Ai\ProviderError;
use Pig\Ai\Utils\JsJson;
use Pig\Async\AbortSignal;
use stdClass;

/**
 * The Radius gateway's catalogue — upstream's `providers/radius-config.ts`, the chat-model half.
 *
 * The gateway answers `GET <gateway>/v1/config` with `{baseUrl, models}`; each model there becomes a
 * `pi-messages` model at that `baseUrl` (`getRadiusModelsFromConfig()`). Upstream's generator reads the
 * unauthenticated catalogue this way to write `RADIUS_MODELS`, and pig's does the same.
 *
 * What is not here: `getRadiusCredentialConfig()` / `getRadiusModels()`, which read a catalogue cached
 * on a Radius OAuth credential (`gatewayConfig`), and the provider's `refreshModels()` that asks the
 * gateway again with the account's key and publishes the answer into the models store — pig has no
 * Radius sign-in and no models store.
 */
final class RadiusConfig
{
    /** Upstream's `DEFAULT_RADIUS_GATEWAY`. */
    public const string DEFAULT_RADIUS_GATEWAY = 'https://radius.pi.dev';

    /**
     * Upstream's `isRadiusGatewayModel()`: an object with a string `id` and `name`, a boolean
     * `reasoning`, an `input` list, a `cost` object and numeric `contextWindow` and `maxTokens`.
     * Read off the JSON as objects (`stdClass`), so `{}` and `[]` stay told apart as JavaScript tells them.
     */
    private static function isRadiusGatewayModel(mixed $value): bool
    {
        if (!$value instanceof stdClass) {
            return false;
        }

        $number = static fn (mixed $v): bool => is_int($v) || is_float($v);

        return is_string($value->id ?? null)
            && is_string($value->name ?? null)
            && is_bool($value->reasoning ?? null)
            && is_array($value->input ?? null)
            && ($value->cost ?? null) instanceof stdClass
            && $number($value->contextWindow ?? null)
            && $number($value->maxTokens ?? null);
    }

    /**
     * Upstream's `sanitizeRadiusGatewayConfig()`: null unless it is an object with a string `baseUrl`
     * and a `models` list, whose entries that are not gateway models are dropped. Takes the JSON
     * decoded as objects (`json_decode($text, false)`) and answers in arrays. Public for the
     * generator's `--radius-from`, which reads a saved answer the same way.
     *
     * @return array{baseUrl: string, models: list<array<string, mixed>>}|null
     */
    public static function sanitizeRadiusGatewayConfig(mixed $config): ?array
    {
        if (!$config instanceof stdClass) {
            return null;
        }

        $baseUrl = $config->baseUrl ?? null;
        $models = $config->models ?? null;

        if (!is_string($baseUrl) || !is_array($models)) {
            return null;
        }

        $kept = [];

        foreach ($models as $model) {
            if (self::isRadiusGatewayModel($model)) {
                // `{...model}`: the object's own fields, as arrays from here on.
                $kept[] = json_decode((string) json_encode($model), true);
            }
        }

        return ['baseUrl' => $baseUrl, 'models' => $kept];
    }

    /** Upstream's `normalizeRadiusGatewayUrl()`: `https://` when there is no scheme, no trailing slashes. */
    public static function normalizeRadiusGatewayUrl(string $value): string
    {
        $withScheme = preg_match('~^https?://~iu', $value) === 1 ? $value : "https://{$value}";

        return (string) preg_replace('~/+$~u', '', $withScheme);
    }

    /**
     * Upstream's `getRadiusModelsFromConfig()`: every gateway model as a `pi-messages` model under
     * `$providerId`, at the config's `baseUrl` — its own `thinkingLevelMap` and `cost.tiers` kept.
     *
     * @param array{baseUrl: string, models: list<array<string, mixed>>} $config
     * @return list<Model>
     */
    public static function getRadiusModelsFromConfig(string $providerId, array $config): array
    {
        $models = [];

        foreach ($config['models'] as $model) {
            $cost = $model['cost'];
            $tiers = [];

            foreach (is_array($cost['tiers'] ?? null) ? $cost['tiers'] : [] as $tier) {
                $tiers[] = new PricingTier(
                    (int) $tier['inputTokensAbove'],
                    (float) $tier['input'],
                    (float) $tier['output'],
                    (float) $tier['cacheRead'],
                    (float) $tier['cacheWrite'],
                );
            }

            $models[] = new Model(
                $model['id'],
                $model['name'],
                Api::PiMessages,
                $providerId,
                $config['baseUrl'],
                (int) $model['contextWindow'],
                (int) $model['maxTokens'],
                $model['reasoning'],
                $model['input'],
                new Pricing((float) ($cost['input'] ?? 0), (float) ($cost['output'] ?? 0), (float) ($cost['cacheRead'] ?? 0), (float) ($cost['cacheWrite'] ?? 0), $tiers),
                thinkingLevelMap: is_array($model['thinkingLevelMap'] ?? null) ? $model['thinkingLevelMap'] : [],
            );
        }

        return $models;
    }

    /** Upstream's `truncateHttpBody()`: trimmed, and cut to 512 UTF-16 units with `…` after. */
    private static function truncateHttpBody(string $body): string
    {
        $trimmed = JsJson::trim($body);
        $units = (string) mb_convert_encoding($trimmed, 'UTF-16LE', 'UTF-8');

        if (strlen($units) <= 1024) {
            return $trimmed;
        }

        return mb_convert_encoding(substr($units, 0, 1024), 'UTF-8', 'UTF-16LE') . '…';
    }

    /**
     * Upstream's `loadRadiusGatewayConfig(gateway, apiKey, signal)`: `GET new URL("/v1/config",
     * gateway)` with `accept: application/json` and the key as a bearer token when there is one.
     *
     * @return array{baseUrl: string, models: list<array<string, mixed>>}
     */
    public static function loadRadiusGatewayConfig(
        string $gateway,
        ?string $apiKey = null,
        ?AbortSignal $signal = null,
        HttpClient $http = new HttpClient(),
    ): array {
        $headers = ['accept' => 'application/json'];

        if ($apiKey !== null && $apiKey !== '') {
            $headers['authorization'] = "Bearer {$apiKey}";
        }

        $response = $http->send(new Request('GET', self::configUrl($gateway), $headers), $signal);
        $text = $response->body->all();

        if (!$response->isSuccessful()) {
            throw new ProviderError("Could not load Radius config from {$gateway}: {$response->status}: " . self::truncateHttpBody($text));
        }

        // `await response.json()`, which rejects a body that is not JSON.
        $config = self::sanitizeRadiusGatewayConfig(JsJson::parse($text, false));

        if ($config === null) {
            throw new ProviderError("Invalid Radius config from {$gateway}");
        }

        return $config;
    }

    /** `new URL("/v1/config", gateway)`: the gateway's origin, whatever path it was given with. */
    private static function configUrl(string $gateway): string
    {
        $parts = parse_url($gateway);

        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            throw new ProviderError("Invalid URL: {$gateway}");
        }

        $port = isset($parts['port']) ? ':' . $parts['port'] : '';

        return strtolower($parts['scheme']) . '://' . strtolower($parts['host']) . $port . '/v1/config';
    }
}
