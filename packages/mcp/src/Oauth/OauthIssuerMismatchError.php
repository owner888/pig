<?php

declare(strict_types=1);

namespace Pig\Mcp\Oauth;

use RuntimeException;

/**
 * The metadata document names a different issuer than the URL it was fetched for — or an
 * authorization response carries an `iss` that is not this server's (RFC 9207).
 *
 * `$expected` is null when no authorization server metadata was discovered; `$received` is null
 * when the response lacks the `iss` its server promised to send.
 */
final class OauthIssuerMismatchError extends RuntimeException
{
    public function __construct(public readonly ?string $expected, public readonly ?string $received)
    {
        $format = static fn (?string $issuer): string => $issuer === null ? 'none' : (string) json_encode($issuer, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        parent::__construct("OAuth issuer mismatch: expected {$format($expected)}, received {$format($received)}");
    }
}
