<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test\Web;

use PHPUnit\Framework\TestCase;
use Pig\CodingAgent\Web\TcpConnection;
use Pig\Test\CapturesLog;

final class TcpConnectionTest extends TestCase
{
    use CapturesLog;

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
        $conn->sendRaw($chunk);
        // Nothing has gone for longer than a reading peer ever takes.
        self::drainedAgo($conn, TcpConnection::STALLED_AFTER + 1);
        $sent = strlen($chunk);

        $this->capturingLog(static function () use ($conn, $chunk, &$sent): void {
            while (!$conn->isClosed() && $sent < 2 * TcpConnection::MAX_SEND_BUFFER) {
                $conn->sendRaw($chunk);
                $sent += strlen($chunk);
            }
        });

        $this->assertTrue($conn->isClosed(), 'closed once the buffer would pass its limit');
        $this->assertSame(1, $closed, 'and the server was told, so the session lets the tab go');
        $this->assertSame(['[web] closing a connection: the peer has read nothing for ' . TcpConnection::STALLED_AFTER . ' s'], $this->loggedWarnings(), 'and the log says why');
        $this->assertLessThanOrEqual(TcpConnection::MAX_SEND_BUFFER + 2 * strlen($chunk), $sent);

        fclose($peer);
    }

    public function testAPeerStillReadingIsNotClosedPastTheSoftLimit(): void
    {
        // A long conversation's `get_messages` for every tab of a page, over a slow link: the peer
        // is reading, just behind. Closed at 32 MB, the page reconnected, asked again and was
        // closed again, without end.
        [$peer, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        $conn = new TcpConnection($server, static fn () => null, static fn () => null);

        $chunk = str_repeat('x', 1024 * 1024);
        $conn->sendRaw($chunk);

        for ($sent = strlen($chunk); $sent < TcpConnection::MAX_SEND_BUFFER + 4 * strlen($chunk); $sent += strlen($chunk)) {
            $conn->sendRaw($chunk);
        }

        $this->assertFalse($conn->isClosed(), 'something left the buffer a moment ago');

        // And memory has its own line, reading or not.
        $this->capturingLog(static function () use ($conn, $chunk, &$sent): void {
            while (!$conn->isClosed() && $sent < 2 * TcpConnection::HARD_SEND_BUFFER) {
                $conn->sendRaw($chunk);
                $sent += strlen($chunk);
            }
        });

        $this->assertTrue($conn->isClosed());
        $this->assertLessThanOrEqual(TcpConnection::HARD_SEND_BUFFER + 2 * strlen($chunk), $sent);
        $this->assertCount(1, $this->loggedWarnings());
        $this->assertStringStartsWith('[web] closing a connection: send buffer past', $this->loggedWarnings()[0]);

        fclose($peer);
    }

    public function testAPeerThatCatchesUpIsToldOnce(): void
    {
        // What `HttpServer` holds a running command's updates back on, and sends them after.
        [$peer, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        stream_set_blocking($peer, false);
        $conn = new TcpConnection($server, static fn () => null, static fn () => null);
        $drained = 0;
        $conn->onDrain(static function () use (&$drained): void {
            $drained++;
        });

        $conn->sendRaw(str_repeat('z', 4 * 1024 * 1024));
        $this->assertTrue($conn->hasBacklog());
        $this->assertSame(0, $drained);

        // The peer reads; each time the socket has room, the rest goes.
        $flush = new \ReflectionMethod(TcpConnection::class, 'flush');
        for ($i = 0; $i < 10000 && $conn->hasBacklog(); $i++) {
            fread($peer, 1024 * 1024);
            $flush->invoke($conn);
        }

        $this->assertFalse($conn->hasBacklog());
        $this->assertSame(1, $drained);

        $conn->close();
        fclose($peer);
    }

    private static function drainedAgo(TcpConnection $conn, float $seconds): void
    {
        (new \ReflectionProperty(TcpConnection::class, 'lastDrainAt'))->setValue($conn, microtime(true) - $seconds);
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

    public function testDrainingSendBufferCountsAsActiveInSilentFor(): void
    {
        [$peer, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        $conn = new TcpConnection($server, static fn () => null, static fn () => null);
        (new \ReflectionProperty(TcpConnection::class, 'lastReadAt'))->setValue($conn, microtime(true) - 90);
        (new \ReflectionProperty(TcpConnection::class, 'sendBuffer'))->setValue($conn, 'still-draining');
        (new \ReflectionProperty(TcpConnection::class, 'lastDrainAt'))->setValue($conn, microtime(true) - 2);

        $this->assertLessThan(5, $conn->silentFor(), 'draining bytes prove the TCP peer is acknowledging segments');

        (new \ReflectionProperty(TcpConnection::class, 'sendBuffer'))->setValue($conn, '');
        $this->assertGreaterThan(80, $conn->silentFor(), 'once drained, silence is measured from lastReadAt');

        $conn->close();
        fclose($peer);
    }
}
