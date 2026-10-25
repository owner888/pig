<?php

declare(strict_types=1);

namespace Pig\Async\Test;

use PHPUnit\Framework\TestCase;
use Pig\Async\Async;
use Pig\Async\Dns;
use Pig\Async\Loop;

final class DnsTest extends TestCase
{
    #[\Override]
    protected function setUp(): void
    {
        Loop::reset();
        Dns::forget();
    }

    #[\Override]
    protected function tearDown(): void
    {
        Dns::forget();
    }

    public function testAnAddressLocalhostOrACallOutsideACoroutineIsLeftForTheConnect(): void
    {
        $this->assertSame('example.com', Dns::resolve('example.com'), 'no coroutine: resolved by the connect as before');

        Async::run(function (): void {
            $this->assertSame('127.0.0.1', Dns::resolve('127.0.0.1'));
            $this->assertSame('::1', Dns::resolve('::1'));
            $this->assertSame('localhost', Dns::resolve('localhost'));
        });
    }

    /** The point of it: the loop keeps ticking while the lookup runs. */
    public function testTheLoopKeepsRunningWhileANameIsLookedUp(): void
    {
        $ticks = 0;
        $answer = Async::run(static function () use (&$ticks): string {
            $timer = Async::spawn(static function () use (&$ticks): void {
                for ($i = 0; $i < 200; $i++) {
                    Async::delay(0.001);
                    $ticks++;
                }
            });

            $answer = Dns::resolve('pig-dns-test.invalid', 10.0);
            $timer->await();

            return $answer;
        });

        // `.invalid` never resolves: handed back for the connect to report as it always did.
        $this->assertSame('pig-dns-test.invalid', $answer);
        $this->assertGreaterThan(0, $ticks);
    }

    public function testALookedUpAddressIsKeptAndSharedByCallersAtOnce(): void
    {
        $host = gethostname();
        $expected = is_string($host) ? gethostbynamel($host) : false;

        if ($expected === false || $expected === []) {
            $this->markTestSkipped('this machine does not resolve its own name');
        }

        [$first, $second, $third] = Async::run(static function () use ($host): array {
            $a = Async::spawn(static fn (): string => Dns::resolve($host));
            $b = Async::spawn(static fn (): string => Dns::resolve($host));

            return [$a->await(), $b->await(), Dns::resolve($host)];
        });

        $this->assertSame($expected[0], $first);
        $this->assertSame($first, $second);
        $this->assertSame($first, $third);
    }
}
