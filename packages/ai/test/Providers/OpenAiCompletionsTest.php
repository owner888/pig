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
    private function send(Context $context, ?Model $model = null, ?ReasoningEffort $reasoning = null): void
    {
        $url = $this->serve([['choices' => [['delta' => ['content' => 'ok'], 'finish_reason' => 'stop']]]]);
        $model ??= $this->model();

        Async::run(function () use ($url, $context, $model, $reasoning): void {
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
                ),
                $context,
                new OpenAiOptions(apiKey: 'test-key', reasoning: $reasoning),
            );

            foreach ($stream as $ignored) {
                // Drain it; what is under test is what went out, not what came back.
            }

            $stream->result()->await();
        });
    }

    private function model(
        string $baseUrl = 'http://127.0.0.1:1',
        bool $reasoning = false,
        bool $images = true,
        ?OpenAiCompat $compat = null,
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
