<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test\Web\Push;

use Generator;
use PHPUnit\Framework\TestCase;
use Pig\Ai\Http\HttpTransport;
use Pig\Ai\Http\Request;
use Pig\Ai\Http\Response;
use Pig\Ai\Http\ResponseBody;
use Pig\Async\AbortSignal;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\CodingAgent\Web\Push\Base64Url;
use Pig\CodingAgent\Web\Push\P256;
use Pig\CodingAgent\Web\Push\PushStore;
use Pig\CodingAgent\Web\Push\WebPush;

/**
 * A message to each of a person's browsers, in that browser's words, readable only by it; and a
 * subscription the push service says is gone, forgotten.
 */
final class WebPushTest extends TestCase
{
    private string $dir;

    /** @var list<Request> */
    private array $sent = [];

    private int $status = 201;

    #[\Override]
    protected function setUp(): void
    {
        Loop::reset();
        $this->dir = sys_get_temp_dir() . '/pig-web-push-' . bin2hex(random_bytes(4));
    }

    #[\Override]
    protected function tearDown(): void
    {
        shell_exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function push(PushStore $store): WebPush
    {
        $transport = new class ($this) implements HttpTransport {
            public function __construct(private readonly WebPushTest $test)
            {
            }

            #[\Override]
            public function send(Request $request, ?AbortSignal $signal = null): Response
            {
                return $this->test->answer($request);
            }
        };

        return new WebPush($store, $transport, static fn (): int => 1_800_000_000);
    }

    public function answer(Request $request): Response
    {
        $this->sent[] = $request;

        return new Response($this->status, 'Status', [], new class () extends ResponseBody {
            #[\Override]
            public function getIterator(): Generator
            {
                yield from [];
            }

            #[\Override]
            public function close(): void
            {
            }
        });
    }

    public function testEachBrowserGetsTheMessageInItsOwnWords(): void
    {
        $store = new PushStore($this->dir . '/web-push.json');
        $phone = P256::generate();
        $phoneAuth = random_bytes(16);
        $laptop = P256::generate();
        $laptopAuth = random_bytes(16);
        $store->add('', ['endpoint' => 'https://web.push.apple.com/phone', 'keys' => ['p256dh' => Base64Url::encode($phone['public']), 'auth' => Base64Url::encode($phoneAuth)]], ['done' => '已完成']);
        $store->add('', ['endpoint' => 'https://fcm.googleapis.com/fcm/send/laptop', 'keys' => ['p256dh' => Base64Url::encode($laptop['public']), 'auth' => Base64Url::encode($laptopAuth)]], ['done' => 'Done']);
        $store->add('someone-else', ['endpoint' => 'https://fcm.googleapis.com/fcm/send/theirs', 'keys' => ['p256dh' => Base64Url::encode(P256::generate()['public']), 'auth' => Base64Url::encode(random_bytes(16))]]);

        Async::run(function () use ($store): void {
            $this->push($store)->toUser('', ['title' => 'Nightly', 'body' => '', 'tag' => 'pig-scheduled-t1'], static fn (array $labels): string => $labels['done'] ?? 'done');
            Async::delay(0.05);
        });

        $this->assertCount(2, $this->sent);
        $byUrl = array_column(array_map(static fn (Request $r): array => [$r->url, $r], $this->sent), 1, 0);
        $this->assertSame(
            ['title' => 'Nightly', 'body' => '已完成', 'tag' => 'pig-scheduled-t1'],
            json_decode(EncryptionTest::decrypt((string) $byUrl['https://web.push.apple.com/phone']->body, $phone, $phoneAuth), true),
        );
        $this->assertSame('Done', json_decode(EncryptionTest::decrypt((string) $byUrl['https://fcm.googleapis.com/fcm/send/laptop']->body, $laptop, $laptopAuth), true)['body']);
        $this->assertSame((string) WebPush::TTL, $byUrl['https://web.push.apple.com/phone']->headers['TTL']);
    }

    public function testASubscriptionThePushServiceSaysIsGoneIsForgotten(): void
    {
        $store = new PushStore($this->dir . '/web-push.json');
        $store->add('', ['endpoint' => 'https://web.push.apple.com/old', 'keys' => ['p256dh' => Base64Url::encode(P256::generate()['public']), 'auth' => Base64Url::encode(random_bytes(16))]]);
        $this->status = 410;

        $status = $this->push($store)->deliver($store->of('')[0], '{"title":"x"}');

        $this->assertSame(410, $status);
        $this->assertSame([], $store->of(''));
    }
}
