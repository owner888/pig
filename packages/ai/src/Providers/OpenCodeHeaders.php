<?php

declare(strict_types=1);

namespace Pig\Ai\Providers;

use Pig\Ai\Model;

/**
 * Upstream's `providers/opencode-headers.ts`: "Adds OpenCode's required per-conversation routing
 * header before API dispatch."
 *
 * Upstream wraps each of the `opencode` and `opencode-go` providers' four APIs in
 * `withOpenCodeSessionHeader()`, so every request through them — `stream` and `streamSimple` alike —
 * carries `x-opencode-session: <sessionId>`, whatever the cache retention. pig has no per-provider
 * stream objects to wrap: `Stream::start()` builds each API's options in one place, and asks this for
 * the headers there, keyed on the provider as upstream's registry is.
 */
final class OpenCodeHeaders
{
    public const string OPENCODE_SESSION_HEADER = 'x-opencode-session';

    /** The providers whose APIs upstream wraps. */
    private const array PROVIDERS = ['opencode', 'opencode-go'];

    /**
     * Upstream's `withSessionHeader()`, on the two fields it reads: the headers unchanged without a
     * session id (an empty one included) or when the caller already names the header in any case —
     * a null value included, which is the caller suppressing it — else the caller's headers plus the
     * session's.
     *
     * @param array<string, string|null>|null $headers
     * @return array<string, string|null>|null
     */
    public static function withSessionHeader(Model $model, ?string $sessionId, ?array $headers): ?array
    {
        if (!in_array($model->provider, self::PROVIDERS, true)) {
            return $headers;
        }

        if ($sessionId === null || $sessionId === '' || self::hasHeader($headers, self::OPENCODE_SESSION_HEADER)) {
            return $headers;
        }

        return [...($headers ?? []), self::OPENCODE_SESSION_HEADER => $sessionId];
    }

    /**
     * Upstream's `hasHeader()`: whether any key is the name, case-insensitively.
     *
     * @param array<string, string|null>|null $headers
     */
    private static function hasHeader(?array $headers, string $name): bool
    {
        $expected = strtolower($name);

        foreach (array_keys($headers ?? []) as $key) {
            if (strtolower((string) $key) === $expected) {
                return true;
            }
        }

        return false;
    }
}
