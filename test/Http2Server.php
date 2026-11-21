<?php

declare(strict_types=1);

namespace Pig\Test;

use Closure;
use Pig\Ai\Http\Http2\HpackDecoder;
use Pig\Ai\Http\Http2\HpackEncoder;
use Pig\Async\Loop;
use RuntimeException;

/**
 * A loopback HTTP/2 server, in clear (prior knowledge), that does what the test tells it to.
 *
 * It parses what the client sends into `$frames` and calls `$onFrame` for each, and writes the frames
 * the test hands to `send()`. It answers the client's SETTINGS with an ACK and nothing else by
 * itself: a response, a WINDOW_UPDATE, a GOAWAY are all the test's to send, when it wants them.
 */
final class Http2Server
{
    public const int DATA = 0;
    public const int HEADERS = 1;
    public const int RST_STREAM = 3;
    public const int SETTINGS = 4;
    public const int PING = 6;
    public const int GOAWAY = 7;
    public const int WINDOW_UPDATE = 8;

    /** @var list<array{type: int, flags: int, stream: int, payload: string}> */
    public array $frames = [];

    /** @var array<int, list<array{0: string, 1: string}>> a stream's request headers */
    public array $requests = [];

    /** @var array<int, string> a stream's request body */
    public array $bodies = [];

    public int $connections = 0;

    /** @var list<resource> */
    private array $open = [];

    /** @var list<string> */
    private array $watchers = [];

    /** @var resource|null */
    private mixed $peer = null;

    /** The watcher on the connection, which has to come off the loop before the stream is closed. */
    private ?string $peerWatcher = null;

    private string $input = '';

    /** What `send()` could not write yet, written as the client reads (`$writer`). */
    private string $pending = '';

    /** The watcher that writes `$pending`, while there is any. */
    private ?string $writer = null;

    private bool $prefaceSeen = false;

    private readonly HpackDecoder $decoder;

    /** @param Closure(self, array{type: int, flags: int, stream: int, payload: string}): void|null $onFrame */
    public function __construct(private readonly ?Closure $onFrame = null, private readonly string $settings = '')
    {
        $this->decoder = new HpackDecoder();
    }

    public function __destruct()
    {
        $this->stop();
    }

    public function stop(): void
    {
        foreach ($this->watchers as $watcher) {
            Loop::get()->cancel($watcher);
        }

        $this->watchers = [];

        foreach ($this->open as $stream) {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $this->open = [];
        $this->peer = null;
        $this->pending = '';
        $this->writer = null;
    }

    /**
     * @param bool $tls speak it over TLS, choosing `h2` from what the client offers, as a server on
     *        the internet does; the default is HTTP/2 in clear, which a connection opens itself
     * @return int the port
     */
    public function start(bool $tls = false): int
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, stream_context_create());

        if ($server === false) {
            throw new RuntimeException("Cannot open test server: {$errstr}");
        }

        stream_set_blocking($server, false);
        $this->open[] = $server;

        $this->watchers[] = Loop::get()->onReadable($server, function ($listening) use ($tls): void {
            $connection = stream_socket_accept($listening, 0);

            if ($connection === false) {
                return;
            }

            stream_set_blocking($connection, false);
            $this->open[] = $connection;

            if ($tls) {
                $this->watchers[] = TlsCertificate::handshake($connection, 'h2', fn ($peer) => $this->begin($peer));

                return;
            }

            $this->begin($connection);
        });

        $name = (string) stream_socket_get_name($server, false);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }

    /** @param resource $connection */
    private function begin(mixed $connection): void
    {
        if ($this->writer !== null) {
            Loop::get()->cancel($this->writer);
            $this->writer = null;
        }

        $this->peer = $connection;
        $this->pending = '';
        $this->input = '';
        $this->prefaceSeen = false;
        $this->connections++;
        $this->send(self::frame(self::SETTINGS, 0, 0, $this->settings));
        $this->peerWatcher = Loop::get()->onReadable($connection, fn ($peer) => $this->read($peer));
        $this->watchers[] = $this->peerWatcher;
    }

    public function send(string $bytes): void
    {
        if (!is_resource($this->peer)) {
            return;
        }

        // Never a blocking write: the client reads on this same loop, so a write larger than the
        // socket buffers (a download's 600 KB; macOS's loopback buffers are far smaller than
        // Linux's) would wait for a read that cannot happen. What does not fit waits for the client.
        $this->pending .= $bytes;
        $this->flush();
    }

    private function flush(): void
    {
        while ($this->pending !== '' && is_resource($this->peer)) {
            $written = fwrite($this->peer, $this->pending);

            if ($written === false || $written === 0) {
                break;
            }

            $this->pending = substr($this->pending, $written);
        }

        if ($this->pending === '' || !is_resource($this->peer)) {
            if ($this->writer !== null) {
                Loop::get()->cancel($this->writer);
                $this->writer = null;
            }

            return;
        }

        if ($this->writer === null) {
            $this->writer = Loop::get()->onWritable($this->peer, fn () => $this->flush());
            $this->watchers[] = $this->writer;
        }
    }

    /** Hang up on the client. */
    public function hangUp(): void
    {
        // What was sent before hanging up arrives first, as it would from a real server.
        if ($this->pending !== '' && is_resource($this->peer)) {
            stream_set_blocking($this->peer, true);
            fwrite($this->peer, $this->pending);
            $this->pending = '';
        }

        if ($this->writer !== null) {
            Loop::get()->cancel($this->writer);
            $this->writer = null;
        }

        if ($this->peerWatcher !== null) {
            Loop::get()->cancel($this->peerWatcher);
            $this->peerWatcher = null;
        }

        if (is_resource($this->peer)) {
            fclose($this->peer);
        }

        $this->peer = null;
    }

    /**
     * @param list<array{0: string, 1: string}> $headers
     */
    public function respond(int $stream, int $status, array $headers = [], ?string $body = null, bool $end = true): void
    {
        $block = (new HpackEncoder())->encode(array_merge([[':status', (string) $status]], $headers));
        $this->send(self::frame(self::HEADERS, 0x4 | ($end && $body === null ? 0x1 : 0), $stream, $block));

        if ($body !== null) {
            $this->data($stream, $body, $end);
        }
    }

    public function data(int $stream, string $body, bool $end = false): void
    {
        $this->send(self::frame(self::DATA, $end ? 0x1 : 0, $stream, $body));
    }

    /** Give the client room to send more: the connection's window and a stream's. */
    public function openWindow(int $stream, int $bytes): void
    {
        $this->send(self::frame(self::WINDOW_UPDATE, 0, 0, pack('N', $bytes)) . self::frame(self::WINDOW_UPDATE, 0, $stream, pack('N', $bytes)));
    }

    /** @return list<array{type: int, flags: int, stream: int, payload: string}> */
    public function framesOfType(int $type): array
    {
        return array_values(array_filter($this->frames, static fn (array $frame): bool => $frame['type'] === $type));
    }

    public static function frame(int $type, int $flags, int $stream, string $payload): string
    {
        return substr(pack('N', strlen($payload)), 1) . chr($type) . chr($flags) . pack('N', $stream) . $payload;
    }

    /** @param resource $peer */
    private function read(mixed $peer): void
    {
        // To the end of what is there: TLS holds the rest of a record where the loop cannot see it.
        $data = '';

        while (($piece = fread($peer, 65536)) !== false && $piece !== '') {
            $data .= $piece;
        }

        if ($data === '') {
            return;
        }

        $this->input .= $data;

        if (!$this->prefaceSeen) {
            if (strlen($this->input) < 24) {
                return;
            }

            $this->input = substr($this->input, 24);
            $this->prefaceSeen = true;
        }

        while (strlen($this->input) >= 9) {
            $length = (ord($this->input[0]) << 16) | (ord($this->input[1]) << 8) | ord($this->input[2]);

            if (strlen($this->input) < 9 + $length) {
                return;
            }

            $frame = [
                'type' => ord($this->input[3]),
                'flags' => ord($this->input[4]),
                'stream' => (int) unpack('N', substr($this->input, 5, 4))[1] & 0x7fffffff,
                'payload' => substr($this->input, 9, $length),
            ];
            $this->input = substr($this->input, 9 + $length);
            $this->frames[] = $frame;

            if ($frame['type'] === self::SETTINGS && ($frame['flags'] & 0x1) === 0) {
                $this->send(self::frame(self::SETTINGS, 0x1, 0, ''));
            }

            if ($frame['type'] === self::HEADERS) {
                $this->requests[$frame['stream']] = $this->decoder->decode($frame['payload']);
            }

            if ($frame['type'] === self::DATA) {
                $this->bodies[$frame['stream']] = ($this->bodies[$frame['stream']] ?? '') . $frame['payload'];
            }

            if ($this->onFrame !== null) {
                ($this->onFrame)($this, $frame);
            }
        }
    }
}
