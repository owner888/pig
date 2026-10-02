<?php

declare(strict_types=1);

namespace Pig\Mcp\Oauth;

use RuntimeException;

/** The metadata document names a different issuer than the URL it was fetched for. */
final class OauthIssuerMismatchError extends RuntimeException
{
    public function __construct(public readonly string $expected, public readonly string $received)
    {
        parent::__construct('OAuth issuer mismatch: expected ' . json_encode($expected) . ', received ' . json_encode($received));
    }
}
