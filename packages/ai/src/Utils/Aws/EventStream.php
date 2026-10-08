<?php

declare(strict_types=1);

namespace Pig\Ai\Utils\Aws;

use RuntimeException;

/**
 * The `application/vnd.amazon.eventstream` binary framing — `@smithy/core/event-streams`:
 * `getChunkedStream()` (cut the body into whole messages), `splitMessage()` (check the two CRC32s)
 * and `HeaderMarshaller` (the typed headers), plus `EventStreamCodec::encode()` for the other direction.
 *
 * A message is
 *
 * ```
 * [total length: u32][headers length: u32][prelude CRC32: u32][headers][payload][message CRC32: u32]
 * ```
 *
 * big-endian, the prelude CRC over the first eight bytes and the message CRC over everything before
 * it. A header is `[name length: u8][name][type: u8][value]`, the value's shape by type: 0/1 true and
 * false, 2 a byte, 3 a short, 4 an int, 5 a long, 6 bytes and 7 a string each behind a u16 length,
 * 8 a timestamp (ms, as a long), 9 a UUID.
 *
 * The error texts are smithy's, word for word, because they are what a failed turn says.
 */
final class EventStream
{
    private const int PRELUDE_LENGTH = 8;

    private const int CHECKSUM_LENGTH = 4;

    private const int MINIMUM_MESSAGE_LENGTH = self::PRELUDE_LENGTH + self::CHECKSUM_LENGTH * 2;

    private string $buffer = '';

    /**
     * `getChunkedStream()`: feed what the socket gave, get back every message that is now whole.
     *
     * @return list<string> whole messages, still encoded
     */
    public function feed(string $bytes): array
    {
        $this->buffer .= $bytes;
        $messages = [];

        while (strlen($this->buffer) >= 4) {
            $length = unpack('N', substr($this->buffer, 0, 4))[1];

            if (strlen($this->buffer) < $length) {
                break;
            }

            $messages[] = substr($this->buffer, 0, $length);
            $this->buffer = substr($this->buffer, $length);

            // A declared length under four would never advance; smithy allocates it and fails in
            // `splitMessage()`, which is what the caller does with this one.
            if ($length < 4) {
                break;
            }
        }

        return $messages;
    }

    /** `getChunkedStream()` at the end of the body: a message cut short is an error, nothing left is not. */
    public function end(): void
    {
        if ($this->buffer !== '') {
            throw new RuntimeException('Truncated event message received.');
        }
    }

    /**
     * `EventStreamCodec::decode()`: `splitMessage()` and the headers.
     *
     * @return array{headers: array<string, array{type: string, value: mixed}>, body: string}
     */
    public static function decode(string $message): array
    {
        $byteLength = strlen($message);

        if ($byteLength < self::MINIMUM_MESSAGE_LENGTH) {
            throw new RuntimeException('Provided message too short to accommodate event stream message overhead');
        }

        [, $messageLength, $headerLength, $expectedPrelude] = unpack('N3', substr($message, 0, 12));

        if ($byteLength !== $messageLength) {
            throw new RuntimeException('Reported message length does not match received message length');
        }

        $expectedMessage = unpack('N', substr($message, -self::CHECKSUM_LENGTH))[1];
        $prelude = crc32(substr($message, 0, self::PRELUDE_LENGTH));

        if ($expectedPrelude !== $prelude) {
            throw new RuntimeException("The prelude checksum specified in the message ({$expectedPrelude}) does not match the calculated CRC32 checksum ({$prelude})");
        }

        $checksum = crc32(substr($message, 0, $byteLength - self::CHECKSUM_LENGTH));

        if ($expectedMessage !== $checksum) {
            throw new RuntimeException("The message checksum ({$checksum}) did not match the expected value of {$expectedMessage}");
        }

        $start = self::PRELUDE_LENGTH + self::CHECKSUM_LENGTH;

        return [
            'headers' => self::parseHeaders(substr($message, $start, $headerLength)),
            'body' => substr($message, $start + $headerLength, $messageLength - $headerLength - ($start + self::CHECKSUM_LENGTH)),
        ];
    }

    /**
     * `EventStreamCodec::encode()` with string headers, which is all a ConverseStream frame carries —
     * for the tests, and for anything that has to speak the format back.
     *
     * @param array<string, string> $headers
     */
    public static function encode(array $headers, string $body): string
    {
        $encodedHeaders = '';

        foreach ($headers as $name => $value) {
            $name = (string) $name;
            $encodedHeaders .= chr(strlen($name)) . $name . chr(7) . pack('n', strlen($value)) . $value;
        }

        $length = strlen($encodedHeaders) + strlen($body) + 16;
        $prelude = pack('NN', $length, strlen($encodedHeaders));
        $message = $prelude . pack('N', crc32($prelude)) . $encodedHeaders . $body;

        return $message . pack('N', crc32($message));
    }

    /**
     * `HeaderMarshaller::parse()`.
     *
     * @return array<string, array{type: string, value: mixed}>
     */
    private static function parseHeaders(string $headers): array
    {
        $out = [];
        $position = 0;
        $length = strlen($headers);

        while ($position < $length) {
            $nameLength = ord($headers[$position++]);
            $name = substr($headers, $position, $nameLength);
            $position += $nameLength;
            $type = ord($headers[$position++]);

            $at = $position;
            $u16 = static fn (): int => unpack('n', substr($headers, $at, 2))[1];
            [$kind, $value, $consumed] = match ($type) {
                0, 1 => ['boolean', $type === 0, 0],
                2 => ['byte', unpack('c', $headers[$at])[1], 1],
                3 => ['short', $u16() << 48 >> 48, 2],
                4 => ['integer', unpack('N', substr($headers, $at, 4))[1] << 32 >> 32, 4],
                5 => ['long', unpack('J', substr($headers, $at, 8))[1], 8],
                6 => ['binary', substr($headers, $at + 2, $u16()), 2 + $u16()],
                7 => ['string', substr($headers, $at + 2, $u16()), 2 + $u16()],
                8 => ['timestamp', unpack('J', substr($headers, $at, 8))[1], 8],
                9 => ['uuid', self::uuid(bin2hex(substr($headers, $at, 16))), 16],
                default => throw new RuntimeException('Unrecognized header type tag'),
            };
            $out[$name] = ['type' => $kind, 'value' => $value];
            $position += $consumed;
        }

        return $out;
    }

    private static function uuid(string $hex): string
    {
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }
}
