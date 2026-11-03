<?php

declare(strict_types=1);

namespace Pig\Ai\Test\Providers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pig\Ai\Api;
use Pig\Ai\DoneEvent;
use Pig\Ai\Model;
use Pig\Ai\Providers\PiMessagesEventConverter;
use Pig\Ai\Providers\PiMessagesOptions;
use Pig\Ai\ProviderError;
use Pig\Ai\ReasoningEffort;
use Pig\Ai\SimpleStreamOptions;
use Pig\Ai\Stream;
use Pig\Ai\TextDeltaEvent;
use Pig\Async\Loop;

/**
 * pi's own message protocol, replayed against what upstream did with the same input (see
 * `UpstreamRecord`).
 *
 * `fixtures/pi-messages/p*.json`: a replayed conversation and every block kind streamed back with
 * a `rewrite` and a provider thinking level on `done`; the backend's own `error` event; a refusal
 * with a JSON error and one with an HTML body (each a `pi_messages_response_failure` diagnostic); a
 * body that ends with no terminal event, `\r\n` frames, a comment and `[DONE]`; `stream()`'s options
 * with `debug`, `cacheRetention`, an object `toolChoice`; `PIG_CACHE_RETENTION=long`.
 *
 * **The request body is compared as JSON, with one difference allowed**: upstream sends a user
 * message's content as the caller gave it — a bare string stays one — and pig's `UserMessage`
 * always holds blocks (docs/dev/design/runtime.md, "Porting the unions"), so a string in the recording is read as one
 * text block. Nulls are dropped on both sides, since pig writes `errorMessage: null`.
 */
final class PiMessagesTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $saved = [];

    #[\Override]
    protected function setUp(): void
    {
        Loop::reset();

        foreach (array_keys(getenv()) as $name) {
            if (str_starts_with($name, 'RADIUS') || str_starts_with($name, 'PI_') || preg_match('/proxy/i', $name) === 1) {
                $this->set($name, null);
            }
        }
    }

    #[\Override]
    protected function tearDown(): void
    {
        foreach ($this->saved as $name => $value) {
            putenv($value === false ? $name : "{$name}={$value}");
        }

        $this->saved = [];
    }

    /** @return iterable<string, array{string}> */
    public static function cases(): iterable
    {
        return UpstreamRecord::cases('pi-messages');
    }

    #[DataProvider('cases')]
    public function testPigDoesWhatUpstreamDidWithTheSameInput(string $case): void
    {
        [$scenario, $upstream] = UpstreamRecord::fixture('pi-messages', $case);

        [$requests, $events, $message] = UpstreamRecord::replay($scenario, function (string $server) use ($scenario) {
            foreach ($scenario['env'] ?? [] as $name => $value) {
                $this->set($name, $value);
            }

            $model = UpstreamRecord::model($scenario['model'], $server);
            $context = UpstreamRecord::context($scenario);
            $o = $scenario['options'];

            if ($scenario['simple']) {
                return Stream::simple($model, $context, new SimpleStreamOptions(
                    maxTokens: $o['maxTokens'] ?? null,
                    apiKey: $o['apiKey'] ?? null,
                    reasoning: isset($o['reasoning']) ? ReasoningEffort::from($o['reasoning']) : null,
                    sessionId: $o['sessionId'] ?? null,
                    headers: $o['headers'] ?? null,
                ));
            }

            return Stream::start($model, $context, new PiMessagesOptions(
                temperature: $o['temperature'] ?? null,
                maxTokens: $o['maxTokens'] ?? null,
                apiKey: $o['apiKey'] ?? null,
                cacheRetention: $o['cacheRetention'] ?? null,
                sessionId: $o['sessionId'] ?? null,
                headers: $o['headers'] ?? null,
                reasoning: $o['reasoning'] ?? null,
                toolChoice: $o['toolChoice'] ?? null,
                debug: $o['debug'] ?? false,
            ));
        });

        self::assertSame(array_column($upstream['requests'], 'path'), array_column($requests, 'path'));

        foreach ($upstream['requests'] as $i => $expected) {
            $actual = $requests[$i];
            self::assertSame($expected['method'], $actual['method']);
            self::assertEquals(self::body($expected['body']), self::body($actual['body']), "request {$i}'s body");
            $wanted = $expected['headers'];
            $sent = array_intersect_key($actual['headers'], array_flip(['authorization', 'accept', 'content-type', 'x-extra']));
            ksort($wanted);
            ksort($sent);
            self::assertSame($wanted, $sent, "request {$i}'s headers");
            // The recordings predate the client identity, when upstream sent undici's `node`; it
            // now sends `getIdentityHeaders()` first, as pig does.
            self::assertSame('node', $expected['userAgent']);
            self::assertSame(\Pig\Ai\ClientIdentity::formatUserAgent(), $actual['headers']['user-agent'] ?? null);
        }

        self::assertSame($upstream['events'], $events);
        self::assertEquals(UpstreamRecord::normalize($upstream['message']), UpstreamRecord::normalize((array) $message));
    }

    public function testAnUnknownEventIsAppliedToNothingAndHandedOnAsNothing(): void
    {
        $converter = new PiMessagesEventConverter(new Model('m', 'm', Api::PiMessages, 'radius', 'https://x.test', 1, 1));

        self::assertNull($converter->convert(['type' => 'future_event']));
        $converter->convert(['type' => 'text_start', 'contentIndex' => 0]);
        self::assertInstanceOf(TextDeltaEvent::class, $converter->convert(['type' => 'text_delta', 'contentIndex' => 0, 'delta' => 'a']));
        $done = $converter->convert(['type' => 'done', 'reason' => 'stop', 'usage' => ['input' => 1, 'output' => 1, 'totalTokens' => 2]]);
        self::assertInstanceOf(DoneEvent::class, $done);
        self::assertSame('a', $done->message->content[0]->text);
    }

    public function testTheTerminalEventSaysWhichModelAnswered(): void
    {
        // Upstream #11265: a virtual model (Radius's `cheap`) is answered by a real one, and the
        // terminal event names it — on `done` and on `error` alike, and on nothing when it does not.
        $usage = ['input' => 1, 'output' => 1, 'totalTokens' => 2];
        $model = new Model('auto', 'auto', Api::PiMessages, 'radius', 'https://x.test', 1, 1);

        $done = (new PiMessagesEventConverter($model))->convert(['type' => 'done', 'reason' => 'stop', 'usage' => $usage, 'responseModel' => 'claude-opus-5-5']);
        self::assertInstanceOf(DoneEvent::class, $done);
        self::assertSame('claude-opus-5-5', $done->message->responseModel);
        self::assertSame('auto', $done->message->model);

        $error = (new PiMessagesEventConverter($model))->convert(['type' => 'error', 'reason' => 'error', 'usage' => $usage, 'errorMessage' => 'Upstream failed', 'responseModel' => 'claude-opus-5-5']);
        self::assertInstanceOf(\Pig\Ai\ErrorEvent::class, $error);
        self::assertSame('claude-opus-5-5', $error->error->responseModel);

        $silent = (new PiMessagesEventConverter($model))->convert(['type' => 'done', 'reason' => 'stop', 'usage' => $usage]);
        self::assertInstanceOf(DoneEvent::class, $silent);
        self::assertNull($silent->message->responseModel);
    }

    public function testADeltaForABlockThatNeverStartedIsAnInvalidContentIndex(): void
    {
        // Was V8's TypeError until upstream 89c921d checked the index before using it.
        $converter = new PiMessagesEventConverter(new Model('m', 'm', Api::PiMessages, 'radius', 'https://x.test', 1, 1));

        $this->expectException(ProviderError::class);
        $this->expectExceptionMessage('Invalid contentIndex 3 in pi-messages text_delta event');
        $converter->convert(['type' => 'text_delta', 'contentIndex' => 3, 'delta' => 'a']);
    }

    /**
     * Upstream's "rejects content indexes that are not the next or an existing block".
     *
     * @param list<array<string, mixed>> $events
     */
    #[DataProvider('invalidContentIndexes')]
    public function testAContentIndexThatIsNotTheNextOrAnExistingBlockIsRefused(array $events, string $message): void
    {
        $converter = new PiMessagesEventConverter(new Model('m', 'm', Api::PiMessages, 'radius', 'https://x.test', 1, 1));
        $converter->convert(['type' => 'start']);
        $last = array_pop($events);

        foreach ($events as $event) {
            $converter->convert($event);
        }

        $this->expectException(ProviderError::class);
        $this->expectExceptionMessage($message);
        $converter->convert($last);
    }

    /** @return array<string, array{list<array<string, mixed>>, string}> */
    public static function invalidContentIndexes(): array
    {
        return [
            'a huge start' => [[['type' => 'text_start', 'contentIndex' => 4294967000]], 'Invalid contentIndex 4294967000 in pi-messages text_start event'],
            'a string start' => [[['type' => 'text_start', 'contentIndex' => '__proto__']], 'Invalid contentIndex "__proto__" in pi-messages text_start event'],
            'a string end' => [[
                ['type' => 'text_start', 'contentIndex' => 0],
                ['type' => 'toolcall_end', 'contentIndex' => '__proto__', 'toolCall' => ['polluted' => true]],
            ], 'Invalid contentIndex "__proto__" in pi-messages toolcall_end event'],
            'a delta before any start' => [[['type' => 'text_delta', 'contentIndex' => 0, 'delta' => 'x']], 'Invalid contentIndex 0 in pi-messages text_delta event'],
            'a start that skips a block' => [[
                ['type' => 'text_start', 'contentIndex' => 0],
                ['type' => 'thinking_start', 'contentIndex' => 2],
            ], 'Invalid contentIndex 2 in pi-messages thinking_start event'],
            'a negative start' => [[['type' => 'text_start', 'contentIndex' => -1]], 'Invalid contentIndex -1 in pi-messages text_start event'],
            'a float start' => [[['type' => 'text_start', 'contentIndex' => 0.5]], 'Invalid contentIndex 0.5 in pi-messages text_start event'],
        ];
    }

    public function testAStartMayBeTheNextBlockOrAnExistingOne(): void
    {
        $converter = new PiMessagesEventConverter(new Model('m', 'm', Api::PiMessages, 'radius', 'https://x.test', 1, 1));

        $converter->convert(['type' => 'text_start', 'contentIndex' => 0]);
        $converter->convert(['type' => 'text_delta', 'contentIndex' => 0, 'delta' => 'a']);
        $converter->convert(['type' => 'thinking_start', 'contentIndex' => 1]);
        $converter->convert(['type' => 'text_start', 'contentIndex' => 0]);
        $done = $converter->convert(['type' => 'done', 'reason' => 'stop', 'usage' => ['input' => 1, 'output' => 1, 'totalTokens' => 2]]);

        self::assertInstanceOf(DoneEvent::class, $done);
        self::assertCount(2, $done->message->content);
    }

    /** @return array<string, mixed> */
    private static function body(string $json): array
    {
        $body = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        foreach ($body['context']['messages'] ?? [] as $i => $message) {
            if (($message['role'] ?? null) === 'user' && is_string($message['content'] ?? null)) {
                $body['context']['messages'][$i]['content'] = [['type' => 'text', 'text' => $message['content']]];
            }
        }

        return (array) UpstreamRecord::withoutNulls($body);
    }

    private function set(string $name, ?string $value): void
    {
        if (!array_key_exists($name, $this->saved)) {
            $this->saved[$name] = getenv($name);
        }

        putenv($value === null ? $name : "{$name}={$value}");
    }
}
