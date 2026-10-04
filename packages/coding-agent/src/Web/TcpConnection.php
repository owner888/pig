<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Web;

use Closure;
use Pig\Async\Loop;
use Pig\CodingAgent\Web\Protocols\Http;
use Pig\CodingAgent\Web\Protocols\ProtocolInterface;

/**
 * Workerman-inspired non-blocking TCP connection with dynamic protocol framing,
 * send-buffering, and backpressure handling.
 */
class TcpConnection
{
    protected string $readBuffer = '';
    protected string $sendBuffer = '';
    protected ?string $readableWatcher = null;
    protected ?string $writableWatcher = null;
    protected bool $isClosed = false;

    /** @var class-string<ProtocolInterface> */
    protected string $protocol = Http::class;

    /**
     * @param resource $socket client socket stream
     * @param Closure(self, mixed): void $onMessage
     * @param Closure(self): void $onClose
     * @param class-string<ProtocolInterface> $protocol
     */
    public function __construct(
        public readonly mixed $socket,
        protected readonly Closure $onMessage,
        protected readonly Closure $onClose,
        string $protocol = Http::class,
    ) {
        stream_set_blocking($this->socket, false);
        $this->protocol = $protocol;
    }

    /**
     * Dynamically switch connection protocol (e.g. from HTTP to WebSocket after handshake).
     *
     * @param class-string<ProtocolInterface> $protocol
     */
    public function setProtocol(string $protocol): void
    {
        $this->protocol = $protocol;
    }

    /**
     * @return class-string<ProtocolInterface>
     */
    public function getProtocol(): string
    {
        return $this->protocol;
    }

    /**
     * Feed incoming raw bytes into the read buffer and parse complete packages via protocol input().
     */
    public function onReadable(): void
    {
        $data = fread($this->socket, 65536);

        if ($data === false || $data === '') {
            $this->close();

            return;
        }

        $this->readBuffer .= $data;

        // Framing state machine loop decoupled by ProtocolInterface::input()
        while ($this->readBuffer !== '' && !$this->isClosed) {
            $packageLen = ($this->protocol)::input($this->readBuffer, $this);

            if ($packageLen === 0) {
                // Incomplete package, wait for next readable chunk
                return;
            }

            if ($packageLen === false) {
                // Protocol error or oversized frame
                $this->close();

                return;
            }

            $rawPackage = substr($this->readBuffer, 0, $packageLen);
            $this->readBuffer = substr($this->readBuffer, $packageLen);

            $data = ($this->protocol)::decode($rawPackage, $this);

            if ($data !== null) {
                ($this->onMessage)($this, $data);
            }
        }
    }

    /**
     * Send application data encoded via connection protocol.
     */
    public function send(mixed $data): void
    {
        $wire = ($this->protocol)::encode($data, $this);
        $this->sendRaw($wire);
    }

    /**
     * Send raw wire bytes directly to client with non-blocking write buffering.
     */
    public function sendRaw(string $data): void
    {
        if ($this->isClosed || $data === '') {
            return;
        }

        if ($this->sendBuffer !== '') {
            $this->sendBuffer .= $data;

            return;
        }

        set_error_handler(static fn (): bool => true);
        try {
            $written = fwrite($this->socket, $data);
        } finally {
            restore_error_handler();
        }

        if ($written === false) {
            $this->close();

            return;
        }

        if ($written < strlen($data)) {
            $this->sendBuffer = substr($data, $written);
            $this->armWritable();
        }
    }

    /**
     * Convenient HTTP response helper.
     *
     * @param array<string, string> $headers
     */
    public function sendResponse(int $status, array $headers, string $body): void
    {
        $response = Http::encode([
            'status' => $status,
            'headers' => $headers,
            'body' => $body,
        ], $this);

        $this->sendRaw($response);
    }

    private function armWritable(): void
    {
        if ($this->writableWatcher !== null || $this->isClosed) {
            return;
        }

        $this->writableWatcher = Loop::get()->onWritable($this->socket, function (): void {
            if ($this->sendBuffer === '') {
                $this->disarmWritable();

                return;
            }

            set_error_handler(static fn (): bool => true);
            try {
                $written = fwrite($this->socket, $this->sendBuffer);
            } finally {
                restore_error_handler();
            }

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

    public function setReadableWatcher(?string $watcher): void
    {
        $this->readableWatcher = $watcher;
    }

    public function close(): void
    {
        if ($this->isClosed) {
            return;
        }

        $this->isClosed = true;
        $this->disarmWritable();

        if ($this->readableWatcher !== null) {
            Loop::get()->cancel($this->readableWatcher);
            $this->readableWatcher = null;
        }

        if (is_resource($this->socket)) {
            fclose($this->socket);
        }

        ($this->onClose)($this);
    }
}
