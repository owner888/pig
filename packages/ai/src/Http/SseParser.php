<?php

declare(strict_types=1);

namespace Pig\Ai\Http;

use Pig\Ai\Utils\JsJson;

/**
 * Incremental `text/event-stream` parser, following the WHATWG event stream rules.
 *
 * Fed arbitrary slices of the body and returns the events they completed. Boundaries
 * fall wherever the network put them, so a field name, a value, or the blank line that
 * dispatches an event can all arrive split in half.
 *
 * Every provider's streaming API is this format, so a bug here shows up as tokens
 * going missing rather than as an error.
 *
 * The bytes are read as upstream's readers decode them (`TextDecoder`, which is also the event
 * stream spec's "UTF-8 decode"): one byte-order mark at the very start of the stream is dropped,
 * and malformed UTF-8 in a line becomes U+FFFD. A line ending is ASCII, so a whole line is always
 * whole characters.
 */
final class SseParser
{
    private string $buffer = '';

    /** Whether the stream's first bytes have been looked at for a byte-order mark. */
    private bool $started = false;

    private string $type = '';

    private string $data = '';

    private ?string $id = null;

    private ?int $retry = null;

    /** @var list<string> the lines of the event being built — see `SseEvent::$raw` */
    private array $raw = [];

    /** @return list<SseEvent> */
    public function feed(string $bytes): array
    {
        $this->buffer .= $bytes;
        $events = [];

        if (!$this->started) {
            // Too few bytes yet to tell a mark from text.
            if (strlen($this->buffer) < 3 && str_starts_with("\u{FEFF}", $this->buffer)) {
                return [];
            }

            $this->started = true;

            if (str_starts_with($this->buffer, "\u{FEFF}")) {
                $this->buffer = substr($this->buffer, 3);
            }
        }

        while (($line = $this->takeLine()) !== null) {
            if ($line === '') {
                $event = $this->dispatch();

                if ($event !== null) {
                    $events[] = $event;
                }

                continue;
            }

            $this->raw[] = $line;

            // A line starting with ':' is a comment. Providers send them as keep-alives.
            if (str_starts_with($line, ':')) {
                continue;
            }

            [$field, $value] = $this->splitField($line);

            match ($field) {
                'event' => $this->type = $value,
                // Each data line contributes value + LF; dispatch drops the last LF.
                'data' => $this->data .= $value . "\n",
                'id' => $this->id = str_contains($value, "\0") ? $this->id : $value,
                'retry' => $this->retry = ctype_digit($value) ? (int) $value : $this->retry,
                default => null,
            };
        }

        return $events;
    }

    /** @return array{0: string, 1: string} */
    private function splitField(string $line): array
    {
        $colon = strpos($line, ':');

        if ($colon === false) {
            return [$line, ''];
        }

        $value = substr($line, $colon + 1);

        return [substr($line, 0, $colon), str_starts_with($value, ' ') ? substr($value, 1) : $value];
    }

    private function dispatch(): ?SseEvent
    {
        // An empty data buffer dispatches nothing — it only resets the event type. The raw lines go
        // with it when there was a type to reset (upstream's decoder flushes such an event), and
        // otherwise carry over to the next event, as upstream's do.
        if ($this->data === '') {
            if ($this->type !== '') {
                $this->raw = [];
            }

            $this->type = '';

            return null;
        }

        $event = new SseEvent(
            $this->type === '' ? 'message' : $this->type,
            substr($this->data, 0, -1),
            $this->id,
            $this->retry,
            $this->raw,
        );

        $this->type = '';
        $this->data = '';
        $this->raw = [];

        return $event;
    }

    /**
     * Take one line, ended by LF, CRLF or a bare CR.
     *
     * A CR at the very end of the buffer is held back: the LF that would pair with it
     * may still be in flight, and treating it as a line ending would split one line in two.
     *
     * @return string|null null when no complete line is buffered yet
     */
    private function takeLine(): ?string
    {
        $length = strlen($this->buffer);

        for ($i = 0; $i < $length; $i++) {
            $character = $this->buffer[$i];

            if ($character === "\n") {
                return $this->cut($i, 1);
            }

            if ($character === "\r") {
                if ($i + 1 === $length) {
                    return null;
                }

                return $this->cut($i, $this->buffer[$i + 1] === "\n" ? 2 : 1);
            }
        }

        return null;
    }

    private function cut(int $at, int $terminatorLength): string
    {
        $line = substr($this->buffer, 0, $at);
        $this->buffer = substr($this->buffer, $at + $terminatorLength);

        return JsJson::decodeUtf8($line);
    }
}
