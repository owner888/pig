<?php

declare(strict_types=1);

namespace PigVless;

use Pig\Async\Async;
use Pig\Async\Dns;
use Pig\Async\Loop;
use Pig\CodingAgent\Logger;
use Pig\CodingAgent\Web\Connection;
use Pig\CodingAgent\Web\Protocols\Http;
use Pig\CodingAgent\Web\Protocols\ProtocolInterface;
use Pig\CodingAgent\Web\Protocols\Websocket;
use Pig\CodingAgent\Web\TcpConnection;

/**
 * A VLESS inbound, the smallest one that a phone can use: TCP or WebSocket, one UUID, TLS if a
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
 * The relay is two `TcpConnection`s — over `Raw` for plain TCP/TLS, or `WsBinary` (RFC 6455
 * binary frames after an HTTP upgrade on `$wsPath`, with `Sec-WebSocket-Protocol` 0-RTT early
 * data) for WebSocket — one for the phone, one for where it asked to go, each writing what the
 * other reads. Domain names are resolved off the loop through `Pig\Async\Dns`, and the outbound
 * connect is asynchronous (`STREAM_CLIENT_ASYNC_CONNECT`, then one writable wakeup).
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

    /** WebSocket upgrade path (e.g. `/vless`), or null for raw TCP. */
    public readonly ?string $wsPath;

    /**
     * @param string      $uuid   as written, with or without dashes
     * @param string|null $cert   PEM path; with `$key` turns the listener into TLS
     * @param string|null $wsPath when set, expects a WebSocket upgrade at this path (`type=ws`)
     */
    public function __construct(
        public readonly string $host,
        public readonly int $port,
        string $uuid,
        public readonly ?string $cert = null,
        public readonly ?string $key = null,
        ?string $wsPath = null,
    ) {
        $hex = str_replace('-', '', $uuid);

        if (strlen($hex) !== 32 || !ctype_xdigit($hex)) {
            throw new \InvalidArgumentException("Not a UUID: {$uuid}");
        }

        $this->uuid = hex2bin($hex);
        $trimmedPath = $wsPath !== null ? trim($wsPath) : '';
        $this->wsPath = $trimmedPath !== '' ? '/' . ltrim($trimmedPath, '/') : null;
    }

    public function isTls(): bool
    {
        return $this->cert !== null && $this->key !== null;
    }

    public function isWs(): bool
    {
        return $this->wsPath !== null;
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
        $earlyPayload = '';
        $connecting = false;
        $far = null;

        $phone = new Connection(
            $client,
            onMessage: function (Connection $phone, mixed $chunk) use (&$head, &$earlyPayload, &$connecting, &$far): void {
                if (is_array($chunk)) {
                    $early = WsBinary::handshake($chunk, $phone, $this->wsPath);
                    if ($early === false) {
                        $phone->close();

                        return;
                    }
                    if ($early === '') {
                        return;
                    }
                    $chunk = $early;
                }

                if ($far !== null) {
                    $far->sendRaw((string) $chunk);

                    return;
                }

                if ($connecting) {
                    $earlyPayload .= (string) $chunk;
                    if (strlen($earlyPayload) > TcpConnection::MAX_SEND_BUFFER) {
                        $phone->close();
                    }

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

                $head = '';
                $host = $request['host'];
                $port = $request['port'];
                $earlyPayload = $request['payload'];

                if (self::needsDns($host)) {
                    $connecting = true;
                    Async::spawn(function () use ($phone, $host, $port, &$earlyPayload, &$connecting, &$far): void {
                        try {
                            $resolved = Dns::resolve($host, 10.0);
                        } catch (\Throwable) {
                            $phone->close();

                            return;
                        }

                        if ($phone->isClosed()) {
                            return;
                        }

                        $payload = $earlyPayload;
                        $earlyPayload = '';
                        $connecting = false;
                        $far = $this->connect($phone, $resolved, $port, $payload);

                        if ($far === null) {
                            $phone->close();
                        }
                    });

                    return;
                }

                $payload = $earlyPayload;
                $earlyPayload = '';
                $far = $this->connect($phone, $host, $port, $payload);

                if ($far === null) {
                    $phone->close();
                }
            },
            onClose: function () use (&$far, $id): void {
                unset($this->connections[$id]);
                $far?->close();
            },
            protocol: $this->isWs() ? Http::class : Raw::class,
        );

        $this->connections[$id] = $phone;
        $phone->setReadableWatcher(Loop::get()->onReadable($client, static fn () => $phone->onReadable()));
    }

    private static function needsDns(string $host): bool
    {
        return strcasecmp($host, 'localhost') !== 0
            && filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) === false;
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
        $connectHost = strcasecmp($host, 'localhost') === 0 ? '127.0.0.1' : $host;
        $target = str_contains($connectHost, ':') ? "tcp://[{$connectHost}]:{$port}" : "tcp://{$connectHost}:{$port}";

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
                $phone->send((string) $chunk);
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
            $phone->send("\x00\x00"); // the response header: version 0, no addons

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
        $transport = $this->wsPath !== null
            ? 'type=ws&path=' . rawurlencode($this->wsPath)
            : 'type=tcp';

        return "vless://{$uuid}@{$host}:{$this->port}?encryption=none&security={$security}&{$transport}#" . rawurlencode($name);
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

/**
 * VLESS over WebSocket: RFC 6455 binary frames (`OPCODE_BINARY`), with Xray/v2ray 0-RTT early
 * data accepted from `Sec-WebSocket-Protocol`.
 */
final class WsBinary implements ProtocolInterface
{
    private const string GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

    /**
     * Upgrade an HTTP request on `$expectedPath` to binary WebSocket frames.
     *
     * @param array{path?: string, headers?: array<string, string>} $request
     * @return string|false early-data bytes from `Sec-WebSocket-Protocol` (empty when none), or false on refusal
     */
    public static function handshake(array $request, TcpConnection $connection, ?string $expectedPath = null): string|false
    {
        if ($expectedPath !== null && ($request['path'] ?? '') !== $expectedPath) {
            return false;
        }

        $headers = $request['headers'] ?? [];
        $upgrade = strtolower($headers['upgrade'] ?? '');
        $connectionHeader = strtolower($headers['connection'] ?? '');
        $key = $headers['sec-websocket-key'] ?? null;

        if ($upgrade !== 'websocket' || !str_contains($connectionHeader, 'upgrade') || $key === null) {
            return false;
        }

        $earlyData = '';
        $protocol = trim($headers['sec-websocket-protocol'] ?? '');
        if ($protocol !== '') {
            $decoded = base64_decode(strtr($protocol, '-_', '+/'), true);
            if ($decoded === false) {
                return false;
            }
            $earlyData = $decoded;
        }

        $accept = base64_encode(sha1($key . self::GUID, true));
        $connection->sendRaw(
            "HTTP/1.1 101 Switching Protocols\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . "Sec-WebSocket-Accept: {$accept}\r\n\r\n",
        );
        $connection->setProtocol(self::class);

        return $earlyData;
    }

    public static function input(string $buffer, TcpConnection $connection): int|false
    {
        return Websocket::input($buffer, $connection);
    }

    public static function decode(string $buffer, TcpConnection $connection): ?string
    {
        return Websocket::decode($buffer, $connection);
    }

    public static function encode(mixed $data, TcpConnection $connection): string
    {
        return Websocket::createFrame((string) $data, Websocket::OPCODE_BINARY);
    }
}
