<?php

declare(strict_types=1);

namespace Pig\Ai\Utils\Oauth;

use Pig\Ai\Timestamp;

/**
 * What a finished sign-in leaves behind.
 *
 * Upstream's `OAuthCredentials`, field for field and under upstream's own names, because this
 * is what gets written to disk beside pi's — a credentials file one tool wrote is read by the
 * other, the same way the session file is.
 *
 * `access` is the token that goes on a request and expires in hours; `refresh` is the one that
 * buys a new `access` and lasts until it is revoked. The last three are each for one provider
 * and are null for the rest: a GitHub Enterprise host, a Google Cloud project, the address
 * a token was issued to.
 */
final readonly class Credentials
{
    /**
     * @param int $expires milliseconds since the epoch, **already** short of the real expiry
     *        by the safety margin the flow subtracted — a token that expires while in flight
     *        fails the request it was attached to, so every flow here hands back a moment it
     *        is certainly still good at
     */
    public function __construct(
        public string $refresh,
        public string $access,
        public int $expires,
        public ?string $enterpriseUrl = null,
        public ?string $projectId = null,
        public ?string $email = null,
    ) {
    }

    /** Upstream's `Date.now() >= creds.expires`, and the moment is injectable so it can be tested. */
    public function hasExpired(?int $now = null): bool
    {
        return ($now ?? Timestamp::nowMs()) >= $this->expires;
    }
}
