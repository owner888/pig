<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Web\Push;

use RuntimeException;

/** Base64url without padding (RFC 4648 §5), how every key and token in Web Push is written. */
final class Base64Url
{
    public static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    public static function decode(string $text): string
    {
        $bytes = base64_decode(strtr($text, '-_', '+/'), true);

        if ($bytes === false) {
            throw new RuntimeException('Not base64url: ' . $text);
        }

        return $bytes;
    }
}
