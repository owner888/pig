<?php

declare(strict_types=1);

namespace Pig\Ai\Test\Utils;

use PHPUnit\Framework\TestCase;
use Pig\Ai\Utils\GaxiosError;
use Pig\Ai\Utils\GoogleAuth;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\Test\AssertsThrows;
use Pig\Test\CannedServer;
use RuntimeException;

/**
 * Application Default Credentials, as `google-auth-library` resolves them.
 *
 * The service-account and gcloud-user flows end to end, against upstream's own runs, are
 * `GoogleVertexTest`'s; this is the signing itself and the metadata server, which those cannot reach.
 */
final class GoogleAuthTest extends TestCase
{
    use AssertsThrows;

    /** @var array<string, string|false> */
    private array $saved = [];

    #[\Override]
    protected function setUp(): void
    {
        Loop::reset();
        GoogleAuth::forgetMetadataServer();

        foreach (array_keys(getenv()) as $name) {
            if (str_starts_with($name, 'GOOGLE_') || str_starts_with($name, 'GCE_METADATA') || preg_match('/proxy/i', $name) === 1) {
                $this->set($name, null);
            }
        }

        $this->set('HOME', '/nonexistent-home');
    }

    #[\Override]
    protected function tearDown(): void
    {
        foreach ($this->saved as $name => $value) {
            putenv($value === false ? $name : "{$name}={$value}");
        }

        $this->saved = [];
        GoogleAuth::forgetMetadataServer();
    }

    public function testTheJwtIsRs256OverTheClaimsAndVerifiesWithTheKeysPublicHalf(): void
    {
        $account = json_decode((string) file_get_contents(__DIR__ . '/../fixtures/vertex/gcp/sa.json'), true);
        $claims = ['iss' => $account['client_email'], 'scope' => GoogleAuth::SCOPE, 'aud' => GoogleAuth::TOKEN_URL, 'exp' => 1_767_326_645, 'iat' => 1_767_323_045];

        $jwt = GoogleAuth::jwt(['alg' => 'RS256'], $claims, $account['private_key']);
        [$header, $payload, $signature] = explode('.', $jwt);
        $decode = static fn (string $part): string => (string) base64_decode(strtr($part, '-_', '+/'), true);

        self::assertSame('{"alg":"RS256"}', $decode($header));
        self::assertSame($claims, json_decode($decode($payload), true));

        $key = openssl_pkey_get_private($account['private_key']);
        self::assertNotFalse($key);
        $public = openssl_pkey_get_details($key)['key'] ?? '';
        self::assertSame(1, openssl_verify("{$header}.{$payload}", $decode($signature), $public, OPENSSL_ALGO_SHA256));
    }

    public function testAKeyThatIsNotOneIsSaidToBeNone(): void
    {
        $this->assertThrows(RuntimeException::class, static fn () => GoogleAuth::jwt(['alg' => 'RS256'], [], 'not a key'), 'Cannot read the service account private key');
    }

    public function testOnComputeEngineTheTokenComesFromTheMetadataServer(): void
    {
        $token = '{"access_token":"ya29.GCE","expires_in":3599,"token_type":"Bearer"}';
        $server = new CannedServer();
        $url = $server->start(["HTTP/1.1 200 OK\r\nmetadata-flavor: Google\r\ncontent-type: application/json\r\ncontent-length: " . strlen($token) . "\r\n\r\n{$token}"]);
        $this->set('GCE_METADATA_HOST', rtrim(substr($url, strlen('http://')), '/'));
        $this->set('METADATA_SERVER_DETECTION', 'assume-present');
        $this->set('GOOGLE_CLOUD_QUOTA_PROJECT', 'qp');

        $headers = Async::run(static fn (): array => (new GoogleAuth(null))->requestHeaders());

        self::assertSame(['authorization' => 'Bearer ya29.GCE', 'x-goog-user-project' => 'qp'], $headers);
        self::assertStringStartsWith(
            "GET /computeMetadata/v1/instance/service-accounts/default/token?scopes=https%3A%2F%2Fwww.googleapis.com%2Fauth%2Fcloud-platform HTTP/1.1\r\n",
            $server->received(),
        );
        self::assertStringContainsStringIgnoringCase("metadata-flavor: Google\r\n", $server->received());
        $server->stop();
    }

    public function testAMetadataAnswerWithoutTheFlavorHeaderIsRefusedInGcpMetadatasWords(): void
    {
        // What upstream's run said when the canned token came back without `Metadata-Flavor`.
        $server = new CannedServer();
        $url = $server->start(["HTTP/1.1 200 OK\r\ncontent-type: application/json\r\ncontent-length: 2\r\n\r\n{}"]);
        $this->set('GCE_METADATA_HOST', rtrim(substr($url, strlen('http://')), '/'));
        $this->set('METADATA_SERVER_DETECTION', 'assume-present');

        $this->assertThrows(
            RuntimeException::class,
            static fn () => Async::run(static fn (): array => (new GoogleAuth(null))->requestHeaders()),
            "Invalid response from metadata service: incorrect Metadata-Flavor header. Expected 'Google', got no header",
        );
        $server->stop();
    }

    public function testNoCredentialsAnywhereIsTheLibrarysOwnSentence(): void
    {
        $this->set('METADATA_SERVER_DETECTION', 'none');

        $this->assertThrows(RuntimeException::class, static fn () => Async::run(static fn (): array => (new GoogleAuth(null))->requestHeaders()), GoogleAuth::NO_ADC_FOUND);
    }

    public function testAnUnknownDetectionModeIsRefusedRatherThanGuessed(): void
    {
        $this->set('METADATA_SERVER_DETECTION', 'sometimes');

        $this->assertThrows(
            RuntimeException::class,
            static fn () => Async::run(static fn (): array => (new GoogleAuth(null))->requestHeaders()),
            'Unknown `METADATA_SERVER_DETECTION` env variable. Got `sometimes`',
        );
    }

    public function testATokenEndpointRefusalIsAGaxiosErrorWithItsStatus(): void
    {
        $body = '{"error":"invalid_grant","error_description":"Bad Request"}';
        $server = new CannedServer();
        $url = $server->start(["HTTP/1.1 400 Bad Request\r\ncontent-type: application/json\r\ncontent-length: " . strlen($body) . "\r\n\r\n{$body}"]);

        $error = $this->assertThrows(
            GaxiosError::class,
            static fn () => Async::run(static fn (): array => (new GoogleAuth(__DIR__ . '/../fixtures/vertex/gcp/user.json', tokenUrl: rtrim($url, '/') . '/token'))->requestHeaders()),
        );

        self::assertInstanceOf(GaxiosError::class, $error);
        self::assertSame(400, $error->status);
        $server->stop();
    }

    private function set(string $name, ?string $value): void
    {
        if (!array_key_exists($name, $this->saved)) {
            $this->saved[$name] = getenv($name);
        }

        putenv($value === null ? $name : "{$name}={$value}");
    }
}
