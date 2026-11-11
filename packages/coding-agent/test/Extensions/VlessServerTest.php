<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test\Extensions;

use PHPUnit\Framework\TestCase;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\Async\Socket;
use PigVless\VlessServer;
use RuntimeException;

/**
 * A phone, a VLESS inbound, and somewhere to go — all three on loopback.
 *
 * The "phone" is the request header written by hand, the far end is an echo server on a
 * port of its own, and what is asserted is what comes back through the inbound: the two-byte
 * response header, then the echo. The protocol is small enough that the header is the test.
 */
final class VlessServerTest extends TestCase
{
    private const string UUID = '7a3f1c2e-9b4d-4e6f-8a1b-2c3d4e5f6a7b';

    private VlessServer $server;

    /** @var resource */
    private $echo;

    private ?string $echoWatcher = null;

    private int $echoPort;

    /** @var list<resource> */
    private array $echoClients = [];

    #[\Override]
    protected function setUp(): void
    {
        Loop::reset();

        if (!class_exists(VlessServer::class, false)) {
            require dirname(__DIR__, 4) . '/extensions/pig-vless/VlessServer.php';
        }

        $this->echoPort = self::freePort();
        $this->echo = stream_socket_server("tcp://127.0.0.1:{$this->echoPort}") ?: throw new RuntimeException('no echo');
        stream_set_blocking($this->echo, false);
        $this->echoWatcher = Loop::get()->onReadable($this->echo, function (): void {
            set_error_handler(static fn (): bool => true);
            try {
                $client = stream_socket_accept($this->echo, 0);
            } finally {
                restore_error_handler();
            }

            if ($client === false) {
                return;
            }

            stream_set_blocking($client, false);
            $this->echoClients[] = $client;
            Loop::get()->onReadable($client, static function () use ($client): void {
                $data = fread($client, 65536);

                if ($data !== false && $data !== '') {
                    fwrite($client, strtoupper($data)); // upper-cased, so the echo is told apart from the input
                }
            });
        });

        $this->server = new VlessServer('127.0.0.1', self::freePort(), self::UUID);
        $this->server->start();
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->server->stop();

        if ($this->echoWatcher !== null) {
            Loop::get()->cancel($this->echoWatcher);
        }

        fclose($this->echo);
    }

    public function testATcpRequestIsAnsweredWithTheHeaderAndThenRelayed(): void
    {
        $reply = $this->through(self::header(self::UUID, VlessServer::CMD_TCP, '127.0.0.1', $this->echoPort) . 'hello');

        $this->assertSame("\x00\x00", substr($reply, 0, 2), 'version 0, no addons');
        $this->assertSame('HELLO', substr($reply, 2));
    }

    public function testADomainAddressAndAPayloadSentLaterBothWork(): void
    {
        $reply = $this->through(self::header(self::UUID, VlessServer::CMD_TCP, 'localhost', $this->echoPort), then: 'later');

        $this->assertSame("\x00\x00LATER", $reply);
    }

    public function testAHeaderSplitAcrossTwoWritesIsStillOneHeader(): void
    {
        $header = self::header(self::UUID, VlessServer::CMD_TCP, '127.0.0.1', $this->echoPort);
        $reply = $this->through(substr($header, 0, 10), then: substr($header, 10) . 'split');

        $this->assertSame("\x00\x00SPLIT", $reply);
    }

    public function testTheWrongUuidIsClosedWithoutAWord(): void
    {
        $reply = $this->through(self::header('00000000-0000-4000-8000-000000000000', VlessServer::CMD_TCP, '127.0.0.1', $this->echoPort) . 'hello');

        $this->assertSame('', $reply);
    }

    public function testUdpIsRefused(): void
    {
        $this->assertSame('', $this->through(self::header(self::UUID, VlessServer::CMD_UDP, '127.0.0.1', $this->echoPort) . 'x'));
    }

    public function testSomethingThatIsNotVlessIsClosed(): void
    {
        $this->assertSame('', $this->through("GET / HTTP/1.1\r\nHost: x\r\n\r\n"));
    }

    public function testTheHeaderParserKnowsAllThreeAddressTypes(): void
    {
        $v4 = VlessServer::parse(self::header(self::UUID, 1, '10.0.0.1', 443) . 'p');
        $this->assertSame(['10.0.0.1', 443, 'p'], [$v4['host'], $v4['port'], $v4['payload']]);

        $v6 = VlessServer::parse(self::header(self::UUID, 1, '2001:db8::1', 53));
        $this->assertSame('2001:db8::1', $v6['host']);

        $name = VlessServer::parse(self::header(self::UUID, 1, 'example.com', 80));
        $this->assertSame('example.com', $name['host']);

        $this->assertNull(VlessServer::parse(substr(self::header(self::UUID, 1, 'example.com', 80), 0, 20)), 'a prefix is not yet a header');
        $this->assertFalse(VlessServer::parse("\x01" . str_repeat('x', 40)), 'version 1 is not VLESS');
    }

    public function testTheShareLinkNamesTheKeyThePortAndThePlainTransport(): void
    {
        $link = $this->server->shareLink('192.168.1.2', 'home mac');

        $this->assertSame('vless://' . self::UUID . "@192.168.1.2:{$this->server->port}?encryption=none&security=none&type=tcp#home%20mac", $link);
    }

    public function testAWebSocketRequestUpgradesAndRelaysBinaryFrames(): void
    {
        $wsServer = new VlessServer('127.0.0.1', self::freePort(), self::UUID, wsPath: 'vless');
        $wsServer->start();

        try {
            $this->assertSame('/vless', $wsServer->wsPath);
            $this->assertSame(
                'vless://' . self::UUID . "@example.com:{$wsServer->port}?encryption=none&security=none&type=ws&path=%2Fvless#ws",
                $wsServer->shareLink('example.com', 'ws'),
            );

            $frame = self::maskedBinaryFrame(self::header(self::UUID, VlessServer::CMD_TCP, '127.0.0.1', $this->echoPort) . 'ws-hello');
            $reply = $this->throughWs($wsServer->port, '/vless', $frame);

            $this->assertSame("\x00\x00WS-HELLO", $reply);
        } finally {
            $wsServer->stop();
        }
    }

    public function testWebSocketEarlyDataInSecWebSocketProtocolIsRelayed(): void
    {
        $wsServer = new VlessServer('127.0.0.1', self::freePort(), self::UUID, wsPath: '/vless');
        $wsServer->start();

        try {
            $early = rtrim(strtr(base64_encode(self::header(self::UUID, VlessServer::CMD_TCP, '127.0.0.1', $this->echoPort) . 'early'), '+/', '-_'), '=');
            $reply = $this->throughWs($wsServer->port, '/vless', '', earlyProtocol: $early);

            $this->assertSame("\x00\x00EARLY", $reply);
        } finally {
            $wsServer->stop();
        }
    }

    public function testWebSocketRefusesTheWrongPath(): void
    {
        $wsServer = new VlessServer('127.0.0.1', self::freePort(), self::UUID, wsPath: '/vless');
        $wsServer->start();

        try {
            $frame = self::maskedBinaryFrame(self::header(self::UUID, VlessServer::CMD_TCP, '127.0.0.1', $this->echoPort) . 'nope');
            $this->assertSame('', $this->throughWs($wsServer->port, '/wrong', $frame));
        } finally {
            $wsServer->stop();
        }
    }

    // ---- the phone -------------------------------------------------------------------------

    /** Writes, waits a moment for the relay to settle, and returns everything read back. */
    private function through(string $first, string $then = ''): string
    {
        return Async::run(function () use ($first, $then): string {
            $socket = Socket::connect('127.0.0.1', $this->server->port, timeout: 2.0);
            $socket->write($first, 2.0);

            if ($then !== '') {
                Async::delay(0.05);
                $socket->write($then, 2.0);
            }

            $got = '';
            $deadline = microtime(true) + 1.0;

            while (microtime(true) < $deadline) {
                try {
                    $chunk = $socket->read(65536, 0.1);
                } catch (\Throwable) {
                    break; // closed under us: the answer is what came before
                }

                if ($chunk === null) {
                    break;
                }

                $got .= $chunk;

                if (strlen($got) >= 2 && !str_ends_with($got, "\x00\x00") && strlen($got) > 2) {
                    break; // header and some echo: that is the whole of what a test sends
                }
            }

            $socket->close();

            return $got;
        });
    }

    private static function header(string $uuid, int $command, string $host, int $port): string
    {
        $address = match (true) {
            filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false => "\x01" . inet_pton($host),
            filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false => "\x03" . inet_pton($host),
            default => "\x02" . chr(strlen($host)) . $host,
        };

        return "\x00" . hex2bin(str_replace('-', '', $uuid)) . "\x00" . chr($command) . pack('n', $port) . $address;
    }

    /** Build a client-to-server masked binary WebSocket frame (RFC 6455). */
    private static function maskedBinaryFrame(string $payload): string
    {
        $mask = "\x12\x34\x56\x78";
        $len = strlen($payload);
        $masked = $payload ^ substr(str_repeat($mask, intdiv($len, 4) + 1), 0, $len);

        return "\x82" . chr(0x80 | $len) . $mask . $masked;
    }

    /** Upgrade to WebSocket on `$path`, send `$frames`, and unframe the binary responses. */
    private function throughWs(int $port, string $path, string $frames, ?string $earlyProtocol = null): string
    {
        return Async::run(function () use ($port, $path, $frames, $earlyProtocol): string {
            $socket = Socket::connect('127.0.0.1', $port, timeout: 2.0);
            $request = "GET {$path} HTTP/1.1\r\n"
                . "Host: 127.0.0.1:{$port}\r\n"
                . "Upgrade: websocket\r\n"
                . "Connection: Upgrade\r\n"
                . "Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\n"
                . ($earlyProtocol !== null ? "Sec-WebSocket-Protocol: {$earlyProtocol}\r\n" : '')
                . "\r\n";

            $socket->write($request . $frames, 2.0);

            $raw = '';
            $deadline = microtime(true) + 1.0;

            while (microtime(true) < $deadline) {
                try {
                    $chunk = $socket->read(65536, 0.1);
                } catch (\Throwable) {
                    break;
                }

                if ($chunk === null) {
                    break;
                }

                $raw .= $chunk;
                $headEnd = strpos($raw, "\r\n\r\n");

                if ($headEnd !== false && strlen($raw) > $headEnd + 4 + 4) {
                    break;
                }
            }

            $socket->close();
            $headEnd = strpos($raw, "\r\n\r\n");

            if ($headEnd === false || !str_starts_with($raw, 'HTTP/1.1 101')) {
                return '';
            }

            $rest = substr($raw, $headEnd + 4);
            $unframed = '';

            while (strlen($rest) >= 2) {
                $opcode = ord($rest[0]) & 0x0F;
                $this->assertSame(0x02, $opcode, 'VLESS over WebSocket uses binary frames');
                $len = ord($rest[1]) & 0x7F;
                $unframed .= substr($rest, 2, $len);
                $rest = substr($rest, 2 + $len);
            }

            return $unframed;
        });
    }

    private static function freePort(): int
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0') ?: throw new RuntimeException('no port');
        $port = (int) substr(strrchr((string) stream_socket_get_name($probe, false), ':'), 1);
        fclose($probe);

        return $port;
    }
}
