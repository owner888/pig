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
        if ($session->cwd === '' || is_dir($session->cwd)) {
            return null;
        }

        return new SessionCwdIssue($session->path, $session->cwd, $fallbackCwd);
    }

    /** Upstream's `formatMissingSessionCwdError()`, word for word. */
    public static function errorMessage(SessionCwdIssue $issue): string
    {
        $sessionFile = $issue->sessionFile !== null ? "\nSession file: {$issue->sessionFile}" : '';

        return "Stored session working directory does not exist: {$issue->sessionCwd}{$sessionFile}\nCurrent working directory: {$issue->fallbackCwd}";
    }

    /** Upstream's `formatMissingSessionCwdPrompt()`, word for word. */
    public static function prompt(SessionCwdIssue $issue): string
    {
        return "cwd from session file does not exist\n{$issue->sessionCwd}\n\ncontinue in current cwd\n{$issue->fallbackCwd}";
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
