<?php

declare(strict_types=1);

namespace Pig\Ai\Http;

use Pig\Async\AbortSignal;
use Pig\Async\Socket;
use Pig\Async\SocketError;
use Throwable;

/**
 * A forward proxy to reach the model through: HTTP CONNECT or SOCKS5.
 *
 * Upstream needs nothing here. Node's `fetch()` is `undici`, which reads `HTTPS_PROXY` through
 * an agent somebody else wrote; PHP's streams connect to the address they are given and nothing
 * else, so the tunnel is ours to build. It is not optional in every network: where
 * `api.anthropic.com` is unreachable directly, a local Clash or v2ray on `127.0.0.1:7890` is the
 * only way pig talks to a provider at all.
 *
 * Not `agent/proxy.ts`, which is a different thing wearing the same word: that one posts the
 * whole conversation to a gateway server that holds the provider keys. This one carries pig's
 * own bytes to the provider unchanged, and the provider still sees pig's own key.
 *
 * ```php
 * HttpClient::useProxy(Proxy::parse('socks5://127.0.0.1:7891'));
 * HttpClient::useProxy(Proxy::fromEnvironment(getenv()));
 * ```
 *
 * An `http://` target is tunnelled with CONNECT too, rather than sent in absolute form the way a
 * browser sends a plain-HTTP request to a proxy. One code path, and the only plain-HTTP endpoints
 * pig has are local ones the loopback rule already sends direct; a proxy that allows CONNECT to
 * 443 alone says so in the refusal, which is diagnosable.
 *
 * `https://` as the proxy's own scheme is refused rather than half-worked: TLS to the proxy with
 * TLS to the provider inside it is two crypto layers on one stream, and
 * `stream_socket_enable_crypto()` does one. Naming it is the whole fix — every proxy that speaks
 * `https://` also speaks `http://` on another port.
 */
final class Proxy
{
    /** curl's default when a proxy URL carries no port, for every proxy type. */
    private const int DEFAULT_PORT = 1080;

    private const int MAX_HEAD_BYTES = 8192;

    /** RFC 1928 §6, by reply code, so a refusal says which one it was. */
    private const array SOCKS_FAILURES = [
        1 => 'general SOCKS server failure',
        2 => 'connection not allowed by ruleset',
        3 => 'network unreachable',
        4 => 'host unreachable',
        5 => 'connection refused',
        6 => 'TTL expired',
        7 => 'command not supported',
        8 => 'address type not supported',
    ];

    /**
     * @param 'http'|'socks5' $scheme
     * @param list<string>    $bypass hosts to reach directly, from `no_proxy`
     */
    private function __construct(
        public readonly string $scheme,
        public readonly string $host,
        public readonly int $port,
        public readonly ?string $user,
        public readonly ?string $password,
        public readonly array $bypass,
    ) {
    }

    /**
     * Read a proxy URL.
     *
     * `socks5h://` and `socks://` are both `socks5`, and a URL with no scheme at all is an HTTP
     * proxy — which is what curl assumes and what people write.
     *
     * @param list<string> $bypass
     */
    public static function parse(string $url, array $bypass = []): self
    {
        $trimmed = trim($url);

        if ($trimmed === '') {
            throw new HttpError('Proxy URL is empty');
        }

        $withScheme = str_contains($trimmed, '://') ? $trimmed : 'http://' . $trimmed;
        $parts = parse_url($withScheme);

        if ($parts === false || !isset($parts['host']) || $parts['host'] === '') {
            throw new HttpError("Cannot read proxy URL \"{$url}\"");
        }

        $scheme = strtolower($parts['scheme'] ?? 'http');

        $normalised = match ($scheme) {
            'http' => 'http',
            'socks5', 'socks5h', 'socks' => 'socks5',
            'https' => throw new HttpError(
                'An https:// proxy cannot be used: TLS to the proxy and TLS to the provider inside '
                . 'it are two layers on one socket, and PHP negotiates one. Use the same proxy\'s '
                . 'http:// or socks5:// port.',
            ),
            'socks4', 'socks4a' => throw new HttpError(
                "SOCKS4 is not supported (\"{$url}\"); it cannot carry a hostname or a password. "
                . 'Every SOCKS4 proxy in use today also speaks SOCKS5.',
            ),
            default => throw new HttpError("Unknown proxy scheme \"{$scheme}\" in \"{$url}\""),
        };

        // A userinfo in the URL is percent-encoded, and a proxy password with a `@` or a `:` in
        // it is the reason that encoding exists — decoding it is not optional.
        $user = isset($parts['user']) ? urldecode($parts['user']) : null;
        $password = isset($parts['pass']) ? urldecode($parts['pass']) : null;

        return new self(
            $normalised,
            trim($parts['host'], '[]'),
            $parts['port'] ?? self::DEFAULT_PORT,
            $user,
            $password,
            $bypass,
        );
    }

    /**
     * The proxy the environment asks for, or null for none.
     *
     * Read in order: `https_proxy`, `HTTPS_PROXY`, `all_proxy`, `ALL_PROXY` — lowercase first,
     * which is curl's precedence. A variable that is present and empty means *no proxy*, which is
     * how a script turns one off for one command, so it ends the search rather than being skipped.
     *
     * `http_proxy` is deliberately not read. It governs `http://` targets, and every provider pig
     * talks to is `https://`; a plain-HTTP endpoint in `models.json` is a local or self-hosted
     * server, which is what `no_proxy` and the loopback rule below are for. Reading it would put
     * a tunnel in front of `http://localhost:8080/v1` and nothing else.
     *
     * @param array<string, string|false> $environment `getenv()`, or a fixture
     */
    public static function fromEnvironment(array $environment): ?self
    {
        $bypass = [];

        foreach (['no_proxy', 'NO_PROXY'] as $name) {
            $value = $environment[$name] ?? false;

            if (is_string($value) && trim($value) !== '') {
                $bypass = array_values(array_filter(array_map(
                    static fn (string $entry): string => strtolower(trim($entry)),
                    explode(',', $value),
                ), static fn (string $entry): bool => $entry !== ''));

                break;
            }
        }

        foreach (['https_proxy', 'HTTPS_PROXY', 'all_proxy', 'ALL_PROXY'] as $name) {
            $value = $environment[$name] ?? false;

            if (!is_string($value)) {
                continue;
            }

            if (trim($value) === '') {
                return null;
            }

            return self::parse($value, $bypass);
        }

        return null;
    }

    /**
     * The same proxy with a different bypass list, for a settings file that carries one.
     *
     * @param list<string> $bypass
     */
    public function bypassing(array $bypass): self
    {
        return new self($this->scheme, $this->host, $this->port, $this->user, $this->password, $bypass);
    }

    /**
     * Whether $host is to be reached directly.
     *
     * The loopback addresses are always direct, and that is not a convenience: pig's own OAuth
     * callback listens on `127.0.0.1`, and a tunnel to a proxy to reach pig itself cannot work.
     * An entry matches the host exactly or as a domain suffix, so `example.com` covers
     * `api.example.com`; a port on the entry is ignored, since one proxy decision is made per
     * host here.
     */
    public function bypasses(string $host): bool
    {
        $lower = strtolower(trim($host, '[]'));

        if ($lower === 'localhost' || $lower === '::1' || str_starts_with($lower, '127.')) {
            return true;
        }

        foreach ($this->bypass as $entry) {
            if ($entry === '*') {
                return true;
            }

            $name = ltrim(strtolower(explode(':', $entry)[0]), '.');

            if ($name === '' || $name === $lower || str_ends_with($lower, '.' . $name)) {
                return true;
            }
        }

        return false;
    }

    /** `socks5://user@127.0.0.1:7891`, for a log line — never the password. */
    public function describe(): string
    {
        $credentials = $this->user !== null ? $this->user . '@' : '';
        $host = str_contains($this->host, ':') ? '[' . $this->host . ']' : $this->host;

        return "{$this->scheme}://{$credentials}{$host}:{$this->port}";
    }

    /**
     * Open a socket to $host:$port through this proxy, with TLS negotiated on the far side.
     *
     * The order is the whole point: the tunnel is built over a plain connection and TLS starts
     * only once the proxy has said the far end is open, so the certificate checked is the
     * provider's and the proxy never sees the key or the conversation.
     */
    public function open(
        string $host,
        int $port,
        bool $tls,
        float $timeout = 30.0,
        ?AbortSignal $signal = null,
    ): Socket {
        try {
            $socket = Socket::connect($this->host, $this->port, false, $timeout, $signal);
        } catch (SocketError $error) {
            // Named as the proxy, because the raw message is `Cannot connect to 127.0.0.1:7890`
            // and the one thing somebody whose Clash is not running needs to be told is that the
            // address pig could not reach is the proxy's and not the provider's.
            throw new HttpError(
                "Cannot reach the proxy {$this->describe()}: " . $error->getMessage()
                . " (asked for {$host}:{$port})",
                previous: $error,
            );
        }

        try {
            if ($this->scheme === 'socks5') {
                $this->socks($socket, $host, $port, $timeout, $signal);
            } else {
                $this->tunnel($socket, $host, $port, $timeout, $signal);
            }

            if ($tls) {
                $socket->enableTls($host, $timeout, $signal);
            }
        } catch (Throwable $error) {
            $socket->close();

            throw $error;
        }

        return $socket;
    }

    /** RFC 9110 §9.3.6: CONNECT, then a 2xx, and everything after it belongs to the far end. */
    private function tunnel(
        Socket $socket,
        string $host,
        int $port,
        float $timeout,
        ?AbortSignal $signal,
    ): void {
        $authority = (str_contains($host, ':') ? '[' . $host . ']' : $host) . ':' . $port;
        $request = "CONNECT {$authority} HTTP/1.1\r\nHost: {$authority}\r\n";

        if ($this->user !== null) {
            $request .= 'Proxy-Authorization: Basic '
                . base64_encode($this->user . ':' . ($this->password ?? '')) . "\r\n";
        }

        $socket->write($request . "\r\n", $timeout, $signal);

        $head = '';

        while (!str_contains($head, "\r\n\r\n")) {
            $chunk = $socket->read(1024, $timeout, $signal);

            if ($chunk === null) {
                throw new HttpError(
                    "Proxy {$this->describe()} closed the connection before answering CONNECT "
                    . "{$authority}",
                );
            }

            $head .= $chunk;

            if (strlen($head) > self::MAX_HEAD_BYTES) {
                throw new HttpError("Proxy {$this->describe()} sent no usable answer to CONNECT");
            }
        }

        [$response, $leftover] = explode("\r\n\r\n", $head, 2);
        $status = (int) (explode(' ', $response)[1] ?? '0');

        if ($status < 200 || $status >= 300) {
            $line = strstr($response, "\r\n", true);

            throw new HttpError(sprintf(
                'Proxy %s refused CONNECT %s: %s',
                $this->describe(),
                $authority,
                $line === false ? $response : $line,
            ));
        }

        // Nothing was sent through the tunnel yet, so nothing can have come back. Bytes here are
        // a proxy that answered something other than what was asked, and quietly handing them to
        // the TLS handshake would surface as an unexplainable handshake failure.
        if ($leftover !== '') {
            throw new HttpError(
                "Proxy {$this->describe()} sent " . strlen($leftover)
                . ' unexpected bytes after accepting CONNECT',
            );
        }
    }

    /** RFC 1928, plus RFC 1929 when there is a username. */
    private function socks(
        Socket $socket,
        string $host,
        int $port,
        float $timeout,
        ?AbortSignal $signal,
    ): void {
        $methods = $this->user !== null ? "\x00\x02" : "\x00";
        $socket->write("\x05" . chr(strlen($methods)) . $methods, $timeout, $signal);

        $greeting = $this->exactly($socket, 2, $timeout, $signal, 'the method greeting');

        if ($greeting[0] !== "\x05") {
            throw new HttpError(sprintf(
                'Proxy %s answered the SOCKS5 greeting with version %d',
                $this->describe(),
                ord($greeting[0]),
            ));
        }

        $method = ord($greeting[1]);

        if ($method === 0x02) {
            $this->authenticate($socket, $timeout, $signal);
        } elseif ($method !== 0x00) {
            throw new HttpError(sprintf(
                'Proxy %s wants SOCKS5 authentication method 0x%02X, which pig does not have%s',
                $this->describe(),
                $method,
                $method === 0xFF && $this->user === null ? ' — it may want a username and password' : '',
            ));
        }

        $socket->write(
            "\x05\x01\x00" . $this->address($host) . pack('n', $port),
            $timeout,
            $signal,
        );

        $reply = $this->exactly($socket, 4, $timeout, $signal, 'the connect reply');
        $code = ord($reply[1]);

        if ($code !== 0x00) {
            throw new HttpError(sprintf(
                'Proxy %s refused a connection to %s:%d: %s',
                $this->describe(),
                $host,
                $port,
                self::SOCKS_FAILURES[$code] ?? sprintf('reply code 0x%02X', $code),
            ));
        }

        // The bound address has to be read even though nothing wants it: whatever is left of it
        // on the socket is the first thing the TLS handshake would read.
        $bound = match (ord($reply[3])) {
            0x01 => 4,
            0x03 => ord($this->exactly($socket, 1, $timeout, $signal, 'the bound address length')),
            0x04 => 16,
            default => throw new HttpError(sprintf(
                'Proxy %s named the bound address with unknown type 0x%02X',
                $this->describe(),
                ord($reply[3]),
            )),
        };

        $this->exactly($socket, $bound + 2, $timeout, $signal, 'the bound address');
    }

    private function authenticate(Socket $socket, float $timeout, ?AbortSignal $signal): void
    {
        $user = (string) $this->user;
        $password = (string) $this->password;

        if (strlen($user) > 255 || strlen($password) > 255) {
            throw new HttpError('A SOCKS5 username and password are at most 255 bytes each');
        }

        $socket->write(
            "\x01" . chr(strlen($user)) . $user . chr(strlen($password)) . $password,
            $timeout,
            $signal,
        );

        $answer = $this->exactly($socket, 2, $timeout, $signal, 'the authentication reply');

        if ($answer[1] !== "\x00") {
            throw new HttpError(
                "Proxy {$this->describe()} rejected the username and password",
            );
        }
    }

    /**
     * ATYP and the address, from RFC 1928 §4.
     *
     * A hostname is sent as a hostname, so the *proxy* resolves it. That is `socks5h` behaviour
     * for both spellings on purpose: in the network that makes a proxy necessary, the local
     * resolver is the other thing that does not work, and a name resolved here would be resolved
     * to the wrong address before the proxy ever saw it.
     */
    private function address(string $host): string
    {
        $bare = trim($host, '[]');

        if (filter_var($bare, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return "\x01" . (string) inet_pton($bare);
        }

        if (filter_var($bare, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return "\x04" . (string) inet_pton($bare);
        }

        if (strlen($bare) > 255) {
            throw new HttpError("Host name is too long for SOCKS5 (255 bytes): \"{$bare}\"");
        }

        return "\x03" . chr(strlen($bare)) . $bare;
    }

    /** Read exactly $length bytes; a handshake cannot work with whatever happened to arrive. */
    private function exactly(
        Socket $socket,
        int $length,
        float $timeout,
        ?AbortSignal $signal,
        string $what,
    ): string {
        $buffer = '';

        while (strlen($buffer) < $length) {
            $chunk = $socket->read($length - strlen($buffer), $timeout, $signal);

            if ($chunk === null) {
                throw new HttpError(sprintf(
                    'Proxy %s closed the connection during %s (%d of %d bytes)',
                    $this->describe(),
                    $what,
                    strlen($buffer),
                    $length,
                ));
            }

            $buffer .= $chunk;
        }

        return $buffer;
    }
}
