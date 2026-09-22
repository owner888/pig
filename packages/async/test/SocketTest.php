<?php

declare(strict_types=1);

namespace Pig\Async\Test;

use Closure;
use PHPUnit\Framework\TestCase;
use Pig\Async\AbortController;
use Pig\Async\AbortError;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\Async\Socket;
use Pig\Async\SocketError;
use Pig\Test\AssertsThrows;

final class SocketTest extends TestCase
{
    use AssertsThrows;

    /** @var list<resource> */
    private array $open = [];

    #[\Override]
    protected function setUp(): void
    {
        Loop::reset();
        $this->open = [];
    }

    public function testWritesAndReadsBack(): void
    {
        [$host, $port] = $this->echoServer();

        $answer = Async::run(static function () use ($host, $port): ?string {
            $socket = Socket::connect($host, $port);
            $socket->write("ping\n");
            $received = $socket->read();
            $socket->close();

            return $received;
        });

        $this->assertSame("ping\n", $answer);
    }

    public function testAWriteLargerThanTheKernelBufferStillArrivesWhole(): void
    {
        [$host, $port] = $this->echoServer();
        $payload = str_repeat('0123456789abcdef', 64 * 1024);   // 1 MiB — well past one buffer

        $received = Async::run(static function () use ($host, $port, $payload): int {
            $socket = Socket::connect($host, $port);
            $socket->write($payload);

            $total = 0;
            while ($total < strlen($payload)) {
                $chunk = $socket->read(65536, 5.0);

                if ($chunk === null) {
                    break;
                }

                $total += strlen($chunk);
            }

            $socket->close();

            return $total;
        });

        $this->assertSame(strlen($payload), $received);
    }

    public function testReadReturnsNullWhenThePeerHangsUp(): void
    {
        [$host, $port] = $this->server(static function ($connection): void {
            fclose($connection);
        });

        $result = Async::run(static function () use ($host, $port): ?string {
            $socket = Socket::connect($host, $port);
            $received = $socket->read(8192, 5.0);
            $socket->close();

            return $received;
        });

        $this->assertNull($result);
    }

    public function testConnectingWhereNobodyListensFails(): void
    {
        // Bind a port, learn its number, release it — nothing is listening there now.
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        [$host, $port] = $this->addressOf($probe);
        fclose($probe);

        $this->assertThrows(
            SocketError::class,
            static fn () => Async::run(static fn () => Socket::connect($host, $port, false, 2.0)),
            'Cannot connect',
        );
    }

    public function testAReadThatWaitsTooLongGivesUp(): void
    {
        [$host, $port] = $this->server(null);   // accepts, then says nothing

        $this->assertThrows(
            SocketError::class,
            static function () use ($host, $port): void {
                Async::run(static function () use ($host, $port): void {
                    $socket = Socket::connect($host, $port);
                    $socket->read(8192, 0.05);
                });
            },
            'timed out',
        );
    }

    public function testAbortingStopsAReadInFlight(): void
    {
        [$host, $port] = $this->server(null);

        $error = $this->assertThrows(AbortError::class, static function () use ($host, $port): void {
            Async::run(static function () use ($host, $port): void {
                $socket = Socket::connect($host, $port);
                $controller = new AbortController();
                Loop::get()->delay(0.02, static fn () => $controller->abort('user pressed esc'));

                $socket->read(8192, 5.0, $controller->signal);
            });
        });

        $this->assertSame('user pressed esc', $error->getMessage());
    }

    public function testClosingFreesACoroutineSuspendedOnARead(): void
    {
        [$host, $port] = $this->server(null);

        $this->assertThrows(
            SocketError::class,
            static function () use ($host, $port): void {
                Async::run(static function () use ($host, $port): void {
                    $socket = Socket::connect($host, $port);
                    Loop::get()->delay(0.02, static fn () => $socket->close());

                    $socket->read(8192, 5.0);
                });
            },
            'Socket closed',
        );
    }

    public function testUsingAClosedSocketSaysSo(): void
    {
        [$host, $port] = $this->echoServer();

        $this->assertThrows(
            SocketError::class,
            static function () use ($host, $port): void {
                Async::run(static function () use ($host, $port): void {
                    $socket = Socket::connect($host, $port);
                    $socket->close();
                    $socket->write('too late');
                });
            },
            'Socket is closed',
        );
    }

    public function testTlsHandshakeAgainstARealHost(): void
    {
        if (getenv('PIG_NETWORK_TESTS') !== '1') {
            self::markTestSkipped('set PIG_NETWORK_TESTS=1 to run tests that reach the internet');
        }

        $status = Async::run(static function (): string {
            $socket = Socket::connect('api.anthropic.com', 443, true, 10.0);
            $socket->write(
                "POST /v1/messages HTTP/1.1\r\n"
                . "Host: api.anthropic.com\r\n"
                . "Connection: close\r\n"
                . "Content-Length: 2\r\n\r\n{}",
            );

            $first = $socket->read(8192, 10.0) ?? '';
            $socket->close();

            return strtok($first, "\r\n") ?: '';
        });

        // No API key, so 401 — what matters is that TLS and HTTP/1.1 completed at all.
        $this->assertSame('HTTP/1.1 401 Unauthorized', $status);
    }

    /** @return array{0: string, 1: int} */
    private function echoServer(): array
    {
        return $this->server(function ($connection): void {
            Loop::get()->onReadable($connection, static function ($peer): void {
                $data = fread($peer, 65536);

                if ($data === false || $data === '') {
                    return;
                }

                fwrite($peer, $data);
            });
        });
    }

    /**
     * A listening socket on a free port, accepting inside the loop.
     *
     * @param Closure(resource): void|null $onConnection
     * @return array{0: string, 1: int}
     */
    private function server(?Closure $onConnection): array
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);

        if ($server === false) {
            throw new SocketError("Cannot open test server: {$errstr}");
        }

        stream_set_blocking($server, false);
        $this->open[] = $server;

        Loop::get()->onReadable($server, function ($listening) use ($onConnection): void {
            $connection = stream_socket_accept($listening, 0);

            if ($connection === false) {
                return;
            }

            stream_set_blocking($connection, false);
            $this->open[] = $connection;

            if ($onConnection !== null) {
                $onConnection($connection);
            }
        });

        return $this->addressOf($server);
    }

    /**
     * @param resource $server
     * @return array{0: string, 1: int}
     */
    private function addressOf(mixed $server): array
    {
        $name = stream_socket_get_name($server, false);
        $separator = strrpos((string) $name, ':');

        return [substr((string) $name, 0, $separator), (int) substr((string) $name, $separator + 1)];
    }
}
