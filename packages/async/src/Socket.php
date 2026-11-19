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
    /**
     * The file of certificate authorities every TLS connection is checked against, when the person
     * has added some of their own (`useExtraCaFile()`); null is PHP's own. Process-wide for
     * `HttpClient::$default`'s reason: connections are opened in a dozen places.
     */
    private static ?string $caFile = null;

    /** @var resource|null */
    private mixed $stream;

    /**
     * Settles every await in flight, so close() cannot strand a suspended coroutine. More than one:
     * a connection several requests share has one coroutine reading while another writes.
     *
     * @var array<int, Closure(?Throwable): void>
     */
    private array $pendingSettles = [];

    /** @param resource $stream */
    private function __construct(mixed $stream)
    {
        $this->stream = $stream;
    }

    /**
     * Trust the certificates in $file as well as the system's, from here on; null goes back to the
     * system's alone. `bin/pig` sets it from `NODE_EXTRA_CA_CERTS`.
     *
     * @throws SocketError when the file cannot be used (`TlsRoots::bundleWith()`)
     */
    public static function useExtraCaFile(?string $file): void
    {
        self::$caFile = $file === null ? null : TlsRoots::bundleWith($file);
    }

    /**
     * Connect, and negotiate TLS if asked.
     *
     * @param float $timeout seconds for each step; 0 waits forever
     * @param list<string> $alpn the application protocols to offer in the TLS handshake, in order of
     *        preference; `alpnProtocol()` says which one the server chose
     */
    public static function connect(
        string $host,
        int $port,
        bool $tls = false,
        float $timeout = 30.0,
        ?AbortSignal $signal = null,
        array $alpn = [],
    ): self {
        $signal?->throwIfAborted();

        // The name looked up on the loop (`Dns`): `stream_socket_client()` would look it up itself,
        // before its asynchronous connect, with the whole process waiting on the DNS server. TLS
        // still names the host: `peer_name` is what the certificate is checked against, and
        // `SNI_server_name` what the server is told it is.
        $address = Dns::resolve($host, $timeout, $signal);
        $context = stream_context_create($tls ? ['ssl' => ['peer_name' => $host, 'SNI_server_name' => $host] + (self::$caFile !== null ? ['cafile' => self::$caFile] : [])] : []);
        $errno = 0;
        $errstr = '';
        $target = str_contains($address, ':') && !str_starts_with($address, '[') ? "[{$address}]" : $address;

        [$stream, $warning] = self::capturingWarnings(static fn () => stream_socket_client(
            "tcp://{$target}:{$port}",
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
            $socket->enableTls($host, $timeout, $signal, $alpn);
        }

        return $socket;
    }

    /** Write all of $data, suspending whenever the kernel buffer is full. */
    public function write(string $data, float $timeout = 0.0, ?AbortSignal $signal = null): void
    {
        $this->assertOpen();
        $length = strlen($data);
        $offset = 0;
        $stallStart = null;
        $stallLimit = $timeout > 0.0 ? $timeout : 30.0;
        $consecutiveZeros = 0;

        while ($offset < $length) {
            $signal?->throwIfAborted();

            if (feof($this->stream)) {
                $this->close();

                throw new SocketError('Write failed: Connection closed by peer');
            }

            // Write in TLS-record-sized chunks (RFC 8446 max record length 16KB) to prevent OpenSSL buffer rejections
            $slice = substr($data, $offset, 16384);
            [$written, $warning] = self::capturingWarnings(
                fn () => fwrite($this->stream, $slice)
            );

            if ($written === false) {
                $this->close();
                $reason = $warning !== '' ? ": {$warning}" : '';

                throw new SocketError("Write failed{$reason}");
            }

            if ($written === 0) {
                if (feof($this->stream)) {
                    $this->close();

                    throw new SocketError('Write failed: Connection closed by peer');
                }

                $now = microtime(true);
                $stallStart ??= $now;

                if (($now - $stallStart) >= $stallLimit) {
                    $this->close();
                    $reason = $warning !== '' ? ": {$warning}" : '';

                    throw new SocketError("Write failed: Connection closed or stalled after {$stallLimit}s{$reason}");
                }

                $consecutiveZeros++;
                $remaining = $stallLimit - ($now - $stallStart);
                $this->awaitReady(true, $remaining, $signal);

                // If non-blocking write repeatedly returns 0, yield slightly to let OS flush TCP buffer / receive ACK
                if ($consecutiveZeros > 3) {
                    if (\Fiber::getCurrent() !== null) {
                        Async::delay(0.01);
                    } else {
                        usleep(10000);
                    }
                }

                continue;
            }

            $consecutiveZeros = 0;
            $stallStart = null;
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
            [$data, $warning] = self::capturingWarnings(
                fn () => fread($this->stream, $length)
            );

            if ($data === false) {
                $this->close();
                $reason = $warning !== '' ? ": {$warning}" : '';

                throw new SocketError("Read failed{$reason}");
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

    /**
     * Read whatever is available without waiting: '' when nothing is, null at end of stream.
     *
     * For a connection kept open between uses, whose peer may have closed it — or said something —
     * while nothing was reading; `read()` would wait for the next byte.
     */
    public function readAvailable(int $length = 8192): ?string
    {
        $this->assertOpen();
        [$data, $warning] = self::capturingWarnings(fn () => fread($this->stream, $length));

        if ($data === false) {
            $this->close();
            $reason = $warning !== '' ? ": {$warning}" : '';

            throw new SocketError("Read failed{$reason}");
        }

        if ($data !== '') {
            return $data;
        }

        return feof($this->stream) ? null : '';
    }

    /**
     * The protocol the server picked from those offered to `connect()` or `enableTls()`, or null when
     * none was offered, the server does not do ALPN, or the connection is not TLS.
     */
    public function alpnProtocol(): ?string
    {
        if ($this->stream === null) {
            return null;
        }

        $protocol = stream_get_meta_data($this->stream)['crypto']['alpn_protocol'] ?? null;

        return is_string($protocol) && $protocol !== '' ? $protocol : null;
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
        foreach ($this->pendingSettles as $settle) {
            $settle(new SocketError('Socket closed'));
        }

        $stream = $this->stream;
        $this->stream = null;

        // Cleanly close without letting broken-pipe / SSL shutdown warnings escape to terminal.
        self::capturingWarnings(static fn () => fclose($stream));
    }

    /**
     * Negotiate TLS on an already-connected socket, verifying the certificate against $host.
     *
     * Public because a tunnelled connection has to do this in two steps: the TCP connection
     * goes to the proxy, and the certificate that matters belongs to the host on the far side
     * of it. `connect()` cannot do that itself — it only knows the address it dialled — so the
     * tunnel is built with `$tls` false and this is called afterwards with the real host.
     *
     * @param list<string> $alpn see `connect()`
     */
    public function enableTls(string $host, float $timeout = 30.0, ?AbortSignal $signal = null, array $alpn = []): void
    {
        $this->assertOpen();

        if ($alpn !== []) {
            stream_context_set_option($this->stream, 'ssl', 'alpn_protocols', implode(',', $alpn));
        }

        if (self::$caFile !== null) {
            stream_context_set_option($this->stream, 'ssl', 'cafile', self::$caFile);
        }

        // The name to verify against, set here rather than only in `connect()`'s context: a
        // tunnelled socket was dialled to the proxy and would otherwise be checked against it.
        stream_context_set_option($this->stream, 'ssl', 'peer_name', $host);
        // And the name the server is told (SNI): PHP would otherwise take it from the address that
        // was dialled, which is the proxy for a tunnel and an IP address for a looked-up host.
        stream_context_set_option($this->stream, 'ssl', 'SNI_server_name', $host);

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
        $sleeper = null;
        $abortListener = null;

        $key = spl_object_id($deferred);

        $settle = function (?Throwable $error) use (
            $key,
            $loop,
            $deferred,
            $signal,
            &$watcher,
            &$timer,
            &$sleeper,
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

            if ($sleeper !== null) {
                $loop->cancel($sleeper);
            }

            if ($abortListener !== null && $signal !== null) {
                $signal->removeListener($abortListener);
            }

            unset($this->pendingSettles[$key]);

            if ($error !== null) {
                $deferred->error($error);

                return;
            }

            $deferred->complete(null);
        };

        $this->pendingSettles[$key] = $settle;
        $ready = static fn () => $settle(null);

        $watcher = $forWriting
            ? $loop->onWritable($this->stream, $ready)
            : $loop->onReadable($this->stream, $ready);

        if ($timeout > 0.0) {
            $timer = $loop->delay($timeout, static fn () => $settle(
                new SocketError(sprintf('Socket timed out after %.1fs', $timeout)),
            ));

            // A wait with a timeout is a wait on a peer that is expected to answer — an HTTP
            // response, the model's stream. One the computer slept through is given up at once
            // (`Loop::onSleep()`): the connection rarely survives the sleep, and waiting out the
            // timeout after waking is what left a run standing still. "Timed out" in the message
            // is what makes it a retryable error to the agent, as an idle timeout is.
            $sleeper = $loop->onSleep(static fn (float $slept) => $settle(
                new SocketError(sprintf('Socket timed out: the computer slept for %ds while waiting, and the connection is not trusted after that', (int) round($slept))),
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
            $warning = $warning === '' ? $message : "{$warning} {$message}";

            return true;
        });

        try {
            return [$fn(), $warning];
        } finally {
            restore_error_handler();
        }
    }
}
