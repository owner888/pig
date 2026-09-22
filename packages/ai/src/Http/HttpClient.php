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

    public function __construct(private readonly float $timeout = 60.0)
    {
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
