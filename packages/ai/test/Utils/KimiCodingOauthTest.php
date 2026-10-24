<?php

declare(strict_types=1);

namespace Pig\Ai\Test\Utils;

use PHPUnit\Framework\TestCase;
use Pig\Ai\Http\HttpClient;
use Pig\Ai\Timestamp;
use Pig\Ai\Utils\Oauth\Credentials;
use Pig\Ai\Utils\Oauth\KimiCoding;
use Pig\Ai\Utils\Oauth\OauthError;
use Pig\Ai\Utils\Oauth\Provider;
use Pig\Async\Async;
use Pig\Test\CannedServer;

/** Kimi Code's device sign-in and renewal, against a loopback server playing auth.kimi.com. */
final class KimiCodingOauthTest extends TestCase
{
    public function testADeviceSignInShowsWhereToApproveItAndPollsUntilItIsApproved(): void
    {
        $server = new CannedServer();
        $host = rtrim($server->startSequence([
            [self::json(200, [
                'device_code' => 'dev-1',
                'user_code' => 'ABCD-EFGH',
                'verification_uri' => 'https://www.kimi.com/code/device',
                'verification_uri_complete' => 'https://www.kimi.com/code/device?user_code=ABCD-EFGH',
                'interval' => 1,
                'expires_in' => 60,
            ])],
            [self::json(400, ['error' => 'authorization_pending'])],
            [self::json(200, ['access_token' => 'acc', 'refresh_token' => 'ref', 'expires_in' => 3600])],
        ]), '/');
        $shown = [];
        $before = Timestamp::nowMs();

        $credentials = Async::run(static function () use ($host, &$shown): Credentials {
            return (new KimiCoding(new HttpClient(5.0), $host))->login(static function (string $url, ?string $instructions) use (&$shown): void {
                $shown = [$url, $instructions];
            });
        });

        $this->assertSame(['https://www.kimi.com/code/device?user_code=ABCD-EFGH', 'Enter code: ABCD-EFGH'], $shown);
        $this->assertSame(['acc', 'ref'], [$credentials->access, $credentials->refresh]);
        $this->assertGreaterThanOrEqual($before + 3_600_000, $credentials->expires);
        $this->assertStringContainsString('POST /api/oauth/device_authorization', $server->received());
        $this->assertStringContainsString('client_id=' . KimiCoding::CLIENT_ID, $server->received());
        $this->assertStringContainsString('grant_type=urn%3Aietf%3Aparams%3Aoauth%3Agrant-type%3Adevice_code', $server->received());
    }

    public function testADeniedSignInSaysSo(): void
    {
        $server = new CannedServer();
        $host = rtrim($server->startSequence([
            [self::json(200, ['device_code' => 'd', 'user_code' => 'u', 'verification_uri' => 'https://kimi.test/d', 'verification_uri_complete' => 'https://kimi.test/d?u', 'interval' => 1])],
            [self::json(400, ['error' => 'access_denied'])],
        ]), '/');

        $this->expectException(OauthError::class);
        $this->expectExceptionMessage('Kimi Code login was denied.');
        Async::run(static fn () => (new KimiCoding(new HttpClient(5.0), $host))->login(static fn () => null));
    }

    public function testAVerificationAddressThatIsNotHttpIsRefused(): void
    {
        // "The verification URI is opened in the user's browser; only http(s) URLs are trusted."
        $server = new CannedServer();
        $host = rtrim($server->start([self::json(200, ['device_code' => 'd', 'user_code' => 'u', 'verification_uri' => 'https://kimi.test/d', 'verification_uri_complete' => 'file:///etc/passwd'])]), '/');

        $this->expectException(OauthError::class);
        $this->expectExceptionMessage('Invalid Kimi Code device authorization response');
        Async::run(static fn () => (new KimiCoding(new HttpClient(5.0), $host))->login(static fn () => null));
    }

    public function testARenewalThatIsRefusedOutrightIsNotRetried(): void
    {
        $server = new CannedServer();
        $host = rtrim($server->start([self::json(400, ['error' => 'invalid_grant', 'error_description' => 'revoked'])]), '/');

        try {
            Async::run(static fn () => (new KimiCoding(new HttpClient(5.0), $host))->refresh('old'));
            $this->fail('no exception');
        } catch (OauthError $error) {
            $this->assertSame('Kimi Code token refresh unauthorized (status 400): revoked', $error->getMessage());
        }

        $this->assertSame(1, $server->connections());
    }

    public function testARenewalRetriesABusyServer(): void
    {
        $server = new CannedServer();
        $host = rtrim($server->startSequence([
            [self::json(503, ['error' => 'busy'])],
            [self::json(200, ['access_token' => 'new', 'refresh_token' => 'ref2', 'expires_in' => 60])],
        ]), '/');

        $credentials = Async::run(static fn (): Credentials => (new KimiCoding(new HttpClient(5.0), $host))->refresh('old'));

        $this->assertSame(['new', 'ref2'], [$credentials->access, $credentials->refresh]);
        $this->assertStringContainsString('grant_type=refresh_token&refresh_token=old', $server->received());
    }

    public function testItIsABuiltInSubscriptionSignInForKimiCoding(): void
    {
        $this->assertSame('kimi-coding', Provider::KimiCoding->value);
        $this->assertSame('Kimi Code (subscription)', Provider::KimiCoding->label());
        $this->assertTrue(Provider::KimiCoding->isSubscription());
        $this->assertTrue(Provider::KimiCoding->available());
        $this->assertSame('tok', Provider::KimiCoding->apiKey(new Credentials('r', 'tok', 0)));
        $this->assertSame(KimiCoding::DEFAULT_OAUTH_HOST, (new KimiCoding())->oauthHost());
    }

    /** @param array<string, mixed> $body */
    private static function json(int $status, array $body): string
    {
        $text = (string) json_encode($body, JSON_UNESCAPED_SLASHES);
        $reason = match ($status) {
            200 => 'OK',
            400 => 'Bad Request',
            503 => 'Service Unavailable',
        };

        return "HTTP/1.1 {$status} {$reason}\r\nContent-Type: application/json\r\nContent-Length: " . strlen($text) . "\r\nConnection: close\r\n\r\n{$text}";
    }
}
