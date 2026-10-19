<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test\Web;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pig\CodingAgent\Web\Middlewares\SameOriginMiddleware;

final class SameOriginMiddlewareTest extends TestCase
{
    /** @return iterable<string, array{array<string, string>, bool}> */
    public static function requests(): iterable
    {
        $host = '127.0.0.1:8088';

        // The request that broke the login page: an extension had set `Origin` to the host without
        // its port, on a stylesheet Chrome itself marks same-origin.
        yield 'a rewritten Origin on the page\'s own stylesheet' => [
            ['host' => $host, 'origin' => 'http://127.0.0.1', 'sec-fetch-site' => 'same-origin'], true,
        ];
        yield 'a navigation somebody typed' => [['host' => $host, 'sec-fetch-site' => 'none'], true];
        yield 'another site, whatever Origin claims' => [
            ['host' => $host, 'origin' => "http://{$host}", 'sec-fetch-site' => 'cross-site'], false,
        ];
        yield 'a navigation from an external app' => [
            ['host' => $host, 'sec-fetch-site' => 'cross-site', 'sec-fetch-mode' => 'navigate'], true,
        ];
        // Another port on 127.0.0.1 is the same site and not the same origin.
        yield 'another port on this machine' => [
            ['host' => $host, 'origin' => 'http://127.0.0.1:3000', 'sec-fetch-site' => 'same-site'], false,
        ];
        yield 'the header in capitals' => [['host' => $host, 'sec-fetch-site' => 'Same-Origin'], true];

        // No Sec-Fetch-Site: an older browser or curl, and Origin against Host as before.
        yield 'no Origin at all' => [['host' => $host], true];
        yield 'the matching Origin' => [['host' => $host, 'origin' => "http://{$host}"], true];
        yield 'another site\'s Origin' => [['host' => $host, 'origin' => 'http://evil.example'], false];
        yield 'the port gone, with nothing to vouch for it' => [['host' => $host, 'origin' => 'http://127.0.0.1'], false];
    }

    /** @param array<string, string> $headers */
    #[DataProvider('requests')]
    public function testWhetherARequestIsThePagesOwn(array $headers, bool $allowed): void
    {
        $this->assertSame($allowed, SameOriginMiddleware::allowed($headers));
    }
}
