<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test\Web\Push;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Pig\CodingAgent\Web\Push\Base64Url;
use Pig\CodingAgent\Web\Push\P256;
use Pig\CodingAgent\Web\Push\PushStore;

/**
 * What is kept for Web Push: one VAPID key, made once and kept to its owner; the subscriptions by
 * account; and only endpoints of a push service, since the server POSTs to whatever is kept.
 */
final class PushStoreTest extends TestCase
{
    private string $dir;

    #[\Override]
    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/pig-push-' . bin2hex(random_bytes(4));
    }

    #[\Override]
    protected function tearDown(): void
    {
        shell_exec('rm -rf ' . escapeshellarg($this->dir));
    }

    /** @return array<string, mixed> */
    private static function subscription(string $endpoint): array
    {
        return ['endpoint' => $endpoint, 'keys' => ['p256dh' => Base64Url::encode(P256::generate()['public']), 'auth' => Base64Url::encode(random_bytes(16))]];
    }

    public function testTheKeyIsMadeOnceAndOnlyItsOwnerCanReadIt(): void
    {
        $file = $this->dir . '/web-push.json';
        $key = (new PushStore($file))->vapid();

        $this->assertSame($key->public, (new PushStore($file))->vapid()->public);
        $this->assertSame(0600, fileperms($file) & 0777);
    }

    public function testOnlyThePushServicesAreTaken(): void
    {
        $store = new PushStore($this->dir . '/web-push.json');

        foreach ([
            'https://fcm.googleapis.com/fcm/send/a',
            'https://updates.push.services.mozilla.com/wpush/v2/a',
            'https://web.push.apple.com/a',
            'https://wns2-par02p.notify.windows.com/w/?token=a',
        ] as $endpoint) {
            $store->add('', self::subscription($endpoint));
        }
        $this->assertCount(4, $store->of(''));

        foreach ([
            'http://fcm.googleapis.com/fcm/send/a',
            'https://127.0.0.1/a',
            'https://fcm.googleapis.com.evil.example/a',
            'https://evilfcm.googleapis.com.example/a',
            'https://user:pw@fcm.googleapis.com/a',
            'https://notpush.apple.com.example/a',
        ] as $endpoint) {
            try {
                $store->add('', self::subscription($endpoint));
                $this->fail("took {$endpoint}");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testASubscriptionIsOneBrowsersAndOnlyItsOwnerTakesItBack(): void
    {
        $store = new PushStore($this->dir . '/web-push.json');
        $endpoint = 'https://fcm.googleapis.com/fcm/send/a';

        $store->add('alice', self::subscription($endpoint), ['done' => 'Done']);
        $store->add('bob', self::subscription($endpoint));
        $this->assertSame([], $store->of('alice'), 'the browser is bob\'s now');
        $this->assertCount(1, $store->of('bob'));

        $store->remove($endpoint, 'alice');
        $this->assertCount(1, $store->of('bob'), 'alice cannot unsubscribe bob');

        $store->remove($endpoint);
        $this->assertSame([], $store->of('bob'), 'gone, said the push service');
    }

    public function testKeysOfTheWrongLengthAreRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new PushStore($this->dir . '/web-push.json'))->add('', ['endpoint' => 'https://fcm.googleapis.com/a', 'keys' => ['p256dh' => 'AAAA', 'auth' => 'AAAA']]);
    }
}
