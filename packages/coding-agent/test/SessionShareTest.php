<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;
use Pig\CodingAgent\Export\SessionShare;

/** Upstream's `getShareViewerUrl()`: the override, or pigagent.dev's viewer, then `#<gist id>`. */
final class SessionShareTest extends TestCase
{
    public function testTheViewerIsPigagentDevUnlessTheEnvironmentNamesAnother(): void
    {
        $this->assertSame('https://pigagent.dev/session/#abc', SessionShare::viewerUrl('abc', []));
        $this->assertSame('https://pigagent.dev/session/#abc', SessionShare::viewerUrl('abc', ['PIG_SHARE_VIEWER_URL' => '']));
        $this->assertSame('https://v.test/#abc', SessionShare::viewerUrl('abc', ['PIG_SHARE_VIEWER_URL' => 'https://v.test/']));
    }

    // ---- the Radius road -------------------------------------------------------------------

    public function testARadiusUploadPostsTheJsonlAndAnswersTheArtifactsUrl(): void
    {
        $server = new \Pig\Test\CannedServer();
        $body = '{"artifact":{"canonical_url":"https://radius.test/a/xyz"}}';
        $gateway = $server->start(["HTTP/1.1 201 Created\r\nContent-Type: application/json\r\nContent-Length: " . strlen($body) . "\r\n\r\n{$body}"]);

        $url = \Pig\Async\Async::run(static fn (): string => SessionShare::uploadToRadius("{\"type\":\"session\"}\n", 'tok', new \Pig\Ai\Http\HttpClient(5.0), gateway: rtrim($gateway, '/')));

        $this->assertSame('https://radius.test/a/xyz', $url);
        $this->assertStringContainsString('POST /v1/artifacts?visibility=organization&title=Pi%20session ', $server->receivedHead());
        $this->assertStringContainsStringIgnoringCase('authorization: Bearer tok', $server->receivedHead());
        $this->assertStringContainsStringIgnoringCase('content-type: application/x-ndjson', $server->receivedHead());
    }

    public function testARefusedRadiusUploadSaysWhyInUpstreamsWords(): void
    {
        $server = new \Pig\Test\CannedServer();
        $body = '{"error":"not a member of an organization"}';
        $gateway = $server->start(["HTTP/1.1 403 Forbidden\r\nContent-Type: application/json\r\nContent-Length: " . strlen($body) . "\r\n\r\n{$body}"]);

        try {
            \Pig\Async\Async::run(static fn (): string => SessionShare::uploadToRadius('{}', 'tok', new \Pig\Ai\Http\HttpClient(5.0), gateway: rtrim($gateway, '/')));
            $this->fail('no exception');
        } catch (\RuntimeException $error) {
            $this->assertSame('Failed to upload Radius artifact: not a member of an organization', $error->getMessage());
        }
    }
}
