<?php

declare(strict_types=1);

namespace Pig\Ai\Http;

use Pig\Async\AbortSignal;
use Pig\Async\Socket;
use Throwable;

/**
 * An RFC 6455 client over a non-blocking socket — what the runtime's global `WebSocket` is to
 * upstream, which uses one for Codex's WebSocket transport.
 *
 * Text messages only in, as upstream decodes every frame to text; pings are answered as they are
 * read. The proxy is `HttpClient`'s (`useProxy()`), tunnelled with CONNECT, as every other request
 * of the process is.
 *
 * Where upstream gets `error` and `close` events, this answers: `connect()` throws when the
 * handshake fails, and `receive()` returns null once the peer has closed, with `closeCode()` and
 * `closeReason()` saying how — 1006 when the connection ended without a close frame, as a
 * browser reports it.
 */
final class WebSocket
{
    private const string GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

    private const int MAX_HEAD_BYTES = 65536;

    private const int READ_SIZE = 65536;

    /** undici's words for a handshake that did not upgrade, which upstream's error carries. */
    public const string HANDSHAKE_FAILED = 'Received network error or non-101 status code.';

    /** Bytes read and not yet made into frames. */
    private string $buffer;

    /** @var list<string> messages read while nobody was receiving */
    private array $queue = [];

    /** A message arriving in more than one frame, so far. */
    private string $fragments = '';

    private bool $open = true;

    private ?int $closeCode = null;

    private ?string $closeReason = null;

    private function __construct(private readonly Socket $socket, string $rest)
    {
        $this->buffer = $rest;
    }

    /**
     * Open the connection and complete the handshake.
     *
     * @param array<string, string> $headers sent with the upgrade request, as given
     * @param float $timeout seconds for each step; 0 waits forever
     */
    public static function connect(string $url, array $headers, ?AbortSignal $signal = null, float $timeout = 0.0, ?Proxy $proxy = null): self
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));

        if ($parts === false || !isset($parts['host']) || ($scheme !== 'ws' && $scheme !== 'wss')) {
            throw new HttpError("Cannot parse WebSocket URL: \"{$url}\"");
        }

        $host = $parts['host'];
        $tls = $scheme === 'wss';
        $port = $parts['port'] ?? ($tls ? 443 : 80);
        $target = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
        $proxy ??= HttpClient::proxy();
        $socket = $proxy !== null && !$proxy->bypasses($host)
            ? $proxy->open($host, $port, $tls, $timeout, $signal)
            : Socket::connect($host, $port, $tls, $timeout, $signal);

        try {
            $key = base64_encode(random_bytes(16));
            $lines = ["GET {$target} HTTP/1.1"];
            $sent = [
                'host' => $port === ($tls ? 443 : 80) ? $host : "{$host}:{$port}",
                'upgrade' => 'websocket',
                'connection' => 'Upgrade',
                'sec-websocket-key' => $key,
                'sec-websocket-version' => '13',
            ];

            foreach ($headers as $name => $value) {
                $sent[strtolower($name)] ??= $value;
            }

            foreach ($sent as $name => $value) {
                $lines[] = "{$name}: {$value}";
            }

            $socket->write(implode("\r\n", $lines) . "\r\n\r\n", $timeout, $signal);
            [$status, $accept, $rest] = self::readHandshake($socket, $timeout, $signal);
        } catch (Throwable $error) {
            $socket->close();

            throw $error;
        }

        if ($status !== 101 || $accept !== base64_encode(sha1($key . self::GUID, true))) {
            $socket->close();

            throw new HttpError(self::HANDSHAKE_FAILED);
        }

        return new self($socket, $rest);
    }

    /** Send one text message. */
    public function send(string $text, ?AbortSignal $signal = null): void
    {
        if (!$this->open) {
            throw new HttpError('WebSocket is not open');
        }

        $this->socket->write(self::frame(0x1, $text), 0.0, $signal);
    }

    /**
     * The next text message, waiting for it; null once the connection has closed.
     *
     * @param float $timeout seconds to wait for each read; 0 waits forever
     */
    public function receive(?AbortSignal $signal = null, float $timeout = 0.0): ?string
    {
        while (true) {
            $this->parse();

            if ($this->queue !== []) {
                return array_shift($this->queue);
            }

            if (!$this->open) {
                return null;
            }

            $chunk = $this->socket->read(self::READ_SIZE, $timeout, $signal);

            if ($chunk === null) {
                $this->closed(1006, '');

                continue;
            }

            $this->buffer .= $chunk;
        }
    }

    /**
     * Whether the connection is still open, having read whatever arrived while nothing was
     * receiving — a close frame, a ping, the end of the stream. Upstream's `readyState === 1`.
     */
    public function isOpen(): bool
    {
        while ($this->open) {
            try {
                $chunk = $this->socket->readAvailable(self::READ_SIZE);
            } catch (Throwable) {
                $this->closed(1006, '');

                break;
            }

            if ($chunk === null) {
                $this->closed(1006, '');

                break;
            }

            if ($chunk === '') {
                break;
            }

            $this->buffer .= $chunk;
            $this->parse();
        }

        return $this->open;
    }

    /** Say goodbye and let go of the socket; quietly, whatever state it is in. */
    public function close(int $code = 1000, string $reason = 'done'): void
    {
        if ($this->open && !$this->socket->isClosed()) {
            try {
                $this->socket->write(self::frame(0x8, pack('n', $code) . $reason), 1.0);
            } catch (Throwable) {
                // Closing a connection that is already going is not a failure of anything.
            }
        }

        $this->open = false;
        $this->closeCode ??= $code;
        $this->closeReason ??= $reason;
        $this->socket->close();
    }

    public function closeCode(): ?int
    {
        return $this->closeCode;
    }

    public function closeReason(): ?string
    {
        return $this->closeReason;
    }

    /**
     * Every whole frame in the buffer, into messages, answers and the close. What already arrived
     * is read even when the connection has gone — a pong that could not be sent does not lose the
     * message after the ping.
     */
    private function parse(): void
    {
        while (true) {
            $frame = self::takeFrame($this->buffer);

            if ($frame === null) {
                return;
            }

            [$fin, $opcode, $payload] = $frame;

            switch ($opcode) {
                case 0x0:
                case 0x1:
                case 0x2:
                    $this->fragments .= $payload;

                    if ($fin) {
                        $this->queue[] = $this->fragments;
                        $this->fragments = '';
                    }

                    break;
                case 0x8:
                    $code = strlen($payload) >= 2 ? (int) unpack('n', substr($payload, 0, 2))[1] : 1005;
                    $this->closed($code, (string) substr($payload, 2));

                    return;
                case 0x9:
                    if ($this->open) {
                        try {
                            $this->socket->write(self::frame(0xA, $payload), 1.0);
                        } catch (Throwable) {
                            $this->closed(1006, '');
                        }
                    }

                    break;
                default:
                    // A pong, or an opcode nothing defines yet: nothing to do.
                    break;
            }
        }
    }

    private function closed(int $code, string $reason): void
    {
        $this->open = false;
        $this->closeCode = $code;
        $this->closeReason = $reason;
        $this->socket->close();
    }

    /**
     * One frame off the front of $buffer, or null when it has not all arrived.
     *
     * @return array{0: bool, 1: int, 2: string}|null fin, opcode, payload
     */
    private static function takeFrame(string &$buffer): ?array
    {
        if (strlen($buffer) < 2) {
            return null;
        }

        $first = ord($buffer[0]);
        $second = ord($buffer[1]);
        $masked = ($second & 0x80) !== 0;
        $length = $second & 0x7F;
        $offset = 2;

        if ($length === 126) {
            if (strlen($buffer) < 4) {
                return null;
            }

            $length = (int) unpack('n', substr($buffer, 2, 2))[1];
            $offset = 4;
        } elseif ($length === 127) {
            if (strlen($buffer) < 10) {
                return null;
            }

            $length = (int) unpack('J', substr($buffer, 2, 8))[1];
            $offset = 10;
        }

        $mask = '';

        if ($masked) {
            if (strlen($buffer) < $offset + 4) {
                return null;
            }

            $mask = substr($buffer, $offset, 4);
            $offset += 4;
        }

        if (strlen($buffer) < $offset + $length) {
            return null;
        }

        $payload = substr($buffer, $offset, $length);
        $buffer = substr($buffer, $offset + $length);

        if ($masked) {
            $payload = self::mask($payload, $mask);
        }

        return [($first & 0x80) !== 0, $first & 0x0F, $payload];
    }

    /** A client frame: final, masked, as RFC 6455 §5.3 requires of every one a client sends. */
    private static function frame(int $opcode, string $payload): string
    {
        $length = strlen($payload);
        $head = chr(0x80 | $opcode);

        if ($length < 126) {
            $head .= chr(0x80 | $length);
        } elseif ($length < 65536) {
            $head .= chr(0x80 | 126) . pack('n', $length);
        } else {
            $head .= chr(0x80 | 127) . pack('J', $length);
        }

        $mask = random_bytes(4);

        return $head . $mask . self::mask($payload, $mask);
    }

    private static function mask(string $payload, string $mask): string
    {
        $length = strlen($payload);

        return $length === 0 ? '' : $payload ^ substr(str_repeat($mask, intdiv($length, 4) + 1), 0, $length);
    }

    /** @return array{0: int, 1: string|null, 2: string} status, `sec-websocket-accept`, the bytes after the head */
    private static function readHandshake(Socket $socket, float $timeout, ?AbortSignal $signal): array
    {
        $buffer = '';

        while (($end = strpos($buffer, "\r\n\r\n")) === false) {
            if (strlen($buffer) > self::MAX_HEAD_BYTES) {
                throw new HttpError(self::HANDSHAKE_FAILED);
            }

            $chunk = $socket->read(8192, $timeout, $signal);

            if ($chunk === null) {
                throw new HttpError(self::HANDSHAKE_FAILED);
            }

            $buffer .= $chunk;
        }

        $lines = explode("\r\n", substr($buffer, 0, $end));

        if (preg_match('#^HTTP/\d\.\d (\d{3})#', array_shift($lines) ?? '', $matches) !== 1) {
            throw new HttpError(self::HANDSHAKE_FAILED);
        }

        $accept = null;

        foreach ($lines as $line) {
            [$name, $value] = array_map('trim', explode(':', $line, 2)) + [1 => ''];

            if (strtolower($name) === 'sec-websocket-accept') {
                $accept = $value;
            }
        }

        return [(int) $matches[1], $accept, substr($buffer, $end + 4)];
    }
}
