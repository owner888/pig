<?php

declare(strict_types=1);

namespace Pig\Ai\Test\Providers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pig\Ai\Api;
use Pig\Ai\Context;
use Pig\Ai\Model;
use Pig\Ai\Pricing;
use Pig\Ai\Providers\GoogleVertex;
use Pig\Ai\Providers\GoogleVertexOptions;
use Pig\Ai\Utils\GoogleAuth;
use Pig\Ai\Utils\MessageJson;
use Pig\Ai\Utils\Transcript;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\Test\CannedServer;

/**
 * Vertex AI, replayed against what upstream did with the same input.
 *
 * As `BedrockTest`: each `fixtures/vertex/<case>.json` is a scenario and the record of upstream's
 * `google-vertex.ts` running it — with `@google/genai` and `google-auth-library` from its lockfile,
 * every fetch sent to the same canned server and the URL it was meant for written down beside it.
 *
 * - **`w` cases, end to end**: each request's path, body (a token request's form with its JWT
 *   decoded), its `authorization`, `x-goog-user-project`, `x-goog-api-key` and `content-type`, and
 *   that the user agent is pig's where upstream's is pi's; then every event and the final message.
 *   API key and ADC; a service account's JWT and a gcloud user's refresh, with their quota project;
 *   a missing credentials file, no credentials, no project; the ambient marker as the key;
 *   thinking levels and budgets; tool choice; errors.
 * - **`u` and `v` cases, the URL**: the address the SDK built for `streamGenerateContent` — the
 *   multi-regions, `global`, a custom base URL with and without a version and a project path, an
 *   endpoint resource, an API key's global host, the environment's project and location.
 *
 * Not compared: `accept`, which undici adds to every fetch (`*\/*`) and pig's Gemini providers do
 * not send.
 *
 * `fixtures/vertex/gcp` holds a service-account file and a gcloud user file made for these tests;
 * the RSA key in `sa.json` was generated for them and opens nothing.
 */
final class GoogleVertexTest extends TestCase
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
        GoogleAuth::forgetMetadataServer();
    }

    /** @return iterable<string, array{string}> */
    public static function endToEnd(): iterable
    {
        foreach (glob(self::dir() . '/w*.json') ?: [] as $path) {
            yield basename($path, '.json') => [basename($path, '.json')];
        }
    }

    /** @return iterable<string, array{string}> */
    public static function urls(): iterable
    {
        foreach ([...glob(self::dir() . '/u*.json') ?: [], ...glob(self::dir() . '/v*.json') ?: []] as $path) {
            $fixture = json_decode((string) file_get_contents($path), true);

            foreach ($fixture['upstream']['requests'] as $request) {
                if (str_contains((string) $request['originalUrl'], ':streamGenerateContent')) {
                    yield basename($path, '.json') => [basename($path, '.json')];
                }
            }
        }
    }

    #[DataProvider('endToEnd')]
    public function testPigDoesWhatUpstreamDidWithTheSameInput(string $case): void
    {
        [$scenario, $upstream] = self::fixture($case);
        [$requests, $events, $message] = $this->replay($scenario);

        self::assertSame(array_column($upstream['requests'], 'path'), array_column($requests, 'path'));

        foreach ($upstream['requests'] as $i => $expected) {
            $actual = $requests[$i];
            $token = str_ends_with(explode('?', $expected['path'])[0], '/token');

            self::assertSame($expected['body'], $token ? self::form($actual['body']) : $actual['body'], "request {$i}'s body");
            $wanted = $expected['headers'];
            $sent = array_intersect_key($actual['headers'], $wanted);
            ksort($wanted);
            ksort($sent);
            self::assertSame($wanted, $sent, "request {$i}'s headers");
            self::assertSame([], array_diff_key(
                array_intersect_key($actual['headers'], array_flip(['authorization', 'x-goog-user-project', 'x-goog-api-key', 'content-type'])),
                $expected['headers'],
            ), "request {$i} sends a header upstream does not");

            if (str_starts_with((string) $expected['userAgent'], 'pi (')) {
                self::assertStringStartsWith('pig (', $actual['headers']['user-agent'] ?? '');
            } else {
                self::assertSame($expected['userAgent'], $actual['headers']['user-agent'] ?? null);
            }
        }

        self::assertSame($upstream['events'], $events);
        self::assertEquals(self::normalize($upstream['message']), self::normalize($message));
    }

    #[DataProvider('urls')]
    public function testTheAddressIsTheOneTheSdkBuilt(string $case): void
    {
        [$scenario, $upstream] = self::fixture($case);

        foreach ($scenario['env'] ?? [] as $name => $value) {
            $this->set($name, $value);
        }

        $model = self::model($scenario['model'], $scenario['model']['baseUrl']);
        $o = $scenario['options'] ?? [];
        $options = new GoogleVertexOptions(apiKey: $o['apiKey'] ?? null, project: $o['project'] ?? null, location: $o['location'] ?? null);
        $provider = new \ReflectionClass(GoogleVertex::class);
        $static = static fn (string $method, mixed ...$arguments): mixed => $provider->getMethod($method)->invoke(null, ...$arguments);

        $apiKey = $static('resolveApiKey', $options);
        $client = $apiKey !== null
            ? ['apiKey' => $apiKey, 'project' => null, 'location' => null]
            : ['apiKey' => null, 'project' => $static('resolveProject', $options), 'location' => $static('resolveLocation', $options)];
        $params = $static('buildParams', $model, Transcript::normalizeContext(self::context($scenario)), $options);
        [$url] = $provider->getMethod('request')->invoke(new GoogleVertex(), $model, $client, $options, $params);

        $expected = array_values(array_filter(
            array_column($upstream['requests'], 'originalUrl'),
            static fn (?string $url): bool => str_contains((string) $url, ':streamGenerateContent'),
        ));

        self::assertSame($expected[0], $url);
    }

    private static function dir(): string
    {
        return __DIR__ . '/../fixtures/vertex';
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} */
    private static function fixture(string $case): array
    {
        $fixture = json_decode((string) file_get_contents(self::dir() . "/{$case}.json"), true, flags: JSON_THROW_ON_ERROR);
        $scenario = json_decode(
            str_replace('FIXTURES/', self::dir() . '/', (string) json_encode($fixture['scenario'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
            true,
        );

        return [$scenario, $fixture['upstream']];
    }

    /**
     * One scenario through pig, as `BedrockTest::replay()`; the token endpoint is the canned server's
     * `/token` and the clock the scenario's.
     *
     * @param array<string, mixed> $scenario
     * @return array{0: list<array{path: string, headers: array<string, string>, body: string}>, 1: list<array{0: string, 1: int|null, 2: string|null}>, 2: array<string, mixed>}
     */
    private function replay(array $scenario): array
    {
        foreach (array_keys(getenv()) as $name) {
            if (str_starts_with($name, 'GOOGLE_') || str_starts_with($name, 'GCLOUD') || str_starts_with($name, 'CLOUDSDK')
                || str_starts_with($name, 'GCE_METADATA') || preg_match('/proxy/i', $name) === 1) {
                $this->forget($name);
            }
        }

        $this->set('HOME', $scenario['home'] ?? '/nonexistent-home');
        $this->set('METADATA_SERVER_DETECTION', $scenario['metadataDetection'] ?? 'none');

        $replies = [];

        foreach ($scenario['responses'] as $response) {
            $pieces = [];

            foreach ($response['chunks'] ?? [] as $chunk) {
                $pieces[] = is_string($chunk) ? $chunk : 'data: ' . json_encode($chunk, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\r\n\r\n";
            }

            $head = 'HTTP/1.1 ' . ($response['status'] ?? 200) . " OK\r\n";

            foreach ($response['headers'] ?? ['content-type' => 'text/event-stream'] as $name => $value) {
                $head .= "{$name}: {$value}\r\n";
            }

            $replies[] = [$head . 'content-length: ' . strlen(implode('', $pieces)) . "\r\n\r\n" . implode('', $pieces)];
        }

        $server = new CannedServer();
        $url = rtrim($server->startSequence($replies), '/');

        foreach ($scenario['env'] ?? [] as $name => $value) {
            $this->set($name, str_replace('SERVER', $url, $value));
        }

        $model = self::model($scenario['model'], str_replace('SERVER', $url, $scenario['model']['baseUrl']));
        $context = self::context($scenario);
        $o = $scenario['options'] ?? [];
        $now = intdiv($scenario['now'] ?? 1_767_323_045_000, 1000);
        $auth = static fn (?string $keyFilename): GoogleAuth => new GoogleAuth(
            $keyFilename,
            now: static fn (): int => $now,
            tokenUrl: $url . '/token',
            sleep: static function (int $ms): void {
            },
        );

        $events = [];
        $message = null;
        Async::run(function () use ($model, $context, $o, $auth, &$events, &$message): void {
            $stream = (new GoogleVertex(auth: $auth))->stream($model, Transcript::normalizeContext($context), new GoogleVertexOptions(
                temperature: $o['temperature'] ?? null,
                maxTokens: $o['maxTokens'] ?? null,
                apiKey: $o['apiKey'] ?? null,
                thinkingEnabled: isset($o['thinking']) ? $o['thinking']['enabled'] : null,
                thinkingBudget: $o['thinking']['budgetTokens'] ?? null,
                thinkingLevel: $o['thinking']['level'] ?? null,
                toolChoice: $o['toolChoice'] ?? null,
                project: $o['project'] ?? null,
                location: $o['location'] ?? null,
            ));

            foreach ($stream as $event) {
                $events[] = [
                    BedrockTest::eventType($event),
                    property_exists($event, 'contentIndex') ? $event->contentIndex : null,
                    property_exists($event, 'delta') ? $event->delta : null,
                ];
            }

            $message = MessageJson::encode($stream->result()->await());
        });

        $requests = [];

        foreach (preg_split('/(?=(?:POST|GET|PUT) \/)/', $server->received(), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $one) {
            [$head, $body] = explode("\r\n\r\n", $one, 2) + [1 => ''];
            $lines = explode("\r\n", $head);
            $line = explode(' ', (string) array_shift($lines));
            $headers = [];

            foreach ($lines as $header) {
                [$name, $value] = explode(':', $header, 2);
                $headers[strtolower($name)] = ltrim($value);
            }

            $requests[] = ['path' => $line[1] ?? '', 'headers' => $headers, 'body' => $body];
        }

        $server->stop();

        return [$requests, $events, $message];
    }

    /** @param array<string, mixed> $m */
    private static function model(array $m, string $baseUrl): Model
    {
        return new Model(
            $m['id'],
            $m['name'],
            Api::GoogleVertex,
            $m['provider'],
            $baseUrl,
            $m['contextWindow'],
            $m['maxTokens'],
            $m['reasoning'],
            $m['input'],
            new Pricing($m['cost']['input'], $m['cost']['output'], $m['cost']['cacheRead'], $m['cost']['cacheWrite']),
            thinkingLevelMap: $m['thinkingLevelMap'] ?? [],
        );
    }

    /** @param array<string, mixed> $scenario */
    private static function context(array $scenario): Context
    {
        return new Context(
            array_map(
                static fn (array $entry): mixed => MessageJson::decode(is_string($entry['content'] ?? null) ? [...$entry, 'content' => [['type' => 'text', 'text' => $entry['content']]]] : $entry),
                $scenario['context']['messages'],
            ),
            $scenario['context']['systemPrompt'] ?? null,
            array_map(static fn (array $tool): mixed => MessageJson::decodeTool($tool), $scenario['context']['tools'] ?? []),
        );
    }

    /**
     * A token request's form, the JWT's header and claims decoded — its signature is RS256 over them
     * with the fixture's key, and the same claims are the same signature.
     *
     * @return array<string, mixed>
     */
    private static function form(string $body): array
    {
        parse_str($body, $fields);

        if (isset($fields['assertion']) && is_string($fields['assertion'])) {
            [$header, $payload] = explode('.', $fields['assertion']);
            $decode = static fn (string $part): mixed => json_decode((string) base64_decode(strtr($part, '-_', '+/'), true), true);
            $fields['assertion'] = ['header' => $decode($header), 'payload' => $decode($payload)];
        }

        return $fields;
    }

    /**
     * @param array<string, mixed> $message
     * @return array<string, mixed>
     */
    private static function normalize(array $message): array
    {
        $message = self::withoutNulls($message);
        unset($message['durationMs'], $message['timestamp']);

        // A call Gemini sends without an id is given a fresh one, on both sides.
        $message['content'] = array_map(static function (array $block): array {
            if (($block['type'] ?? null) === 'toolCall') {
                unset($block['id']);
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
