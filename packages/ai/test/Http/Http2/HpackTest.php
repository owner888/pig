<?php

declare(strict_types=1);

namespace Pig\Ai\Test\Http\Http2;

use PHPUnit\Framework\TestCase;
use Pig\Ai\Http\Http2\HpackDecoder;
use Pig\Ai\Http\Http2\HpackEncoder;
use Pig\Ai\Http\Http2\Huffman;
use Pig\Ai\Http\HttpError;
use Pig\Test\AssertsThrows;

/** RFC 7541's own examples (Appendix C) and what the encoder must never do. */
final class HpackTest extends TestCase
{
    use AssertsThrows;

    public function testHuffmanMatchesTheRfcExamples(): void
    {
        $this->assertSame('f1e3c2e5f23a6ba0ab90f4ff', bin2hex(Huffman::encode('www.example.com')));
        $this->assertSame('www.example.com', Huffman::decode((string) hex2bin('f1e3c2e5f23a6ba0ab90f4ff')));
        $this->assertSame('a8eb10649cbf', bin2hex(Huffman::encode('no-cache')));
    }

    public function testHuffmanRoundTripsEveryByte(): void
    {
        $all = implode('', array_map(chr(...), range(0, 255)));

        $this->assertSame($all, Huffman::decode(Huffman::encode($all)));
        $this->assertSame(strlen(Huffman::encode($all)), Huffman::encodedLength($all));
        $this->assertSame('', Huffman::decode(''));
    }

    public function testHuffmanRefusesBadPadding(): void
    {
        // 'a' is 00011; the three padding bits here are zeros, not ones.
        $this->assertThrows(HttpError::class, static fn () => Huffman::decode("\x18"));
        // Eight bits of ones are padding longer than a code can be short of.
        $this->assertThrows(HttpError::class, static fn () => Huffman::decode("\xff"));
    }

    public function testRequestsWithHuffmanStringsShareADynamicTable(): void
    {
        $decoder = new HpackDecoder();

        $this->assertSame(
            [[':method', 'GET'], [':scheme', 'http'], [':path', '/'], [':authority', 'www.example.com']],
            $decoder->decode((string) hex2bin('828684418cf1e3c2e5f23a6ba0ab90f4ff')),
        );
        // The second request points at the entry the first one added (index 62 = 0xbe).
        $this->assertSame(
            [[':method', 'GET'], [':scheme', 'http'], [':path', '/'], [':authority', 'www.example.com'], ['cache-control', 'no-cache']],
            $decoder->decode((string) hex2bin('828684be5886a8eb10649cbf')),
        );
        $this->assertSame(
            [[':method', 'GET'], [':scheme', 'https'], [':path', '/index.html'], [':authority', 'www.example.com'], ['custom-key', 'custom-value']],
            $decoder->decode((string) hex2bin('828785bf408825a849e95ba97d7f8925a849e95bb8e8b4bf')),
        );
    }

    public function testResponsesEvictFromASmallTable(): void
    {
        $decoder = new HpackDecoder(256);
        $first = $decoder->decode((string) hex2bin(
            '488264025885aec3771a4b6196d07abe941054d444a8200595040b8166e082a62d1bff6e919d29ad171863c78f0b97c8e9ae82ae43d3',
        ));

        $this->assertSame([':status', '302'], $first[0]);
        $this->assertSame(['location', 'https://www.example.com'], $first[3]);

        // Index 8 is the static ":status: 200"; 62 and up are the dynamic entries just added.
        $second = $decoder->decode((string) hex2bin('4883640effc1c0bf'));

        $this->assertSame([':status', '307'], $second[0]);
        $this->assertSame(['cache-control', 'private'], $second[1]);
        $this->assertSame(['location', 'https://www.example.com'], $second[3]);
    }

    public function testEncoderOutputDecodesToTheSameHeaders(): void
    {
        $headers = [
            [':method', 'POST'],
            [':scheme', 'https'],
            [':authority', 'api.example.com'],
            [':path', '/v1/messages?alt=sse'],
            ['accept', 'text/event-stream'],
            ['authorization', 'Bearer sk-test-0123456789'],
            ['content-type', 'application/json'],
            ['x-empty', ''],
            ['x-' . str_repeat('long', 40), str_repeat("é\x00", 300)],
        ];

        $block = (new HpackEncoder())->encode($headers);

        $this->assertSame($headers, (new HpackDecoder())->decode($block));
        // A full static match is one byte; a credential is never indexed (0001xxxx).
        $this->assertSame("\x83", (new HpackEncoder())->encode([[':method', 'POST']]));
        $this->assertSame(0x10, ord((new HpackEncoder())->encode([['authorization', 'x']])[0]) & 0xf0);
    }

    public function testEncoderDoesNotTouchThePeersTable(): void
    {
        $encoder = new HpackEncoder();
        $block = $encoder->encode([['x-one', 'a'], ['x-one', 'a']]);
        $decoder = new HpackDecoder();

        $this->assertSame([['x-one', 'a'], ['x-one', 'a']], $decoder->decode($block));
        // Nothing was added: index 62 does not exist.
        $this->assertThrows(HttpError::class, static fn () => $decoder->decode("\xbe"));
    }

    public function testDecoderRefusesWhatIsNotAHeaderBlock(): void
    {
        $decoder = new HpackDecoder();

        $this->assertThrows(HttpError::class, static fn () => $decoder->decode("\x80"));          // index 0
        $this->assertThrows(HttpError::class, static fn () => $decoder->decode("\xff"));          // integer cut short
        $this->assertThrows(HttpError::class, static fn () => $decoder->decode("\x40\x05ab"));    // string cut short
        $this->assertThrows(HttpError::class, static fn () => $decoder->decode("\x3f\xe2\x1f"));  // table size 4097 > 4096
        $this->assertThrows(HttpError::class, static fn () => $decoder->decode("\xff\xff\xff\xff\xff\xff"));
    }

    public function testATableSizeUpdateBelowTheLimitIsAccepted(): void
    {
        // 001xxxxx: size 0, then ":method: GET".
        $this->assertSame([[':method', 'GET']], (new HpackDecoder())->decode("\x20\x82"));
    }
}
