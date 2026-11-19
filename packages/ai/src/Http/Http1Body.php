<?php

declare(strict_types=1);

namespace Pig\Ai\Http;

use Closure;
use Generator;
use Pig\Async\AbortSignal;
use Pig\Async\Socket;

/**
 * The body, delivered as it arrives.
 *
 * HTTP/1.1, on a socket of its own. Framed one of three ways, in the order HTTP gives them
 * precedence: chunked, then Content-Length, then "until the peer closes". Iterating suspends the
 * coroutine between chunks, so a slow model does not block the keyboard.
 */
final class Http1Body extends ResponseBody
{
    private bool $consumed = false;

    public function __construct(
        private readonly Socket $socket,
        private string $buffered,
        private readonly ?ChunkedDecoder $decoder,
        private readonly ?int $contentLength,
        private readonly float $timeout,
        private readonly ?AbortSignal $signal,
        /** Puts the socket back in the pool: called when the body ended where it said it would, on a connection that can be used again. */
        private readonly ?Closure $keep = null,
    ) {
    }

    /** @return Generator<int, string> */
    #[\Override]
    public function getIterator(): Generator
    {
        if ($this->consumed) {
            throw new HttpError('Response body has already been read');
        }

        $this->consumed = true;
        $delivered = 0;
        $pending = $this->buffered;
        $this->buffered = '';
        $complete = false;

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
                    $complete = true;

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
            if ($complete && $this->keep !== null) {
                ($this->keep)();
            } else {
                $this->socket->close();
            }
        }
    }

    #[\Override]
    public function close(): void
    {
        // A body with nothing in it, never read, leaves a connection as good as one read to the end.
        $unread = !$this->consumed && $this->buffered === '' && $this->isFinished(0);
        $this->consumed = true;

        if ($unread && $this->keep !== null) {
            ($this->keep)();

            return;
        }

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
