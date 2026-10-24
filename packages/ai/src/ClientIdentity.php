<?php

declare(strict_types=1);

namespace Pig\Ai;

use Composer\InstalledVersions;
use Pig\Ai\Utils\PigUserAgent;

/**
 * How `pig/ai` identifies the calling app to providers — upstream's `ClientIdentity`
 * (`utils/identity.ts`): the `User-Agent`, and the attribution headers OpenRouter, NVIDIA NIM and
 * OpenCode read. Every field is optional, and an absent one is the default: a detailed
 * `User-Agent` with pig's version and no attribution headers.
 *
 * Providers send `headers()` below the model's, the provider's and the request's own headers, so a
 * configured header overrides them and a null request header removes one.
 */
final readonly class ClientIdentity
{
    /**
     * @param 'short'|'detailed'|array{custom: string}|null $userAgent upstream's `UserAgentMode`:
     *        `detailed` is `pig/<version> (<platform>; php/<version>; <arch>)`, `short` is `pig`,
     *        `['custom' => …]` the given value. Null: not said (`detailed`)
     * @param ClientAttribution|false|null $attribution the attribution headers' source; false turns
     *        them off, also over a merged identity that set them (upstream's `null`); null: not said
     * @param string|null $version the version in the detailed `User-Agent`; null: pig's own
     */
    public function __construct(
        public string|array|null $userAgent = null,
        public ClientAttribution|false|null $attribution = null,
        public ?string $version = null,
    ) {
    }

    /** Upstream's `mergeClientIdentity()`: field by field, later sources winning, unsaid fields skipped. */
    public static function merge(?self ...$sources): ?self
    {
        $merged = null;

        foreach ($sources as $source) {
            if ($source === null) {
                continue;
            }

            $merged = new self(
                $source->userAgent ?? $merged?->userAgent,
                $source->attribution ?? $merged?->attribution,
                $source->version ?? $merged?->version,
            );
        }

        return $merged;
    }

    /** Upstream's `getClientVersion()`: the identity's, else pig's own as Composer installed it. */
    public static function version(?self $identity = null): ?string
    {
        if ($identity?->version !== null) {
            return $identity->version;
        }

        try {
            $version = class_exists(InstalledVersions::class) ? InstalledVersions::getPrettyVersion('pigagent/pig') : null;
        } catch (\OutOfBoundsException) {
            $version = null;
        }

        return $version === null || $version === '' ? null : ltrim($version, 'v');
    }

    /**
     * Upstream's `formatUserAgent()`. The runtime is PHP's where upstream names Node's or Bun's; a
     * build Composer cannot name a version for says `pig` alone before the parentheses.
     */
    public static function formatUserAgent(?self $identity = null): string
    {
        $mode = $identity?->userAgent ?? 'detailed';

        if (is_array($mode)) {
            return $mode['custom'];
        }

        if ($mode === 'short') {
            return 'pig';
        }

        $version = self::version($identity);

        return ($version === null ? 'pig' : "pig/{$version}") . ' (' . PigUserAgent::platform() . '; php/' . PHP_VERSION . '; ' . PigUserAgent::arch() . ')';
    }

    /**
     * Upstream's `getAttributionHeaders()`: OpenRouter's three, NVIDIA's invoke origin (first letter
     * upper-cased: `Pig`), OpenCode's client; nothing for anyone else, or with attribution off.
     *
     * @return array<string, string>
     */
    public static function attributionHeaders(Model|ClassifierModel|ImageModel $target, ?self $identity = null): array
    {
        $attribution = $identity?->attribution;

        if (!$attribution instanceof ClientAttribution) {
            return [];
        }

        if (self::isOpenRouter($target)) {
            return [
                'HTTP-Referer' => $attribution->url,
                'X-OpenRouter-Title' => $attribution->name,
                ...($attribution->categories === [] ? [] : ['X-OpenRouter-Categories' => implode(',', $attribution->categories)]),
            ];
        }

        if (self::isNvidiaNim($target)) {
            return ['X-BILLING-INVOKE-ORIGIN' => ucfirst($attribution->name)];
        }

        if (self::isOpenCode($target)) {
            return ['x-opencode-client' => $attribution->name];
        }

        return [];
    }

    /**
     * Upstream's `getIdentityHeaders()`: the `User-Agent` and the attribution headers — the
     * defaults a provider puts under every other header of a request.
     *
     * @return array<string, string>
     */
    public static function headers(Model|ClassifierModel|ImageModel $target, ?self $identity = null): array
    {
        return ['User-Agent' => self::formatUserAgent($identity), ...self::attributionHeaders($target, $identity)];
    }

    /**
     * Upstream's `getOpenCodeSessionHeaders()`: OpenCode's per-conversation routing header, for
     * custom providers pointing at OpenCode too; null without a session or for anyone else.
     *
     * @return array<string, string>|null
     */
    public static function openCodeSessionHeaders(Model|ClassifierModel|ImageModel $target, ?string $sessionId): ?array
    {
        return $sessionId !== null && $sessionId !== '' && self::isOpenCode($target) ? ['x-opencode-session' => $sessionId] : null;
    }

    private static function isOpenRouter(Model|ClassifierModel|ImageModel $target): bool
    {
        return $target->provider === 'openrouter' || str_contains($target->baseUrl, 'openrouter.ai');
    }

    private static function isNvidiaNim(Model|ClassifierModel|ImageModel $target): bool
    {
        return $target->provider === 'nvidia' || self::matchesHost($target->baseUrl, 'integrate.api.nvidia.com');
    }

    private static function isOpenCode(Model|ClassifierModel|ImageModel $target): bool
    {
        return in_array($target->provider, ['opencode', 'opencode-go'], true) || self::matchesHost($target->baseUrl, 'opencode.ai');
    }

    /** Upstream's `matchesHost()`: the URL's host exactly, false for one that does not parse. */
    private static function matchesHost(string $baseUrl, string $host): bool
    {
        $parsed = parse_url($baseUrl, PHP_URL_HOST);

        return is_string($parsed) && strtolower($parsed) === $host;
    }
}
