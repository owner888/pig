<?php

declare(strict_types=1);

namespace PigMcp;

use RuntimeException;

/** Nobody finished the sign-in: the prompt was escaped, or the wait ran out. */
final class McpSignInCancelledError extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Sign-in cancelled');
    }
}
