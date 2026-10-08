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
 * with `debug`, `cacheRetention`, an object `toolChoice`; `PI_CACHE_RETENTION=long`.
 *
 * **The request body is compared as JSON, with one difference allowed**: upstream sends a user
 * message's content as the caller gave it — a bare string stays one — and pig's `UserMessage`
 * always holds blocks (CLAUDE.md, "Porting the unions"), so a string in the recording is read as one
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
            // Upstream sets no user agent of its own; what it sent was undici's `node`.
            self::assertSame('node', $expected['userAgent']);
            self::assertArrayNotHasKey('user-agent', $actual['headers']);
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

    public function testADeltaForABlockThatNeverStartedIsV8sTypeError(): void
    {
        $converter = new PiMessagesEventConverter(new Model('m', 'm', Api::PiMessages, 'radius', 'https://x.test', 1, 1));

        $this->expectException(ProviderError::class);
        $this->expectExceptionMessage("Cannot read properties of undefined (reading 'text')");
        $converter->convert(['type' => 'text_delta', 'contentIndex' => 3, 'delta' => 'a']);
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
