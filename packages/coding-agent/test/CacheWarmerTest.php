<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pig\Ai\AnthropicCompat;
use Pig\Ai\Api;
use Pig\Ai\AssistantMessage;
use Pig\Ai\DoneEvent;
use Pig\Ai\Model;
use Pig\Ai\Pricing;
use Pig\Ai\ReasoningEffort;
use Pig\Ai\SimpleStreamOptions;
use Pig\Ai\StopReason;
use Pig\Ai\TextContent;
use Pig\Ai\TranscriptContext;
use Pig\Ai\Usage;
use Pig\Ai\Utils\AssistantMessageEventStream;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\CodingAgent\Hooks\Events\CacheWarmingDecisionEvent;
use Pig\CodingAgent\Session\CacheWarmer;
use Pig\CodingAgent\Session\CacheWarmingDecision;
use Pig\CodingAgent\Session\CacheWarmingStatus;
use Pig\CodingAgent\Session\UsageEntry;

/**
 * Upstream's `cache-warmer.ts`. The model's cache lives 10.1 seconds, so the refresh is due 100ms
 * after the request — the rule is 90% of the TTL, but never closer than ten seconds to the end.
 */
final class CacheWarmerTest extends TestCase
{
    /** @var list<SimpleStreamOptions> what each refresh was sent with */
    private array $sent = [];

    /** @var list<array{string, string, string, Usage, ?string}> */
    private array $recorded = [];

    private string $mode = 'streaming';

    private bool $current = true;

    private int $promptTokens = 100_000;

    /** @var Closure(CacheWarmingDecisionEvent): string */
    private Closure $decide;

    /** @var list<CacheWarmingDecisionEvent> */
    private array $asked = [];

    protected function setUp(): void
    {
        Loop::reset();
        $this->decide = static fn (CacheWarmingDecisionEvent $event): string => $event->action;
    }

    protected function tearDown(): void
    {
        Loop::reset();
    }

    /** @return array<string, array{int, ?int}> */
    public static function lifetimes(): array
    {
        return [
            'five minutes' => [300_000, 270_000],
            'an hour' => [3_600_000, 3_240_000],
            'just over ten seconds' => [10_100, 100],
            'ten seconds is no margin at all' => [10_000, null],
        ];
    }

    #[DataProvider('lifetimes')]
    public function testTheRefreshIsDueAtNinetyPercentButNeverInTheLastTenSeconds(int $ttlMs, ?int $delayMs): void
    {
        $this->assertSame($delayMs, CacheWarmer::delayMs($ttlMs));
    }

    public function testTheLifetimeIsTheModelsTierForTheRetentionTheRequestUsed(): void
    {
        $model = self::model(promptCache: ['short' => 300, 'long' => 3600]);

        $this->assertSame(300_000, CacheWarmer::ttlMs($model, new SimpleStreamOptions(cacheRetention: 'short')));
        $this->assertSame(3_600_000, CacheWarmer::ttlMs($model, new SimpleStreamOptions(cacheRetention: 'long')));
        $this->assertNull(CacheWarmer::ttlMs($model, new SimpleStreamOptions(cacheRetention: 'none')));
        $this->assertNull(CacheWarmer::ttlMs(self::model(promptCache: null), new SimpleStreamOptions(cacheRetention: 'short')));
    }

    public function testBudgetThinkingOnAnthropicCannotBeReplayedAndAdaptiveThinkingCan(): void
    {
        $thinking = new SimpleStreamOptions(reasoning: ReasoningEffort::High);

        $this->assertTrue(CacheWarmer::isReplayable(self::model(), new SimpleStreamOptions()));
        $this->assertFalse(CacheWarmer::isReplayable(self::model(), $thinking));
        $this->assertTrue(CacheWarmer::isReplayable(self::model(compat: new AnthropicCompat(forceAdaptiveThinking: true)), $thinking));
        $this->assertTrue(CacheWarmer::isReplayable(self::model(api: Api::OpenAiResponses), $thinking));
    }

    public function testARefreshReplaysTheRequestForOneTokenAndIsWrittenDown(): void
    {
        $warmer = $this->warmer();

        Async::run(function () use ($warmer): void {
            $warmer->start(self::model(), new TranscriptContext([]), new SimpleStreamOptions(sessionId: 'abc', maxTokens: 8000), fn (): bool => $this->current);
            $this->assertSame('scheduled', $warmer->status()->state);
            Async::delay(0.35);
            $warmer->cancel();
        });

        $this->assertGreaterThanOrEqual(2, count($this->sent), 'a refresh schedules the next one');
        $this->assertSame(1, $this->sent[0]->maxTokens);
        $this->assertSame(0, $this->sent[0]->maxRetries);
        $this->assertSame('abc', $this->sent[0]->sessionId, 'the same request, so the same cache entry');
        $this->assertNotNull($this->sent[0]->signal);
        $this->assertSame('cache_warm', $this->recorded[0][0]);
        $this->assertSame('anthropic', $this->recorded[0][1]);
        $this->assertSame(1, $this->recorded[0][3]->output);
        $this->assertNull($this->recorded[0][4]);

        // A refresh already in flight finishes; nothing schedules another.
        $sent = count($this->sent);
        $started = microtime(true);
        Loop::get()->run();
        $this->assertSame($sent, count($this->sent));
        $this->assertLessThan(0.08, microtime(true) - $started, 'a cancelled warmer leaves no timer behind');
    }

    public function testStreamingModeStopsWhenTheRunSettles(): void
    {
        $warmer = $this->warmer();

        Async::run(function () use ($warmer): void {
            $warmer->start(self::model(), new TranscriptContext([]), new SimpleStreamOptions(), fn (): bool => true);
            $warmer->onAgentSettled();
            Async::delay(0.25);
        });

        $this->assertSame([], $this->sent);
        $this->assertSame('Inactive (agent run settled)', CacheWarmer::formatStatus($warmer->status()));
    }

    public function testIdleModeKeepsGoingAfterTheRunOnlyWhileItPays(): void
    {
        $this->mode = 'idle';
        $this->promptTokens = 1_000_000;
        $warmer = $this->warmer();

        Async::run(function () use ($warmer): void {
            $warmer->start(self::model(), new TranscriptContext([]), new SimpleStreamOptions(), fn (): bool => true);
            $warmer->onAgentSettled();
            Async::delay(0.25);
            $warmer->cancel();
        });

        // 15% of a $3.45 miss is worth a $0.30 refresh.
        $this->assertNotSame([], $this->sent);
        $this->assertSame(0.15, $this->asked[0]->continuationProbability);
        $this->assertSame('warm', $this->asked[0]->action);

        Loop::reset();
        $this->sent = [];
        $this->promptTokens = 100_000;
        $warmer = $this->warmer();

        Async::run(function () use ($warmer): void {
            $warmer->start(self::model(), new TranscriptContext([]), new SimpleStreamOptions(), fn (): bool => true);
            $warmer->onAgentSettled();
            Async::delay(0.25);
        });

        // A tenth of the prompt: 15% of $0.345 does not cover $0.03.
        $this->assertSame([], $this->sent);
        $status = $warmer->status();
        $this->assertSame('inactive', $status->state);
        $this->assertSame('expected savings below threshold', $status->reason);
        $this->assertStringStartsWith('Stopped (15% continuation probability, expected savings', CacheWarmer::formatStatus($status));
    }

    public function testAHookThatSaysStopStopsItAndTheStatusSaysWho(): void
    {
        $this->decide = static fn (CacheWarmingDecisionEvent $event): string => 'stop';
        $warmer = $this->warmer();

        Async::run(function () use ($warmer): void {
            $warmer->start(self::model(), new TranscriptContext([]), new SimpleStreamOptions(), fn (): bool => true);
            Async::delay(0.25);
        });

        $this->assertSame([], $this->sent);
        $status = $warmer->status();
        $this->assertSame('stopped by extension', $status->reason);
        $this->assertTrue($status->extensionOverride);
        $this->assertStringStartsWith('Stopped (extension override, 100% continuation probability while agent is running', CacheWarmer::formatStatus($status));
    }

    public function testAHookThatSaysWarmAgainstPigsAnswerIsNotedOnTheEntry(): void
    {
        $this->promptTokens = 1_000;
        $this->decide = static fn (CacheWarmingDecisionEvent $event): string => 'warm';
        $warmer = $this->warmer();

        Async::run(function () use ($warmer): void {
            $warmer->start(self::model(), new TranscriptContext([]), new SimpleStreamOptions(), fn (): bool => true);
            Async::delay(0.18);
            $warmer->cancel();
        });

        $this->assertSame('stop', $this->asked[0]->action, 'pig alone would not have paid for this one');
        $this->assertSame('extension override', $this->recorded[0][4]);
    }

    public function testNothingIsScheduledWithTheModeOff(): void
    {
        $this->mode = 'off';
        $warmer = $this->warmer();

        $warmer->start(self::model(), new TranscriptContext([]), new SimpleStreamOptions(), fn (): bool => true);

        $this->assertTrue(Loop::get()->isIdle());
        $this->assertSame('cache warming disabled', $warmer->status()->reason);
    }

    public function testTurningTheModeOffMidRunStopsIt(): void
    {
        $warmer = $this->warmer();

        $warmer->start(self::model(), new TranscriptContext([]), new SimpleStreamOptions(), fn (): bool => true);
        $this->mode = 'off';
        $warmer->onModeChanged();

        $this->assertTrue(Loop::get()->isIdle());
    }

    public function testAConversationThatMovedOnIsNotWarmed(): void
    {
        $warmer = $this->warmer();

        Async::run(function () use ($warmer): void {
            $warmer->start(self::model(), new TranscriptContext([]), new SimpleStreamOptions(), fn (): bool => $this->current);
            $this->current = false;
            Async::delay(0.25);
        });

        $this->assertSame([], $this->sent);
        $this->assertSame('conversation context changed', $warmer->status()->reason);
    }

    public function testARequestThatCannotBeReplayedIsNotWarmed(): void
    {
        $warmer = $this->warmer();

        $warmer->start(self::model(), new TranscriptContext([]), new SimpleStreamOptions(reasoning: ReasoningEffort::Low), fn (): bool => true);

        $this->assertTrue(Loop::get()->isIdle());
        $this->assertSame('Inactive (request cannot be replayed safely)', CacheWarmer::formatStatus($warmer->status()));
    }

    public function testTheScheduledLineSaysWhenAndWhyInUpstreamsWords(): void
    {
        $decision = new CacheWarmingDecision('streaming', 0.03, 0.345, 1.0, 0.315, true, 'warm');
        $status = new CacheWarmingStatus('scheduled', null, 1_000_000 + 270_000, $decision);

        $this->assertSame(
            'Decision in 4m 30s (100% continuation probability while agent is running, expected savings $0.315 >= $0.050 -> warm)',
            CacheWarmer::formatStatus($status, 1_000_000),
        );
        $this->assertSame('Inactive (waiting for first request)', CacheWarmer::formatStatus($this->warmer()->status()));
    }

    private function warmer(): CacheWarmer
    {
        return new CacheWarmer(
            function (Model $model, TranscriptContext $context, SimpleStreamOptions $options): AssistantMessageEventStream {
                $this->sent[] = $options;
                $stream = new AssistantMessageEventStream();
                $message = new AssistantMessage(
                    [new TextContent('.')],
                    $model->api,
                    $model->provider,
                    $model->id,
                    (new Usage(output: 1, cacheRead: $this->promptTokens))->withCost($model),
                    StopReason::Length,
                );

                Async::spawn(static function () use ($stream, $message): void {
                    $stream->push(new DoneEvent(StopReason::Length, $message));
                    $stream->end();
                });

                return $stream;
            },
            fn (): string => $this->mode,
            function (CacheWarmingDecisionEvent $event): string {
                $this->asked[] = $event;

                return ($this->decide)($event);
            },
            fn (): int => $this->promptTokens,
            function (string $kind, string $provider, string $model, Usage $usage, ?string $note): ?UsageEntry {
                $this->recorded[] = [$kind, $provider, $model, $usage, $note];

                return new UsageEntry($kind, $provider, $model, $usage, $note);
            },
        );
    }

    /** @param array{short?: int|float, long?: int|float}|null $promptCache */
    private static function model(
        Api $api = Api::AnthropicMessages,
        ?AnthropicCompat $compat = null,
        ?array $promptCache = ['short' => 10.1],
    ): Model {
        return new Model(
            'warm-test',
            'Warm Test',
            $api,
            'anthropic',
            'https://example.invalid',
            200_000,
            8_000,
            pricing: new Pricing(3.0, 15.0, 0.3, 3.75),
            compat: $compat,
            promptCache: $promptCache,
        );
    }
}
