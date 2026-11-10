<?php

declare(strict_types=1);

namespace Pig\Ai\Providers;

/**
 * The client an Antigravity subscription's requests are expected to come from: Google's own
 * Antigravity CLI (`agy`).
 *
 * The Code Assist deployment behind Antigravity reads the `User-Agent` and acts on it — a request
 * that does not name the CLI is refused, and the **version** in it decides which models the
 * catalogue shows and serves: 1.2.4 was not shown Gemini 4 Argon, 1.4.0 is. So the string is one
 * fact, and it lives here for `ClaudeCode`'s reason: it is read from several places — the
 * Antigravity extension's chat, quota, image, search and catalogue requests, the video extension's
 * Antigravity path, `scripts/fetch-antigravity-models.php` — and a copy left on an older version is
 * a request served an older catalogue, which is how four copies had drifted to three versions.
 *
 * Moving to a new CLI release is changing `VERSION` and `CHANGELIST` below, both from
 * pi-antigravity's `DEFAULT_USER_AGENT` (`client/client.ts`), and nothing else.
 */
final class AntigravityCli
{
    /** The CLI release requests say they are. */
    public const string VERSION = '1.4.0';

    /** The build's changelist, which the CLI sends beside its version. */
    public const string CHANGELIST = '982146307';

    /**
     * The `User-Agent` of every request to Antigravity — pi-antigravity's `antigravityEnv("USER_AGENT")
     * || defaultUserAgent()`: `ANTIGRAVITY_USER_AGENT` (or its older name `NOAGY_USER_AGENT`) when
     * one is set to something, for trying a newer CLI's string without a release, the CLI's own
     * otherwise.
     *
     * The platform in it is the reference implementation's literal and has nothing to do with the
     * machine pig runs on: the point is to be the CLI, and the CLI this was read from ran on Linux.
     */
    public static function userAgent(): string
    {
        foreach (['ANTIGRAVITY_USER_AGENT', 'NOAGY_USER_AGENT'] as $variable) {
            $value = getenv($variable);

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return self::defaultUserAgent();
    }

    /** pi-antigravity's `defaultUserAgent()`: the CLI's own string, whatever the environment says. */
    public static function defaultUserAgent(): string
    {
        return 'antigravity/cli/' . self::VERSION . ' (aidev_client; os_type=linux; arch=amd64; cl=' . self::CHANGELIST . '; auth_method=consumer)';
    }
}
