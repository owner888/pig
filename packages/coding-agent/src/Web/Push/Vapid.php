<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Web\Push;

/**
 * This server's VAPID identity (RFC 8292): the key pair a browser's subscription is tied to
 * (`applicationServerKey`), and the signed token that proves to the push service a message is from
 * the server the subscription was made for.
 */
final readonly class Vapid
{
    /** Who to contact about the messages, which Apple's push service insists on. */
    public const string SUBJECT = 'https://pigagent.dev';

    /** How long a token is good for: under the 24 hours RFC 8292 allows. */
    public const int LIFETIME = 12 * 3600;

    /**
     * @param string $private the 32-byte scalar
     * @param string $public the 65-byte point
     */
    public function __construct(public string $private, public string $public)
    {
    }

    public static function generate(): self
    {
        $pair = P256::generate();

        return new self($pair['private'], $pair['public']);
    }

    /** The `Authorization` header for a message to `$endpoint`, at `$now` (epoch seconds). */
    public function authorization(string $endpoint, int $now): string
    {
        $parts = parse_url($endpoint);
        $audience = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '');

        $signing = Base64Url::encode((string) json_encode(['typ' => 'JWT', 'alg' => 'ES256']))
            . '.' . Base64Url::encode((string) json_encode(['aud' => $audience, 'exp' => $now + self::LIFETIME, 'sub' => self::SUBJECT], JSON_UNESCAPED_SLASHES));
        $signature = P256::sign($signing, P256::privateKey($this->private, $this->public));

        return 'vapid t=' . $signing . '.' . Base64Url::encode($signature) . ', k=' . Base64Url::encode($this->public);
    }
}
