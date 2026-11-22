<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test\Web\Push;

use PHPUnit\Framework\TestCase;
use Pig\CodingAgent\Web\Push\Base64Url;
use Pig\CodingAgent\Web\Push\Encryption;
use Pig\CodingAgent\Web\Push\P256;

/**
 * A push message's body is RFC 8291's: byte for byte the RFC's own example, and what a browser
 * holding the subscription's private key reads back.
 */
final class EncryptionTest extends TestCase
{
    public function testTheRfcExampleComesOutByteForByte(): void
    {
        // RFC 8291, Appendix A.
        $body = Encryption::encrypt(
            'When I grow up, I want to be a watermelon',
            Base64Url::decode('BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4'),
            Base64Url::decode('BTBZMqHH6r4Tts7J_aSIgg'),
            [
                'private' => Base64Url::decode('yfWPiYE-n46HLnH0KqZOF1fJJU3MYrct3AELtAQ-oRw'),
                'public' => Base64Url::decode('BP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A8'),
            ],
            Base64Url::decode('DGv6ra1nlYgDCS1FRnbzlw'),
        );

        $this->assertSame(
            'DGv6ra1nlYgDCS1FRnbzlwAAEABBBP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A_yl95bQpu6cVPTpK4Mqgkf1CXztLVBSt2Ks3oZwbuwXPXLWyouBWLVWGNWQexSgSxsj_Qulcy4a-fN',
            Base64Url::encode($body),
        );
    }

    public function testTheBrowserReadsWhatWasSentWithAFreshKeyEachTime(): void
    {
        $browser = P256::generate();
        $auth = random_bytes(16);
        $text = '{"title":"Done · pig","body":"已完成"}';

        $first = Encryption::encrypt($text, $browser['public'], $auth);
        $second = Encryption::encrypt($text, $browser['public'], $auth);

        $this->assertNotSame(substr($first, 21, 65), substr($second, 21, 65), 'a new server key for every message');
        $this->assertSame($text, self::decrypt($first, $browser, $auth));
        $this->assertSame($text, self::decrypt($second, $browser, $auth));
    }

    public function testAMessageTooLongForOneRecordIsRefused(): void
    {
        $this->expectException(\RuntimeException::class);
        Encryption::encrypt(str_repeat('x', 4000), P256::generate()['public'], random_bytes(16));
    }

    /**
     * The browser's half of RFC 8291, here so the test does not take the sender's word for it.
     *
     * @param array{private: string, public: string} $browser
     */
    public static function decrypt(string $body, array $browser, string $auth): string
    {
        $salt = substr($body, 0, 16);
        $idLength = ord($body[20]);
        $serverPublic = substr($body, 21, $idLength);
        $record = substr($body, 21 + $idLength);

        $secret = P256::sharedSecret(P256::privateKey($browser['private'], $browser['public']), P256::publicKey($serverPublic));
        $ikm = hash_hkdf('sha256', $secret, 32, "WebPush: info\0" . $browser['public'] . $serverPublic, $auth);
        $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);

        $plain = openssl_decrypt(substr($record, 0, -16), 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, substr($record, -16));
        self::assertIsString($plain);
        self::assertSame("\x02", substr($plain, -1), 'one record, the last');

        return substr($plain, 0, -1);
    }
}
