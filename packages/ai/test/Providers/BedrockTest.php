<?php

declare(strict_types=1);

namespace Pig\Ai\Test\Providers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pig\Ai\Api;
use Pig\Ai\BedrockCompat;
use Pig\Ai\Context;
use Pig\Ai\Model;
use Pig\Ai\Pricing;
use Pig\Ai\Providers\Bedrock;
use Pig\Ai\Providers\BedrockOptions;
use Pig\Ai\ReasoningEffort;
use Pig\Ai\SimpleStreamOptions;
use Pig\Ai\Stream;
use Pig\Ai\Utils\Aws\EventStream;
use Pig\Ai\Utils\Aws\ServiceError;
use Pig\Ai\Utils\MessageJson;
use Pig\Ai\Utils\Transcript;
use Pig\Async\AbortController;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\Test\CannedServer;

/**
 * Amazon Bedrock's ConverseStream, replayed against what upstream did with the same input.
 *
 * Each `fixtures/bedrock/<case>.json` is a scenario and **upstream's own answer to it**: the
 * scenario was run through upstream's `bedrock-converse-stream.ts` and the AWS SDK it pins (from
 * its lockfile, against the same canned server), and what that sent and emitted was written down
 * beside it. This test runs pig on the scenario and holds it to that record:
 *
 * - the last request's line, headers and body — the body byte for byte, the headers all but the
 *   ones that cannot match (`amz-sdk-invocation-id` and `x-amz-date` are fresh each time, `host` is
 *   the port, the user agent's `os/` and `md/` tokens are the machine and the runtime, and a SigV4
 *   signature covers the invocation id — its credential scope and signed-header list are compared);
 * - every attempt's `amz-sdk-request`, which is the retry loop;
 * - every event, its content index and its delta;
 * - the final message, durations and timestamps aside.
 *
 * The cases: `b` request building end to end, `c` the request-building branches (adaptive and
 * budget thinking, GovCloud, OpenAI's and gpt-oss's effort fields, Nova, ARNs, the simple API,
 * cache points), `e` errors, diagnostics, retries and first-frame exceptions, `a` the bearer token,
 * `AWS_BEDROCK_SKIP_AUTH`, profile and default regions and no credentials, `ab` aborts, and `k` the
 * credential chain — assume role, web identity, `credential_process`, legacy SSO, the ECS full URI,
 * a missing profile, an STS error, and an STS 503 the STS client retries.
 */
final class BedrockTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $saved = [];

    #[\Override]
    protected function setUp(): void
    {
        Loop::reset();
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
        foreach (glob(self::dir() . '/*.json') ?: [] as $path) {
            yield basename($path, '.json') => [basename($path, '.json')];
        }
    }

    #[DataProvider('cases')]
    public function testPigDoesWhatUpstreamDidWithTheSameInput(string $case): void
    {
        $fixture = json_decode((string) file_get_contents(self::dir() . "/{$case}.json"), true, flags: JSON_THROW_ON_ERROR);
        $scenario = json_decode(str_replace('FIXTURES/', self::dir() . '/', (string) json_encode($fixture['scenario'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)), true);
        $upstream = $fixture['upstream'];

        [$requests, $events, $message] = $this->replay($scenario);

        $last = $requests === [] ? null : $requests[count($requests) - 1];

        if ($upstream['request'] === null) {
            self::assertNull($last, 'upstream sent nothing');
        } else {
            self::assertNotNull($last, 'upstream sent a request');
            self::assertSame($upstream['request']['line'], $last['line']);
            self::assertSame($upstream['request']['body'], $last['body']);
            self::assertSame($upstream['request']['headers'], self::comparable($last['headers']));
        }

        if ($upstream['attempts'] !== null) {
            $attempts = [];

            foreach ($requests as $request) {
                if (isset($request['headers']['amz-sdk-request'])) {
                    $attempts[] = $request['headers']['amz-sdk-request'];
                }
            }

            self::assertSame($upstream['attempts'], $attempts);
        }

        if (isset($scenario['abortAfterEvents'])) {
            // **Where an abort lands mid-stream is a race**, upstream's as much as pig's: Node had
            // the rest of the frames buffered and emitted them before its post-loop check said
            // "Request was aborted"; pig's read is interrupted at once, which is "Request aborted".
            // What is held is the outcome — the events upstream's run started with, an `error` event
            // last, and an aborted message.
            self::assertSame(array_slice($upstream['events'], 0, count($events) - 1), array_slice($events, 0, -1));
            self::assertSame('error', $events[count($events) - 1][0]);
            self::assertSame($upstream['message']['stopReason'], $message['stopReason']);

            return;
        }

        self::assertSame($upstream['events'], $events);
        self::assertEquals(self::normalize($upstream['message']), self::normalize($message));
    }

    public function testBedrocksOwnExceptionsAreNamedAndEverythingElseIsItsMessage(): void
    {
        $throttled = new ServiceError('Too many tokens, please wait.', 'ThrottlingException', 429, null, true);
        $plain = new \RuntimeException('socket hang up');

        self::assertSame('Throttling error: Too many tokens, please wait.', Bedrock::formatBedrockError($throttled));
        self::assertSame('socket hang up', Bedrock::formatBedrockError($plain));
    }

    public function testTheStandardEndpointRegionIsReadOffTheHost(): void
    {
        self::assertSame('eu-central-1', Bedrock::getStandardBedrockEndpointRegion('https://bedrock-runtime.eu-central-1.amazonaws.com'));
        self::assertSame('us-gov-west-1', Bedrock::getStandardBedrockEndpointRegion('https://bedrock-runtime-fips.us-gov-west-1.amazonaws.com'));
        self::assertNull(Bedrock::getStandardBedrockEndpointRegion('https://proxy.example.com'));
    }

    private static function dir(): string
    {
        return __DIR__ . '/../fixtures/bedrock';
    }

    /**
     * One scenario through pig: the canned replies served, the environment set as the scenario says,
     * and the stream read to the end. (Not `run()`, which is `final` on `TestCase` — see
     * `GenerateModelsTest::generated()`.)
     *
     * @param array<string, mixed> $scenario
     * @return array{0: list<array{line: string, headers: array<string, string>, body: string}>, 1: list<array{0: string, 1: int|null, 2: string|null}>, 2: array<string, mixed>}
     */
    private function replay(array $scenario): array
    {
        foreach (array_keys(getenv()) as $name) {
            if (str_starts_with($name, 'AWS_') || preg_match('/proxy/i', $name) === 1) {
                $this->forget($name);
            }
        }

        $this->set('HOME', $scenario['home'] ?? '/nonexistent-home');
        $this->set('AWS_CONFIG_FILE', $scenario['awsConfigFile'] ?? '/nonexistent/config');
        $this->set('AWS_SHARED_CREDENTIALS_FILE', $scenario['awsCredentialsFile'] ?? '/nonexistent/credentials');
        $this->set('AWS_EC2_METADATA_DISABLED', 'true');

        $replies = [];

        foreach ($scenario['responses'] ?? [$scenario['response']] as $response) {
            // One piece per frame, as upstream's harness wrote one `res.write()` per frame: a frame
            // that arrives on its own is a point where the reader can see an abort.
            $pieces = [];

            if (isset($response['frames'])) {
                foreach ($response['frames'] as $frame) {
                    $pieces[] = EventStream::encode($frame['headers'], $frame['payload'] ?? '');
                }
            } elseif (isset($response['raw'])) {
                $pieces[] = $response['raw'];
            }

            $head = 'HTTP/1.1 ' . ($response['status'] ?? 200) . " OK\r\n";

            foreach ($response['headers'] ?? ['content-type' => 'application/vnd.amazon.eventstream'] as $name => $value) {
                $head .= "{$name}: {$value}\r\n";
            }

            $replies[] = [$head . 'content-length: ' . strlen(implode('', $pieces)) . "\r\n\r\n", ...$pieces];
        }

        $server = new CannedServer();
        $url = rtrim($server->startSequence($replies), '/');

        foreach ($scenario['env'] ?? [] as $name => $value) {
            $this->set($name, str_replace('SERVER', $url, $value));
        }

        $m = $scenario['model'];
        $model = new Model(
            $m['id'],
            $m['name'],
            Api::BedrockConverseStream,
            $m['provider'],
            $m['baseUrl'] === 'SERVER' ? $url : $m['baseUrl'],
            $m['contextWindow'],
            $m['maxTokens'],
            $m['reasoning'],
            $m['input'],
            new Pricing($m['cost']['input'], $m['cost']['output'], $m['cost']['cacheRead'], $m['cost']['cacheWrite']),
            compat: isset($m['compat']['supportsStrictMode']) ? new BedrockCompat($m['compat']['supportsStrictMode']) : null,
            thinkingLevelMap: $m['thinkingLevelMap'] ?? [],
        );
        $context = new Context(
            array_map(
                static fn (array $entry): mixed => MessageJson::decode(is_string($entry['content'] ?? null) ? [...$entry, 'content' => [['type' => 'text', 'text' => $entry['content']]]] : $entry),
                $scenario['context']['messages'],
            ),
            $scenario['context']['systemPrompt'] ?? null,
            array_map(static fn (array $tool): mixed => MessageJson::decodeTool($tool), $scenario['context']['tools'] ?? []),
        );
        $o = $scenario['options'] ?? [];

        $events = [];
        $message = null;
        Async::run(function () use ($scenario, $model, $context, $o, &$events, &$message): void {
            $controller = new AbortController();
            $abortBefore = $scenario['abortBefore'] ?? false;

            if ($abortBefore) {
                $controller->abort();
            }

            $signal = $abortBefore || isset($scenario['abortAfterEvents']) ? $controller->signal : null;
            $stream = ($scenario['simple'] ?? false)
                ? Stream::simple($model, $context, new SimpleStreamOptions(
                    temperature: $o['temperature'] ?? null,
                    maxTokens: $o['maxTokens'] ?? null,
                    signal: $signal,
                    apiKey: $o['apiKey'] ?? null,
                    reasoning: isset($o['reasoning']) ? ReasoningEffort::from($o['reasoning']) : null,
                    toolChoice: $o['toolChoice'] ?? null,
                    cacheRetention: $o['cacheRetention'] ?? null,
                ))
                : (new Bedrock(
                    now: isset($scenario['now']) ? static fn (): int => $scenario['now'] : null,
                    sleep: static function (int $ms): void {
                    },
                ))->stream($model, Transcript::normalizeContext($context), new BedrockOptions(
                    temperature: $o['temperature'] ?? null,
                    maxTokens: $o['maxTokens'] ?? null,
                    signal: $signal,
                    apiKey: $o['apiKey'] ?? null,
                    region: $o['region'] ?? null,
                    profile: $o['profile'] ?? null,
                    toolChoice: $o['toolChoice'] ?? null,
                    reasoning: $o['reasoning'] ?? null,
                    interleavedThinking: $o['interleavedThinking'] ?? null,
                    thinkingDisplay: $o['thinkingDisplay'] ?? null,
                    requestMetadata: $o['requestMetadata'] ?? null,
                    cacheRetention: $o['cacheRetention'] ?? null,
                    env: $o['env'] ?? null,
                ));

            foreach ($stream as $event) {
                $events[] = [
                    self::eventType($event),
                    property_exists($event, 'contentIndex') ? $event->contentIndex : null,
                    property_exists($event, 'delta') ? $event->delta : null,
                ];

                if (isset($scenario['abortAfterEvents']) && count($events) === $scenario['abortAfterEvents']) {
                    $controller->abort();
                }
            }

            $message = MessageJson::encode($stream->result()->await());
        });

        $requests = [];

        foreach (preg_split('/(?=(?:POST|GET|PUT) \/)/', $server->received(), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $one) {
            [$head, $body] = explode("\r\n\r\n", $one, 2) + [1 => ''];
            $lines = explode("\r\n", $head);
            $line = (string) array_shift($lines);
            $headers = [];

            foreach ($lines as $header) {
                [$name, $value] = explode(':', $header, 2);
                $headers[strtolower($name)] = ltrim($value);
            }

            $requests[] = ['line' => (string) preg_replace('/ HTTP\/1\.1$/', '', $line), 'headers' => $headers, 'body' => $body];
        }

        $server->stop();

        return [$requests, $events, $message];
    }

    /**
     * The headers as the fixture records upstream's: the ones that cannot match left out, the user
     * agent without the machine and the runtime, a signature cut to its scope and signed headers.
     *
     * @param array<string, string> $headers
     * @return array<string, string>
     */
    private static function comparable(array $headers): array
    {
        $out = [];

        foreach ($headers as $name => $value) {
            if (in_array($name, ['amz-sdk-invocation-id', 'host', 'connection', 'accept-encoding', 'x-amz-date'], true)) {
                continue;
            }

            if ($name === 'user-agent') {
                $value = implode(' ', array_filter(explode(' ', $value), static fn (string $token): bool => !str_starts_with($token, 'os/') && !str_starts_with($token, 'md/')));
            }

            if ($name === 'authorization' && str_starts_with($value, 'AWS4')) {
                $value = (string) preg_replace('/\/\d{8}\//', '/D/', explode(', Signature=', $value)[0]);
            }

            $out[$name] = $value;
        }

        ksort($out);

        return $out;
    }

    /**
     * @param array<string, mixed> $message
     * @return array<string, mixed>
     */
    private static function normalize(array $message): array
    {
        $message = self::withoutNulls($message);
        unset($message['durationMs'], $message['timestamp']);

        if (isset($message['diagnostics'])) {
            $message['diagnostics'] = array_map(static function (array $diagnostic): array {
                unset($diagnostic['timestamp']);

                return $diagnostic;
            }, $message['diagnostics']);
        }

        $message['content'] = array_map(static function (array $block): array {
            if (($block['thinkingSignature'] ?? null) === '') {
                unset($block['thinkingSignature']);
            }

            return $block;
        }, $message['content']);

        if (isset($message['usage']['cost'])) {
            $message['usage']['cost'] = array_map(static fn (int|float $value): float => round((float) $value, 12), $message['usage']['cost']);
        }

        return $message;
    }

    private static function withoutNulls(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        $out = [];

        foreach ($value as $key => $item) {
            if ($item !== null) {
                $out[$key] = self::withoutNulls($item);
            }
        }

        return array_is_list($value) ? array_values($out) : $out;
    }

    /** pig's event class as upstream's `type` — `GoogleVertexTest` reads its events with it too. */
    public static function eventType(object $event): string
    {
        $short = (new \ReflectionClass($event))->getShortName();

        return match ($short) {
            'StartEvent' => 'start',
            'TextStartEvent' => 'text_start',
            'TextDeltaEvent' => 'text_delta',
            'TextEndEvent' => 'text_end',
            'ThinkingStartEvent' => 'thinking_start',
            'ThinkingDeltaEvent' => 'thinking_delta',
            'ThinkingEndEvent' => 'thinking_end',
            'ToolCallStartEvent' => 'toolcall_start',
            'ToolCallDeltaEvent' => 'toolcall_delta',
            'ToolCallEndEvent' => 'toolcall_end',
            'DoneEvent' => 'done',
            'ErrorEvent' => 'error',
            default => $short,
        };
    }

    private function set(string $name, string $value): void
    {
        if (!array_key_exists($name, $this->saved)) {
            $this->saved[$name] = getenv($name);
        }

        putenv("{$name}={$value}");
    }

    private function forget(string $name): void
    {
        if (!array_key_exists($name, $this->saved)) {
            $this->saved[$name] = getenv($name);
        }

        putenv($name);
    }
}
