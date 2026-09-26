<?php

declare(strict_types=1);

namespace Pig\Agent\Test;

use Closure;
use PHPUnit\Framework\TestCase;
use Pig\Agent\AgentContext;
use Pig\Agent\AgentError;
use Pig\Agent\AgentLoop;
use Pig\Agent\AgentLoopConfig;
use Pig\Agent\AgentMessage;
use Pig\Agent\AgentTool;
use Pig\Agent\AgentToolResult;
use Pig\Ai\Api;
use Pig\Ai\AssistantMessage;
use Pig\Ai\Context;
use Pig\Ai\DoneEvent;
use Pig\Ai\ErrorEvent;
use Pig\Ai\Model;
use Pig\Ai\StartEvent;
use Pig\Ai\StopReason;
use Pig\Ai\TextContent;
use Pig\Ai\Tool;
use Pig\Ai\ToolCall;
use Pig\Ai\ToolResultMessage;
use Pig\Ai\Usage;
use Pig\Ai\UserMessage;
use Pig\Ai\Utils\AssistantMessageEventStream;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\Test\AssertsThrows;
use ReflectionClass;
use RuntimeException;

/** A message the app invented, which the model must never see. */
final class Notice implements AgentMessage
{
    public function __construct(public readonly string $text)
    {
    }
}

/** A tool whose behaviour each test decides. */
final class ScriptedTool implements AgentTool
{
    /** @var list<array<string, mixed>> */
    public array $calls = [];

    public function __construct(
        private readonly string $name,
        private readonly Closure $handler,
    ) {
    }

    public function definition(): Tool
    {
        return new Tool($this->name, 'a tool', [
            'properties' => ['path' => ['type' => 'string'], 'limit' => ['type' => 'integer']],
            'required' => ['path'],
        ]);
    }

    public function label(): string
    {
        return ucfirst($this->name);
    }

    public function execute(string $toolCallId, array $arguments, ?\Pig\Async\AbortSignal $signal = null, ?Closure $onUpdate = null): AgentToolResult
    {
        $this->calls[] = $arguments;

        return ($this->handler)($arguments, $onUpdate);
    }
}

final class AgentLoopTest extends TestCase
{
    use AssertsThrows;

    /** @var list<Context> every context the provider was handed */
    private array $seen = [];

    #[\Override]
    protected function setUp(): void
    {
        Loop::reset();
        $this->seen = [];
    }

    public function testAPlainAnswerRunsOneTurn(): void
    {
        [$events, $messages] = $this->run(
            [$this->answer('hello there')],
            [new UserMessage('hi')],
        );

        $this->assertSame([
            'AgentStartEvent',
            'TurnStartEvent',
            'MessageStartEvent',   // the prompt
            'MessageEndEvent',
            'MessageStartEvent',   // the assistant
            'MessageEndEvent',
            'TurnEndEvent',
            'AgentEndEvent',
        ], $events);

        // The prompt and the answer, in order.
        $this->assertCount(2, $messages);
        $this->assertInstanceOf(UserMessage::class, $messages[0]);
        $this->assertInstanceOf(AssistantMessage::class, $messages[1]);
    }

    public function testAToolCallRunsTheToolAndAsksAgain(): void
    {
        $tool = new ScriptedTool('read', static fn (array $args): AgentToolResult => new AgentToolResult(
            [new TextContent("contents of {$args['path']}")],
            details: ['bytes' => 120],
        ));

        [$events, $messages] = $this->run(
            [$this->wantsTool('read', ['path' => '/tmp/a.php']), $this->answer('it is a php file')],
            [new UserMessage('what is in a.php?')],
            tools: [$tool],
        );

        $this->assertSame([['path' => '/tmp/a.php']], $tool->calls);

        $this->assertSame([
            'AgentStartEvent', 'TurnStartEvent',
            'MessageStartEvent', 'MessageEndEvent',                      // prompt
            'MessageStartEvent', 'MessageEndEvent',                      // assistant asks for the tool
            'ToolExecutionStartEvent', 'ToolExecutionEndEvent',
            'MessageStartEvent', 'MessageEndEvent',                      // the tool result
            'TurnEndEvent',
            'TurnStartEvent',
            'MessageStartEvent', 'MessageEndEvent',                      // the second answer
            'TurnEndEvent',
            'AgentEndEvent',
        ], $events);

        $result = $messages[2];
        $this->assertInstanceOf(ToolResultMessage::class, $result);
        $this->assertFalse($result->isError);
        $this->assertSame('contents of /tmp/a.php', $result->content[0]->text);
        // Details are for the UI and must not be in what the model is shown.
        $this->assertSame(['bytes' => 120], $result->details);
    }

    public function testAToolThatThrowsBecomesSomethingTheModelCanRead(): void
    {
        $tool = new ScriptedTool('read', static fn (): AgentToolResult => throw new RuntimeException('no such file'));

        [, $messages] = $this->run(
            [$this->wantsTool('read', ['path' => '/nope']), $this->answer('sorry')],
            [new UserMessage('read it')],
            tools: [$tool],
        );

        $result = $messages[2];
        $this->assertInstanceOf(ToolResultMessage::class, $result);
        $this->assertTrue($result->isError);
        $this->assertSame('no such file', $result->content[0]->text);
        // The run carried on: a broken tool is not a broken agent.
        $this->assertInstanceOf(AssistantMessage::class, $messages[3]);
    }

    public function testAToolThatIsNotThereIsReportedTheSameWay(): void
    {
        [, $messages] = $this->run(
            [$this->wantsTool('write', ['path' => '/tmp/a']), $this->answer('ok')],
            [new UserMessage('write it')],
            tools: [],
        );

        $this->assertStringContainsString('Tool write not found', $messages[2]->content[0]->text);
        $this->assertTrue($messages[2]->isError);
    }

    public function testArgumentsThatDoNotMatchTheSchemaComeBackAsAFixableError(): void
    {
        $tool = new ScriptedTool('read', static fn (): AgentToolResult => new AgentToolResult([new TextContent('ok')]));

        [, $messages] = $this->run(
            // No `path`, and `limit` is a string where the schema says integer.
            [$this->wantsTool('read', ['limit' => 'ten']), $this->answer('let me retry')],
            [new UserMessage('read it')],
            tools: [$tool],
        );

        $text = $messages[2]->content[0]->text;
        $this->assertTrue($messages[2]->isError);

        // AJV's wording, which is upstream's, rather than the phrasing the two-rule checker used
        // before `JsonSchema` replaced it. It matters because the reader is the **model**: it has
        // seen `must have required property` everywhere else, and it is about to correct itself
        // from this sentence.
        $this->assertStringContainsString("path: must have required property 'path'", $text);
        $this->assertStringContainsString('limit: must be integer', $text);
        // The tool never ran.
        $this->assertSame([], $tool->calls);
    }

    public function testSteeringSkipsTheToolsThatHaveNotRunYet(): void
    {
        $tool = new ScriptedTool('read', static fn (array $args): AgentToolResult => new AgentToolResult(
            [new TextContent("read {$args['path']}")],
        ));

        $steering = [new UserMessage('stop, do something else')];

        [, $messages] = $this->run(
            [$this->wantsTools('read', [['path' => '/a'], ['path' => '/b'], ['path' => '/c']]), $this->answer('ok')],
            [new UserMessage('read all three')],
            tools: [$tool],
            // The user types while the first tool is running. The loop also drains
            // steering once before the first model call — upstream does too — so a
            // double that answers immediately would be consumed there instead.
            getSteeringMessages: static function () use (&$steering, $tool): array {
                if ($tool->calls === []) {
                    return [];
                }

                $queued = $steering;
                $steering = [];

                return $queued;
            },
        );

        // Only the first tool actually ran.
        $this->assertSame([['path' => '/a']], $tool->calls);

        // …but all three calls got a result, or the next request would be malformed.
        $results = array_values(array_filter($messages, static fn ($m): bool => $m instanceof ToolResultMessage));
        $this->assertCount(3, $results);
        $this->assertSame('read /a', $results[0]->content[0]->text);
        $this->assertSame('Skipped due to queued user message.', $results[1]->content[0]->text);
        $this->assertTrue($results[2]->isError);
    }

    public function testAFollowUpRestartsAnAgentThatWouldHaveStopped(): void
    {
        $followUps = [new UserMessage('and one more thing')];

        [$events, $messages] = $this->run(
            [$this->answer('first'), $this->answer('second')],
            [new UserMessage('hi')],
            getFollowUpMessages: static function () use (&$followUps): array {
                $queued = $followUps;
                $followUps = [];

                return $queued;
            },
        );

        $this->assertSame(2, count(array_filter($events, static fn (string $e): bool => $e === 'TurnStartEvent')));
        $this->assertCount(4, $messages);   // prompt, answer, follow-up, answer
    }

    public function testAFailedResponseEndsTheRunThere(): void
    {
        [$events, $messages] = $this->run(
            [$this->failure('the provider fell over')],
            [new UserMessage('hi')],
        );

        $this->assertSame('AgentEndEvent', end($events));
        $this->assertCount(2, $messages);
        $this->assertSame(StopReason::Error, $messages[1]->stopReason);
    }

    public function testTheModelNeverSeesTheAppsOwnMessages(): void
    {
        $this->run(
            [$this->answer('ok')],
            [new Notice('the user opened a file'), new UserMessage('what is this?')],
        );

        $sent = $this->seen[0]->messages;

        $this->assertCount(1, $sent);
        $this->assertInstanceOf(UserMessage::class, $sent[0]);
    }

    public function testContinueRefusesWhatWouldBeRejectedAnyway(): void
    {
        $config = $this->config([]);

        $this->assertThrows(
            AgentError::class,
            fn () => AgentLoop::continue(new AgentContext(), $config),
            'no messages in context',
        );

        $this->assertThrows(
            AgentError::class,
            fn () => AgentLoop::continue(new AgentContext([$this->answer('hi')]), $config),
            'Cannot continue from an assistant message',
        );
    }

    public function testAProviderThatThrowsReachesTheConsumerInsteadOfHangingIt(): void
    {
        // The producer runs in a fiber of its own, so before `EventStream::fail()` this throw
        // escaped that fiber, the stream stayed open, and whoever was iterating it waited
        // forever — for a reason nobody ever gave them. A dead socket and a missing API key
        // both arrive here.
        $caught = Async::run(function (): ?string {
            $stream = AgentLoop::start(
                [new UserMessage('hi')],
                new AgentContext(),
                $this->config([]),
                null,
                static fn (): never => throw new AgentError('DNS is on fire'),
            );

            try {
                foreach ($stream as $ignored) {
                    // nothing after the prompt's own events
                }
            } catch (AgentError $error) {
                return $error->getMessage();
            }

            return null;
        });

        $this->assertSame('DNS is on fire', $caught);
    }

    public function testTheEventsBeforeAProviderThrowsStillArrive(): void
    {
        $seen = Async::run(function (): array {
            $stream = AgentLoop::start(
                [new UserMessage('hi')],
                new AgentContext(),
                $this->config([]),
                null,
                static fn (): never => throw new AgentError('DNS is on fire'),
            );

            $events = [];

            try {
                foreach ($stream as $event) {
                    $events[] = (new ReflectionClass($event))->getShortName();
                }
            } catch (AgentError) {
                // the point is what came before it
            }

            return $events;
        });

        // The prompt was announced before the provider was ever called, and a UI that drew
        // the user's message should not have to un-draw it.
        $this->assertSame(['AgentStartEvent', 'TurnStartEvent', 'MessageStartEvent', 'MessageEndEvent'], $seen);
    }

    public function testContinueCarriesOnWithoutAddingAnything(): void
    {
        [$events, $messages] = Async::run(function (): array {
            $stream = AgentLoop::continue(
                new AgentContext([new UserMessage('hi')]),
                $this->config([$this->answer('carried on')]),
                null,
                $this->provider([$this->answer('carried on')]),
            );

            $seen = [];

            foreach ($stream as $event) {
                $seen[] = (new ReflectionClass($event))->getShortName();
            }

            return [$seen, $stream->result()->await()];
        });

        $this->assertSame('AgentStartEvent', $events[0]);
        // Only the answer is new; the prompt was already in the context.
        $this->assertCount(1, $messages);
        $this->assertInstanceOf(AssistantMessage::class, $messages[0]);
    }

    public function testTheContextIsTransformedBeforeItIsConvertedForTheModel(): void
    {
        // The order is the whole point: `transformContext` works on the app's own messages —
        // compaction and the `ContextEvent` hook both arrive here — and `convertToLlm` then throws
        // away whatever the model cannot read. Reversed, a transform would be handed messages that
        // no longer include the app's own kinds, which is the one thing it is for.
        $saw = null;
        $transformed = null;
        $converted = null;

        $config = new AgentLoopConfig(
            model: $this->model(),
            convertToLlm: static function (array $messages) use (&$converted): array {
                $converted = $messages;

                return array_values(array_filter(
                    $messages,
                    static fn ($m): bool => $m instanceof UserMessage || $m instanceof AssistantMessage,
                ));
            },
            transformContext: static function (array $messages) use (&$saw, &$transformed): array {
                $saw = $messages;

                // Upstream's own test prunes to the last two, which is what a context transform
                // does for a living.
                $transformed = array_slice($messages, -2);

                return $transformed;
            },
            apiKey: 'test-key',
        );

        Async::run(function () use ($config): void {
            $stream = AgentLoop::start(
                [new UserMessage('the new one')],
                new AgentContext([
                    new Notice('the app said something'),
                    new UserMessage('an old question'),
                    $this->answer('an old answer'),
                ], 'be brief'),
                $config,
                null,
                $this->provider([$this->answer('ok')]),
            );

            foreach ($stream as $ignored) {
                // Drained; what is under test is what the two callbacks were handed.
            }

            $stream->result()->await();
        });

        // Four in the context by then — three plus the prompt — and the transform saw all of them,
        // the app's own message included.
        $this->assertCount(4, $saw ?? []);
        $this->assertInstanceOf(Notice::class, ($saw ?? [])[0]);
        $this->assertCount(2, $transformed ?? []);
        $this->assertSame($transformed, $converted, 'convertToLlm is handed the transform’s output');

        // And the model saw only what survived both steps.
        $this->assertCount(2, $this->seen[0]->messages);
    }

    public function testAnAppsOwnMessageMayBeTheLastOneWhenContinuing(): void
    {
        // Only an assistant message is refused. A hook message at the end is the caller's
        // business: `convertToLlm` is what turns it into something a provider accepts, and
        // refusing it here would refuse the case `continue()` exists for.
        [$messages, $sent] = Async::run(function (): array {
            $stream = AgentLoop::continue(
                new AgentContext([new Notice('a hook said something')]),
                new AgentLoopConfig(
                    model: $this->model(),
                    convertToLlm: static fn (array $messages): array => array_map(
                        static fn ($m): UserMessage => $m instanceof Notice
                            ? new UserMessage([new TextContent($m->text)])
                            : $m,
                        $messages,
                    ),
                    apiKey: 'test-key',
                ),
                null,
                $this->provider([$this->answer('answered the hook')]),
            );

            foreach ($stream as $ignored) {
                // Drained.
            }

            return [$stream->result()->await(), $this->seen[0]->messages];
        });

        $this->assertCount(1, $messages);
        $this->assertInstanceOf(AssistantMessage::class, $messages[0]);

        // The provider saw a user message, which is what the conversion was for.
        $this->assertInstanceOf(UserMessage::class, $sent[0]);
    }

    /**
     * @param list<AssistantMessage> $turns   one per model call, in order
     * @param list<mixed>            $prompts
     * @param list<AgentTool>        $tools
     * @return array{0: list<string>, 1: list<mixed>}
     */
    private function run(
        array $turns,
        array $prompts,
        array $tools = [],
        ?Closure $getSteeringMessages = null,
        ?Closure $getFollowUpMessages = null,
    ): array {
        return Async::run(function () use ($turns, $prompts, $tools, $getSteeringMessages, $getFollowUpMessages): array {
            $stream = AgentLoop::start(
                $prompts,
                new AgentContext([], 'be brief', $tools),
                $this->config($turns, $getSteeringMessages, $getFollowUpMessages),
                null,
                $this->provider($turns),
            );

            $events = [];

            foreach ($stream as $event) {
                $events[] = (new ReflectionClass($event))->getShortName();
            }

            return [$events, $stream->result()->await()];
        });
    }

    /** @param list<AssistantMessage> $turns */
    private function config(array $turns, ?Closure $steering = null, ?Closure $followUp = null): AgentLoopConfig
    {
        return new AgentLoopConfig(
            model: $this->model(),
            // The default from upstream: keep what the LLM understands, drop the rest.
            convertToLlm: static fn (array $messages): array => array_values(array_filter(
                $messages,
                static fn ($m): bool => $m instanceof UserMessage
                    || $m instanceof AssistantMessage
                    || $m instanceof ToolResultMessage,
            )),
            getSteeringMessages: $steering,
            getFollowUpMessages: $followUp,
            apiKey: 'test-key',
        );
    }

    /**
     * A provider that replays prepared answers instead of calling anything.
     *
     * @param list<AssistantMessage> $turns
     */
    private function provider(array $turns): Closure
    {
        $index = 0;

        return function (Model $model, Context $context, mixed $options) use ($turns, &$index): AssistantMessageEventStream {
            $this->seen[] = $context;
            $message = $turns[$index++] ?? throw new RuntimeException('the loop asked for more turns than were scripted');
            $stream = new AssistantMessageEventStream();

            Async::spawn(static function () use ($stream, $message): void {
                $stream->push(new StartEvent($message));

                $message->stopReason->isFailure()
                    ? $stream->push(new ErrorEvent($message->stopReason, $message))
                    : $stream->push(new DoneEvent($message->stopReason, $message));

                $stream->end();
            });

            return $stream;
        };
    }

    private function answer(string $text): AssistantMessage
    {
        return $this->assistant([new TextContent($text)], StopReason::Stop);
    }

    /** @param array<string, mixed> $arguments */
    private function wantsTool(string $name, array $arguments): AssistantMessage
    {
        return $this->wantsTools($name, [$arguments]);
    }

    /** @param list<array<string, mixed>> $calls */
    private function wantsTools(string $name, array $calls): AssistantMessage
    {
        $content = [];

        foreach ($calls as $index => $arguments) {
            $content[] = new ToolCall("call_{$index}", $name, $arguments);
        }

        return $this->assistant($content, StopReason::ToolUse);
    }

    private function failure(string $why): AssistantMessage
    {
        return new AssistantMessage(
            [],
            Api::AnthropicMessages,
            'anthropic',
            'test-model',
            new Usage(),
            StopReason::Error,
            $why,
        );
    }

    /** @param list<\Pig\Ai\AssistantContent> $content */
    private function assistant(array $content, StopReason $reason): AssistantMessage
    {
        return new AssistantMessage(
            $content,
            Api::AnthropicMessages,
            'anthropic',
            'test-model',
            new Usage(),
            $reason,
        );
    }

    private function model(): Model
    {
        return new Model('test-model', 'Test', Api::AnthropicMessages, 'anthropic', 'http://127.0.0.1:1', 200_000, 64_000);
    }
}
