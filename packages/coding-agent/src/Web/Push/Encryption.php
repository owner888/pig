<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Web\Push;

use RuntimeException;

/**
 * A push message's body, encrypted for one browser: RFC 8291 (Message Encryption for Web Push)
 * over RFC 8188's `aes128gcm`, one record. What the browser gave when it subscribed — its public
 * key (`p256dh`) and the auth secret — is all it takes; the push service in between sees only
 * ciphertext.
 */
final class Encryption
{
    public const int RECORD_SIZE = 4096;

    /**
     * @param string $uaPublic the browser's public key, 65 raw bytes
     * @param string $authSecret the browser's auth secret, 16 raw bytes
     * @param array{private: string, public: string}|null $as this server's key for this message;
     *        a new one each time unless a test gives one (RFC 8291 Appendix A)
     * @param string|null $salt 16 bytes; random unless a test gives it
     * @return string the body to POST, with `Content-Encoding: aes128gcm`
     */
    public static function encrypt(string $plaintext, string $uaPublic, string $authSecret, ?array $as = null, ?string $salt = null): string
    {
        if (strlen($authSecret) !== 16) {
            throw new RuntimeException('A Web Push auth secret is 16 bytes, not ' . strlen($authSecret) . '.');
        }

        // One record: the header, the text, the last-record delimiter, the tag.
        if (strlen($plaintext) + 1 + 16 + 86 > self::RECORD_SIZE) {
            throw new RuntimeException('A push message is at most ' . (self::RECORD_SIZE - 103) . ' bytes.');
        }

        $as ??= P256::generate();
        $salt ??= random_bytes(16);

        $ecdhSecret = P256::sharedSecret(P256::privateKey($as['private'], $as['public']), P256::publicKey($uaPublic));
        $ikm = hash_hkdf('sha256', $ecdhSecret, 32, "WebPush: info\0" . $uaPublic . $as['public'], $authSecret);
        $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);

        $ciphertext = openssl_encrypt($plaintext . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);

        if ($ciphertext === false) {
            throw new RuntimeException('Could not encrypt the push message.');
        }

        return $salt . pack('N', self::RECORD_SIZE) . chr(65) . $as['public'] . $ciphertext . $tag;
    }
}
