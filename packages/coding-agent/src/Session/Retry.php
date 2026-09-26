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
     * The longest wait worth waiting, when the provider names one.
     *
     * A minute, because that is about as long as anybody sits in front of a countdown — and past
     * it the retry stops being a retry: a turn that resumes in ten minutes is not the turn
     * somebody asked for, it is a screen that looks like a hang. So a stated wait longer than
     * this is not waited out at all; the turn ends and the provider's own sentence — which says
     * when to come back — is what the person reads.
     *
     * The developer's number. It bounds only a *stated* wait: the doubling reaches 8s and needs
     * no bound of its own.
     */
    public const float MAX_STATED_WAIT = 60.0;

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
     *
     * **Nor is a wait the provider itself put past `MAX_STATED_WAIT`.** It is checked before the
     * status and before the words, because it beats both: a 429 is retryable and "rate limit" is
     * in the word list, and neither of those knows that the sentence beside it says "not for ten
     * minutes". Refusing here rather than waiting is the whole point — what comes back is the
     * provider's own line, which names the time.
     */
    public static function worthRetrying(AssistantMessage $message, ?int $contextWindow = null): bool
    {
        if ($message->stopReason !== StopReason::Error || $message->errorMessage === null) {
            return false;
        }

        if (Overflow::happened($message, $contextWindow)) {
            return false;
        }

        $stated = self::statedDelay($message->errorMessage);

        if ($stated !== null && $stated > self::MAX_STATED_WAIT) {
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

    /**
     * How long the provider itself asked for, when it said — in seconds, or null.
     *
     * **A guess beats nothing; what the server said beats a guess.** Code Assist's free tier
     * answers a 429 with the moment its quota resets, and against a quota that comes back in 39
     * seconds the doubling is three more 429s and a turn that dies after fourteen seconds of
     * waiting. Upstream reads these in `providers/google-gemini-cli.ts`, inside a retry loop of
     * its own that pig does not have — the policy is the right place for it here, because pig has
     * one retry and it is this one.
     *
     * Three shapes, upstream's three, and they are Google's prose rather than a header: `Your
     * quota will reset after 18h31m10s`, `Please retry in 250ms`, and a `retryDelay` field in the
     * error body. Anything else answers null and gets the doubling.
     *
     * **A second is added**, as upstream adds it: coming back at the exact moment a quota resets
     * is a coin toss between two clocks, and losing it costs the attempt.
     *
     * **This answers what was asked for, however long that is** — the bound is
     * `worthRetrying()`'s, because a wait past `MAX_STATED_WAIT` is not a long retry but a
     * different decision: do not retry at all. Upstream waits whatever it is told, in a
     * `setTimeout` nobody can see; eighteen hours of that is a session that looks dead.
     */
    public static function statedDelay(string $error): ?float
    {
        // "Your quota will reset after 18h31m10s" — hours and minutes optional, seconds not.
        if (preg_match('/reset after (?:(\d+)h)?(?:(\d+)m)?(\d+(?:\.\d+)?)s/i', $error, $match) === 1) {
            $seconds = ((int) ($match[1] ?: 0) * 60 + (int) ($match[2] ?: 0)) * 60 + (float) $match[3];

            return $seconds > 0 ? $seconds + 1 : null;
        }

        if (preg_match('/Please retry in ([0-9.]+)(ms|s)\b/i', $error, $match) === 1) {
            return self::seconds((float) $match[1], $match[2]);
        }

        if (preg_match('/"retryDelay":\s*"([0-9.]+)(ms|s)"/i', $error, $match) === 1) {
            return self::seconds((float) $match[1], $match[2]);
        }

        return null;
    }

    private static function seconds(float $value, string $unit): ?float
    {
        if ($value <= 0) {
            return null;
        }

        return (strtolower($unit) === 'ms' ? $value / 1000 : $value) + 1;
    }
}
