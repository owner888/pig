<?php

declare(strict_types=1);

namespace Pig\Ai\Test\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pig\Ai\Http\SseEvent;
use Pig\Ai\Http\SseParser;

final class SseParserTest extends TestCase
{
    /**
     * @param list<string>                $writes
     * @param list<array{string, string}> $expected type and data of each event
     */
    #[DataProvider('streams')]
    public function testParses(array $writes, array $expected): void
    {
        $parser = new SseParser();
        $events = [];

        foreach ($writes as $write) {
            foreach ($parser->feed($write) as $event) {
                $events[] = [$event->type, $event->data];
            }
        }

        $this->assertSame($expected, $events);
    }

    /** @return array<string, array{0: list<string>, 1: list<array{string, string}>}> */
    public static function streams(): array
    {
        return [
            'one event' => [["data: hello\n\n"], [['message', 'hello']]],
            'named event' => [["event: ping\ndata: hello\n\n"], [['ping', 'hello']]],
            'two events' => [["data: a\n\ndata: b\n\n"], [['message', 'a'], ['message', 'b']]],
            'multi-line data joins with newlines' => [["data: a\ndata: b\n\n"], [['message', "a\nb"]]],
            'empty data line is kept' => [["data: a\ndata:\ndata: b\n\n"], [['message', "a\n\nb"]]],
            'only one leading space is stripped' => [["data:  hello\n\n"], [['message', ' hello']]],
            'no space after the colon' => [["data:hello\n\n"], [['message', 'hello']]],
            'a field with no colon has an empty value' => [["data\n\n"], [['message', '']]],
            'comments are ignored' => [[": keep-alive\ndata: hello\n\n"], [['message', 'hello']]],
            'unknown fields are ignored' => [["foo: bar\ndata: hello\n\n"], [['message', 'hello']]],
            'a blank line with no data dispatches nothing' => [["\n\ndata: hello\n\n"], [['message', 'hello']]],
            'event type resets between events' => [
                ["event: ping\ndata: a\n\ndata: b\n\n"],
                [['ping', 'a'], ['message', 'b']],
            ],
            'CRLF line endings' => [["data: hello\r\n\r\n"], [['message', 'hello']]],
            'bare CR line endings' => [
                // The final CR is held back, so only the first event lands.
                ["data: hello\r\rdata: next\r\r"],
                [['message', 'hello']],
            ],
            'a trailing CR waits for the LF that may follow' => [
                ["data: hello\r", "\n\n"],
                [['message', 'hello']],
            ],
            'an unterminated event is not dispatched' => [["data: hello\n"], []],
            'data with a colon in it' => [["data: {\"a\": 1}\n\n"], [['message', '{"a": 1}']]],
            'split across every boundary' => [
                str_split("event: delta\ndata: hi\n\n"),
                [['delta', 'hi']],
            ],
        ];
    }

    public function testWhateverIsStillPendingWhenTheStreamEndsIsDiscarded(): void
    {
        // Two things are at work and both are deliberate. A trailing CR cannot be acted
        // on, because the LF that would pair with it may not have arrived. And the spec
        // says a partial event at end of stream is dropped, not dispatched — so a
        // provider that hangs up mid-event loses it rather than yielding half of one.
        $parser = new SseParser();

        $this->assertSame([], $parser->feed("data: half an event\n"));
        $this->assertSame([], $parser->feed("data: hello\r"));
    }

    public function testAnEventKeepsTheLinesItWasMadeOfCommentsIncluded(): void
    {
        // Upstream's Anthropic decoder keeps them (`ServerSentEvent.raw`) to quote in the error for
        // an event it cannot parse; a comment before an event belongs to it.
        $parser = new SseParser();
        $events = $parser->feed(": ping\nevent: x\ndata: a\ndata: b\n\ndata: c\n\n");

        $this->assertSame([': ping', 'event: x', 'data: a', 'data: b'], $events[0]->raw);
        $this->assertSame(['data: c'], $events[1]->raw);
    }

    public function testIdAndRetryPersistAcrossEventsAsTheSpecSays(): void
    {
        $parser = new SseParser();
        $events = $parser->feed("id: 1\nretry: 3000\ndata: a\n\ndata: b\n\n");

        $this->assertCount(2, $events);
        $this->assertSame('1', $events[0]->id);
        $this->assertSame(3000, $events[0]->retry);

        // No id or retry on the second event, so the last ones still stand.
        $this->assertSame('1', $events[1]->id);
        $this->assertSame(3000, $events[1]->retry);
    }

    public function testAnIdContainingANullByteIsIgnored(): void
    {
        $parser = new SseParser();
        $events = $parser->feed("id: good\n\ndata: x\n\nid: bad\0id\ndata: y\n\n");

        $this->assertSame('good', $events[0]->id);
        $this->assertSame('good', $events[1]->id);
    }

    public function testByteByByteMatchesOneBigFeed(): void
    {
        // The real shape: an Anthropic message stream, framed as providers frame it.
        $stream = "event: message_start\ndata: {\"type\":\"message_start\"}\n\n"
            . ": keep-alive\n\n"
            . "event: content_block_delta\ndata: {\"delta\":{\"text\":\"Hel\"}}\n\n"
            . "event: content_block_delta\ndata: {\"delta\":{\"text\":\"lo\"}}\n\n"
            . "event: message_stop\ndata: {\"type\":\"message_stop\"}\n\n";

        $atOnce = array_map($this->describe(...), (new SseParser())->feed($stream));

        $drip = new SseParser();
        $dripped = [];
        foreach (str_split($stream) as $byte) {
            foreach ($drip->feed($byte) as $event) {
                $dripped[] = $this->describe($event);
            }
        }

        $this->assertSame($atOnce, $dripped);
        $this->assertCount(4, $atOnce);
        $this->assertSame('content_block_delta|{"delta":{"text":"Hel"}}', $atOnce[1]);
    }

    public function testALeadingByteOrderMarkIsDroppedEvenSplitAcrossFeeds(): void
    {
        // `TextDecoder` (and the spec's UTF-8 decode) drops one BOM at the start of the stream;
        // without it the first field name is "\u{FEFF}event" and the first event loses its type.
        $parser = new SseParser();
        $events = [];

        foreach (["\xEF", "\xBB", "\xBFevent: ping\ndata: 1\n\n", "\u{FEFF}data: 2\n\n"] as $piece) {
            array_push($events, ...$parser->feed($piece));
        }

        // Only at the very start: a later one is part of a line, whose field name it spoils, so the
        // second event has no data field and is not dispatched.
        $this->assertSame(['ping|1'], array_map($this->describe(...), $events));
    }

    public function testMalformedUtf8InALineIsAReplacementCharacter(): void
    {
        $events = (new SseParser())->feed("data: caf\xE9!\n\n");

        $this->assertSame("caf\u{FFFD}!", $events[0]->data);
    }

    private function describe(SseEvent $event): string
    {
        return "{$event->type}|{$event->data}";
    }
}
