<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Session;

use RuntimeException;

/**
 * Upstream's `MissingSessionCwdError`: a session was asked to carry on in a directory that no
 * longer exists. Thrown before anything has been torn down, so the caller can ask whether to carry
 * on in `$issue->fallbackCwd` instead (`SessionCwd::prompt()`) and try again with that override —
 * or, where nobody can be asked, say this message and stop.
 */
final class MissingSessionCwdError extends RuntimeException
{
    public function __construct(public readonly SessionCwdIssue $issue)
    {
        parent::__construct(SessionCwd::errorMessage($issue));
    }
}
