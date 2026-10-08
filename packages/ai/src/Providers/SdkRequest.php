<?php

declare(strict_types=1);

namespace Pig\Ai\Providers;

use Closure;
use Pig\Ai\Http\HttpClient;
use Pig\Ai\Http\HttpError;
use Pig\Ai\Http\Request;
use Pig\Ai\Http\Response;
use Pig\Ai\Utils\ProviderHttpError;
use Pig\Async\AbortController;
use Pig\Async\AbortError;
use Pig\Async\AbortSignal;
use Pig\Async\Loop;
use Pig\Async\SocketError;
use Throwable;

/**
 * One attempt of a Stainless SDK request (`@anthropic-ai/sdk`, `openai`) with `maxRetries: 0`, as
 * upstream makes it inside `retryProviderRequest()`: the SDK's `makeRequest()` up to the response.
 *
 * - **`timeout`** arms a timer around the fetch only (`fetchWithTimeout()`): it covers the wait for
 *   the response headers and is cleared once they arrive, so a long stream is never cut by it.
 * - **A fetch that fails** is `APIConnectionTimeoutError` ("Request timed out.") when it was the
 *   timer, or when the failure's text matches `/timed? ?out/i` — the SDK's own test — and
 *   `APIConnectionError` ("Connection error.") otherwise. Both carry no status and no headers, so
 *   `retryProviderRequest()` retries them.
 * - **A response that is not ok** is the SDK's `APIError`: the body read whole, the message the
 *   provider's own (`$explain`), status and headers kept for the retry policy.
 *
 * @internal
 */
final class SdkRequest
{
    /** The DOMException text a Node fetch rejects with when its signal aborts with no reason. */
    public const string DOM_ABORT_MESSAGE = 'This operation was aborted';

    /** `APIConnectionError`'s default message. */
    public const string CONNECTION_ERROR = 'Connection error.';

    /** `APIConnectionTimeoutError`'s default message. */
    public const string TIMEOUT_ERROR = 'Request timed out.';

    /** What Node's fetch says when the body stops arriving: undici's `TypeError: terminated`. */
    public const string BODY_TERMINATED = 'terminated';

    /**
     * The message a provider's catch records for an error, as Node's fetch would have worded the
     * transport's part of it: an abort while the body is read is the DOMException "This operation
     * was aborted", a socket that failed or closed mid-body (or a body that cannot be framed) is
     * undici's "terminated", and anything else — the provider's own errors — says what it says.
     * (A failure before the response is worded where it happens: `send()`, and the raw-fetch
     * providers' "fetch failed".)
     */
    public static function errorMessage(Throwable $error): string
    {
        return match (true) {
            $error instanceof AbortError => self::DOM_ABORT_MESSAGE,
            $error instanceof SocketError, $error instanceof HttpError => self::BODY_TERMINATED,
            default => ProviderHttpError::displayOf($error),
        };
    }

    /**
     * @param Closure(int $status, string $body): (string|array{0: string, 1: string}) $explain the
     *        SDK `APIError` message, or that and what the provider's catch displays for it
     */
    public static function send(
        HttpClient $http,
        Request $request,
        ?AbortSignal $signal,
        int $timeoutMs,
        Closure $explain,
    ): Response {
        $signal?->throwIfAborted();

        $combined = new AbortController();
        $timedOut = false;
        $timer = Loop::get()->delay(max(0, $timeoutMs) / 1000, static function () use ($combined, &$timedOut): void {
            $timedOut = true;
            $combined->abort(self::DOM_ABORT_MESSAGE);
        });
        $listener = $signal?->onAbort(static function (string $reason) use ($combined): void {
            $combined->abort($reason);
        });

        try {
            $response = $http->send($request, $combined->signal);
        } catch (Throwable $error) {
            if ($listener !== null) {
                $signal?->removeListener($listener);
            }

            if ($signal?->aborted() ?? false) {
                throw $error;
            }

            $isTimeout = $timedOut || preg_match('/timed? ?out/i', $error->getMessage()) === 1;

            throw new ProviderHttpError($isTimeout ? self::TIMEOUT_ERROR : self::CONNECTION_ERROR, previous: $error);
        } finally {
            Loop::get()->cancel($timer);
        }

        if (!$response->isSuccessful()) {
            if ($listener !== null) {
                $signal?->removeListener($listener);
            }

            $explained = $explain($response->status, $response->body->all());
            [$message, $display] = is_array($explained) ? $explained : [$explained, null];

            throw new ProviderHttpError($message, $response->status, $response->headers, display: $display);
        }

        return $response;
    }
}
