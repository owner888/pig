<?php

declare(strict_types=1);

namespace Pig\Ai\Test\Providers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pig\Ai\Providers\OpenAiCodexResponses;
use Pig\Ai\Providers\OpenAiCodexResponsesOptions;
use Pig\Ai\ProviderError;
use Pig\Ai\ReasoningEffort;
use Pig\Ai\SimpleStreamOptions;
use Pig\Ai\Stream;
use Pig\Ai\Timestamp;
use Pig\Async\Loop;

/**
 * ChatGPT's Codex backend, replayed against what upstream did with the same input (see
 * `UpstreamRecord`).
 *
 * `fixtures/codex/c*.json`, every one with `transport: "sse"` and `node:zlib`'s `zstdCompressSync`
 * taken away — the branch pig is, since it has no WebSocket client and no zstd (see
 * `OpenAiCodexResponses`): a replayed conversation, `instructions`, the account header, the session
 * headers and `prompt_cache_key`; the options' service tier priced by Codex's rule, verbosity, tool
 * choice and summary; `response.done` with `end_turn`; an `error` event, `response.failed`, a frame
 * that is not JSON, a token with no account; a 429 usage limit worded as one; a 503 retried after
 * `retry-after-ms` and a 400 retried as a "network" error; a terminal quota message and a server
 * delay past the cap, neither retried; `[DONE]` skipped, an unknown status read as none, a frame the
 * body's end closes; `reasoningEffort: "none"`; an `incomplete` answer.
 *
 * The recording's clock is frozen; `c4`'s `resets_at` is moved by the difference so the minutes
 * left come out the same.
 */
final class OpenAiCodexResponsesTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $saved = [];

    #[\Override]
    protected function setUp(): void
    {
        Loop::reset();

        foreach (array_keys(getenv()) as $name) {
            if (str_starts_with($name, 'OPENAI') || str_starts_with($name, 'PI_') || preg_match('/proxy/i', $name) === 1) {
                $this->saved[$name] = getenv($name);
                putenv($name);
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
        return UpstreamRecord::cases('codex');
    }

    #[DataProvider('cases')]
    public function testPigDoesWhatUpstreamDidWithTheSameInput(string $case): void
    {
        [$scenario, $upstream] = UpstreamRecord::fixture('codex', $case);
        $shift = intdiv(Timestamp::nowMs() - UpstreamRecord::RECORDED_NOW_MS, 1000);

        [$requests, $events, $message] = UpstreamRecord::replay(
            $scenario,
            static function (string $server) use ($scenario) {
                $model = UpstreamRecord::model($scenario['model'], $server);
                $context = UpstreamRecord::context($scenario);
                $o = $scenario['options'];

                if ($scenario['simple']) {
                    return Stream::simple($model, $context, new SimpleStreamOptions(
                        maxTokens: $o['maxTokens'] ?? null,
                        apiKey: $o['apiKey'] ?? null,
                        reasoning: isset($o['reasoning']) ? ReasoningEffort::from($o['reasoning']) : null,
                        cacheRetention: $o['cacheRetention'] ?? null,
                        sessionId: $o['sessionId'] ?? null,
                        maxRetries: $o['maxRetries'] ?? null,
                        maxRetryDelayMs: $o['maxRetryDelayMs'] ?? null,
                    ));
                }

                return Stream::start($model, $context, new OpenAiCodexResponsesOptions(
                    temperature: $o['temperature'] ?? null,
                    apiKey: $o['apiKey'] ?? null,
                    sessionId: $o['sessionId'] ?? null,
                    headers: $o['headers'] ?? null,
                    reasoningEffort: $o['reasoningEffort'] ?? null,
                    reasoningSummary: $o['reasoningSummary'] ?? null,
                    serviceTier: $o['serviceTier'] ?? null,
                    textVerbosity: $o['textVerbosity'] ?? null,
                    toolChoice: $o['toolChoice'] ?? null,
                ));
            },
            static function (array $response) use ($shift): array {
                foreach ($response['chunks'] ?? [] as $i => $chunk) {
                    $decoded = is_string($chunk) ? json_decode($chunk, true) : null;

                    if (is_array($decoded) && isset($decoded['error']['resets_at'])) {
                        $decoded['error']['resets_at'] += $shift;
                        $response['chunks'][$i] = json_encode($decoded);
                    }
                }

                return $response;
            },
        );

        self::assertSame(array_column($upstream['requests'], 'path'), array_column($requests, 'path'));

        foreach ($upstream['requests'] as $i => $expected) {
            $actual = $requests[$i];
            self::assertSame($expected['method'], $actual['method']);
            self::assertSame($expected['body'], $actual['body'], "request {$i}'s body");
            $wanted = $expected['headers'];
            $sent = array_intersect_key($actual['headers'], array_flip(['authorization', 'chatgpt-account-id', 'originator', 'openai-beta', 'accept', 'content-type', 'content-encoding', 'session-id', 'x-client-request-id', 'x-extra']));
            ksort($wanted);
            ksort($sent);
            self::assertSame($wanted, $sent, "request {$i}'s headers");
            self::assertStringStartsWith('pig (', $actual['headers']['user-agent'] ?? '');
        }

        self::assertSame($upstream['events'], $events);
        self::assertEquals(UpstreamRecord::normalize($upstream['message']), UpstreamRecord::normalize((array) $message));
    }

    public function testTheUrlIsTheBackendsResponsesPath(): void
    {
        self::assertSame('https://chatgpt.com/backend-api/codex/responses', OpenAiCodexResponses::resolveCodexUrl(null));
        self::assertSame('https://chatgpt.com/backend-api/codex/responses', OpenAiCodexResponses::resolveCodexUrl('  '));
        self::assertSame('https://x.test/codex/responses', OpenAiCodexResponses::resolveCodexUrl('https://x.test//'));
        self::assertSame('https://x.test/codex/responses', OpenAiCodexResponses::resolveCodexUrl('https://x.test/codex'));
        self::assertSame('https://x.test/codex/responses', OpenAiCodexResponses::resolveCodexUrl('https://x.test/codex/responses/'));
    }

    public function testTheAccountIsReadFromTheTokensClaimsInStandardBase64Only(): void
    {
        $claims = base64_encode((string) json_encode(['https://api.openai.com/auth' => ['chatgpt_account_id' => 'acc_9']]));

        self::assertSame('acc_9', OpenAiCodexResponses::extractAccountId('h.' . rtrim($claims, '=') . '.s'));

        // `atob()` refuses the base64url alphabet, so a payload carrying `-` or `_` has no account.
        $this->expectException(ProviderError::class);
        $this->expectExceptionMessage('Failed to extract accountId from token');
        OpenAiCodexResponses::extractAccountId('h.' . strtr($claims, '+/', '-_') . '-_.s');
    }

    public function testTheSimpleArmNeedsAKeyBeforeAnythingIsSent(): void
    {
        [$scenario] = UpstreamRecord::fixture('codex', 'c1');

        $this->expectException(ProviderError::class);
        $this->expectExceptionMessage('No API key for provider: openai-codex');

        Stream::simple(UpstreamRecord::model($scenario['model'], ''), UpstreamRecord::context($scenario));
    }
}
