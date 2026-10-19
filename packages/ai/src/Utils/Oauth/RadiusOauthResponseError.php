<?php

declare(strict_types=1);

namespace Pig\Ai\Utils\Oauth;

use RuntimeException;

/** Upstream's `OAuthResponseError` in `auth/oauth/radius.ts`: the status and the OAuth `error` code of a refused request. */
final class RadiusOauthResponseError extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status,
        public readonly ?string $oauthError,
    ) {
        parent::__construct($message);
    }
}
