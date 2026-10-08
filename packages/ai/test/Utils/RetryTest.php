<?php

declare(strict_types=1);

namespace Pig\Ai\Test\Utils;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pig\Ai\Api;
use Pig\Ai\AssistantMessage;
use Pig\Ai\StopReason;
use Pig\Ai\TextContent;
use Pig\Ai\Usage;
use Pig\Ai\Utils\Retry;
use Pig\Async\AbortController;
use Pig\Async\Async;
use Pig\Async\Loop;

/**
 * Upstream's `utils/retry.ts`: a failed turn is retried when its error **text** matches upstream's
 * retryable patterns and none of its provider-limit ones, and the wait doubles from the base up to
 * the cap.
 *
 * pig used to read an HTTP status out of the message first, keep a word list of its own, wait out a
 * delay the provider stated, and refuse a stated delay over a minute; upstream does none of that,
 * so neither does pig any more.
 */
final class RetryTest extends TestCase
{
    private static function failed(string $error, StopReason $reason = StopReason::Error): AssistantMessage
    {
        return new AssistantMessage([new TextContent('')], Api::AnthropicMessages, 'anthropic', 'claude-test', new Usage(), $reason, $error);
    }

    /** @return iterable<string, array{0: string}> one message per upstream pattern family */
    public static function retryable(): iterable
    {
        yield 'anthropic overloaded' => ['529 {"type":"error","error":{"type":"overloaded_error","message":"Overloaded"}}'];
        yield 'rate limit words' => ['Rate limit reached for requests'];
        yield 'too many requests' => ['Too Many Requests'];
        yield 'a 503 anywhere' => ['OpenAI API error (503): upstream'];
        yield 'a 520' => ['520: cloudflare'];
        yield 'service unavailable' => ['Service Unavailable'];
        yield 'internal error' => ['internal_error'];
        yield 'openrouter wrapper' => ['Provider returned error'];
        yield 'fetch failed' => ['fetch failed'];
        yield 'sdk connection error' => ['Connection error.'];
        yield 'sdk timeout' => ['Request timed out.'];
        yield 'connection refused' => ['Cannot connect to api.example.com:443: connection refused'];
        yield 'socket hang up' => ['socket hang up'];
        yield 'anthropic stream ended' => ['Anthropic stream ended before message_stop'];
        yield 'ended without' => ['Mistral stream ended without a finish reason'];
        yield 'responses terminal' => ['OpenAI Responses stream ended before a terminal response event'];
        yield 'retry delay cap' => ['Server requested 120s retry delay (max: 60s). 429 rate limited'];
        yield 'retry guidance' => ['An error occurred. You can retry your request.'];
        yield 'grpc' => ['ResourceExhausted: try later'];
        yield 'chatgpt usage unavailable' => ['subscription_sharing_usage_unavailable'];
        yield 'antigravity throttling' => ['Rate limited by Antigravity (429 ResourceExhausted). Next: retrying automatically; if it persists, switch models.'];
    }

    #[DataProvider('retryable')]
    public function testUpstreamsRetryablePatternsAreRetried(string $error): void
    {
        $this->assertTrue(Retry::isRetryableAssistantError(self::failed($error)));
    }

    /** @return iterable<string, array{0: string}> */
    public static function notRetryable(): iterable
    {
        // The provider-limit list wins over the retryable one: each of these also says 429.
        yield 'openai quota' => ['429: {"error":{"code":"insufficient_quota"}}'];
        yield 'opencode go' => ['429 GoUsageLimitError'];
        yield 'monthly' => ['429 Monthly usage limit reached'];
        yield 'billing' => ['429 check your billing details'];
        yield 'chatgpt limit' => ['429 subscription_sharing_usage_limit_exceeded'];
        // Nothing transient in it.
        yield 'a 400' => ['400 {"type":"error","error":{"type":"invalid_request_error","message":"bad"}}'];
        yield 'a 401' => ['401 {"type":"error","error":{"type":"authentication_error","message":"invalid x-api-key"}}'];
        yield 'antigravity quota wall' => ['Quota reached. Please wait 2h3m. Next: switch models or retry later.'];
        yield 'gemini safety' => ['Provider stopped with: SAFETY'];
    }

    #[DataProvider('notRetryable')]
    public function testWhatUpstreamDoesNotRetryIsNotRetried(string $error): void
    {
        $this->assertFalse(Retry::isRetryableAssistantError(self::failed($error)));
    }

    public function testOnlyAnErrorWithAMessageIsClassified(): void
    {
        $this->assertFalse(Retry::isRetryableAssistantError(self::failed('503 overloaded', StopReason::Aborted)));
        $this->assertFalse(Retry::isRetryableAssistantError(self::failed('')));
        $this->assertFalse(Retry::isRetryableAssistantError(
            new AssistantMessage([new TextContent('fine')], Api::AnthropicMessages, 'anthropic', 'm', new Usage(), StopReason::Stop),
        ));
    }

    public function testAStatedWaitIsNoLongerReadOrRefused(): void
    {
        // pig used to wait `Please retry in 39s` out exactly, and refuse to wait at all past a
        // minute; upstream's session retry knows nothing of it — the doubling decides — and a
        // provider-level wait over `maxRetryDelayMs` is refused one level down, as "retry delay".
        $this->assertTrue(Retry::isRetryableAssistantError(self::failed('429 Your quota will reset after 18h31m10s')));
    }

    public function testTheDelayDoublesFromTheBaseUpToTheCap(): void
    {
        $this->assertSame(2000.0, Retry::retryDelayMs(2000, 1));
        $this->assertSame(4000.0, Retry::retryDelayMs(2000, 2));
        $this->assertSame(8000.0, Retry::retryDelayMs(2000, 3));
        // `Math.max(0, attempt - 1)`.
        $this->assertSame(2000.0, Retry::retryDelayMs(2000, 0));
        // `maxAgentDelayMs`, 60 s when unset.
        $this->assertSame(60_000.0, Retry::retryDelayMs(2000, 10));
        $this->assertSame(5000.0, Retry::retryDelayMs(2000, 3, 5000));
        // A delay that is not a safe integer counts as `Number.MAX_SAFE_INTEGER` before the cap.
        $this->assertSame((float) 9_007_199_254_740_991, Retry::retryDelayMs(1e300, 1, 1e300));
        $this->assertSame((float) 9_007_199_254_740_991, Retry::retryDelayMs(1.5, 1, 1e300));
    }

    public function testRetryAssistantCallRetriesATransientErrorAndReportsEachStep(): void
    {
        Loop::reset();
        $responses = [self::failed('overloaded_error'), self::failed('fetch failed'), self::done()];
        $log = [];

        // Not an arrow function: that would capture `$log` by value, and the callbacks write to it.
        $result = Async::run(static function () use (&$responses, &$log): AssistantMessage {
            return Retry::retryAssistantCall(
                static function () use (&$responses, &$log): AssistantMessage {
                    $log[] = 'call';

                    return array_shift($responses);
                },
                ['enabled' => true, 'maxRetries' => 3, 'baseDelayMs' => 1.0],
                null,
                [
                    'onRetryScheduled' => static function (int $attempt, int $max, float $delayMs, string $error) use (&$log): void {
                        $log[] = "scheduled {$attempt}/{$max} {$delayMs} {$error}";
                    },
                    'onRetryAttemptStart' => static function () use (&$log): void {
                        $log[] = 'start';
                    },
                    'onRetryFinished' => static function (bool $success, int $attempt, ?string $error) use (&$log): void {
                        $log[] = 'finished ' . ($success ? 'ok' : 'failed') . " {$attempt}";
                    },
                ],
            );
        });

        $this->assertSame(StopReason::Stop, $result->stopReason);
        $this->assertSame(['call', 'scheduled 1/3 1 overloaded_error', 'start', 'call', 'scheduled 2/3 2 fetch failed', 'start', 'call', 'finished ok 2'], $log);
    }

    public function testRetryAssistantCallReturnsADeterministicErrorOrADisabledPolicysFirstAnswerAtOnce(): void
    {
        Loop::reset();
        $calls = 0;
        $produce = static function () use (&$calls): AssistantMessage {
            $calls++;

            return self::failed($calls === 1 ? 'invalid_request_error: bad schema' : 'overloaded_error');
        };

        $result = Async::run(static fn (): AssistantMessage => Retry::retryAssistantCall($produce, ['enabled' => true, 'maxRetries' => 3, 'baseDelayMs' => 1.0], null));
        $this->assertSame('invalid_request_error: bad schema', $result->errorMessage);
        $this->assertSame(1, $calls);

        $result = Async::run(static fn (): AssistantMessage => Retry::retryAssistantCall($produce, ['enabled' => false, 'maxRetries' => 3, 'baseDelayMs' => 1.0], null));
        $this->assertSame('overloaded_error', $result->errorMessage);
        $this->assertSame(2, $calls, 'no policy, no retry');

        $result = Async::run(static fn (): AssistantMessage => Retry::retryAssistantCall($produce, ['enabled' => true, 'maxRetries' => 1, 'baseDelayMs' => 1.0], null));
        $this->assertSame(4, $calls, 'one retry, then the budget is spent');
        $this->assertSame(StopReason::Error, $result->stopReason);
    }

    public function testAnAbortDuringTheBackoffComesBackAsAnAbortedMessage(): void
    {
        Loop::reset();
        $finished = null;
        $controller = new AbortController();

        $result = Async::run(static function () use ($controller, &$finished): AssistantMessage {
            Loop::get()->delay(0.02, static fn () => $controller->abort());

            return Retry::retryAssistantCall(
                    static fn (): AssistantMessage => self::failed('overloaded_error'),
                    ['enabled' => true, 'maxRetries' => 3, 'baseDelayMs' => 10_000.0],
                    $controller->signal,
                    ['onRetryFinished' => static function (bool $success, int $attempt, ?string $error) use (&$finished): void {
                        $finished = [$success, $attempt, $error];
                    }],
            );
        });

        $this->assertSame(StopReason::Aborted, $result->stopReason);
        $this->assertNull($result->errorMessage);
        $this->assertSame([false, 1, 'overloaded_error'], $finished);
    }

    private static function done(): AssistantMessage
    {
        return new AssistantMessage([new TextContent('summary')], Api::AnthropicMessages, 'anthropic', 'claude-test', new Usage(), StopReason::Stop);
    }
}
