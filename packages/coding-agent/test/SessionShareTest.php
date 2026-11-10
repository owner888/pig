<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;
use Pig\CodingAgent\Export\SessionShare;

/**
 * `/share`'s two roads: upstream's `getShareViewerUrl()` for a gist, and pig's own encrypted share on
 * pigagent.dev.
 */
final class SessionShareTest extends TestCase
{
    public function testTheViewerIsPigagentDevUnlessTheEnvironmentNamesAnother(): void
    {
        $this->assertSame('https://pigagent.dev/session/#abc', SessionShare::viewerUrl('abc', []));
        $this->assertSame('https://pigagent.dev/session/#abc', SessionShare::viewerUrl('abc', ['PIG_SHARE_VIEWER_URL' => '']));
        $this->assertSame('https://v.test/#abc', SessionShare::viewerUrl('abc', ['PIG_SHARE_VIEWER_URL' => 'https://v.test/']));
    }

    // ---- pigagent.dev -----------------------------------------------------------------------

    public function testTheServerIsPigagentDevUnlessTheEnvironmentNamesAnother(): void
    {
        $this->assertSame('https://pigagent.dev', SessionShare::server([]));
        $this->assertSame('http://127.0.0.1:9', SessionShare::server(['PIG_SHARE_SERVER' => 'http://127.0.0.1:9/']));
        $this->assertSame('https://pigagent.dev/s/abc#key', SessionShare::url('abc', 'key', 'https://pigagent.dev'));
    }

    public function testASealedPageOpensOnlyWithItsKeyAndIsNotThePage(): void
    {
        $page = '<html>' . str_repeat('the session ', 200) . '</html>';
        $sealed = SessionShare::seal($page);

        $this->assertStringNotContainsString('the session', $sealed['body']);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $sealed['key'], '32 bytes, base64url, no padding: a fragment as it is');
        $this->assertSame($page, SessionShare::open($sealed['body'], $sealed['key']));
        $this->assertNotSame($sealed['key'], SessionShare::seal($page)['key'], 'a key per share');

        $other = SessionShare::seal('x')['key'];
        $this->expectException(\RuntimeException::class);
        SessionShare::open($sealed['body'], $other);
    }

    public function testAnUploadPostsTheCiphertextAndAnswersTheShare(): void
    {
        $server = new \Pig\Test\CannedServer();
        $body = '{"id":"0123456789abcdef01234567","deleteToken":"del-tok","expiresAt":1900000000}';
        $base = $server->start(["HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: " . strlen($body) . "\r\n\r\n{$body}"]);

        $share = \Pig\Async\Async::run(static fn (): array => SessionShare::upload('sealed bytes', 7, new \Pig\Ai\Http\HttpClient(5.0), server: rtrim($base, '/')));

        $this->assertSame(['id' => '0123456789abcdef01234567', 'deleteToken' => 'del-tok', 'expiresAt' => 1_900_000_000], $share);
        $this->assertStringContainsString('POST /api/shares?days=7 ', $server->receivedHead());
        $this->assertStringContainsStringIgnoringCase('content-type: application/octet-stream', $server->receivedHead());
        $this->assertStringEndsWith("\r\n\r\nsealed bytes", $server->received());
    }

    public function testARefusedUploadSaysTheServersReason(): void
    {
        $server = new \Pig\Test\CannedServer();
        $body = '{"error":"too large"}';
        $base = $server->start(["HTTP/1.1 413 Payload Too Large\r\nContent-Type: application/json\r\nContent-Length: " . strlen($body) . "\r\n\r\n{$body}"]);

        try {
            \Pig\Async\Async::run(static fn (): array => SessionShare::upload('x', 30, new \Pig\Ai\Http\HttpClient(5.0), server: rtrim($base, '/')));
            $this->fail('no exception');
        } catch (\RuntimeException $error) {
            $this->assertSame('pigagent.dev refused the share: too large', $error->getMessage());
        }
    }

    public function testARateLimitSaysTheFrameworksMessage(): void
    {
        $server = new \Pig\Test\CannedServer();
        $body = '{"success":false,"error":{"code":10429,"message":"Rate limit exceeded. Try again in 40 seconds."}}';
        $base = $server->start(["HTTP/1.1 429 Too Many Requests\r\nContent-Type: application/json\r\nContent-Length: " . strlen($body) . "\r\n\r\n{$body}"]);

        try {
            \Pig\Async\Async::run(static fn (): array => SessionShare::upload('x', 30, new \Pig\Ai\Http\HttpClient(5.0), server: rtrim($base, '/')));
            $this->fail('no exception');
        } catch (\RuntimeException $error) {
            $this->assertSame('pigagent.dev refused the share: Rate limit exceeded. Try again in 40 seconds.', $error->getMessage());
        }
    }

    public function testADeleteSendsTheTokenAndAShareAlreadyGoneIsNotAnError(): void
    {
        $server = new \Pig\Test\CannedServer();
        $base = $server->start(["HTTP/1.1 404 Not Found\r\nContent-Length: 0\r\n\r\n"]);

        \Pig\Async\Async::run(static fn () => SessionShare::delete('0123456789abcdef', 'del-tok', new \Pig\Ai\Http\HttpClient(5.0), server: rtrim($base, '/')));

        $this->assertStringContainsString('DELETE /api/shares/0123456789abcdef ', $server->receivedHead());
        $this->assertStringContainsStringIgnoringCase('authorization: Bearer del-tok', $server->receivedHead());
    }
}
