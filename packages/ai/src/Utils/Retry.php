<?php

declare(strict_types=1);

namespace Pig\Ai\Utils;

use Closure;
use Pig\Ai\AssistantMessage;
use Pig\Ai\StopReason;
use Pig\Async\AbortSignal;
use Pig\Async\Deferred;
use Pig\Async\Loop;

/**
 * Upstream's `utils/retry.ts`: whether a failed turn looks transient, and how long to wait before
 * trying it again. The policy itself — the budget, the sleep, the events — is the caller's
 * (`AgentSession::prepareRetry()`), as upstream's `_prepareRetry()` is.
 *
 * Classified by the error's **text**, as upstream classifies it, against the two pattern lists
 * below, copied entry for entry. `retryAssistantCall()` is the bounded retry of one request that
 * the summaries go through (compaction and branch summaries).
 */
final class Retry
{
    /** Upstream's `NON_RETRYABLE_PROVIDER_LIMIT_ERROR_PATTERN` entries. */
    private const array NON_RETRYABLE_PROVIDER_LIMIT_ERROR_PATTERNS = [
        // OpenCode Go/free-tier limits returned as 429 JSON error types by OpenCode's
        // Zen API. These are subscription/account limits, not transient throttles.
        'GoUsageLimitError',
        'FreeUsageLimitError',

        // OpenCode Go subscription-limit text asks users to enable available-balance
        // usage after rolling/weekly/monthly limits are reached.
        'Monthly usage limit reached',
        'available balance',

        // Generic quota/budget/billing exhaustion. `insufficient_quota` is OpenAI's
        // quota/billing error code; the other strings cover common gateway wording.
        'insufficient_quota',
        'out of budget',
        'quota exceeded',
        'billing',

        // Sign in with ChatGPT: the subscription's shared usage limit, which resets
        // after hours rather than seconds.
        'subscription_sharing_usage_limit_exceeded',
    ];

    /**
     * pig's own additions to the list above, kept apart so the upstream list stays a copy.
     *
     * `Quota reached.` is pi-antigravity's own sentence for an account that has used its share
     * (`AntigravityApi::friendlyAntigravityError()`, word for word from there), and it matches none
     * of upstream's entries — upstream never classifies it, because its session simply stops.
     * pig's does not: it is the one error a fallback model exists for.
     */
    private const array PIG_PROVIDER_LIMIT_ERROR_PATTERNS = [
        'quota reached',
    ];

    /** Upstream's `RETRYABLE_PROVIDER_ERROR_PATTERN` entries. */
    private const array RETRYABLE_PROVIDER_ERROR_PATTERNS = [
        // Generic provider load, HTTP status, and server-side transient failures.
        'overloaded',
        'server_busy',
        'servers are currently busy',
        'currently experiencing high demand',
        'model is at capacity',
        'rate.?limit',
        'too many requests',
        '429',
        '500',
        '502',
        '503',
        '504',
        '520',
        '524',
        'service.?unavailable',
        'server.?error',
        'internal.?error',

        // Wrapper/provider text for transient upstream failures, including OpenRouter
        // "Provider returned error" responses (#2264).
        'provider.?returned.?error',
        'exceeded request buffer limit while retrying upstream',

        // Network, proxy, and fetch transport failures. This includes OpenAI Codex
        // raw-fetch failures such as "upstream connect", "connection refused", and
        // "reset before headers" (#733), plus OpenRouter connection drops (#3317).
        'network.?error',
        'connection.?error',
        'connection.?refused',
        'connection.?lost',
        'other side closed',
        'fetch failed',
        'getaddrinfo',
        'ENOTFOUND',
        'EAI_AGAIN',
        'upstream.?connect',
        'reset before headers',
        'socket hang up',
        'socket connection was closed',
        'timed? out',
        'timeout',
        'terminated',

        // WebSocket transports can report close/error text instead of HTTP/fetch text.
        'websocket.?closed',
        'websocket.?error',

        // Premature stream endings from SDKs and transports. Anthropic can throw
        // "stream ended without ..." and "Anthropic stream ended before message_stop"
        // (#4433); Bedrock/Smithy can throw an HTTP/2 no-response error (#3594).
        'ended without',
        'stream ended before message_stop',
        'stream ended before a terminal response event',
        'http2 request did not get a response',
        // Node ERR_HTTP2_STREAM_CANCEL: the HTTP/2 session died before the request was
        // sent, e.g. after the Bedrock SDK's 5-minute session timeout (#10379).
        'pending stream has been canceled',

        // Provider-requested retry delay cap failures should flow through the outer
        // retry policy so callers can surface/abort the backoff (#1123).
        'retry delay',

        // Explicit retry guidance emitted mid-stream by OpenAI Responses and Bedrock
        // stream exceptions (#6019).
        'you can retry your request',
        'try your request again',
        'please retry your request',

        // gRPC based providers (e.g. NVIDIA NIM)
        'ResourceExhausted',

        // Sign in with ChatGPT: usage or user data temporarily unavailable. Usage
        // failures can arrive mid-stream without an HTTP 503 in the message.
        'subscription_sharing_usage_unavailable',
        'subscription_sharing_user_unavailable',
    ];

    /** Upstream's `DEFAULT_MAX_AGENT_RETRY_DELAY_MS`. */
    public const int DEFAULT_MAX_AGENT_RETRY_DELAY_MS = 60_000;

    /** `Number.MAX_SAFE_INTEGER`. */
    private const int MAX_SAFE_INTEGER = 9_007_199_254_740_991;

    /**
     * Upstream's `retryDelayMs(policy, attempt)`: `baseDelayMs * 2^(attempt-1)`, capped at
     * `maxAgentDelayMs` (60 seconds when unset).
     */
    public static function retryDelayMs(float $baseDelayMs, int $attempt, ?float $maxAgentDelayMs = null): float
    {
        $delay = $baseDelayMs * 2 ** max(0, $attempt - 1);
        // `Number.isSafeInteger(delay) ? delay : Number.MAX_SAFE_INTEGER`.
        $safeDelay = is_finite($delay) && floor($delay) === (float) $delay && abs($delay) <= self::MAX_SAFE_INTEGER
            ? $delay
            : (float) self::MAX_SAFE_INTEGER;

        return min($safeDelay, $maxAgentDelayMs ?? self::DEFAULT_MAX_AGENT_RETRY_DELAY_MS);
    }

    /**
     * Run a single assistant-producing call with bounded retry on transient errors — upstream's
     * `retryAssistantCall()`.
     *
     * - A successful response is returned immediately. Aborts are terminal and never retried, but
     *   reported as unsuccessful if they happen after a retry was scheduled. An abort during the
     *   backoff sleep is normalized to an aborted `AssistantMessage` too.
     * - A non-retryable error (`isRetryableAssistantError()`, quota and billing exhaustion
     *   included) is returned immediately, so deterministic errors fail fast.
     * - Otherwise it retries up to `maxRetries` times with exponential backoff, calling
     *   `onRetryScheduled` before each sleep, `onRetryAttemptStart` after it, and `onRetryFinished`
     *   once at the end.
     *
     * With no policy, or a disabled one, the first response is returned unchanged.
     *
     * @param Closure(): AssistantMessage $produce
     * @param array{enabled: bool, maxRetries: int, baseDelayMs: float, maxAgentDelayMs?: float|null}|null $policy
     * @param array{onRetryScheduled?: Closure(int, int, float, string): void, onRetryAttemptStart?: Closure(): void, onRetryFinished?: Closure(bool, int, ?string): void} $callbacks
     */
    public static function retryAssistantCall(Closure $produce, ?array $policy, ?AbortSignal $signal, array $callbacks = []): AssistantMessage
    {
        $maxAttempts = ($policy['enabled'] ?? false) ? (int) $policy['maxRetries'] : 0;
        $attempt = 0;
        $lastRetry = null;
        $finished = static function (bool $success, int $attempt, ?string $error = null) use ($callbacks): void {
            if (isset($callbacks['onRetryFinished'])) {
                ($callbacks['onRetryFinished'])($success, $attempt, $error);
            }
        };

        while (true) {
            $response = $produce();

            // "Abort: terminal but not successful. Never retry an aborted message."
            if ($response->stopReason === StopReason::Aborted) {
                if ($lastRetry !== null) {
                    $finished(false, $lastRetry['attempt']);
                }

                return $response;
            }

            if ($response->stopReason !== StopReason::Error) {
                if ($lastRetry !== null) {
                    $finished(true, $lastRetry['attempt']);
                }

                return $response;
            }

            // "Non-retryable, or budget exhausted: return the final error message."
            if ($attempt >= $maxAttempts || !self::isRetryableAssistantError($response)) {
                if ($lastRetry !== null) {
                    $finished(false, $lastRetry['attempt'], $response->errorMessage);
                }

                return $response;
            }

            $attempt++;
            $lastRetry = ['attempt' => $attempt, 'errorMessage' => $response->errorMessage !== null && $response->errorMessage !== '' ? $response->errorMessage : 'Unknown error'];
            $delayMs = self::retryDelayMs((float) $policy['baseDelayMs'], $attempt, isset($policy['maxAgentDelayMs']) ? (float) $policy['maxAgentDelayMs'] : null);

            if (isset($callbacks['onRetryScheduled'])) {
                ($callbacks['onRetryScheduled'])($attempt, $maxAttempts, $delayMs, $lastRetry['errorMessage']);
            }

            // "Normalize aborts during retry backoff to the same AssistantMessage shape as provider
            // stream aborts, so callers do not need to care when cancellation happened."
            if (!self::sleep($delayMs / 1000, $signal)) {
                $finished(false, $attempt, $lastRetry['errorMessage']);

                return new AssistantMessage(
                    $response->content,
                    $response->api,
                    $response->provider,
                    $response->model,
                    $response->usage,
                    StopReason::Aborted,
                    null,
                    $response->timestamp,
                    $response->rawStopReason,
                    $response->responseId,
                    $response->responseModel,
                    $response->endTurn,
                    $response->diagnostics,
                    $response->providerThinkingLevel,
                    $response->deferred,
                    $response->thinkingLevel,
                );
            }

            if (isset($callbacks['onRetryAttemptStart'])) {
                ($callbacks['onRetryAttemptStart'])();
            }
        }
    }

    /** Upstream's backoff `sleep()`: false when the signal aborted it. */
    private static function sleep(float $seconds, ?AbortSignal $signal): bool
    {
        if ($signal?->aborted() ?? false) {
            return false;
        }

        $done = new Deferred();
        $timer = Loop::get()->delay($seconds, static function () use ($done): void {
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
            return $done->future->await() === true;
        } finally {
            if ($listener !== null) {
                $signal?->removeListener($listener);
            }
        }
    }

    /**
     * Upstream's `isRetryableAssistantError()`: "Classifies whether a failed assistant message looks
     * like a transient provider or transport error". Context overflow is the caller's to exclude
     * first.
     */
    public static function isRetryableAssistantError(AssistantMessage $message): bool
    {
        if ($message->stopReason !== StopReason::Error || $message->errorMessage === null || $message->errorMessage === '') {
            return false;
        }

        if (self::isProviderLimitError($message)) {
            return false;
        }

        // A JavaScript string is always text; malformed bytes are read as U+FFFD, as it would hold them.
        return preg_match(self::pattern(self::RETRYABLE_PROVIDER_ERROR_PATTERNS), JsJson::decodeUtf8($message->errorMessage)) === 1;
    }

    /**
     * Whether a failed turn hit a quota, budget or billing wall — the first half of
     * `isRetryableAssistantError()`, on its own because `AgentSession` has a second use for it:
     * a limit is what a fallback model is for, where a transient error is what a retry is for.
     * One list for both, so the two cannot drift apart.
     */
    public static function isProviderLimitError(AssistantMessage $message): bool
    {
        if ($message->stopReason !== StopReason::Error || $message->errorMessage === null || $message->errorMessage === '') {
            return false;
        }

        $errorMessage = JsJson::decodeUtf8($message->errorMessage);

        return preg_match(self::pattern(self::NON_RETRYABLE_PROVIDER_LIMIT_ERROR_PATTERNS), $errorMessage) === 1
            || preg_match(self::pattern(self::PIG_PROVIDER_LIMIT_ERROR_PATTERNS), $errorMessage) === 1;
    }

    /**
     * Upstream's `buildProviderErrorPattern()`: `new RegExp(patterns.join("|"), "i")`. `u` so `.`
     * is one character, as a JavaScript regex reads a string's characters.
     *
     * @param list<string> $patterns
     */
    private static function pattern(array $patterns): string
    {
        return '/' . str_replace('/', '\/', implode('|', $patterns)) . '/iu';
    }
}
