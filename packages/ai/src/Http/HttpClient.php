<?php

declare(strict_types=1);

namespace Pig\Ai\Http;

use Pig\Async\AbortSignal;
use Pig\Async\Socket;
use Throwable;

/**
 * HTTP/1.1 over a non-blocking socket — what `fetch()` is to upstream.
 *
 * Returns as soon as the head has arrived, leaving the body to be read from the
 * socket as it comes. One request per connection: `Connection: close` costs a TLS
 * handshake each time (~25ms) and buys the absence of a connection pool, which is
 * the right trade until something is measured to need otherwise.
 */
final class HttpClient
{
    private const int MAX_HEAD_BYTES = 65536;

    private const int READ_SIZE = 8192;

    /** Enough for any sane chain; more than this is a loop somebody wrote by accident. */
    private const int MAX_REDIRECTS = 5;

    public function __construct(private readonly float $timeout = 60.0)
    {
    }

    /**
     * Send the request, following redirects, and return once status and headers are in.
     *
     * Separate from `send()` because a streaming API never redirects and paying for the
     * possibility on every token would be silly — while a file download almost always
     * does, since GitHub answers a release URL with a 302 to its object store.
     *
     * A redirected POST becomes a GET on 301, 302 and 303, which is what every browser
     * does and what every server expects; 307 and 308 keep the method, which is what
     * they exist for.
     */
    public function follow(Request $request, ?AbortSignal $signal = null): Response
    {
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $response = $this->send($request, $signal);
            $location = $response->header('location');

            if ($location === null || $response->status < 300 || $response->status >= 400) {
                return $response;
            }

            // The body of a redirect is not wanted, and the socket has to be let go of
            // before the next one is opened.
            $response->body->close();

            $keepMethod = $response->status === 307 || $response->status === 308;

            $request = new Request(
                $keepMethod ? $request->method : 'GET',
                self::absolute($location, $request->url),
                $request->headers,
                $keepMethod ? $request->body : null,
            );
        }

        throw new HttpError('Too many redirects, starting from "' . $request->url . '"');
    }

    /** A Location header, which may be relative, against the URL it came from. */
    private static function absolute(string $location, string $from): string
    {
        if (preg_match('#^https?://#i', $location) === 1) {
            return $location;
        }

        $parts = parse_url($from);

        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new HttpError("Cannot resolve redirect to \"{$location}\"");
        }

        $base = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');

        if (str_starts_with($location, '/')) {
            return $base . $location;
        }

        $directory = rtrim(dirname($parts['path'] ?? '/'), '/');

        return $base . $directory . '/' . $location;
    }

    /** Send the request and return once status and headers are in. */
    public function send(Request $request, ?AbortSignal $signal = null): Response
    {
        [$host, $port, $tls, $target] = $this->resolve($request->url);
        $socket = Socket::connect($host, $port, $tls, $this->timeout, $signal);

        try {
            $socket->write($this->serialize($request, $host, $port, $tls, $target), $this->timeout, $signal);
            [$head, $rest] = $this->readHead($socket, $signal);
        } catch (Throwable $error) {
            $socket->close();

            throw $error;
        }

        [$status, $reason, $headers] = $this->parseHead($head);

        // Chunked wins over Content-Length when a peer sends both (RFC 9112 §6.3).
        $chunked = strtolower($headers['transfer-encoding'] ?? '') === 'chunked';
        $length = isset($headers['content-length']) ? (int) $headers['content-length'] : null;

        return new Response(
            $status,
            $reason,
            $headers,
            new ResponseBody(
                $socket,
                $rest,
                $chunked ? new ChunkedDecoder() : null,
                $chunked ? null : $length,
                $this->timeout,
                $signal,
            ),
        );
    }

    /** @return array{0: string, 1: int, 2: bool, 3: string} host, port, tls, request target */
    private function resolve(string $url): array
    {
        $parts = parse_url($url);

        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new HttpError("Cannot parse URL: \"{$url}\"");
        }

        $scheme = strtolower($parts['scheme']);

        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new HttpError("Unsupported scheme \"{$scheme}\" in \"{$url}\"");
        }

        $tls = $scheme === 'https';
        $target = ($parts['path'] ?? '') . (isset($parts['query']) ? '?' . $parts['query'] : '');

        return [$parts['host'], $parts['port'] ?? ($tls ? 443 : 80), $tls, $target === '' ? '/' : $target];
    }

    private function serialize(Request $request, string $host, int $port, bool $tls, string $target): string
    {
        $headers = [];
        foreach ($request->headers as $name => $value) {
            $headers[strtolower($name)] = $value;
        }

        $headers['host'] ??= $port === ($tls ? 443 : 80) ? $host : "{$host}:{$port}";
        $headers['connection'] = 'close';
        // Nothing here decompresses, so do not leave the choice to the server.
        $headers['accept-encoding'] ??= 'identity';

        if ($request->body !== null) {
            $headers['content-length'] = (string) strlen($request->body);
        }

        $lines = ["{$request->method} {$target} HTTP/1.1"];

        foreach ($headers as $name => $value) {
            $lines[] = "{$name}: {$value}";
        }

        return implode("\r\n", $lines) . "\r\n\r\n" . ($request->body ?? '');
    }

    /** @return array{0: string, 1: string} the head, and whatever body bytes came with it */
    private function readHead(Socket $socket, ?AbortSignal $signal): array
    {
        $buffer = '';

        while (true) {
            $end = strpos($buffer, "\r\n\r\n");

            if ($end !== false) {
                return [substr($buffer, 0, $end), substr($buffer, $end + 4)];
            }

            if (strlen($buffer) > self::MAX_HEAD_BYTES) {
                throw new HttpError('Response head exceeds ' . self::MAX_HEAD_BYTES . ' bytes');
            }

            $chunk = $socket->read(self::READ_SIZE, $this->timeout, $signal);

            if ($chunk === null) {
                throw new HttpError('Connection closed before the response head was complete');
            }

            $buffer .= $chunk;
        }
    }

    /** @return array{0: int, 1: string, 2: array<string, string>} */
    private function parseHead(string $head): array
    {
        $lines = explode("\r\n", $head);
        $statusLine = array_shift($lines) ?? '';

        if (preg_match('#^HTTP/\d\.\d (\d{3})(?: (.*))?$#', $statusLine, $matches) !== 1) {
            throw new HttpError("Malformed status line: \"{$statusLine}\"");
        }

        $headers = [];

        foreach ($lines as $line) {
            $colon = strpos($line, ':');

            if ($colon === false) {
                throw new HttpError("Malformed header line: \"{$line}\"");
            }

            $name = strtolower(trim(substr($line, 0, $colon)));
            $value = trim(substr($line, $colon + 1));

            // Repeated headers join with ", ", as RFC 9110 §5.3 allows.
            $headers[$name] = isset($headers[$name]) ? "{$headers[$name]}, {$value}" : $value;
        }

        return [(int) $matches[1], $matches[2] ?? '', $headers];
    }
}
