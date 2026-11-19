<?php

declare(strict_types=1);

namespace Pig\Ai\Http\Http2;

use Generator;
use Pig\Ai\Http\HttpError;
use Pig\Ai\Http\ResponseBody;
use Pig\Async\AbortSignal;

/**
 * A response body that is one stream of a shared connection, delivered as its DATA frames arrive.
 *
 * Reading it gives the server room to send more, and ending it, early or not, lets go of the
 * stream — a stream abandoned part way is cancelled — and not of the connection.
 */
final class Http2Body extends ResponseBody
{
    private bool $consumed = false;

    public function __construct(
        private readonly Http2Connection $connection,
        private readonly Http2Stream $stream,
        private readonly float $timeout,
        private readonly ?AbortSignal $signal,
        private readonly ?int $contentLength,
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

        try {
            while (true) {
                $this->connection->waitFor($this->stream, $this->timeout, $this->signal);

                // Whatever came in is delivered before an error is reported.
                while ($this->stream->chunks !== []) {
                    $chunk = array_shift($this->stream->chunks);
                    $delivered += strlen($chunk);
                    $this->connection->consumed($this->stream, strlen($chunk));

                    yield $chunk;
                }

                if ($this->stream->ended) {
                    if ($this->contentLength !== null && $delivered < $this->contentLength) {
                        throw new HttpError("Connection closed after {$delivered} of {$this->contentLength} promised bytes");
                    }

                    return;
                }

                if ($this->stream->error !== null) {
                    throw $this->stream->error;
                }
            }
        } finally {
            $this->connection->release($this->stream);
        }
    }

    #[\Override]
    public function close(): void
    {
        $this->consumed = true;
        $this->connection->release($this->stream);
    }
}
