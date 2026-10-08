<?php

declare(strict_types=1);

namespace Pig\Ai\Test\Providers;

use PHPUnit\Framework\TestCase;
use Pig\Ai\Api;
use Pig\Ai\AssistantMessage;
use Pig\Ai\Context;
use Pig\Ai\ErrorEvent;
use Pig\Ai\Extension\ProviderRegistry;
use Pig\Ai\ImageContent;
use Pig\Ai\Model;
use Pig\Ai\Models;
use Pig\Ai\Providers\Faux;
use Pig\Ai\Providers\FauxProvider;
use Pig\Ai\Providers\FauxProviderState;
use Pig\Ai\SimpleStreamOptions;
use Pig\Ai\StopReason;
use Pig\Ai\Stream;
use Pig\Ai\StreamOptions;
use Pig\Ai\TextContent;
use Pig\Ai\ThinkingContent;
use Pig\Ai\ToolCall;
use Pig\Ai\ToolCallDeltaEvent;
use Pig\Ai\ToolResultMessage;
use Pig\Ai\TranscriptContext;
use Pig\Ai\UserMessage;
use Pig\Ai\Utils\Transcript;
use Pig\Async\AbortController;
use Pig\Async\Async;
use Pig\Async\Loop;
use RuntimeException;

/**
 * Upstream's `faux-provider.test.ts`, through the provider's own stream (upstream's
 * `registerFauxProvider()` + `stream()`) and once through `Extension\ProviderRegistry` and `Stream`.
 */
final class FauxProviderTest extends TestCase
{
    #[\Override]
    protected function setUp(): void
    {
        Loop::reset();
        ProviderRegistry::forget();
        Models::forgetRegistered();
    }

    #[\Override]
    protected function tearDown(): void
    {
        ProviderRegistry::forget();
        Models::forgetRegistered();
    }

    public function testARegisteredFauxProviderAnswersThroughStreamAndEstimatesItsUsage(): void
    {
        $faux = Faux::fauxProvider();
        ProviderRegistry::register($faux->provider());
        $faux->setResponses([Faux::fauxAssistantMessage('hello world')]);
        $model = Models::find('faux', 'faux-1');
        $this->assertNotNull($model);

        // pig's `Stream` asks an extension's protocol for a key; upstream's faux auth resolves to none.
        $response = Async::run(static fn (): AssistantMessage => Stream::simple($model, new Context([new UserMessage('hi there')], 'Be concise.'), new SimpleStreamOptions(apiKey: 'faux'))->result()->await());

        $this->assertEquals([new TextContent('hello world')], $response->content);
        $this->assertGreaterThan(0, $response->usage->input);
        $this->assertGreaterThan(0, $response->usage->output);
        $this->assertSame($response->usage->input + $response->usage->output, $response->usage->totalTokens);
        $this->assertSame(1, $faux->state->callCount);
    }

    public function testHelperBlocksForTextThinkingAndToolCalls(): void
    {
        $faux = Faux::fauxProvider();
        $faux->setResponses([Faux::fauxAssistantMessage([Faux::fauxThinking('think'), Faux::fauxToolCall('echo', ['text' => 'hi']), Faux::fauxText('done')], StopReason::ToolUse)]);

        $response = self::complete($faux, self::hi());

        $this->assertEquals(new ThinkingContent('think'), $response->content[0]);
        $this->assertInstanceOf(ToolCall::class, $response->content[1]);
        $this->assertSame(['echo', ['text' => 'hi']], [$response->content[1]->name, $response->content[1]->arguments]);
        $this->assertStringStartsWith('tool:', $response->content[1]->id);
        $this->assertEquals(new TextContent('done'), $response->content[2]);
        $this->assertSame(StopReason::ToolUse, $response->stopReason);
    }

    public function testSeveralModelsAndModelAwareFactories(): void
    {
        $faux = Faux::fauxProvider(models: [['id' => 'faux-fast', 'name' => 'Faux Fast', 'reasoning' => false], ['id' => 'faux-thinker', 'name' => 'Faux Thinker', 'reasoning' => true]]);
        $factory = static fn (TranscriptContext $context, ?StreamOptions $options, FauxProviderState $state, Model $model): AssistantMessage => Faux::fauxAssistantMessage($model->id . ':' . ($model->reasoning ? 'true' : 'false'));
        $faux->setResponses([$factory, $factory]);

        $fast = self::complete($faux, self::hi(), $faux->getModel('faux-fast'));
        $thinker = self::complete($faux, self::hi(), $faux->getModel('faux-thinker'));

        $this->assertEquals([new TextContent('faux-fast:false')], $fast->content);
        $this->assertEquals([new TextContent('faux-thinker:true')], $thinker->content);
        $this->assertSame('faux-fast', $faux->getModel()?->id);
        $this->assertNull($faux->getModel('missing'));
    }

    public function testTheProviderAndModelAreRewrittenOnReturnedMessages(): void
    {
        $faux = Faux::fauxProvider(provider: 'faux-provider', models: [['id' => 'faux-model']]);
        $faux->setResponses([Faux::fauxAssistantMessage('hello')]);

        $response = self::complete($faux, self::hi());

        $this->assertSame([Api::Extension, 'faux-provider', 'faux-model'], [$response->api, $response->provider, $response->model]);
    }

    public function testQueuedResponsesAreTakenInOrderAndAnEmptyQueueIsAnError(): void
    {
        $faux = Faux::fauxProvider();
        $faux->setResponses([Faux::fauxAssistantMessage('first'), Faux::fauxAssistantMessage('second')]);

        $first = self::complete($faux, self::hi());
        $second = self::complete($faux, self::hi());
        $exhausted = self::complete($faux, self::hi());

        $this->assertEquals([new TextContent('first')], $first->content);
        $this->assertEquals([new TextContent('second')], $second->content);
        $this->assertSame(StopReason::Error, $exhausted->stopReason);
        $this->assertSame('No more faux responses queued', $exhausted->errorMessage);
        $this->assertSame(0, $faux->getPendingResponseCount());
        $this->assertSame(3, $faux->state->callCount);
    }

    public function testTheQueueCanBeReplacedAndAppendedTo(): void
    {
        $faux = Faux::fauxProvider();
        $faux->setResponses([Faux::fauxAssistantMessage('first')]);
        $this->assertEquals([new TextContent('first')], self::complete($faux, self::hi())->content);
        $this->assertSame(0, $faux->getPendingResponseCount());

        $faux->setResponses([Faux::fauxAssistantMessage('second')]);
        $this->assertSame(1, $faux->getPendingResponseCount());
        $this->assertEquals([new TextContent('second')], self::complete($faux, self::hi())->content);

        $faux->appendResponses([Faux::fauxAssistantMessage('third'), Faux::fauxAssistantMessage('fourth')]);
        $this->assertSame(2, $faux->getPendingResponseCount());
        $this->assertEquals([new TextContent('third')], self::complete($faux, self::hi())->content);
        $this->assertEquals([new TextContent('fourth')], self::complete($faux, self::hi())->content);
        $this->assertSame(0, $faux->getPendingResponseCount());
    }

    public function testAFactorySeesTheContextAndTheState(): void
    {
        $faux = Faux::fauxProvider();
        $faux->setResponses([static fn (TranscriptContext $context, ?StreamOptions $options, FauxProviderState $state): AssistantMessage => Faux::fauxAssistantMessage(count($context->messages) . ':' . $state->callCount)]);

        $this->assertEquals([new TextContent('1:1')], self::complete($faux, self::hi())->content);
    }

    public function testAFactoryThatThrowsIsOneErrorEvent(): void
    {
        $faux = Faux::fauxProvider();
        $faux->setResponses([static function (): never {
            throw new RuntimeException('boom');
        }]);

        $events = self::events($faux, self::hi());

        $this->assertCount(1, $events);
        $this->assertInstanceOf(ErrorEvent::class, $events[0]);
        $this->assertSame([StopReason::Error, 'boom'], [$events[0]->error->stopReason, $events[0]->error->errorMessage]);
    }

    public function testAResponseWithoutATerminalStopReasonIsRefused(): void
    {
        $faux = Faux::fauxProvider();
        $faux->setResponses([Faux::fauxAssistantMessage('partial', StopReason::Pending)]);

        $events = self::events($faux, self::hi());
        $terminal = $events[count($events) - 1];

        $this->assertSame([], array_filter($events, static fn ($event): bool => $event instanceof \Pig\Ai\DoneEvent));
        $this->assertInstanceOf(ErrorEvent::class, $terminal);
        $this->assertSame([StopReason::Error, 'Faux response ended without a stop reason'], [$terminal->error->stopReason, $terminal->error->errorMessage]);
    }

    public function testPromptAndOutputTokensAreEstimatedFromTheSerializedTranscript(): void
    {
        $faux = Faux::fauxProvider();
        $faux->setResponses([Faux::fauxAssistantMessage('done')]);
        $context = new Context([
            new UserMessage([new TextContent('hello'), new ImageContent('abcd', 'image/png')], 1),
            Faux::fauxAssistantMessage('prior'),
            new ToolResultMessage('tool-1', 'echo', [new TextContent('tool out')], false, null, 2),
        ], 'sys');

        $response = self::complete($faux, $context);

        $promptText = implode("\n\n", ['system:sys', "user:hello\n[image:image/png:4]", 'assistant:prior', "toolResult:echo\ntool out"]);
        $this->assertSame((int) ceil(strlen($promptText) / 4), $response->usage->input);
        $this->assertSame(1, $response->usage->output);
        $this->assertSame([0, 0], [$response->usage->cacheRead, $response->usage->cacheWrite]);
        $this->assertSame($response->usage->input + 1, $response->usage->totalTokens);
    }

    public function testThePromptCacheIsSimulatedPerSessionAndOnlyWithOne(): void
    {
        $faux = Faux::fauxProvider();
        $faux->setResponses([Faux::fauxAssistantMessage('first'), Faux::fauxAssistantMessage('second'), Faux::fauxAssistantMessage('third'), Faux::fauxAssistantMessage('fourth')]);
        $messages = [new UserMessage('hello')];

        $first = self::complete($faux, new Context($messages, 'Be concise.'), options: new SimpleStreamOptions(sessionId: 'session-1', cacheRetention: 'short'));
        $this->assertSame(0, $first->usage->cacheRead);
        $this->assertGreaterThan(0, $first->usage->cacheWrite);

        $messages = [...$messages, $first, new UserMessage('follow up')];
        $other = self::complete($faux, new Context($messages, 'Be concise.'), options: new SimpleStreamOptions(sessionId: 'session-2', cacheRetention: 'short'));
        $this->assertSame(0, $other->usage->cacheRead);
        $this->assertGreaterThan(0, $other->usage->cacheWrite);

        $same = self::complete($faux, new Context($messages, 'Be concise.'), options: new SimpleStreamOptions(sessionId: 'session-1', cacheRetention: 'short'));
        $this->assertGreaterThan(0, $same->usage->cacheRead);

        $none = self::complete($faux, new Context($messages, 'Be concise.'));
        $this->assertSame([0, 0], [$none->usage->cacheRead, $none->usage->cacheWrite]);
    }

    public function testNoCachingIsSimulatedWithCacheRetentionNone(): void
    {
        $faux = Faux::fauxProvider();
        $faux->setResponses([Faux::fauxAssistantMessage('first'), Faux::fauxAssistantMessage('second')]);

        self::complete($faux, self::hi(), options: new SimpleStreamOptions(sessionId: 'session-1', cacheRetention: 'none'));
        $second = self::complete($faux, new Context([new UserMessage('hi'), Faux::fauxAssistantMessage('first'), new UserMessage('follow up')]), options: new SimpleStreamOptions(sessionId: 'session-1', cacheRetention: 'none'));

        $this->assertSame([0, 0], [$second->usage->cacheRead, $second->usage->cacheWrite]);
    }

    public function testThinkingTextAndToolCallArgumentsStreamInPieces(): void
    {
        $faux = Faux::fauxProvider();
        $faux->setResponses([Faux::fauxAssistantMessage([Faux::fauxThinking('thinking text'), Faux::fauxText('answer text'), Faux::fauxToolCall('echo', ['text' => 'hi', 'count' => 12], 'tool-1')], StopReason::ToolUse)]);

        $events = self::events($faux, self::hi());
        $types = array_map(self::type(...), $events);
        $deltas = array_map(static fn (ToolCallDeltaEvent $event): string => $event->delta, array_values(array_filter($events, static fn ($event): bool => $event instanceof ToolCallDeltaEvent)));

        foreach (['thinking_start', 'thinking_delta', 'text_start', 'text_delta', 'toolcall_start', 'toolcall_delta', 'toolcall_end'] as $type) {
            $this->assertContains($type, $types);
        }

        $this->assertGreaterThan(1, count($deltas));
        $this->assertSame(['text' => 'hi', 'count' => 12], json_decode(implode('', $deltas), true));
    }

    public function testTheEventOrderForOneTokenPieces(): void
    {
        $faux = Faux::fauxProvider(tokenSize: ['min' => 1, 'max' => 1]);
        $faux->setResponses([Faux::fauxAssistantMessage([Faux::fauxThinking('go'), Faux::fauxText('ok'), Faux::fauxToolCall('echo', [], 'tool-1')], StopReason::ToolUse)]);

        $events = self::events($faux, self::hi());

        $this->assertSame(StopReason::Pending, $events[0]->partial->stopReason);
        $this->assertSame(['start', 'thinking_start', 'thinking_delta', 'thinking_end', 'text_start', 'text_delta', 'text_end', 'toolcall_start', 'toolcall_delta', 'toolcall_end', 'done'], array_map(self::type(...), $events));
    }

    public function testAScriptedErrorOrAbortedMessageEndsAsAnError(): void
    {
        $faux = Faux::fauxProvider();
        $faux->setResponses([
            Faux::fauxAssistantMessage('partial', StopReason::Error, 'upstream failed'),
            Faux::fauxAssistantMessage('partial', StopReason::Aborted, 'Request was aborted'),
        ]);

        foreach ([[StopReason::Error, 'upstream failed'], [StopReason::Aborted, 'Request was aborted']] as [$reason, $message]) {
            $events = self::events($faux, self::hi());
            $terminal = $events[count($events) - 1];
            $this->assertInstanceOf(ErrorEvent::class, $terminal);
            $this->assertSame([$reason, $reason, $message], [$terminal->reason, $terminal->error->stopReason, $terminal->error->errorMessage]);
        }
    }

    public function testAbortingBeforeTheFirstPieceAndInTheMiddleOfAPacedStream(): void
    {
        $faux = Faux::fauxProvider(tokensPerSecond: 50);
        $faux->setResponses([Faux::fauxAssistantMessage('never'), Faux::fauxAssistantMessage(str_repeat('word ', 200))]);
        $before = new AbortController();
        $before->abort();

        $events = self::events($faux, self::hi(), new SimpleStreamOptions(signal: $before->signal));
        $this->assertSame(['error'], array_map(self::type(...), $events));
        $this->assertSame([StopReason::Aborted, 'Request was aborted'], [$events[0]->error->stopReason, $events[0]->error->errorMessage]);

        $during = new AbortController();
        $events = Async::run(static function () use ($faux, $during): array {
            $stream = $faux->stream($faux->getModel(), Transcript::normalizeContext(self::hi()), new SimpleStreamOptions(signal: $during->signal));
            $seen = [];

            foreach ($stream as $event) {
                $seen[] = $event;

                if (self::type($event) === 'text_delta') {
                    $during->abort();
                }
            }

            return $seen;
        });
        $terminal = $events[count($events) - 1];
        $this->assertInstanceOf(ErrorEvent::class, $terminal);
        $this->assertSame(StopReason::Aborted, $terminal->error->stopReason);
        $this->assertInstanceOf(TextContent::class, $terminal->error->content[0]);
        $this->assertNotSame('', $terminal->error->content[0]->text);
    }

    public function testUnregisteringTheProviderTakesItsModelsAway(): void
    {
        $faux = Faux::fauxProvider();
        ProviderRegistry::register($faux->provider());
        $this->assertNotNull(Models::find('faux', 'faux-1'));

        ProviderRegistry::unregister('faux');

        $this->assertNull(Models::find('faux', 'faux-1'));
    }

    private static function hi(): Context
    {
        return new Context([new UserMessage('hi')]);
    }

    private static function complete(FauxProvider $faux, Context $context, ?Model $model = null, ?SimpleStreamOptions $options = null): AssistantMessage
    {
        return Async::run(static fn (): AssistantMessage => $faux->stream($model ?? $faux->getModel(), Transcript::normalizeContext($context), $options ?? new SimpleStreamOptions())->result()->await());
    }

    /** @return list<object> */
    private static function events(FauxProvider $faux, Context $context, ?SimpleStreamOptions $options = null): array
    {
        return Async::run(static function () use ($faux, $context, $options): array {
            $events = [];

            foreach ($faux->stream($faux->getModel(), Transcript::normalizeContext($context), $options ?? new SimpleStreamOptions()) as $event) {
                $events[] = $event;
            }

            return $events;
        });
    }

    /** The event's wire name, as upstream's `event.type`. */
    private static function type(object $event): string
    {
        return match ($event::class) {
            \Pig\Ai\StartEvent::class => 'start',
            \Pig\Ai\ThinkingStartEvent::class => 'thinking_start',
            \Pig\Ai\ThinkingDeltaEvent::class => 'thinking_delta',
            \Pig\Ai\ThinkingEndEvent::class => 'thinking_end',
            \Pig\Ai\TextStartEvent::class => 'text_start',
            \Pig\Ai\TextDeltaEvent::class => 'text_delta',
            \Pig\Ai\TextEndEvent::class => 'text_end',
            \Pig\Ai\ToolCallStartEvent::class => 'toolcall_start',
            ToolCallDeltaEvent::class => 'toolcall_delta',
            \Pig\Ai\ToolCallEndEvent::class => 'toolcall_end',
            \Pig\Ai\DoneEvent::class => 'done',
            ErrorEvent::class => 'error',
        };
    }
}
