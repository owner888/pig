<?php

declare(strict_types=1);

namespace Pig\Mcp\Oauth;

use RuntimeException;

/**
 * The metadata document names a different issuer than the URL it was fetched for — or an
 * authorization response carries an `iss` that is not this server's (RFC 9207).
 *
 * `$received` is null when the response lacks the `iss` its server promised to send.
 */
final class OauthIssuerMismatchError extends RuntimeException
{
    public function __construct(public readonly string $expected, public readonly ?string $received)
    {
        parent::__construct(
            $received === null
                ? 'OAuth issuer mismatch: expected ' . json_encode($expected) . ', but the authorization response has no iss parameter'
                : 'OAuth issuer mismatch: expected ' . json_encode($expected) . ', received ' . json_encode($received),
        );
    }
}
