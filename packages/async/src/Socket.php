<?php

declare(strict_types=1);

namespace Pig\Async;

use Closure;
use Throwable;

/**
 * A non-blocking TCP or TLS socket that reads and writes like a blocking one.
 *
 * Every call that would block suspends the calling coroutine and registers a watcher
 * on the loop instead, so one `stream_select()` can be waiting on this socket and on
 * the keyboard at the same time. Upstream needs none of this: `fetch()` is a JS builtin.
 */
final class Socket
{
    /** @var resource|null */
    private mixed $stream;

    /** Settles whatever await is in flight, so close() cannot strand a suspended coroutine. */
    private ?Closure $pendingSettle = null;

    /** @param resource $stream */
    private function __construct(mixed $stream)
    {
        $this->stream = $stream;
    }

    /**
     * Connect, and negotiate TLS if asked.
     *
     * @param float $timeout seconds for each step; 0 waits forever
     */
    public static function connect(
        string $host,
        int $port,
        bool $tls = false,
        float $timeout = 30.0,
        ?AbortSignal $signal = null,
    ): self {
        $signal?->throwIfAborted();

        $context = stream_context_create($tls ? ['ssl' => ['peer_name' => $host]] : []);
        $errno = 0;
        $errstr = '';

        [$stream, $warning] = self::capturingWarnings(static fn () => stream_socket_client(
            "tcp://{$host}:{$port}",
            $errno,
            $errstr,
            $timeout,
            STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT,
            $context,
        ));

        if ($stream === false) {
            $detail = $errstr !== '' ? $errstr : $warning;

            throw new SocketError("Cannot connect to {$host}:{$port}: {$detail}");
        }

        stream_set_blocking($stream, false);
        $socket = new self($stream);

        // ASYNC_CONNECT returns before the handshake: writability is what says it landed.
        $socket->awaitReady(true, $timeout, $signal);

        if (stream_socket_get_name($stream, true) === false) {
            $socket->close();

            throw new SocketError("Cannot connect to {$host}:{$port}: connection refused");
        }

        if ($tls) {
            $socket->enableTls($host, $timeout, $signal);
        }

        return $socket;
    }

    /** Write all of $data, suspending whenever the kernel buffer is full. */
    public function write(string $data, float $timeout = 0.0, ?AbortSignal $signal = null): void
    {
        $this->assertOpen();
        $length = strlen($data);
        $offset = 0;

        while ($offset < $length) {
            $signal?->throwIfAborted();
            $written = fwrite($this->stream, substr($data, $offset));

            if ($written === false) {
                throw new SocketError('Write failed');
            }

            if ($written === 0) {
                $this->awaitReady(true, $timeout, $signal);

                continue;
            }

            $offset += $written;
        }
    }

    /**
     * Read whatever is available, suspending until there is something.
     *
     * @return string|null null at end of stream
     */
    public function read(int $length = 8192, float $timeout = 0.0, ?AbortSignal $signal = null): ?string
    {
        $this->assertOpen();

        while (true) {
            $signal?->throwIfAborted();

            // Read before selecting. OpenSSL decrypts a whole record at a time and holds
            // the remainder in its own buffer, where select() cannot see it — wait first
            // and a response already sitting in that buffer hangs until the peer sends more.
            $data = fread($this->stream, $length);

            if ($data === false) {
                throw new SocketError('Read failed');
            }

            if ($data !== '') {
                return $data;
            }

            if (feof($this->stream)) {
                return null;
            }

            $this->awaitReady(false, $timeout, $signal);
        }
    }

    public function isClosed(): bool
    {
        return $this->stream === null;
    }

    public function close(): void
    {
        if ($this->stream === null) {
            return;
        }

        // Settle first: the watcher must come off the loop before the stream goes away,
        // or the next tick trips over a watcher on a closed stream.
        ($this->pendingSettle ?? static fn (?Throwable $e) => null)(new SocketError('Socket closed'));

        $stream = $this->stream;
        $this->stream = null;
        fclose($stream);
    }

    /**
     * Negotiate TLS on an already-connected socket, verifying the certificate against $host.
     *
     * Public because a tunnelled connection has to do this in two steps: the TCP connection
     * goes to the proxy, and the certificate that matters belongs to the host on the far side
     * of it. `connect()` cannot do that itself — it only knows the address it dialled — so the
     * tunnel is built with `$tls` false and this is called afterwards with the real host.
     */
    public function enableTls(string $host, float $timeout = 30.0, ?AbortSignal $signal = null): void
    {
        $this->assertOpen();

        // The name to verify against, set here rather than only in `connect()`'s context: a
        // tunnelled socket was dialled to the proxy and would otherwise be checked against it.
        stream_context_set_option($this->stream, 'ssl', 'peer_name', $host);

        while (true) {
            [$done, $warning] = self::capturingWarnings(
                fn () => stream_socket_enable_crypto($this->stream, true, STREAM_CRYPTO_METHOD_TLS_CLIENT),
            );

            if ($done === true) {
                return;
            }

            if ($done === false) {
                $this->close();

                throw new SocketError("TLS handshake with {$host} failed" . ($warning !== '' ? ": {$warning}" : ''));
            }

            // 0 means "not enough bytes yet, call me again" — not failure. Treating it as
            // one breaks the connection intermittently; a handshake takes two passes or more.
            $this->awaitReady(false, $timeout, $signal);
        }
    }

    /** Suspend until the socket is readable or writable, or the wait is called off. */
    private function awaitReady(bool $forWriting, float $timeout, ?AbortSignal $signal): void
    {
        $loop = Loop::get();
        $deferred = new Deferred();
        $watcher = null;
        $timer = null;
        $abortListener = null;

        $settle = function (?Throwable $error) use (
            $loop,
            $deferred,
            $signal,
            &$watcher,
            &$timer,
            &$abortListener,
        ): void {
            // Timeout, abort and readiness can all land in the same tick; first one wins.
            if ($deferred->isComplete()) {
                return;
            }

            if ($watcher !== null) {
                $loop->cancel($watcher);
            }

            if ($timer !== null) {
                $loop->cancel($timer);
            }

            if ($abortListener !== null && $signal !== null) {
                $signal->removeListener($abortListener);
            }

            $this->pendingSettle = null;

            if ($error !== null) {
                $deferred->error($error);

                return;
            }

            $deferred->complete(null);
        };

        $this->pendingSettle = $settle;
        $ready = static fn () => $settle(null);

        $watcher = $forWriting
            ? $loop->onWritable($this->stream, $ready)
            : $loop->onReadable($this->stream, $ready);

        if ($timeout > 0.0) {
            $timer = $loop->delay($timeout, static fn () => $settle(
                new SocketError(sprintf('Socket timed out after %.1fs', $timeout)),
            ));
        }

        if ($signal !== null) {
            $abortListener = $signal->onAbort(static fn (string $reason) => $settle(new AbortError($reason)));
        }

        $deferred->future->await();
    }

    private function assertOpen(): void
    {
        if ($this->stream === null) {
            throw new SocketError('Socket is closed');
        }
    }

    /**
     * Run $fn with warnings captured rather than printed or suppressed.
     *
     * The stream functions signal failure through both a return value and a warning, and
     * the warning is where the detail is. `@` would throw that away.
     *
     * @return array{0: mixed, 1: string}
     */
    private static function capturingWarnings(Closure $fn): array
    {
        $warning = '';
        set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
            $warning = $message;

            return true;
        });

        try {
            return [$fn(), $warning];
        } finally {
            restore_error_handler();
        }
    }
}
