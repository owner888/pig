<?php

declare(strict_types=1);

namespace Pig\CodingAgent;

use Pig\Ai\Model;

/**
 * Upstream's `core/provider-attribution.ts` and `core/telemetry.ts`: the headers that tell
 * OpenRouter, NVIDIA NIM, Cloudflare and OpenCode which client is calling.
 *
 * **pig's name, not pi's**, as the developer asked: `X-OpenRouter-Title: pig` with `https://pigagent.dev`,
 * `X-BILLING-INVOKE-ORIGIN: Pig`, `User-Agent: pig-coding-agent` and `x-opencode-client: pig` —
 * a request says what sent it, as `PigUserAgent` does. And **no install ping**: upstream's
 * `reportInstallTelemetry()` reports to pi.dev, which counts pi's installs, not pig's.
 *
 * The first three ride on `enableInstallTelemetry` (on by default), which `PIG_TELEMETRY`
 * overrides either way (`1`/`true`/`yes`, anything else off). OpenCode's client header goes
 * beside its session header whatever the setting, as upstream's `getSessionHeaders()` does.
 */
final class ProviderAttribution
{
    private const string OPENROUTER_HOST = 'openrouter.ai';

    private const string NVIDIA_NIM_HOST = 'integrate.api.nvidia.com';

    private const string CLOUDFLARE_API_HOST = 'api.cloudflare.com';

    private const string CLOUDFLARE_AI_GATEWAY_HOST = 'gateway.ai.cloudflare.com';

    private const string OPENCODE_HOST = 'opencode.ai';

    /**
     * Upstream's `isInstallTelemetryEnabled()`: the environment when it says anything, else the
     * setting.
     */
    public static function telemetryEnabled(Settings $settings, string|false|null $environment = null): bool
    {
        $value = $environment ?? getenv('PIG_TELEMETRY');

        if ($value === false) {
            return $settings->installTelemetry();
        }

        return in_array(strtolower($value), ['1', 'true', 'yes'], true);
    }

    /**
     * Upstream's `mergeProviderAttributionHeaders()`: the session's and the attribution headers,
     * with the request's own over them — so a caller can still change or delete any of them.
     *
     * @param array<string, string|null>|null $headers
     * @return array<string, string|null>|null
     */
    public static function merge(Model $model, Settings $settings, ?string $sessionId, ?array $headers, string|false|null $environment = null): ?array
    {
        $merged = [
            ...self::sessionHeaders($model, $sessionId),
            ...self::defaultHeaders($model, $settings, $environment),
            ...($headers ?? []),
        ];

        return $merged === [] ? null : $merged;
    }

    /** @return array<string, string> */
    private static function defaultHeaders(Model $model, Settings $settings, string|false|null $environment): array
    {
        if (!self::telemetryEnabled($settings, $environment)) {
            return [];
        }

        if ($model->provider === 'openrouter' || str_contains($model->baseUrl, self::OPENROUTER_HOST)) {
            return [
                'HTTP-Referer' => 'https://pigagent.dev',
                'X-OpenRouter-Title' => 'pig',
                'X-OpenRouter-Categories' => 'cli-agent',
            ];
        }

        if ($model->provider === 'nvidia' || self::matchesHost($model->baseUrl, self::NVIDIA_NIM_HOST)) {
            return ['X-BILLING-INVOKE-ORIGIN' => 'Pig'];
        }

        if (in_array($model->provider, ['cloudflare-workers-ai', 'cloudflare-ai-gateway'], true)
            || self::matchesHost($model->baseUrl, self::CLOUDFLARE_API_HOST)
            || self::matchesHost($model->baseUrl, self::CLOUDFLARE_AI_GATEWAY_HOST)) {
            return ['User-Agent' => 'pig-coding-agent'];
        }

        return [];
    }

    /**
     * Upstream's `getSessionHeaders()`. `x-opencode-session` is also what `pig/ai` adds on its own
     * (`OpenCodeHeaders`), as upstream's provider wrapper does; the same value either way.
     *
     * @return array<string, string>
     */
    private static function sessionHeaders(Model $model, ?string $sessionId): array
    {
        if ($sessionId === null || $sessionId === '') {
            return [];
        }

        if (!in_array($model->provider, ['opencode', 'opencode-go'], true) && !self::matchesHost($model->baseUrl, self::OPENCODE_HOST)) {
            return [];
        }

        return ['x-opencode-session' => $sessionId, 'x-opencode-client' => 'pig'];
    }

    /** Upstream's `matchesHost()`: the URL's host exactly, and false for one that does not parse. */
    private static function matchesHost(string $baseUrl, string $host): bool
    {
        $parsed = parse_url($baseUrl, PHP_URL_HOST);

        return is_string($parsed) && strtolower($parsed) === $host;
    }
}
