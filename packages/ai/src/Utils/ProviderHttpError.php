<?php

declare(strict_types=1);

namespace Pig\Ai\Utils;

use RuntimeException;
use Throwable;

/**
 * Upstream's `ProviderError` shape in `utils/provider-retry.ts`: an error that carries `status` and
 * `headers`, either of which may be undefined. That is the SDKs' `APIError` — a refused request
 * (status and headers both set) or `APIConnectionError` / `APIConnectionTimeoutError` (neither set,
 * "Connection error." / "Request timed out.") — and the Google SDK's `ApiError` once
 * `retryGoogleRequest()` has given it a `headers` of undefined.
 *
 * `ProviderRetry::retryProviderRequest()` retries only these, as upstream's `isProviderError()`
 * does; any other throwable propagates at once.
 */
final class ProviderHttpError extends RuntimeException
{
    /**
     * @param int|null $status the HTTP status, or null for a request that never got one
     * @param array<string, string>|null $headers the response headers, lowercased names
     * @param string|null $display what the provider's catch makes of this error for the turn's
     *        `errorMessage` (upstream's `formatProviderError(normalizeProviderError(error))`, which
     *        reads more of the SDK error than its message), when that is not the message itself
     */
    public function __construct(
        string $message,
        public readonly ?int $status = null,
        public readonly ?array $headers = null,
        ?Throwable $previous = null,
        public readonly ?string $display = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /** The turn's `errorMessage` for an error the provider caught: `display`, else the message. */
    public static function displayOf(Throwable $error): string
    {
        return $error instanceof self && $error->display !== null ? $error->display : $error->getMessage();
    }

    /** `error.headers?.get(name)`: case-insensitive, null when absent. */
    public function header(string $name): ?string
    {
        if ($this->headers === null) {
            return null;
        }

        $name = strtolower($name);

        foreach ($this->headers as $key => $value) {
            if (strtolower((string) $key) === $name) {
                return $value;
            }
        }

        return null;
    }
}
