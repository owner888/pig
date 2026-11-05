<?php

declare(strict_types=1);

namespace Pig\Ai\Http;

use Closure;
use Pig\Async\AbortError;
use Pig\Async\AbortSignal;
use Pig\Async\Deferred;
use Pig\Async\Loop;
use Throwable;

/**
 * An ordinary request, tried again when the network or a busy server loses it — what Python's
 * `requests` has as a retry adapter, for every plain REST call pig makes (a model catalog, a
 * market-data page, a package index). `HttpClient` sends once; this is the loop around it, so
 * that nobody writes the loop again.
 *
 * The rules are the same everywhere:
 *
 * - **Tried again**: a transport failure (cannot connect, TLS, timed out, closed under the
 *   request — anything `send()` or reading the body throws), and a status in `$statuses`
 *   (408, 425, 429, 500, 502, 503, 504 unless the caller says otherwise).
 * - **Not tried again**: every other status — a 4xx is the request's fault, and asking again
 *   changes nothing — and an aborted `$signal`, which throws at once.
 * - **The wait**: `Retry-After` when the server sends one (seconds or an HTTP date); otherwise
 *   `$baseDelay` doubled each time with ±25% jitter, up to `$maxDelay`. `$baseDelay` 0 is
 *   upstream's `fetchWithRetry()`: immediate.
 * - **The answer**: the last response, whatever its status, with its body read — the caller
 *   judges the status; a transport failure on the last attempt is thrown as it came.
 *
 * pig's own; upstream reaches for Node's `fetch` and writes each loop where it needs it. Not
 * for model requests, which keep `ProviderRetry` (the SDKs' policy, `x-should-retry`, the
 * provider's delays) and `Retry` (the turn).
 */
final class Retrying
{
    public const array RETRYABLE_STATUSES = [408, 425, 429, 500, 502, 503, 504];

    /**
     * @param int $attempts in all, the first one included; 1 is `HttpClient::send()` with the body read
     * @param float $baseDelay seconds before the second attempt; doubled each time after
     * @param list<int>|null $statuses the statuses to try again on; null is `RETRYABLE_STATUSES`
     * @param Closure(int, string, float): void|null $onRetry told the attempt that failed (1-based), why, and the wait in seconds
     * @return array{0: Response, 1: string} the response and its whole body
     */
    public static function fetch(
        HttpClient $http,
        Request $request,
        int $attempts = 3,
        float $baseDelay = 0.5,
        float $maxDelay = 30.0,
        ?AbortSignal $signal = null,
        ?array $statuses = null,
        ?Closure $onRetry = null,
    ): array {
        if ($attempts < 1) {
            throw new \InvalidArgumentException("At least one attempt, not {$attempts}.");
        }

        $statuses ??= self::RETRYABLE_STATUSES;

        for ($attempt = 1; ; $attempt++) {
            $signal?->throwIfAborted();

            try {
                $response = $http->send($request, $signal);
                $body = $response->body->all();

                if ($attempt >= $attempts || !in_array($response->status, $statuses, true)) {
                    return [$response, $body];
                }

                $reason = "HTTP {$response->status} {$response->reason}";
                $wait = self::retryAfter($response) ?? self::backoff($baseDelay, $maxDelay, $attempt);
            } catch (AbortError $error) {
                throw $error;
            } catch (Throwable $error) {
                if (($signal?->aborted() ?? false) || $attempt >= $attempts) {
                    throw $error;
                }

                $reason = $error->getMessage();
                $wait = self::backoff($baseDelay, $maxDelay, $attempt);
            }

            if ($onRetry !== null) {
                $onRetry($attempt, $reason, $wait);
            }

            self::sleep($wait, $signal);
        }
    }

    /** `$baseDelay` doubled per failed attempt, ±25% jitter, at most `$maxDelay`. */
    public static function backoff(float $baseDelay, float $maxDelay, int $failedAttempts): float
    {
        if ($baseDelay <= 0) {
            return 0.0;
        }

        $delay = $baseDelay * 2 ** ($failedAttempts - 1) * (0.75 + mt_rand(0, 500) / 1000);

        return min($maxDelay, $delay);
    }

    /** The server's `Retry-After`, in seconds, when it sent one it is sane to honour. */
    public static function retryAfter(Response $response): ?float
    {
        $header = $response->header('retry-after');

        if ($header === null || trim($header) === '') {
            return null;
        }

        $header = trim($header);

        if (is_numeric($header)) {
            $seconds = (float) $header;
        } else {
            $at = strtotime($header);

            if ($at === false) {
                return null;
            }

            $seconds = $at - time();
        }

        // A server asking for more than an hour is not asking for a retry.
        return $seconds >= 0 && $seconds <= 3600 ? $seconds : null;
    }

    /** Wait, unless `$signal` aborts first — then throw, as `ProviderRetry::abortableSleep()` does. */
    private static function sleep(float $seconds, ?AbortSignal $signal): void
    {
        if ($seconds <= 0) {
            $signal?->throwIfAborted();

            return;
        }

        $done = new Deferred();
        $timer = Loop::get()->delay($seconds, static function () use ($done): void {
            if (!$done->isComplete()) {
                $done->complete(true);
            }
        });
        $listener = $signal?->onAbort(static function (string $reason) use ($done, $timer): void {
            Loop::get()->cancel($timer);

            if (!$done->isComplete()) {
                $done->error(new AbortError($reason));
            }
        });

        try {
            $done->future->await();
        } finally {
            if ($listener !== null) {
                $signal->removeListener($listener);
            }
        }
    }
}
