<?php

declare(strict_types=1);

namespace Pig\Ai\Http\Http2;

use Closure;
use Pig\Ai\Http\HttpError;
use Pig\Ai\Http\Request;
use Pig\Ai\Http\Response;
use Pig\Async\AbortError;
use Pig\Async\AbortSignal;
use Pig\Async\Deferred;
use Pig\Async\Loop;
use Pig\Async\Socket;
use Throwable;

/**
 * One HTTP/2 connection (RFC 9113) carrying many requests at once, kept open between them.
 *
 * **Nothing reads in the background.** Whoever is waiting for something — a response head, the next
 * piece of a body, room in the send window — reads the socket itself; while one coroutine does, the
 * others wait to be told something arrived and re-check what they were waiting for. When the reader
 * is done it hands the socket on, so a connection nobody is waiting on has no coroutine and no
 * watcher on the loop, and a pooled one costs nothing between requests. The frames that arrived
 * meanwhile (a PING, a GOAWAY, the server closing) are read before the connection is used again.
 *
 * HPACK's encoder keeps no table (`HpackEncoder`), so a request's headers do not depend on which
 * requests went before it and may be written in any order. Stream ids must go out in increasing
 * order, so one is taken only while holding the write lock, and the lock is held for the HEADERS
 * frames and for nothing longer.
 *
 * @internal
 */
final class Http2Connection
{
    private const string PREFACE = "PRI * HTTP/2.0\r\n\r\nSM\r\n\r\n";

    private const int DATA = 0;
    private const int HEADERS = 1;
    private const int RST_STREAM = 3;
    private const int SETTINGS = 4;
    private const int PUSH_PROMISE = 5;
    private const int PING = 6;
    private const int GOAWAY = 7;
    private const int WINDOW_UPDATE = 8;
    private const int CONTINUATION = 9;

    private const int END_STREAM = 0x1;
    private const int ACK = 0x1;
    private const int END_HEADERS = 0x4;
    private const int PADDED = 0x8;
    private const int PRIORITY_FLAG = 0x20;

    private const int CANCEL = 8;

    /**
     * What the server may send before a WINDOW_UPDATE, as Node's `fetch` opens a connection (undici's
     * `initialWindowSize` and `connectionWindowSize`, read off the wire from Node 26): a server that tells
     * clients apart by how they say hello sees the same one.
     */
    private const int RECEIVE_WINDOW = 262144;

    private const int CONNECTION_WINDOW = 524288;

    /**
     * What a stream is given once it has delivered a whole `RECEIVE_WINDOW` — a download, not a model's
     * answer, which is a few kilobytes — and the connection with it. A window is a bandwidth–delay product: at
     * 256 KiB a 10 MB download took 15 s on a link where curl took 2.4 s. The hello is Node's and the
     * growth comes after it, in frames nothing compares.
     */
    private const int BULK_WINDOW = 8388608;

    /** The largest frame this side accepts: the protocol's default, which is never raised. */
    private const int MAX_FRAME = 16384;

    /** The most a header block may add up to across its CONTINUATION frames. */
    private const int MAX_BLOCK = 262144;

    /** The most one write carries, and so the longest the write lock is held. */
    private const int WRITE_BATCH = 65536;

    private const int MAX_WINDOW = 0x7fffffff;

    private const array ERROR_NAMES = [
        'NO_ERROR', 'PROTOCOL_ERROR', 'INTERNAL_ERROR', 'FLOW_CONTROL_ERROR', 'SETTINGS_TIMEOUT',
        'STREAM_CLOSED', 'FRAME_SIZE_ERROR', 'REFUSED_STREAM', 'CANCEL', 'COMPRESSION_ERROR',
        'CONNECT_ERROR', 'ENHANCE_YOUR_CALM', 'INADEQUATE_SECURITY', 'HTTP_1_1_REQUIRED',
    ];

    /** Headers that describe an HTTP/1.1 connection, and are an error on an HTTP/2 one (§8.2.2). */
    private const array CONNECTION_HEADERS = ['connection', 'keep-alive', 'proxy-connection', 'transfer-encoding', 'upgrade', 'host'];

    /** @var array<int, Http2Stream> */
    private array $streams = [];

    private int $nextId = 1;

    private string $input = '';

    private readonly HpackDecoder $decoder;

    private readonly HpackEncoder $encoder;

    /** What the server said it takes (RFC 9113 §6.5.2), until it says otherwise. */
    private int $peerMaxStreams = self::MAX_WINDOW;

    private int $peerInitialWindow = 65535;

    private int $peerMaxFrame = 16384;

    /** How many bytes of body the server will take on the connection before a WINDOW_UPDATE. */
    private int $sendWindow = 65535;

    /** Bytes handed on and not yet given back to the server's send window. */
    private int $unacknowledged = 0;

    /** The connection's window has been raised to `BULK_WINDOW`. */
    private bool $grown = false;

    /** Someone is reading the socket. */
    private bool $reading = false;

    private bool $writing = false;

    /** @var list<Deferred<null>> */
    private array $writeQueue = [];

    /** @var list<Deferred<null>> those waiting to be told that something arrived */
    private array $waiters = [];

    /** The header block being assembled across CONTINUATION frames. */
    private ?int $blockStream = null;

    private string $block = '';

    private bool $blockEnds = false;

    /** Set when the connection is over, and says why. Everything after that fails with it. */
    private ?string $closedReason = null;

    /** The server sent GOAWAY: what is in flight finishes, nothing new is started. */
    private bool $draining = false;

    /** @param float $timeout seconds a write may stall */
    private function __construct(private readonly Socket $socket, private readonly float $timeout)
    {
        $this->decoder = new HpackDecoder();
        $this->encoder = new HpackEncoder();
    }

    /**
     * Speak HTTP/2 on $socket, whose TLS handshake has just chosen `h2`.
     *
     * The first frames go out at once and the server's SETTINGS are not waited for: the protocol
     * allows requests straight after the preface, on its defaults.
     */
    public static function open(Socket $socket, float $timeout): self
    {
        $connection = new self($socket, $timeout);
        $connection->writeRaw(
            self::PREFACE
            // No server push; a window per stream of our own choosing.
            . self::frame(self::SETTINGS, 0, 0, pack('nNnN', 2, 0, 4, self::RECEIVE_WINDOW))
            // The connection's own window starts at the protocol's 65535, whatever SETTINGS says.
            . self::frame(self::WINDOW_UPDATE, 0, 0, pack('N', self::CONNECTION_WINDOW - 65535)),
        );

        return $connection;
    }

    /**
     * Whether a request may be started on this connection. Reads what arrived while it was idle
     * first, which is how a server that closed it or said GOAWAY is found out.
     */
    public function isUsable(): bool
    {
        $this->drain();

        return $this->closedReason === null && !$this->draining && $this->nextId < self::MAX_WINDOW - 2;
    }

    /**
     * Send the request and return once the response head is in; the body is read from the stream.
     *
     * @param string $authority the `:authority`, as the host header would carry it
     * @param string $target the path and query
     * @param float $idleTimeout seconds the head, and then each wait for the body, may take; 0 is forever
     */
    public function request(Request $request, string $authority, string $target, float $idleTimeout, ?AbortSignal $signal): Response
    {
        $headers = $this->headersOf($request, $authority, $target);

        do {
            $this->wait(
                fn (): bool => $this->closedReason !== null || count($this->streams) < $this->peerMaxStreams,
                $idleTimeout,
                $signal,
            );
            $stream = $this->startStream($headers, $request->body !== null && $request->body !== '', $signal);
        } while ($stream === null);

        try {
            $this->sendBody($stream, (string) $request->body, $idleTimeout, $signal);
            $this->wait(
                static fn (): bool => $stream->status !== null || $stream->error !== null,
                $idleTimeout,
                $signal,
            );

            if ($stream->status === null) {
                throw $stream->error ?? new HttpError($this->closedReason ?? 'Connection closed before the response head was complete');
            }
        } catch (Throwable $error) {
            $this->release($stream);

            throw $error;
        }

        $length = isset($stream->headers['content-length']) ? (int) $stream->headers['content-length'] : null;

        return new Response(
            $stream->status,
            '',
            $stream->headers,
            new Http2Body($this, $stream, $idleTimeout, $signal, $length),
        );
    }

    /** Suspend until $stream has a piece of body, has ended or has failed. */
    public function waitFor(Http2Stream $stream, float $idleTimeout, ?AbortSignal $signal): void
    {
        $this->wait(
            static fn (): bool => $stream->chunks !== [] || $stream->ended || $stream->error !== null,
            $idleTimeout,
            $signal,
        );
    }

    /** $bytes of $stream's body have been handed on: give the server room to send more. */
    public function consumed(Http2Stream $stream, int $bytes): void
    {
        $this->unacknowledged += $bytes;
        $stream->unacknowledged += $bytes;
        $stream->delivered += $bytes;
        $frames = '';
        $open = !$stream->ended && $stream->error === null;

        // A body that has used a whole window is a download: let it have more, and the connection with it.
        if ($open && !$stream->grown && $stream->delivered >= self::RECEIVE_WINDOW) {
            $stream->grown = true;
            $frames .= self::frame(self::WINDOW_UPDATE, 0, $stream->id, pack('N', self::BULK_WINDOW - self::RECEIVE_WINDOW));

            if (!$this->grown) {
                $this->grown = true;
                $frames .= self::frame(self::WINDOW_UPDATE, 0, 0, pack('N', self::BULK_WINDOW - self::CONNECTION_WINDOW));
            }
        }

        if ($open && $stream->unacknowledged >= intdiv($stream->grown ? self::BULK_WINDOW : self::RECEIVE_WINDOW, 2)) {
            $frames .= self::frame(self::WINDOW_UPDATE, 0, $stream->id, pack('N', $stream->unacknowledged));
            $stream->unacknowledged = 0;
        }

        $this->send($frames . $this->connectionWindowUpdate());
    }

    /**
     * Let go of $stream: the request is over, or its body is not wanted any more. A stream that has
     * not finished is cancelled, so the server stops sending it.
     */
    public function release(Http2Stream $stream): void
    {
        if (!isset($this->streams[$stream->id])) {
            return;
        }

        unset($this->streams[$stream->id]);

        // What was received and never read still counts against the connection's window.
        foreach ($stream->chunks as $chunk) {
            $this->unacknowledged += strlen($chunk);
        }

        $stream->chunks = [];
        $frames = '';

        if (!$stream->ended && $stream->error === null && $this->closedReason === null) {
            $frames = self::frame(self::RST_STREAM, 0, $stream->id, pack('N', self::CANCEL));
        }

        if ($this->closedReason === null) {
            $this->send($frames . $this->connectionWindowUpdate());
        }

        if ($this->draining && $this->streams === []) {
            $this->shutdown('Connection closed after the server said GOAWAY');
        }
    }

    /** Close the connection; whatever is still in flight on it fails. */
    public function close(): void
    {
        $this->shutdown('Connection closed');
    }

    /**
     * Take a stream id and write the request's headers, in one go with the write lock held.
     *
     * @param list<array{0: string, 1: string}> $headers
     * @return Http2Stream|null null when the server's limit on concurrent streams is reached
     */
    private function startStream(array $headers, bool $hasBody, ?AbortSignal $signal): ?Http2Stream
    {
        return $this->locked(function () use ($headers, $hasBody, $signal): ?Http2Stream {
            if ($this->closedReason !== null) {
                throw new HttpError($this->closedReason);
            }

            if ($this->draining) {
                throw new HttpError('Connection closed: the server is going away and takes no new requests');
            }

            if (count($this->streams) >= $this->peerMaxStreams) {
                return null;
            }

            $stream = new Http2Stream($this->nextId, $this->peerInitialWindow);
            $this->nextId += 2;
            $this->streams[$stream->id] = $stream;

            $block = $this->encoder->encode($headers);
            $size = min($this->peerMaxFrame, self::WRITE_BATCH);
            $fragments = str_split($block, $size);
            $frames = '';

            foreach ($fragments as $i => $fragment) {
                $last = $i === count($fragments) - 1;
                $frames .= $i === 0
                    ? self::frame(self::HEADERS, ($last ? self::END_HEADERS : 0) | ($hasBody ? 0 : self::END_STREAM), $stream->id, $fragment)
                    : self::frame(self::CONTINUATION, $last ? self::END_HEADERS : 0, $stream->id, $fragment);
            }

            $this->writeRaw($frames, $signal);

            return $stream;
        });
    }

    /** Write the body as DATA frames, as far as the two send windows allow, waiting for more when they do not. */
    private function sendBody(Http2Stream $stream, string $body, float $idleTimeout, ?AbortSignal $signal): void
    {
        $length = strlen($body);
        $offset = 0;

        while ($offset < $length) {
            $this->wait(
                fn (): bool => $this->closedReason !== null
                    || $stream->error !== null
                    || $stream->ended
                    || ($this->sendWindow > 0 && $stream->sendWindow > 0),
                $idleTimeout,
                $signal,
            );

            if ($this->closedReason !== null) {
                throw new HttpError($this->closedReason);
            }

            if ($stream->error !== null) {
                // The server may answer before it has read all of it — a refusal — and reset the stream.
                if ($stream->status !== null) {
                    return;
                }

                throw $stream->error;
            }

            if ($stream->ended) {
                return;
            }

            $batch = min($this->sendWindow, $stream->sendWindow, $length - $offset, self::WRITE_BATCH);
            // Spent before the write suspends, so that a request sending beside this one cannot spend it twice.
            $this->sendWindow -= $batch;
            $stream->sendWindow -= $batch;
            $frames = '';

            foreach (str_split(substr($body, $offset, $batch), $this->peerMaxFrame) as $piece) {
                $offset += strlen($piece);
                $frames .= self::frame(self::DATA, $offset >= $length ? self::END_STREAM : 0, $stream->id, $piece);
            }

            $this->send($frames, $signal);
        }
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function headersOf(Request $request, string $authority, string $target): array
    {
        // In the order Node's `fetch` sends them (read off the wire from Node 26), which is not curl's or a browser's.
        $headers = [
            [':authority', $authority],
            [':method', $request->method],
            [':path', $target],
            [':scheme', 'https'],
        ];

        // Keyed by lowercase name, the last one winning, as HTTP/1.1's writer does (`HttpClient::serialize()`):
        // `Accept` and `accept` are one header, and a header sent twice is a malformed request to a front
        // end — AWS's load balancer answers `400 Bad Request` to a repeated `content-length`.
        $map = [];

        foreach ($request->headers as $name => $value) {
            $map[strtolower($name)] = $value;
        }

        // Nothing here decompresses, so do not leave the choice to the server.
        $map['accept-encoding'] ??= 'identity';

        if ($request->body !== null) {
            $map['content-length'] = (string) strlen($request->body);
        }

        foreach ($map as $name => $value) {
            if (!in_array($name, self::CONNECTION_HEADERS, true) && ($name !== 'te' || strtolower($value) === 'trailers')) {
                $headers[] = [$name, $value];
            }
        }

        return $headers;
    }

    // ---- waiting and reading -------------------------------------------------------------------

    /**
     * Suspend until $done holds, reading the socket when no one else is.
     *
     * The timeout runs from here and is not restarted by the traffic of other requests, so a stream
     * that is waiting is not kept alive by its neighbours.
     *
     * @param Closure(): bool $done
     */
    private function wait(Closure $done, float $timeout, ?AbortSignal $signal): void
    {
        $started = microtime(true);

        while (!$done()) {
            if ($this->closedReason !== null) {
                throw new HttpError($this->closedReason);
            }

            $remaining = 0.0;

            if ($timeout > 0.0) {
                $remaining = $timeout - (microtime(true) - $started);

                if ($remaining <= 0.0) {
                    throw new HttpError(sprintf('HTTP/2 stream timed out after %.1fs', $timeout));
                }
            }

            $signal?->throwIfAborted();

            if ($this->reading) {
                $this->awaitActivity($remaining, $signal);

                continue;
            }

            $this->reading = true;

            try {
                $this->readSome($remaining, $signal);
            } finally {
                // Whoever reads next is whichever waiter gets here first.
                $this->reading = false;
                $this->wake();
            }
        }
    }

    private function readSome(float $timeout, ?AbortSignal $signal): void
    {
        try {
            $chunk = $this->socket->read(65536, $timeout, $signal);
        } catch (Throwable $error) {
            // A timeout or an abort is this reader's own; a socket that is closed is everybody's.
            if ($this->socket->isClosed()) {
                $this->shutdown('Connection closed: ' . $error->getMessage());
            }

            throw $error;
        }

        if ($chunk === null) {
            $this->shutdown('Connection closed by the server');

            return;
        }

        $this->input .= $chunk;
        $this->parse();
    }

    /** Read what is there without waiting, for a connection that sat idle. */
    private function drain(): void
    {
        if ($this->reading) {
            return;
        }

        while ($this->closedReason === null) {
            try {
                $chunk = $this->socket->readAvailable(65536);
            } catch (Throwable $error) {
                $this->shutdown('Connection closed: ' . $error->getMessage());

                throw $error;
            }

            if ($chunk === null) {
                $this->shutdown('Connection closed by the server');

                return;
            }

            if ($chunk === '') {
                return;
            }

            $this->input .= $chunk;
            $this->parse();
        }
    }

    /** Suspend until the reader has read something, or this wait is called off. */
    private function awaitActivity(float $timeout, ?AbortSignal $signal): void
    {
        $loop = Loop::get();
        $deferred = new Deferred();
        $this->waiters[] = $deferred;
        $timer = null;
        $listener = null;

        if ($timeout > 0.0) {
            $timer = $loop->delay($timeout, static function () use ($deferred, $timeout): void {
                if (!$deferred->isComplete()) {
                    $deferred->error(new HttpError(sprintf('HTTP/2 stream timed out after %.1fs', $timeout)));
                }
            });
        }

        if ($signal !== null) {
            $listener = $signal->onAbort(static function (string $reason) use ($deferred): void {
                if (!$deferred->isComplete()) {
                    $deferred->error(new AbortError($reason));
                }
            });
        }

        try {
            $deferred->future->await();
        } finally {
            if ($timer !== null) {
                $loop->cancel($timer);
            }

            if ($listener !== null && $signal !== null) {
                $signal->removeListener($listener);
            }
        }
    }

    private function wake(): void
    {
        $waiters = $this->waiters;
        $this->waiters = [];

        foreach ($waiters as $waiter) {
            if (!$waiter->isComplete()) {
                $waiter->complete(null);
            }
        }
    }

    // ---- writing -------------------------------------------------------------------------------

    private function send(string $bytes, ?AbortSignal $signal = null): void
    {
        if ($bytes === '') {
            return;
        }

        $this->locked(function () use ($bytes, $signal): void {
            $this->writeRaw($bytes, $signal);
        });
    }

    /**
     * Run $fn alone: two coroutines writing at once would interleave their frames.
     *
     * @template T
     * @param Closure(): T $fn
     * @return T
     */
    private function locked(Closure $fn): mixed
    {
        while ($this->writing) {
            $turn = new Deferred();
            $this->writeQueue[] = $turn;
            $turn->future->await();
        }

        $this->writing = true;

        try {
            return $fn();
        } finally {
            $this->writing = false;
            array_shift($this->writeQueue)?->complete(null);
        }
    }

    /** Write with the lock held. A write that fails or is cut short leaves a half frame: the end of the connection. */
    private function writeRaw(string $bytes, ?AbortSignal $signal = null): void
    {
        if ($this->closedReason !== null) {
            throw new HttpError($this->closedReason);
        }

        try {
            $this->socket->write($bytes, $this->timeout, $signal);
        } catch (Throwable $error) {
            $this->shutdown('Connection closed: ' . $error->getMessage());

            throw $error;
        }
    }

    private function connectionWindowUpdate(): string
    {
        if ($this->unacknowledged < intdiv($this->grown ? self::BULK_WINDOW : self::CONNECTION_WINDOW, 2)) {
            return '';
        }

        $frame = self::frame(self::WINDOW_UPDATE, 0, 0, pack('N', $this->unacknowledged));
        $this->unacknowledged = 0;

        return $frame;
    }

    private static function frame(int $type, int $flags, int $stream, string $payload): string
    {
        return substr(pack('N', strlen($payload)), 1) . chr($type) . chr($flags) . pack('N', $stream) . $payload;
    }

    // ---- frames in -----------------------------------------------------------------------------

    private function parse(): void
    {
        $offset = 0;
        $total = strlen($this->input);

        try {
            while ($total - $offset >= 9) {
                $length = (ord($this->input[$offset]) << 16) | (ord($this->input[$offset + 1]) << 8) | ord($this->input[$offset + 2]);

                if ($length > self::MAX_FRAME) {
                    $this->fail("a frame of {$length} bytes, above the " . self::MAX_FRAME . ' allowed');
                }

                if ($total - $offset < 9 + $length) {
                    break;
                }

                $type = ord($this->input[$offset + 3]);
                $flags = ord($this->input[$offset + 4]);
                $id = ((ord($this->input[$offset + 5]) & 0x7f) << 24) | (ord($this->input[$offset + 6]) << 16)
                    | (ord($this->input[$offset + 7]) << 8) | ord($this->input[$offset + 8]);
                $payload = substr($this->input, $offset + 9, $length);
                $offset += 9 + $length;

                $this->dispatch($type, $flags, $id, $payload);
            }
        } finally {
            $this->input = (string) substr($this->input, $offset);
            $this->wake();
        }

        $this->send($this->connectionWindowUpdate());
    }

    private function dispatch(int $type, int $flags, int $id, string $payload): void
    {
        if ($this->blockStream !== null && $type !== self::CONTINUATION) {
            $this->fail('a frame in the middle of a header block');
        }

        match ($type) {
            self::DATA => $this->onData($flags, $id, $payload),
            self::HEADERS => $this->onHeaders($flags, $id, $payload),
            self::RST_STREAM => $this->onReset($id, $payload),
            self::SETTINGS => $this->onSettings($flags, $payload),
            self::PUSH_PROMISE => $this->fail('a PUSH_PROMISE, which this side turned off'),
            self::PING => $flags & self::ACK ? null : $this->send(self::frame(self::PING, self::ACK, 0, $payload)),
            self::GOAWAY => $this->onGoAway($payload),
            self::WINDOW_UPDATE => $this->onWindowUpdate($id, $payload),
            self::CONTINUATION => $this->onContinuation($flags, $id, $payload),
            // PRIORITY is advice, and a type nobody has heard of is to be ignored (§4.1).
            default => null,
        };
    }

    private function onData(int $flags, int $id, string $payload): void
    {
        if ($id === 0) {
            $this->fail('DATA on stream 0');
        }

        $data = $this->unpadded($flags, $payload);
        $stream = $this->streams[$id] ?? null;

        // Flow control counts the whole payload, padding included.
        if ($stream === null || $stream->error !== null) {
            $this->unacknowledged += strlen($payload);

            return;
        }

        $padding = strlen($payload) - strlen($data);
        $this->unacknowledged += $padding;
        $stream->unacknowledged += $padding;

        if ($data !== '') {
            $stream->chunks[] = $data;
        }

        if (($flags & self::END_STREAM) !== 0) {
            $stream->ended = true;
        }
    }

    private function onHeaders(int $flags, int $id, string $payload): void
    {
        if ($id === 0) {
            $this->fail('HEADERS on stream 0');
        }

        $fragment = $this->unpadded($flags, $payload);

        if (($flags & self::PRIORITY_FLAG) !== 0) {
            $fragment = (string) substr($fragment, 5);
        }

        $this->blockStream = $id;
        $this->block = $fragment;
        $this->blockEnds = ($flags & self::END_STREAM) !== 0;

        if (($flags & self::END_HEADERS) !== 0) {
            $this->finishBlock();
        }
    }

    private function onContinuation(int $flags, int $id, string $payload): void
    {
        if ($this->blockStream === null || $id !== $this->blockStream) {
            $this->fail('a CONTINUATION without a header block to continue');
        }

        $this->block .= $payload;

        if (strlen($this->block) > self::MAX_BLOCK) {
            $this->fail('a header block larger than ' . self::MAX_BLOCK . ' bytes');
        }

        if (($flags & self::END_HEADERS) !== 0) {
            $this->finishBlock();
        }
    }

    /**
     * A header block is complete. It is decoded even for a stream nobody wants any more: HPACK's
     * table is shared by the whole connection, and skipping one block corrupts every later one.
     */
    private function finishBlock(): void
    {
        $id = (int) $this->blockStream;
        $ends = $this->blockEnds;
        $block = $this->block;
        $this->blockStream = null;
        $this->block = '';

        try {
            $headers = $this->decoder->decode($block);
        } catch (HttpError $error) {
            $this->fail($error->getMessage());
        }

        $stream = $this->streams[$id] ?? null;

        if ($stream === null || $stream->error !== null) {
            return;
        }

        // Anything after the response's head is trailers, which nothing here reads.
        if ($stream->status === null) {
            $status = null;
            $map = [];

            foreach ($headers as [$name, $value]) {
                if ($name === ':status') {
                    $status = (int) $value;
                } elseif ($name !== '' && $name[0] !== ':') {
                    $map[$name] = isset($map[$name]) ? "{$map[$name]}, {$value}" : $value;
                }
            }

            if ($status === null) {
                $stream->error = new HttpError('HTTP/2 response without a :status');

                return;
            }

            // An interim response (100 Continue, 103 Early Hints) is followed by the real one.
            if ($status >= 100 && $status < 200 && $status !== 101) {
                return;
            }

            $stream->status = $status;
            $stream->headers = $map;
        }

        if ($ends) {
            $stream->ended = true;
        }
    }

    private function onReset(int $id, string $payload): void
    {
        $stream = $this->streams[$id] ?? null;

        if ($stream === null || strlen($payload) !== 4) {
            return;
        }

        $code = (int) unpack('N', $payload)[1];

        // The server answered and stopped listening: a reset that says nothing went wrong.
        if ($stream->ended || ($code === 0 && $stream->status !== null)) {
            $stream->ended = true;

            return;
        }

        $stream->error = new HttpError('Connection closed: the server reset the stream (' . (self::ERROR_NAMES[$code] ?? "error {$code}") . ')');
    }

    private function onSettings(int $flags, string $payload): void
    {
        if (($flags & self::ACK) !== 0) {
            return;
        }

        if (strlen($payload) % 6 !== 0) {
            $this->fail('a SETTINGS frame of the wrong size');
        }

        for ($i = 0; $i < strlen($payload); $i += 6) {
            ['id' => $setting, 'value' => $value] = unpack('nid/Nvalue', $payload, $i);

            if ($setting === 3) {
                $this->peerMaxStreams = $value;
            } elseif ($setting === 4) {
                if ($value > self::MAX_WINDOW) {
                    $this->fail('an initial window above 2^31-1');
                }

                // The streams already open are given, or lose, the difference (§6.9.2).
                foreach ($this->streams as $stream) {
                    $stream->sendWindow += $value - $this->peerInitialWindow;
                }

                $this->peerInitialWindow = $value;
            } elseif ($setting === 5) {
                if ($value < 16384 || $value > 16777215) {
                    $this->fail('a maximum frame size outside 16384..16777215');
                }

                $this->peerMaxFrame = $value;
            }
        }

        $this->send(self::frame(self::SETTINGS, self::ACK, 0, ''));
    }

    private function onGoAway(string $payload): void
    {
        if (strlen($payload) < 8) {
            $this->fail('a GOAWAY of the wrong size');
        }

        ['last' => $last, 'code' => $code] = unpack('Nlast/Ncode', $payload);
        $last &= self::MAX_WINDOW;
        $this->draining = true;
        $why = $code === 0 ? '' : ' (' . (self::ERROR_NAMES[$code] ?? "error {$code}") . ')';

        // What the server never started it says so, and the request may be sent again.
        foreach ($this->streams as $stream) {
            if ($stream->id > $last && !$stream->ended && $stream->error === null) {
                $stream->error = new HttpError("Connection closed: the server is going away and did not process the request{$why}");
            }
        }

        if ($this->streams === []) {
            $this->shutdown('Connection closed after the server said GOAWAY' . $why);
        }
    }

    private function onWindowUpdate(int $id, string $payload): void
    {
        if (strlen($payload) !== 4) {
            $this->fail('a WINDOW_UPDATE of the wrong size');
        }

        $increment = (int) unpack('N', $payload)[1] & self::MAX_WINDOW;

        if ($increment === 0 && $id === 0) {
            $this->fail('a WINDOW_UPDATE of nothing');
        }

        if ($id === 0) {
            $this->sendWindow += $increment;

            if ($this->sendWindow > self::MAX_WINDOW) {
                $this->fail('a connection window above 2^31-1');
            }

            return;
        }

        $stream = $this->streams[$id] ?? null;

        if ($stream !== null) {
            $stream->sendWindow += $increment;
        }
    }

    private function unpadded(int $flags, string $payload): string
    {
        if (($flags & self::PADDED) === 0) {
            return $payload;
        }

        $padding = ord($payload[0] ?? $this->fail('a padded frame with no padding length'));

        if ($padding >= strlen($payload)) {
            $this->fail('padding longer than its frame');
        }

        return substr($payload, 1, strlen($payload) - 1 - $padding);
    }

    /** The server broke the protocol: the connection is over. */
    private function fail(string $message): never
    {
        $reason = "Connection closed: HTTP/2 protocol error, {$message}";
        $this->shutdown($reason);

        throw new HttpError($reason);
    }

    private function shutdown(string $reason): void
    {
        if ($this->closedReason !== null) {
            return;
        }

        $this->closedReason = $reason;

        foreach ($this->streams as $stream) {
            if (!$stream->ended && $stream->error === null) {
                $stream->error = new HttpError($reason);
            }
        }

        $this->socket->close();
        $this->wake();
    }
}
