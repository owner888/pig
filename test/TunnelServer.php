<?php

declare(strict_types=1);

namespace Pig\Test;

use Pig\Async\Loop;
use RuntimeException;

/**
 * A loopback proxy that speaks HTTP CONNECT or SOCKS5 and forwards to wherever it was told.
 *
 * Written because the alternative was asserting on the bytes pig *sends* and calling that a
 * tunnel. It is not: a handshake that looks right and leaves one stray byte on the socket fails
 * at the TLS handshake, or — worse — at the first response, thousands of lines away from the
 * mistake. This forwards for real, so a test can ask the one question that matters: did the
 * request arrive at the far end intact.
 *
 * Plain TCP on both sides. TLS through the tunnel is the same handshake with `enableTls()` after
 * it, and a loopback certificate authority to make that assertable is a lot of machinery for a
 * line of code that `Socket` already has its own tests for.
 */
final class TunnelServer
{
    /** @var list<resource> */
    private array $open = [];

    /** What the client sent during the handshake, before any forwarding. */
    private string $handshake = '';

    /** How many connections were accepted, so a bypass can be shown to have skipped this. */
    private int $connections = 0;

    /** Where the client asked to go, as the proxy saw it: `host:port`. */
    private ?string $requested = null;

    private ?int $refuse = null;

    private bool $requireCredentials = false;

    private ?string $forwardTo = null;

    /**
     * @param 'http'|'socks5' $scheme
     */
    public function __construct(private readonly string $scheme = 'http')
    {
    }

    /** Answer every CONNECT with this status instead of tunnelling, for the refusal tests. */
    public function refuseWith(int $status): self
    {
        $this->refuse = $status;

        return $this;
    }

    /**
     * Send every tunnel to this address whatever the client asked for, which is what makes an
     * end-to-end test possible at all: the client has to name a host that is *not* loopback, or
     * `Proxy::bypasses()` quite rightly refuses to use a proxy to reach the machine it is on. So
     * the test asks for `provider.invalid:443` — a name nothing can resolve, which proves pig
     * never tried — and this is the proxy doing the resolving, as a proxy does.
     */
    public function forwardTo(string $authority): self
    {
        $this->forwardTo = $authority;

        return $this;
    }

    /** Demand a username and password: `Proxy-Authorization`, or SOCKS5 method 0x02. */
    public function requireCredentials(): self
    {
        $this->requireCredentials = true;

        return $this;
    }

    /** @return string the proxy URL, `http://127.0.0.1:<port>` */
    public function start(): string
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);

        if ($server === false) {
            throw new RuntimeException("Cannot open tunnel server: {$errstr}");
        }

        stream_set_blocking($server, false);
        $this->open[] = $server;

        Loop::get()->onReadable($server, function ($listening): void {
            $connection = stream_socket_accept($listening, 0);

            if ($connection === false) {
                return;
            }

            stream_set_blocking($connection, false);
            $this->open[] = $connection;
            $this->connections++;

            if ($this->scheme === 'socks5') {
                $this->socksGreeting($connection);
            } else {
                $this->awaitConnect($connection);
            }
        });

        $name = (string) stream_socket_get_name($server, false);

        return $this->scheme . '://' . $name;
    }

    public function handshake(): string
    {
        return $this->handshake;
    }

    public function connections(): int
    {
        return $this->connections;
    }

    public function requested(): ?string
    {
        return $this->requested;
    }

    public function close(): void
    {
        foreach ($this->open as $stream) {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $this->open = [];
    }

    // ---- HTTP CONNECT --------------------------------------------------------------------------

    /** @param resource $client */
    private function awaitConnect(mixed $client): void
    {
        $buffer = '';
        $watcher = null;

        $watcher = Loop::get()->onReadable($client, function ($peer) use (&$buffer, &$watcher): void {
            $data = fread($peer, 65536);

            if ($data === false || $data === '') {
                return;
            }

            $buffer .= $data;

            if (!str_contains($buffer, "\r\n\r\n")) {
                return;
            }

            Loop::get()->cancel((string) $watcher);
            $this->handshake .= $buffer;
            $head = (string) strstr($buffer, "\r\n\r\n", true);

            if ($this->refuse !== null) {
                fwrite($peer, "HTTP/1.1 {$this->refuse} Refused\r\nContent-Length: 0\r\n\r\n");
                fclose($peer);

                return;
            }

            if ($this->requireCredentials && !str_contains(strtolower($head), 'proxy-authorization:')) {
                fwrite($peer, "HTTP/1.1 407 Proxy Authentication Required\r\nContent-Length: 0\r\n\r\n");
                fclose($peer);

                return;
            }

            if (preg_match('#^CONNECT\s+(\S+)\s+HTTP/1\.1#i', $head, $match) !== 1) {
                fwrite($peer, "HTTP/1.1 400 Bad Request\r\nContent-Length: 0\r\n\r\n");
                fclose($peer);

                return;
            }

            $this->requested = $match[1];
            $upstream = $this->dial($match[1]);

            if ($upstream === null) {
                fwrite($peer, "HTTP/1.1 502 Bad Gateway\r\nContent-Length: 0\r\n\r\n");
                fclose($peer);

                return;
            }

            fwrite($peer, "HTTP/1.1 200 Connection Established\r\n\r\n");
            $this->pump($peer, $upstream);
        });
    }

    // ---- SOCKS5 --------------------------------------------------------------------------------

    /** @param resource $client */
    private function socksGreeting(mixed $client): void
    {
        $this->afterBytes($client, 2, function (mixed $peer, string $header): void {
            $count = ord($header[1]);

            $this->afterBytes($peer, $count, function (mixed $peer, string $methods): void {
                if ($this->requireCredentials) {
                    if (!str_contains($methods, "\x02")) {
                        // 0xFF: nothing on offer is acceptable.
                        fwrite($peer, "\x05\xFF");
                        fclose($peer);

                        return;
                    }

                    fwrite($peer, "\x05\x02");
                    $this->socksAuthentication($peer);

                    return;
                }

                fwrite($peer, "\x05\x00");
                $this->socksRequest($peer);
            });
        });
    }

    /** @param resource $client */
    private function socksAuthentication(mixed $client): void
    {
        $this->afterBytes($client, 2, function (mixed $peer, string $header): void {
            $this->afterBytes($peer, ord($header[1]), function (mixed $peer, string $user): void {
                $this->afterBytes($peer, 1, function (mixed $peer, string $length) use ($user): void {
                    $this->afterBytes(
                        $peer,
                        ord($length),
                        function (mixed $peer, string $password) use ($user): void {
                            $this->handshake .= "user={$user} password={$password}\n";
                            fwrite($peer, "\x01\x00");
                            $this->socksRequest($peer);
                        },
                    );
                });
            });
        });
    }

    /** @param resource $client */
    private function socksRequest(mixed $client): void
    {
        $this->afterBytes($client, 4, function (mixed $peer, string $header): void {
            $type = ord($header[3]);

            $length = match ($type) {
                0x01 => 4,
                0x04 => 16,
                default => null,
            };

            if ($length !== null) {
                $this->afterBytes($peer, $length + 2, function (mixed $peer, string $rest) use ($length): void {
                    $address = (string) inet_ntop(substr($rest, 0, $length));
                    $this->socksConnect($peer, $address, unpack('n', substr($rest, $length))[1]);
                });

                return;
            }

            if ($type !== 0x03) {
                fwrite($peer, "\x05\x08\x00\x01\x00\x00\x00\x00\x00\x00");
                fclose($peer);

                return;
            }

            $this->afterBytes($peer, 1, function (mixed $peer, string $size): void {
                $this->afterBytes(
                    $peer,
                    ord($size) + 2,
                    function (mixed $peer, string $rest) use ($size): void {
                        $name = substr($rest, 0, ord($size));
                        $this->socksConnect($peer, $name, unpack('n', substr($rest, ord($size)))[1]);
                    },
                );
            });
        });
    }

    /** @param resource $client */
    private function socksConnect(mixed $client, string $host, int $port): void
    {
        $this->requested = "{$host}:{$port}";
        $this->handshake .= "CONNECT {$host}:{$port}\n";

        if ($this->refuse !== null) {
            fwrite($client, "\x05" . chr($this->refuse) . "\x00\x01\x00\x00\x00\x00\x00\x00");
            fclose($client);

            return;
        }

        $upstream = $this->dial("{$host}:{$port}");

        if ($upstream === null) {
            fwrite($client, "\x05\x04\x00\x01\x00\x00\x00\x00\x00\x00");
            fclose($client);

            return;
        }

        // The bound address, which pig has to read past before its own bytes start.
        fwrite($client, "\x05\x00\x00\x01\x7F\x00\x00\x01" . pack('n', 1080));
        $this->pump($client, $upstream);
    }

    /**
     * Read exactly $length bytes, then call $then. Written out rather than looped because a
     * handshake is a sequence of fixed-size reads and this keeps each step next to its answer.
     *
     * @param resource                       $client
     * @param callable(resource, string):void $then
     */
    private function afterBytes(mixed $client, int $length, callable $then): void
    {
        if ($length === 0) {
            $then($client, '');

            return;
        }

        $buffer = '';
        $watcher = null;

        $watcher = Loop::get()->onReadable(
            $client,
            function ($peer) use (&$buffer, &$watcher, $length, $then): void {
                $data = fread($peer, $length - strlen($buffer));

                if ($data === false || $data === '') {
                    return;
                }

                $buffer .= $data;
                $this->handshake .= $data;

                if (strlen($buffer) < $length) {
                    return;
                }

                Loop::get()->cancel((string) $watcher);
                $then($peer, $buffer);
            },
        );
    }

    // ---- forwarding ----------------------------------------------------------------------------

    /** @return resource|null */
    private function dial(string $authority): mixed
    {
        $authority = $this->forwardTo ?? $authority;
        $separator = strrpos($authority, ':');

        if ($separator === false) {
            return null;
        }

        $host = trim(substr($authority, 0, $separator), '[]');
        $port = (int) substr($authority, $separator + 1);
        $upstream = stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, 2.0);

        if ($upstream === false) {
            return null;
        }

        stream_set_blocking($upstream, false);
        $this->open[] = $upstream;

        return $upstream;
    }

    /**
     * Copy both ways until either side is done.
     *
     * @param resource $left
     * @param resource $right
     */
    private function pump(mixed $left, mixed $right): void
    {
        foreach ([[$left, $right], [$right, $left]] as [$from, $to]) {
            $watcher = null;

            $watcher = Loop::get()->onReadable($from, static function ($source) use ($to, &$watcher): void {
                $data = fread($source, 65536);

                if ($data === false || ($data === '' && feof($source))) {
                    Loop::get()->cancel((string) $watcher);

                    if (is_resource($to)) {
                        // The far end has to see the close, or a `Connection: close` response
                        // never ends and the client waits for a body that is already complete.
                        fclose($to);
                    }

                    return;
                }

                if ($data !== '' && is_resource($to)) {
                    fwrite($to, $data);
                }
            });
        }
    }
}
