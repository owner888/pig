<?php

declare(strict_types=1);

namespace Pig\Ai\Test\Http;

use Closure;
use PHPUnit\Framework\TestCase;
use Pig\Ai\Http\HttpError;
use Pig\Ai\Http\WebSocket;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\Test\WebSocketServer;

/**
 * The WebSocket client: the handshake and its refusal, a message in pieces put back together, a
 * ping answered without the caller seeing it, and a close frame's code and reason — or 1006 for a
 * connection that just ended.
 */
final class WebSocketTest extends TestCase
{
    private WebSocketServer $server;

    #[\Override]
    protected function setUp(): void
    {
        Loop::reset();
        $this->server = new WebSocketServer();
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->server->stop();
    }

    public function testAFragmentedMessageArrivesWholeAndAPingIsAnsweredOnTheWay(): void
    {
        $base = $this->server->start(static function (mixed $message, Closure $send, Closure $close, int $id, Closure $raw): void {
            if ($message === 'pong?') {
                return;
            }

            $raw(WebSocketServer::frame(0x1, '{"a":', false) . WebSocketServer::frame(0x9, 'hb') . WebSocketServer::frame(0x0, '1}'));
            $close(1009, '');
        });

        [$first, $second, $code, $reason] = $this->exchange($base, '"hello"');

        self::assertSame('{"a":1}', $first);
        self::assertNull($second);
        self::assertSame(1009, $code);
        self::assertSame('', $reason);
        self::assertSame(['hello'], $this->server->bodies());
        self::assertSame('pig', $this->server->upgrades[0]['x-test']);
    }

    public function testARefusedUpgradeIsTheHandshakeFailing(): void
    {
        $this->server->upgrade = 'refuse';
        $base = $this->server->start(static function (): void {
        });

        $this->expectException(HttpError::class);
        $this->expectExceptionMessage(WebSocket::HANDSHAKE_FAILED);
        $this->exchange($base, '"x"');
    }

    public function testAConnectionThatEndsWithoutACloseFrameIs1006(): void
    {
        $server = $this->server;
        $base = $server->start(static function () use ($server): void {
            $server->stop();
        });

        [$first, , $code] = $this->exchange($base, '"x"');

        self::assertNull($first);
        self::assertSame(1006, $code);
    }

    /** @return array{0: string|null, 1: string|null, 2: int|null, 3: string|null} */
    private function exchange(string $base, string $text): array
    {
        return Async::run(static function () use ($base, $text): array {
            $socket = WebSocket::connect(str_replace('http://', 'ws://', $base) . '/path?q=1', ['X-Test' => 'pig'], timeout: 5.0);
            $socket->send($text);
            $first = $socket->receive(timeout: 5.0);
            $second = $first === null ? null : $socket->receive(timeout: 5.0);

            return [$first, $second, $socket->closeCode(), $socket->closeReason()];
        });
    }
}
