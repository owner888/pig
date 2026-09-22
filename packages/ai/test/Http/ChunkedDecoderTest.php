<?php

declare(strict_types=1);

namespace Pig\Ai\Test\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pig\Ai\Http\ChunkedDecoder;
use Pig\Ai\Http\HttpError;
use Pig\Test\AssertsThrows;

final class ChunkedDecoderTest extends TestCase
{
    use AssertsThrows;

    /** @param list<string> $writes */
    #[DataProvider('bodies')]
    public function testDecodesWhateverWayTheBytesArrive(array $writes, string $expected, bool $complete): void
    {
        $decoder = new ChunkedDecoder();
        $decoded = '';

        foreach ($writes as $write) {
            $decoded .= $decoder->feed($write);
        }

        $this->assertSame($expected, $decoded);
        $this->assertSame($complete, $decoder->isComplete());
    }

    /** @return array<string, array{0: list<string>, 1: string, 2: bool}> */
    public static function bodies(): array
    {
        return [
            'one chunk' => [["4\r\nWiki\r\n0\r\n\r\n"], 'Wiki', true],
            'several chunks' => [["4\r\nWiki\r\n5\r\npedia\r\n0\r\n\r\n"], 'Wikipedia', true],
            'split mid-payload' => [["4\r\nWi", "ki\r\n0\r\n\r\n"], 'Wiki', true],
            'split mid-size-line' => [["4", "\r\nWiki\r\n0\r\n\r\n"], 'Wiki', true],
            'split inside the CRLF after data' => [["4\r\nWiki\r", "\n0\r\n\r\n"], 'Wiki', true],
            'one byte at a time' => [str_split("4\r\nWiki\r\n0\r\n\r\n"), 'Wiki', true],
            'hex size above nine' => [["1a\r\n" . str_repeat('x', 26) . "\r\n0\r\n\r\n"], str_repeat('x', 26), true],
            'uppercase hex size' => [["A\r\n0123456789\r\n0\r\n\r\n"], '0123456789', true],
            'chunk extensions are ignored' => [["4;name=value\r\nWiki\r\n0\r\n\r\n"], 'Wiki', true],
            'trailer fields are dropped' => [["4\r\nWiki\r\n0\r\nX-Checksum: abc\r\n\r\n"], 'Wiki', true],
            'empty body' => [["0\r\n\r\n"], '', true],
            'still open without the terminator' => [["4\r\nWiki\r\n"], 'Wiki', false],
            'still open without the trailer blank line' => [["4\r\nWiki\r\n0\r\n"], 'Wiki', false],
            'payload containing CRLF' => [["5\r\na\r\nb\r\r\n0\r\n\r\n"], "a\r\nb\r", true],
        ];
    }

    public function testDecodedPayloadComesBackOnTheFeedThatCompletedIt(): void
    {
        $decoder = new ChunkedDecoder();

        // The size line alone completes nothing.
        $this->assertSame('', $decoder->feed("5\r\n"));
        $this->assertSame('hel', $decoder->feed('hel'));
        $this->assertSame('lo', $decoder->feed("lo\r\n"));
        $this->assertFalse($decoder->isComplete());

        $this->assertSame('', $decoder->feed("0\r\n\r\n"));
        $this->assertTrue($decoder->isComplete());
    }

    #[DataProvider('malformed')]
    public function testMalformedFramingIsRejected(string $body, string $message): void
    {
        $decoder = new ChunkedDecoder();

        $this->assertThrows(HttpError::class, static fn () => $decoder->feed($body), $message);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function malformed(): array
    {
        return [
            'size is not hex' => ["zz\r\nWiki\r\n", 'Malformed chunk size'],
            'size is empty' => ["\r\nWiki\r\n", 'Malformed chunk size'],
            'data not followed by CRLF' => ["4\r\nWikiXX\r\n", 'not terminated by CRLF'],
            'size line never ends' => [str_repeat('a', 2048), 'exceeds 1024 bytes'],
        ];
    }
}
