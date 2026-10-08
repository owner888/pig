<?php

declare(strict_types=1);

namespace Pig\Ai\Test\Providers;

use PHPUnit\Framework\TestCase;
use Pig\Ai\Api;
use Pig\Ai\AssistantMessage;
use Pig\Ai\Context;
use Pig\Ai\ImageContent;
use Pig\Ai\Model;
use Pig\Ai\Pricing;
use Pig\Ai\Providers\Mistral;
use Pig\Ai\Providers\MistralOptions;
use Pig\Ai\ReasoningEffort;
use Pig\Ai\SimpleStreamOptions;
use Pig\Ai\StopReason;
use Pig\Ai\Stream;
use Pig\Ai\TextContent;
use Pig\Ai\ThinkingContent;
use Pig\Ai\Tool;
use Pig\Ai\ToolCall;
use Pig\Ai\ToolResultMessage;
use Pig\Ai\Usage;
use Pig\Ai\UserMessage;
use Pig\Ai\Utils\PigUserAgent;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\Test\CannedServer;

/**
 * Mistral's own API, `mistral-conversations` — upstream's `api/mistral-conversations.ts`.
 *
 * Upstream has no test file of its own for this provider at HEAD, so these read its source: the
 * request `buildChatPayload()` and `toChatMessages()` build, the stream `readMistralEvents()` and
 * `consumeChatStream()` read, and what `streamSimple()` asks for. Mistral's models used to go
 * through `OpenAiCompletions` with four Mistral rules detected from the host; what this API does
 * differently from that one is what is pinned here.
 */
final class MistralTest extends TestCase
{
    private CannedServer $server;

    #[\Override]
    protected function setUp(): void
    {
        Loop::reset();
        $this->server = new CannedServer();
    }

    // ---- the request -----------------------------------------------------------------------

    public function testTheRequestGoesToMistralsOwnEndpointInItsOwnShape(): void
    {
        $context = new Context(
            [
                new UserMessage([new TextContent('look'), new ImageContent('aGk=', 'image/png')]),
                $this->assistant([
                    new ThinkingContent('pondering'),
                    new TextContent('calling'),
                    new ToolCall('abcDEF123', 'read', ['path' => 'a.txt']),
                ]),
                new ToolResultMessage('abcDEF123', 'read', [new TextContent('contents')]),
            ],
            'be brief',
            [
                new Tool('read', 'Read a file', ['type' => 'object', 'properties' => ['path' => ['type' => 'string']], 'required' => ['path']]),
                new Tool('edit', 'Edit a file', ['type' => 'object', 'properties' => ['path' => ['type' => 'string']], 'required' => ['path']], constrainedSampling: ['type' => 'json_schema', 'strict' => 'prefer']),
            ],
        );

        [$head, $body] = $this->capture($this->model(), $context, new MistralOptions(maxTokens: 512, apiKey: 'secret', toolChoice: 'any'));

        // `new URL("v1/chat/completions", baseUrl + "/")` on `https://api.mistral.ai`.
        $this->assertStringStartsWith('POST /v1/chat/completions ', $head);
        $this->assertStringContainsStringIgnoringCase("authorization: Bearer secret\r\n", $head);
        $this->assertStringContainsStringIgnoringCase("accept: text/event-stream\r\n", $head);

        $this->assertSame('test-model', $body['model']);
        $this->assertTrue($body['stream']);
        $this->assertSame(512, $body['max_tokens']);
        $this->assertSame('any', $body['tool_choice']);

        // Thinking is a content chunk on a replayed turn, not a field beside it; an assistant turn
        // says `prefix: false`; a call's `index` is 0; a tool result carries its tool's name; an
        // image is a data URL under `image_url`.
        $this->assertSame([
            ['role' => 'system', 'content' => 'be brief'],
            ['role' => 'user', 'content' => [
                ['type' => 'text', 'text' => 'look'],
                ['type' => 'image_url', 'image_url' => 'data:image/png;base64,aGk='],
            ]],
            ['role' => 'assistant', 'prefix' => false, 'content' => [
                ['type' => 'thinking', 'thinking' => [['type' => 'text', 'text' => 'pondering']]],
                ['type' => 'text', 'text' => 'calling'],
            ], 'tool_calls' => [
                ['id' => 'abcDEF123', 'type' => 'function', 'function' => ['name' => 'read', 'arguments' => '{"path":"a.txt"}'], 'index' => 0],
            ]],
            ['role' => 'tool', 'name' => 'read', 'content' => [['type' => 'text', 'text' => 'contents']], 'tool_call_id' => 'abcDEF123'],
        ], $body['messages']);

        // `toFunctionTools()`: `resolveJsonSchemaStrictSampling(tool, true)` — strict mode is always
        // there, so a tool that asks for JSON-schema sampling goes strict — and `strict` is always
        // sent, `false` for a tool that did not ask.
        $this->assertSame('function', $body['tools'][0]['type']);
        $this->assertFalse($body['tools'][0]['function']['strict']);
        $this->assertTrue($body['tools'][1]['function']['strict']);
    }

    public function testAnotherProvidersToolIdBecomesNineCharactersTheCallAndItsResultAgreeOn(): void
    {
        // `createMistralToolCallIdNormalizer()`: Mistral takes exactly nine alphanumeric
        // characters. A foreign id is a hash of itself, the same for the call and its result; an id
        // that already is nine alphanumerics is kept. pig used to cut and pad (`ABCDEFGHI`).
        $foreign = new AssistantMessage(
            [new ToolCall('toolu_01ABCdef-ghij', 'read', []), new ToolCall('call12345', 'read', [])],
            Api::AnthropicMessages,
            'anthropic',
            'claude-x',
            new Usage(),
            StopReason::ToolUse,
        );
        $context = new Context([
            new UserMessage('hi'),
            $foreign,
            new ToolResultMessage('toolu_01ABCdef-ghij', 'read', [new TextContent('one')]),
            new ToolResultMessage('call12345', 'read', [new TextContent('two')]),
        ]);

        [, $body] = $this->capture($this->model(), $context);
        $calls = $body['messages'][1]['tool_calls'];

        $this->assertSame(1, preg_match('/^[a-zA-Z0-9]{9}$/', $calls[0]['id']), $calls[0]['id']);
        $this->assertNotSame('toolu01AB', $calls[0]['id'], 'a hash, not a cut');
        $this->assertSame($calls[0]['id'], $body['messages'][2]['tool_call_id']);
        $this->assertSame('call12345', $calls[1]['id']);
        $this->assertSame('call12345', $body['messages'][3]['tool_call_id']);
    }

    public function testASessionIsThePromptCacheKeyAndTheAffinityHeaderUnlessCachingIsOff(): void
    {
        // `shouldUsePromptCaching()`: a session id and not `cacheRetention: "none"`.
        [$head, $body] = $this->capture($this->model(), new Context([new UserMessage('hi')]), new MistralOptions(apiKey: 'k', sessionId: 'sess-1'));
        $this->assertSame('sess-1', $body['prompt_cache_key']);
        $this->assertStringContainsStringIgnoringCase("x-affinity: sess-1\r\n", $head);

        [$head, $body] = $this->capture($this->model(), new Context([new UserMessage('hi')]), new MistralOptions(apiKey: 'k', cacheRetention: 'none', sessionId: 'sess-1'));
        $this->assertArrayNotHasKey('prompt_cache_key', $body);
        $this->assertStringNotContainsStringIgnoringCase('x-affinity', $head);
    }

    public function testAnEmptyToolResultAndAnErrorAreSaidInUpstreamsWords(): void
    {
        // `buildToolResultText()`.
        $context = new Context([
            new UserMessage('hi'),
            $this->assistant([new ToolCall('abcDEF123', 'read', []), new ToolCall('abcDEF124', 'read', [])]),
            new ToolResultMessage('abcDEF123', 'read', []),
            new ToolResultMessage('abcDEF124', 'read', [new TextContent('  boom ')], true),
        ]);

        [, $body] = $this->capture($this->model(), $context);

        $this->assertSame('(no tool output)', $body['messages'][2]['content'][0]['text']);
        $this->assertSame('[tool error] boom', $body['messages'][3]['content'][0]['text']);
    }

    // ---- what Stream::simple() asks for --------------------------------------------------------

    public function testAModelWithEffortsIsAskedForThemAndOneWithoutGetsThePromptMode(): void
    {
        // Upstream's `streamSimple()`: "Models with a thinking level map use `reasoning_effort`;
        // other reasoning models use `prompt_mode`." A level the map has no word for is `high`, and
        // thinking off is the map's `off` when it has one.
        $efforts = $this->model(reasoning: true, levels: ['off' => 'none', 'minimal' => null, 'low' => null, 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => null]);

        $body = $this->simple($efforts, ReasoningEffort::High);
        $this->assertSame('high', $body['reasoning_effort']);
        $this->assertArrayNotHasKey('prompt_mode', $body);

        // `low` clamps up to `high`, the map's only level.
        $this->assertSame('high', $this->simple($efforts, ReasoningEffort::Low)['reasoning_effort']);
        $this->assertSame('none', $this->simple($efforts, null)['reasoning_effort']);

        $magistral = $this->model(reasoning: true);
        $this->assertSame('reasoning', $this->simple($magistral, ReasoningEffort::Medium)['prompt_mode']);
        $this->assertArrayNotHasKey('reasoning_effort', $this->simple($magistral, ReasoningEffort::Medium));
        $this->assertArrayNotHasKey('prompt_mode', $this->simple($magistral, null));

        $plain = $this->simple($this->model(), ReasoningEffort::High);
        $this->assertArrayNotHasKey('prompt_mode', $plain);
        $this->assertArrayNotHasKey('reasoning_effort', $plain);
    }

    // ---- the stream ----------------------------------------------------------------------------

    public function testTextThinkingAndAToolCallStreamAsBlocks(): void
    {
        $url = $this->serve([
            ['id' => 'cmpl-1', 'choices' => [['delta' => ['content' => [['type' => 'thinking', 'thinking' => [['type' => 'text', 'text' => 'hmm']]]]]]]],
            // An empty string between thinking and text is no block (upstream's GLM comment).
            ['id' => 'cmpl-2', 'choices' => [['delta' => ['content' => '']]]],
            ['choices' => [['delta' => ['content' => 'Hel']]]],
            ['choices' => [['delta' => ['content' => [['type' => 'text', 'text' => 'lo']]]]]],
            ['choices' => [['delta' => ['tool_calls' => [['id' => 'abc123XYZ', 'index' => 0, 'function' => ['name' => 'read', 'arguments' => '{"path":']]]]]]],
            ['choices' => [['delta' => ['tool_calls' => [['index' => 0, 'function' => ['name' => 'read', 'arguments' => '"a"}']]]]]]],
            ['choices' => [['delta' => [], 'finish_reason' => 'tool_calls']], 'usage' => [
                'prompt_tokens' => 100, 'completion_tokens' => 20, 'total_tokens' => 120,
                'prompt_tokens_details' => ['cached_tokens' => 30],
            ]],
        ], done: true);

        [$types, $message] = $this->collect($url);

        $this->assertSame([
            'StartEvent',
            'ThinkingStartEvent', 'ThinkingDeltaEvent', 'ThinkingEndEvent',
            'TextStartEvent', 'TextDeltaEvent', 'TextDeltaEvent', 'TextEndEvent',
            'ToolCallStartEvent', 'ToolCallDeltaEvent', 'ToolCallDeltaEvent', 'ToolCallEndEvent',
            'DoneEvent',
        ], $types);
        $this->assertEquals([
            new ThinkingContent('hmm'),
            new TextContent('Hello'),
            new ToolCall('abc123XYZ', 'read', ['path' => 'a']),
        ], $message->content);
        $this->assertSame(StopReason::ToolUse, $message->stopReason);
        $this->assertSame('tool_calls', $message->rawStopReason);
        // The first chunk id, kept.
        $this->assertSame('cmpl-1', $message->responseId);
        // Cached prompt tokens are taken out of the input they were counted in.
        $this->assertSame([70, 20, 30, 0, 120], [$message->usage->input, $message->usage->output, $message->usage->cacheRead, $message->usage->cacheWrite, $message->usage->totalTokens]);
    }

    public function testACallWithNoIdIsGivenOneFromItsIndexAndObjectArgumentsAreItsJson(): void
    {
        // `deriveMistralToolCallId("toolcall:" + index, 0)`, and `JSON.stringify(arguments)` when
        // the arguments arrive as an object.
        $url = $this->serve([
            ['choices' => [['delta' => ['tool_calls' => [['id' => 'null', 'index' => 2, 'function' => ['name' => 'ls', 'arguments' => ['dir' => '.']]]]]]]],
            ['choices' => [['delta' => [], 'finish_reason' => 'tool_calls']]],
        ]);

        [, $message] = $this->collect($url);

        $this->assertSame(1, preg_match('/^[a-zA-Z0-9]{9}$/', $message->content[0]->id));
        $this->assertSame(['dir' => '.'], $message->content[0]->arguments);
    }

    /** @return iterable<string, array{0: string, 1: StopReason, 2: string|null}> */
    public static function finishReasons(): iterable
    {
        yield 'stop' => ['stop', StopReason::Stop, null];
        yield 'length' => ['length', StopReason::Length, null];
        yield 'model_length' => ['model_length', StopReason::Length, null];
        // "Mistral reports transient server failures this way; "server error" makes the message
        // retryable."
        yield 'error' => ['error', StopReason::Error, 'Provider stopped with: error (server error)'];
        yield 'anything else' => ['weird', StopReason::Error, 'Provider stopped with: weird'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('finishReasons')]
    public function testAFinishReasonMapsAsUpstreamsMapChatStopReasonDoes(string $reason, StopReason $stop, ?string $error): void
    {
        $url = $this->serve([['choices' => [['delta' => ['content' => 'x'], 'finish_reason' => $reason]]]]);

        [, $message] = $this->collect($url);

        $this->assertSame($stop, $message->stopReason);
        $this->assertSame($error, $message->errorMessage);
        $this->assertSame($reason, $message->rawStopReason);
    }

    public function testAStreamThatEndsWithoutAFinishReasonIsAnError(): void
    {
        $url = $this->serve([['choices' => [['delta' => ['content' => 'half']]]]]);

        [$types, $message] = $this->collect($url);

        $this->assertSame('ErrorEvent', end($types));
        $this->assertSame('Mistral stream ended without a finish reason', $message->errorMessage);
    }

    public function testAnEventThatIsNoChunkEndsTheTurn(): void
    {
        // `parseMistralEvent()`: anything but an object with a `choices` list is refused by name.
        $url = $this->serve([['object' => 'not a chunk']]);

        [, $message] = $this->collect($url);

        $this->assertSame(StopReason::Error, $message->stopReason);
        $this->assertSame('Invalid Mistral streaming event', $message->errorMessage);
    }

    public function testARefusedRequestReadsAsUpstreamsFormatMistralErrorDoes(): void
    {
        $body = '{"object":"error","message":"Unauthorized"}';
        $url = $this->server->start(["HTTP/1.1 401 Unauthorized\r\nContent-Length: " . strlen($body) . "\r\n\r\n" . $body]);

        $this->assertSame('Mistral API error (401): {"object":"error","message":"Unauthorized"}', $this->collect($url)[1]->errorMessage);

        // An empty body: the status text.
        $this->server = new CannedServer();
        $url = $this->server->start(["HTTP/1.1 503 Service Unavailable\r\nContent-Length: 0\r\n\r\n"]);

        $this->assertSame('Mistral API error (503): Service Unavailable', $this->collect($url)[1]->errorMessage);
    }

    public function testAPayloadThatIsNotJsonEndsTheTurnWithV8sMessage(): void
    {
        // `JSON.parse(data)` in `parseMistralEvent()`; pig printed PHP's `Syntax error`.
        $url = $this->server->start([
            "HTTP/1.1 200 OK\r\nContent-Type: text/event-stream\r\nTransfer-Encoding: chunked\r\n\r\n",
            $this->chunk("data: {\"choices\": nope}\n\n"),
            "0\r\n\r\n",
        ]);

        $this->assertSame('Unexpected token \'o\', "{"choices": nope}" is not valid JSON', $this->collect($url)[1]->errorMessage);
    }

    public function testTheResponseHeadersHaveTimeoutMsToArrive(): void
    {
        // Upstream: "The timeout covers only the wait for response headers", `timeoutMs ?? 60_000`,
        // and a deadline that passes first is `Mistral response headers timed out after <ms>ms`.
        $url = $this->server->start([], closeAfter: false);

        $message = Async::run(function () use ($url): AssistantMessage {
            $stream = (new Mistral())->stream($this->model(baseUrl: $url), new Context([new UserMessage('hi')]), new MistralOptions(apiKey: 'k', timeoutMs: 150));

            foreach ($stream as $ignored) {
            }

            return $stream->result()->await();
        });

        $this->assertSame(StopReason::Error, $message->stopReason);
        $this->assertSame('Mistral response headers timed out after 150ms', $message->errorMessage);
    }

    public function testAStreamLongerThanTheTimeoutIsNotCutOff(): void
    {
        // "Long streams (e.g. extended thinking) must not be cut off by a fixed deadline": the
        // pieces below arrive 10ms apart, well past a 30ms deadline, and all of them are read.
        $pieces = ["HTTP/1.1 200 OK\r\nContent-Type: text/event-stream\r\nTransfer-Encoding: chunked\r\n\r\n"];

        foreach (['a', 'b', 'c', 'd', 'e', 'f'] as $piece) {
            $pieces[] = $this->chunk('data: ' . json_encode(['choices' => [['delta' => ['content' => $piece]]]]) . "\n\n");
        }

        $pieces[] = $this->chunk('data: ' . json_encode(['choices' => [['delta' => [], 'finish_reason' => 'stop']]]) . "\n\n");
        $pieces[] = "0\r\n\r\n";
        $url = $this->server->start($pieces);

        $message = Async::run(function () use ($url): AssistantMessage {
            $stream = (new Mistral())->stream($this->model(baseUrl: $url), new Context([new UserMessage('hi')]), new MistralOptions(apiKey: 'k', timeoutMs: 30));

            foreach ($stream as $ignored) {
            }

            return $stream->result()->await();
        });

        $this->assertSame(StopReason::Stop, $message->stopReason);
        $this->assertSame('abcdef', $message->content[0]->text);
    }

    public function testTheUserAgentIsPisUnderTheModelsOwn(): void
    {
        // `buildMistralHeaders()`: `"User-Agent": getPiUserAgent()` first, the model's headers over it.
        [$head] = $this->capture($this->model(), new Context([new UserMessage('hi')]));
        $this->assertStringContainsString('user-agent: ' . PigUserAgent::get() . "\r\n", $head);

        $model = $this->model();
        $model = new Model($model->id, $model->name, $model->api, $model->provider, $model->baseUrl, $model->contextWindow, $model->maxTokens, $model->reasoning, $model->input, $model->pricing, ['User-Agent' => 'mine/2']);
        [$head] = $this->capture($model, new Context([new UserMessage('hi')]));
        $this->assertSame(1, preg_match_all('/^user-agent: /mi', $head));
        $this->assertStringContainsString("user-agent: mine/2\r\n", $head);
    }

    // ---- helpers -------------------------------------------------------------------------------

    /** @param list<\Pig\Ai\AssistantContent> $content */
    private function assistant(array $content): AssistantMessage
    {
        return new AssistantMessage($content, Api::MistralConversations, 'mistral', 'test-model', new Usage(), StopReason::Stop);
    }

    /** @param array<string, string|null> $levels */
    private function model(string $baseUrl = 'http://127.0.0.1:1', bool $reasoning = false, array $levels = []): Model
    {
        return new Model(
            'test-model',
            'Test Model',
            Api::MistralConversations,
            'mistral',
            rtrim($baseUrl, '/'),
            128_000,
            16_384,
            $reasoning,
            ['text', 'image'],
            new Pricing(input: 1.0, output: 2.0),
            thinkingLevelMap: $levels,
        );
    }

    /** @return array{0: string, 1: array<string, mixed>} the head and the decoded body that went out */
    private function capture(Model $model, Context $context, ?MistralOptions $options = null): array
    {
        $this->server = new CannedServer();
        $url = $this->serve([['choices' => [['delta' => [], 'finish_reason' => 'stop']]]]);
        $model = new Model($model->id, $model->name, $model->api, $model->provider, rtrim($url, '/'), $model->contextWindow, $model->maxTokens, $model->reasoning, $model->input, $model->pricing, $model->headers, $model->compat, $model->thinkingLevelMap);

        Async::run(function () use ($model, $context, $options): void {
            $stream = (new Mistral())->stream($model, $context, $options ?? new MistralOptions(apiKey: 'test-key'));

            foreach ($stream as $ignored) {
            }
        });

        return [$this->server->receivedHead(), $this->server->receivedJson()];
    }

    /** @return array<string, mixed> the body `Stream::simple()` sent for this level */
    private function simple(Model $model, ?ReasoningEffort $reasoning): array
    {
        $this->server = new CannedServer();
        $url = $this->serve([['choices' => [['delta' => [], 'finish_reason' => 'stop']]]]);
        $model = new Model($model->id, $model->name, $model->api, $model->provider, rtrim($url, '/'), $model->contextWindow, $model->maxTokens, $model->reasoning, $model->input, $model->pricing, $model->headers, $model->compat, $model->thinkingLevelMap);

        Async::run(function () use ($model, $reasoning): void {
            $stream = Stream::simple($model, new Context([new UserMessage('hi')]), new SimpleStreamOptions(apiKey: 'k', reasoning: $reasoning));

            foreach ($stream as $ignored) {
            }
        });

        return $this->server->receivedJson();
    }

    /** @return array{0: list<string>, 1: AssistantMessage} */
    private function collect(string $url): array
    {
        return Async::run(function () use ($url): array {
            $stream = (new Mistral())->stream($this->model(baseUrl: $url), new Context([new UserMessage('hi')]), new MistralOptions(apiKey: 'test-key'));
            $types = [];

            foreach ($stream as $event) {
                $types[] = (new \ReflectionClass($event))->getShortName();
            }

            return [$types, $stream->result()->await()];
        });
    }

    /** @param list<array<string, mixed>> $chunks */
    private function serve(array $chunks, bool $done = false): string
    {
        $pieces = ["HTTP/1.1 200 OK\r\nContent-Type: text/event-stream\r\nTransfer-Encoding: chunked\r\n\r\n"];

        foreach ($chunks as $chunk) {
            $pieces[] = $this->chunk('data: ' . json_encode($chunk) . "\n\n");
        }

        if ($done) {
            $pieces[] = $this->chunk("data: [DONE]\n\n");
        }

        $pieces[] = "0\r\n\r\n";

        return $this->server->start($pieces);
    }

    private function chunk(string $body): string
    {
        return sprintf("%x\r\n%s\r\n", strlen($body), $body);
    }
}
