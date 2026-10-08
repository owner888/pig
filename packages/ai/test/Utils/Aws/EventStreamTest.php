<?php

declare(strict_types=1);

namespace Pig\Ai\Test\Utils\Aws;

use PHPUnit\Framework\TestCase;
use Pig\Ai\Utils\Aws\EventStream;
use Pig\Test\AssertsThrows;
use RuntimeException;

/**
 * The event-stream framing against frames `@smithy/core`'s own `EventStreamCodec` wrote.
 *
 * The hex below was printed by smithy's encoder (from upstream's lockfile) for a message carrying
 * one header of every type, and for a plain `messageStart` frame; the error texts are what smithy's
 * decoder threw on the same bytes corrupted the same way.
 */
final class EventStreamTest extends TestCase
{
    use AssertsThrows;

    /** smithy's encoding of every header type and a JSON body. */
    private const string EVERY_TYPE = '0000008000000059c67386ab017400016601016202fd017303fed4016904fffeee90016c05fffffffed5fa0e000362696e060004000102ff0373747207000668c3a96c6c6f027473080000019b7ca98d03026964090f1e2d3c4b5a69788796a5b4c3d2e1f07b2264656c7461223a7b2274657874223a226869227d7d00de1228';

    /** smithy's `messageStart` frame. */
    private const string MESSAGE_START = '0000003f0000001bcc3639d20b3a6576656e742d7479706507000c6d65737361676553746172747b22726f6c65223a22617373697374616e74227d13bcddda';

    public function testEveryHeaderTypeDecodesAsSmithyDecodesIt(): void
    {
        $decoded = EventStream::decode((string) hex2bin(self::EVERY_TYPE));

        self::assertSame([
            't' => ['type' => 'boolean', 'value' => true],
            'f' => ['type' => 'boolean', 'value' => false],
            'b' => ['type' => 'byte', 'value' => -3],
            's' => ['type' => 'short', 'value' => -300],
            'i' => ['type' => 'integer', 'value' => -70000],
            'l' => ['type' => 'long', 'value' => -5_000_000_000],
            'bin' => ['type' => 'binary', 'value' => "\x00\x01\x02\xff"],
            'str' => ['type' => 'string', 'value' => 'héllo'],
            'ts' => ['type' => 'timestamp', 'value' => 1_767_323_045_123],
            'id' => ['type' => 'uuid', 'value' => '0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0'],
        ], $decoded['headers']);
        self::assertSame('{"delta":{"text":"hi"}}', $decoded['body']);
    }

    public function testEncodeWritesTheBytesSmithyWrites(): void
    {
        self::assertSame(self::MESSAGE_START, bin2hex(EventStream::encode([':event-type' => 'messageStart'], '{"role":"assistant"}')));
    }

    public function testFeedHandsBackOnlyWholeMessagesHoweverTheBytesArrive(): void
    {
        $two = (string) hex2bin(self::MESSAGE_START) . (string) hex2bin(self::EVERY_TYPE);
        $stream = new EventStream();
        $messages = [];

        // One byte at a time: the worst a socket can do.
        foreach (str_split($two) as $byte) {
            $messages = [...$messages, ...$stream->feed($byte)];
        }

        $stream->end();
        self::assertSame([(string) hex2bin(self::MESSAGE_START), (string) hex2bin(self::EVERY_TYPE)], $messages);

        // And all at once.
        self::assertCount(2, (new EventStream())->feed($two));
    }

    public function testABodyThatEndsMidMessageIsSmithysTruncationError(): void
    {
        $stream = new EventStream();
        self::assertSame([], $stream->feed(substr((string) hex2bin(self::MESSAGE_START), 0, 20)));

        $this->assertThrows(RuntimeException::class, static fn () => $stream->end(), 'Truncated event message received.');
    }

    public function testACorruptPreludeIsRefusedInSmithysWords(): void
    {
        $message = (string) hex2bin(self::MESSAGE_START);
        $message[9] = chr(ord($message[9]) ^ 1);

        $this->assertThrows(RuntimeException::class, static fn () => EventStream::decode($message), 'The prelude checksum specified in the message (3426171346) does not match the calculated CRC32 checksum (3426105810)');
    }

    public function testACorruptBodyIsRefusedInSmithysWords(): void
    {
        $message = (string) hex2bin(self::MESSAGE_START);
        $at = strlen($message) - 6;
        $message[$at] = chr(ord($message[$at]) ^ 1);

        $this->assertThrows(RuntimeException::class, static fn () => EventStream::decode($message), 'The message checksum (178777243) did not match the expected value of 331144666');
    }

    public function testTheLengthChecksAreSmithys(): void
    {
        $message = (string) hex2bin(self::MESSAGE_START);

        $this->assertThrows(RuntimeException::class, static fn () => EventStream::decode(substr($message, 0, 10)), 'Provided message too short to accommodate event stream message overhead');
        $this->assertThrows(RuntimeException::class, static fn () => EventStream::decode($message . "\x00"), 'Reported message length does not match received message length');
    }
}
