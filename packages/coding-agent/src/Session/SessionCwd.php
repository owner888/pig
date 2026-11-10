<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Session;

/**
 * Upstream's `core/session-cwd.ts`: whether a session's stored working directory still exists,
 * and the two sentences said when it does not.
 *
 * A session carries on in the directory its header names — that is what resuming another
 * project's conversation means (`AgentSession::switchTo()`, `bin/pig`'s `--resume`). When that
 * directory is gone, upstream does not quietly use the current one: an interactive caller asks
 * (`prompt()`, "continue in current cwd"), anything else fails with `errorMessage()`.
 */
final class SessionCwd
{
    /**
     * Upstream's `getMissingSessionCwdIssue()`: null for a session with no file, no stored
     * directory, or one that exists.
     */
    public static function missingIssue(SessionManager $session, string $fallbackCwd): ?SessionCwdIssue
    {
        if ($session->cwd === '' || self::exists($session->cwd)) {
            return null;
        }

        return new SessionCwdIssue($session->path, $session->cwd, $fallbackCwd);
    }

    /**
     * Whether `$cwd` is a directory where the tools work: here, or where a Docker or SSH account's
     * run (`Tools\ToolSet::operations()`), whose folders need not be this machine's.
     */
    private static function exists(string $cwd): bool
    {
        $elsewhere = \Pig\CodingAgent\Tools\ToolSet::operations();

        return $elsewhere === null ? is_dir($cwd) : $elsewhere->exists($cwd) && $elsewhere->isDirectory($cwd);
    }

    /** Upstream's `formatMissingSessionCwdError()`, word for word. */
    public static function errorMessage(SessionCwdIssue $issue): string
    {
        $sessionFile = $issue->sessionFile !== null ? "\nSession file: {$issue->sessionFile}" : '';

        return "Stored session working directory does not exist: {$issue->sessionCwd}{$sessionFile}\nCurrent working directory: {$issue->fallbackCwd}";
    }

    /** Upstream's `formatMissingSessionCwdPrompt()`, word for word (#11975). */
    public static function prompt(SessionCwdIssue $issue): string
    {
        return "Session cwd does not exist: {$issue->sessionCwd}";
    }

    /** What `options()` answers to carry on in the current directory, once, asked again next time. */
    public const string CONTINUE = 'continue';

    /** What `options()` answers to fork into the current directory: a new session, the original kept. */
    public const string FORK = 'fork';

    /**
     * Upstream's `getMissingSessionCwdOptions()` (#11975): each choice's label, naming the current
     * directory, and what it means — `CONTINUE`, `FORK`, or null to cancel. Fork is what makes the
     * choice stick without rewriting the session's history: the copy's header names the current
     * directory.
     *
     * @return array<string, ?string> label => choice
     */
    public static function options(SessionCwdIssue $issue): array
    {
        return [
            "Continue in {$issue->fallbackCwd} (asks again next time)" => self::CONTINUE,
            "Fork into {$issue->fallbackCwd} (new session ID, original kept)" => self::FORK,
            'Cancel' => null,
        ];
    }

    /**
     * Upstream's `assertSessionCwdExists()`.
     *
     * @throws MissingSessionCwdError
     */
    public static function assertExists(SessionManager $session, string $fallbackCwd): void
    {
        $issue = self::missingIssue($session, $fallbackCwd);

        if ($issue !== null) {
            throw new MissingSessionCwdError($issue);
        }
    }
}
