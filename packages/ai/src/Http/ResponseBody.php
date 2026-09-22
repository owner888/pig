<?php

declare(strict_types=1);

namespace Pig\Ai\Http;

use Generator;
use IteratorAggregate;
use Pig\Async\AbortSignal;
use Pig\Async\Socket;

/**
 * The body, delivered as it arrives.
 *
 * Framed one of three ways, in the order HTTP gives them precedence: chunked, then
 * Content-Length, then "until the peer closes". Iterating suspends the coroutine
 * between chunks, so a slow model does not block the keyboard.
 *
 * @implements IteratorAggregate<int, string>
 */
final class ResponseBody implements IteratorAggregate
{
    private bool $consumed = false;

    public function __construct(
        private readonly Socket $socket,
        private string $buffered,
        private readonly ?ChunkedDecoder $decoder,
        private readonly ?int $contentLength,
        private readonly float $timeout,
        private readonly ?AbortSignal $signal,
    ) {
    }

    /** @return Generator<int, string> */
    public function getIterator(): Generator
    {
        if ($this->consumed) {
            throw new HttpError('Response body has already been read');
        }

        $this->consumed = true;
        $delivered = 0;
        $pending = $this->buffered;
        $this->buffered = '';

        try {
            while (true) {
                if ($pending !== '') {
                    $payload = $this->decoder?->feed($pending) ?? $pending;
                    $pending = '';

                    if ($this->contentLength !== null) {
                        // A peer may send more than it promised; deliver only what it promised.
                        $payload = substr($payload, 0, $this->contentLength - $delivered);
                    }

                    if ($payload !== '') {
                        $delivered += strlen($payload);

                        yield $payload;
                    }
                }

                if ($this->isFinished($delivered)) {
                    return;
                }

                $chunk = $this->socket->read(8192, $this->timeout, $this->signal);

                if ($chunk === null) {
                    $this->assertCompleteAtEof($delivered);

                    return;
                }

                $pending = $chunk;
            }
        } finally {
            $this->socket->close();
        }
    }

    /** Read the whole body into a string. For error responses and non-streaming calls. */
    public function all(): string
    {
        $body = '';

        foreach ($this as $chunk) {
            $body .= $chunk;
        }

        return $body;
    }

    /** Stop reading and hang up — used when a stream is abandoned part way. */
    public function close(): void
    {
        $this->consumed = true;
        $this->socket->close();
    }

    private function isFinished(int $delivered): bool
    {
        if ($this->decoder !== null) {
            return $this->decoder->isComplete();
        }

        if ($this->contentLength !== null) {
            return $delivered >= $this->contentLength;
        }

        // No framing at all: only the peer hanging up ends this body.
        return false;
    }

    private function assertCompleteAtEof(int $delivered): void
    {
        if ($this->decoder !== null && !$this->decoder->isComplete()) {
            throw new HttpError('Connection closed in the middle of a chunked body');
        }

        if ($this->contentLength !== null && $delivered < $this->contentLength) {
            throw new HttpError(
                "Connection closed after {$delivered} of {$this->contentLength} promised bytes",
            );
        }
    }
}
