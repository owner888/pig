<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Web;

use Closure;
use Pig\Async\Loop;
use Pig\CodingAgent\Logger;
use Pig\CodingAgent\Web\Protocols\Http;
use Pig\CodingAgent\Web\Protocols\ProtocolInterface;
use Throwable;

/**
 * Workerman-inspired non-blocking TCP connection with dynamic protocol framing,
 * send-buffering, and backpressure handling.
 */
class TcpConnection
{
    protected string $readBuffer = '';
    /**
     * How much this connection holds for a peer that has stopped reading — Workerman's
     * `maxSendBufferSize`, with the other answer to it: past this the connection is closed rather
     * than data dropped from the middle of a stream.
     *
     * A browser tab that stops reading (an iPad tab in the background, a laptop asleep) leaves its
     * socket open; every event of every turn on that page went on being appended here, and one
     * conversation's events and `get_messages` answers grew the buffer past PHP's memory limit,
     * which took the whole web server down with every other tab on it. Closed, the page
     * reconnects when it reads again and loads the conversation afresh (`network.js`).
     *
     * Only a peer that has stopped: one that is reading but slowly — a long conversation's
     * `get_messages` for every tab of a page, over a VPN — was closed at 32 MB too, reconnected,
     * asked for the same again and was closed again, for as long as the page stayed open. So past
     * this, the connection is closed only when nothing of the buffer has gone for
     * `STALLED_AFTER` seconds; `HARD_SEND_BUFFER` is the line memory draws whatever the peer does.
     *
     * Both are brakes, not working room. What a page is sent at a steady rate is small (a running
     * command's output is updated ten times a second, and a page that falls behind gets only the
     * newest of those — `hasBacklog()`, `onDrain()`, `HttpServer::onSessionEvent()`); what is big
     * is what it asks for — a conversation's history — and a page asks for one tab's at a time.
     * One message bigger than either limit still goes out whole when nothing is queued before it.
     */
    public const int MAX_SEND_BUFFER = 8 * 1024 * 1024;

    public const float STALLED_AFTER = 15.0;

    public const int HARD_SEND_BUFFER = 32 * 1024 * 1024;

    /** Called each time everything queued has been written (`onDrain()`). */
    private ?Closure $drainListener = null;

    /** When the send buffer last shrank, or was started. */
    protected float $lastDrainAt = 0.0;

    /** The most one `fwrite()` is given, so a big frame goes out a piece at a time. */
    public const int WRITE_CHUNK = 256 * 1024;

    /**
     * How long to wait before writing again after the system said it has no buffer for it.
     *
     * macOS answers `ENOBUFS` (errno 55) to a write a tunnel interface cannot queue yet — a
     * Tailscale `utun` above all — which PHP does not count as "try again" the way it counts
     * `EAGAIN`, so `fwrite()` answered false and the page's socket was closed. With a long
     * conversation every page load wrote megabytes, so the page reconnected every few seconds,
     * for good. It is a full queue, not a broken peer: wait a moment and go on.
     */
    public const float NO_BUFFER_RETRY = 0.02;

    private ?string $retryTimer = null;

    /** How much of `$sendBuffer` has been written already. */
    private int $sendOffset = 0;

    protected string $sendBuffer = '';

    /** When the peer last sent anything — a pong counts (`HttpServer::armHeartbeat()`). */
    protected float $lastReadAt;
    protected ?string $readableWatcher = null;
    protected ?string $writableWatcher = null;
    protected bool $isClosed = false;
    protected bool $closeWhenDrained = false;

    /**
     * A WebSocket message whose frames have not all arrived, or null between messages —
     * Workerman's `websocketDataBuffer`. `Websocket::decode()` keeps it.
     */
    public ?string $websocketDataBuffer = null;

    /** @var class-string<ProtocolInterface> */
    protected string $protocol = Http::class;

    /**
     * @param resource $socket client socket stream
     * @param Closure(self, mixed): void $onMessage
     * @param Closure(self): void $onClose
     * @param class-string<ProtocolInterface> $protocol
     * @param Closure(self, Throwable): void|null $onError fault-isolation callback
     */
    public function __construct(
        public readonly mixed $socket,
        protected readonly Closure $onMessage,
        protected readonly Closure $onClose,
        string $protocol = Http::class,
        protected readonly ?Closure $onError = null,
    ) {
        stream_set_blocking($this->socket, false);
        $this->protocol = $protocol;
        $this->lastReadAt = microtime(true);
    }

    /**
     * Dynamically switch connection protocol (e.g. from HTTP to WebSocket after handshake).
     *
     * @param class-string<ProtocolInterface> $protocol
     */
    public function setProtocol(string $protocol): void
    {
        $this->protocol = $protocol;
    }

    /**
     * @return class-string<ProtocolInterface>
     */
    public function getProtocol(): string
    {
        return $this->protocol;
    }

    /** The address the peer connected from, without its port; `unknown` when the socket cannot say. */
    public function remoteAddress(): string
    {
        return self::hostOf(stream_socket_get_name($this->socket, true));
    }

    /**
     * Whether the peer is on this machine: a loopback address (127.0.0.0/8, ::1, also IPv4-mapped),
     * or the very address it connected to — what a server listening on one LAN address sees when
     * this machine connects to it.
     */
    public function isFromThisMachine(): bool
    {
        $peer = strtolower($this->remoteAddress());
        if ($peer === '::1' || str_starts_with($peer, '127.') || str_starts_with($peer, '::ffff:127.')) {
            return true;
        }

        return $peer !== 'unknown' && $peer === strtolower(self::hostOf(stream_socket_get_name($this->socket, false)));
    }

    private static function hostOf(string|false $name): string
    {
        if ($name === false) {
            return 'unknown';
        }

        return trim(preg_replace('/:\d+$/', '', $name) ?? $name, '[]');
    }

    /**
     * Feed incoming raw bytes into the read buffer and parse complete packages via protocol input().
     */
    public function onReadable(): void
    {
        $data = fread($this->socket, 65536);

        if ($data === false || $data === '') {
            $this->close();

            return;
        }

        $this->lastReadAt = microtime(true);
        $this->readBuffer .= $data;

        // Framing state machine loop decoupled by ProtocolInterface::input()
        while ($this->readBuffer !== '' && !$this->isClosed) {
            $packageLen = ($this->protocol)::input($this->readBuffer, $this);

            if ($packageLen === 0) {
                // Incomplete package, wait for next readable chunk
                return;
            }

            if ($packageLen === false) {
                // Protocol error or oversized frame
                $this->close('a frame the protocol could not read');

                return;
            }

            $rawPackage = substr($this->readBuffer, 0, $packageLen);
            $this->readBuffer = substr($this->readBuffer, $packageLen);

            try {
                $data = ($this->protocol)::decode($rawPackage, $this);

                if ($data !== null) {
                    ($this->onMessage)($this, $data);
                }
            } catch (Throwable $e) {
                if ($this->onError !== null) {
                    ($this->onError)($this, $e);
                } else {
                    // Default fault isolation: if HTTP, return 500 JSON; otherwise close safely
                    if ($this->protocol === Http::class && !$this->isClosed) {
                        $this->sendResponse(500, ['Content-Type' => 'application/json'], json_encode([
                            'error' => 'Internal server error: ' . $e->getMessage(),
                        ]));
                    }
                    $this->close();
                }
            }
        }
    }

    /**
     * Send application data encoded via connection protocol.
     */
    public function send(mixed $data): void
    {
        $wire = ($this->protocol)::encode($data, $this);
        $this->sendRaw($wire);
    }

    /**
     * Send raw wire bytes directly to client with non-blocking write buffering.
     */
    public function sendRaw(string $data): void
    {
        if ($this->isClosed || $data === '') {
            return;
        }

        if ($this->sendBuffer !== '') {
            $size = strlen($this->sendBuffer) - $this->sendOffset + strlen($data);
            $stalled = microtime(true) - $this->lastDrainAt > self::STALLED_AFTER;

            if ($size > self::HARD_SEND_BUFFER) {
                $this->close('send buffer past ' . intdiv(self::HARD_SEND_BUFFER, 1024 * 1024) . ' MB');

                return;
            }

            if ($size > self::MAX_SEND_BUFFER && $stalled) {
                $this->close('the peer has read nothing for ' . self::STALLED_AFTER . ' s');

                return;
            }

            $this->sendBuffer .= $data;

            return;
        }

        $this->sendBuffer = $data;
        $this->lastDrainAt = microtime(true);
        $this->flush();
    }

    /**
     * Write what the socket takes now, a chunk at a time; wait for room for the rest, or for the
     * system to have buffer again (`NO_BUFFER_RETRY`); close on anything else.
     */
    private function flush(): void
    {
        while ($this->sendBuffer !== '' && !$this->isClosed) {
            $problem = null;
            set_error_handler(static function (int $no, string $message) use (&$problem): bool {
                $problem = $message;

                return true;
            });
            try {
                $written = fwrite($this->socket, substr($this->sendBuffer, $this->sendOffset, self::WRITE_CHUNK));
            } finally {
                restore_error_handler();
            }

            if ($written === false) {
                if ($problem !== null && preg_match('/errno=(55|105)\b|No buffer space/i', $problem) === 1) {
                    $this->disarmWritable();
                    $this->retryTimer ??= Loop::get()->delay(self::NO_BUFFER_RETRY, function (): void {
                        $this->retryTimer = null;
                        $this->flush();
                    });

                    return;
                }

                $this->close('write failed' . ($problem !== null ? ": {$problem}" : ''));

                return;
            }

            if ($written === 0) {
                $this->armWritable();

                return;
            }

            // From an offset, not a fresh copy of the rest after every write: with a big buffer
            // and small writes that copying was quadratic, and pinned the server's CPU.
            $this->sendOffset += $written;
            $this->lastDrainAt = microtime(true);

            if ($this->sendOffset >= strlen($this->sendBuffer)) {
                $this->sendBuffer = '';
                $this->sendOffset = 0;
            } elseif ($this->sendOffset > self::WRITE_CHUNK * 16) {
                $this->sendBuffer = substr($this->sendBuffer, $this->sendOffset);
                $this->sendOffset = 0;
            }
        }

        $this->disarmWritable();

        if ($this->sendBuffer === '' && $this->closeWhenDrained) {
            $this->close();

            return;
        }

        if ($this->sendBuffer === '' && $this->drainListener !== null) {
            ($this->drainListener)($this);
        }
    }

    /** Whether something is still waiting to be written: the peer is behind. */
    public function hasBacklog(): bool
    {
        return $this->sendBuffer !== '';
    }

    /** Run `$listener` each time the queue empties; null stops it. */
    public function onDrain(?Closure $listener): void
    {
        $this->drainListener = $listener;
    }

    /**
     * Close once everything queued has been written.
     *
     * `close()` closes now, and anything still in the send buffer goes with it — which is right
     * for an error and wrong for a response whose end *is* the close, an SSE stream above all.
     * This is the other order: the socket stays until the buffer is empty, then goes.
     */
    public function closeAfterSend(): void
    {
        if ($this->isClosed) {
            return;
        }

        $this->closeWhenDrained = true;

        if ($this->sendBuffer === '') {
            $this->close();
        }
    }

    /**
     * Convenient HTTP response helper.
     *
     * @param array<string, string> $headers
     */
    public function sendResponse(int $status, array $headers, string $body): void
    {
        $response = Http::encode([
            'status' => $status,
            'headers' => $headers,
            'body' => $body,
        ], $this);

        $this->sendRaw($response);
    }

    private function armWritable(): void
    {
        if ($this->writableWatcher !== null || $this->isClosed) {
            return;
        }

        $this->writableWatcher = Loop::get()->onWritable($this->socket, function (): void {
            $this->flush();
        });
    }

    private function disarmWritable(): void
    {
        if ($this->writableWatcher !== null) {
            Loop::get()->cancel($this->writableWatcher);
            $this->writableWatcher = null;
        }
    }

    public function setReadableWatcher(?string $watcher): void
    {
        $this->readableWatcher = $watcher;
    }

    /** Seconds since the peer last sent anything. */
    public function silentFor(): float
    {
        return microtime(true) - $this->lastReadAt;
    }

    public function isClosed(): bool
    {
        return $this->isClosed;
    }

    /**
     * Close now. `$why`, for a close this side chose, goes to the log: a page that keeps
     * reconnecting is otherwise a mystery from both ends.
     */
    public function close(?string $why = null): void
    {
        if ($this->isClosed) {
            return;
        }

        if ($why !== null) {
            Logger::warning("[web] closing a connection: {$why}");
        }

        $this->isClosed = true;
        $this->disarmWritable();

        if ($this->retryTimer !== null) {
            Loop::get()->cancel($this->retryTimer);
            $this->retryTimer = null;
        }

        if ($this->readableWatcher !== null) {
            Loop::get()->cancel($this->readableWatcher);
            $this->readableWatcher = null;
        }

        if (is_resource($this->socket)) {
            fclose($this->socket);
        }

        ($this->onClose)($this);
    }
}
