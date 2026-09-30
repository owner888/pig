<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Web;

use Closure;
use Pig\Async\Loop;

/**
 * Non-blocking client connection with send-buffering & backpressure,
 * inspired by Workerman's connection architecture.
 */
final class Connection
{
    private string $readBuffer = '';
    private string $sendBuffer = '';
    private ?string $readableWatcher = null;
    private ?string $writableWatcher = null;
    private bool $isClosed = false;

    /**
     * @param resource $socket client socket stream
     * @param Closure(self, array{method: string, uri: string, path: string, query: array<string, string>, headers: array<string, string>, body: string}): void $onMessage
     * @param Closure(self): void $onClose
     */
    public function __construct(
        public readonly mixed $socket,
        private readonly Closure $onMessage,
        private readonly Closure $onClose,
    ) {
        stream_set_blocking($this->socket, false);
    }

    /**
     * Feed incoming raw bytes into the read buffer and parse complete HTTP/1.1 requests.
     */
    public function onReadable(): void
    {
        $data = fread($this->socket, 65536);

        if ($data === false || $data === '') {
            $this->close();

            return;
        }

        $this->readBuffer .= $data;

        // HTTP/1.1 framing state machine (Workerman Http::input pattern)
        while ($this->readBuffer !== '') {
            $headEnd = strpos($this->readBuffer, "\r\n\r\n");

            if ($headEnd === false) {
                // Incomplete HTTP header, wait for next readable chunk
                if (strlen($this->readBuffer) > 16384) {
                    $this->close();
                }

                return;
            }

            $rawHead = substr($this->readBuffer, 0, $headEnd);
            $contentLength = 0;

            if (preg_match('/content-length:\s*(\d+)/i', $rawHead, $m) === 1) {
                $contentLength = (int) $m[1];
            }

            $totalPackageLength = $headEnd + 4 + $contentLength;

            if (strlen($this->readBuffer) < $totalPackageLength) {
                // Incomplete HTTP body, wait for more data
                return;
            }

            $rawPackage = substr($this->readBuffer, 0, $totalPackageLength);
            $this->readBuffer = substr($this->readBuffer, $totalPackageLength);

            $request = $this->parseHttpRequest($rawPackage, $headEnd);
            if ($request !== null) {
                ($this->onMessage)($this, $request);
            }
        }
    }

    /**
     * Send raw data to client with non-blocking write buffering and flow control.
     */
    public function send(string $data): void
    {
        if ($this->isClosed) {
            return;
        }

        if ($this->sendBuffer !== '') {
            $this->sendBuffer .= $data;

            return;
        }

        $written = fwrite($this->socket, $data);

        if ($written === false) {
            $this->close();

            return;
        }

        if ($written < strlen($data)) {
            // Short write: buffer remaining bytes and wait for socket writable
            $this->sendBuffer = substr($data, $written);
            $this->armWritable();
        }
    }

    public function sendResponse(int $status, array $headers, string $body): void
    {
        $headers['Content-Length'] = (string) strlen($body);
        $headers['Connection'] ??= 'keep-alive';

        $statusText = match ($status) {
            200 => 'OK',
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

        $this->send($head . $body);
    }

    private function armWritable(): void
    {
        if ($this->writableWatcher !== null) {
            return;
        }

        $this->writableWatcher = Loop::get()->onWritable($this->socket, function (): void {
            if ($this->sendBuffer === '' || $this->isClosed) {
                $this->disarmWritable();

                return;
            }

            $written = fwrite($this->socket, $this->sendBuffer);

            if ($written === false) {
                $this->close();

                return;
            }

            $this->sendBuffer = substr($this->sendBuffer, $written);

            if ($this->sendBuffer === '') {
                $this->disarmWritable();
            }
        });
    }

    private function disarmWritable(): void
    {
        if ($this->writableWatcher !== null) {
            Loop::get()->cancel($this->writableWatcher);
            $this->writableWatcher = null;
        }
    }

    public function setReadableWatcher(string $id): void
    {
        $this->readableWatcher = $id;
    }

    public function close(): void
    {
        if ($this->isClosed) {
            return;
        }

        $this->isClosed = true;

        if ($this->readableWatcher !== null) {
            Loop::get()->cancel($this->readableWatcher);
            $this->readableWatcher = null;
        }

        $this->disarmWritable();

        if (is_resource($this->socket)) {
            fclose($this->socket);
        }

        ($this->onClose)($this);
    }

    /**
     * @return array{method: string, uri: string, path: string, query: array<string, string>, headers: array<string, string>, body: string}|null
     */
    private function parseHttpRequest(string $package, int $headEnd): ?array
    {
        $rawHead = substr($package, 0, $headEnd);
        $body = substr($package, $headEnd + 4);

        $lines = explode("\r\n", $rawHead);
        if ($lines === []) {
            return null;
        }

        $first = explode(' ', $lines[0]);
        if (count($first) < 2) {
            return null;
        }

        $method = strtoupper($first[0]);
        $uri = $first[1];

        $parts = parse_url($uri);
        $path = $parts['path'] ?? '/';
        $query = [];
        if (isset($parts['query'])) {
            parse_str($parts['query'], $query);
        }

        $headers = [];
        foreach (array_slice($lines, 1) as $line) {
            if (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $headers[strtolower(trim($k))] = trim($v);
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
}
