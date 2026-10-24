<?php

declare(strict_types=1);

namespace Pig\Ai\Test;

use PHPUnit\Framework\TestCase;
use Pig\Ai\Api;
use Pig\Ai\ClientAttribution;
use Pig\Ai\ClientIdentity;
use Pig\Ai\Context;
use Pig\Ai\Model;
use Pig\Ai\Providers\OpenAiCompletions;
use Pig\Ai\Providers\OpenAiOptions;
use Pig\Ai\UserMessage;
use Pig\Async\Async;
use Pig\Test\CannedServer;

/** Upstream's `identity.ts`: the `User-Agent`, the attribution headers, and how identities merge. */
final class ClientIdentityTest extends TestCase
{
    public function testTheUserAgentIsDetailedShortOrCustom(): void
    {
        $this->assertMatchesRegularExpression('/^pig\/1\.2\.3 \((darwin|linux|win32|[a-z]+); php\/\S+; [a-z0-9_]+\)$/', ClientIdentity::formatUserAgent(new ClientIdentity(version: '1.2.3')));
        $this->assertSame('pig', ClientIdentity::formatUserAgent(new ClientIdentity('short')));
        $this->assertSame('mine/1', ClientIdentity::formatUserAgent(new ClientIdentity(['custom' => 'mine/1'])));
    }

    public function testIdentitiesMergeFieldByFieldAndFalseTurnsAttributionOff(): void
    {
        $merged = ClientIdentity::merge(new ClientIdentity(attribution: ClientAttribution::pig()), null, new ClientIdentity('short', version: '2.0.0'));

        $this->assertEquals(new ClientIdentity('short', ClientAttribution::pig(), '2.0.0'), $merged);
        $this->assertFalse(ClientIdentity::merge($merged, new ClientIdentity(attribution: false))?->attribution);
        $this->assertNull(ClientIdentity::merge(null, null));
    }

    public function testNoAttributionHeadersWithoutAttribution(): void
    {
        $openRouter = new Model('m', 'M', Api::OpenAiCompletions, 'custom', 'https://openrouter.ai/api/v1', 1000, 100);

        $this->assertSame([], ClientIdentity::attributionHeaders($openRouter));
        $this->assertSame([], ClientIdentity::attributionHeaders($openRouter, new ClientIdentity(attribution: false)));
        $this->assertSame(
            ['HTTP-Referer' => 'https://x.dev', 'X-OpenRouter-Title' => 'x'],
            ClientIdentity::attributionHeaders($openRouter, new ClientIdentity(attribution: new ClientAttribution('x', 'https://x.dev'))),
        );
    }

    public function testTheIdentitysHeadersGoUnderTheRequestsAndANullOneRemovesThem(): void
    {
        $server = new CannedServer();
        $url = rtrim($server->start(["HTTP/1.1 400 Bad Request\r\nContent-Length: 2\r\n\r\n{}"]), '/');
        $model = new Model('m', 'M', Api::OpenAiCompletions, 'openrouter', $url, 100_000, 1_000);
        $options = new OpenAiOptions(apiKey: 'k', headers: ['X-OpenRouter-Title' => 'mine', 'X-OpenRouter-Categories' => null], identity: new ClientIdentity(attribution: ClientAttribution::pig(), version: '9.9.9'));

        Async::run(static fn () => (new OpenAiCompletions())->stream($model, \Pig\Ai\Utils\Transcript::normalizeContext(new Context([new UserMessage('hi')])), $options)->result()->await());

        $head = strtolower($server->receivedHead());
        $this->assertStringContainsString("user-agent: pig/9.9.9 (", $head);
        $this->assertStringContainsString("http-referer: https://pigagent.dev\r\n", $head);
        $this->assertStringContainsString("x-openrouter-title: mine\r\n", $head);
        $this->assertStringNotContainsString('x-openrouter-categories', $head);
    }
}
