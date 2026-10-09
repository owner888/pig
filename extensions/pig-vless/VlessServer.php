<?php

declare(strict_types=1);

namespace PigVless;

use Pig\Async\Loop;
use Pig\CodingAgent\Logger;
use Pig\CodingAgent\Web\Connection;
use Pig\CodingAgent\Web\Protocols\ProtocolInterface;
use Pig\CodingAgent\Web\TcpConnection;

/**
 * A VLESS inbound, the smallest one that a phone can use: TCP only, one UUID, TLS if a
 * certificate is configured and plain if not.
 *
 * VLESS is the thinnest of the V2Ray protocols — a header and then bytes:
 *
 *     request:  version(1)=0 | uuid(16) | addons-len(1) addons | command(1) | port(2, BE)
 *               | address-type(1) address | payload…
 *     response: version(1)=0 | addons-len(1)=0 | payload…
 *
 * Commands are 1 TCP, 2 UDP, 3 MUX; address types 1 IPv4 (4 bytes), 2 domain (len + name),
 * 3 IPv6 (16 bytes). This serves TCP and closes on the other two: UDP over a stream needs
 * per-packet framing on both ends, and MUX a whole multiplexer, and neither is "a phone
 * through the Mac". Shadowrocket falls back to direct for UDP when the proxy declines it.
 *
 * The relay is two `TcpConnection`s over a `Raw` protocol — the Web UI's buffered,
 * backpressured socket, with the framing taken out — one for the phone, one for where it
 * asked to go, each writing what the other reads. An outbound connect is asynchronous
 * (`STREAM_CLIENT_ASYNC_CONNECT`, then one writable wakeup); the name lookup inside it is
 * not, which is the one blocking call here and the price of the simplest version.
 *
 * Not a general proxy server. No REALITY, no Vision, no XHTTP, no UDP, no flow control
 * beyond the two send buffers — Xray and sing-box exist for that, and this is for the case
 * where running one of them is more than the job needs.
 */
final class VlessServer
{
    public const int CMD_TCP = 1;

    public const int CMD_UDP = 2;

    public const int CMD_MUX = 3;

    /** @var resource|null */
    private $socket = null;

    private ?string $watcher = null;

    /** @var array<int, Connection> every live socket, phone and far end alike */
    private array $connections = [];

    private int $nextId = 0;

    /** @var array<int, string> the pending TLS handshakes' watchers */
    private array $handshakes = [];

    /** @var string the uuid as its 16 bytes */
    private readonly string $uuid;

    /**
     * @param string      $uuid as written, with or without dashes
     * @param string|null $cert PEM path; with `$key` turns the listener into TLS
     */
    public function __construct(
        public readonly string $host,
        public readonly int $port,
        string $uuid,
        public readonly ?string $cert = null,
        public readonly ?string $key = null,
    ) {
        $hex = str_replace('-', '', $uuid);

        if (strlen($hex) !== 32 || !ctype_xdigit($hex)) {
            throw new \InvalidArgumentException("Not a UUID: {$uuid}");
        }

        $this->uuid = hex2bin($hex);
    }

    public function isTls(): bool
    {
        return $this->cert !== null && $this->key !== null;
    }

    /** @throws \RuntimeException when the port cannot be bound or the certificate cannot be read */
    public function start(): void
    {
        if ($this->isTls()) {
            foreach ([$this->cert, $this->key] as $file) {
                if (!is_readable((string) $file)) {
                    throw new \RuntimeException("Cannot read {$file}");
                }
            }
        }

        $address = "tcp://{$this->host}:{$this->port}";
        $errno = 0;
        $errstr = '';

        set_error_handler(static fn () => true);

        try {
            $socket = stream_socket_server($address, $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $this->context());
        } finally {
            restore_error_handler();
        }

        if ($socket === false) {
            throw new \RuntimeException("Could not bind VLESS to {$address}: {$errstr} ({$errno})");
        }

        stream_set_blocking($socket, false);
        $this->socket = $socket;
        $this->watcher = Loop::get()->onReadable($socket, function (): void {
            set_error_handler(static fn () => true);

            try {
                $client = stream_socket_accept($this->socket, 0);
            } finally {
                restore_error_handler();
            }

            if ($client !== false) {
                stream_set_blocking($client, false);
                $this->isTls() ? $this->handshake($client) : $this->serve($client);
            }
        });
    }

    public function stop(): void
    {
        if ($this->watcher !== null) {
            Loop::get()->cancel($this->watcher);
            $this->watcher = null;
        }

        foreach ($this->handshakes as $watcher) {
            Loop::get()->cancel($watcher);
        }

        $this->handshakes = [];

        foreach ($this->connections as $conn) {
            $conn->close();
        }

        $this->connections = [];

        if (is_resource($this->socket)) {
            fclose($this->socket);
        }

        $this->socket = null;
    }

    public function connections(): int
    {
        return count($this->connections);
    }

    /** @return resource */
    private function context()
    {
        if (!$this->isTls()) {
            return stream_context_create();
        }

        return stream_context_create(['ssl' => [
            'local_cert' => $this->cert,
            'local_pk' => $this->key,
            'verify_peer' => false,
            'verify_peer_name' => false,
        ]]);
    }

    /**
     * The TLS handshake, a step at a time: the listener is plain `tcp://` and the crypto is
     * enabled on the accepted socket, because `stream_socket_accept()` on a `tls://` listener
     * does the whole handshake blocking, and a phone on a slow link would hold the loop for
     * every other connection while it did.
     *
     * @param resource $client
     */
    private function handshake($client): void
    {
        $id = ++$this->nextId;
        $step = function () use ($client, $id): void {
            set_error_handler(static fn () => true);

            try {
                $done = stream_socket_enable_crypto($client, true, STREAM_CRYPTO_METHOD_TLS_SERVER);
            } finally {
                restore_error_handler();
            }

            if ($done === 0) {
                return; // needs more bytes; the watcher stays
            }

            Loop::get()->cancel($this->handshakes[$id]);
            unset($this->handshakes[$id]);

            if ($done === true) {
                $this->serve($client);
            } else {
                fclose($client);
            }
        };

        $this->handshakes[$id] = Loop::get()->onReadable($client, $step);
        $step();
    }

    /**
     * One phone connection: read until the header is whole, check the UUID, connect where it
     * says, then relay.
     *
     * @param resource $client
     */
    private function serve($client): void
    {
        $id = ++$this->nextId;
        $head = '';
        $far = null;

        $phone = new Connection(
            $client,
            onMessage: function (Connection $phone, mixed $chunk) use (&$head, &$far, $id): void {
                if ($far !== null) {
                    $far->sendRaw((string) $chunk);

                    return;
                }

                $head .= (string) $chunk;
                $request = self::parse($head);

                if ($request === null) {
                    if (strlen($head) > 1024) {
                        $phone->close(); // no VLESS header is this long; this is something else knocking
                    }

                    return;
                }

                if ($request === false || $request['uuid'] !== $this->uuid) {
                    // Wrong protocol or wrong key: the connection just goes away. Saying why
                    // would tell a scanner what is here; staying silent tells it nothing.
                    $phone->close();

                    return;
                }

                if ($request['command'] !== self::CMD_TCP) {
                    Logger::debug("[vless] refused command {$request['command']} (TCP only)");
                    $phone->close();

                    return;
                }

                $far = $this->connect($phone, $request['host'], $request['port'], $request['payload']);
                $head = '';

                if ($far === null) {
                    $phone->close();
                }
            },
            onClose: function () use (&$far, $id): void {
                unset($this->connections[$id]);
                $far?->close();
            },
            protocol: Raw::class,
        );

        $this->connections[$id] = $phone;
        $phone->setReadableWatcher(Loop::get()->onReadable($client, static fn () => $phone->onReadable()));
    }

    /**
     * The far end: connect without waiting, and start relaying once the connect lands.
     *
     * @return Connection|null null when the connect could not even be started
     */
    private function connect(Connection $phone, string $host, int $port, string $payload): ?Connection
    {
        $errno = 0;
        $errstr = '';
        $target = str_contains($host, ':') ? "tcp://[{$host}]:{$port}" : "tcp://{$host}:{$port}";

        set_error_handler(static fn () => true);

        try {
            $socket = stream_socket_client($target, $errno, $errstr, 0, STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT);
        } finally {
            restore_error_handler();
        }

        if ($socket === false) {
            Logger::debug("[vless] {$target}: {$errstr}");

            return null;
        }

        stream_set_blocking($socket, false);
        $id = ++$this->nextId;

        $far = new Connection(
            $socket,
            onMessage: static function (Connection $far, mixed $chunk) use ($phone): void {
                $phone->sendRaw((string) $chunk);
            },
            onClose: function () use ($phone, $id): void {
                unset($this->connections[$id]);
                $phone->close();
            },
            protocol: Raw::class,
        );

        $this->connections[$id] = $far;

        // Connected or refused shows as writable; `stream_socket_get_name(…, true)` says which.
        $probe = null;
        $probe = Loop::get()->onWritable($socket, function () use (&$probe, $socket, $far, $phone, $payload, $target): void {
            Loop::get()->cancel((string) $probe);

            if (stream_socket_get_name($socket, true) === false) {
                Logger::debug("[vless] {$target}: connect failed");
                $far->close();

                return;
            }

            $far->setReadableWatcher(Loop::get()->onReadable($socket, static fn () => $far->onReadable()));
            $phone->sendRaw("\x00\x00"); // the response header: version 0, no addons

            if ($payload !== '') {
                $far->sendRaw($payload);
            }
        });

        return $far;
    }

    /**
     * The request header.
     *
     * @return array{uuid: string, command: int, host: string, port: int, payload: string}|null|false
     *         null while the bytes so far are a prefix of a header, false when they cannot be one
     */
    public static function parse(string $bytes): array|null|false
    {
        $length = strlen($bytes);

        if ($length < 1) {
            return null;
        }

        if (ord($bytes[0]) !== 0) {
            return false;
        }

        if ($length < 18) {
            return null;
        }

        $uuid = substr($bytes, 1, 16);
        $addons = ord($bytes[17]);
        $at = 18 + $addons;

        if ($length < $at + 4) {
            return null;
        }

        $command = ord($bytes[$at]);
        $port = unpack('n', substr($bytes, $at + 1, 2))[1];
        $type = ord($bytes[$at + 3]);
        $at += 4;

        switch ($type) {
            case 1:
                if ($length < $at + 4) {
                    return null;
                }

                $host = inet_ntop(substr($bytes, $at, 4)) ?: '';
                $at += 4;
                break;

            case 2:
                if ($length < $at + 1) {
                    return null;
                }

                $size = ord($bytes[$at]);

                if ($length < $at + 1 + $size) {
                    return null;
                }

                $host = substr($bytes, $at + 1, $size);
                $at += 1 + $size;
                break;

            case 3:
                if ($length < $at + 16) {
                    return null;
                }

                $host = inet_ntop(substr($bytes, $at, 16)) ?: '';
                $at += 16;
                break;

            default:
                return false;
        }

        if ($host === '' || $port === 0) {
            return false;
        }

        return ['uuid' => $uuid, 'command' => $command, 'host' => $host, 'port' => $port, 'payload' => substr($bytes, $at)];
    }

    /** The link a phone scans or pastes. */
    public function shareLink(string $host, string $name = 'pig'): string
    {
        $uuid = bin2hex($this->uuid);
        $uuid = substr($uuid, 0, 8) . '-' . substr($uuid, 8, 4) . '-' . substr($uuid, 12, 4) . '-' . substr($uuid, 16, 4) . '-' . substr($uuid, 20);
        $security = $this->isTls() ? 'tls' : 'none';

        return "vless://{$uuid}@{$host}:{$this->port}?encryption=none&security={$security}&type=tcp#" . rawurlencode($name);
    }
}

/** Bytes in, bytes out: `TcpConnection` without a frame. */
final class Raw implements ProtocolInterface
{
    public static function input(string $buffer, TcpConnection $connection): int|false
    {
        return strlen($buffer);
    }

    public static function decode(string $buffer, TcpConnection $connection): mixed
    {
        return $buffer;
    }

    public static function encode(mixed $data, TcpConnection $connection): string
    {
        return (string) $data;
    }
}
