<?php

declare(strict_types=1);

namespace Pig\Ai\Utils;

use Closure;
use Pig\Async\AbortSignal;
use Pig\Async\Deferred;
use Pig\Async\Loop;
use RuntimeException;
use Throwable;

/**
 * Upstream's `utils/provider-retry.ts`, line for line.
 *
 * "Reproduce the retry behavior used by the OpenAI and Anthropic SDKs while making their backoff
 * sleep interruptible." Upstream calls the SDK with `maxRetries: 0` and wraps the request with
 * this; pig has no SDK, so the request closure is pig's own send-and-check, and it throws
 * `ProviderHttpError` where the SDK would throw its `APIError`.
 */
final class ProviderRetry
{
    /** Upstream's `DEFAULT_MAX_RETRY_DELAY_MS`. */
    public const int DEFAULT_MAX_RETRY_DELAY_MS = 60_000;

    /** Upstream's `createAbortError()` message. */
    public const string ABORT_MESSAGE = 'Request aborted';

    /**
     * Upstream's `retryProviderRequest()`. "Provider-requested delays above `maxRetryDelayMs` fail
     * immediately (60 seconds by default); set it to zero to disable the limit."
     *
     * @template T
     * @param Closure(): T $request
     * @param list<int> $noRetryStatuses "HTTP statuses that fail at once although the default
     *        policy would retry them"
     * @return T
     */
    public static function retryProviderRequest(
        Closure $request,
        ?int $maxRetries = null,
        ?int $maxRetryDelayMs = null,
        ?AbortSignal $signal = null,
        array $noRetryStatuses = [],
    ): mixed {
        $maxRetries ??= 0;
        $retriesRemaining = $maxRetries;

        for (;;) {
            try {
                // "Each retry is a fresh SDK request, so X-Stainless-Retry-Count remains zero."
                return $request();
            } catch (Throwable $error) {
                if ($signal?->aborted() ?? false) {
                    throw self::createAbortError();
                }

                if ($retriesRemaining <= 0 || !$error instanceof ProviderHttpError || !self::isRetryableProviderError($error)) {
                    throw $error;
                }

                if ($error->status !== null && in_array($error->status, $noRetryStatuses, true)) {
                    throw $error;
                }

                $retryIndex = $maxRetries - $retriesRemaining;
                $retriesRemaining--;
                self::abortableSleep(self::getRetryDelayMs($error, $retryIndex, $maxRetryDelayMs), $signal);
            }
        }
    }

    /** Upstream's `isRetryableProviderError()`: "Mirrors the pinned OpenAI/Anthropic SDK retry policy". */
    public static function isRetryableProviderError(ProviderHttpError $error): bool
    {
        $shouldRetry = $error->header('x-should-retry');

        if ($shouldRetry === 'true') {
            return true;
        }

        if ($shouldRetry === 'false') {
            return false;
        }

        if ($error->status === null) {
            return true;
        }

        return $error->status === 408
            || $error->status === 409
            || $error->status === 429
            || $error->status >= 500;
    }

    /** Upstream's `getRetryDelayMs()`. */
    public static function getRetryDelayMs(ProviderHttpError $error, int $retryIndex, ?int $maxRetryDelayMs): float
    {
        $retryAfterMs = $error->header('retry-after-ms');

        if ($retryAfterMs !== null && $retryAfterMs !== '') {
            $value = JsJson::parseFloat($retryAfterMs);

            if (is_finite($value)) {
                return self::validateServerRetryDelayMs($value, $maxRetryDelayMs, $error->getMessage());
            }
        }

        $retryAfter = $error->header('retry-after');

        if ($retryAfter !== null && $retryAfter !== '') {
            $seconds = JsJson::parseFloat($retryAfter);
            $delayMs = is_nan($seconds) ? self::dateParse($retryAfter) - microtime(true) * 1000 : $seconds * 1000;

            if (is_finite($delayMs)) {
                return self::validateServerRetryDelayMs($delayMs, $maxRetryDelayMs, $error->getMessage());
            }
        }

        $exponentialDelay = min(0.5 * 2 ** $retryIndex, 8) * 1000;

        return $exponentialDelay * (1 - (mt_rand() / mt_getrandmax()) * 0.25);
    }

    /** Upstream's `validateServerRetryDelayMs()`. */
    private static function validateServerRetryDelayMs(float $delayMs, ?int $maxRetryDelayMs, string $providerErrorMessage): float
    {
        $maxDelayMs = $maxRetryDelayMs ?? self::DEFAULT_MAX_RETRY_DELAY_MS;

        if ($maxDelayMs > 0 && $delayMs > $maxDelayMs) {
            throw new RuntimeException(sprintf(
                'Server requested %ds retry delay (max: %ds). %s',
                (int) ceil($delayMs / 1000),
                (int) ceil($maxDelayMs / 1000),
                $providerErrorMessage,
            ));
        }

        return $delayMs;
    }

    /**
     * `Date.parse()` for the HTTP-date a `retry-after` carries, in milliseconds, NaN when unreadable.
     * `strtotime()` reads every RFC 9110 date form (IMF-fixdate, RFC 850, asctime) that V8 does.
     */
    private static function dateParse(string $value): float
    {
        $time = strtotime($value);

        return $time === false ? NAN : $time * 1000.0;
    }

    /** Upstream's `createAbortError()`: `Error("Request aborted")` named `AbortError`. */
    public static function createAbortError(): RuntimeException
    {
        return new RuntimeException(self::ABORT_MESSAGE);
    }

    /** Upstream's `abortableSleep()`. */
    private static function abortableSleep(float $ms, ?AbortSignal $signal): void
    {
        if ($signal?->aborted() ?? false) {
            throw self::createAbortError();
        }

        $done = new Deferred();
        $timer = Loop::get()->delay(max(0.0, $ms) / 1000, static function () use ($done): void {
            if (!$done->isComplete()) {
                $done->complete(true);
            }
        });
        $listener = $signal?->onAbort(static function () use ($done, $timer): void {
            Loop::get()->cancel($timer);

            if (!$done->isComplete()) {
                $done->complete(false);
            }
        });

        try {
            if ($done->future->await() !== true) {
                throw self::createAbortError();
            }
        } finally {
            if ($listener !== null) {
                $signal?->removeListener($listener);
            }
        }
    }
}
