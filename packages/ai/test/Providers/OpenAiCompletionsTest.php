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
use Pig\Ai\Utils\PigUserAgent;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\Test\CannedServer;
use Pig\Ai\Utils\Transcript;

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

    public function testOnlyTheFirstReasoningFieldInADeltaIsRead(): void
    {
        // chutes.ai sends the same text in `reasoning_content` and `reasoning`. Upstream reads the
        // first non-empty field and stops; pig read all three, and every thought appeared twice.
        $url = $this->serve([
            ['choices' => [['delta' => ['reasoning_content' => 'let me think', 'reasoning' => 'let me think']]]],
            ['choices' => [['delta' => ['content' => 'the answer'], 'finish_reason' => 'stop']]],
        ]);

        [, $message] = $this->collect($url, new Context([new UserMessage('hi')]));

        $this->assertSame('let me think', $message->content[0]->thinking);
        $this->assertSame('reasoning_content', $message->content[0]->thinkingSignature);
    }

    public function testReasoningDetailsAreKeptOnTheThinkingBlockAndMergedAsTheyStream(): void
    {
        // OpenRouter's shape: the reasoning as text in `reasoning`, and the same reasoning as
        // `reasoning_details` that have to go back on the next request. Upstream merges consecutive
        // `reasoning.text` deltas into one entry, keeps an encrypted entry on its own, and stores
        // the list as the thinking block's signature. pig read only encrypted entries and filed
        // them on a tool call, so the text entries were dropped.
        $url = $this->serve([
            ['choices' => [['delta' => ['reasoning' => 'let me ', 'reasoning_details' => [
                ['type' => 'reasoning.text', 'text' => 'let me ', 'format' => 'anthropic-claude-v1', 'index' => 0],
            ]]]]],
            ['choices' => [['delta' => ['reasoning' => 'think', 'reasoning_details' => [
                ['type' => 'reasoning.text', 'text' => 'think', 'signature' => 'SIG', 'index' => 0],
            ]]]]],
            ['choices' => [['delta' => ['content' => 'the answer', 'reasoning_details' => [
                ['type' => 'reasoning.encrypted', 'data' => 'AAAA'],
            ]]]]],
            ['choices' => [['delta' => [], 'finish_reason' => 'stop']]],
        ]);

        [, $message] = $this->collect($url, new Context([new UserMessage('hi')]));
        $thinking = $message->content[0];

        $this->assertInstanceOf(ThinkingContent::class, $thinking);
        $this->assertSame('let me think', $thinking->thinking);
        $this->assertSame([
            ['type' => 'reasoning.text', 'text' => 'let me think', 'format' => 'anthropic-claude-v1', 'index' => 0, 'signature' => 'SIG'],
            ['type' => 'reasoning.encrypted', 'data' => 'AAAA'],
        ], json_decode((string) $thinking->thinkingSignature, true), 'the details that arrived after the text ended too');
    }

    public function testEncryptedReasoningBesideACallGetsAThinkingBlockOfItsOwnAndLeavesTheCallWhole(): void
    {
        // Encrypted-only reasoning comes beside the calls with no reasoning text before it, so there
        // is no thinking block to keep it on. Upstream makes one (`ensureThinkingBlock("")`). pig's
        // ordinary way to open a block would end the call it arrived with; this one is never the
        // open block, so the call's arguments that follow still land in the same call.
        $detail = ['type' => 'reasoning.encrypted', 'id' => 'c1', 'data' => 'AAAA'];
        $url = $this->serve([
            ['choices' => [['delta' => ['tool_calls' => [['id' => 'c1', 'function' => ['name' => 'read', 'arguments' => '{"pa']]]]]]],
            ['choices' => [['delta' => ['reasoning_details' => [$detail]]]]],
            ['choices' => [['delta' => ['tool_calls' => [['function' => ['arguments' => 'th":"a"}']]]]]]],
            ['choices' => [['delta' => [], 'finish_reason' => 'tool_calls']]],
        ]);

        [$types, $message] = $this->collect($url, new Context([new UserMessage('hi')]));

        $this->assertCount(2, $message->content);
        [$call, $thinking] = $message->content;

        $this->assertInstanceOf(ToolCall::class, $call);
        $this->assertSame(['path' => 'a'], $call->arguments);
        $this->assertNull($call->thoughtSignature, 'not on the call any more');

        $this->assertInstanceOf(ThinkingContent::class, $thinking);
        $this->assertSame('', $thinking->thinking);
        $this->assertSame([$detail], json_decode((string) $thinking->thinkingSignature, true));

        // Every block that started also ended.
        $this->assertSame(['ThinkingStartEvent'], array_values(array_filter($types, static fn (string $t): bool => $t === 'ThinkingStartEvent')));
        $this->assertContains('ThinkingEndEvent', $types);
    }

    public function testReasoningDetailsGoBackInsteadOfTheReasoningField(): void
    {
        // Upstream: `reasoning_details` is the structured alternative to a raw reasoning field, so
        // a message that has them sends them and not the field — even with no tool call.
        $details = [['type' => 'reasoning.text', 'text' => 'hmm', 'signature' => 'SIG']];
        $context = new Context([
            new UserMessage('hi'),
            $this->assistant([new ThinkingContent('hmm', (string) json_encode($details)), new TextContent('so')]),
            new UserMessage('go on'),
        ]);

        $this->send($context);
        $assistant = $this->server->receivedJson()['messages'][1];

        $this->assertSame($details, $assistant['reasoning_details']);
        $this->assertSame('so', $assistant['content']);

        foreach (['reasoning', 'reasoning_content', 'reasoning_text'] as $field) {
            $this->assertArrayNotHasKey($field, $assistant);
        }
    }

    public function testTheFirstThinkingBlocksFieldCarriesEveryThoughtJoinedByANewline(): void
    {
        // Upstream picks the field from the first thinking block's signature and joins all the
        // thinking with "\n". pig sent each block under its own signature with no separator, so two
        // thoughts ran together as "hmmand then" — and two signatures meant two fields.
        $context = new Context([
            new UserMessage('hi'),
            $this->assistant([
                new ThinkingContent('hmm', 'reasoning_content'),
                new TextContent('so'),
                new ThinkingContent('and then', 'reasoning'),
            ]),
            new UserMessage('go on'),
        ]);

        $this->send($context);
        $assistant = $this->server->receivedJson()['messages'][1];

        $this->assertSame("hmm\nand then", $assistant['reasoning_content']);
        $this->assertArrayNotHasKey('reasoning', $assistant);
    }

    public function testASignatureThatIsNotAReasoningFieldSendsNoField(): void
    {
        // Upstream writes the field only when the signature names one of the three. A signature
        // that is something else — here one that is not a list of details either — is not a field
        // name, and pig used it as one.
        $context = new Context([
            new UserMessage('hi'),
            $this->assistant([new ThinkingContent('hmm', 'thinking'), new TextContent('so')]),
            new UserMessage('go on'),
        ]);

        $this->send($context);
        $assistant = $this->server->receivedJson()['messages'][1];

        $this->assertSame(['role', 'content'], array_keys($assistant));
    }

    public function testEncryptedReasoningAnOlderSessionKeptOnACallStillGoesBack(): void
    {
        // Sessions saved before the details moved to the thinking block have each encrypted detail
        // on its tool call's `thoughtSignature`. Upstream still reads them there
        // (`parseLegacyEncryptedReasoningDetail()`), so a resumed session keeps its reasoning.
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

        // Sent as the object it arrived as: a string here is rejected.
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

    public function testAnImageBecomesAPlaceholderForAModelThatCannotSeeOne(): void
    {
        $context = new Context([new UserMessage([new TextContent('look'), new \Pig\Ai\ImageContent('AAA', 'image/png')])]);

        $this->send($context, $this->model(images: false));

        $parts = $this->server->receivedJson()['messages'][0]['content'];

        // Was one part: the image vanished and the model was never told. Upstream's
        // `downgradeUnsupportedImages()` puts a line of text in its place.
        $this->assertSame([
            ['type' => 'text', 'text' => 'look'],
            ['type' => 'text', 'text' => '(image omitted: model does not support images)'],
        ], $parts);
    }

    public function testAToolResultRunsImagesGoOutTogetherAfterTheWholeRun(): void
    {
        // Upstream's `toolResult` arm walks the run of consecutive results: every `tool` message,
        // then one user message with all their images — behind the bridge when the endpoint wants
        // one — and `lastRole = "user"`, so the next user message is not bridged a second time.
        // pig put each result's images straight after that result, so the second result followed a
        // user message, which an endpoint pairing calls with results refuses.
        $context = new Context([
            new UserMessage('hi'),
            $this->assistant([new ToolCall('c1', 'shot', []), new ToolCall('c2', 'shot', []), new ToolCall('c3', 'read', [])]),
            new ToolResultMessage('c1', 'shot', [new ImageContent('AAA', 'image/png')]),
            new ToolResultMessage('c2', 'shot', [new TextContent('second'), new ImageContent('BBB', 'image/jpeg')]),
            new ToolResultMessage('c3', 'read', [new TextContent('plain')]),
            new UserMessage('next'),
        ]);

        $this->send($context, $this->model(images: true, compat: new OpenAiCompat(assistantAfterToolResult: true)));

        $messages = array_slice($this->server->receivedJson()['messages'], 2);

        $this->assertSame([
            ['role' => 'tool', 'content' => '(see attached image)', 'tool_call_id' => 'c1'],
            ['role' => 'tool', 'content' => 'second', 'tool_call_id' => 'c2'],
            ['role' => 'tool', 'content' => 'plain', 'tool_call_id' => 'c3'],
            ['role' => 'assistant', 'content' => 'I have processed the tool results.'],
            ['role' => 'user', 'content' => [
                ['type' => 'text', 'text' => 'Attached image(s) from tool result:'],
                ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,AAA']],
                ['type' => 'image_url', 'image_url' => ['url' => 'data:image/jpeg;base64,BBB']],
            ]],
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'next']]],
        ], $messages);
    }

    public function testASkippedEmptyMessageDoesNotResetTheLastRole(): void
    {
        // Upstream `continue`s past an empty user message before `lastRole = msg.role`: a tool result,
        // an empty user turn, then a real one still gets the bridge. And an empty text part is
        // dropped from a user turn (`item.text.length > 0`), where pig sent it.
        $context = new Context([
            new UserMessage('hi'),
            $this->assistant([new ToolCall('c1', 'read', [])]),
            new ToolResultMessage('c1', 'read', [new TextContent('ok')]),
            new UserMessage([new TextContent('')]),
            new UserMessage([new TextContent(''), new TextContent('go on')]),
        ]);

        $this->send($context, $this->model(compat: new OpenAiCompat(assistantAfterToolResult: true)));

        $messages = array_slice($this->server->receivedJson()['messages'], 2);

        $this->assertSame([
            ['role' => 'tool', 'content' => 'ok', 'tool_call_id' => 'c1'],
            ['role' => 'assistant', 'content' => 'I have processed the tool results.'],
            ['role' => 'assistant', 'content' => 'I have processed the tool results.'],
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'go on']]],
        ], $messages);
    }

    /** @return iterable<string, array{0: list<string>, 1: string}> */
    public static function sdkStreamFailures(): iterable
    {
        // The `openai` SDK's `Stream` (7.19.0), which upstream's chunks come through. pig skipped
        // data that did not decode and read straight past an `error` in a chunk.
        yield 'data that is not JSON' => [["data: {oops\n\n"], 'Error reading response: malformed server-sent event JSON.'];
        // OpenRouter's mid-stream failure, with a 200: an `APIError` with no status is its message,
        // and upstream's catch appends `error.metadata.raw` on a line of its own.
        yield 'an error in the chunk' => [
            ['data: {"error":{"code":502,"message":"Provider returned error","metadata":{"raw":"upstream timeout"}}}' . "\n\n"],
            "Provider returned error\nupstream timeout",
        ];
        yield 'an error event' => [["event: error\ndata: {\"message\":\"overloaded\"}\n\n"], 'overloaded'];
    }

    /** @param list<string> $events */
    #[DataProvider('sdkStreamFailures')]
    public function testTheStreamFailsWhereTheSdksStreamDoes(array $events, string $expected): void
    {
        $pieces = ["HTTP/1.1 200 OK\r\nContent-Type: text/event-stream\r\nTransfer-Encoding: chunked\r\n\r\n"];
        $pieces[] = $this->chunk('data: ' . json_encode(['choices' => [['delta' => ['content' => 'par']]]]) . "\n\n");

        foreach ($events as $event) {
            $pieces[] = $this->chunk($event);
        }

        $pieces[] = "0\r\n\r\n";

        [$types, $message] = $this->collect($this->server->start($pieces), new Context([new UserMessage('hi')]));

        $this->assertSame('ErrorEvent', end($types));
        $this->assertSame($expected, $message->errorMessage);
        $this->assertSame('par', $message->content[0]->text);
    }

    public function testDoneEndsTheStreamWhateverFollows(): void
    {
        // `if (sse.data === '[DONE]') break`: what comes after is never read.
        $url = $this->server->start([
            "HTTP/1.1 200 OK\r\nContent-Type: text/event-stream\r\nTransfer-Encoding: chunked\r\n\r\n",
            $this->chunk('data: ' . json_encode(['choices' => [['delta' => ['content' => 'ok'], 'finish_reason' => 'stop']]]) . "\n\n"),
            $this->chunk("data: [DONE]\n\n"),
            $this->chunk("data: {not json at all\n\n"),
            "0\r\n\r\n",
        ]);

        [, $message] = $this->collect($url, new Context([new UserMessage('hi')]));

        $this->assertSame(StopReason::Stop, $message->stopReason);
        $this->assertSame('ok', $message->content[0]->text);
    }

    public function testTheUserAgentIsPisUnderTheModelsOwn(): void
    {
        [$head] = $this->sendWith($this->model(), new OpenAiOptions(apiKey: 'k'));

        $this->assertStringContainsString('user-agent: ' . PigUserAgent::get() . "\r\n", $head);
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

    /**
     * Upstream says "(no tool output)" for a result with neither text nor an image, and "(see
     * attached image)" only when there is an image. pig said the image one for both, so a command
     * that printed nothing came back to the model as a pointer to a picture that did not exist.
     * A lone empty text block counts as no text: upstream tests the joined text's length.
     */
    public function testAToolResultWithNothingInItSaysSoRatherThanPointingAtAnImage(): void
    {
        foreach (['no blocks' => [], 'empty text' => [new TextContent('')]] as $case => $content) {
            $this->server = new CannedServer();
            $context = new Context([
                new UserMessage('hi'),
                $this->assistant([new ToolCall('c1', 'bash', [])]),
                new ToolResultMessage('c1', 'bash', $content),
            ]);

            $this->send($context, $this->model(images: true));
            $messages = $this->server->receivedJson()['messages'];

            $this->assertSame('(no tool output)', $messages[count($messages) - 1]['content'], $case);
        }
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

    public function testMistralsHostIsNotOneOfThisApisQuirksAnyMore(): void
    {
        // Mistral speaks its own API now (`Providers\Mistral`), as upstream's does, and upstream's
        // `detectCompat()` no longer names it. pig kept the four rules its detection had before the
        // move — `max_tokens`, the tool result's name, thinking as text, nine-character tool ids —
        // which applied to any endpoint whose URL said `mistral.ai`.
        $compat = OpenAiCompat::detect('https://api.mistral.ai/v1');

        $this->assertSame('max_completion_tokens', $compat->maxTokensField);
        $this->assertFalse($compat->toolResultName);
        $this->assertFalse($compat->thinkingAsText);
        $this->assertTrue($compat->store);
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
        // Upstream's `isDeepSeek` lowercases the URL, and it decides this flag like every other:
        // pig used to match `deepseek.com` case-sensitively here, so `API.DeepSeek.com` got
        // `max_completion_tokens` — the field DeepSeek silently ignores.
        $this->assertSame('max_tokens', OpenAiCompat::detect('https://API.DeepSeek.com/v1')->maxTokensField);
        $this->assertSame('max_tokens', OpenAiCompat::detect('http://127.0.0.1:8080/v1', 'deepseek')->maxTokensField);
    }

    /**
     * DeepSeek is one of upstream's non-standard endpoints: no `store`, no `developer` role. This
     * test used to assert the opposite — "it takes `store`, the `developer` role and
     * `reasoning_effort` without complaint" — which was pig's guess and not upstream's
     * `detectCompat()`, where `isDeepSeek` is in `isNonStandard`. `reasoning_effort` it does keep.
     */
    public function testDeepSeekIsNonStandardTheWayUpstreamDetectsIt(): void
    {
        foreach ([['https://api.deepseek.com/v1', ''], ['https://API.DEEPSEEK.COM/v1', ''], ['http://127.0.0.1:1', 'deepseek']] as [$url, $provider]) {
            $compat = OpenAiCompat::detect($url, $provider);

            $this->assertFalse($compat->store, $url);
            $this->assertFalse($compat->developerRole, $url);
            $this->assertTrue($compat->reasoningEffort, $url);
        }
    }

    /**
     * Upstream's `detectCompat()` row by row, for the keys pig has. Every one of these was either
     * missing from pig's table or decided by the URL alone where upstream also takes the provider
     * name — z.ai was treated as fully standard, so it was sent `store`, the `developer` role,
     * `reasoning_effort` and `max_completion_tokens`, all four of which upstream withholds.
     */
    public function testEveryEndpointUpstreamNamesIsDetectedAsUpstreamDetectsIt(): void
    {
        // [url, provider, model id] => [store, developerRole, reasoningEffort, maxTokensField]
        $table = [
            'z.ai' => [['https://api.z.ai/api/coding/paas/v4', 'zai', ''], [false, false, false, 'max_tokens']],
            'bigmodel' => [['https://open.bigmodel.cn/api/paas/v4', 'x', ''], [false, false, false, 'max_tokens']],
            'moonshot' => [['https://api.moonshot.ai/v1', '', ''], [false, false, false, 'max_tokens']],
            'together' => [['https://api.together.xyz/v1', '', ''], [false, false, false, 'max_tokens']],
            'nvidia' => [['https://integrate.api.nvidia.com/v1', '', ''], [false, false, false, 'max_tokens']],
            'ant-ling' => [['https://api.ant-ling.com/v1', '', ''], [false, false, false, 'max_tokens']],
            'cf gateway' => [['https://gateway.ai.cloudflare.com/v1/a/b/compat', '', ''], [false, false, false, 'max_tokens']],
            'cf workers' => [['https://api.cloudflare.com/client/v4/accounts/a/ai/v1', '', ''], [false, false, true, 'max_completion_tokens']],
            'chutes' => [['https://llm.chutes.ai/v1', '', ''], [false, false, true, 'max_tokens']],
            'cerebras' => [['http://127.0.0.1:1', 'cerebras', ''], [false, false, true, 'max_completion_tokens']],
            'xai by name' => [['http://127.0.0.1:1', 'xai', ''], [false, false, false, 'max_completion_tokens']],
            'opencode' => [['https://opencode.ai/zen/v1', '', ''], [false, false, true, 'max_completion_tokens']],
            'openrouter' => [['https://openrouter.ai/api/v1', '', 'deepseek/deepseek-r1'], [true, false, true, 'max_completion_tokens']],
            'openrouter openai' => [['https://openrouter.ai/api/v1', '', 'openai/gpt-5'], [true, true, true, 'max_completion_tokens']],
            'groq' => [['https://api.groq.com/openai/v1', 'groq', ''], [true, true, true, 'max_completion_tokens']],
        ];

        foreach ($table as $name => [[$url, $provider, $id], [$store, $developerRole, $reasoningEffort, $field]]) {
            $compat = OpenAiCompat::detect($url, $provider, $id);

            $this->assertSame(
                [$store, $developerRole, $reasoningEffort, $field],
                [$compat->store, $compat->developerRole, $compat->reasoningEffort, $compat->maxTokensField],
                $name,
            );
        }
    }

    /**
     * Upstream's `detectCompat()` for the caching and session keys: session headers and the
     * `openrouter` format for OpenRouter alone, no long cache retention on Together, Cloudflare,
     * NVIDIA and Ant Ling, and Anthropic `cache_control` for an OpenRouter `anthropic/…` model. pig
     * detected none of them — the completions provider sent no cache key and no session at all.
     */
    public function testTheCachingAndSessionKeysAreDetectedAsUpstreamDetectsThem(): void
    {
        // [url, provider, model id] => [sendSessionAffinityHeaders, sessionAffinityFormat, supportsLongCacheRetention, cacheControlFormat]
        $table = [
            'openrouter claude' => [['https://openrouter.ai/api/v1', 'openrouter', 'anthropic/claude-opus-4.8'], [true, 'openrouter', true, 'anthropic']],
            'openrouter by url' => [['https://openrouter.ai/api/v1', 'x', 'anthropic/claude-opus-4.8'], [true, 'openrouter', true, null]],
            'groq' => [['https://api.groq.com/openai/v1', 'groq', 'x'], [false, 'openai', true, null]],
            'together' => [['https://api.together.xyz/v1', '', ''], [false, 'openai', false, null]],
            'cf workers' => [['https://api.cloudflare.com/client/v4/accounts/a/ai/v1', '', ''], [false, 'openai', false, null]],
            'cf gateway' => [['https://gateway.ai.cloudflare.com/v1/a/b/compat', '', ''], [false, 'openai', false, null]],
            'nvidia' => [['https://integrate.api.nvidia.com/v1', '', ''], [false, 'openai', false, null]],
            'ant-ling' => [['https://api.ant-ling.com/v1', '', ''], [false, 'openai', false, null]],
        ];

        foreach ($table as $name => [[$url, $provider, $id], $expected]) {
            $compat = OpenAiCompat::detect($url, $provider, $id);

            $this->assertSame(
                $expected,
                [$compat->sendSessionAffinityHeaders, $compat->sessionAffinityFormat, $compat->supportsLongCacheRetention, $compat->cacheControlFormat],
                $name,
            );
        }

        // And a model's own key wins over detection, as every other key's does.
        $model = $this->model('https://openrouter.ai/api/v1', compat: new OpenAiCompat(sendSessionAffinityHeaders: false, supportsLongCacheRetention: false));
        $this->assertFalse(OpenAiCompat::resolve($model)->sendSessionAffinityHeaders);
        $this->assertFalse(OpenAiCompat::resolve($model)->supportsLongCacheRetention);
    }

    /**
     * Upstream's `getCompat()`: `model.compat.x ?? detected.x`, key by key. A block that says one
     * thing leaves the rest to detection — pig used to take any block as the whole answer, so
     * this DeepSeek model, whose block only asks for thinking as text, was sent `store` and the
     * `developer` role and had its output cap under the name DeepSeek ignores.
     */
    public function testAnExplicitCompatOverridesDetectionOnlyForTheKeysItSets(): void
    {
        $model = new Model(
            'deepseek-reasoner',
            'DeepSeek',
            Api::OpenAiCompletions,
            'deepseek',
            'http://127.0.0.1:1',
            64_000,
            8_192,
            true,
            compat: new OpenAiCompat(thinkingAsText: true),
        );

        $resolved = OpenAiCompat::resolve($model);

        $this->assertTrue($resolved->thinkingAsText, 'the key the block sets');
        $this->assertFalse($resolved->store, 'detected');
        $this->assertFalse($resolved->developerRole, 'detected');
        $this->assertSame('max_tokens', $resolved->maxTokensField, 'detected');
        $this->assertTrue($resolved->reasoningContentOnAssistantMessages, 'detected');

        // And on the wire, which is what the endpoint answers to.
        $this->send(new Context([new UserMessage('hi')], systemPrompt: 'be brief'), $model);
        $body = $this->server->receivedJson();

        $this->assertArrayNotHasKey('store', $body);
        $this->assertSame('system', $body['messages'][0]['role']);
    }

    /**
     * Upstream's `openRouterRouting`, sent as the request's `provider` field exactly as written —
     * and read off the model's own compat, so only a model that says it sends it. pig had no such
     * key: a `models.json` written for pi with OpenRouter routing preferences was read and its
     * routing silently dropped, so requests went wherever OpenRouter chose.
     */
    public function testOpenRouterRoutingIsSentAsTheProviderField(): void
    {
        $routing = ['order' => ['anthropic', 'amazon-bedrock'], 'allow_fallbacks' => false, 'max_price' => ['prompt' => 3]];
        $this->send(new Context([new UserMessage('hi')]), $this->model(compat: new OpenAiCompat(openRouterRouting: $routing)));

        $this->assertSame($routing, $this->server->receivedJson()['provider']);

        // Not said, not sent — detection's `{}` is the resolved default and never goes out.
        $this->server = new CannedServer();
        $this->send(new Context([new UserMessage('hi')]), $this->model());
        $this->assertArrayNotHasKey('provider', $this->server->receivedJson());

        // An empty object is said, and JS sends it (`{}` is truthy): as `{}`, not `[]`.
        $this->server = new CannedServer();
        $this->send(new Context([new UserMessage('hi')]), $this->model(compat: new OpenAiCompat(openRouterRouting: [])));
        $this->assertStringContainsString('"provider":{}', $this->server->received());
    }

    /**
     * Upstream's `vercelGatewayRouting`: `providerOptions.gateway` with `only` and `order` and
     * nothing else, and only when one of the two is there.
     */
    public function testVercelGatewayRoutingIsSentAsTheGatewayOptions(): void
    {
        $this->send(new Context([new UserMessage('hi')]), $this->model(compat: new OpenAiCompat(
            vercelGatewayRouting: ['only' => ['bedrock'], 'order' => ['anthropic', 'bedrock'], 'other' => true],
        )));

        $this->assertSame(
            ['gateway' => ['only' => ['bedrock'], 'order' => ['anthropic', 'bedrock']]],
            $this->server->receivedJson()['providerOptions'],
        );

        $this->server = new CannedServer();
        $this->send(new Context([new UserMessage('hi')]), $this->model(compat: new OpenAiCompat(vercelGatewayRouting: ['other' => true])));
        $this->assertArrayNotHasKey('providerOptions', $this->server->receivedJson());
    }

    /** Upstream's `getCompat()`: `openRouterRouting ?? {}` and `vercelGatewayRouting ?? detected`. */
    public function testTheRoutingKeysResolveLikeUpstreamsGetCompat(): void
    {
        $resolved = OpenAiCompat::resolve($this->model(compat: new OpenAiCompat(store: false)));
        $this->assertSame([], $resolved->openRouterRouting);
        $this->assertSame([], $resolved->vercelGatewayRouting);

        $resolved = OpenAiCompat::resolve($this->model(compat: new OpenAiCompat(openRouterRouting: ['zdr' => true], vercelGatewayRouting: ['only' => ['x']])));
        $this->assertSame(['zdr' => true], $resolved->openRouterRouting);
        $this->assertSame(['only' => ['x']], $resolved->vercelGatewayRouting);
    }

    public function testDeepSeekIsDetectedByNameOrHostAsNeedingReasoningContentOnEveryAssistantTurn(): void
    {
        // Upstream's `isDeepSeek`: the provider called `deepseek`, or `deepseek.com` anywhere in
        // the URL, case aside. Nothing else gets the flag.
        $this->assertTrue(OpenAiCompat::detect('https://api.deepseek.com/v1')->reasoningContentOnAssistantMessages);
        $this->assertTrue(OpenAiCompat::detect('https://API.DeepSeek.com/v1')->reasoningContentOnAssistantMessages);
        $this->assertTrue(OpenAiCompat::detect('http://127.0.0.1:8080/v1', 'deepseek')->reasoningContentOnAssistantMessages);
        $this->assertFalse(OpenAiCompat::detect('https://api.openai.com/v1', 'openai')->reasoningContentOnAssistantMessages);
    }

    public function testDeepSeekGetsAnEmptyReasoningContentOnAReplayedTurnThatHadNoThinking(): void
    {
        // The 400 this fixes: DeepSeek's thinking mode wants `reasoning_content` on **every**
        // assistant turn that goes back to it, and pig only wrote the field when the turn had
        // thinking to put in it — so the first replayed tool-call turn without reasoning (or one
        // from another provider) failed the whole conversation. Upstream adds `""`.
        // The provider name is what is detected here, because the canned server's URL is not
        // DeepSeek's.
        $deepseek = new Model('deepseek-reasoner', 'DeepSeek', Api::OpenAiCompletions, 'deepseek', 'http://127.0.0.1:1', 64_000, 8_192, true);
        $context = new Context([
            new UserMessage('hi'),
            $this->assistant([new ToolCall('c1', 'read', ['path' => 'a'])]),
            new ToolResultMessage('c1', 'read', [new TextContent('ok')]),
            // Its own turn, so the thinking stays thinking rather than becoming text.
            new AssistantMessage(
                [new ThinkingContent('mine', 'reasoning_content'), new TextContent('done')],
                Api::OpenAiCompletions,
                'deepseek',
                'deepseek-reasoner',
                new Usage(),
                StopReason::Stop,
            ),
            new UserMessage('more'),
        ]);

        $this->send($context, $deepseek);
        $messages = $this->server->receivedJson()['messages'];

        $this->assertSame('', $messages[1]['reasoning_content']);
        // A turn that has its own reasoning keeps it; the empty one is only a filler.
        $this->assertSame('mine', $messages[3]['reasoning_content']);
    }

    public function testTheReasoningContentFillerNeedsTheFlagAndAReasoningModel(): void
    {
        // Upstream's condition is the compat flag **and** `model.reasoning` — the model's
        // capability, not whether this request thinks. Neither alone writes the field.
        $context = new Context([new UserMessage('hi'), $this->assistant([new TextContent('hello')]), new UserMessage('more')]);

        $this->send($context, $this->model(reasoning: false, compat: new OpenAiCompat(reasoningContentOnAssistantMessages: true)));
        $this->assertArrayNotHasKey('reasoning_content', $this->server->receivedJson()['messages'][1]);

        $this->server = new CannedServer();
        $this->send($context, $this->model(reasoning: true));
        $this->assertArrayNotHasKey('reasoning_content', $this->server->receivedJson()['messages'][1]);

        $this->server = new CannedServer();
        $this->send($context, $this->model(reasoning: true, compat: new OpenAiCompat(reasoningContentOnAssistantMessages: true)));
        $this->assertSame('', $this->server->receivedJson()['messages'][1]['reasoning_content']);
    }

    public function testAToolIdGoesOutAsItIsWhateverTheHost(): void
    {
        // The nine-character Mistral ids were the one id rule this API had of its own, and upstream
        // dropped it with Mistral's move — `Providers\Mistral` makes them now (`MistralTest`).
        $context = new Context([
            new UserMessage('hi'),
            $this->assistant([new ToolCall('call_abc-123456789', 'read', [])]),
            new ToolResultMessage('call_abc-123456789', 'read', [new TextContent('ok')]),
        ]);

        $this->send($context, $this->model(compat: OpenAiCompat::detect('https://api.mistral.ai/v1')));

        $messages = $this->server->receivedJson()['messages'];
        $this->assertSame('call_abc-123456789', $messages[1]['tool_calls'][0]['id']);
        $this->assertSame('call_abc-123456789', $messages[2]['tool_call_id']);
        $this->assertArrayNotHasKey('name', $messages[2]);
    }

    public function testAStreamThatEndsWithoutAFinishReasonIsAnErrorNotAnAnswer(): void
    {
        // Upstream's output starts at `stopReason: "pending"`, and a stream that ends with no
        // `finish_reason` throws `Stream ended without finish_reason`. pig's builder started at
        // `stop`, so a connection cut mid-answer came back as a finished one — half a sentence, no
        // usage — and the agent went on.
        $url = $this->serve([['choices' => [['delta' => ['content' => 'half a sen']]]]]);

        [$types, $message] = $this->collect($url, new Context([new UserMessage('hi')]));

        $this->assertSame('ErrorEvent', end($types));
        $this->assertSame(StopReason::Error, $message->stopReason);
        $this->assertSame('Stream ended without finish_reason', $message->errorMessage);
        $this->assertSame('half a sen', $message->content[0]->text);
    }

    public function testAnEndpointThatSendsNoFinishReasonIsFinishedWhenItsStreamIs(): void
    {
        // `supportsFinishReason: false` — "When false, pi infers stop or toolUse when the stream
        // ends." A turn that made a call is `toolUse`, one that did not is `stop`.
        $compat = new OpenAiCompat(supportsFinishReason: false);

        $url = $this->serve([['choices' => [['delta' => ['content' => 'done']]]]]);
        $message = Async::run(fn (): AssistantMessage => (new OpenAiCompletions())
            ->stream($this->model(baseUrl: $url, compat: $compat), Transcript::normalizeContext(new Context([new UserMessage('hi')])), new OpenAiOptions(apiKey: 'k'))
            ->result()->await());
        $this->assertSame(StopReason::Stop, $message->stopReason);

        $this->server = new CannedServer();
        $url = $this->serve([['choices' => [['delta' => ['tool_calls' => [['index' => 0, 'id' => 'c1', 'function' => ['name' => 'read', 'arguments' => '{}']]]]]]]]);
        $message = Async::run(fn (): AssistantMessage => (new OpenAiCompletions())
            ->stream($this->model(baseUrl: $url, compat: $compat), Transcript::normalizeContext(new Context([new UserMessage('hi')])), new OpenAiOptions(apiKey: 'k'))
            ->result()->await());
        $this->assertSame(StopReason::ToolUse, $message->stopReason);
    }

    public function testAFinishReasonNobodyMappedIsAnErrorThatSaysWhich(): void
    {
        // Upstream's `mapStopReason()`: `stop`/`end`, `length`, a call — and anything else is
        // `Provider finish_reason: <reason>`. pig read an unknown reason as a clean `stop` and
        // `content_filter` as an error with no words in it.
        foreach (['content_filter', 'network_error', 'weird'] as $reason) {
            $url = $this->serve([['choices' => [['delta' => ['content' => 'x'], 'finish_reason' => $reason]]]]);

            [, $message] = $this->collect($url, new Context([new UserMessage('hi')]));

            $this->assertSame(StopReason::Error, $message->stopReason, $reason);
            $this->assertSame("Provider finish_reason: {$reason}", $message->errorMessage, $reason);
            $this->assertSame($reason, $message->rawStopReason, $reason);
        }

        $this->server = new CannedServer();
        $url = $this->serve([['choices' => [['delta' => ['content' => 'x'], 'finish_reason' => 'end']]]]);
        $this->assertSame(StopReason::Stop, $this->collect($url, new Context([new UserMessage('hi')]))[1]->stopReason);
    }

    public function testUsageInTheStreamIsAskedForUnlessTheCompatSaysNot(): void
    {
        // `if (compat.supportsUsageInStreaming !== false) params.stream_options = …` — an endpoint
        // that rejects `stream_options` can say so; pig sent it to every endpoint.
        [, $body] = $this->sendWith($this->model(), new OpenAiOptions(apiKey: 'k'));
        $this->assertSame(['include_usage' => true], $body['stream_options']);

        $this->server = new CannedServer();
        [, $body] = $this->sendWith($this->model(compat: new OpenAiCompat(supportsUsageInStreaming: false)), new OpenAiOptions(apiKey: 'k'));
        $this->assertArrayNotHasKey('stream_options', $body);
    }

    public function testZaiIsAskedToStreamToolCallsWhereItsCompatSaysSo(): void
    {
        // `compat.zaiToolStream` puts `tool_stream: true` beside the tools — z.ai streams a call's
        // arguments only when asked. Not without tools, and not for a model that does not say so.
        $tool = new Tool('read', 'Read a file', ['type' => 'object', 'properties' => []]);
        $context = new Context([new UserMessage('hi')], tools: [$tool]);

        [, $body] = $this->sendWith($this->model(compat: new OpenAiCompat(zaiToolStream: true)), new OpenAiOptions(apiKey: 'k'), context: $context);
        $this->assertTrue($body['tool_stream']);

        $this->server = new CannedServer();
        [, $body] = $this->sendWith($this->model(compat: new OpenAiCompat(zaiToolStream: true)), new OpenAiOptions(apiKey: 'k'));
        $this->assertArrayNotHasKey('tool_stream', $body);

        $this->server = new CannedServer();
        [, $body] = $this->sendWith($this->model(), new OpenAiOptions(apiKey: 'k'), context: $context);
        $this->assertArrayNotHasKey('tool_stream', $body);
    }

    public function testVllmsPriorityAndTheThinkingBudgetFieldGoOutAsTopLevelFields(): void
    {
        // `vllmPriority` as `priority`, and the reasoning budget under `thinkingTokenBudgetField` —
        // or `thinking_token_budget` for `supportsThinkingTokenBudget` — with the same clamped budget
        // `{"$var": "thinking.budget"}` reads: medium's 8,192, under a 16,384 ceiling.
        $model = $this->model(reasoning: true, compat: new OpenAiCompat(vllmPriority: -5, thinkingTokenBudgetField: 'thinking_budget'));
        [, $body] = $this->sendWith($model, new OpenAiOptions(maxTokens: 16_384, apiKey: 'k', reasoning: ReasoningEffort::Medium));
        $this->assertSame(-5, $body['priority']);
        $this->assertSame(8192, $body['thinking_budget']);

        $model = $this->model(reasoning: true, compat: new OpenAiCompat(supportsThinkingTokenBudget: true));
        $this->server = new CannedServer();
        [, $body] = $this->sendWith($model, new OpenAiOptions(maxTokens: 16_384, apiKey: 'k', reasoning: ReasoningEffort::Low));
        $this->assertSame(2048, $body['thinking_token_budget']);
        $this->assertArrayNotHasKey('priority', $body);

        // No thinking, no budget.
        $this->server = new CannedServer();
        [, $body] = $this->sendWith($model, new OpenAiOptions(maxTokens: 16_384, apiKey: 'k'));
        $this->assertArrayNotHasKey('thinking_token_budget', $body);
    }

    public function testTheNewCompatKeysAreLaidOverDetectionKeyByKey(): void
    {
        // `getCompat()`: each key `model.compat.x ?? detected.x`, and `vllmPriority` the model's alone.
        $detected = OpenAiCompat::detect('https://example.test/v1');
        $this->assertTrue($detected->supportsUsageInStreaming);
        $this->assertTrue($detected->supportsFinishReason);
        $this->assertFalse($detected->zaiToolStream);
        $this->assertFalse($detected->supportsThinkingTokenBudget);
        $this->assertNull($detected->thinkingTokenBudgetField);
        $this->assertNull($detected->vllmPriority);

        $resolved = OpenAiCompat::resolve($this->model(compat: new OpenAiCompat(supportsFinishReason: false, vllmPriority: 2.5)));
        $this->assertFalse($resolved->supportsFinishReason);
        $this->assertTrue($resolved->supportsUsageInStreaming);
        $this->assertSame(2.5, $resolved->vllmPriority);
    }

    public function testARefusedRequestReadsAsUpstreamsSdkErrorDoes(): void
    {
        // `formatProviderError(normalizeProviderError(error))` over the `openai` SDK's `APIError`,
        // no prefix on this API: `<status>: <the error object as JSON>`, and OpenRouter's
        // `metadata.raw` on a line of its own when the message does not already hold it. pig used
        // to say `<provider> returned <status>: <message>`, which dropped the code and the type.
        $body = '{"error":{"message":"bad things","type":"invalid_request_error","metadata":{"raw":"upstream said no"}}}';
        $url = $this->server->start(["HTTP/1.1 400 Bad Request\r\nContent-Type: application/json\r\nContent-Length: " . strlen($body) . "\r\n\r\n" . $body]);

        [, $message] = $this->collect($url, new Context([new UserMessage('hi')]));

        $this->assertSame(
            '400: {"message":"bad things","type":"invalid_request_error","metadata":{"raw":"upstream said no"}}',
            $message->errorMessage,
        );

        // A body that is not JSON is the SDK's own message, status and text.
        $this->server = new CannedServer();
        $url = $this->server->start(["HTTP/1.1 502 Bad Gateway\r\nContent-Length: 7\r\n\r\nupstream"]);
        $this->assertSame('502 upstrea', $this->collect($url, new Context([new UserMessage('hi')]))[1]->errorMessage);

        // And the raw metadata is added when the formatted message lacks it: a quote inside it is
        // escaped in the JSON, so the text itself is not there.
        $body = '{"error":{"message":"m","metadata":{"raw":"say \\"no\\""}}}';
        $this->server = new CannedServer();
        $url = $this->server->start(["HTTP/1.1 400 Bad Request\r\nContent-Length: " . strlen($body) . "\r\n\r\n" . $body]);
        $this->assertSame(
            '400: {"message":"m","metadata":{"raw":"say \\"no\\""}}' . "\nsay \"no\"",
            $this->collect($url, new Context([new UserMessage('hi')]))[1]->errorMessage,
        );
    }

    public function testRawMetadataThatIsANestedListIsWrittenAsJavaScriptWritesIt(): void
    {
        // `String(rawMetadata)`: `Array.prototype.toString()` flattens nested lists with commas
        // and writes null as nothing. pig used to drop every item that was not a scalar.
        $body = '{"error":{"message":"m","metadata":{"raw":[["a","b"],null,3,true,{"x":1}]}}}';
        $url = $this->server->start(["HTTP/1.1 400 Bad Request\r\nContent-Length: " . strlen($body) . "\r\n\r\n" . $body]);

        $this->assertSame(
            '400: {"message":"m","metadata":{"raw":[["a","b"],null,3,true,{"x":1}]}}' . "\na,b,,3,true,[object Object]",
            $this->collect($url, new Context([new UserMessage('hi')]))[1]->errorMessage,
        );
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

    /**
     * Upstream's `convertTools()`: where `compat.supportsStrictMode` is not false, every tool
     * carries `strict` — true with the strict schema for one asking for it, false for the rest.
     * Detection says false ("OpenAI compatibility alone does not imply strict JSON-schema tool
     * support"), and then no tool carries the field at all, because some endpoints reject it.
     */
    public function testStrictToolsGoOutStrictOnlyWhereTheCompatSaysTheEndpointTakesThem(): void
    {
        $plain = new Tool('ls', 'List', ['type' => 'object', 'properties' => ['path' => ['type' => 'string']]]);
        $context = new Context([new UserMessage('hi')], tools: [new Tool('read', 'Read a file', [
            'type' => 'object',
            'properties' => ['path' => ['type' => 'string'], 'limit' => ['type' => 'number']],
            'required' => ['path'],
        ], ['type' => 'json_schema', 'strict' => 'prefer']), $plain]);

        $this->send($context, $this->model(compat: new OpenAiCompat(strictMode: true)));
        $tools = $this->server->receivedJson()['tools'];

        $this->assertTrue($tools[0]['function']['strict']);
        $this->assertSame([
            'type' => 'object',
            'properties' => ['path' => ['type' => 'string'], 'limit' => ['anyOf' => [['type' => 'number'], ['type' => 'null']]]],
            'required' => ['path', 'limit'],
            'additionalProperties' => false,
        ], $tools[0]['function']['parameters']);
        $this->assertFalse($tools[1]['function']['strict'], 'every tool says, strict or not');
        $this->assertSame(['type' => 'object', 'properties' => ['path' => ['type' => 'string']]], $tools[1]['function']['parameters']);

        $this->server = new CannedServer();
        $this->send($context, $this->model());
        $tools = $this->server->receivedJson()['tools'];

        $this->assertArrayNotHasKey('strict', $tools[0]['function']);
        $this->assertSame(['path'], $tools[0]['function']['parameters']['required'], 'the schema as written');
    }

    // ---- how thinking is switched on: `thinkingFormat` ---------------------------------------

    /**
     * Upstream's detected `thinkingFormat`: `deepseek`, `zai`, `together`, `ant-ling`,
     * `openrouter`, else `openai`, in that order of precedence.
     */
    public function testTheThinkingFormatIsDetectedAsUpstreamDetectsIt(): void
    {
        $this->assertSame('deepseek', OpenAiCompat::detect('https://api.deepseek.com/v1')->thinkingFormat);
        $this->assertSame('zai', OpenAiCompat::detect('https://api.z.ai/api/coding/paas/v4', 'zai')->thinkingFormat);
        $this->assertSame('together', OpenAiCompat::detect('https://api.together.xyz/v1')->thinkingFormat);
        $this->assertSame('ant-ling', OpenAiCompat::detect('https://api.ant-ling.com/v1')->thinkingFormat);
        $this->assertSame('openrouter', OpenAiCompat::detect('https://openrouter.ai/api/v1')->thinkingFormat);
        $this->assertSame('openai', OpenAiCompat::detect('https://api.groq.com/openai/v1', 'groq')->thinkingFormat);
    }

    /**
     * The regression the detection port caused and this closes: z.ai is told whether to think in
     * its own field, `thinking: {type}`. Detection now withholds `reasoning_effort` from it, as
     * upstream does, and without the `zai` format nothing at all said whether to think — so a
     * turn with thinking off still thought (GLM's default) and was billed for it.
     */
    public function testZaiIsToldWhetherToThinkInItsOwnField(): void
    {
        $zai = new Model('glm-4.6', 'GLM', Api::OpenAiCompletions, 'zai', 'http://127.0.0.1:1', 200_000, 8_192, true);

        $this->send(new Context([new UserMessage('hi')]), $zai, ReasoningEffort::High);
        $body = $this->server->receivedJson();

        $this->assertSame(['type' => 'enabled', 'clear_thinking' => false], $body['thinking']);
        $this->assertArrayNotHasKey('reasoning_effort', $body, 'supportsReasoningEffort is false for z.ai');

        $this->server = new CannedServer();
        $this->send(new Context([new UserMessage('hi')]), $zai);

        $this->assertSame(['type' => 'disabled'], $this->server->receivedJson()['thinking']);
    }

    /**
     * DeepSeek: `thinking: {type}` plus `reasoning_effort`, and thinking off is said out loud
     * unless the model's map calls `off` null — a model that cannot stop thinking is not told to.
     */
    public function testDeepSeekIsToldWhetherToThinkAndHowHard(): void
    {
        $deepseek = new Model('deepseek-v4', 'DeepSeek', Api::OpenAiCompletions, 'deepseek', 'http://127.0.0.1:1', 64_000, 8_192, true);

        $this->send(new Context([new UserMessage('hi')]), $deepseek, ReasoningEffort::High);
        $body = $this->server->receivedJson();

        $this->assertSame(['type' => 'enabled'], $body['thinking']);
        $this->assertSame('high', $body['reasoning_effort']);

        $this->server = new CannedServer();
        $this->send(new Context([new UserMessage('hi')]), $deepseek);
        $body = $this->server->receivedJson();

        $this->assertSame(['type' => 'disabled'], $body['thinking']);
        $this->assertArrayNotHasKey('reasoning_effort', $body);

        $this->server = new CannedServer();
        $this->send(new Context([new UserMessage('hi')]), $this->model(
            reasoning: true,
            compat: new OpenAiCompat(thinkingFormat: 'deepseek'),
            thinkingLevelMap: ['off' => null],
        ));

        $this->assertArrayNotHasKey('thinking', $this->server->receivedJson());
    }

    /**
     * The other formats, each as upstream's `buildParams()` writes it, on and off. A model that does
     * not reason gets none of them.
     *
     * @param array<string, mixed>       $compat
     * @param array<string, string|null> $map
     * @param array<string, mixed>       $on    fields expected with thinking at `high`
     * @param array<string, mixed>       $off   fields expected with thinking off
     */
    #[DataProvider('thinkingFormats')]
    public function testEachThinkingFormatSaysItTheWayUpstreamDoes(array $compat, array $map, array $on, array $off): void
    {
        $model = $this->model(reasoning: true, compat: new OpenAiCompat(...$compat), thinkingLevelMap: $map);
        $fields = ['thinking', 'enable_thinking', 'chat_template_kwargs', 'chat_template_args', 'reasoning', 'reasoning_effort'];

        $this->send(new Context([new UserMessage('hi')]), $model, ReasoningEffort::High);
        $this->assertSame($on, array_intersect_key($this->server->receivedJson(), array_flip($fields)), 'on');

        $this->server = new CannedServer();
        $this->send(new Context([new UserMessage('hi')]), $model);
        $this->assertSame($off, array_intersect_key($this->server->receivedJson(), array_flip($fields)), 'off');

        $this->server = new CannedServer();
        $this->send(new Context([new UserMessage('hi')]), $this->model(compat: new OpenAiCompat(...$compat), thinkingLevelMap: $map), ReasoningEffort::High);
        $this->assertSame([], array_intersect_key($this->server->receivedJson(), array_flip($fields)), 'not a reasoning model');
    }

    /** @return iterable<string, array{0: array<string, mixed>, 1: array<string, string|null>, 2: array<string, mixed>, 3: array<string, mixed>}> */
    public static function thinkingFormats(): iterable
    {
        yield 'qwen' => [['thinkingFormat' => 'qwen'], [], ['enable_thinking' => true, 'reasoning_effort' => 'high'], ['enable_thinking' => false]];
        yield 'qwen-chat-template' => [
            ['thinkingFormat' => 'qwen-chat-template'],
            [],
            ['chat_template_kwargs' => ['enable_thinking' => true, 'preserve_thinking' => true]],
            ['chat_template_kwargs' => ['enable_thinking' => false, 'preserve_thinking' => true]],
        ];
        yield 'openrouter' => [['thinkingFormat' => 'openrouter'], [], ['reasoning' => ['effort' => 'high']], ['reasoning' => ['effort' => 'none']]];
        yield 'together' => [
            ['thinkingFormat' => 'together'],
            [],
            ['reasoning' => ['enabled' => true], 'reasoning_effort' => 'high'],
            ['reasoning' => ['enabled' => false]],
        ];
        yield 'string-thinking' => [['thinkingFormat' => 'string-thinking'], ['off' => 'minimal'], ['thinking' => 'high'], ['thinking' => 'minimal']];
        // Only a mapped effort is sent, and nothing when off.
        yield 'ant-ling' => [['thinkingFormat' => 'ant-ling'], ['high' => 'deep'], ['reasoning' => ['effort' => 'deep']], []];
        // `$var`s are filled in; `omitWhenOff` drops one when thinking is off; a plain value goes as
        // it is. The budget is upstream's default for `high`, 16,384, under a 16,384-token ceiling
        // less the 1,024 kept for the answer.
        yield 'chat-template' => [
            ['thinkingFormat' => 'chat-template', 'chatTemplateKwargs' => [
                'enable_thinking' => ['$var' => 'thinking.enabled'],
                'effort' => ['$var' => 'thinking.effort', 'omitWhenOff' => true],
                'budget' => ['$var' => 'thinking.budget'],
                'fixed' => 'yes',
            ]],
            [],
            ['chat_template_kwargs' => ['enable_thinking' => true, 'effort' => 'high', 'budget' => 15_360, 'fixed' => 'yes']],
            ['chat_template_kwargs' => ['enable_thinking' => false, 'fixed' => 'yes']],
        ];
        // An empty object as a value — `models.json` now keeps it `{}` (a `stdClass`) rather than
        // `[]` — is an object to upstream's `resolveChatTemplateKwargValue()` like any other: one
        // with no `$var`, which resolves to the effort, and to nothing when thinking is off.
        yield 'chat-template with an empty object' => [
            ['thinkingFormat' => 'chat-template', 'chatTemplateKwargs' => ['level' => new \stdClass()]],
            [],
            ['chat_template_kwargs' => ['level' => 'high']],
            [],
        ];
        yield 'baseten' => [
            ['thinkingFormat' => 'baseten', 'chatTemplateArgs' => ['enable_thinking' => ['$var' => 'thinking.enabled']]],
            ['off' => 'none'],
            ['chat_template_args' => ['enable_thinking' => true], 'reasoning_effort' => 'high'],
            ['chat_template_args' => ['enable_thinking' => false], 'reasoning_effort' => 'none'],
        ];
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

            // No key on purpose — see the responses test: a key sends this to the real Copilot. The
            // bearer goes as header-owned auth, which upstream's `getClientApiKey()` accepts.
            $stream = (new OpenAiCompletions())->stream(
                $model,
                Transcript::normalizeContext(new Context([new UserMessage([new TextContent('look'), new ImageContent('AAA', 'image/png')])])),
                new OpenAiOptions(headers: ['Authorization' => 'Bearer copilot-token']),
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

    // ---- grammar (custom) tools --------------------------------------------------------------

    public function testAGrammarToolGoesOutAsACustomToolWhenTheCompatSaysTheEndpointTakesThem(): void
    {
        // Upstream's `convertTools()` grammar arm for chat completions — off unless a model's compat
        // says `supportsOpenAIGrammarTools`, as nothing built in does for this API.
        $this->send(new Context([new UserMessage('hi')], tools: [self::grammarTool()]), $this->model(compat: new OpenAiCompat(grammarTools: true)));

        $this->assertSame([
            'type' => 'custom',
            'custom' => [
                'name' => 'apply_patch',
                'description' => 'Apply a patch',
                'format' => ['type' => 'grammar', 'grammar' => ['syntax' => 'regex', 'definition' => '[a-z]+']],
            ],
        ], $this->server->receivedJson()['tools'][0]);

        // And without the flag it is an ordinary function tool.
        $this->server = new CannedServer();
        $this->send(new Context([new UserMessage('hi')], tools: [self::grammarTool()]));
        $this->assertSame('function', $this->server->receivedJson()['tools'][0]['type']);
    }

    public function testACustomToolCallDeltaBecomesArgumentsInTheToolsInputProperty(): void
    {
        // Upstream's `custom?.input` arm: the raw input accumulates into `{<property>: <input>}`, and
        // the JSON deltas pig streams close with `"}` when the call ends.
        $url = $this->serve([
            ['choices' => [['delta' => ['tool_calls' => [['index' => 0, 'id' => 'call_1', 'type' => 'custom', 'custom' => ['name' => 'apply_patch', 'input' => 'ab']]]]]]],
            ['choices' => [['delta' => ['tool_calls' => [['index' => 0, 'custom' => ['input' => 'c']]]]]]],
            ['choices' => [['delta' => [], 'finish_reason' => 'tool_calls']]],
        ]);

        [$deltas, $message] = Async::run(function () use ($url): array {
            $stream = (new OpenAiCompletions())->stream(
                $this->model($url, compat: new OpenAiCompat(grammarTools: true)),
                Transcript::normalizeContext(new Context([new UserMessage('patch it')], tools: [self::grammarTool()])),
                new OpenAiOptions(apiKey: 'test-key'),
            );
            $deltas = [];

            foreach ($stream as $event) {
                if ($event instanceof \Pig\Ai\ToolCallDeltaEvent) {
                    $deltas[] = $event->delta;
                }
            }

            return [$deltas, $stream->result()->await()];
        });

        $this->assertSame(['{"patch":"ab', 'c', '"}'], $deltas);
        $this->assertSame(['patch' => 'abc'], $message->toolCalls()[0]->arguments);
        $this->assertSame('apply_patch', $message->toolCalls()[0]->name);
    }

    public function testAGrammarToolsCallGoesBackAsACustomToolCall(): void
    {
        $this->send(
            new Context([
                new UserMessage('patch it'),
                $this->assistant([new ToolCall('call_1', 'apply_patch', ['patch' => 'abc'])]),
                new ToolResultMessage('call_1', 'apply_patch', [new TextContent('done')], false),
            ], tools: [self::grammarTool()]),
            $this->model(compat: new OpenAiCompat(grammarTools: true)),
        );

        $this->assertSame(
            [['id' => 'call_1', 'type' => 'custom', 'custom' => ['name' => 'apply_patch', 'input' => 'abc']]],
            $this->server->receivedJson()['messages'][1]['tool_calls'],
        );
    }

    private static function grammarTool(): Tool
    {
        return new Tool(
            'apply_patch',
            'Apply a patch',
            ['type' => 'object', 'properties' => ['patch' => ['type' => 'string']], 'required' => ['patch']],
            ['type' => 'grammar', 'variants' => ['openai_regex' => '[a-z]+']],
        );
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
                Transcript::normalizeContext($context),
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

    // ---- prompt cache and session affinity ---------------------------------------------------

    public function testOpenAisOwnEndpointGetsThePromptCacheKeyUnlessCachingIsOff(): void
    {
        // Upstream's `prompt_cache_key`: the session id, cut to 64 code points, for a base URL that
        // contains `api.openai.com` — here as a path segment of the canned server, which is what
        // upstream's `includes()` reads — and `cacheRetention` anything but `none`. pig sent none,
        // so a long conversation through OpenAI's own chat completions cached by luck.
        $long = str_repeat('é', 70);
        $this->server = new CannedServer();
        $body = $this->sendWith($this->model(), new OpenAiOptions(apiKey: 'test-key', sessionId: $long), '/api.openai.com/v1')[1];
        $this->assertSame(str_repeat('é', 64), $body['prompt_cache_key']);
        $this->assertArrayNotHasKey('prompt_cache_retention', $body);

        $this->server = new CannedServer();
        $body = $this->sendWith($this->model(), new OpenAiOptions(apiKey: 'test-key', cacheRetention: 'none', sessionId: 's1'), '/api.openai.com/v1')[1];
        $this->assertArrayNotHasKey('prompt_cache_key', $body);

        // Elsewhere only `long` asks, and then with `prompt_cache_retention: "24h"` too — unless the
        // endpoint is one detection says cannot keep a long cache (Together, here).
        $this->server = new CannedServer();
        $body = $this->sendWith($this->model(), new OpenAiOptions(apiKey: 'test-key', sessionId: 's1'))[1];
        $this->assertArrayNotHasKey('prompt_cache_key', $body);

        $this->server = new CannedServer();
        $body = $this->sendWith($this->model(), new OpenAiOptions(apiKey: 'test-key', cacheRetention: 'long', sessionId: 's1'))[1];
        $this->assertSame('s1', $body['prompt_cache_key']);
        $this->assertSame('24h', $body['prompt_cache_retention']);
        // In upstream's key order: right after `stream`, before `stream_options`.
        $this->assertSame(['model', 'messages', 'stream', 'prompt_cache_key', 'prompt_cache_retention', 'stream_options'], array_slice(array_keys($body), 0, 6));

        $this->server = new CannedServer();
        $body = $this->sendWith($this->model(compat: new OpenAiCompat(supportsLongCacheRetention: false)), new OpenAiOptions(apiKey: 'test-key', cacheRetention: 'long', sessionId: 's1'))[1];
        $this->assertArrayNotHasKey('prompt_cache_key', $body);
        $this->assertArrayNotHasKey('prompt_cache_retention', $body);
    }

    public function testTheSessionGoesOutAsHeadersOnlyWhereTheCompatSaysSo(): void
    {
        // Upstream's `sendSessionAffinityHeaders` (detected for OpenRouter alone) and
        // `sessionAffinityFormat`: `x-session-id` for `openrouter`; otherwise `x-client-request-id`
        // and `x-session-affinity`, plus `session_id` for `openai`. No session, or `none`, sends none.
        $this->server = new CannedServer();
        $head = $this->sendWith($this->model(), new OpenAiOptions(apiKey: 'test-key', sessionId: 's1'))[0];
        $this->assertStringNotContainsStringIgnoringCase('x-client-request-id', $head);
        $this->assertStringNotContainsStringIgnoringCase('x-session-id', $head);

        $this->server = new CannedServer();
        $head = $this->sendWith($this->model(compat: new OpenAiCompat(sendSessionAffinityHeaders: true)), new OpenAiOptions(apiKey: 'test-key', sessionId: 's1'))[0];
        $this->assertStringContainsStringIgnoringCase("session_id: s1\r\n", $head);
        $this->assertStringContainsStringIgnoringCase("x-client-request-id: s1\r\n", $head);
        $this->assertStringContainsStringIgnoringCase("x-session-affinity: s1\r\n", $head);

        $this->server = new CannedServer();
        $head = $this->sendWith($this->model(compat: new OpenAiCompat(sendSessionAffinityHeaders: true, sessionAffinityFormat: 'openai-nosession')), new OpenAiOptions(apiKey: 'test-key', sessionId: 's1'))[0];
        $this->assertStringNotContainsStringIgnoringCase('session_id: s1', $head);
        $this->assertStringContainsStringIgnoringCase("x-session-affinity: s1\r\n", $head);

        $this->server = new CannedServer();
        $head = $this->sendWith($this->model(compat: new OpenAiCompat(sendSessionAffinityHeaders: true, sessionAffinityFormat: 'openrouter')), new OpenAiOptions(apiKey: 'test-key', cacheRetention: 'none', sessionId: 's1'))[0];
        $this->assertStringNotContainsStringIgnoringCase('x-session-id', $head);

        $this->server = new CannedServer();
        $head = $this->sendWith($this->model(compat: new OpenAiCompat(sendSessionAffinityHeaders: true, sessionAffinityFormat: 'openrouter')), new OpenAiOptions(apiKey: 'test-key', sessionId: 's1'))[0];
        $this->assertStringContainsStringIgnoringCase("x-session-id: s1\r\n", $head);
        $this->assertStringNotContainsStringIgnoringCase('x-client-request-id', $head);
    }

    public function testAnAnthropicModelThroughOpenRouterGetsCacheControlMarks(): void
    {
        // Upstream's `cacheControlFormat: "anthropic"` (detected for OpenRouter's `anthropic/…`
        // models): `cache_control` on the system prompt, the last tool and the last conversation
        // text, with `ttl: "1h"` for `long`. Without it every OpenRouter Claude turn paid full price
        // for the whole prompt.
        $model = $this->model(compat: new OpenAiCompat(cacheControlFormat: 'anthropic'));
        $context = new Context(
            [new UserMessage('first'), $this->assistant([new TextContent('reply')]), new UserMessage('second')],
            'Be brief.',
            [new Tool('a', 'A', ['type' => 'object']), new Tool('b', 'B', ['type' => 'object'])],
        );

        $this->server = new CannedServer();
        $body = $this->sendWith($model, new OpenAiOptions(apiKey: 'test-key', cacheRetention: 'long'), context: $context)[1];
        $mark = ['type' => 'ephemeral', 'ttl' => '1h'];
        $this->assertSame([['type' => 'text', 'text' => 'Be brief.', 'cache_control' => $mark]], $body['messages'][0]['content']);
        $this->assertArrayNotHasKey('cache_control', $body['tools'][0]);
        $this->assertSame($mark, $body['tools'][1]['cache_control']);
        $this->assertSame($mark, $body['messages'][3]['content'][0]['cache_control']);
        $this->assertArrayNotHasKey('cache_control', $body['messages'][1]['content'][0]);
        $this->assertSame('reply', $body['messages'][2]['content'], 'only the last conversation message is marked');

        // `short` marks without a TTL, and `none` marks nothing.
        $this->server = new CannedServer();
        $body = $this->sendWith($model, new OpenAiOptions(apiKey: 'test-key'), context: $context)[1];
        $this->assertSame(['type' => 'ephemeral'], $body['tools'][1]['cache_control']);

        $this->server = new CannedServer();
        $body = $this->sendWith($model, new OpenAiOptions(apiKey: 'test-key', cacheRetention: 'none'), context: $context)[1];
        $this->assertArrayNotHasKey('cache_control', $body['tools'][1]);
        $this->assertSame('Be brief.', $body['messages'][0]['content']);
    }

    /**
     * One request with these options, from a model whose base URL is the canned server's — with
     * `$path` after it, so a test can put `api.openai.com` in the URL the way upstream reads it.
     *
     * @return array{0: string, 1: array<string, mixed>} the request's head and its JSON body
     */
    private function sendWith(Model $model, OpenAiOptions $options, string $path = '', ?Context $context = null): array
    {
        $url = rtrim($this->serve([['choices' => [['delta' => ['content' => 'ok'], 'finish_reason' => 'stop']]]]), '/') . $path;

        Async::run(function () use ($url, $model, $options, $context): void {
            $stream = (new OpenAiCompletions())->stream(
                new Model($model->id, $model->name, $model->api, $model->provider, $url, $model->contextWindow, $model->maxTokens, $model->reasoning, $model->input, $model->pricing, $model->headers, $model->compat, $model->thinkingLevelMap),
                Transcript::normalizeContext($context ?? new Context([new UserMessage('hi')])),
                $options,
            );

            foreach ($stream as $ignored) {
            }
        });

        return [$this->server->receivedHead(), $this->server->receivedJson()];
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
                Transcript::normalizeContext($context),
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

        // Sent by hand rather than through `send()`, and the missing key is the reason: with one,
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
                Transcript::normalizeContext($context),
                new OpenAiOptions(headers: ['Authorization' => 'Bearer copilot-token']),
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
