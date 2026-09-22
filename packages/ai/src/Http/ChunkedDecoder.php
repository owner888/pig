<?php

declare(strict_types=1);

namespace Pig\Ai\Http;

/**
 * Incremental `Transfer-Encoding: chunked` decoder (RFC 9112 §7.1).
 *
 * Fed arbitrary slices of the wire — a chunk header can arrive split across two
 * reads, and one read can hold several chunks — and returns whatever payload those
 * bytes completed. Streaming responses arrive this way, so the decoder cannot wait
 * for the whole body before saying anything.
 */
final class ChunkedDecoder
{
    /** A size line past this is a malformed or hostile peer, not a large chunk. */
    private const int MAX_SIZE_LINE = 1024;

    private string $buffer = '';

    private int $remaining = 0;

    private ChunkedState $state = ChunkedState::Size;

    /** @return string the payload these bytes completed, possibly empty */
    public function feed(string $bytes): string
    {
        $this->buffer .= $bytes;
        $decoded = '';

        while ($this->step($decoded)) {
            // Each step consumes what it can; the loop ends when the buffer runs short.
        }

        return $decoded;
    }

    public function isComplete(): bool
    {
        return $this->state === ChunkedState::Done;
    }

    /** @return bool whether progress was made and another step is worth trying */
    private function step(string &$decoded): bool
    {
        return match ($this->state) {
            ChunkedState::Size => $this->readSize(),
            ChunkedState::Data => $this->readData($decoded),
            ChunkedState::DataEnd => $this->readDataEnd(),
            ChunkedState::Trailer => $this->readTrailer(),
            ChunkedState::Done => false,
        };
    }

    private function readSize(): bool
    {
        $line = $this->takeLine();

        if ($line === null) {
            if (strlen($this->buffer) > self::MAX_SIZE_LINE) {
                throw new HttpError('Chunk size line exceeds ' . self::MAX_SIZE_LINE . ' bytes');
            }

            return false;
        }

        // A size may carry extensions: "1a;name=value". Everything after the ';' is ignorable.
        $size = strstr($line, ';', true);
        $size = trim($size === false ? $line : $size);

        if ($size === '' || !ctype_xdigit($size)) {
            throw new HttpError("Malformed chunk size: \"{$line}\"");
        }

        $this->remaining = (int) hexdec($size);
        $this->state = $this->remaining === 0 ? ChunkedState::Trailer : ChunkedState::Data;

        return true;
    }

    private function readData(string &$decoded): bool
    {
        if ($this->buffer === '') {
            return false;
        }

        $take = min($this->remaining, strlen($this->buffer));
        $decoded .= substr($this->buffer, 0, $take);
        $this->buffer = substr($this->buffer, $take);
        $this->remaining -= $take;

        if ($this->remaining === 0) {
            $this->state = ChunkedState::DataEnd;
        }

        return true;
    }

    private function readDataEnd(): bool
    {
        if (strlen($this->buffer) < 2) {
            return false;
        }

        if (!str_starts_with($this->buffer, "\r\n")) {
            throw new HttpError('Chunk data not terminated by CRLF');
        }

        $this->buffer = substr($this->buffer, 2);
        $this->state = ChunkedState::Size;

        return true;
    }

    /** Trailer fields after the final chunk, ending at the blank line. */
    private function readTrailer(): bool
    {
        $line = $this->takeLine();

        if ($line === null) {
            return false;
        }

        if ($line === '') {
            $this->state = ChunkedState::Done;
        }

        return true;
    }

    /** @return string|null null when no complete CRLF-terminated line is buffered yet */
    private function takeLine(): ?string
    {
        $end = strpos($this->buffer, "\r\n");

        if ($end === false) {
            return null;
        }

        $line = substr($this->buffer, 0, $end);
        $this->buffer = substr($this->buffer, $end + 2);

        return $line;
    }
}
