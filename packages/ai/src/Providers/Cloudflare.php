<?php

declare(strict_types=1);

namespace Pig\Ai\Providers;

use Pig\Ai\ClassifierModel;
use Pig\Ai\Model;
use Pig\Ai\StreamOptions;

/**
 * Cloudflare's two providers — upstream's `api/cloudflare.ts` (the endpoints),
 * `providers/cloudflare-auth.ts` (what a request needs before it can go) and
 * `providers/cloudflare-stream.ts` (the endpoint's placeholders filled in before dispatch).
 *
 * - **Workers AI** (`cloudflare-workers-ai`): its chat models on the OpenAI-compatible
 *   `/ai/v1` endpoint, its System One classifiers on the REST `/ai/run`; a key and an account id.
 * - **AI Gateway** (`cloudflare-ai-gateway`): the gateway's `/compat` (Workers AI models, Chat
 *   Completions), `/openai` (Responses) and `/anthropic` (Messages) passthroughs; a key, an account id
 *   and a gateway id, and the key goes as `cf-aig-authorization` with the SDKs' own auth headers
 *   suppressed — "the gateway … would treat a request-supplied auth header as a BYOK provider key".
 *
 * Every base URL carries `{CLOUDFLARE_ACCOUNT_ID}` (and the gateway's `{CLOUDFLARE_GATEWAY_ID}`),
 * which `resolveCloudflareModel()` fills in from the provider env — upstream's `cloudflareStreams()`
 * and `cloudflareClassifier()` wrappers, applied by `Stream::start()` and `Models::classify()` for
 * either provider.
 *
 * Upstream's sign-in (`login`, which asks for the key and the ids and stores the ids as the
 * credential's `env`) is not ported: pig's `Auth` keeps a key and no env, so the ids come from the
 * environment or `StreamOptions::$env`.
 */
final class Cloudflare
{
    public const string WORKERS_AI = 'cloudflare-workers-ai';

    public const string AI_GATEWAY = 'cloudflare-ai-gateway';

    /** "Workers AI direct endpoint." */
    public const string CLOUDFLARE_WORKERS_AI_BASE_URL = 'https://api.cloudflare.com/client/v4/accounts/{CLOUDFLARE_ACCOUNT_ID}/ai/v1';

    /** "Workers AI REST endpoint root; model runs are posted to `run`." */
    public const string CLOUDFLARE_WORKERS_AI_REST_BASE_URL = 'https://api.cloudflare.com/client/v4/accounts/{CLOUDFLARE_ACCOUNT_ID}/ai';

    /** "AI Gateway Unified API. https://developers.cloudflare.com/ai-gateway/usage/unified-api/" */
    public const string CLOUDFLARE_AI_GATEWAY_COMPAT_BASE_URL = 'https://gateway.ai.cloudflare.com/v1/{CLOUDFLARE_ACCOUNT_ID}/{CLOUDFLARE_GATEWAY_ID}/compat';

    /** "AI Gateway → OpenAI passthrough. Used until /compat supports /v1/responses." */
    public const string CLOUDFLARE_AI_GATEWAY_OPENAI_BASE_URL = 'https://gateway.ai.cloudflare.com/v1/{CLOUDFLARE_ACCOUNT_ID}/{CLOUDFLARE_GATEWAY_ID}/openai';

    /** "AI Gateway → Anthropic passthrough." */
    public const string CLOUDFLARE_AI_GATEWAY_ANTHROPIC_BASE_URL = 'https://gateway.ai.cloudflare.com/v1/{CLOUDFLARE_ACCOUNT_ID}/{CLOUDFLARE_GATEWAY_ID}/anthropic';

    private const string CLOUDFLARE_API_KEY = 'CLOUDFLARE_API_KEY';

    private const string CLOUDFLARE_ACCOUNT_ID = 'CLOUDFLARE_ACCOUNT_ID';

    private const string CLOUDFLARE_GATEWAY_ID = 'CLOUDFLARE_GATEWAY_ID';

    public static function isCloudflare(string $provider): bool
    {
        return $provider === self::WORKERS_AI || $provider === self::AI_GATEWAY;
    }

    /**
     * Upstream's `resolveCloudflareEnv(kind, ctx, credential)`: the key (the credential's, else
     * `CLOUDFLARE_API_KEY`), the account id and — for the gateway — the gateway id, each from the
     * provider env first ("Per-field merge: prefer the credential value, fall back to ambient env"),
     * or null when any is missing, which upstream's `Models` reports as "Provider is not configured".
     *
     * @param array<string, string>|null $env
     * @return array{apiKey: string, env: array<string, string>}|null
     */
    public static function resolveCloudflareEnv(string $provider, ?string $apiKey, ?array $env): ?array
    {
        $apiKey = $apiKey !== null && $apiKey !== '' ? $apiKey : StreamOptions::providerEnvValue(self::CLOUDFLARE_API_KEY, $env);
        $accountId = StreamOptions::providerEnvValue(self::CLOUDFLARE_ACCOUNT_ID, $env);
        $gatewayId = $provider === self::AI_GATEWAY ? StreamOptions::providerEnvValue(self::CLOUDFLARE_GATEWAY_ID, $env) : null;

        if ($apiKey === null || $accountId === null || ($provider === self::AI_GATEWAY && $gatewayId === null)) {
            return null;
        }

        return [
            'apiKey' => $apiKey,
            'env' => [self::CLOUDFLARE_ACCOUNT_ID => $accountId, ...($gatewayId !== null ? [self::CLOUDFLARE_GATEWAY_ID => $gatewayId] : [])],
        ];
    }

    /**
     * What `cloudflareAIGatewayAuth().resolve()` adds to the request's headers, under the caller's:
     * the key as `cf-aig-authorization`, and the SDKs' `Authorization` and `x-api-key` suppressed.
     *
     * @return array<string, string|null>
     */
    public static function gatewayAuthHeaders(string $apiKey): array
    {
        return ['cf-aig-authorization' => "Bearer {$apiKey}", 'Authorization' => null, 'x-api-key' => null];
    }

    /**
     * Upstream's `mergeHeaders(auth.headers, options.headers)`: the caller's headers replace the
     * auth's of the same name, whatever its case.
     *
     * @param array<string, string|null> $base
     * @param array<string, string|null>|null $override
     * @return array<string, string|null>
     */
    public static function mergeHeaders(array $base, ?array $override): array
    {
        $merged = $base;

        foreach ($override ?? [] as $name => $value) {
            foreach (array_keys($merged) as $existing) {
                if (strtolower((string) $existing) === strtolower((string) $name)) {
                    unset($merged[$existing]);
                }
            }

            $merged[$name] = $value;
        }

        return $merged;
    }

    /**
     * Upstream's `resolveCloudflareModel(model, env)`: each placeholder replaced by its env value, or
     * left where there is none; the model as it was when nothing changed.
     *
     * @param array<string, string>|null $env
     */
    public static function resolveCloudflareModel(Model|ClassifierModel $model, ?array $env): Model|ClassifierModel
    {
        if ($env === null) {
            return $model;
        }

        $baseUrl = str_replace(
            ['{' . self::CLOUDFLARE_ACCOUNT_ID . '}', '{' . self::CLOUDFLARE_GATEWAY_ID . '}'],
            [$env[self::CLOUDFLARE_ACCOUNT_ID] ?? '{' . self::CLOUDFLARE_ACCOUNT_ID . '}', $env[self::CLOUDFLARE_GATEWAY_ID] ?? '{' . self::CLOUDFLARE_GATEWAY_ID . '}'],
            $model->baseUrl,
        );

        if ($baseUrl === $model->baseUrl) {
            return $model;
        }

        return $model instanceof ClassifierModel
            ? $model->withBaseUrl($baseUrl)
            : new Model(
                $model->id,
                $model->name,
                $model->api,
                $model->provider,
                $baseUrl,
                $model->contextWindow,
                $model->maxTokens,
                $model->reasoning,
                $model->input,
                $model->pricing,
                $model->headers,
                $model->compat,
                $model->thinkingLevelMap,
                $model->inputLimits,
                $model->promptCache,
            );
    }
}
