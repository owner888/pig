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
use Pig\Ai\Providers\OpenAiOptions;
use Pig\Ai\Providers\OpenAiResponses;
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
 * OpenAI's Responses API, against a server answering from a script.
 *
 * Two things here have no counterpart in the other providers, and most of these are about
 * them: a reasoning item has to go back whole, and a tool call has two ids.
 */
final class OpenAiResponsesTest extends TestCase
{
    private CannedServer $server;

    #[\Override]
    protected function setUp(): void
    {
        Loop::reset();
        $this->server = new CannedServer();
    }

    // ---- reading the stream -----------------------------------------------------------

    public function testATextResponseArrivesAsEventsAndThenAsAMessage(): void
    {
        $url = $this->serve([
            ['type' => 'response.output_item.added', 'item' => ['type' => 'message', 'id' => 'msg_1']],
            ['type' => 'response.output_text.delta', 'delta' => 'Hel'],
            ['type' => 'response.output_text.delta', 'delta' => 'lo'],
            ['type' => 'response.output_item.done', 'item' => ['type' => 'message', 'id' => 'msg_1']],
            ['type' => 'response.completed', 'response' => [
                'status' => 'completed',
                'usage' => ['input_tokens' => 20, 'output_tokens' => 5, 'total_tokens' => 25],
            ]],
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

        // The message's own id, which has to go back with it next turn.
        $this->assertSame('msg_1', $message->content[0]->textSignature);
        $this->assertSame(StopReason::Stop, $message->stopReason);
    }

    public function testAReasoningItemIsKeptWholeAndNotJustItsSummary(): void
    {
        $item = ['type' => 'reasoning', 'id' => 'rs_1', 'encrypted_content' => 'OPAQUE'];

        $url = $this->serve([
            ['type' => 'response.output_item.added', 'item' => ['type' => 'reasoning', 'id' => 'rs_1']],
            ['type' => 'response.reasoning_summary_text.delta', 'delta' => 'let me think'],
            ['type' => 'response.output_item.done', 'item' => $item],
            ['type' => 'response.completed', 'response' => ['status' => 'completed']],
        ]);

        [, $message] = $this->collect($url, new Context([new UserMessage('hi')]));
        $block = $message->content[0];

        $this->assertInstanceOf(ThinkingContent::class, $block);
        $this->assertSame('let me think', $block->thinking);

        // The summary is what a person reads; the model wants its own encrypted item
        // back verbatim, or it reasons from nothing again.
        $this->assertSame($item, json_decode((string) $block->thinkingSignature, true));
    }

    public function testASummaryPartEndingIsAParagraphBreak(): void
    {
        $url = $this->serve([
            ['type' => 'response.output_item.added', 'item' => ['type' => 'reasoning', 'id' => 'rs_1']],
            ['type' => 'response.reasoning_summary_text.delta', 'delta' => 'first'],
            ['type' => 'response.reasoning_summary_part.done', 'part' => []],
            ['type' => 'response.reasoning_summary_text.delta', 'delta' => 'second'],
            ['type' => 'response.output_item.done', 'item' => ['type' => 'reasoning', 'id' => 'rs_1']],
            ['type' => 'response.completed', 'response' => ['status' => 'completed']],
        ]);

        [, $message] = $this->collect($url, new Context([new UserMessage('hi')]));

        // Nothing else in the stream says one thought ended and another began.
        $this->assertSame("first\n\nsecond", $message->content[0]->thinking);
    }

    public function testAToolCallCarriesBothOfItsIds(): void
    {
        $url = $this->serve([
            ['type' => 'response.output_item.added', 'item' => [
                'type' => 'function_call', 'id' => 'fc_1', 'call_id' => 'call_1', 'name' => 'read', 'arguments' => '',
            ]],
            ['type' => 'response.function_call_arguments.delta', 'delta' => '{"pa'],
            ['type' => 'response.function_call_arguments.delta', 'delta' => 'th":"a.php"}'],
            ['type' => 'response.output_item.done', 'item' => [
                'type' => 'function_call', 'id' => 'fc_1', 'call_id' => 'call_1', 'name' => 'read',
            ]],
            ['type' => 'response.completed', 'response' => ['status' => 'completed']],
        ]);

        [, $message] = $this->collect($url, new Context([new UserMessage('hi')]));
        $call = $message->content[0];

        $this->assertInstanceOf(ToolCall::class, $call);

        // `call_id` addresses the result, `id` is the item's own, and both have to go
        // back — so they travel joined.
        $this->assertSame('call_1|fc_1', $call->id);
        $this->assertSame(['path' => 'a.php'], $call->arguments);
    }

    public function testATurnThatCalledAToolIsFinishedWithTheTurnAndNotTheTask(): void
    {
        $url = $this->serve([
            ['type' => 'response.output_item.added', 'item' => [
                'type' => 'function_call', 'id' => 'fc_1', 'call_id' => 'call_1', 'name' => 'read', 'arguments' => '{}',
            ]],
            ['type' => 'response.output_item.done', 'item' => [
                'type' => 'function_call', 'id' => 'fc_1', 'call_id' => 'call_1', 'name' => 'read',
            ]],
            ['type' => 'response.completed', 'response' => ['status' => 'completed']],
        ]);

        [, $message] = $this->collect($url, new Context([new UserMessage('hi')]));

        // The status says "completed" either way, so the content is what tells them apart.
        $this->assertSame(StopReason::ToolUse, $message->stopReason);
    }

    public function testRunningOutOfRoomIsLengthAndNotSuccess(): void
    {
        $url = $this->serve([
            ['type' => 'response.output_item.added', 'item' => ['type' => 'message', 'id' => 'msg_1']],
            ['type' => 'response.output_text.delta', 'delta' => 'half an ans'],
            ['type' => 'response.output_item.done', 'item' => ['type' => 'message', 'id' => 'msg_1']],
            ['type' => 'response.completed', 'response' => ['status' => 'incomplete']],
        ]);

        [, $message] = $this->collect($url, new Context([new UserMessage('hi')]));

        $this->assertSame(StopReason::Length, $message->stopReason);
    }

    public function testCachedTokensAreTakenOutOfTheInputTheyWereCountedIn(): void
    {
        $url = $this->serve([
            ['type' => 'response.completed', 'response' => [
                'status' => 'completed',
                'usage' => [
                    'input_tokens' => 100,
                    'output_tokens' => 10,
                    'total_tokens' => 110,
                    'input_tokens_details' => ['cached_tokens' => 40],
                ],
            ]],
        ]);

        [, $message] = $this->collect($url, new Context([new UserMessage('hi')]));

        $this->assertSame(60, $message->usage->input);
        $this->assertSame(40, $message->usage->cacheRead);
        $this->assertSame(110, $message->usage->totalTokens);
    }

    public function testAnErrorEventEndsTheStreamAsAFailure(): void
    {
        $url = $this->serve([
            ['type' => 'error', 'code' => 'rate_limit', 'message' => 'slow down'],
        ]);

        [$types, $message] = $this->collect($url, new Context([new UserMessage('hi')]));

        $this->assertContains('ErrorEvent', $types);
        $this->assertSame(StopReason::Error, $message->stopReason);
        $this->assertStringContainsString('slow down', (string) $message->errorMessage);
    }

    // ---- writing the request -------------------------------------------------------------

    public function testTheRequestGoesToTheResponsesEndpointWithTheKey(): void
    {
        $this->send(new Context([new UserMessage('hi')]));

        $head = $this->server->receivedHead();
        $body = $this->server->receivedJson();

        $this->assertStringContainsString('POST /responses', $head);
        $this->assertStringContainsString('Bearer test-key', $head);
        $this->assertSame('test-model', $body['model']);
        $this->assertSame([['type' => 'input_text', 'text' => 'hi']], $body['input'][0]['content']);
    }

    public function testAReasoningModelsSystemPromptGoesInADeveloperTurn(): void
    {
        $this->send(new Context([new UserMessage('hi')], 'be helpful'), $this->model(reasoning: true));

        $this->assertSame('developer', $this->server->receivedJson()['input'][0]['role']);
    }

    public function testAskingToThinkAlsoAsksForTheEncryptedReasoningBack(): void
    {
        $this->send(new Context([new UserMessage('hi')]), $this->model(reasoning: true), ReasoningEffort::High);

        $body = $this->server->receivedJson();

        $this->assertSame('high', $body['reasoning']['effort']);

        // Without this the reasoning never comes back, and a thinking block with nothing
        // to replay costs a turn to rebuild.
        $this->assertSame(['reasoning.encrypted_content'], $body['include']);
    }

    public function testGptFiveIsToldNotToThinkTheOnlyWayItCanBe(): void
    {
        $model = $this->model(reasoning: true, id: 'gpt-5.2');

        $this->send(new Context([new UserMessage('hi')]), $model);

        $input = $this->server->receivedJson()['input'];
        $last = $input[count($input) - 1];

        // There is no documented way to turn it off; this is the one upstream found.
        $this->assertSame('developer', $last['role']);
        $this->assertStringContainsString('Juice: 0', $last['content'][0]['text']);
    }

    public function testAModelThatDoesNotReasonIsNotSentTheJuiceHack(): void
    {
        $this->send(new Context([new UserMessage('hi')]), $this->model(id: 'gpt-5.2'));

        $input = $this->server->receivedJson()['input'];

        $this->assertSame('user', $input[count($input) - 1]['role']);
    }

    public function testAThinkingBlockGoesBackAsTheItemItCameFrom(): void
    {
        $item = ['type' => 'reasoning', 'id' => 'rs_1', 'encrypted_content' => 'OPAQUE'];
        $context = new Context([
            new UserMessage('hi'),
            $this->assistant([new ThinkingContent('summary', (string) json_encode($item))]),
            new UserMessage('go on'),
        ]);

        $this->send($context);

        // Verbatim, not rebuilt: the encrypted part is the bit that matters and it is
        // not something this can reconstruct.
        $this->assertSame($item, $this->server->receivedJson()['input'][1]);
    }

    public function testAnAbortedTurnsThinkingAndCallsAreNotSentBack(): void
    {
        $context = new Context([
            new UserMessage('hi'),
            $this->assistant(
                [new ThinkingContent('half a thought', '{"type":"reasoning"}'), new ToolCall('call_1|fc_1', 'read', [])],
                StopReason::Error,
            ),
            new UserMessage('never mind'),
        ]);

        $this->send($context);

        $input = $this->server->receivedJson()['input'];
        $kinds = array_map(static fn (array $item): string => $item['type'] ?? $item['role'], $input);

        // Asking the model to carry on from something it never finished is worse than
        // dropping it — and the result that answers a dropped call goes with it, or
        // OpenAI rejects a result addressed to a call it never saw.
        $this->assertNotContains('reasoning', $kinds);
        $this->assertNotContains('function_call', $kinds);
        $this->assertNotContains('function_call_output', $kinds);
        $this->assertSame(['user', 'user'], $kinds);
    }

    public function testATextBlockKeepsItsIdAcrossTurns(): void
    {
        $context = new Context([
            new UserMessage('hi'),
            $this->assistant([new TextContent('the answer', 'msg_abc')]),
            new UserMessage('go on'),
        ]);

        $this->send($context);

        $this->assertSame('msg_abc', $this->server->receivedJson()['input'][1]['id']);
    }

    public function testAnOverlongIdIsHashedRatherThanRenumbered(): void
    {
        $long = 'msg_' . str_repeat('x', 200);
        $context = new Context([
            new UserMessage('hi'),
            $this->assistant([new TextContent('the answer', $long)]),
            new UserMessage('go on'),
        ]);

        $this->send($context);
        $first = $this->server->receivedJson()['input'][1]['id'];

        $this->server = new CannedServer();
        $this->send($context);
        $second = $this->server->receivedJson()['input'][1]['id'];

        $this->assertLessThanOrEqual(64, strlen($first));

        // The same message has to come back under the same id every turn; a counter
        // would renumber everything whenever something earlier was dropped.
        $this->assertSame($first, $second);
    }

    public function testAToolCallAndItsResultAreSplitBackIntoTwoIds(): void
    {
        $context = new Context([
            new UserMessage('hi'),
            $this->assistant([new ToolCall('call_1|fc_1', 'read', ['path' => 'a.php'])]),
            new ToolResultMessage('call_1|fc_1', 'read', [new TextContent('<?php')]),
        ]);

        $this->send($context);

        $input = $this->server->receivedJson()['input'];

        $this->assertSame('fc_1', $input[1]['id']);
        $this->assertSame('call_1', $input[1]['call_id']);
        $this->assertSame('function_call_output', $input[2]['type']);
        $this->assertSame('call_1', $input[2]['call_id']);
    }

    public function testACallFromAnotherProviderHasOneIdAndIsUsedTwice(): void
    {
        // What `/model` leaves behind when it switches mid-conversation.
        $context = new Context([
            new UserMessage('hi'),
            $this->assistant([new ToolCall('toolu_abc', 'read', [])]),
            new ToolResultMessage('toolu_abc', 'read', [new TextContent('ok')]),
        ]);

        $this->send($context);

        $input = $this->server->receivedJson()['input'];

        $this->assertSame('toolu_abc', $input[1]['id']);
        $this->assertSame('toolu_abc', $input[1]['call_id']);
        $this->assertSame('toolu_abc', $input[2]['call_id']);
    }

    public function testToolResultImagesFollowAsAUserTurnOfTheirOwn(): void
    {
        $context = new Context([
            new UserMessage('hi'),
            $this->assistant([new ToolCall('call_1|fc_1', 'shot', [])]),
            new ToolResultMessage('call_1|fc_1', 'shot', [new ImageContent('AAA', 'image/png')]),
        ]);

        $this->send($context, $this->model(images: true));

        $input = $this->server->receivedJson()['input'];
        $last = $input[count($input) - 1];

        $this->assertSame('(see attached image)', $input[count($input) - 2]['output']);
        $this->assertSame('user', $last['role']);
        $this->assertSame('input_image', $last['content'][1]['type']);
    }

    public function testToolsGoOutAsFlatFunctions(): void
    {
        $tool = new Tool('read', 'Read a file', ['type' => 'object', 'properties' => []]);

        $this->send(new Context([new UserMessage('hi')], null, [$tool]));

        $sent = $this->server->receivedJson()['tools'][0];

        // Flat here, unlike chat-completions, where the same thing nests under `function`.
        $this->assertSame('function', $sent['type']);
        $this->assertSame('read', $sent['name']);
    }

    // ---- scaffolding -------------------------------------------------------------------------

    /** @param list<mixed> $content */
    private function assistant(array $content, StopReason $stop = StopReason::Stop): AssistantMessage
    {
        return new AssistantMessage(
            $content,
            Api::OpenAiResponses,
            'openai',
            'test-model',
            new Usage(),
            $stop,
        );
    }

    /** @return array{0: list<string>, 1: AssistantMessage} */
    private function collect(string $url, Context $context): array
    {
        return Async::run(function () use ($url, $context): array {
            $stream = (new OpenAiResponses())->stream(
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

    private function send(Context $context, ?Model $model = null, ?ReasoningEffort $reasoning = null): void
    {
        $url = $this->serve([['type' => 'response.completed', 'response' => ['status' => 'completed']]]);
        $model ??= $this->model();

        Async::run(function () use ($url, $context, $model, $reasoning): void {
            $stream = (new OpenAiResponses())->stream(
                $this->model($url, $model->reasoning, $model->acceptsImages(), $model->id),
                $context,
                new OpenAiOptions(apiKey: 'test-key', reasoning: $reasoning),
            );

            foreach ($stream as $ignored) {
                // Drain it; what is under test is what went out.
            }

            $stream->result()->await();
        });
    }

    private function model(
        string $baseUrl = 'http://127.0.0.1:1',
        bool $reasoning = false,
        bool $images = true,
        string $id = 'test-model',
    ): Model {
        return new Model(
            $id,
            'Test Model',
            Api::OpenAiResponses,
            'openai',
            rtrim($baseUrl, '/'),
            200_000,
            64_000,
            $reasoning,
            $images ? ['text', 'image'] : ['text'],
            new Pricing(input: 1.0, output: 2.0),
        );
    }

    /** @param list<array<string, mixed>> $events */
    private function serve(array $events): string
    {
        $pieces = ["HTTP/1.1 200 OK\r\nContent-Type: text/event-stream\r\nTransfer-Encoding: chunked\r\n\r\n"];

        foreach ($events as $event) {
            $body = "event: {$event['type']}\ndata: " . json_encode($event) . "\n\n";
            $pieces[] = sprintf("%x\r\n%s\r\n", strlen($body), $body);
        }

        $pieces[] = "0\r\n\r\n";

        return $this->server->start($pieces);
    }
}
