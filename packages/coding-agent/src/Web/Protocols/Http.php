<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Web\Protocols;

use Pig\CodingAgent\Web\TcpConnection;

/**
 * Pure PHP HTTP/1.1 protocol framing, parser and serializer.
 */
final class Http implements ProtocolInterface
{
    private const MAX_HEADER_SIZE = 16384;

    public static function input(string $buffer, TcpConnection $connection): int|false
    {
        $headEnd = strpos($buffer, "\r\n\r\n");

        if ($headEnd === false) {
            if (strlen($buffer) > self::MAX_HEADER_SIZE) {
                return false; // Malicious or malformed header too large
            }

            return 0; // Incomplete header, wait for more data
        }

        $rawHead = substr($buffer, 0, $headEnd);
        $contentLength = 0;

        if (preg_match('/content-length:\s*(\d+)/i', $rawHead, $m) === 1) {
            $contentLength = (int) $m[1];
        }

        $totalLength = $headEnd + 4 + $contentLength;

        if (strlen($buffer) < $totalLength) {
            return 0; // Incomplete body, wait for more data
        }

        return $totalLength;
    }

    /**
     * @return array{method: string, uri: string, path: string, query: array<string, string>, headers: array<string, string>, body: string}|null
     */
    public static function decode(string $buffer, TcpConnection $connection): ?array
    {
        $headEnd = strpos($buffer, "\r\n\r\n");
        if ($headEnd === false) {
            return null;
        }

        $rawHead = substr($buffer, 0, $headEnd);
        $body = substr($buffer, $headEnd + 4);
        $lines = explode("\r\n", $rawHead);

        if ($lines === [] || $lines[0] === '') {
            return null;
        }

        $requestLine = array_shift($lines);
        $parts = explode(' ', $requestLine, 3);

        if (count($parts) < 2) {
            return null;
        }

        $method = strtoupper($parts[0]);
        $uri = $parts[1];

        $parsedUri = parse_url($uri);
        $path = $parsedUri['path'] ?? '/';
        $query = [];

        if (isset($parsedUri['query'])) {
            parse_str($parsedUri['query'], $query);
        }

        $headers = [];
        foreach ($lines as $line) {
            $colon = strpos($line, ':');
            if ($colon !== false) {
                $k = strtolower(trim(substr($line, 0, $colon)));
                $v = trim(substr($line, $colon + 1));
                $headers[$k] = $v;
            }
        }

        return [
            'method' => $method,
            'uri' => $uri,
            'path' => $path,
            'query' => $query,
            'headers' => $headers,
            'body' => $body,
        ];
    }

    /**
     * @param array{status?: int, headers?: array<string, string>, body?: string}|string $data
     */
    public static function encode(mixed $data, TcpConnection $connection): string
    {
        if (is_string($data)) {
            return $data;
        }

        if (!is_array($data)) {
            return '';
        }

        $status = $data['status'] ?? 200;
        $headers = $data['headers'] ?? [];
        $body = $data['body'] ?? '';

        $headers['Content-Length'] = (string) strlen($body);
        $headers['Connection'] ??= 'keep-alive';

        $statusText = match ($status) {
            200 => 'OK',
            101 => 'Switching Protocols',
            204 => 'No Content',
            400 => 'Bad Request',
            404 => 'Not Found',
            500 => 'Internal Server Error',
            default => 'Response',
        };

        $head = "HTTP/1.1 {$status} {$statusText}\r\n";
        foreach ($headers as $k => $v) {
            $head .= "{$k}: {$v}\r\n";
        }
        $head .= "\r\n";

        return $head . $body;
    }
}
