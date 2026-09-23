<?php

declare(strict_types=1);

namespace Pig\Ai\Utils\Oauth;

/**
 * The pair that makes an authorization code safe to hand through a browser.
 *
 * Upstream's `utils/oauth/pkce.ts`. A random `verifier` is kept here and never leaves the
 * process; its SHA-256, as `challenge`, is what goes in the URL. So the code that comes back
 * is worth nothing to anyone who intercepted the URL: redeeming it needs the verifier too.
 *
 * Two differences from upstream, both because PHP has what JavaScript had to reach for:
 * `create()` is **not asynchronous** — Web Crypto's `digest()` returns a promise and
 * `hash()` returns a string — and the randomness is `random_bytes()`, which is a CSPRNG and
 * *throws* when it cannot be one. `crypto.getRandomValues` has no failure mode to report, so
 * upstream has nothing to handle; here a machine with no entropy source stops the sign-in
 * rather than continuing with something guessable.
 */
final readonly class Pkce
{
    /** 32 bytes, which is what upstream generates and what base64url turns into 43 characters. */
    private const int BYTES = 32;

    private function __construct(
        public string $verifier,
        public string $challenge,
    ) {
    }

    public static function create(): self
    {
        $verifier = self::base64url(random_bytes(self::BYTES));

        return new self($verifier, self::base64url(hash('sha256', $verifier, true)));
    }

    /**
     * base64, in the alphabet a URL can carry.
     *
     * The padding is stripped rather than escaped: RFC 7636 says the challenge is
     * base64url **without** padding, and a `%3D` in its place is a different string as far
     * as the server comparing them is concerned.
     */
    private static function base64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
