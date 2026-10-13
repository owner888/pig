<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test\Web;

use PHPUnit\Framework\TestCase;
use Pig\CodingAgent\Web\TcpConnection;

final class TcpConnectionTest extends TestCase
{
    public function testAPeerThatStopsReadingIsClosedInsteadOfBufferedWithoutEnd(): void
    {
        // A tab in the background: its socket is open and nothing is read from it. Before, every
        // event went on being appended to the send buffer until PHP ran out of memory.
        [$peer, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        $closed = 0;
        $conn = new TcpConnection($server, static fn () => null, static function () use (&$closed): void {
            $closed++;
        });

        $chunk = str_repeat('x', 1024 * 1024);
        $sent = 0;

        while (!$conn->isClosed() && $sent < 2 * TcpConnection::MAX_SEND_BUFFER) {
            $conn->sendRaw($chunk);
            $sent += strlen($chunk);
        }

        $this->assertTrue($conn->isClosed(), 'closed once the buffer would pass its limit');
        $this->assertSame(1, $closed, 'and the server was told, so the session lets the tab go');
        $this->assertLessThanOrEqual(TcpConnection::MAX_SEND_BUFFER + 2 * strlen($chunk), $sent);

        fclose($peer);
    }

    public function testAnythingThePeerSendsCountsAsAliveAPongIncluded(): void
    {
        // What `HttpServer::armHeartbeat()` closes a silent socket on.
        [$peer, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        $conn = new TcpConnection($server, static fn () => null, static fn () => null);
        (new \ReflectionProperty(TcpConnection::class, 'lastReadAt'))->setValue($conn, microtime(true) - 120);

        $this->assertGreaterThan(100, $conn->silentFor());

        fwrite($peer, 'GET');
        $conn->onReadable();

        $this->assertLessThan(5, $conn->silentFor(), 'a byte from the peer is a sign of life');

        $conn->close();
        fclose($peer);
    }

    public function testOneMessageLargerThanTheLimitStillGoesOut(): void
    {
        // The limit is on what piles up behind a peer, not on a message: a long conversation's
        // `get_messages` answer can be bigger, and with nothing queued it is written as before.
        [$peer, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        $conn = new TcpConnection($server, static fn () => null, static fn () => null);

        $conn->sendRaw(str_repeat('y', TcpConnection::MAX_SEND_BUFFER + 1024));

        $this->assertFalse($conn->isClosed());

        $conn->close();
        fclose($peer);
    }
}
