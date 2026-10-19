<?php

declare(strict_types=1);

namespace Pig\Ai\Test\Utils;

use PHPUnit\Framework\TestCase;
use Pig\Ai\Utils\Oauth\Credentials;
use Pig\Ai\Utils\Oauth\Radius;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\Test\CannedServer;
use Throwable;

/**
 * The Radius gateway's sign-in, against a canned gateway: upstream's `auth/oauth/radius.ts` — the
 * device code through `authorization_pending`, a denied one, the refresh, and the browser's
 * discovery refused.
 */
final class RadiusOauthTest extends TestCase
{
    #[\Override]
    protected function setUp(): void
    {
        Loop::reset();
    }

    public function testTheDeviceCodeIsShownAndPolledUntilTheGatewayAnswers(): void
    {
        $server = new CannedServer();
        $base = $server->startSequence([
            self::reply(200, ['device_code' => 'dev-1', 'user_code' => 'ABCD-EFGH', 'verification_uri' => 'https://radius.example/device', 'expires_in' => 60, 'interval' => 1]),
            self::reply(400, ['error' => 'authorization_pending']),
            self::reply(200, ['access_token' => 'at-1', 'refresh_token' => 'rt-1', 'expires_in' => 3600, 'scope' => 'gateway offline_access']),
        ]);
        $shown = [];
        $asked = [];

        [$result, $thrown] = self::inLoop(static function () use ($base, &$shown, &$asked): ?Credentials {
            return (new Radius(gateway: $base))->login(
                static function (string $url, ?string $instructions) use (&$shown): void {
                    $shown[] = [$url, $instructions];
                },
                onSelect: static function (string $title, array $options) use (&$asked): string {
                    $asked[] = [$title, array_column($options, 1)];

                    return Radius::METHOD_DEVICE_CODE;
                },
            );
        });
        $received = $server->received();
        $server->stop();

        $this->assertNull($thrown);
        $this->assertSame([['Sign in to Radius:', ['Sign in with browser (recommended)', 'Sign in with device code (when signing in from another device)']]], $asked);
        $this->assertSame([['https://radius.example/device', 'Enter code: ABCD-EFGH']], $shown);
        $this->assertInstanceOf(Credentials::class, $result);
        $this->assertSame(['at-1', 'rt-1'], [$result->access, $result->refresh]);
        // An hour, less upstream's minute of skew.
        $this->assertEqualsWithDelta(time() * 1000 + 3_540_000, $result->expires, 5_000);
        $this->assertStringContainsString('POST /v1/oauth/device', $received);
        $this->assertStringContainsString('client_id=pi-gateway&scope=gateway+offline_access', $received);
        $this->assertSame(2, substr_count($received, 'grant_type=urn%3Aietf%3Aparams%3Aoauth%3Agrant-type%3Adevice_code&client_id=pi-gateway&device_code=dev-1'));
    }

    public function testADeniedDeviceCodeSaysSo(): void
    {
        $server = new CannedServer();
        $base = $server->startSequence([
            self::reply(200, ['device_code' => 'd', 'user_code' => 'U', 'verification_uri' => 'https://x/device', 'expires_in' => 60]),
            self::reply(400, ['error' => 'access_denied']),
        ]);

        [, $thrown] = self::inLoop(static fn (): ?Credentials => (new Radius(gateway: $base))->login(
            static function (): void {},
            onSelect: static fn (): string => Radius::METHOD_DEVICE_CODE,
        ));
        $server->stop();

        $this->assertSame('Device authorization was denied.', $thrown);
    }

    public function testTheRefreshIsATokenRequestOnTheGateway(): void
    {
        $server = new CannedServer();
        $base = $server->startSequence([self::reply(200, ['access_token' => 'at-2', 'refresh_token' => 'rt-2', 'expires_in' => 120])]);

        [$result] = self::inLoop(static fn (): Credentials => (new Radius(gateway: $base))->refresh('rt-1'));
        $received = $server->received();
        $server->stop();

        $this->assertSame('at-2', $result->access);
        $this->assertStringContainsString('POST /v1/oauth/token', $received);
        $this->assertStringContainsString('grant_type=refresh_token&client_id=pi-gateway&refresh_token=rt-1', $received);
    }

    public function testARefusedTokenRequestNamesTheOauthError(): void
    {
        $server = new CannedServer();
        $base = $server->startSequence([self::reply(400, ['error' => 'invalid_grant', 'error_description' => 'expired'])]);

        [, $thrown] = self::inLoop(static fn (): Credentials => (new Radius(gateway: $base))->refresh('rt-1'));
        $server->stop();

        $this->assertSame('Radius OAuth token request failed: invalid_grant: expired', $thrown);
    }

    public function testTheBrowserFlowNeedsTheGatewaysAuthorizationEndpoint(): void
    {
        $server = new CannedServer();
        $base = $server->startSequence([self::reply(200, ['nope' => true])]);

        [, $thrown] = self::inLoop(static fn (): ?Credentials => (new Radius(gateway: $base))->login(
            static function (): void {},
            onSelect: static fn (): string => Radius::METHOD_BROWSER,
        ));
        $received = $server->received();
        $server->stop();

        $this->assertStringContainsString('GET /v1/oauth ', $received);
        $this->assertSame('Invalid Radius OAuth config from ' . rtrim($base, '/'), $thrown);
    }

    public function testAGatewayWithoutASchemeIsHttps(): void
    {
        $this->assertSame('https://radius.example', Radius::normalizeGatewayUrl('radius.example/'));
        $this->assertSame('http://127.0.0.1:9', Radius::normalizeGatewayUrl('http://127.0.0.1:9'));
    }

    /** @return array{mixed, ?string} */
    private static function inLoop(\Closure $call): array
    {
        $result = null;
        $thrown = null;

        Async::run(static function () use ($call, &$result, &$thrown): void {
            try {
                $result = $call();
            } catch (Throwable $error) {
                $thrown = $error->getMessage();
            }
        });

        return [$result, $thrown];
    }

    /** @return list<string> */
    private static function reply(int $status, array $body): array
    {
        $json = (string) json_encode($body);
        $reason = $status === 200 ? 'OK' : 'Bad Request';

        return ["HTTP/1.1 {$status} {$reason}\r\ncontent-type: application/json\r\ncontent-length: " . strlen($json) . "\r\n\r\n" . $json];
    }
}
