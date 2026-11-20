<?php

declare(strict_types=1);

namespace Pig\Ai\Test\Http\Http2;

use PHPUnit\Framework\TestCase;
use Pig\Ai\Http\Http2\Http2Connection;
use Pig\Ai\Http\Http2\Http2Pool;
use Pig\Ai\Http\HttpError;
use Pig\Ai\Http\Request;
use Pig\Ai\Http\Response;
use Pig\Async\AbortController;
use Pig\Async\AbortError;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\Async\Socket;
use Pig\Test\AssertsThrows;
use Pig\Test\Http2Server;

/** The connection against a scripted server: what it sends, and what it does with what comes back. */
final class Http2ConnectionTest extends TestCase
{
    use AssertsThrows;

    #[\Override]
    protected function setUp(): void
    {
        Loop::reset();
    }

    #[\Override]
    protected function tearDown(): void
    {
        Http2Pool::clear();
    }

    /** A server that answers every request with its own path as the body. */
    private static function echoingPaths(): Http2Server
    {
        return new Http2Server(static function (Http2Server $server, array $frame): void {
            if ($frame['type'] === Http2Server::HEADERS) {
                $path = '';

                foreach ($server->requests[$frame['stream']] as [$name, $value]) {
                    $path = $name === ':path' ? $value : $path;
                }

                $server->respond($frame['stream'], 200, [['content-type', 'text/plain']], $path);
            }
        });
    }

    private static function connect(int $port): Http2Connection
    {
        return Http2Connection::open(Socket::connect('127.0.0.1', $port), 5.0);
    }

    private static function get(Http2Connection $connection, string $path, ?float $idleTimeout = 5.0, ?AbortController $controller = null): Response
    {
        return $connection->request(new Request('GET', "https://example.test{$path}"), 'example.test', $path, $idleTimeout, $controller?->signal);
    }

    public function testSendsPseudoHeadersAndReadsTheResponse(): void
    {
        $server = self::echoingPaths();
        $port = $server->start();

        $result = Async::run(static function () use ($port): array {
            $connection = self::connect($port);
            $response = $connection->request(
                // The caller's own content-length is replaced, not repeated, and a name given twice in two cases is one header.
                new Request('POST', 'https://example.test/p?q=1', ['Accept' => 'text/plain', 'Connection' => 'close', 'Host' => 'ignored', 'TE' => 'gzip', 'Content-Length' => '7', 'ACCEPT' => 'application/json', 'content-length' => '9'], '{"a":1}'),
                'example.test',
                '/p?q=1',
                5.0,
                null,
            );
            $body = $response->body->all();
            $connection->close();

            return [$response->status, $response->reason, $response->header('content-type'), $body];
        });

        $this->assertSame([200, '', 'text/plain', '/p?q=1'], $result);
        $this->assertSame([
            // Node's order, read off the wire from Node 26.
            [':authority', 'example.test'],
            [':method', 'POST'],
            [':path', '/p?q=1'],
            [':scheme', 'https'],
            ['accept', 'application/json'],
            ['content-length', '7'],
            ['accept-encoding', 'identity'],
        ], $server->requests[1]);
        $this->assertSame('{"a":1}', $server->bodies[1]);
        // The preface, as Node's `fetch` sends it (Node 26): SETTINGS with no push and a 256 KiB window per
        // stream, then the connection's window made 512 KiB.
        $this->assertSame(Http2Server::SETTINGS, $server->frames[0]['type']);
        $this->assertSame(pack('nNnN', 2, 0, 4, 262144), $server->frames[0]['payload']);
        $this->assertSame(Http2Server::WINDOW_UPDATE, $server->frames[1]['type']);
        $this->assertSame(pack('N', 458753), $server->frames[1]['payload']);
    }

    public function testRequestsShareOneConnectionAndEachGetsItsOwnResponse(): void
    {
        $pending = [];
        $server = new Http2Server(static function (Http2Server $server, array $frame) use (&$pending): void {
            if ($frame['type'] !== Http2Server::HEADERS) {
                return;
            }

            $pending[] = $frame['stream'];

            // Only when all three are in, and in the opposite order to the one they came in.
            if (count($pending) === 3) {
                foreach (array_reverse($pending) as $id) {
                    foreach ($server->requests[$id] as [$name, $value]) {
                        if ($name === ':path') {
                            $server->respond($id, 200, [], $value);
                        }
                    }
                }
            }
        });
        $port = $server->start();

        $bodies = Async::run(static function () use ($port): array {
            $connection = self::connect($port);
            $futures = array_map(
                static fn (string $path) => Async::spawn(static fn (): string => self::get($connection, $path)->body->all()),
                ['/one', '/two', '/three'],
            );

            return array_map(static fn ($future) => $future->await(), $futures);
        });

        $this->assertSame(['/one', '/two', '/three'], $bodies);
        $this->assertSame(1, $server->connections);
        $this->assertSame([1, 3, 5], array_keys($server->requests));
    }

    public function testBodyArrivesInPiecesAsTheyAreSent(): void
    {
        $server = new Http2Server(static function (Http2Server $server, array $frame): void {
            if ($frame['type'] !== Http2Server::HEADERS) {
                return;
            }

            $id = $frame['stream'];
            $server->respond($id, 200, [], null, false);

            foreach (['one', 'two', 'three'] as $i => $piece) {
                Loop::get()->delay(0.02 * ($i + 1), static fn () => $server->data($id, $piece, $piece === 'three'));
            }
        });
        $port = $server->start();

        $pieces = Async::run(static function () use ($port): array {
            $pieces = [];

            foreach (self::get(self::connect($port), '/')->body as $piece) {
                $pieces[] = $piece;
            }

            return $pieces;
        });

        $this->assertSame(['one', 'two', 'three'], $pieces);
    }

    public function testABodyLargerThanTheSendWindowWaitsForTheServerToOpenIt(): void
    {
        $received = 0;
        $granted = 0;
        $overspent = false;
        $server = new Http2Server(static function (Http2Server $server, array $frame) use (&$received, &$granted, &$overspent): void {
            if ($frame['type'] !== Http2Server::DATA) {
                return;
            }

            $received += strlen($frame['payload']);
            // Nothing may arrive beyond the protocol's 65535 and what the server has given since.
            $overspent = $overspent || $received > 65535 + $granted;

            if (($frame['flags'] & 0x1) !== 0) {
                $server->respond($frame['stream'], 200, [], (string) $received);

                return;
            }

            $granted += strlen($frame['payload']);
            $server->openWindow($frame['stream'], strlen($frame['payload']));
        });
        $port = $server->start();
        $body = str_repeat('0123456789abcdef', 12500);

        $answer = Async::run(static function () use ($port, $body): string {
            $connection = self::connect($port);

            return $connection->request(new Request('POST', 'https://example.test/up', [], $body), 'example.test', '/up', 5.0, null)->body->all();
        });

        $this->assertSame('200000', $answer);
        $this->assertSame($body, $server->bodies[1]);
        $this->assertFalse($overspent, 'the client sent past the window it had');
    }

    /** @return list<int> the increments of the WINDOW_UPDATEs the client sent, for a stream (0 is the connection) */
    private static function windowUpdates(Http2Server $server, int $stream): array
    {
        return array_values(array_map(
            static fn (array $frame): int => (int) unpack('N', $frame['payload'])[1],
            array_filter($server->framesOfType(Http2Server::WINDOW_UPDATE), static fn (array $frame): bool => $frame['stream'] === $stream),
        ));
    }

    public function testAnAnswerThatFitsTheOpeningWindowAsksForNothingMore(): void
    {
        $server = new Http2Server(static function (Http2Server $server, array $frame): void {
            if ($frame['type'] === Http2Server::HEADERS) {
                $server->respond($frame['stream'], 200, [], str_repeat('x', 16000));
            }
        });
        $port = $server->start();

        Async::run(static function () use ($port): void {
            self::get(self::connect($port), '/')->body->all();
        });

        // Only the connection's own window, once, when it opened: what Node's `fetch` sends.
        $this->assertSame([458753], self::windowUpdates($server, 0));
        $this->assertSame([], self::windowUpdates($server, 1));
    }

    public function testADownloadIsGivenAWiderWindowOnceItHasUsedTheFirst(): void
    {
        $server = new Http2Server(static function (Http2Server $server, array $frame): void {
            if ($frame['type'] !== Http2Server::HEADERS) {
                return;
            }

            $server->respond($frame['stream'], 200, [], null, false);

            for ($sent = 0; $sent < 600000; $sent += 16384) {
                $server->data($frame['stream'], str_repeat('x', 16384), $sent + 16384 >= 600000);
            }
        });
        $port = $server->start();

        $length = Async::run(static function () use ($port): int {
            $length = 0;

            foreach (self::get(self::connect($port), '/big')->body as $piece) {
                $length += strlen($piece);
            }

            return $length;
        });

        $this->assertGreaterThanOrEqual(600000, $length);
        // Once for the stream and once for the connection, raising each from the opening size to 8 MiB.
        $this->assertContains(8388608 - 262144, self::windowUpdates($server, 1));
        $this->assertContains(8388608 - 524288, self::windowUpdates($server, 0));
    }

    public function testAResetStreamFailsAfterWhatCameBeforeIt(): void
    {
        $server = new Http2Server(static function (Http2Server $server, array $frame): void {
            if ($frame['type'] !== Http2Server::HEADERS) {
                return;
            }

            $id = $frame['stream'];

            if ($id === 1) {
                $server->respond($id, 200, [], 'part', false);
                $server->send(Http2Server::frame(Http2Server::RST_STREAM, 0, $id, pack('N', 2)));

                return;
            }

            $server->respond($id, 200, [], 'fine');
        });
        $port = $server->start();

        $seen = [];
        $error = $this->assertThrows(HttpError::class, static function () use ($port, &$seen): void {
            Async::run(static function () use ($port, &$seen): void {
                $connection = self::connect($port);

                try {
                    foreach (self::get($connection, '/reset')->body as $piece) {
                        $seen[] = $piece;
                    }
                } finally {
                    // The connection is still good for the next request.
                    $seen[] = self::get($connection, '/ok')->body->all();
                }
            });
        });

        $this->assertSame(['part', 'fine'], $seen);
        $this->assertStringContainsString('Connection closed: the server reset the stream (INTERNAL_ERROR)', $error->getMessage());
    }

    public function testGoAwayFailsWhatWasNotStartedAndStopsNewRequests(): void
    {
        $server = new Http2Server(static function (Http2Server $server, array $frame): void {
            if ($frame['type'] === Http2Server::HEADERS && $frame['stream'] === 3) {
                $server->respond(1, 200, [], 'first');
                $server->send(Http2Server::frame(Http2Server::GOAWAY, 0, 0, pack('NN', 1, 0)));
            }
        });
        $port = $server->start();

        [$first, $second, $usable] = Async::run(static function () use ($port): array {
            $connection = self::connect($port);
            $one = Async::spawn(static fn (): string => self::get($connection, '/a')->body->all());
            $two = Async::spawn(static function () use ($connection): string {
                try {
                    return self::get($connection, '/b')->body->all();
                } catch (HttpError $error) {
                    return $error->getMessage();
                }
            });

            return [$one->await(), $two->await(), $connection->isUsable()];
        });

        $this->assertSame('first', $first);
        $this->assertStringContainsString('did not process the request', $second);
        $this->assertFalse($usable);
    }

    public function testTheServerHangingUpFailsWhatIsWaitingAndEndsTheConnection(): void
    {
        $server = new Http2Server(static function (Http2Server $server, array $frame): void {
            if ($frame['type'] === Http2Server::HEADERS) {
                $server->hangUp();
            }
        });
        $port = $server->start();

        [$first, $second, $usable] = Async::run(static function () use ($port): array {
            $connection = self::connect($port);
            $messages = [];

            foreach (['/a', '/b'] as $path) {
                try {
                    self::get($connection, $path);
                    $messages[] = 'answered';
                } catch (\Throwable $error) {
                    // Whether the reset reaches the client as a closed stream or a failed read is the
                    // kernel's to say; both are what pig's turn retries on.
                    $messages[] = $error->getMessage();
                }
            }

            return [...$messages, $connection->isUsable()];
        });

        $this->assertMatchesRegularExpression('/Connection closed|Read failed/', $first);
        $this->assertStringContainsString('Connection closed', $second);
        $this->assertFalse($usable);
    }

    public function testAbandoningABodyCancelsItsStreamAndKeepsTheConnection(): void
    {
        $server = new Http2Server(static function (Http2Server $server, array $frame): void {
            if ($frame['type'] !== Http2Server::HEADERS) {
                return;
            }

            if ($frame['stream'] === 1) {
                $server->respond(1, 200, [], 'first', false);

                return;
            }

            $server->respond($frame['stream'], 200, [], 'second');
        });
        $port = $server->start();

        $answer = null;
        $this->assertThrows(AbortError::class, static function () use ($port, &$answer): void {
            Async::run(static function () use ($port, &$answer): void {
                $connection = self::connect($port);
                $controller = new AbortController();

                try {
                    foreach (self::get($connection, '/stream', 5.0, $controller)->body as $piece) {
                        $controller->abort('user pressed esc');
                    }
                } finally {
                    $answer = self::get($connection, '/next')->body->all();
                }
            });
        });

        $this->assertSame('second', $answer);
        $reset = $server->framesOfType(Http2Server::RST_STREAM);
        $this->assertCount(1, $reset);
        $this->assertSame(1, $reset[0]['stream']);
        $this->assertSame(pack('N', 8), $reset[0]['payload']);
    }

    public function testAStreamThatTimesOutDoesNotTakeTheConnectionWithIt(): void
    {
        $server = new Http2Server(static function (Http2Server $server, array $frame): void {
            if ($frame['type'] === Http2Server::HEADERS && $frame['stream'] !== 1) {
                $server->respond($frame['stream'], 200, [], 'fine');
            }
        });
        $port = $server->start();

        $answer = null;
        $error = $this->assertThrows(\Throwable::class, static function () use ($port, &$answer): void {
            Async::run(static function () use ($port, &$answer): void {
                $connection = self::connect($port);

                try {
                    self::get($connection, '/hang', 0.2);
                } finally {
                    $answer = self::get($connection, '/ok')->body->all();
                }
            });
        });

        $this->assertStringContainsString('timed out', $error->getMessage());
        $this->assertSame('fine', $answer);
        $this->assertSame(1, $server->framesOfType(Http2Server::RST_STREAM)[0]['stream']);
    }

    public function testAConnectionOnWhichNothingArrivedForAWholeTimeoutIsNotHandedOutAgain(): void
    {
        // A connection that went dark (a laptop that slept, a NAT that forgot it) takes writes and
        // answers nothing. The stream's timeout is retried by the turn, and the retry must not go
        // back onto the same connection for another whole timeout.
        $server = new Http2Server(static function (Http2Server $server, array $frame): void {
            if ($frame['type'] === Http2Server::HEADERS && $frame['stream'] === 1) {
                $server->respond(1, 200, [], 'first');
            }
        });
        $port = $server->start();

        Async::run(function () use ($port): void {
            $connection = self::connect($port);
            self::get($connection, '/a')->body->all();
            Async::delay(0.05);
            Http2Pool::add('example.test:443', $connection);

            $error = $this->assertThrows(\Throwable::class, static fn () => self::get($connection, '/hang', 0.2));

            $this->assertStringContainsString('timed out', $error->getMessage());
            $this->assertFalse($connection->isUsable());
            $this->assertNull(Http2Pool::acquire('example.test:443'));
        });
    }

    public function testARefusedStreamIsOneTheServerNeverProcessed(): void
    {
        $server = new Http2Server(static function (Http2Server $server, array $frame): void {
            if ($frame['type'] === Http2Server::HEADERS) {
                $server->send(Http2Server::frame(Http2Server::RST_STREAM, 0, $frame['stream'], pack('N', 7)));
            }
        });
        $port = $server->start();

        $error = $this->assertThrows(HttpError::class, static function () use ($port): void {
            Async::run(static function () use ($port): void {
                self::get(self::connect($port), '/');
            });
        });

        $this->assertStringContainsString('REFUSED_STREAM', $error->getMessage());
        $this->assertTrue($error->unprocessed);
    }

    public function testAResponseThatHasNoBodyIsNotHeldToItsContentLength(): void
    {
        // HEAD, 204 and 304 carry the length the body would have had, and no body (RFC 9110 §8.6).
        $server = new Http2Server(static function (Http2Server $server, array $frame): void {
            if ($frame['type'] !== Http2Server::HEADERS) {
                return;
            }

            $path = '';

            foreach ($server->requests[$frame['stream']] as [$name, $value]) {
                $path = $name === ':path' ? $value : $path;
            }

            $server->respond($frame['stream'], $path === '/gone' ? 304 : 200, [['content-length', '10']]);
        });
        $port = $server->start();

        $bodies = Async::run(static function () use ($port): array {
            $connection = self::connect($port);

            return [
                $connection->request(new Request('HEAD', 'https://example.test/head'), 'example.test', '/head', 5.0, null)->body->all(),
                self::get($connection, '/gone')->body->all(),
            ];
        });

        $this->assertSame(['', ''], $bodies);
    }

    public function testAStreamTheServerHasEndedNoLongerCountsAgainstItsLimitOnStreams(): void
    {
        // SETTINGS_MAX_CONCURRENT_STREAMS of 1. A stream the server has sent END_STREAM on is closed
        // (RFC 9113 §5.1) whether or not its body has been read yet, so it leaves room for the next.
        $server = new Http2Server(static function (Http2Server $server, array $frame): void {
            if ($frame['type'] === Http2Server::HEADERS) {
                $server->respond($frame['stream'], 200, [], $frame['stream'] === 1 ? 'a' : 'b');
            }
        }, pack('nN', 3, 1));
        $port = $server->start();

        $bodies = Async::run(static function () use ($port): array {
            $connection = self::connect($port);
            $first = self::get($connection, '/a');
            $second = Async::spawn(static fn (): string => self::get($connection, '/b', 1.0)->body->all());
            Async::delay(0.1);

            return [$first->body->all(), $second->await()];
        });

        $this->assertSame(['a', 'b'], $bodies);
    }

    public function testAStreamLetGoOfLeavesRoomForTheRequestWaitingForIt(): void
    {
        // SETTINGS_MAX_CONCURRENT_STREAMS of 1, and the first stream still open when its body is
        // abandoned: the request waiting for room goes out once it is let go, not at its timeout.
        $server = new Http2Server(static function (Http2Server $server, array $frame): void {
            // As every server must (RFC 9113 §6.7).
            if ($frame['type'] === Http2Server::PING && ($frame['flags'] & 0x1) === 0) {
                $server->send(Http2Server::frame(Http2Server::PING, 0x1, 0, $frame['payload']));
            }

            if ($frame['type'] !== Http2Server::HEADERS) {
                return;
            }

            $frame['stream'] === 1
                ? $server->respond(1, 200, [], 'part', false)
                : $server->respond($frame['stream'], 200, [], 'b');
        }, pack('nN', 3, 1));
        $port = $server->start();

        $second = Async::run(static function () use ($port): string {
            $connection = self::connect($port);
            $first = self::get($connection, '/a');
            $waiting = Async::spawn(static fn (): string => self::get($connection, '/b', 1.0)->body->all());
            Async::delay(0.1);
            $first->body->close();

            return $waiting->await();
        });

        $this->assertSame('b', $second);
    }

    public function testAnInterimResponseIsSkipped(): void
    {
        $server = new Http2Server(static function (Http2Server $server, array $frame): void {
            if ($frame['type'] === Http2Server::HEADERS) {
                $server->respond($frame['stream'], 100, [], null, false);
                $server->respond($frame['stream'], 201, [['x-after', 'yes']], 'made');
            }
        });
        $port = $server->start();

        $result = Async::run(static function () use ($port): array {
            $response = self::get(self::connect($port), '/');

            return [$response->status, $response->header('x-after'), $response->body->all()];
        });

        $this->assertSame([201, 'yes', 'made'], $result);
    }

    public function testTheHeaderTableIsSharedByEveryStreamOfTheConnection(): void
    {
        $server = new Http2Server(static function (Http2Server $server, array $frame): void {
            if ($frame['type'] !== Http2Server::HEADERS) {
                return;
            }

            // 200; then x-token: abc, added to the table the first time and pointed at the second (index 62).
            $block = $frame['stream'] === 1
                ? "\x88\x40\x07x-token\x03abc"
                : "\x88\xbe";
            $server->send(Http2Server::frame(Http2Server::HEADERS, 0x5, $frame['stream'], $block));
        });
        $port = $server->start();

        $tokens = Async::run(static function () use ($port): array {
            $connection = self::connect($port);

            return [self::get($connection, '/a')->header('x-token'), self::get($connection, '/b')->header('x-token')];
        });

        $this->assertSame(['abc', 'abc'], $tokens);
    }

    public function testWhatArrivedWhileIdleIsReadBeforeTheConnectionIsUsedAgain(): void
    {
        $server = self::echoingPaths();
        $port = $server->start();

        $usableAfterPing = null;
        Async::run(function () use ($port, $server, &$usableAfterPing): void {
            $connection = self::connect($port);
            self::get($connection, '/a')->body->all();

            $server->send(Http2Server::frame(Http2Server::PING, 0, 0, 'pingpong'));
            Async::delay(0.05);
            $usableAfterPing = $connection->isUsable();
            Async::delay(0.05);
            $this->assertCount(1, $server->framesOfType(Http2Server::PING));

            $server->send(Http2Server::frame(Http2Server::GOAWAY, 0, 0, pack('NN', 1, 0)));
            Async::delay(0.05);
            $this->assertFalse($connection->isUsable());
        });

        $this->assertTrue($usableAfterPing);
        $ping = $server->framesOfType(Http2Server::PING)[0];
        $this->assertSame(0x1, $ping['flags']);
        $this->assertSame('pingpong', $ping['payload']);
    }

    public function testAGracefulGoAwayFollowedByAPingLeavesAnIdleConnectionUnusableWithoutFailing(): void
    {
        $server = self::echoingPaths();
        $port = $server->start();

        Async::run(function () use ($port, $server): void {
            $connection = self::connect($port);
            self::get($connection, '/a')->body->all();
            Http2Pool::add('example.test:443', $connection);

            // RFC 9113 §6.8's graceful shutdown, in one write: GOAWAY with the highest stream id, then a PING.
            // The GOAWAY closes the idle connection, and the PING after it has nobody to be answered to.
            $server->send(
                Http2Server::frame(Http2Server::GOAWAY, 0, 0, pack('NN', 0x7fffffff, 0))
                . Http2Server::frame(Http2Server::PING, 0, 0, 'pingpong'),
            );
            Async::delay(0.05);

            $this->assertNull(Http2Pool::acquire('example.test:443'));
            $this->assertFalse($connection->isUsable());
        });

        $this->assertSame([], $server->framesOfType(Http2Server::PING));
    }

    public function testThePoolHandsOutConnectionsThatCanStillBeUsed(): void
    {
        $server = self::echoingPaths();
        $port = $server->start();

        Async::run(function () use ($port, $server): void {
            $connection = self::connect($port);
            self::get($connection, '/a')->body->all();
            Http2Pool::add('example.test:443', $connection);

            $this->assertSame($connection, Http2Pool::acquire('example.test:443'));
            $this->assertNull(Http2Pool::acquire('other.test:443'));

            $server->send(Http2Server::frame(Http2Server::GOAWAY, 0, 0, pack('NN', 0, 0)));
            Async::delay(0.05);

            $this->assertNull(Http2Pool::acquire('example.test:443'));
        });
    }

    public function testAServerThatBreaksTheProtocolEndsTheConnection(): void
    {
        // A DATA frame on stream 0.
        $server = new Http2Server(static function (Http2Server $server, array $frame): void {
            if ($frame['type'] === Http2Server::HEADERS) {
                $server->send(Http2Server::frame(Http2Server::DATA, 0, 0, 'x'));
            }
        });
        $port = $server->start();

        $error = $this->assertThrows(HttpError::class, static function () use ($port): void {
            Async::run(static function () use ($port): void {
                self::get(self::connect($port), '/');
            });
        });

        $this->assertStringContainsString('HTTP/2 protocol error', $error->getMessage());
    }
}
