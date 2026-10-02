<?php

declare(strict_types=1);

namespace Pig\Mcp\Oauth;

use RuntimeException;

/** Dynamic client registration was refused. */
final class OauthRegistrationError extends RuntimeException
{
    public function __construct(public readonly int $status, public readonly string $body)
    {
        parent::__construct("OAuth dynamic client registration failed with status {$status}: {$body}");
    }
}
