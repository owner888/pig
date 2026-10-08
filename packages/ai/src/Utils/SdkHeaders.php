<?php

declare(strict_types=1);

namespace Pig\Ai\Utils;

/**
 * The headers upstream's pinned SDKs add to every request on their own, which pig — having no SDK —
 * has to write itself to send what upstream sends.
 *
 * The versions are the ones `packages/ai/package.json` pins (`@anthropic-ai/sdk` 0.129.0, `openai`
 * 7.19.0, `@google/genai` 2.21.0), because those are the SDKs whose wire behaviour pig reproduces —
 * the retry count, the timeout, the auth headers.
 *
 * **What describes the runtime says PHP.** The Stainless SDKs' `X-Stainless-Runtime` /
 * `X-Stainless-Runtime-Version` and the Google SDK's `gl-node/<process.version>` are statements about
 * the language runtime making the request; sending `node` and a Node version from a PHP process
 * would be a lie a provider's telemetry would believe. So those say `php` / `PHP_VERSION`, and
 * Google's token is `gl-php/<version>` — the spelling Google's own PHP client libraries use for
 * `x-goog-api-client`. Everything else is the SDK's value as it would compute it on this machine:
 * `X-Stainless-Lang: js` and the package version (they name the SDK whose behaviour this is, not the
 * runtime), and `X-Stainless-OS` / `X-Stainless-Arch` from the same `process.platform` /
 * `process.arch` spellings `PigUserAgent` already derives, normalised as the SDK normalises them.
 */
final class SdkHeaders
{
    /** `@anthropic-ai/sdk`'s `VERSION`, as pinned upstream. */
    public const string ANTHROPIC_SDK_VERSION = '0.129.0';

    /** `openai`'s `VERSION`, as pinned upstream. */
    public const string OPENAI_SDK_VERSION = '7.19.0';

    /** `@google/genai`'s `SDK_VERSION`, as pinned upstream. */
    public const string GOOGLE_GENAI_SDK_VERSION = '2.21.0';

    /** Both Stainless SDKs' `DEFAULT_TIMEOUT`: "10 minutes". */
    public const int STAINLESS_DEFAULT_TIMEOUT_MS = 600_000;

    /**
     * The Stainless SDKs' `buildHeaders()` first source: `Accept`, the SDK's own `User-Agent`,
     * `X-Stainless-Retry-Count` (always 0 — "Each retry is a fresh SDK request"),
     * `X-Stainless-Timeout` (`Math.trunc(timeout / 1000)`), then `getPlatformHeaders()`.
     *
     * @return array<string, string>
     */
    public static function stainless(string $userAgent, string $packageVersion, int $timeoutMs): array
    {
        return [
            'Accept' => 'application/json',
            'User-Agent' => $userAgent,
            'X-Stainless-Retry-Count' => '0',
            'X-Stainless-Timeout' => (string) intdiv($timeoutMs, 1000),
            ...self::platformHeaders($packageVersion),
        ];
    }

    /**
     * The Stainless `getPlatformHeaders()` node arm, with the runtime pair describing PHP (see the
     * class comment).
     *
     * @return array<string, string>
     */
    public static function platformHeaders(string $packageVersion): array
    {
        return [
            'X-Stainless-Lang' => 'js',
            'X-Stainless-Package-Version' => $packageVersion,
            'X-Stainless-OS' => self::normalizePlatform(PigUserAgent::platform()),
            'X-Stainless-Arch' => self::normalizeArch(PigUserAgent::arch()),
            'X-Stainless-Runtime' => 'php',
            'X-Stainless-Runtime-Version' => PHP_VERSION,
        ];
    }

    /**
     * `@google/genai`'s `LIBRARY_LABEL + ' ' + userAgentExtra`, the value of both its `User-Agent` and
     * its `x-goog-api-client`: `google-genai-sdk/<version> gl-node/<process.version>` there,
     * `gl-php/<PHP_VERSION>` here (see the class comment).
     */
    public static function googleApiClient(): string
    {
        return 'google-genai-sdk/' . self::GOOGLE_GENAI_SDK_VERSION . ' gl-php/' . PHP_VERSION;
    }

    /** The Stainless `normalizePlatform()`. */
    private static function normalizePlatform(string $platform): string
    {
        $platform = strtolower($platform);

        return match (true) {
            str_contains($platform, 'ios') => 'iOS',
            $platform === 'android' => 'Android',
            $platform === 'darwin' => 'MacOS',
            $platform === 'win32' => 'Windows',
            $platform === 'freebsd' => 'FreeBSD',
            $platform === 'openbsd' => 'OpenBSD',
            $platform === 'linux' => 'Linux',
            $platform !== '' => "Other:{$platform}",
            default => 'Unknown',
        };
    }

    /** The Stainless `normalizeArch()`. */
    private static function normalizeArch(string $arch): string
    {
        return match (true) {
            $arch === 'x32' => 'x32',
            $arch === 'x86_64' || $arch === 'x64' => 'x64',
            $arch === 'arm' => 'arm',
            $arch === 'aarch64' || $arch === 'arm64' => 'arm64',
            $arch !== '' => "other:{$arch}",
            default => 'unknown',
        };
    }
}
