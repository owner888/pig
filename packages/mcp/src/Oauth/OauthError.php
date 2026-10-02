<?php

declare(strict_types=1);

namespace Pig\Mcp\Oauth;

use RuntimeException;

/**
 * An OAuth error the authorization server named (`invalid_grant`, `invalid_client`, …).
 *
 * `$oauthCode` and not `$code`: `Exception::$code` is an int and not readonly, so the name is taken.
 */
final class OauthError extends RuntimeException
{
    public function __construct(
        public readonly string $oauthCode,
        string $message = '',
        public readonly ?string $errorUri = null,
    ) {
        parent::__construct($message !== '' ? $message : $oauthCode);
    }
}
