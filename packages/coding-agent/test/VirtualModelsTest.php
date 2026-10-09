<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;
use Pig\Agent\Agent;
use Pig\Agent\AgentOptions;
use Pig\Agent\AgentTool;
use Pig\Agent\AgentToolResult;
use Pig\Agent\ThinkingLevel;
use Pig\Ai\Api;
use Pig\Ai\AssistantMessage;
use Pig\Ai\DoneEvent;
use Pig\Ai\ErrorEvent;
use Pig\Ai\Model;
use Pig\Ai\Models;
use Pig\Ai\SimpleStreamOptions;
use Pig\Ai\StartEvent;
use Pig\Ai\StopReason;
use Pig\Ai\Stream;
use Pig\Ai\TextContent;
use Pig\Ai\Tool;
use Pig\Ai\TranscriptContext;
use Pig\Ai\Usage;
use Pig\Ai\Utils\AssistantMessageEventStream;
use Pig\Async\AbortSignal;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\CodingAgent\Extensions\ExtensionApi;
use Pig\CodingAgent\Session\AgentSession;
use Pig\CodingAgent\Session\CustomEntry;
use Pig\CodingAgent\Session\SessionManager;
use Pig\CodingAgent\Settings;
use Pig\CodingAgent\VirtualModels\ModelRoute;
use Pig\CodingAgent\VirtualModels\ModelRouteRequest;
use Pig\CodingAgent\VirtualModels\VirtualModelDefinition;
use Pig\CodingAgent\VirtualModels\VirtualModelRegistry;
use RuntimeException;

/**
 * Virtual models: a catalogue entry an extension registers that routes each request to a
 * physical model. Upstream's `virtual-models.ts` and the routing in `agent-session.ts`.
 */
final class VirtualModelsTest extends TestCase
{
    /** @var list<array{model: string, reasoning: ?string}> what the provider was asked, in order */
    private array $requests = [];

    /** @var list<string|array{error: string}> */
    private array $script = [];

    /** @var list<ModelRouteRequest> */
    private array $routed = [];

    #[\Override]
    protected function setUp(): void
    {
        Loop::reset();
        VirtualModelRegistry::reset();
        Models::forgetRegistered();
        Models::register([
            new Model('fast', 'Fast', Api::AnthropicMessages, 'lab', 'http://127.0.0.1:1', 100_000, 8_000),
            new Model('smart', 'Smart', Api::AnthropicMessages, 'lab', 'http://127.0.0.1:1', 200_000, 16_000, reasoning: true),
        ]);
        $this->requests = [];
        $this->routed = [];
    }

    #[\Override]
    protected function tearDown(): void
    {
        VirtualModelRegistry::reset();
        Models::forgetRegistered();
    }

    // ---- the catalogue ------------------------------------------------------------------

    public function testARegisteredVirtualModelIsListedAndItsProviderNeedsNoKey(): void
    {
        $this->register();

        $auto = Models::find('auto', 'auto');
        $this->assertNotNull($auto);
        $this->assertSame(Api::Virtual, $auto->api);
        $this->assertTrue($auto->reasoning, 'a level other than off makes it a reasoning model');
        $this->assertSame([ThinkingLevel::Off, ThinkingLevel::High], ThinkingLevel::supportedBy($auto));
        $this->assertSame(Stream::AMBIENT_AUTH_MARKER, Stream::envApiKey('auto'), 'a provider of nothing but virtual models is signed in');
        $this->assertNull(Stream::envApiKey('lab'));

        VirtualModelRegistry::current()->unregister('auto', 'auto');
        $this->assertNull(Models::find('auto', 'auto'));
    }

    public function testAVirtualModelMayNotTakeAPhysicalModelsId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('conflicts with a physical model');
        (new ExtensionApi('.', 'test.php', 'test'))->registerVirtualModel(new VirtualModelDefinition('lab', 'fast', 'Fast?', static fn () => null));
    }

    public function testAVirtualModelHidesAPhysicalModelRegisteredLaterUnderItsId(): void
    {
        $this->register('lab', 'auto');
        Models::register([new Model('auto', 'Later', Api::AnthropicMessages, 'lab', 'http://127.0.0.1:1', 1, 1)]);
        $this->assertSame(Api::Virtual, Models::find('lab', 'auto')?->api);
    }

    public function testStreamingAVirtualModelWithoutRoutingIsRefused(): void
    {
        $this->register();
        $this->expectException(\Pig\Ai\ProviderError::class);
        $this->expectExceptionMessage('must be routed before streaming');
        Stream::simple(Models::find('auto', 'auto'), new TranscriptContext([]), new SimpleStreamOptions(apiKey: 'x'));
    }

    // ---- routing in the agent loop ------------------------------------------------------

    public function testEachRequestIsRoutedAndTheResponsesRecordThePhysicalModel(): void
    {
        $this->register();
        $session = $this->session(['tool', 'done']);
        $session->setModel(Models::find('auto', 'auto'), ThinkingLevel::High);

        Async::run(static fn () => $session->prompt('hi'));
        self::settle();

        $this->assertSame(
            [['model' => 'smart', 'reasoning' => 'high'], ['model' => 'fast', 'reasoning' => null]],
            $this->requests,
            'the first request went to the smart model with thinking, the continuation to the fast one — a level the fast model has not got is clamped',
        );
        $this->assertSame([ModelRouteRequest::USER, ModelRouteRequest::CONTINUATION], array_map(static fn (ModelRouteRequest $r): string => $r->reason, $this->routed));
        $this->assertNull($this->routed[0]->previous);
        $this->assertSame('smart', $this->routed[1]->previous?->model->id, 'the continuation is told what answered last');
        $this->assertSame('auto', $session->model()?->id, 'the selection stays virtual');
        $this->assertSame('fast', $session->routedModel()?->model->id);

        $responses = array_values(array_filter($session->messages(), static fn (mixed $m): bool => $m instanceof AssistantMessage));
        $this->assertSame(['smart', 'fast'], array_map(static fn (AssistantMessage $m): string => $m->model, $responses));
    }

    public function testARetryIsRoutedAsOneWithTheFailedRequest(): void
    {
        $this->register();
        $session = $this->session([['error' => 'overloaded_error'], 'done']);
        $session->setModel(Models::find('auto', 'auto'));

        Async::run(static fn () => $session->prompt('hi'));
        self::settle();

        $this->assertSame([ModelRouteRequest::USER, ModelRouteRequest::RETRY], array_map(static fn (ModelRouteRequest $r): string => $r->reason, $this->routed));
        $this->assertSame('smart', $this->routed[1]->failed?->model->id);
        $this->assertSame(StopReason::Error, $this->routed[1]->failed?->message?->stopReason);
        $this->assertSame('done', self::textOf($session->messages()[count($session->messages()) - 1]));
    }

    public function testARouterThatThrowsEndsTheRunWithAnErrorNamingIt(): void
    {
        $this->register(route: static fn (): ModelRoute => throw new RuntimeException('no model for you'));
        $session = $this->session(['never']);
        $session->setModel(Models::find('auto', 'auto'));

        Async::run(static fn () => $session->prompt('hi'));
        self::settle();

        $last = $session->messages()[count($session->messages()) - 1];
        $this->assertInstanceOf(AssistantMessage::class, $last);
        $this->assertSame(StopReason::Error, $last->stopReason);
        $this->assertStringContainsString('no model for you', (string) $last->errorMessage);
        $this->assertSame([], $this->requests, 'nothing reached a provider');
    }

    public function testARouteToAModelWithoutCredentialsIsRefused(): void
    {
        Models::register([new Model('far', 'Far', Api::AnthropicMessages, 'elsewhere', 'http://127.0.0.1:1', 1, 1)]);
        $this->register(route: static fn (): ModelRoute => new ModelRoute(Models::find('elsewhere', 'far'), ThinkingLevel::Off));
        $session = $this->session(['never'], keyed: ['lab']);
        $session->setModel(Models::find('auto', 'auto'));

        Async::run(static fn () => $session->prompt('hi'));
        self::settle();

        $last = $session->messages()[count($session->messages()) - 1];
        $this->assertStringContainsString('which has no credentials', (string) $last->errorMessage);
    }

    // ---- the session file -------------------------------------------------------------------

    public function testRouterStateIsKeptOnTheBranchAndTheVirtualSelectionSurvivesAResume(): void
    {
        $dir = sys_get_temp_dir() . '/pig-virtual-' . bin2hex(random_bytes(4));
        $store = SessionManager::create($dir);
        $this->register(route: function (ModelRouteRequest $request): ModelRoute {
            $this->routed[] = $request;
            $count = (int) ($request->state['count'] ?? 0) + 1;

            return new ModelRoute(Models::find('lab', 'fast'), ThinkingLevel::Off, ['count' => $count]);
        });
        $session = $this->session(['tool', 'done'], $store);
        $session->setModel(Models::find('auto', 'auto'));

        Async::run(static fn () => $session->prompt('hi'));
        self::settle();

        $this->assertSame([null, ['count' => 1]], array_map(static fn (ModelRouteRequest $r): mixed => $r->state, $this->routed), 'the second request sees what the first returned');
        $states = array_map(static fn (CustomEntry $e): mixed => $e->data['state'], $store->customEntries(VirtualModelRegistry::STATE_ENTRY));
        $this->assertSame([['count' => 1], ['count' => 2]], $states);

        $reopened = SessionManager::open($store->path);
        $this->assertSame('auto', $reopened->settings()['model']?->modelId, 'the virtual model_change holds over the physical responses after it');

        $resumed = $this->session(['again'], $reopened);
        $resumed->restore($reopened->messages());
        $resumed->restoreSettings();
        $this->assertSame('auto', $resumed->model()?->id);

        Async::run(static fn () => $resumed->prompt('more'));
        self::settle();
        $this->assertSame(['count' => 2], $this->routed[2]->state, 'and the state comes back with it');

        VirtualModelRegistry::reset();
        $this->assertSame('fast', SessionManager::open($store->path)->settings()['model']?->modelId, 'a virtual model no longer registered does not hold');
        exec('rm -rf ' . escapeshellarg($dir));
    }

    public function testASummaryIsRoutedDirectly(): void
    {
        $this->register();
        $session = $this->session(['tool', 'done', 'summary of hi']);
        $session->setModel(Models::find('auto', 'auto'));

        Async::run(static fn () => $session->prompt('hi'));
        self::settle();
        Async::run(static fn () => $session->compact());
        self::settle();

        $this->assertSame(ModelRouteRequest::DIRECT, $this->routed[2]->reason);
        $this->assertSame('fast', $this->requests[2]['model'], 'direct requests take the router\'s answer too');
    }

    // ---- helpers --------------------------------------------------------------------------

    /** A router that sends a user's turn to the smart model and everything else to the fast one. */
    private function register(string $provider = 'auto', string $id = 'auto', ?\Closure $route = null): void
    {
        $route ??= function (ModelRouteRequest $request): ModelRoute {
            $this->routed[] = $request;

            return $request->reason === ModelRouteRequest::USER
                ? new ModelRoute(Models::find('lab', 'smart'), $request->thinkingLevel)
                : new ModelRoute(Models::find('lab', 'fast'), $request->thinkingLevel);
        };

        (new ExtensionApi('.', 'test.php', 'test'))->registerVirtualModel(new VirtualModelDefinition(
            $provider,
            $id,
            'Auto',
            $route,
            thinkingLevels: [ThinkingLevel::Off, ThinkingLevel::High],
        ));
    }

    /**
     * @param list<string|array{error: string}> $script
     * @param list<string> $keyed the providers there is a key for
     */
    private function session(array $script, ?SessionManager $store = null, array $keyed = ['lab', 'elsewhere']): AgentSession
    {
        $this->script = $script;
        $agent = new Agent(new AgentOptions(
            streamFn: $this->provider(...),
            getApiKey: static fn (string $provider): ?string => in_array($provider, $keyed, true) ? 'key' : Stream::envApiKey($provider),
        ));
        $agent->setModel(Models::find('lab', 'fast'));
        $agent->setTools([self::tool()]);

        return new AgentSession($agent, sys_get_temp_dir(), $store, Settings::inMemory(['retry' => ['baseDelayMs' => 1], 'compaction' => ['keepRecentTokens' => 1]]));
    }

    private function provider(Model $model, TranscriptContext $context, SimpleStreamOptions $options): AssistantMessageEventStream
    {
        $this->requests[] = ['model' => $model->id, 'reasoning' => $options->reasoning?->value];
        $turn = array_shift($this->script) ?? throw new RuntimeException('out of scripted turns');
        $stream = new AssistantMessageEventStream();

        if ($turn === 'tool') {
            $message = new AssistantMessage([new \Pig\Ai\ToolCall('call-1', 'note', [])], $model->api, $model->provider, $model->id, new Usage(), StopReason::ToolUse);
        } elseif (is_string($turn)) {
            $message = new AssistantMessage([new TextContent($turn)], $model->api, $model->provider, $model->id, new Usage(), StopReason::Stop);
        } else {
            $message = new AssistantMessage([new TextContent('')], $model->api, $model->provider, $model->id, new Usage(), StopReason::Error, $turn['error']);
        }

        Async::spawn(static function () use ($stream, $message): void {
            $stream->push(new StartEvent($message));
            $stream->push($message->stopReason === StopReason::Error
                ? new ErrorEvent(StopReason::Error, $message)
                : new DoneEvent($message->stopReason, $message));
            $stream->end();
        });

        return $stream;
    }

    private static function tool(): AgentTool
    {
        return new class implements AgentTool {
            public function definition(): Tool
            {
                return new Tool('note', 'does nothing', ['type' => 'object', 'properties' => []]);
            }

            public function label(): string
            {
                return 'Note';
            }

            public function execute(string $toolCallId, array $arguments, ?AbortSignal $signal = null, ?\Closure $onUpdate = null): AgentToolResult
            {
                return new AgentToolResult([new TextContent('noted')]);
            }
        };
    }

    private static function settle(int $ticks = 400): void
    {
        for ($tick = 0; $tick < $ticks && !Loop::get()->isIdle(); $tick++) {
            Loop::get()->tick();
        }
    }

    private static function textOf(mixed $message): string
    {
        $text = '';

        foreach ($message->content ?? [] as $part) {
            if ($part instanceof TextContent) {
                $text .= $part->text;
            }
        }

        return $text;
    }
}
