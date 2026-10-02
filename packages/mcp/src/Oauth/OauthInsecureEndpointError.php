<?php

declare(strict_types=1);

namespace Pig\Mcp\Oauth;

use RuntimeException;

/** A token endpoint that is neither HTTPS nor loopback gets no credentials. */
final class OauthInsecureEndpointError extends RuntimeException
{
    public function __construct(public readonly string $endpoint)
    {
        parent::__construct("Refusing to send OAuth credentials to non-HTTPS endpoint {$endpoint}");
    }
}
