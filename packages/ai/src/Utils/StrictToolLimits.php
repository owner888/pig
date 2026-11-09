<?php

declare(strict_types=1);

namespace Pig\Ai\Utils;

/**
 * Upstream's `strict-tool-limits.ts`: telling a rejection for Claude's strict tool limits apart, and
 * remembering, per session, that one happened.
 *
 * A session whose strict tools Claude rejected sends its `strict: "prefer"` tools non-strict from
 * then on: the tool set rarely changes between turns, so trying strict again would only repeat the
 * rejection — one rejected request per turn — and going back to strict later would rewrite the tool
 * definitions and miss the prompt cache. Shared by the Anthropic and Bedrock providers, which run the
 * same Claude limits (upstream #11626).
 */
final class StrictToolLimits
{
    /**
     * "Claude rejects (on the Anthropic API and on Bedrock) a request whose strict tools exceed its
     * limits with one of these messages. Observed limits: 20 strict tools, and 16 optional or
     * union-typed parameters across all strict tools."
     */
    private const array ERROR_MESSAGES = [
        'The compiled grammar is too large',
        'Too many strict tools (',
        'Schemas contains too many parameters with union types (',
    ];

    /** Upstream's `MAX_SESSIONS_OVER_STRICT_TOOL_LIMITS`. */
    private const int MAX_SESSIONS = 10_000;

    /** @var array<string, true> in the order they were marked, oldest first */
    private static array $sessions = [];

    /** Upstream's `isStrictToolLimitErrorMessage()`. */
    public static function isErrorMessage(string $message): bool
    {
        foreach (self::ERROR_MESSAGES as $limitMessage) {
            if (str_contains($message, $limitMessage)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Upstream's `markStrictToolLimitsExceeded()`; nothing without a session. At most `MAX_SESSIONS`
     * are kept, the oldest dropped first: entries are freed by `cleanup()` when a session ends, and
     * the cap bounds memory for a caller that never says so — a dropped one pays one more rejection.
     */
    public static function markExceeded(?string $sessionId): void
    {
        if ($sessionId === null) {
            return;
        }

        self::$sessions[$sessionId] = true;

        if (count(self::$sessions) > self::MAX_SESSIONS) {
            unset(self::$sessions[array_key_first(self::$sessions)]);
        }
    }

    /** Upstream's `hasExceededStrictToolLimits()`. */
    public static function hasExceeded(?string $sessionId): bool
    {
        return $sessionId !== null && isset(self::$sessions[$sessionId]);
    }

    /** Upstream's `registerSessionResourceCleanup()` callback: the session's entry, or every one. */
    public static function cleanup(?string $sessionId = null): void
    {
        if ($sessionId !== null) {
            unset(self::$sessions[$sessionId]);
        } else {
            self::$sessions = [];
        }
    }
}
