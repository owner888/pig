<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test\Web\Push;

use PHPUnit\Framework\TestCase;
use Pig\CodingAgent\Web\Push\Base64Url;
use Pig\CodingAgent\Web\Push\P256;
use Pig\CodingAgent\Web\Push\Vapid;

/**
 * The `Authorization` a push service is sent (RFC 8292): a JWT for the service's origin, good for
 * less than a day, signed ES256 — `r || s`, which a DER signature converted wrongly is not, one
 * time in a hundred or so, when `r` or `s` starts with a zero byte.
 */
final class VapidTest extends TestCase
{
    public function testTheTokenIsForThePushServiceAndVerifiesWithTheKeyItNames(): void
    {
        $vapid = Vapid::generate();
        $now = 1_800_000_000;

        for ($i = 0; $i < 200; $i++) {
            $header = $vapid->authorization('https://web.push.apple.com:443/QGuQyavX?x=1', $now);
            $this->assertSame(1, preg_match('/^vapid t=([\w-]+)\.([\w-]+)\.([\w-]+), k=([\w-]+)$/', $header, $m));

            $this->assertSame(['typ' => 'JWT', 'alg' => 'ES256'], json_decode(Base64Url::decode($m[1]), true));
            $this->assertSame(
                ['aud' => 'https://web.push.apple.com:443', 'exp' => $now + Vapid::LIFETIME, 'sub' => Vapid::SUBJECT],
                json_decode(Base64Url::decode($m[2]), true),
            );
            $this->assertSame($vapid->public, Base64Url::decode($m[4]));

            $signature = Base64Url::decode($m[3]);
            $this->assertSame(64, strlen($signature));
            $this->assertSame(1, openssl_verify("{$m[1]}.{$m[2]}", self::der($signature), P256::publicKey($vapid->public), OPENSSL_ALGO_SHA256));
        }
    }

    /** `r || s` as the DER openssl verifies. */
    private static function der(string $raw): string
    {
        $int = static function (string $bytes): string {
            $bytes = ltrim($bytes, "\0");
            $bytes = ord($bytes[0] ?? "\0") & 0x80 ? "\0" . $bytes : $bytes;

            return "\x02" . chr(strlen($bytes)) . $bytes;
        };
        $body = $int(substr($raw, 0, 32)) . $int(substr($raw, 32));

        return "\x30" . chr(strlen($body)) . $body;
    }
}
