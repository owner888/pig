<?php

declare(strict_types=1);

namespace Pig\Ai\Utils\Oauth;

use Closure;
use Pig\Ai\Timestamp;
use Pig\Async\AbortSignal;
use Pig\Async\Deferred;
use Pig\Async\Loop;

/**
 * Polling a device-code sign-in until somebody approves it — upstream's `auth/oauth/device-code.ts`.
 *
 * The poll is the flow's own (`$poll`), answering one of upstream's `OAuthDeviceCodePollResult`s as
 * an array: `['status' => 'pending']`, `['status' => 'slow_down', 'intervalSeconds' => ?float]`,
 * `['status' => 'failed', 'message' => string]` or `['status' => 'complete', 'value' => mixed]`.
 * OpenAI's Codex flow polls through this; GitHub Copilot's still has its own loop in `GithubCopilot`.
 */
final class DeviceCodeFlow
{
    private const string CANCEL_MESSAGE = 'Login cancelled';

    private const string TIMEOUT_MESSAGE = 'Device flow timed out';

    private const string SLOW_DOWN_TIMEOUT_MESSAGE = 'Device flow timed out after one or more slow_down responses. This is often caused by clock drift in WSL or VM environments. Please sync or restart the VM clock and try again.';

    private const int MINIMUM_INTERVAL_MS = 1000;

    /** "RFC 8628 section 3.2: if the authorization server omits `interval`, the client must use 5 seconds." */
    private const int DEFAULT_POLL_INTERVAL_SECONDS = 5;

    /** "RFC 8628 section 3.5: `slow_down` means the polling interval must increase by 5 seconds." */
    private const int SLOW_DOWN_INTERVAL_INCREMENT_MS = 5000;

    private function __construct()
    {
    }

    /** Upstream's `abortableSleep()`: `$cancelMessage` thrown when the signal ends the wait. */
    public static function abortableSleep(float $ms, AbortSignal $signal, string $cancelMessage): void
    {
        if ($signal->aborted()) {
            throw new OauthError($cancelMessage);
        }

        $done = new Deferred();
        $timer = Loop::get()->delay(max(0.0, $ms) / 1000, static function () use ($done): void {
            if (!$done->isComplete()) {
                $done->complete(true);
            }
        });
        $listener = $signal->onAbort(static function () use ($done, $timer): void {
            Loop::get()->cancel($timer);

            if (!$done->isComplete()) {
                $done->complete(false);
            }
        });

        try {
            $slept = $done->future->await();
        } finally {
            $signal->removeListener($listener);
        }

        if ($slept !== true) {
            throw new OauthError($cancelMessage);
        }
    }

    /**
     * Upstream's `pollOAuthDeviceCodeFlow(options)`.
     *
     * @param Closure(): array{status: string, value?: mixed, message?: string, intervalSeconds?: int|float|null} $poll
     */
    public static function pollOAuthDeviceCodeFlow(
        Closure $poll,
        AbortSignal $signal,
        int|float|null $intervalSeconds = null,
        int|float|null $expiresInSeconds = null,
        bool $waitBeforeFirstPoll = false,
    ): mixed {
        $deadline = $expiresInSeconds !== null ? Timestamp::nowMs() + $expiresInSeconds * 1000 : INF;
        $intervalMs = max(self::MINIMUM_INTERVAL_MS, (int) floor(($intervalSeconds ?? self::DEFAULT_POLL_INTERVAL_SECONDS) * 1000));
        $slowDownResponses = 0;

        if ($waitBeforeFirstPoll) {
            $remainingMs = $deadline - Timestamp::nowMs();

            if ($remainingMs > 0) {
                self::abortableSleep(min($intervalMs, $remainingMs), $signal, self::CANCEL_MESSAGE);
            }
        }

        while (Timestamp::nowMs() < $deadline) {
            if ($signal->aborted()) {
                throw new OauthError(self::CANCEL_MESSAGE);
            }

            $result = $poll();

            if ($result['status'] === 'complete') {
                return $result['value'] ?? null;
            }

            if ($result['status'] === 'failed') {
                throw new OauthError((string) ($result['message'] ?? ''));
            }

            if ($result['status'] === 'slow_down') {
                $slowDownResponses++;
                // "Use the server-provided interval when given (GitHub reports the new required minimum
                // in `interval`); trusting only a client-tracked value risks polling early forever under
                // WSL/VM clock drift. Otherwise apply RFC 8628 section 3.5: increase by 5 seconds."
                $serverInterval = $result['intervalSeconds'] ?? null;
                $intervalMs = (is_int($serverInterval) || is_float($serverInterval)) && is_finite((float) $serverInterval) && $serverInterval > 0
                    ? max(self::MINIMUM_INTERVAL_MS, (int) floor($serverInterval * 1000))
                    : max(self::MINIMUM_INTERVAL_MS, $intervalMs + self::SLOW_DOWN_INTERVAL_INCREMENT_MS);
            }

            $remainingMs = $deadline - Timestamp::nowMs();

            if ($remainingMs <= 0) {
                break;
            }

            self::abortableSleep(min($intervalMs, $remainingMs), $signal, self::CANCEL_MESSAGE);
        }

        throw new OauthError($slowDownResponses > 0 ? self::SLOW_DOWN_TIMEOUT_MESSAGE : self::TIMEOUT_MESSAGE);
    }
}
