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
use Pig\Ai\Utils\ShortHash;
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
            // The finished item repeats the text whole, as OpenAI sends it; that copy is the one
            // kept (see testTheFinishedMessageItemIsTheTextAndARefusalIsPartOfIt).
            ['type' => 'response.output_item.done', 'item' => ['type' => 'message', 'id' => 'msg_1', 'content' => [
                ['type' => 'output_text', 'text' => 'Hello', 'annotations' => []],
            ]]],
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

        // The message's own id, which has to go back with it next turn — as upstream's
        // `TextSignatureV1` JSON now, where it used to be the bare id: upstream changed what it
        // writes so the item's `phase` can travel with the id (see the phase tests below).
        $this->assertSame('{"v":1,"id":"msg_1"}', $message->content[0]->textSignature);
        $this->assertSame(StopReason::Stop, $message->stopReason);
    }

    public function testTheFinishedMessageItemIsTheTextAndARefusalIsPartOfIt(): void
    {
        // Upstream's `output_item.done` arm rebuilds the block from the finished item:
        // `item.content.map(c => c.type === "output_text" ? c.text : c.refusal).join("")`. pig kept
        // the streamed deltas instead, so a refusal that arrived only on the finished item — or a
        // stream whose deltas fell short of the item — left the person with less than OpenAI said.
        $url = $this->serve([
            ['type' => 'response.output_item.added', 'item' => ['type' => 'message', 'id' => 'msg_1']],
            ['type' => 'response.output_text.delta', 'delta' => 'Part'],
            ['type' => 'response.output_item.done', 'item' => ['type' => 'message', 'id' => 'msg_1', 'content' => [
                ['type' => 'output_text', 'text' => 'Partly. '],
                ['type' => 'refusal', 'refusal' => 'I cannot help with the rest.'],
            ]]],
            ['type' => 'response.completed', 'response' => ['status' => 'completed']],
        ]);

        [, $message] = $this->collect($url, new Context([new UserMessage('hi')]));

        $this->assertSame('Partly. I cannot help with the rest.', $message->content[0]->text);
    }

    public function testAFinishedMessageItemWithNoContentLeavesNoText(): void
    {
        // Upstream's `?.map(...).join("") || ""`: an item with no `content` replaces the streamed
        // text with "", it does not fall back to it. Pinned so a "keep the deltas when the item
        // is empty" fallback is a deliberate departure and not a drift.
        $url = $this->serve([
            ['type' => 'response.output_item.added', 'item' => ['type' => 'message', 'id' => 'msg_1']],
            ['type' => 'response.output_text.delta', 'delta' => 'streamed'],
            ['type' => 'response.output_item.done', 'item' => ['type' => 'message', 'id' => 'msg_1']],
            ['type' => 'response.completed', 'response' => ['status' => 'completed']],
        ]);

        [, $message] = $this->collect($url, new Context([new UserMessage('hi')]));

        $this->assertSame('', $message->content[0]->text);
    }

    public function testAFinalAnswerPhaseDoesNotOutvoteTheTerminalStatus(): void
    {
        // Upstream sets `stop` when a `final_answer` message finishes, and then the terminal
        // event maps its own status over it. The net result is the status's: `incomplete` is
        // still `length` after a `final_answer` item.
        $url = $this->serve([
            ['type' => 'response.output_item.added', 'item' => ['type' => 'message', 'id' => 'msg_1']],
            ['type' => 'response.output_item.done', 'item' => ['type' => 'message', 'id' => 'msg_1', 'phase' => 'final_answer', 'content' => [
                ['type' => 'output_text', 'text' => 'cut sho'],
            ]]],
            ['type' => 'response.incomplete', 'response' => ['status' => 'incomplete', 'incomplete_details' => ['reason' => 'max_output_tokens']]],
        ]);

        [, $message] = $this->collect($url, new Context([new UserMessage('hi')]));

        $this->assertSame(StopReason::Length, $message->stopReason);
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

    public function testArgumentsThatOnlyArriveOnTheFinishedItemAreStillTheCall(): void
    {
        // Which is how a compatible endpoint can send them — Copilot speaks this API and implements
        // it itself — and how OpenAI's own stream ends every call: `output_item.done` carries the
        // complete `arguments` string. pig accumulated the deltas and never read that field, so a
        // call whose arguments were not streamed went out with none at all.
        $url = $this->serve([
            ['type' => 'response.output_item.added', 'item' => [
                'type' => 'function_call', 'id' => 'fc_1', 'call_id' => 'call_1', 'name' => 'read',
            ]],
            ['type' => 'response.output_item.done', 'item' => [
                'type' => 'function_call', 'id' => 'fc_1', 'call_id' => 'call_1', 'name' => 'read',
                'arguments' => '{"path":"b.php"}',
            ]],
            ['type' => 'response.completed', 'response' => ['status' => 'completed']],
        ]);

        [, $message] = $this->collect($url, new Context([new UserMessage('hi')]));
        $call = $message->content[0];

        $this->assertInstanceOf(ToolCall::class, $call);
        $this->assertSame(['path' => 'b.php'], $call->arguments);
    }

    public function testTheFinishedItemsArgumentsReplaceTheDeltasRatherThanJoiningThem(): void
    {
        // The usual case: the same JSON arrives twice, once in pieces and once whole. Appending the
        // whole to the pieces would be `{"path":"a.php"}{"path":"a.php"}` — valid to nobody.
        $url = $this->serve([
            ['type' => 'response.output_item.added', 'item' => [
                'type' => 'function_call', 'id' => 'fc_1', 'call_id' => 'call_1', 'name' => 'read', 'arguments' => '',
            ]],
            ['type' => 'response.function_call_arguments.delta', 'delta' => '{"path":'],
            ['type' => 'response.function_call_arguments.delta', 'delta' => '"a.php"}'],
            ['type' => 'response.output_item.done', 'item' => [
                'type' => 'function_call', 'id' => 'fc_1', 'call_id' => 'call_1', 'name' => 'read',
                'arguments' => '{"path":"a.php"}',
            ]],
            ['type' => 'response.completed', 'response' => ['status' => 'completed']],
        ]);

        [, $message] = $this->collect($url, new Context([new UserMessage('hi')]));
        $call = $message->content[0];

        $this->assertInstanceOf(ToolCall::class, $call);
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
            ['type' => 'response.output_item.done', 'item' => ['type' => 'message', 'id' => 'msg_1', 'content' => [
                ['type' => 'output_text', 'text' => 'half an ans'],
            ]]],
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

    public function testACallWithNoArgumentsGoesOutAsAnObjectAndNotAnEmptyList(): void
    {
        // An empty PHP array encodes as `[]`, and `arguments` is an object — Anthropic's and
        // Google's arms guard this and these two did not.
        $this->send(new Context([
            new UserMessage('hi'),
            $this->assistant([new ToolCall('c1', 'now', [])]),
            new ToolResultMessage('c1', 'now', [new TextContent('12:00')], false),
            new UserMessage('thanks'),
        ]));

        $input = $this->server->receivedJson()['input'];
        $call = array_values(array_filter(
            $input,
            static fn (array $item): bool => ($item['type'] ?? '') === 'function_call',
        ))[0];

        $this->assertSame('{}', $call['arguments']);
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

    public function testATurnAbortedAfterItsReasoningDoesNotSendTheReasoningBackAlone(): void
    {
        // Escape after the reasoning item finished and before the answer started leaves a turn
        // that is nothing but a signed reasoning item. `OpenAiResponses` used to guard `Error`
        // only, so pig sent this one back on the next prompt, followed by the user turn — OpenAI's
        // "reasoning was provided without its required following item" 400, upstream's reason for
        // skipping a failed turn in `transformMessages()`, which `TransformMessages` now does.
        $context = new Context([
            new UserMessage('hi'),
            $this->assistant([new ThinkingContent('a whole thought', '{"type":"reasoning","id":"rs_1"}')], StopReason::Aborted),
            new UserMessage('go on'),
        ]);

        $this->send($context);

        $kinds = array_map(static fn (array $item): string => $item['type'] ?? $item['role'], $this->server->receivedJson()['input']);

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

    public function testAMessagesPhaseIsKeptWithItsIdAndSentBackOnTheSameMessage(): void
    {
        // gpt-5 marks a message item `commentary` (said between tool calls) or `final_answer`.
        // Upstream writes the phase into the text signature beside the id and sends it back on
        // the replayed item; pig kept the bare id, so the phase was dropped on every replay.
        $url = $this->serve([
            ['type' => 'response.output_item.added', 'item' => ['type' => 'message', 'id' => 'msg_1']],
            ['type' => 'response.output_text.delta', 'delta' => 'checking'],
            ['type' => 'response.output_item.done', 'item' => ['type' => 'message', 'id' => 'msg_1', 'phase' => 'commentary', 'content' => [
                ['type' => 'output_text', 'text' => 'checking'],
            ]]],
            ['type' => 'response.completed', 'response' => ['status' => 'completed']],
        ]);

        [, $message] = $this->collect($url, new Context([new UserMessage('hi')]));

        $this->assertSame('{"v":1,"id":"msg_1","phase":"commentary"}', $message->content[0]->textSignature);

        $this->server = new CannedServer();
        $this->send(new Context([new UserMessage('hi'), $message, new UserMessage('go on')]));
        $item = $this->server->receivedJson()['input'][1];

        $this->assertSame('msg_1', $item['id'], 'the id out of the JSON, not the JSON');
        $this->assertSame('commentary', $item['phase']);
    }

    public function testOnlyTheTwoKnownPhasesGoBackAndNoPhaseMeansNoField(): void
    {
        // Upstream's `parseTextSignature()` passes on `commentary` and `final_answer` and nothing
        // else; a signature without one gives an item with no `phase` key at all (upstream's
        // `undefined`), not `phase: null`.
        $context = new Context([
            new UserMessage('hi'),
            $this->assistant([
                new TextContent('one', '{"v":1,"id":"msg_a","phase":"final_answer"}'),
                new TextContent('two', '{"v":1,"id":"msg_b","phase":"thinking_aloud"}'),
                new TextContent('three', '{"v":1,"id":"msg_c"}'),
            ]),
            new UserMessage('go on'),
        ]);

        $this->send($context);
        $input = $this->server->receivedJson()['input'];

        $this->assertSame(['msg_a', 'msg_b', 'msg_c'], [$input[1]['id'], $input[2]['id'], $input[3]['id']]);
        $this->assertSame('final_answer', $input[1]['phase']);
        $this->assertArrayNotHasKey('phase', $input[2]);
        $this->assertArrayNotHasKey('phase', $input[3]);
    }

    public function testATextBlockWithNoIdGetsUpstreamsFallbackNumberedByMessageAndBlock(): void
    {
        // Upstream's fallback is `msg_pi_${msgIndex}` for a message's first text block and
        // `msg_pi_${msgIndex}_${textBlockIndex}` for the rest. pig sent `msg_${position}` for every
        // block, so two text blocks in one message went out under the same id. And `msgIndex` counts
        // the messages that went out: the empty user turn sends nothing and does not move it.
        $context = new Context([
            new UserMessage([]),
            new UserMessage('hi'),
            $this->assistant([new TextContent('first'), new TextContent('second')]),
            new UserMessage('go on'),
            $this->assistant([new TextContent('third')]),
        ]);

        $this->send($context);
        $ids = array_column(
            array_filter($this->server->receivedJson()['input'], static fn (array $item): bool => ($item['type'] ?? null) === 'message'),
            'id',
        );

        $this->assertSame(['msg_pi_1', 'msg_pi_1_1', 'msg_pi_3'], array_values($ids));
    }

    public function testAnOverlongIdInsideTheJsonIsHashedAsTheIdAlone(): void
    {
        // The hash is of the id, as upstream's `shortHash(msgId)` after parsing — not of the
        // whole signature, which would give a different id from pi's for the same message.
        $long = 'msg_' . str_repeat('x', 200);
        $context = new Context([
            new UserMessage('hi'),
            $this->assistant([new TextContent('the answer', (string) json_encode(['v' => 1, 'id' => $long]))]),
            new UserMessage('go on'),
        ]);

        $this->send($context);

        $this->assertSame('msg_' . ShortHash::of($long), $this->server->receivedJson()['input'][1]['id']);
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

    /**
     * A call from another provider has one id, and the item id is left out rather than copied.
     *
     * OpenAI validates the *shape* of `id` — it must begin with `fc` — so sending the call id
     * there is `400 Invalid 'input[1].id'` and the whole conversation is refused. Found by running
     * `test/live.php` against the real API; the test that used to be here asserted the id was used
     * twice, which is what the code did rather than what the caller needs, and it held the bug in
     * place. Upstream sends `split("|")[1]`, which is `undefined` for a foreign id and disappears
     * from the JSON — an omission PHP has to make on purpose.
     *
     * What this is the fix for: `/model` from Anthropic to gpt-5 with a tool call in the history,
     * and a dangling call `TransformMessages` invented a result for.
     */
    public function testACallFromAnotherProviderSendsNoItemIdAtAll(): void
    {
        // What `/model` leaves behind when it switches mid-conversation.
        $context = new Context([
            new UserMessage('hi'),
            $this->assistant([new ToolCall('toolu_abc', 'read', [])]),
            new ToolResultMessage('toolu_abc', 'read', [new TextContent('ok')]),
        ]);

        $this->send($context);

        $input = $this->server->receivedJson()['input'];

        $this->assertArrayNotHasKey('id', $input[1]);
        $this->assertSame('toolu_abc', $input[1]['call_id']);

        // The result is addressed by `call_id` alone in both cases, so it is unaffected.
        $this->assertSame('toolu_abc', $input[2]['call_id']);
    }

    public function testAnotherProvidersPairKeepsItsCallIdAndGetsAnItemIdOfItsOwn(): void
    {
        // Upstream's `normalizeToolCallId` in `convertResponsesMessages()`. `/model` from
        // `github-copilot/gpt-5` to `openai/gpt-5`: the pair is a Responses pair, but its item id
        // was minted by another provider, so it is replaced by `fc_` and a hash of itself rather
        // than sent as it came — pig sent it as it came, `+`, `/`, `=` and all, and OpenAI
        // validates the shape of `id`. The call id is what the result is addressed by, and both
        // sides of the pair agree on it.
        $id = 'call_1|' . str_repeat('Zm9v+/=', 70);
        $this->send(new Context([
            new UserMessage('hi'),
            new AssistantMessage(
                [new ToolCall($id, 'read', [])],
                Api::OpenAiResponses,
                'github-copilot',
                'gpt-5',
                new Usage(),
                StopReason::ToolUse,
            ),
            new ToolResultMessage($id, 'read', [new TextContent('ok')]),
        ]));

        $input = $this->server->receivedJson()['input'];

        $this->assertSame('call_1', $input[1]['call_id']);
        $this->assertStringStartsWith('fc_', $input[1]['id']);
        $this->assertLessThanOrEqual(64, strlen($input[1]['id']));
        $this->assertSame(1, preg_match('/^[a-zA-Z0-9_-]+$/', $input[1]['id']));
        $this->assertSame('call_1', $input[2]['call_id']);
    }

    public function testAnotherModelOfThisProviderSendsNoItemIdAndNeitherDoesOneNotStartingFc(): void
    {
        // Upstream's `isDifferentModel`: same provider, same API, another model. OpenAI pairs a
        // call's item id with the `rs_…` reasoning item before it, and another model's reasoning
        // is not sent back (`TransformMessages` makes it text), so a paired id would arrive
        // without its reasoning item. Upstream leaves the id out, as it does for a foreign call;
        // pig sent it. An item id that is not `fc_…` is left out too — OpenAI refuses the shape.
        $this->send(new Context([
            new UserMessage('hi'),
            new AssistantMessage(
                [new ToolCall('call_1|fc_1', 'read', [])],
                Api::OpenAiResponses,
                'openai',
                'gpt-5-mini',
                new Usage(),
                StopReason::ToolUse,
            ),
            new ToolResultMessage('call_1|fc_1', 'read', [new TextContent('ok')]),
            $this->assistant([new ToolCall('call_2|ctc_2', 'read', [])]),
            new ToolResultMessage('call_2|ctc_2', 'read', [new TextContent('ok')]),
        ]));

        $input = $this->server->receivedJson()['input'];

        $this->assertSame('call_1', $input[1]['call_id']);
        $this->assertArrayNotHasKey('id', $input[1], 'another model of this provider');
        $this->assertSame('call_2', $input[3]['call_id']);
        $this->assertArrayNotHasKey('id', $input[3], 'not an fc_ id');
    }

    public function testCopilotIsSentAnotherModelsPairAsOneCallIdAndNoItemId(): void
    {
        // Copilot is not among upstream's `OPENAI_TOOL_CALL_PROVIDERS`, so another model's pair
        // is sanitised whole for it: the `|` becomes `_`, and with no `|` left there is no item
        // id to send. The result is renamed to match. (pig's old rule renamed only
        // Copilot-to-Copilot crossings, and only for chat completions' sake.)
        $this->sendAsCopilot(new Context([
            new UserMessage('hi'),
            new AssistantMessage(
                [new ToolCall('call_1|fc_1', 'read', [])],
                Api::OpenAiResponses,
                'openai',
                'gpt-5',
                new Usage(),
                StopReason::ToolUse,
            ),
            new ToolResultMessage('call_1|fc_1', 'read', [new TextContent('ok')]),
        ]));

        $input = $this->server->receivedJson()['input'];

        $this->assertSame('call_1_fc_1', $input[1]['call_id']);
        $this->assertArrayNotHasKey('id', $input[1]);
        $this->assertSame('call_1_fc_1', $input[2]['call_id']);
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

    /**
     * A truncated answer is a truncated answer, and it still reports what it cost.
     *
     * `response.incomplete` is the other terminal event and was not in the list, so the stream
     * just ended: the status was never read, `stopReason()`'s `'incomplete' => Length` arm was
     * unreachable, and the usage riding on that event was dropped. What came back was a clean
     * `stop` with **no usage at all** — the half-sentence read as the finished answer, and the
     * turn cost nothing in `/session` or the footer.
     *
     * Found against the real API with a 16-token budget: `stop` after 0 output tokens. Two
     * symptoms, one missing arm. Upstream handles neither terminal event.
     */
    public function testTheResponseIdComesFromCreatedAndTheReasoningSplitFromTheUsage(): void
    {
        // Upstream takes the id from `response.created` and again from the terminal event.
        $url = $this->serve([
            ['type' => 'response.created', 'response' => ['id' => 'resp_1', 'status' => 'in_progress']],
            ['type' => 'response.output_item.added', 'output_index' => 0, 'item' => ['type' => 'message']],
            ['type' => 'response.output_text.delta', 'output_index' => 0, 'delta' => 'hi'],
            ['type' => 'response.completed', 'response' => [
                'status' => 'completed',
                'usage' => [
                    'input_tokens' => 10,
                    'output_tokens' => 20,
                    'total_tokens' => 30,
                    'output_tokens_details' => ['reasoning_tokens' => 12],
                ],
            ]],
        ]);

        [, $message] = $this->collect($url, new Context([new UserMessage('hi')]));

        $this->assertSame('resp_1', $message->responseId);
        // A share of `output_tokens`, which already counts it.
        $this->assertSame(12, $message->usage->reasoning);
        $this->assertSame(20, $message->usage->output);

        // A terminal event that names the response is believed over what came before, and a usage
        // without the breakdown still says 0 rather than nothing — upstream's `|| 0`.
        $this->server = new CannedServer();
        $url = $this->serve([
            ['type' => 'response.output_item.added', 'output_index' => 0, 'item' => ['type' => 'message']],
            ['type' => 'response.output_text.delta', 'output_index' => 0, 'delta' => 'hi'],
            ['type' => 'response.completed', 'response' => [
                'id' => 'resp_2',
                'status' => 'completed',
                'usage' => ['input_tokens' => 1, 'output_tokens' => 1, 'total_tokens' => 2],
            ]],
        ]);

        [, $late] = $this->collect($url, new Context([new UserMessage('hi')]));

        $this->assertSame('resp_2', $late->responseId);
        $this->assertSame(0, $late->usage->reasoning);
    }

    public function testAnIncompleteResponseIsLengthAndKeepsItsUsage(): void
    {
        $url = $this->serve([
            ['type' => 'response.output_item.added', 'output_index' => 0, 'item' => ['type' => 'message']],
            ['type' => 'response.output_text.delta', 'output_index' => 0, 'delta' => 'The sea is'],
            ['type' => 'response.incomplete', 'response' => [
                'status' => 'incomplete',
                'incomplete_details' => ['reason' => 'max_output_tokens'],
                'usage' => ['input_tokens' => 14, 'output_tokens' => 16, 'total_tokens' => 30],
            ]],
        ]);

        [, $message] = $this->collect($url, new Context([new UserMessage('write a paragraph')]));

        $this->assertSame(StopReason::Length, $message->stopReason);
        // `length` cannot say what ran out; the raw reason can, as upstream writes it.
        $this->assertSame('incomplete.max_output_tokens', $message->rawStopReason);
        $this->assertSame(14, $message->usage->input);
        $this->assertSame(16, $message->usage->output);
    }

    public function testACompletedResponseKeepsItsStatusAsTheRawStopReason(): void
    {
        $url = $this->serve([['type' => 'response.completed', 'response' => ['status' => 'completed']]]);

        [, $message] = $this->collect($url, new Context([new UserMessage('hi')]));

        // No incomplete details, so no suffix: the status alone.
        $this->assertSame('completed', $message->rawStopReason);
    }

    public function testAFailedResponseKeepsItsStatusThroughTheError(): void
    {
        // Upstream sets the raw reason from the failed response's status and then throws; the
        // error turn is built from the same state, so it still says `failed`.
        $url = $this->serve([['type' => 'response.failed', 'response' => [
            'status' => 'failed',
            'error' => ['message' => 'the server had an error'],
        ]]]);

        [, $message] = $this->collect($url, new Context([new UserMessage('hi')]));

        $this->assertSame(StopReason::Error, $message->stopReason);
        $this->assertStringContainsString('the server had an error', (string) $message->errorMessage);
        $this->assertSame('failed', $message->rawStopReason);
    }

    /** An error event with nothing at the documented path still says what arrived. */
    public function testAnErrorWithNoMessageWhereItBelongsIsNotCalledUnknown(): void
    {
        // A live run produced a bare `unknown error` for an oversized prompt, which means the
        // message was somewhere else. Both shapes are read now, and the payload is the fallback:
        // `Overflow`'s table matches against the provider's own words, so a message that goes
        // missing here is a conversation that could have been compacted and instead died.
        $url = $this->serve([['type' => 'error', 'error' => [
            'message' => 'Requested 300000 tokens, exceeds the context window',
            'code' => 'context_length_exceeded',
        ]]]);

        [, $message] = $this->collect($url, new Context([new UserMessage('hi')]));

        $this->assertSame(StopReason::Error, $message->stopReason);
        $this->assertStringContainsString('exceeds the context window', (string) $message->errorMessage);
        $this->assertStringContainsString('context_length_exceeded', (string) $message->errorMessage);

        // **And it is the message rather than the payload printed around it.** Asserting only that
        // the sentence is in there passes either way, because the fallback prints the whole JSON —
        // which contains it. The mutation that reads the flat path alone was silent until this
        // line, in a test written to catch exactly that mutation.
        $this->assertStringNotContainsString('an error with no message in it', (string) $message->errorMessage);
    }

    /** And with the message nowhere at all, the payload itself is the message. */
    public function testAnErrorWithNoMessageAnywhereCarriesWhatArrived(): void
    {
        $url = $this->serve([['type' => 'error', 'detail' => 'a shape nobody documented']]);

        [, $message] = $this->collect($url, new Context([new UserMessage('hi')]));

        $this->assertStringContainsString('an error with no message in it', (string) $message->errorMessage);
        $this->assertStringContainsString('a shape nobody documented', (string) $message->errorMessage);
    }

    // ---- what Copilot needs on top -----------------------------------------------------------

    public function testCopilotIsToldWhoAskedAndThatAnImageIsComing(): void
    {
        // Copilot's models speak *this* API, and these three headers lived in the completions
        // provider only — so the one that actually talks to Copilot sent none of them: every
        // follow-up after a tool was billed as though a person had typed it, and an image was
        // refused outright.
        $this->sendAsCopilot(new Context([
            new UserMessage([new TextContent('look at this'), new ImageContent('AAA', 'image/png')]),
            $this->assistant([new ToolCall('c1', 'read', ['path' => 'a.php'])]),
            new ToolResultMessage('c1', 'read', [new TextContent('ok')], false),
        ]));

        $head = strtolower($this->server->receivedHead());

        $this->assertStringContainsString('x-initiator: agent', $head, 'a tool result is the agent carrying on');
        $this->assertStringContainsString('openai-intent: conversation-edits', $head);
        $this->assertStringContainsString('copilot-vision-request: true', $head);
    }

    public function testATurnSomebodyTypedIsNotAnAgentCall(): void
    {
        $this->sendAsCopilot(new Context([new UserMessage('hello')]));

        $head = strtolower($this->server->receivedHead());

        $this->assertStringContainsString('x-initiator: user', $head);
        $this->assertStringNotContainsString('copilot-vision-request', $head, 'and no image, no header');
    }

    public function testAnOrdinaryOpenAiModelGetsNoneOfThem(): void
    {
        $this->send(new Context([new UserMessage('hello')]));

        $head = strtolower($this->server->receivedHead());

        $this->assertStringNotContainsString('x-initiator', $head);
        $this->assertStringNotContainsString('openai-intent', $head);
    }

    public function testNothingSaidYetCountsAsThePerson(): void
    {
        // `Copilot::headers()` reads the *last* message to decide who asked, and an empty
        // conversation has none — which its own comment calls upstream's default and which no
        // test reached: swapping the `!== null` that answers it changed nothing in the suite.
        // Copilot bills and rate-limits an agent call differently from something a person typed.
        $this->sendAsCopilot(new Context([]));

        $this->assertStringContainsString('x-initiator: user', $this->server->receivedHead());
    }

    private function sendAsCopilot(Context $context): void
    {
        $url = $this->serve([['type' => 'response.completed', 'response' => ['status' => 'completed']]]);

        Async::run(function () use ($url, $context): void {
            $model = new Model(
                'gpt-5.2',
                'GPT-5.2',
                Api::OpenAiResponses,
                'github-copilot',
                rtrim($url, '/'),
                200_000,
                64_000,
                true,
                ['text', 'image'],
                new Pricing(),
            );

            // An empty key on purpose: with one, `endpoint()` asks `GithubCopilot::baseUrl()` where
            // to go and the request leaves for the real Copilot API instead of the canned server.
            $stream = (new OpenAiResponses())->stream($model, $context, new OpenAiOptions(apiKey: ''));

            foreach ($stream as $ignored) {
                // Drain it; what is under test is what went out.
            }

            $stream->result()->await();
        });
    }

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
        $url = $this->serve([['type' => 'response.completed', 'response' => ['status' => 'completed']]]);
        $model ??= $this->model();

        Async::run(function () use ($url, $context, $model, $reasoning, $temperature): void {
            $stream = (new OpenAiResponses())->stream(
                $this->model($url, $model->reasoning, $model->acceptsImages(), $model->id),
                $context,
                new OpenAiOptions(temperature: $temperature, apiKey: 'test-key', reasoning: $reasoning),
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
