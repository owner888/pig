<?php

declare(strict_types=1);

namespace Pig\Ai\Test\Http;

use PHPUnit\Framework\TestCase;
use Pig\Ai\Http\Http1Body;
use Pig\Ai\Http\Http1Pool;
use Pig\Ai\Http\Http2\Http2Body;
use Pig\Ai\Http\Http2\Http2Pool;
use Pig\Ai\Http\HttpClient;
use Pig\Ai\Http\Request;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\Async\Socket;
use Pig\Async\SocketError;
use Pig\Async\TlsRoots;
use Pig\Test\AssertsThrows;
use Pig\Test\Http2Server;
use Pig\Test\KeepAliveServer;
use Pig\Test\TlsCertificate;

/**
 * What the TLS handshake decides: HTTP/2 or HTTP/1.1, against servers on loopback whose certificate
 * the client is told to trust — the one thing a plain-socket test cannot reach.
 */
final class HttpClientTlsTest extends TestCase
{
    use AssertsThrows;

    #[\Override]
    protected function setUp(): void
    {
        if (TlsRoots::systemFile() === null) {
            self::markTestSkipped('this PHP has no file of system certificates to add the test certificate to');
        }

        Loop::reset();
        Socket::useExtraCaFile(TlsCertificate::file());
    }

    #[\Override]
    protected function tearDown(): void
    {
        Socket::useExtraCaFile(null);
        Http1Pool::clear();
        Http2Pool::clear();
    }

    public function testAServerThatTakesH2CarriesEveryRequestOnOneConnection(): void
    {
        $server = new Http2Server(static function (Http2Server $server, array $frame): void {
            if ($frame['type'] === Http2Server::HEADERS) {
                $server->respond($frame['stream'], 200, [], 'hello over h2');
            }
        });
        $port = $server->start(tls: true);

        $bodies = Async::run(static function () use ($port): array {
            $client = new HttpClient();
            $classes = [];
            $send = static function (string $path) use ($client, $port, &$classes): string {
                $response = $client->send(new Request('POST', "https://127.0.0.1:{$port}{$path}", [], '{}'));
                $classes[] = get_class($response->body);

                return $response->body->all();
            };

            $bodies = [$send('/one'), $send('/two')];
            $futures = array_map(static fn (string $path) => Async::spawn(static fn (): string => $send($path)), ['/a', '/b', '/c']);

            foreach ($futures as $future) {
                $bodies[] = $future->await();
            }

            return [$bodies, array_unique($classes)];
        });

        $this->assertSame([array_fill(0, 5, 'hello over h2'), [Http2Body::class]], $bodies);
        $this->assertSame(1, $server->connections);
        $this->assertSame([':authority', "127.0.0.1:{$port}"], $server->requests[1][0]);
        $this->assertSame([':method', 'POST'], $server->requests[1][1]);
    }

    public function testAServerWithoutH2GetsHttp11AndKeepsTheConnectionForTheNextRequest(): void
    {
        $server = new KeepAliveServer(static fn (array $request): string => KeepAliveServer::response('hello over http/1.1'));
        $url = $server->start(tls: true);

        $result = Async::run(static function () use ($url): array {
            $client = new HttpClient();
            $first = $client->send(new Request('GET', $url . 'one'));
            $firstBody = $first->body->all();
            $second = $client->send(new Request('GET', $url . 'two'));

            return [get_class($first->body), $firstBody, $second->body->all()];
        });

        $this->assertSame([Http1Body::class, 'hello over http/1.1', 'hello over http/1.1'], $result);
        $this->assertSame(1, $server->connections);
    }

    public function testACertificateNobodyTrustedIsRefused(): void
    {
        Socket::useExtraCaFile(null);
        $server = new KeepAliveServer(static fn (array $request): string => KeepAliveServer::response('never'));
        $url = $server->start(tls: true);

        $this->assertThrows(SocketError::class, static function () use ($url): void {
            Async::run(static function () use ($url): void {
                (new HttpClient())->send(new Request('GET', $url));
            });
        }, 'TLS handshake');
    }
}
