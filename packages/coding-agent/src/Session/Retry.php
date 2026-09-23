<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Session;

use Pig\Ai\AssistantMessage;
use Pig\Ai\StopReason;
use Pig\Ai\Utils\Overflow;

/**
 * Is this failure worth trying again, and how long should the wait be?
 *
 * The policy, with nothing in it that does anything — `AgentSession` does the waiting and the
 * retrying. Separated because "is a 503 worth retrying" is a question with a right answer that
 * can be asked of a value, and a question tangled up with a session is a question nobody tests.
 *
 * **The status code, not the prose.** Upstream matches the error message against
 * `/overloaded|rate.?limit|429|500|502|503|504|.../i`, which is what it has: its providers
 * word failures however they like. pig's four providers all write
 * `"<provider> returned <status>: <message>"`, so the number is right there and reading it is
 * not a guess. The word list is still here underneath, for the failures that never reached
 * HTTP at all — a socket that died mid-stream has no status code to read.
 *
 * Ported from the auto-retry half of upstream's `core/agent-session.ts`.
 */
final class Retry
{
    /** Attempts after the first failure, so four requests at most. */
    public const int MAX_ATTEMPTS = 3;

    /** Doubling from here: 2s, 4s, 8s. */
    public const float BASE_DELAY = 2.0;

    /**
     * The statuses worth waiting out.
     *
     * 429 is the provider saying "slower"; the 5xx are it saying "not now". Everything else a
     * provider returns is about the request, and the request will not change by being sent
     * again — a 401 retried three times is three more rejections and twenty-eight seconds.
     */
    private const array RETRYABLE_STATUSES = [408, 429, 500, 502, 503, 504, 529];

    /** `Anthropic returned 429: rate limit exceeded` — the shape every provider here writes. */
    private const string STATUS = '/\breturned (\d{3})\b/';

    /**
     * What a failure with no status code looks like.
     *
     * A stream that stopped halfway, a socket that closed, a TLS handshake that never
     * finished: real, retryable, and never an HTTP response.
     */
    private const string WORDS = '/overloaded|rate.?limit|too many requests|service.?unavailable'
        . '|server error|internal error|connection.?(error|reset|closed)|timed? ?out'
        . '|stream ended|broken pipe/i';

    /**
     * Should this failed turn be tried again?
     *
     * An overflow is not retryable, whatever else it looks like: the request is too big, and
     * sending it again unchanged is the one thing guaranteed not to work. Compaction is what
     * answers that, which is why the context window has to be passed in here.
     */
    public static function worthRetrying(AssistantMessage $message, ?int $contextWindow = null): bool
    {
        if ($message->stopReason !== StopReason::Error || $message->errorMessage === null) {
            return false;
        }

        if (Overflow::happened($message, $contextWindow)) {
            return false;
        }

        $status = self::statusOf($message->errorMessage);

        if ($status !== null) {
            return in_array($status, self::RETRYABLE_STATUSES, true);
        }

        return preg_match(self::WORDS, $message->errorMessage) === 1;
    }

    /**
     * The HTTP status a provider reported, or null when the failure never got that far.
     *
     * Null is the interesting answer: it means the request did not come back with a verdict,
     * which is a different kind of failure from one that did.
     */
    public static function statusOf(string $error): ?int
    {
        if (preg_match(self::STATUS, $error, $match) !== 1) {
            return null;
        }

        return (int) $match[1];
    }

    /**
     * Seconds to wait before attempt `$attempt`, counting the first retry as 1.
     *
     * Doubling, because the failures this waits out are the ones where everyone else is also
     * retrying: a fixed delay means the whole crowd comes back at once and the provider that
     * was overloaded is overloaded again.
     */
    public static function delayFor(int $attempt, float $base = self::BASE_DELAY): float
    {
        return $base * 2 ** max(0, $attempt - 1);
    }
}
