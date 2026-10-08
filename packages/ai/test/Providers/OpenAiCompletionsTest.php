<?php

declare(strict_types=1);

namespace Pig\Ai\Test\Providers;

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

    public function testCachedTokensAreTakenOutOfTheInputTheyWereCountedIn(): void
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
        // at the input rate. Reasoning is billed as output, and Groq leaves it out of
        // the total, so the total is added up rather than read.
        $this->assertSame(60, $message->usage->input);
        $this->assertSame(40, $message->usage->cacheRead);
        $this->assertSame(15, $message->usage->output);
        $this->assertSame(115, $message->usage->totalTokens);
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

    public function testThinkingFromAnotherProviderBecomesTaggedText(): void
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
        // travels as text rather than claiming to be reasoning this model did.
        $this->assertStringContainsString('<thinking>', $assistant['content'][0]['text']);
        $this->assertStringContainsString('deep thoughts', $assistant['content'][0]['text']);
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
        $compat = new OpenAiCompat(thinkingAsText: true);
        $context = new Context([
            new UserMessage('hi'),
            new AssistantMessage(
                [new ThinkingContent('hmm', 'reasoning'), new TextContent('so')],
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

        $this->assertStringContainsString('<thinking>', $assistant['content'][0]['text']);
        $this->assertSame('so', $assistant['content'][1]['text']);
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
        // by its own Completions API** — so crossing between them, a tool call's id is stripped
        // to what the other side accepts and truncated to forty characters. The mutation sweep
        // found this whole feature uncovered: every mutation in the rename block survived,
        // including deleting the `$renamedIds` map that keeps the *result* pointing at the call.
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

    public function testOnlyCopilotsOwnTwoApisRenameAnything(): void
    {
        // The rename is for one provider serving both OpenAI shapes. Any other crossing leaves the
        // id alone — a provider that did not mint it has no opinion about its shape, and rewriting
        // one would break the result that points at it. Both halves of that three-part condition
        // could be widened to `||` without a test noticing.
        $id = 'call_' . str_repeat('z', 60) . '|weird';

        $context = new Context([
            new UserMessage('hi'),
            new AssistantMessage(
                [new ToolCall($id, 'read', ['path' => 'a.php'])],
                Api::OpenAiResponses,
                'openai',
                'gpt-5',
                new Usage(),
                StopReason::ToolUse,
            ),
            new ToolResultMessage($id, 'read', [new TextContent('contents')]),
        ]);

        $url = $this->serve([['choices' => [['delta' => ['content' => 'ok'], 'finish_reason' => 'stop']]]]);

        Async::run(function () use ($url, $context): void {
            $stream = (new OpenAiCompletions())->stream($this->model($url), $context, new OpenAiOptions(apiKey: 'k'));

            foreach ($stream as $ignored) {
                // Drain it; what is under test is what went out.
            }

            $stream->result()->await();
        });

        $body = $this->server->receivedJson();

        $this->assertSame($id, $body['messages'][1]['tool_calls'][0]['id'], 'left exactly as it arrived');
        $this->assertSame($id, $body['messages'][2]['tool_call_id']);

        // And the other way round, which is the half that survived on its own: a message Copilot
        // *did* produce, going somewhere that is not Copilot. `/model` from
        // `github-copilot/gpt-5` to `openai/gpt-5` is how somebody gets there, and mangling the
        // id for a provider that has no opinion about its shape helps nobody.
        $this->server = new CannedServer();
        $fromCopilot = new Context([
            new UserMessage('hi'),
            new AssistantMessage(
                [new ToolCall($id, 'read', ['path' => 'a.php'])],
                Api::OpenAiResponses,
                'github-copilot',
                'gpt-5',
                new Usage(),
                StopReason::ToolUse,
            ),
            new ToolResultMessage($id, 'read', [new TextContent('contents')]),
        ]);

        $url = $this->serve([['choices' => [['delta' => ['content' => 'ok'], 'finish_reason' => 'stop']]]]);

        Async::run(function () use ($url, $fromCopilot): void {
            $stream = (new OpenAiCompletions())->stream($this->model($url), $fromCopilot, new OpenAiOptions(apiKey: 'k'));

            foreach ($stream as $ignored) {
                // Drain it; what is under test is what went out.
            }

            $stream->result()->await();
        });

        $this->assertSame($id, $this->server->receivedJson()['messages'][1]['tool_calls'][0]['id']);
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
