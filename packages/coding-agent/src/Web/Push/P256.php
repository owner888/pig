<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Web\Push;

use OpenSSLAsymmetricKey;
use RuntimeException;

/**
 * The P-256 pieces Web Push is made of, over `ext-openssl`: a key pair as raw bytes (what the
 * browser hands out and wants back), the PEM openssl takes, ECDH, and ECDSA signatures in the
 * JOSE shape. Raw public keys are the 65-byte uncompressed point `04 || x || y`.
 */
final class P256
{
    /** The DER of a P-256 SubjectPublicKeyInfo up to its point (RFC 5480). */
    private const string SPKI_PREFIX = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

    /** @return array{private: string, public: string} a new key pair: the 32-byte scalar and the point */
    public static function generate(): array
    {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);

        if ($key === false) {
            throw new RuntimeException('Could not make a P-256 key: ' . self::opensslError());
        }

        $ec = openssl_pkey_get_details($key)['ec'] ?? null;

        if (!is_array($ec)) {
            throw new RuntimeException('openssl made a key that is not an EC key.');
        }

        return [
            'private' => str_pad($ec['d'], 32, "\0", STR_PAD_LEFT),
            'public' => "\x04" . str_pad($ec['x'], 32, "\0", STR_PAD_LEFT) . str_pad($ec['y'], 32, "\0", STR_PAD_LEFT),
        ];
    }

    public static function publicKey(string $point): OpenSSLAsymmetricKey
    {
        self::mustBePoint($point);
        $der = (string) hex2bin(self::SPKI_PREFIX) . $point;
        $key = openssl_pkey_get_public(self::pem('PUBLIC KEY', $der));

        if ($key === false) {
            throw new RuntimeException('Not a P-256 public key: ' . self::opensslError());
        }

        return $key;
    }

    /** The private key `$scalar`, whose public point is `$point` (RFC 5915's ECPrivateKey). */
    public static function privateKey(string $scalar, string $point): OpenSSLAsymmetricKey
    {
        self::mustBePoint($point);

        if (strlen($scalar) !== 32) {
            throw new RuntimeException('A P-256 private key is 32 bytes, not ' . strlen($scalar) . '.');
        }

        $der = "\x30\x77\x02\x01\x01\x04\x20" . $scalar
            . "\xa0\x0a\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07"
            . "\xa1\x44\x03\x42\x00" . $point;
        $key = openssl_pkey_get_private(self::pem('EC PRIVATE KEY', $der));

        if ($key === false) {
            throw new RuntimeException('Not a P-256 private key: ' . self::opensslError());
        }

        return $key;
    }

    /** ECDH: the shared x coordinate, 32 bytes. */
    public static function sharedSecret(OpenSSLAsymmetricKey $private, OpenSSLAsymmetricKey $public): string
    {
        $secret = openssl_pkey_derive($public, $private, 32);

        if ($secret === false) {
            throw new RuntimeException('ECDH failed: ' . self::opensslError());
        }

        return $secret;
    }

    /** ES256 (RFC 7518 §3.4): SHA-256, the signature as `r || s`, 32 bytes each — not DER. */
    public static function sign(string $data, OpenSSLAsymmetricKey $private): string
    {
        if (!openssl_sign($data, $der, $private, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Could not sign: ' . self::opensslError());
        }

        // SEQUENCE { INTEGER r, INTEGER s }, each a minimal two's-complement integer.
        $at = 2 + (ord($der[1]) & 0x80 ? ord($der[1]) & 0x7f : 0);
        $raw = '';

        for ($i = 0; $i < 2; $i++) {
            $length = ord($der[$at + 1]);
            $int = ltrim(substr($der, $at + 2, $length), "\0");
            $raw .= str_pad($int, 32, "\0", STR_PAD_LEFT);
            $at += 2 + $length;
        }

        return $raw;
    }

    private static function mustBePoint(string $point): void
    {
        if (strlen($point) !== 65 || $point[0] !== "\x04") {
            throw new RuntimeException('A P-256 public key is 65 bytes starting 0x04.');
        }
    }

    private static function pem(string $label, string $der): string
    {
        return "-----BEGIN {$label}-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END {$label}-----\n";
    }

    private static function opensslError(): string
    {
        $errors = [];

        while (($error = openssl_error_string()) !== false) {
            $errors[] = $error;
        }

        return $errors === [] ? 'no reason given' : implode('; ', $errors);
    }
}
