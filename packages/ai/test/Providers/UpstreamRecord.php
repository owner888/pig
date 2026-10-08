<?php

declare(strict_types=1);

namespace Pig\Ai\Test\Providers;

use Closure;
use Pig\Ai\Api;
use Pig\Ai\Context;
use Pig\Ai\Model;
use Pig\Ai\OpenAiCompat;
use Pig\Ai\Pricing;
use Pig\Ai\Utils\MessageJson;
use Pig\Ai\Utils\AssistantMessageEventStream;
use Pig\Async\Async;
use Pig\Test\CannedServer;

/**
 * The replay half of the Azure, Codex and pi-messages tests: a scenario served the way upstream was
 * served it, and pig's requests, events and final message read back in the record's shape.
 *
 * Each `fixtures/{azure,codex,pi-messages}/<case>.json` is `{scenario, upstream}`: the scenario
 * upstream's `src` (at the developer's reference commit, with its lockfile's `openai` SDK) was run
 * through under `node --experimental-strip-types`, against a canned local server, and what it sent
 * and emitted. A mismatch is pig's bug unless the test says why not.
 */
final class UpstreamRecord
{
    /** Upstream's frozen clock in the recording (`Date.UTC(2026, 0, 2, 3, 4, 5)`). */
    public const int RECORDED_NOW_MS = 1_767_323_045_000;

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} the scenario and upstream's record */
    public static function fixture(string $family, string $case): array
    {
        $fixture = json_decode((string) file_get_contents(self::dir($family) . "/{$case}.json"), true, flags: JSON_THROW_ON_ERROR);

        return [$fixture['scenario'], $fixture['upstream']];
    }

    /** @return iterable<string, array{string}> */
    public static function cases(string $family): iterable
    {
        foreach (glob(self::dir($family) . '/*.json') ?: [] as $path) {
            yield basename($path, '.json') => [basename($path, '.json')];
        }
    }

    private static function dir(string $family): string
    {
        return __DIR__ . '/../fixtures/' . $family;
    }

    /**
     * The scenario's model, its compat in pig's names.
     *
     * @param array<string, mixed> $m
     */
    public static function model(array $m, string $server): Model
    {
        $compat = $m['compat'] ?? [];
        $flag = static fn (string $key): ?bool => is_bool($compat[$key] ?? null) ? $compat[$key] : null;

        return new Model(
            $m['id'],
            $m['name'],
            Api::from($m['api']),
            $m['provider'],
            str_replace('SERVER', $server, $m['baseUrl']),
            $m['contextWindow'],
            $m['maxTokens'],
            $m['reasoning'],
            $m['input'],
            new Pricing($m['cost']['input'], $m['cost']['output'], $m['cost']['cacheRead'], $m['cost']['cacheWrite']),
            compat: $compat === [] ? null : new OpenAiCompat(
                developerRole: $flag('supportsDeveloperRole'),
                reasoningContentOnAssistantMessages: $flag('requiresReasoningContentOnAssistantMessages'),
                strictMode: $flag('supportsStrictMode'),
                thinkingFormat: is_string($compat['thinkingFormat'] ?? null) ? $compat['thinkingFormat'] : null,
                grammarTools: $flag('supportsOpenAIGrammarTools'),
                supportsLongCacheRetention: $flag('supportsLongCacheRetention'),
                supportsMidConvoSystemMessages: $flag('supportsMidConvoSystemMessages'),
                supportsToolSearch: $flag('supportsToolSearch'),
                supportsAdditionalTools: $flag('supportsAdditionalTools'),
            ),
            thinkingLevelMap: $m['thinkingLevelMap'] ?? [],
        );
    }

    /** @param array<string, mixed> $scenario */
    public static function context(array $scenario): Context
    {
        return new Context(
            array_map(
                static fn (array $entry): mixed => MessageJson::decode(is_string($entry['content'] ?? null) && ($entry['role'] ?? null) !== 'system'
                    ? [...$entry, 'content' => [['type' => 'text', 'text' => $entry['content']]]]
                    : $entry),
                $scenario['context']['messages'],
            ),
            $scenario['context']['systemPrompt'] ?? null,
            array_map(static fn (array $tool): mixed => MessageJson::decodeTool($tool), $scenario['context']['tools'] ?? []),
        );
    }

    /**
     * Serve the scenario's responses, one connection each, run `$start` against the server, and read
     * back what pig sent and emitted.
     *
     * @param array<string, mixed> $scenario
     * @param Closure(string): AssistantMessageEventStream $start given the server's base URL
     * @param (Closure(array<string, mixed>): array<string, mixed>)|null $response a response's last change before it is served
     * @return array{0: list<array{method: string, path: string, headers: array<string, string>, body: string}>, 1: list<array{0: string, 1: int|null, 2: string|null}>, 2: array<string, mixed>|null}
     */
    public static function replay(array $scenario, Closure $start, ?Closure $response = null): array
    {
        $replies = [];

        foreach ($scenario['responses'] as $reply) {
            $reply = $response !== null ? $response($reply) : $reply;
            $pieces = [];

            foreach ($reply['body'] ?? [] as $piece) {
                $pieces[] = $piece;
            }

            foreach ($reply['chunks'] ?? [] as $chunk) {
                $pieces[] = is_string($chunk) ? $chunk : 'data: ' . json_encode($chunk, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n\n";
            }

            $status = $reply['status'] ?? 200;
            $head = "HTTP/1.1 {$status} " . self::reason($status) . "\r\n";

            foreach ($reply['headers'] ?? ['content-type' => 'text/event-stream'] as $name => $value) {
                $head .= "{$name}: {$value}\r\n";
            }

            $replies[] = [$head . 'content-length: ' . strlen(implode('', $pieces)) . "\r\n\r\n", ...$pieces];
        }

        $server = new CannedServer();
        $url = rtrim($server->startSequence($replies), '/');
        $events = [];
        $message = null;

        Async::run(function () use ($start, $url, &$events, &$message): void {
            $stream = $start($url);

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

            $requests[] = ['method' => $line[0], 'path' => $line[1] ?? '', 'headers' => $headers, 'body' => $body];
        }

        $server->stop();

        return [$requests, $events, $message];
    }

    /** The reason phrase Node's `http` server writes, which upstream's `statusText` reads. */
    private static function reason(int $status): string
    {
        return match ($status) {
            200 => 'OK',
            400 => 'Bad Request',
            402 => 'Payment Required',
            429 => 'Too Many Requests',
            500 => 'Internal Server Error',
            502 => 'Bad Gateway',
            503 => 'Service Unavailable',
            default => 'Unknown',
        };
    }

    /**
     * A final message as both sides can be compared: nulls dropped (pig writes `errorMessage: null`
     * where `JSON.stringify` leaves an undefined one out), the clock's fields, a stack trace and the
     * canned server's port gone, costs rounded where JavaScript and PHP float arithmetic part at the last digit.
     *
     * @param array<string, mixed> $message
     * @return array<string, mixed>
     */
    public static function normalize(array $message): array
    {
        $message = self::withoutNulls($message);
        unset($message['timestamp']);

        foreach ($message['diagnostics'] ?? [] as $i => $diagnostic) {
            unset($message['diagnostics'][$i]['timestamp'], $message['diagnostics'][$i]['error']['stack'], $message['diagnostics'][$i]['details']['timestampMs']);

            // Each side's canned server had its own port.
            if (is_string($diagnostic['details']['url'] ?? null)) {
                $message['diagnostics'][$i]['details']['url'] = (string) preg_replace('#^http://127\.0\.0\.1:\d+#', 'SERVER', $diagnostic['details']['url']);
            }
        }

        if (isset($message['usage']['cost'])) {
            $message['usage']['cost'] = array_map(static fn (int|float $value): float => round((float) $value, 12), $message['usage']['cost']);
        }

        return $message;
    }

    public static function withoutNulls(mixed $value): mixed
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
}
