<?php

declare(strict_types=1);

namespace Pig\Ai\Test\Providers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pig\Ai\Api;
use Pig\Ai\AssistantMessage;
use Pig\Ai\Context;
use Pig\Ai\ImageContent;
use Pig\Ai\Model;
use Pig\Ai\OpenAiCompat;
use Pig\Ai\Pricing;
use Pig\Ai\Providers\OpenAiCompletions;
use Pig\Ai\Providers\OpenAiOptions;
use Pig\Ai\ReasoningEffort;
use Pig\Ai\StopReason;
use Pig\Ai\TextContent;
use Pig\Ai\ThinkingContent;
use Pig\Ai\Tool;
use Pig\Ai\ToolCall;
use Pig\Ai\ToolResultMessage;
use Pig\Ai\Usage;
use Pig\Ai\UserMessage;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\Test\CannedServer;

/**
 * The chat-completions protocol, against a server answering from a script.
 *
 * The interesting difference from Anthropic is that nothing here says a content block has
 * started or ended — a block runs until something of a different kind arrives — so most
 * of these are about where the boundaries land.
 */
final class OpenAiCompletionsTest extends TestCase
{
    private CannedServer $server;

    #[\Override]
    protected function setUp(): void
    {
        Loop::reset();
        $this->server = new CannedServer();
    }

    // ---- reading the stream ---------------------------------------------------------

    public function testATextResponseArrivesAsEventsAndThenAsAMessage(): void
    {
        $url = $this->serve([
            ['choices' => [['delta' => ['content' => 'Hel']]]],
            ['choices' => [['delta' => ['content' => 'lo']]]],
            ['choices' => [['delta' => [], 'finish_reason' => 'stop']]],
            ['usage' => ['prompt_tokens' => 16, 'completion_tokens' => 5]],
        ]);

        [$types, $message] = $this->collect($url, new Context([new UserMessage('hi')]));

        $this->assertSame([
            'StartEvent',
            'TextStartEvent',
            'TextDeltaEvent',
            'TextDeltaEvent',
            'TextEndEvent',
            'DoneEvent',
        ], $types);

        $this->assertSame('Hello', $message->content[0]->text);
        $this->assertSame(StopReason::Stop, $message->stopReason);
    }

    public function testTheFinalDoneMarkerIsNotMistakenForJson(): void
    {
        // The stream ends with a literal `[DONE]`, which is the one line in it that is
        // not a JSON object.
        $url = $this->serve([['choices' => [['delta' => ['content' => 'hi'], 'finish_reason' => 'stop']]]], done: true);

        [, $message] = $this->collect($url, new Context([new UserMessage('hi')]));

        $this->assertSame('hi', $message->content[0]->text);
        $this->assertSame(StopReason::Stop, $message->stopReason);
    }

    public function testReasoningBecomesAThinkingBlockWhicheverFieldItArrivesIn(): void
    {
        foreach (['reasoning_content', 'reasoning', 'reasoning_text'] as $field) {
            $url = $this->serve([
                ['choices' => [['delta' => [$field => 'let me think']]]],
                ['choices' => [['delta' => ['content' => 'the answer'], 'finish_reason' => 'stop']]],
            ]);

            [, $message] = $this->collect($url, new Context([new UserMessage('hi')]));

            $this->assertInstanceOf(ThinkingContent::class, $message->content[0], $field);
            $this->assertSame('let me think', $message->content[0]->thinking);

            // The field it came in is kept, because it is the field it has to go back in.
            $this->assertSame($field, $message->content[0]->thinkingSignature);
        }
    }

    public function testABlockEndsWhenSomethingOfADifferentKindArrives(): void
    {
        $url = $this->serve([
            ['choices' => [['delta' => ['reasoning' => 'thinking...']]]],
            ['choices' => [['delta' => ['content' => 'answering']]]],
            ['choices' => [['delta' => [], 'finish_reason' => 'stop']]],
        ]);

        [$types, $message] = $this->collect($url, new Context([new UserMessage('hi')]));

        // Nothing in the protocol says the thinking stopped; the text arriving is what
        // says it.
        $this->assertSame([
            'StartEvent',
            'ThinkingStartEvent',
            'ThinkingDeltaEvent',
            'ThinkingEndEvent',
            'TextStartEvent',
            'TextDeltaEvent',
            'TextEndEvent',
            'DoneEvent',
        ], $types);

        $this->assertCount(2, $message->content);
    }

    public function testAToolCallIsAssembledFromItsPieces(): void
    {
        $url = $this->serve([
            ['choices' => [['delta' => ['tool_calls' => [['id' => 'c1', 'function' => ['name' => 'read', 'arguments' => '{"pa']]]]]]],
            ['choices' => [['delta' => ['tool_calls' => [['function' => ['arguments' => 'th":"a.php"}']]]]]]],
            ['choices' => [['delta' => [], 'finish_reason' => 'tool_calls']]],
        ]);

        [, $message] = $this->collect($url, new Context([new UserMessage('hi')]));

        $this->assertInstanceOf(ToolCall::class, $message->content[0]);
        $this->assertSame('c1', $message->content[0]->id);
        $this->assertSame('read', $message->content[0]->name);
        $this->assertSame(['path' => 'a.php'], $message->content[0]->arguments);
        $this->assertSame(StopReason::ToolUse, $message->stopReason);
        // `finish_reason` as sent: `tool_calls` and `function_call` both map to `toolUse`.
        $this->assertSame('tool_calls', $message->rawStopReason);
    }

    public function testASecondIdMeansASecondCallAndNotMoreOfTheFirst(): void
    {
        $url = $this->serve([
            ['choices' => [['delta' => ['tool_calls' => [['id' => 'c1', 'function' => ['name' => 'read', 'arguments' => '{"path":"a"}']]]]]]],
            ['choices' => [['delta' => ['tool_calls' => [['id' => 'c2', 'function' => ['name' => 'read', 'arguments' => '{"path":"b"}']]]]]]],
            ['choices' => [['delta' => [], 'finish_reason' => 'tool_calls']]],
        ]);

        [, $message] = $this->collect($url, new Context([new UserMessage('hi')]));

        $this->assertCount(2, $message->content);
        $this->assertSame('c1', $message->content[0]->id);
        $this->assertSame('c2', $message->content[1]->id);
    }

    public function testEncryptedReasoningIsKeptWithTheCallItBelongsTo(): void
    {
        // OpenRouter's shape: a reasoning model's chain of thought comes back as an opaque blob
        // addressed to a tool call by id, not as text. Nothing read this field, so the reasoning was
        // lost — and with it the model's place in a multi-step task.
        $url = $this->serve([
            ['choices' => [['delta' => ['tool_calls' => [['id' => 'c1', 'function' => ['name' => 'read', 'arguments' => '{}']]]]]]],
            ['choices' => [['delta' => ['reasoning_details' => [
                ['type' => 'reasoning.encrypted', 'id' => 'c1', 'data' => 'AAAA'],
            ]]]]],
            ['choices' => [['delta' => [], 'finish_reason' => 'tool_calls']]],
        ]);

        [, $message] = $this->collect($url, new Context([new UserMessage('hi')]));
        $call = $message->content[0];

        $this->assertInstanceOf(ToolCall::class, $call);
        $this->assertSame(
            ['type' => 'reasoning.encrypted', 'id' => 'c1', 'data' => 'AAAA'],
            json_decode((string) $call->thoughtSignature, true),
            'the whole detail, because that is what has to go back',
        );
    }

    public function testEncryptedReasoningGoesBackBesideTheCalls(): void
    {
        $detail = ['type' => 'reasoning.encrypted', 'id' => 'c1', 'data' => 'AAAA'];

        $context = new Context([
            new UserMessage('hi'),
            $this->assistant([new ToolCall('c1', 'read', ['path' => 'a'], (string) json_encode($detail))]),
            new ToolResultMessage('c1', 'read', [new TextContent('ok')], false),
            new UserMessage('go on'),
        ]);

        $this->send($context);

        $messages = $this->server->receivedJson()['messages'];
        $assistant = array_values(array_filter($messages, static fn (array $m): bool => $m['role'] === 'assistant'))[0];

        // The other half. Sent as the object it arrived as: a string here is rejected.
        $this->assertSame([$detail], $assistant['reasoning_details']);
        $this->assertSame('c1', $assistant['tool_calls'][0]['id']);
    }

    public function testCachedTokensAreTakenOutOfTheInputAndReasoningIsNotAddedToTheOutputTwice(): void
    {
        $url = $this->serve([
            ['choices' => [['delta' => ['content' => 'hi'], 'finish_reason' => 'stop']]],
            ['usage' => [
                'prompt_tokens' => 100,
                'completion_tokens' => 10,
                'prompt_tokens_details' => ['cached_tokens' => 40],
                'completion_tokens_details' => ['reasoning_tokens' => 5],
            ]],
        ]);

        [, $message] = $this->collect($url, new Context([new UserMessage('hi')]));

        // `prompt_tokens` includes the cached ones; input here means what was paid for
        // at the input rate.
        $this->assertSame(60, $message->usage->input);
        $this->assertSame(40, $message->usage->cacheRead);

        // `completion_tokens` already includes the 5 reasoning tokens. This used to be 15: pig
        // added `reasoning_tokens` on top as a Groq workaround, which upstream does not do, and
        // every OpenAI-compatible reasoning turn was billed and counted with its thinking twice.
        $this->assertSame(10, $message->usage->output);
        $this->assertSame(5, $message->usage->reasoning);
        $this->assertSame(110, $message->usage->totalTokens);
    }

    /** @return iterable<string, array{array<string, mixed>, int}> */
    public static function cacheReadFields(): iterable
    {
        // Upstream's list: providers disagree on where the cache hits go.
        yield 'OpenAI / OpenRouter' => [['prompt_tokens_details' => ['cached_tokens' => 30]], 30];
        yield 'DeepSeek' => [['prompt_cache_hit_tokens' => 30], 30];
        yield 'Kimi' => [['cached_tokens' => 30], 30];

        // The first one present wins, in that order — even a 0, as with upstream's `??`.
        yield 'details beat DeepSeek and Kimi' => [
            ['prompt_tokens_details' => ['cached_tokens' => 0], 'prompt_cache_hit_tokens' => 30, 'cached_tokens' => 20],
            0,
        ];
        yield 'DeepSeek beats Kimi' => [['prompt_cache_hit_tokens' => 30, 'cached_tokens' => 20], 30];
    }

    /** @param array<string, mixed> $fields */
    #[DataProvider('cacheReadFields')]
    public function testCacheReadsAreFoundWhereverTheProviderPutsThem(array $fields, int $cacheRead): void
    {
        $url = $this->serve([
            ['choices' => [['delta' => ['content' => 'hi'], 'finish_reason' => 'stop']]],
            ['usage' => ['prompt_tokens' => 100, 'completion_tokens' => 10] + $fields],
        ]);

        [, $message] = $this->collect($url, new Context([new UserMessage('hi')]));

        // Reading only `prompt_tokens_details` missed DeepSeek's and Kimi's cache hits entirely,
        // so they were priced as fresh input.
        $this->assertSame($cacheRead, $message->usage->cacheRead);
        $this->assertSame(100 - $cacheRead, $message->usage->input);
    }

    public function testUsageOnTheChoiceIsReadWhenTheChunkHasNoneOfItsOwn(): void
    {
        // Moonshot puts the usage on `choices[0]` rather than on the chunk. Upstream falls back
        // to it; pig read only the chunk's, so every Moonshot turn was counted as free.
        $url = $this->serve([
            ['choices' => [['delta' => ['content' => 'hi']]]],
            ['choices' => [[
                'delta' => [],
                'finish_reason' => 'stop',
                'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 10, 'cached_tokens' => 30],
            ]]],
        ]);

        [, $message] = $this->collect($url, new Context([new UserMessage('hi')]));

        $this->assertSame(70, $message->usage->input);
        $this->assertSame(10, $message->usage->output);
        $this->assertSame(30, $message->usage->cacheRead);
    }

    public function testTheChunksOwnUsageBeatsTheChoices(): void
    {
        // Only a fallback: a chunk that carries both is read from the chunk, as upstream's
        // `!chunk.usage` guard has it.
        $url = $this->serve([
            ['choices' => [[
                'delta' => ['content' => 'hi'],
                'finish_reason' => 'stop',
                'usage' => ['prompt_tokens' => 999, 'completion_tokens' => 999],
            ]], 'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 10]],
        ]);

        [, $message] = $this->collect($url, new Context([new UserMessage('hi')]));

        $this->assertSame(100, $message->usage->input);
        $this->assertSame(10, $message->usage->output);
    }

    public function testCacheWritesAreTheirOwnCountAndAreNotSubtractedFromTheReads(): void
    {
        // OpenRouter-compatible providers report writes beside the reads. Both are inside
        // `prompt_tokens`, so both come out of the input; the reads stay as reported.
        $url = $this->serve([
            ['choices' => [['delta' => ['content' => 'hi'], 'finish_reason' => 'stop']]],
            ['usage' => [
                'prompt_tokens' => 100,
                'completion_tokens' => 10,
                'prompt_tokens_details' => ['cached_tokens' => 30, 'cache_write_tokens' => 20],
            ]],
        ]);

        [, $message] = $this->collect($url, new Context([new UserMessage('hi')]));

        $this->assertSame(50, $message->usage->input);
        $this->assertSame(30, $message->usage->cacheRead);
        $this->assertSame(20, $message->usage->cacheWrite);
        $this->assertSame(110, $message->usage->totalTokens);
    }

    public function testReasoningTokensAreKeptAsTheirOwnSplitAndZeroWhenNoneWereReported(): void
    {
        // Upstream's `usage.reasoning`: a provider that reports the breakdown always sets it,
        // to 0 when there was none, so "no reasoning" and "never said" stay different things.
        $url = $this->serve([
            ['choices' => [['delta' => ['content' => 'hi'], 'finish_reason' => 'stop']]],
            ['usage' => ['prompt_tokens' => 10, 'completion_tokens' => 10, 'completion_tokens_details' => ['reasoning_tokens' => 4]]],
        ]);

        [, $message] = $this->collect($url, new Context([new UserMessage('hi')]));

        $this->assertSame(4, $message->usage->reasoning);

        $this->server = new CannedServer();
        $url = $this->serve([
            ['choices' => [['delta' => ['content' => 'hi'], 'finish_reason' => 'stop']]],
            ['usage' => ['prompt_tokens' => 10, 'completion_tokens' => 10]],
        ]);

        [, $plain] = $this->collect($url, new Context([new UserMessage('hi')]));

        $this->assertSame(0, $plain->usage->reasoning);
    }

    public function testTheFirstChunkIdIsTheResponseIdAndOnlyAnotherModelIsTheResponseModel(): void
    {
        // Every chunk of one completion carries the same id; upstream keeps the first it sees
        // (`||=`), and the model only when it is not the one that was asked for — which is what a
        // router reports when it picked something.
        $url = $this->serve([
            ['id' => '', 'model' => '', 'choices' => [['delta' => ['content' => 'h']]]],
            ['id' => 'chatcmpl-1', 'model' => 'openrouter/picked-model', 'choices' => [['delta' => ['content' => 'i']]]],
            ['id' => 'chatcmpl-2', 'model' => 'another', 'choices' => [['delta' => [], 'finish_reason' => 'stop']]],
        ]);

        [, $message] = $this->collect($url, new Context([new UserMessage('hi')]));

        $this->assertSame('chatcmpl-1', $message->responseId, 'an empty id is no id, and the first real one wins');
        $this->assertSame('openrouter/picked-model', $message->responseModel);

        $this->server = new CannedServer();
        $url = $this->serve([
            ['id' => 'chatcmpl-3', 'model' => 'test-model', 'choices' => [['delta' => ['content' => 'hi'], 'finish_reason' => 'stop']]],
        ]);

        [, $same] = $this->collect($url, new Context([new UserMessage('hi')]));

        $this->assertSame('chatcmpl-3', $same->responseId);
        $this->assertNull($same->responseModel, 'the model that was asked for is not worth repeating');
    }

    public function testAFailureComesBackAsTheStreamsResultAndNotAsAThrow(): void
    {
        $url = $this->server->start([
            "HTTP/1.1 429 Too Many Requests\r\nContent-Type: application/json\r\n\r\n",
            json_encode(['error' => ['message' => 'slow down']]),
        ]);

        [$types, $message] = $this->collect($url, new Context([new UserMessage('hi')]));

        $this->assertContains('ErrorEvent', $types);
        $this->assertSame(StopReason::Error, $message->stopReason);
        $this->assertStringContainsString('slow down', (string) $message->errorMessage);
    }

    // ---- writing the request ----------------------------------------------------------

    public function testTheRequestCarriesTheModelTheMessagesAndTheKey(): void
    {
        $this->send(new Context([new UserMessage('hi')]));

        $body = $this->server->receivedJson();

        $this->assertSame('test-model', $body['model']);
        $this->assertTrue($body['stream']);
        $this->assertTrue($body['stream_options']['include_usage']);
        $this->assertSame([['type' => 'text', 'text' => 'hi']], $body['messages'][0]['content']);
        $this->assertStringContainsString('Bearer test-key', $this->server->receivedHead());
    }

    public function testACallWithNoArgumentsGoesOutAsAnObjectAndNotAnEmptyList(): void
    {
        // A tool that takes nothing, or a call whose arguments never arrived, leaves an empty
        // PHP array — and an empty PHP array encodes as `[]`, which is not what `arguments`
        // is. Anthropic's and Google's arms already guard this; these two did not.
        $this->send(new Context([
            new UserMessage('hi'),
            $this->assistant([new ToolCall('c1', 'now', [])]),
            new ToolResultMessage('c1', 'now', [new TextContent('12:00')], false),
            new UserMessage('thanks'),
        ]));

        $body = $this->server->receivedJson();

        $this->assertSame('{}', $body['messages'][1]['tool_calls'][0]['function']['arguments']);
    }

    public function testAReasoningModelsSystemPromptGoesInADeveloperTurn(): void
    {
        $this->send(new Context([new UserMessage('hi')], 'be helpful'), $this->model(reasoning: true));

        $this->assertSame('developer', $this->server->receivedJson()['messages'][0]['role']);
    }

    public function testAModelThatDoesNotReasonGetsAPlainSystemTurn(): void
    {
        $this->send(new Context([new UserMessage('hi')], 'be helpful'));

        $this->assertSame('system', $this->server->receivedJson()['messages'][0]['role']);
    }

    public function testToolsGoOutAsFunctions(): void
    {
        $tool = new Tool('read', 'Read a file', ['type' => 'object', 'properties' => ['path' => ['type' => 'string']]]);

        $this->send(new Context([new UserMessage('hi')], null, [$tool]));

        $body = $this->server->receivedJson();

        $this->assertSame('function', $body['tools'][0]['type']);
        $this->assertSame('read', $body['tools'][0]['function']['name']);
    }

    public function testAConversationWithToolCallsSendsAToolsFieldEvenWithNoTools(): void
    {
        $context = new Context([
            new UserMessage('hi'),
            $this->assistant([new ToolCall('c1', 'read', ['path' => 'a'])]),
            new ToolResultMessage('c1', 'read', [new TextContent('<?php')]),
        ]);

        $this->send($context);

        // Some proxies reject the conversation outright without it, even empty.
        $this->assertSame([], $this->server->receivedJson()['tools']);
    }

    public function testAnImageIsLeftOutForAModelThatCannotSeeOne(): void
    {
        $context = new Context([new UserMessage([new TextContent('look'), new \Pig\Ai\ImageContent('AAA', 'image/png')])]);

        $this->send($context, $this->model(images: false));

        $parts = $this->server->receivedJson()['messages'][0]['content'];

        $this->assertCount(1, $parts);
        $this->assertSame('text', $parts[0]['type']);
    }

    public function testAToolResultsImagesFollowAsAUserTurnOfTheirOwn(): void
    {
        $context = new Context([
            new UserMessage('hi'),
            $this->assistant([new ToolCall('c1', 'shot', [])]),
            new ToolResultMessage('c1', 'shot', [new \Pig\Ai\ImageContent('AAA', 'image/png')]),
        ]);

        $this->send($context, $this->model(images: true));

        $messages = $this->server->receivedJson()['messages'];
        $last = $messages[count($messages) - 1];

        // A tool result has nowhere to put an image, so it says so and the image follows.
        $this->assertSame('tool', $messages[count($messages) - 2]['role']);
        $this->assertSame('(see attached image)', $messages[count($messages) - 2]['content']);
        $this->assertSame('user', $last['role']);
        $this->assertSame('image_url', $last['content'][1]['type']);
    }

    public function testAnEmptyAssistantTurnIsLeftOutEntirely(): void
    {
        // What an aborted turn leaves behind. Every endpoint rejects a turn with neither
        // content nor tool calls.
        $context = new Context([new UserMessage('hi'), $this->assistant([]), new UserMessage('still there?')]);

        $this->send($context);

        $roles = array_column($this->server->receivedJson()['messages'], 'role');

        $this->assertSame(['user', 'user'], $roles);
    }

    public function testAToolCallWithNoResultGetsOneInventedForIt(): void
    {
        // An interrupted turn leaves a dangling call, and every provider rejects the
        // conversation rather than ignoring it.
        $context = new Context([
            new UserMessage('hi'),
            $this->assistant([new ToolCall('c1', 'read', ['path' => 'a'])]),
            new UserMessage('never mind'),
        ]);

        $this->send($context);

        $messages = $this->server->receivedJson()['messages'];

        $this->assertSame('tool', $messages[2]['role']);
        $this->assertSame('No result provided', $messages[2]['content']);
        $this->assertSame('c1', $messages[2]['tool_call_id']);
    }

    public function testThinkingFromAnotherProviderBecomesPlainText(): void
    {
        $context = new Context([
            new UserMessage('hi'),
            new AssistantMessage(
                [new ThinkingContent('deep thoughts', 'sig'), new TextContent('the answer')],
                Api::AnthropicMessages,
                'anthropic',
                'claude-sonnet-4-5',
                new Usage(),
                StopReason::Stop,
            ),
            new UserMessage('go on'),
        ]);

        $this->send($context);

        $assistant = $this->server->receivedJson()['messages'][1];

        // A signed thinking block means nothing to a model that did not sign it, so it
        // travels as text rather than claiming to be reasoning this model did — untagged, as
        // upstream sends it, so the model does not learn to mimic the tags.
        $this->assertStringNotContainsString('<thinking>', (string) json_encode($assistant));
        $this->assertStringContainsString('deep thoughts', (string) json_encode($assistant));
    }

    public function testReasoningEffortIsSentOnlyWhenTheModelReasons(): void
    {
        $this->send(new Context([new UserMessage('hi')]), $this->model(reasoning: true), ReasoningEffort::High);
        $this->assertSame('high', $this->server->receivedJson()['reasoning_effort']);

        $this->server = new CannedServer();
        $this->send(new Context([new UserMessage('hi')]), $this->model(), ReasoningEffort::High);
        $this->assertFalse(array_key_exists('reasoning_effort', $this->server->receivedJson()));
    }

    public function testAModelsOwnWordForAnEffortIsWhatGetsSent(): void
    {
        $this->send(
            new Context([new UserMessage('hi')]),
            $this->model(reasoning: true, thinkingLevelMap: ['high' => 'max']),
            ReasoningEffort::High,
        );

        $this->assertSame('max', $this->server->receivedJson()['reasoning_effort']);
    }

    public function testAnEffortTheMapDoesNotMentionIsSentUnchanged(): void
    {
        $this->send(
            new Context([new UserMessage('hi')]),
            $this->model(reasoning: true, thinkingLevelMap: ['high' => 'max']),
            ReasoningEffort::Medium,
        );

        $this->assertSame('medium', $this->server->receivedJson()['reasoning_effort']);
    }

    public function testThinkingOffCanBeSaidInTheEndpointsOwnWord(): void
    {
        // Some endpoints want to be told thinking is off rather than being told nothing. Only a
        // string does it: `off => null` means this model has no way to be told, and the field is
        // left out rather than guessed at.
        $this->send(
            new Context([new UserMessage('hi')]),
            $this->model(reasoning: true, thinkingLevelMap: ['off' => 'none']),
            null,
        );

        $this->assertSame('none', $this->server->receivedJson()['reasoning_effort']);

        $this->server = new CannedServer();
        $this->send(
            new Context([new UserMessage('hi')]),
            $this->model(reasoning: true, thinkingLevelMap: ['off' => null]),
            null,
        );

        $this->assertFalse(array_key_exists('reasoning_effort', $this->server->receivedJson()));
    }

    public function testWithNoMapAtAllNothingIsSentForThinkingOff(): void
    {
        $this->send(new Context([new UserMessage('hi')]), $this->model(reasoning: true), null);

        $this->assertFalse(array_key_exists('reasoning_effort', $this->server->receivedJson()));
    }

    // ---- the endpoints that are not quite compatible -------------------------------------

    public function testGrokIsNotSentAReasoningEffortItRejects(): void
    {
        $compat = OpenAiCompat::detect('https://api.x.ai/v1');

        $this->assertFalse($compat->reasoningEffort);
        $this->assertFalse($compat->store);
    }

    public function testMistralGetsMaxTokensUnderItsOlderName(): void
    {
        $this->assertSame('max_tokens', OpenAiCompat::detect('https://api.mistral.ai/v1')->maxTokensField);
        $this->assertSame('max_completion_tokens', OpenAiCompat::detect('https://api.groq.com/openai/v1')->maxTokensField);
    }

    /**
     * DeepSeek too, and it is the one that was found by counting rather than by a refusal.
     *
     * It **accepts** `max_completion_tokens` and ignores it, so nothing fails and every request is
     * unbounded: `test/live.php` asked for 16 output tokens against `api.deepseek.com` and the turn
     * came back after 145, stopped because the model had finished rather than because it hit a
     * limit. The model's own `maxTokens` had never applied either.
     *
     * Reachable only through `models.json`, since pig ships no DeepSeek entry — which is why it
     * took pointing the live harness at a declared endpoint to find.
     */
    public function testDeepSeekGetsItUnderTheOlderNameToo(): void
    {
        $this->assertSame('max_tokens', OpenAiCompat::detect('https://api.deepseek.com/v1')->maxTokensField);

        // And nothing else about it is treated as strict: it takes `store`, the `developer` role
        // and `reasoning_effort` without complaint.
        $compat = OpenAiCompat::detect('https://api.deepseek.com/v1');

        $this->assertTrue($compat->store);
        $this->assertTrue($compat->developerRole);
        $this->assertTrue($compat->reasoningEffort);
    }

    public function testMistralsToolIdsAreCutAndPaddedToExactlyNine(): void
    {
        // Detected rather than hand-built: what Mistral needs is several flags at once,
        // and picking them one at a time is how a test passes against a config nobody has.
        $compat = OpenAiCompat::detect('https://api.mistral.ai/v1');
        $context = new Context([
            new UserMessage('hi'),
            $this->assistant([new ToolCall('call_abc-123456789', 'read', [])]),
            new ToolResultMessage('call_abc-123456789', 'read', [new TextContent('ok')]),
        ]);

        $this->send($context, $this->model(compat: $compat));

        $messages = $this->server->receivedJson()['messages'];
        $sent = $messages[1]['tool_calls'][0]['id'];

        $this->assertSame(9, strlen($sent));
        $this->assertSame(1, preg_match('/^[a-zA-Z0-9]+$/', $sent), "{$sent} is not alphanumeric");

        // Shortened separately for the call and the result, so it has to be the same
        // answer both times or nothing lines up.
        $this->assertSame($sent, $messages[2]['tool_call_id']);
        $this->assertSame('read', $messages[2]['name']);
    }

    public function testAShortToolIdIsPaddedDeterministically(): void
    {
        $compat = OpenAiCompat::detect('https://api.mistral.ai/v1');
        $context = new Context([
            new UserMessage('hi'),
            $this->assistant([new ToolCall('ab', 'read', [])]),
            new ToolResultMessage('ab', 'read', [new TextContent('ok')]),
        ]);

        $this->send($context, $this->model(compat: $compat));

        $messages = $this->server->receivedJson()['messages'];

        $this->assertSame(9, strlen($messages[1]['tool_calls'][0]['id']));
        $this->assertSame($messages[1]['tool_calls'][0]['id'], $messages[2]['tool_call_id']);
    }

    public function testAnEndpointWithNoThinkingFieldGetsItAsText(): void
    {
        // Upstream's `requiresThinkingAsText` arm: every thought joined by a blank line into one
        // text part, in front of the answer, with **no tags**. pig used to send one
        // `<thinking>…</thinking>` part per thought — tags in the history teach the model to
        // write them into its own answers, which is why upstream leaves them off.
        $compat = new OpenAiCompat(thinkingAsText: true);
        $context = new Context([
            new UserMessage('hi'),
            new AssistantMessage(
                [new ThinkingContent('hmm', 'reasoning'), new TextContent('so'), new ThinkingContent('and then', 'reasoning')],
                Api::OpenAiCompletions,
                'test-provider',
                'test-model',
                new Usage(),
                StopReason::Stop,
            ),
            new UserMessage('go on'),
        ]);

        $this->send($context, $this->model(compat: $compat));

        $assistant = $this->server->receivedJson()['messages'][1];

        $this->assertSame(
            [['type' => 'text', 'text' => "hmm\n\nand then"], ['type' => 'text', 'text' => 'so']],
            $assistant['content'],
        );
        $this->assertArrayNotHasKey('reasoning', $assistant, 'as text instead of the field, not as well as');
    }

    public function testAnExplicitCompatBeatsWhatTheUrlSuggests(): void
    {
        // A proxy can live at any address, so the model gets the last word.
        $model = $this->model(compat: new OpenAiCompat(store: false), baseUrl: 'https://api.openai.com/v1');

        $this->send(new Context([new UserMessage('hi')]), $model);

        $this->assertFalse(array_key_exists('store', $this->server->receivedJson()));
    }

    // ---- what Copilot needs on top -----------------------------------------------------------

    public function testCopilotGetsItsHeadersHereToo(): void
    {
        // The rule lives in `Copilot` and both providers call it, so this is the sibling of the
        // same case in `OpenAiResponsesTest` — written down on both sides because having it on one
        // is how it went missing from the other.
        $url = $this->serve([['choices' => [['delta' => ['content' => 'ok'], 'finish_reason' => 'stop']]]]);

        Async::run(function () use ($url): void {
            $model = new Model(
                'gpt-4.1',
                'GPT-4.1',
                Api::OpenAiCompletions,
                'github-copilot',
                rtrim($url, '/'),
                128_000,
                16_000,
                false,
                ['text', 'image'],
                new Pricing(),
            );

            // Empty key on purpose — see the responses test: a key sends this to the real Copilot.
            $stream = (new OpenAiCompletions())->stream(
                $model,
                new Context([new UserMessage([new TextContent('look'), new ImageContent('AAA', 'image/png')])]),
                new OpenAiOptions(apiKey: ''),
            );

            foreach ($stream as $ignored) {
                // Drain it.
            }

            $stream->result()->await();
        });

        $head = strtolower($this->server->receivedHead());

        $this->assertStringContainsString('x-initiator: user', $head);
        $this->assertStringContainsString('openai-intent: conversation-edits', $head);
        $this->assertStringContainsString('copilot-vision-request: true', $head);
    }

    // ---- scaffolding ---------------------------------------------------------------------

    /** @param list<mixed> $content */
    private function assistant(array $content): AssistantMessage
    {
        return new AssistantMessage(
            $content,
            Api::OpenAiCompletions,
            'test-provider',
            'test-model',
            new Usage(),
            StopReason::Stop,
        );
    }

    /** @return array{0: list<string>, 1: AssistantMessage} */
    private function collect(string $url, Context $context): array
    {
        return Async::run(function () use ($url, $context): array {
            $stream = (new OpenAiCompletions())->stream(
                $this->model(baseUrl: $url),
                $context,
                new OpenAiOptions(apiKey: 'test-key'),
            );
            $types = [];

            foreach ($stream as $event) {
                $types[] = (new \ReflectionClass($event))->getShortName();
            }

            return [$types, $stream->result()->await()];
        });
    }

    /** Send one request against a server that answers with nothing, and keep what was sent. */
    public function testATemperatureSomebodySetReachesTheRequest(): void
    {
        // Found by the mutation sweep: deleting the line that sets it passed the whole suite, in
        // this provider and in the other four. An option nothing follows to the wire is an option
        // that can stop working without anybody hearing about it.
        $this->send(new Context([new UserMessage('hi')]), temperature: 0.7);

        $this->assertSame(0.7, $this->server->receivedJson()['temperature']);

        // And absent means absent, not a default the caller never chose.
        $this->server = new CannedServer();
        $this->send(new Context([new UserMessage('hi')]));

        $this->assertArrayNotHasKey('temperature', $this->server->receivedJson());
    }

    private function send(
        Context $context,
        ?Model $model = null,
        ?ReasoningEffort $reasoning = null,
        ?float $temperature = null,
    ): void {
        $url = $this->serve([['choices' => [['delta' => ['content' => 'ok'], 'finish_reason' => 'stop']]]]);
        $model ??= $this->model();

        Async::run(function () use ($url, $context, $model, $reasoning, $temperature): void {
            $stream = (new OpenAiCompletions())->stream(
                new Model(
                    $model->id,
                    $model->name,
                    $model->api,
                    $model->provider,
                    $url,
                    $model->contextWindow,
                    $model->maxTokens,
                    $model->reasoning,
                    $model->input,
                    $model->pricing,
                    $model->headers,
                    $model->compat,
                    // Every field, field by field — which is why this list is a hazard: a new
                    // one left off here does not fail to compile, it makes the feature look
                    // broken in whichever test happens to need it.
                    $model->thinkingLevelMap,
                ),
                $context,
                new OpenAiOptions(temperature: $temperature, apiKey: 'test-key', reasoning: $reasoning),
            );

            foreach ($stream as $ignored) {
                // Drain it; what is under test is what went out, not what came back.
            }

            $stream->result()->await();
        });
    }

    public function testCopilotsOwnIdsAreRemadeForItsOtherApiAndTheResultFollows(): void
    {
        // **Copilot serves both OpenAI shapes, and the ids its Responses API mints are rejected
        // by its own Completions API** — so crossing between them, a tool call's id is remade
        // into what the other side accepts, at most forty characters. pig used to do this with a
        // Copilot-only rule inside `TransformMessages`; it is now upstream's per-provider
        // `normalizeToolCallId`, which covers Copilot because it covers every `call_id|item_id`.
        // The `$renamedIds` map is what keeps the *result* pointing at the call.
        //
        // How somebody gets here: `/model` from `github-copilot/gpt-5` to a Copilot model on the
        // other shape, mid-tool-use.
        $long = 'fc_' . str_repeat('a', 50) . '|resp_68|weird';
        $model = new Model(
            'claude-x',
            'Claude X',
            Api::OpenAiCompletions,
            'github-copilot',
            'http://127.0.0.1:1',
            128_000,
            16_384,
            true,
            ['text'],
            new Pricing(),
        );

        $context = new Context([
            new UserMessage('hi'),
            new AssistantMessage(
                [new TextContent('let me look'), new ToolCall($long, 'read', ['path' => 'a.php'])],
                Api::OpenAiResponses,
                'github-copilot',
                'gpt-5',
                new Usage(),
                StopReason::ToolUse,
            ),
            new ToolResultMessage($long, 'read', [new TextContent('contents')]),
        ]);

        // Sent by hand rather than through `send()`, and the empty key is the reason: with one,
        // `endpoint()` asks `GithubCopilot::baseUrl()` where to go and the request leaves for the
        // real Copilot API instead of the canned server. The first version of this case did
        // exactly that and failed reading a body nothing had received.
        $url = $this->serve([['choices' => [['delta' => ['content' => 'ok'], 'finish_reason' => 'stop']]]]);

        Async::run(function () use ($url, $context, $model): void {
            $stream = (new OpenAiCompletions())->stream(
                new Model(
                    $model->id,
                    $model->name,
                    $model->api,
                    $model->provider,
                    rtrim($url, '/'),
                    $model->contextWindow,
                    $model->maxTokens,
                    $model->reasoning,
                    $model->input,
                    $model->pricing,
                ),
                $context,
                new OpenAiOptions(apiKey: ''),
            );

            foreach ($stream as $ignored) {
                // Drain it; what is under test is what went out.
            }

            $stream->result()->await();
        });

        $body = $this->server->receivedJson();
        $sent = $body['messages'][1]['tool_calls'][0]['id'];

        $this->assertNotSame($long, $sent, 'the id it arrived with is the one Copilot refuses');
        $this->assertSame(40, strlen($sent), 'truncated to what the other API accepts');
        $this->assertSame(1, preg_match('/^[a-zA-Z0-9_-]+$/', $sent), 'and nothing it rejects in it');

        // **Renamed, not duplicated.** Without the `continue` after the rewrite, the original
        // call goes out beside the new one and the provider is handed two calls where the model
        // made one.
        $this->assertCount(1, $body['messages'][1]['tool_calls']);

        // And the rest of the message survives the crossing: everything that is not a tool call
        // is copied across, which is one line that nothing used to notice the absence of.
        $this->assertSame('let me look', $body['messages'][1]['content']);

        // The half the `$renamedIds` map exists for: a result addressed to the old id is a result
        // the provider cannot match to any call.
        $this->assertSame($sent, $body['messages'][2]['tool_call_id']);
    }

    public function testAResponsesIdIsRemadeForChatCompletionsWhoeverMintedIt(): void
    {
        // This case used to be `testOnlyCopilotsOwnTwoApisRenameAnything`, asserting that a
        // `call_id|item_id` from openai or from Copilot reached a third provider untouched. That
        // was pig's hard-coded Copilot-to-Copilot rule, not upstream's: upstream's
        // `normalizeToolCallId` in `openai-completions.ts` remakes **any** id with a `|` in it,
        // because no chat-completions endpoint takes one — it is a Responses API id, and Copilot
        // is only one of the providers that mint them (upstream names openai-codex and opencode).
        //
        // The two halves are kept, sanitised and joined by `_` — two calls in one turn can share
        // a `call_id` and differ by item, and this API wants them distinct — and an id that would
        // run past forty is the call id's head and a hash of the whole.
        foreach (['openai', 'github-copilot'] as $minter) {
            $this->server = new CannedServer();
            $id = 'call_' . str_repeat('z', 60) . '|weird';
            $this->send($this->responsesTurn($minter, $id));
            $body = $this->server->receivedJson();
            $sent = $body['messages'][1]['tool_calls'][0]['id'];

            $this->assertSame(40, strlen($sent), "{$minter}: cut to what this API accepts");
            $this->assertStringStartsWith('call_' . str_repeat('z', 26) . '_', $sent, "{$minter}: the call id's head, then the hash");
            $this->assertSame($sent, $body['messages'][2]['tool_call_id'], "{$minter}: the result follows its call");
        }

        // Short enough, the halves are simply joined, with what this API refuses made into `_`.
        $this->server = new CannedServer();
        $this->send($this->responsesTurn('openai', 'call_1|fc_a+b'));
        $this->assertSame('call_1_fc_a_b', $this->server->receivedJson()['messages'][1]['tool_calls'][0]['id']);
    }

    public function testAnIdWithNoPipeIsLeftAloneExceptThatOpenAiGetsItCutToForty(): void
    {
        // Upstream's other two arms. An id that is not a Responses pair is some other API's own
        // — Anthropic's `toolu_…` — and a provider that did not mint it has no opinion about its
        // shape, so it goes through unchanged. Only `openai` itself, which caps at forty, gets it
        // cut.
        $id = 'toolu_' . str_repeat('q', 50);
        $fromAnthropic = new Context([
            new UserMessage('hi'),
            new AssistantMessage(
                [new ToolCall($id, 'read', ['path' => 'a.php'])],
                Api::AnthropicMessages,
                'anthropic',
                'claude-sonnet-4-5',
                new Usage(),
                StopReason::ToolUse,
            ),
            new ToolResultMessage($id, 'read', [new TextContent('contents')]),
        ]);

        $this->send($fromAnthropic);
        $this->assertSame($id, $this->server->receivedJson()['messages'][1]['tool_calls'][0]['id']);

        $this->server = new CannedServer();
        $openai = $this->model();
        $this->send($fromAnthropic, new Model(
            $openai->id,
            $openai->name,
            $openai->api,
            'openai',
            $openai->baseUrl,
            $openai->contextWindow,
            $openai->maxTokens,
            $openai->reasoning,
            $openai->input,
            $openai->pricing,
        ));

        $body = $this->server->receivedJson();
        $this->assertSame(substr($id, 0, 40), $body['messages'][1]['tool_calls'][0]['id']);
        $this->assertSame(substr($id, 0, 40), $body['messages'][2]['tool_call_id']);
    }

    public function testAssistantTextGoesBackAsOnePlainStringForEveryEndpoint(): void
    {
        // Upstream sends an assistant turn's text as one string, whoever is answering: an array of
        // `{type: "text"}` parts is non-standard, and some models (DeepSeek V3.2 via NVIDIA NIM,
        // upstream's comment says) mirror the structure in their own output, nesting it deeper
        // every turn. pig sent the string to Copilot only and parts to everybody else.
        $this->send(new Context([
            new UserMessage('hi'),
            $this->assistant([new TextContent('one, '), new TextContent('   '), new TextContent('two')]),
            new UserMessage('go on'),
        ]));

        // Blank blocks are left out and the rest joined with nothing between, as upstream does.
        $this->assertSame('one, two', $this->server->receivedJson()['messages'][1]['content']);
    }

    private function responsesTurn(string $provider, string $id): Context
    {
        return new Context([
            new UserMessage('hi'),
            new AssistantMessage(
                [new ToolCall($id, 'read', ['path' => 'a.php'])],
                Api::OpenAiResponses,
                $provider,
                'gpt-5',
                new Usage(),
                StopReason::ToolUse,
            ),
            new ToolResultMessage($id, 'read', [new TextContent('contents')]),
        ]);
    }

    private function model(
        string $baseUrl = 'http://127.0.0.1:1',
        bool $reasoning = false,
        bool $images = true,
        ?OpenAiCompat $compat = null,
        array $thinkingLevelMap = [],
    ): Model {
        return new Model(
            'test-model',
            'Test Model',
            Api::OpenAiCompletions,
            'test-provider',
            rtrim($baseUrl, '/'),
            128_000,
            16_384,
            $reasoning,
            $images ? ['text', 'image'] : ['text'],
            new Pricing(input: 1.0, output: 2.0),
            [],
            $compat,
            $thinkingLevelMap,
        );
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
