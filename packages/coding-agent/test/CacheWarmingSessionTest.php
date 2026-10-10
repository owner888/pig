<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;
use Pig\Agent\Agent;
use Pig\Agent\AgentOptions;
use Pig\Ai\Api;
use Pig\Ai\AssistantMessage;
use Pig\Ai\DoneEvent;
use Pig\Ai\Model;
use Pig\Ai\Pricing;
use Pig\Ai\SimpleStreamOptions;
use Pig\Ai\StartEvent;
use Pig\Ai\StopReason;
use Pig\Ai\TextContent;
use Pig\Ai\TranscriptContext;
use Pig\Ai\Usage;
use Pig\Ai\Utils\AssistantMessageEventStream;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\CodingAgent\Hooks\Events\CacheWarmingDecisionEvent;
use Pig\CodingAgent\Hooks\HookApi;
use Pig\CodingAgent\Hooks\HookRunner;
use Pig\CodingAgent\Hooks\LoadedHook;
use Pig\CodingAgent\Hooks\Results\CacheWarmingDecisionEventResult;
use Pig\CodingAgent\Session\AgentSession;
use Pig\CodingAgent\Session\SessionManager;
use Pig\CodingAgent\Session\UsageEntry;
use Pig\CodingAgent\Settings;

/** The warmer as `AgentSession` wires it: which requests start it, where a refresh goes, what it costs. */
final class CacheWarmingSessionTest extends TestCase
{
    /** @var list<SimpleStreamOptions> */
    private array $requests = [];

    protected function setUp(): void
    {
        Loop::reset();
    }

    protected function tearDown(): void
    {
        Loop::reset();
    }

    public function testBetweenRunsARefreshGoesToTheProviderAndIsOnTheBill(): void
    {
        $store = SessionManager::create(sys_get_temp_dir());
        $session = $this->session($store, Settings::inMemory(['cacheWarming' => 'idle']));
        $session->enableCacheWarming();

        Async::run(function () use ($session): void {
            $session->prompt('hi');
            $before = $session->stats()->cost;
            Async::delay(0.18);
            $session->dispose();
            $this->assertGreaterThan($before, $session->stats()->cost, 'the refresh was paid for');
        });

        $this->assertGreaterThanOrEqual(2, count($this->requests));
        $this->assertSame(1, $this->requests[1]->maxTokens);
        $this->assertSame($store->id, $this->requests[1]->sessionId);
        $warmed = array_values(array_filter($store->everyMessage(), static fn (mixed $m): bool => $m instanceof UsageEntry));
        $this->assertNotSame([], $warmed);
        $this->assertSame('cache_warm', $warmed[0]->kind);

        // And it is a line pi reads back as the same entry.
        $reopened = SessionManager::open($store->path);
        $this->assertCount(count($warmed), array_filter($reopened->everyMessage(), static fn (mixed $m): bool => $m instanceof UsageEntry));
        $this->assertSame([], array_filter($reopened->messages(), static fn (mixed $m): bool => $m instanceof UsageEntry), 'not part of the conversation');
    }

    public function testByDefaultItStopsWhenThePromptSettles(): void
    {
        $session = $this->session(SessionManager::create(sys_get_temp_dir()), Settings::inMemory());
        $session->enableCacheWarming();

        Async::run(function () use ($session): void {
            $session->prompt('hi');
            Async::delay(0.18);
        });

        $this->assertCount(1, $this->requests);
        $this->assertSame('agent run settled', $session->cacheWarmingStatus()?->reason);
    }

    public function testAModeThatNeverTurnedItOnSendsNothingAndHasNoStatus(): void
    {
        $session = $this->session(SessionManager::create(sys_get_temp_dir()), Settings::inMemory(['cacheWarming' => 'idle']));

        Async::run(function () use ($session): void {
            $session->prompt('hi');
            Async::delay(0.18);
        });

        $this->assertCount(1, $this->requests);
        $this->assertNull($session->cacheWarmingStatus());
    }

    public function testAHookHasTheLastWordOnEachRefresh(): void
    {
        $asked = [];
        $api = new HookApi('.', 'test.php');
        $api->on('cache_warming_decision', static function (CacheWarmingDecisionEvent $event) use (&$asked): CacheWarmingDecisionEventResult {
            $asked[] = $event;

            return new CacheWarmingDecisionEventResult('stop');
        });
        $store = SessionManager::create(sys_get_temp_dir());
        $session = $this->session($store, Settings::inMemory(['cacheWarming' => 'idle']), new HookRunner([new LoadedHook('test.php', 'test.php', $api)], sys_get_temp_dir()));
        $session->enableCacheWarming();

        Async::run(function () use ($session): void {
            $session->prompt('hi');
            Async::delay(0.18);
        });

        $this->assertCount(1, $this->requests);
        $this->assertSame('warm', $asked[0]->action);
        $this->assertSame('stopped by extension', $session->cacheWarmingStatus()?->reason);
    }

    public function testSettingTheModeSavesItAndStopsARunningWarm(): void
    {
        $settings = Settings::inMemory(['cacheWarming' => 'idle']);
        $session = $this->session(SessionManager::create(sys_get_temp_dir()), $settings);
        $session->enableCacheWarming();

        Async::run(function () use ($session): void {
            $session->prompt('hi');
            $session->setCacheWarmingMode('off');
            Async::delay(0.18);
        });

        $this->assertSame('off', $settings->cacheWarmingMode());
        $this->assertCount(1, $this->requests);
    }

    public function testSwitchingFilesLeavesTheOldConversationsEntryAlone(): void
    {
        $session = $this->session(SessionManager::create(sys_get_temp_dir()), Settings::inMemory(['cacheWarming' => 'idle']));
        $session->enableCacheWarming();

        Async::run(function () use ($session): void {
            $session->prompt('hi');
            $session->writeTo(SessionManager::create(sys_get_temp_dir()));
            Async::delay(0.18);
        });

        $this->assertCount(1, $this->requests);
    }

    private function session(SessionManager $store, Settings $settings, ?HookRunner $hooks = null): AgentSession
    {
        $agent = new Agent(new AgentOptions(
            streamFn: function (Model $model, TranscriptContext $context, SimpleStreamOptions $options): AssistantMessageEventStream {
                $this->requests[] = $options;

                return self::answer($model, count($this->requests) === 1
                    ? new Usage(input: 10, cacheWrite: 1_000_000, output: 5)
                    : new Usage(output: 1, cacheRead: 1_000_000));
            },
            apiKey: 'test-key',
        ));
        $agent->setModel(new Model(
            'warm-test',
            'Warm Test',
            Api::AnthropicMessages,
            'anthropic',
            'http://127.0.0.1:1',
            2_000_000,
            8_000,
            pricing: new Pricing(3.0, 15.0, 0.3, 3.75),
            promptCache: ['short' => 10.1],
        ));

        return new AgentSession($agent, sys_get_temp_dir(), $store, $settings, $hooks);
    }

    private static function answer(Model $model, Usage $usage): AssistantMessageEventStream
    {
        $stream = new AssistantMessageEventStream();
        $message = new AssistantMessage([new TextContent('ok')], $model->api, $model->provider, $model->id, $usage->withCost($model), StopReason::Stop);

        Async::spawn(static function () use ($stream, $message): void {
            $stream->push(new StartEvent($message));
            $stream->push(new DoneEvent(StopReason::Stop, $message));
            $stream->end();
        });

        return $stream;
    }
}
