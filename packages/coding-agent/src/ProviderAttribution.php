<?php

declare(strict_types=1);

namespace Pig\CodingAgent;

use Pig\Ai\ClientAttribution;
use Pig\Ai\ClientIdentity;
use Pig\Ai\Model;

/**
 * Upstream's `core/client-identity.ts`, `core/telemetry.ts` and what is left of
 * `core/provider-attribution.ts`: how pig's requests identify it to providers.
 *
 * **pig's name, not pi's**, as the developer asked: a detailed `User-Agent` with pig's version
 * (`pig/<version> (…)`), and attribution to pig as a CLI agent (`ClientAttribution::pig()`:
 * `X-OpenRouter-Title: pig` with `https://pigagent.dev`, `X-BILLING-INVOKE-ORIGIN: Pig`,
 * `x-opencode-client: pig`) while `enableInstallTelemetry` is on (the default), which `PIG_TELEMETRY`
 * overrides either way (`1`/`true`/`yes`, anything else off). And **no install ping**: upstream's
 * `reportInstallTelemetry()` reports to pi.dev, which counts pi's installs, not pig's.
 *
 * The providers send the identity's headers under every other header of a request (`pig/ai`'s
 * `ClientIdentity`), so `before_provider_headers` handlers no longer see the attribution headers;
 * returning null for one still removes it. Cloudflare gets the detailed `User-Agent` like everyone
 * else, where it used to get `pig-coding-agent`.
 */
final class ProviderAttribution
{
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
     * Upstream's `getClientIdentity()`: read per request, so a change of the setting applies at once.
     */
    public static function identity(Settings $settings, string|false|null $environment = null): ClientIdentity
    {
        try {
            $version = Version::current();
        } catch (CodingAgentError) {
            $version = null;
        }

        return new ClientIdentity(
            'detailed',
            self::telemetryEnabled($settings, $environment) ? ClientAttribution::pig() : false,
            $version,
        );
    }

    /**
     * The session's OpenCode routing header with the request's own headers over it — what
     * upstream's `Models` adds before `transformHeaders`, so `before_provider_headers` handlers
     * see it and can change or delete it.
     *
     * @param array<string, string|null>|null $headers
     * @return array<string, string|null>|null
     */
    public static function merge(Model $model, ?string $sessionId, ?array $headers): ?array
    {
        $merged = [...(ClientIdentity::openCodeSessionHeaders($model, $sessionId) ?? []), ...($headers ?? [])];

        return $merged === [] ? null : $merged;
    }
}
