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

    /**
     * Upstream rebuilds the thinking text from the finished reasoning item, the way it
     * rebuilds a message's text: the summaries joined by a blank line. The deltas only had
     * a part break *after* each part, so the streamed text ends in a dangling "\n\n" the
     * finished item does not — and it is the finished item that is stored and shown.
     */
    public function testTheFinishedReasoningItemsSummaryIsTheThinkingText(): void
    {
        $item = [
            'type' => 'reasoning',
            'id' => 'rs_1',
            'summary' => [['type' => 'summary_text', 'text' => 'first'], ['type' => 'summary_text', 'text' => 'second']],
            'encrypted_content' => 'OPAQUE',
        ];

        $url = $this->serve([
            ['type' => 'response.output_item.added', 'item' => ['type' => 'reasoning', 'id' => 'rs_1']],
            ['type' => 'response.reasoning_summary_text.delta', 'delta' => 'fir'],
            ['type' => 'response.reasoning_summary_part.done', 'part' => []],
            ['type' => 'response.output_item.done', 'item' => $item],
            ['type' => 'response.completed', 'response' => ['status' => 'completed']],
        ]);

        [, $message] = $this->collect($url, new Context([new UserMessage('hi')]));

        $this->assertSame("first\n\nsecond", $message->content[0]->thinking);
        // Replacing the text does not touch the signature: still the whole item.
        $this->assertSame($item, json_decode((string) $message->content[0]->thinkingSignature, true));
    }

    /**
     * `summaryText || contentText || streamed`: a model that exposes its raw reasoning rather
     * than a summary (`reasoning_text.delta`, `item.content`) gets that as its thinking text,
     * and an empty summary list does not outvote it.
     */
    public function testRawReasoningContentIsTheThinkingWhenThereIsNoSummary(): void
    {
        $url = $this->serve([
            ['type' => 'response.output_item.added', 'item' => ['type' => 'reasoning', 'id' => 'rs_1']],
            ['type' => 'response.reasoning_text.delta', 'delta' => 'streamed raw'],
            ['type' => 'response.output_item.done', 'item' => [
                'type' => 'reasoning',
                'id' => 'rs_1',
                'summary' => [],
                'content' => [['type' => 'reasoning_text', 'text' => 'raw one'], ['type' => 'reasoning_text', 'text' => 'raw two']],
            ]],
            ['type' => 'response.completed', 'response' => ['status' => 'completed']],
        ]);

        [$types, $message] = $this->collect($url, new Context([new UserMessage('hi')]));

        $this->assertContains('ThinkingDeltaEvent', $types);
        $this->assertSame("raw one\n\nraw two", $message->content[0]->thinking);
    }

    /**
     * An item with neither summary nor content text keeps what the deltas built, rather than
     * wiping the thinking to "" the way a message item with no content wipes its text.
     */
    public function testAReasoningItemWithNothingToReadKeepsTheStreamedThinking(): void
    {
        $url = $this->serve([
            ['type' => 'response.output_item.added', 'item' => ['type' => 'reasoning', 'id' => 'rs_1']],
            ['type' => 'response.reasoning_text.delta', 'delta' => 'streamed raw'],
            ['type' => 'response.output_item.done', 'item' => ['type' => 'reasoning', 'id' => 'rs_1', 'summary' => []]],
            ['type' => 'response.completed', 'response' => ['status' => 'completed']],
        ]);

        [, $message] = $this->collect($url, new Context([new UserMessage('hi')]));

        $this->assertSame('streamed raw', $message->content[0]->thinking);
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
        // Only `max_output_tokens` is `length` — upstream's `mapStopReason()`. This used to send a
        // bare `incomplete` and expect `length`; with no reason that is now an error (below).
        $url = $this->serve([
            ['type' => 'response.output_item.added', 'item' => ['type' => 'message', 'id' => 'msg_1']],
            ['type' => 'response.output_text.delta', 'delta' => 'half an ans'],
            ['type' => 'response.output_item.done', 'item' => ['type' => 'message', 'id' => 'msg_1', 'content' => [
                ['type' => 'output_text', 'text' => 'half an ans'],
            ]]],
            ['type' => 'response.completed', 'response' => ['status' => 'incomplete', 'incomplete_details' => ['reason' => 'max_output_tokens']]],
        ]);

        [, $message] = $this->collect($url, new Context([new UserMessage('hi')]));

        $this->assertSame(StopReason::Length, $message->stopReason);
    }

    public function testAnAnswerCutOffForAnyOtherReasonIsAnErrorThatSaysWhy(): void
    {
        // A content filter stopping the answer is not "the answer ran long": pig read every
        // `incomplete` as `length`, so a filtered turn looked like a truncated one and the agent
        // carried on with it. Upstream ends it as an error naming the reason.
        $url = $this->serve([
            ['type' => 'response.output_item.added', 'item' => ['type' => 'message', 'id' => 'msg_1']],
            ['type' => 'response.output_item.done', 'item' => ['type' => 'message', 'id' => 'msg_1', 'content' => [
                ['type' => 'output_text', 'text' => 'partial'],
            ]]],
            ['type' => 'response.incomplete', 'response' => ['status' => 'incomplete', 'incomplete_details' => ['reason' => 'content_filter']]],
        ]);

        [$types, $message] = $this->collect($url, new Context([new UserMessage('hi')]));

        $this->assertContains('ErrorEvent', $types);
        $this->assertSame(StopReason::Error, $message->stopReason);
        $this->assertSame('Response incomplete: content_filter', $message->errorMessage);
        $this->assertSame('incomplete.content_filter', $message->rawStopReason);

        $this->server = new CannedServer();
        [, $bare] = $this->collect($this->serve([
            ['type' => 'response.completed', 'response' => ['status' => 'incomplete']],
        ]), new Context([new UserMessage('hi')]));

        $this->assertSame('Response incomplete without a provider reason', $bare->errorMessage);
    }

    public function testAToolCallWhoseItemNeverFinishedIsRefused(): void
    {
        // Upstream: "The agent runs every tool call in the final message. Refuse to hand over calls
        // whose output_item.done never arrived: their arguments may be cut off or mixed up". pig
        // handed the half-built call to the agent, which ran it.
        $url = $this->serve([
            ['type' => 'response.output_item.added', 'item' => [
                'type' => 'function_call', 'id' => 'fc_1', 'call_id' => 'call_1', 'name' => 'bash',
            ]],
            ['type' => 'response.function_call_arguments.delta', 'delta' => '{"command":"rm -'],
            ['type' => 'response.completed', 'response' => ['status' => 'completed']],
        ]);

        [$types, $message] = $this->collect($url, new Context([new UserMessage('hi')]));

        $this->assertContains('ErrorEvent', $types);
        $this->assertSame(
            'OpenAI Responses stream completed with an unfinished tool call: bash (call_1|fc_1)',
            $message->errorMessage,
        );
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

    public function testThinkingOffIsTheMapsOffEffortAndNoLongerAJuiceMessage(): void
    {
        // Upstream's off arm: `reasoning: {effort: map.off ?? "none"}` unless the map says `off:
        // null` or the provider is Copilot. pig appended a `# Juice: 0 !important` developer
        // message to every reasoning gpt-5 turn instead, which upstream no longer sends.
        [, $body] = $this->capture($this->model(reasoning: true, id: 'gpt-5.2', levels: ['off' => 'none']), new Context([new UserMessage('hi')]));

        $this->assertSame(['effort' => 'none'], $body['reasoning']);
        $this->assertSame(['user'], array_column($body['input'], 'role'));
        $this->assertArrayNotHasKey('include', $body);

        // No map at all: `?? "none"`.
        [, $body] = $this->capture($this->model(reasoning: true, id: 'o3'), new Context([new UserMessage('hi')]));
        $this->assertSame(['effort' => 'none'], $body['reasoning']);

        // A model that cannot be switched off (`off: null`, every gpt-5 before 5.1): nothing.
        [, $body] = $this->capture($this->model(reasoning: true, id: 'gpt-5', levels: ['off' => null]), new Context([new UserMessage('hi')]));
        $this->assertArrayNotHasKey('reasoning', $body);

        // Copilot: nothing either, whatever the map says.
        [, $body] = $this->capture($this->model(reasoning: true, id: 'gpt-6-sol', levels: ['off' => 'none'], provider: 'github-copilot'), new Context([new UserMessage('hi')]));
        $this->assertArrayNotHasKey('reasoning', $body);
    }

    public function testAModelThatDoesNotReasonIsToldNothingAboutReasoning(): void
    {
        [, $body] = $this->capture($this->model(id: 'gpt-5.2'), new Context([new UserMessage('hi')]));

        $this->assertSame('user', $body['input'][count($body['input']) - 1]['role']);
        $this->assertArrayNotHasKey('reasoning', $body);
    }

    public function testTheEffortIsWhatTheModelsMapCallsTheLevel(): void
    {
        // `model.thinkingLevelMap?.[effort] ?? effort`: pig sent the level's own name whatever
        // the map said.
        [, $body] = $this->capture(
            $this->model(reasoning: true, levels: ['high' => 'maximal']),
            new Context([new UserMessage('hi')]),
            new OpenAiOptions(apiKey: 'test-key', reasoning: ReasoningEffort::High),
        );

        $this->assertSame(['effort' => 'maximal', 'summary' => 'auto'], $body['reasoning']);
    }

    public function testMaxOutputTokensIsNeverBelowSixteen(): void
    {
        // "OpenAI Responses rejects max_output_tokens below 16" — upstream raises it; pig sent the
        // number as it came and the API answered 400.
        [, $body] = $this->capture($this->model(), new Context([new UserMessage('hi')]), new OpenAiOptions(maxTokens: 5, apiKey: 'test-key'));
        $this->assertSame(16, $body['max_output_tokens']);

        // A 0 is upstream's falsy `options?.maxTokens`: nothing is sent.
        [, $body] = $this->capture($this->model(), new Context([new UserMessage('hi')]), new OpenAiOptions(maxTokens: 0, apiKey: 'test-key'));
        $this->assertArrayNotHasKey('max_output_tokens', $body);

        // And an endpoint whose compat says it refuses the field gets none.
        [, $body] = $this->capture(
            $this->model(compat: new OpenAiCompat(supportsMaxOutputTokens: false)),
            new Context([new UserMessage('hi')]),
            new OpenAiOptions(maxTokens: 500, apiKey: 'test-key'),
        );
        $this->assertArrayNotHasKey('max_output_tokens', $body);
    }

    public function testEveryRequestSaysStoreFalse(): void
    {
        [, $body] = $this->capture($this->model(), new Context([new UserMessage('hi')]));

        $this->assertFalse($body['store']);
    }

    public function testTheSessionIsThePromptCacheKeyAndTheAffinityHeaders(): void
    {
        // Upstream: `prompt_cache_key` is the session id cut to 64 code points, and the client
        // sends it as `session_id` and `x-client-request-id` for the `openai` format.
        $session = str_repeat('é', 70);
        [$head, $body] = $this->capture($this->model(), new Context([new UserMessage('hi')]), new OpenAiOptions(apiKey: 'test-key', sessionId: $session));

        $this->assertSame(str_repeat('é', 64), $body['prompt_cache_key']);
        $this->assertStringContainsString("session_id: {$session}", $head);
        $this->assertStringContainsString("x-client-request-id: {$session}", $head);
        $this->assertArrayNotHasKey('prompt_cache_retention', $body);

        // `cacheRetention: none`: no key and no headers.
        [$head, $body] = $this->capture($this->model(), new Context([new UserMessage('hi')]), new OpenAiOptions(apiKey: 'test-key', cacheRetention: 'none', sessionId: 's1'));
        $this->assertArrayNotHasKey('prompt_cache_key', $body);
        $this->assertStringNotContainsString('x-client-request-id', $head);

        // OpenRouter's format is one header, `x-session-id`.
        [$head] = $this->capture($this->model(provider: 'openrouter'), new Context([new UserMessage('hi')]), new OpenAiOptions(apiKey: 'test-key', sessionId: 's1'));
        $this->assertStringContainsString('x-session-id: s1', $head);
        $this->assertStringNotContainsString('session_id', $head);
    }

    public function testLongRetentionIsTwentyFourHoursOrAThirtyMinuteExplicitCache(): void
    {
        [, $body] = $this->capture($this->model(), new Context([new UserMessage('hi')]), new OpenAiOptions(apiKey: 'sk-test', cacheRetention: 'long'));
        $this->assertSame('24h', $body['prompt_cache_retention']);
        $this->assertArrayNotHasKey('prompt_cache_options', $body);

        // GPT-5.6 and later (`supportsExplicitPromptCacheMode`): `prompt_cache_options` instead —
        // `{ttl: "30m"}` for long, `{mode: "explicit"}` for none.
        $explicit = $this->model(compat: new OpenAiCompat(supportsExplicitPromptCacheMode: true));
        [, $body] = $this->capture($explicit, new Context([new UserMessage('hi')]), new OpenAiOptions(apiKey: 'sk-test', cacheRetention: 'long'));
        $this->assertSame(['ttl' => '30m'], $body['prompt_cache_options']);
        $this->assertArrayNotHasKey('prompt_cache_retention', $body);

        [, $body] = $this->capture($explicit, new Context([new UserMessage('hi')]), new OpenAiOptions(apiKey: 'sk-test', cacheRetention: 'none'));
        $this->assertSame(['mode' => 'explicit'], $body['prompt_cache_options']);
    }

    public function testTheToolChoiceAndServiceTierGoOutAndTheTierPricesTheTurn(): void
    {
        $url = $this->serve([['type' => 'response.completed', 'response' => [
            'status' => 'completed',
            'service_tier' => 'flex',
            'usage' => ['input_tokens' => 1_000_000, 'output_tokens' => 0, 'total_tokens' => 1_000_000],
        ]]]);
        $message = Async::run(function () use ($url) {
            $stream = (new OpenAiResponses())->stream(
                $this->model(baseUrl: $url),
                new Context([new UserMessage('hi')]),
                new OpenAiOptions(apiKey: 'test-key', toolChoice: 'none', serviceTier: 'priority'),
            );

            foreach ($stream as $ignored) {
            }

            return $stream->result()->await();
        });
        $body = $this->server->receivedJson();

        $this->assertSame('none', $body['tool_choice']);
        $this->assertSame('priority', $body['service_tier']);
        // The tier the response reports wins over the one asked for: flex halves the $1/Mtok input.
        $this->assertEqualsWithDelta(0.5, $message->usage->cost->input, 1e-9);
        $this->assertEqualsWithDelta(0.5, $message->usage->cost->total, 1e-9);
    }

    public function testACallsNamespaceIsKeptAndGoesBackOnlyToTheSameModel(): void
    {
        // Upstream's `ToolCall.namespace`, "for calls to dynamically loaded or namespaced tools":
        // read off the item, and replayed on it — but only to the model that made the call.
        $url = $this->serve([
            ['type' => 'response.output_item.added', 'item' => [
                'type' => 'function_call', 'id' => 'fc_1', 'call_id' => 'call_1', 'name' => 'search', 'namespace' => 'mcp_docs',
            ]],
            ['type' => 'response.output_item.done', 'item' => [
                'type' => 'function_call', 'id' => 'fc_1', 'call_id' => 'call_1', 'name' => 'search', 'arguments' => '{}', 'namespace' => 'mcp_docs',
            ]],
            ['type' => 'response.completed', 'response' => ['status' => 'completed']],
        ]);

        [, $message] = $this->collect($url, new Context([new UserMessage('hi')]));
        $call = $message->content[0];
        $this->assertInstanceOf(ToolCall::class, $call);
        $this->assertSame('mcp_docs', $call->namespace);

        $history = new Context([
            new UserMessage('hi'),
            $message,
            new ToolResultMessage($call->id, 'search', [new TextContent('found')]),
        ]);

        [, $body] = $this->capture($this->model(), $history);
        $this->assertSame('mcp_docs', $body['input'][1]['namespace']);

        // Another model of the same provider: the call goes back without it.
        [, $body] = $this->capture($this->model(id: 'other-model'), $history);
        $this->assertArrayNotHasKey('namespace', $body['input'][1]);
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

    /**
     * Upstream's `convertToolResultOutput()` puts a result's images **inside its
     * `function_call_output`**: `output` becomes a content list — the text as `input_text` when
     * there is any, then each image as `input_image` with `detail: "auto"`. pig used to send
     * "(see attached image)" there and the images after it as a user turn of their own, which put
     * a user message where the model's next turn belongs and presented the screenshot as
     * something a person said. This test asserted that old shape; it now asserts upstream's.
     */
    public function testToolResultImagesGoInsideTheFunctionCallOutput(): void
    {
        $context = new Context([
            new UserMessage('hi'),
            $this->assistant([new ToolCall('call_1|fc_1', 'shot', [])]),
            new ToolResultMessage('call_1|fc_1', 'shot', [
                new TextContent('took it'),
                new ImageContent('AAA', 'image/png'),
                new ImageContent('BBB', 'image/jpeg'),
            ]),
        ]);

        $this->send($context, $this->model(images: true));

        $input = $this->server->receivedJson()['input'];
        $last = $input[count($input) - 1];

        // The output is the last item: no user turn follows it.
        $this->assertSame('function_call_output', $last['type']);
        $this->assertSame('call_1', $last['call_id']);
        $this->assertSame([
            ['type' => 'input_text', 'text' => 'took it'],
            ['type' => 'input_image', 'detail' => 'auto', 'image_url' => 'data:image/png;base64,AAA'],
            ['type' => 'input_image', 'detail' => 'auto', 'image_url' => 'data:image/jpeg;base64,BBB'],
        ], $last['output']);
    }

    /**
     * The other two cases: an image-only result leaves out the `input_text` altogether (no
     * placeholder inside the list), and a model that takes no images still gets a plain string —
     * `TransformMessages` has already replaced the image with its note by then, as upstream's
     * `transformMessages()` does, so no list is built for a model that could not read it.
     */
    public function testAnImageOnlyResultHasNoTextPartAndATextOnlyModelStillGetsAString(): void
    {
        $context = new Context([
            new UserMessage('hi'),
            $this->assistant([new ToolCall('call_1|fc_1', 'shot', [])]),
            new ToolResultMessage('call_1|fc_1', 'shot', [new ImageContent('AAA', 'image/png')]),
        ]);

        $this->send($context, $this->model(images: true));
        $input = $this->server->receivedJson()['input'];
        $this->assertSame(
            [['type' => 'input_image', 'detail' => 'auto', 'image_url' => 'data:image/png;base64,AAA']],
            $input[count($input) - 1]['output'],
        );

        $this->server = new CannedServer();
        $this->send($context, $this->model(images: false));
        $input = $this->server->receivedJson()['input'];
        $this->assertSame('(tool image omitted: model does not support images)', $input[count($input) - 1]['output']);
    }

    /**
     * Upstream's `convertToolResultOutput()`: "(no tool output)" when there is neither text nor an
     * image, "(see attached image)" only when there is an image. pig said the image one for both.
     */
    public function testAToolResultWithNothingInItSaysSoRatherThanPointingAtAnImage(): void
    {
        foreach (['no blocks' => [], 'empty text' => [new TextContent('')]] as $case => $content) {
            $this->server = new CannedServer();
            $context = new Context([
                new UserMessage('hi'),
                $this->assistant([new ToolCall('call_1|fc_1', 'bash', [])]),
                new ToolResultMessage('call_1|fc_1', 'bash', $content),
            ]);

            $this->send($context, $this->model(images: true));
            $input = $this->server->receivedJson()['input'];

            $this->assertSame('(no tool output)', $input[count($input) - 1]['output'], $case);
        }
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

    /**
     * Upstream's `convertResponsesTools()` with `supportsStrictMode` from the model's compat:
     * `strict` is the tool's strict answer or `false`, and the field is only there where strict
     * mode is. pig sent `strict: null` on every tool to every endpoint — never the strict schema.
     */
    public function testAStrictToolGoesOutStrictWhereTheModelTakesStrictTools(): void
    {
        $plain = new Tool('ls', 'List', ['type' => 'object', 'properties' => ['path' => ['type' => 'string']]]);
        $context = new Context([new UserMessage('hi')], null, [new Tool('read', 'Read a file', [
            'type' => 'object',
            'properties' => ['path' => ['type' => 'string'], 'limit' => ['type' => 'number']],
            'required' => ['path'],
        ], ['type' => 'json_schema', 'strict' => 'prefer']), $plain]);

        $this->send($context, $this->model(compat: new OpenAiCompat(strictMode: true)));
        $tools = $this->server->receivedJson()['tools'];

        $this->assertTrue($tools[0]['strict']);
        $this->assertSame([
            'type' => 'object',
            'properties' => ['path' => ['type' => 'string'], 'limit' => ['anyOf' => [['type' => 'number'], ['type' => 'null']]]],
            'required' => ['path', 'limit'],
            'additionalProperties' => false,
        ], $tools[0]['parameters']);
        $this->assertFalse($tools[1]['strict']);

        // No compat — upstream's default, `supportsStrictMode: false` — and no field at all.
        $this->server = new CannedServer();
        $this->send($context, $this->model());
        $tools = $this->server->receivedJson()['tools'];

        $this->assertArrayNotHasKey('strict', $tools[0]);
        $this->assertSame(['path'], $tools[0]['parameters']['required']);
    }

    public function testOnlyAnImageInAUserOrToolResultMessageAsksCopilotForVision(): void
    {
        // Upstream's `hasCopilotVisionInput()` looks at user and toolResult messages and nothing
        // else. pig looked at every message's content, so an image inside an assistant turn — which
        // a session written elsewhere can hold — sent `Copilot-Vision-Request` upstream never sends.
        $this->sendAsCopilot(new Context([
            new UserMessage('hello'),
            $this->assistant([new TextContent('here'), new ImageContent('AAA', 'image/png')]),
            new UserMessage('thanks'),
        ]));

        $this->assertStringNotContainsString('copilot-vision-request', strtolower($this->server->receivedHead()));

        // An image a tool returned is one the model is being shown, and counts.
        $this->server = new CannedServer();
        $this->sendAsCopilot(new Context([
            new UserMessage('look'),
            $this->assistant([new ToolCall('c1|fc_1', 'read', ['path' => 'a.png'])]),
            new ToolResultMessage('c1|fc_1', 'read', [new ImageContent('AAA', 'image/png')], false),
        ]));

        $this->assertStringContainsString('copilot-vision-request: true', strtolower($this->server->receivedHead()));
    }

    // ---- grammar (custom) tools --------------------------------------------------------------

    public function testAGrammarToolGoesOutAsAnOpenAiCustomToolWhereTheModelTakesThem(): void
    {
        // Upstream's `convertResponsesTools()` grammar arm: `{type: "custom", name, description,
        // format: {type: "grammar", syntax, definition}}`, Lark preferred over regex. pig sent every
        // tool as a function tool, so a grammar tool's constraint was never sent at all.
        $this->send(new Context([new UserMessage('hi')], tools: [self::grammarTool()]), $this->model(compat: new OpenAiCompat(grammarTools: true)));

        $this->assertSame([
            'type' => 'custom',
            'name' => 'apply_patch',
            'description' => 'Apply a patch',
            'format' => ['type' => 'grammar', 'syntax' => 'lark', 'definition' => 'start: "x"'],
        ], $this->server->receivedJson()['tools'][0]);
    }

    public function testAGrammarToolFallsBackToAFunctionToolWhereTheModelTakesNone(): void
    {
        // `supportsOpenAIGrammarTools` defaults to false: "grammar-constrained tools fall back to
        // normal function tools".
        $this->send(new Context([new UserMessage('hi')], tools: [self::grammarTool()]));

        $tool = $this->server->receivedJson()['tools'][0];
        $this->assertSame('function', $tool['type']);
        $this->assertSame(['patch'], $tool['parameters']['required']);
    }

    public function testACustomToolCallStreamsAsJsonArgumentsInTheToolsInputProperty(): void
    {
        // Upstream's `custom_tool_call` arm of `processResponsesStream()`: the raw input becomes the
        // arguments `{<property>: <input>}`, and the deltas pig streams are that object's JSON. pig
        // had no arm for the item, so a grammar tool's call vanished from the turn.
        $url = $this->serve([
            ['type' => 'response.output_item.added', 'output_index' => 0, 'item' => ['type' => 'custom_tool_call', 'id' => 'ctc_1', 'call_id' => 'call_1', 'name' => 'apply_patch', 'input' => '']],
            ['type' => 'response.custom_tool_call_input.delta', 'output_index' => 0, 'delta' => '*** Begin'],
            ['type' => 'response.custom_tool_call_input.delta', 'output_index' => 0, 'delta' => " \"x\"\n"],
            ['type' => 'response.custom_tool_call_input.done', 'output_index' => 0, 'input' => "*** Begin \"x\"\n"],
            ['type' => 'response.output_item.done', 'output_index' => 0, 'item' => ['type' => 'custom_tool_call', 'id' => 'ctc_1', 'call_id' => 'call_1', 'name' => 'apply_patch', 'input' => "*** Begin \"x\"\n"]],
            ['type' => 'response.completed', 'response' => ['status' => 'completed']],
        ]);

        [$deltas, $message] = Async::run(function () use ($url): array {
            $stream = (new OpenAiResponses())->stream(
                $this->model($url, compat: new OpenAiCompat(grammarTools: true)),
                new Context([new UserMessage('patch it')], tools: [self::grammarTool()]),
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

        $this->assertSame(['{"patch":"*** Begin', ' \\"x\\"\\n', '"}'], $deltas);
        $this->assertSame('{"patch":"*** Begin \\"x\\"\\n"}', implode('', $deltas), 'the deltas are one JSON object');
        $this->assertSame(StopReason::ToolUse, $message->stopReason);
        $call = $message->toolCalls()[0];
        $this->assertSame('call_1|ctc_1', $call->id);
        $this->assertSame('apply_patch', $call->name);
        $this->assertSame(['patch' => "*** Begin \"x\"\n"], $call->arguments);
    }

    public function testACustomToolCallAndItsResultGoBackAsCustomItems(): void
    {
        // Upstream's replay: a grammar tool's call is a `custom_tool_call` carrying the raw input and
        // its own `ctc_` item id, and its result a `custom_tool_call_output`. An `fc_` id on such a
        // call is dropped, as a `ctc_` one is on a function call — "a call can switch between the
        // two types when grammar tool support differs".
        $this->send(
            new Context([
                new UserMessage('patch it'),
                $this->assistant([
                    new ToolCall('call_1|ctc_1', 'apply_patch', ['patch' => 'P']),
                    new ToolCall('call_2|fc_2', 'apply_patch', ['patch' => 'Q']),
                    new ToolCall('call_3|ctc_3', 'read', ['path' => 'a']),
                ], StopReason::ToolUse),
                new ToolResultMessage('call_1|ctc_1', 'apply_patch', [new TextContent('done')], false),
                new ToolResultMessage('call_3|ctc_3', 'read', [new TextContent('body')], false),
            ], tools: [self::grammarTool(), new Tool('read', 'Read', ['type' => 'object', 'properties' => ['path' => ['type' => 'string']], 'required' => ['path']])]),
            $this->model(id: 'test-model', compat: new OpenAiCompat(grammarTools: true)),
        );

        $input = $this->server->receivedJson()['input'];

        $this->assertSame(['type' => 'custom_tool_call', 'id' => 'ctc_1', 'call_id' => 'call_1', 'name' => 'apply_patch', 'input' => 'P'], $input[1]);
        $this->assertSame(['type' => 'custom_tool_call', 'call_id' => 'call_2', 'name' => 'apply_patch', 'input' => 'Q'], $input[2]);
        $this->assertSame('function_call', $input[3]['type']);
        $this->assertArrayNotHasKey('id', $input[3], 'a ctc_ id does not fit a function call');
        $this->assertSame(['type' => 'custom_tool_call_output', 'call_id' => 'call_1', 'output' => 'done'], $input[4]);
        $this->assertSame('function_call_output', $input[5]['type']);
    }

    public function testCopilotAndOpenAiGptFiveModelsTakeGrammarTools(): void
    {
        // Upstream's `applyOpenAIGrammarToolCompatMetadata()`: a Responses model of `openai` or
        // `github-copilot` whose id is `gpt-<n>` with n >= 5 — and nothing older.
        $this->assertTrue(\Pig\Ai\Models::find('openai', 'gpt-5')?->compat?->grammarTools);
        $this->assertTrue(\Pig\Ai\Models::find('github-copilot', 'gpt-5.4')?->compat?->grammarTools);
        $this->assertNull(\Pig\Ai\Models::find('openai', 'gpt-4.1')?->compat?->grammarTools);
    }

    private static function grammarTool(): Tool
    {
        return new Tool(
            'apply_patch',
            'Apply a patch',
            ['type' => 'object', 'properties' => ['patch' => ['type' => 'string']], 'required' => ['patch']],
            ['type' => 'grammar', 'variants' => ['openai_lark' => 'start: "x"', 'openai_regex' => '.*']],
        );
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

    /**
     * An `event: error` reads the way upstream reads it, which is through the `openai` SDK: its
     * `Stream` throws an `APIError` made of `data.error ?? data` before pi's own code sees the event,
     * and a status-less `APIError` is its `message` and nothing else.
     */
    public function testAnErrorEventIsTheSdksMessageWithoutTheCode(): void
    {
        // The live API's nested shape, which once came back as a bare `unknown error` because the
        // message was not where pig looked. `Overflow`'s table matches the provider's own words, so
        // the sentence has to survive whole; the code does not, because upstream does not keep it
        // either — this used to assert pig's own `Error context_length_exceeded:` prefix.
        $url = $this->serve([['type' => 'error', 'error' => [
            'message' => 'Requested 300000 tokens, exceeds the context window',
            'code' => 'context_length_exceeded',
        ]]]);

        [, $message] = $this->collect($url, new Context([new UserMessage('hi')]));

        $this->assertSame(StopReason::Error, $message->stopReason);
        $this->assertSame('Requested 300000 tokens, exceeds the context window', $message->errorMessage);

        // The documented flat shape under `event: error` is the same: `data.error` is absent, so
        // the SDK makes its error of the whole event and reads its `message`.
        $url = $this->serve([['type' => 'error', 'code' => 'server_error', 'message' => 'flat and named']]);
        [, $message] = $this->collect($url, new Context([new UserMessage('hi')]));
        $this->assertSame('flat and named', $message->errorMessage);
    }

    /** With the message nowhere at all, the SDK's message is the error object's JSON. */
    public function testAnErrorWithNoMessageAnywhereCarriesWhatArrived(): void
    {
        // `APIError.makeMessage()`: no `message` on the error, so `JSON.stringify(error)` — which is
        // still better than a sentence that describes nothing, and is what upstream shows. This
        // used to be pig's own "an error with no message in it: <json>".
        $url = $this->serve([['type' => 'error', 'detail' => 'a shape nobody documented']]);

        [, $message] = $this->collect($url, new Context([new UserMessage('hi')]));

        $this->assertSame('{"type":"error","detail":"a shape nobody documented"}', $message->errorMessage);
    }

    /** Upstream's own `error` arm: `Error Code ${event.code}: ${event.message}`, for an unnamed event. */
    public function testAFlatErrorWithNoEventNameIsErrorCodeAndMessage(): void
    {
        // Only a server that sends no `event: error` line — a compatible endpoint or a proxy —
        // reaches processResponsesStream's arm; the template never comes out empty, so its
        // `|| "Unknown error"` never applies, and a missing field reads `undefined`.
        $url = $this->serve([['type' => 'error', 'code' => 'rate_limit_exceeded', 'message' => 'Slow down']], named: false);
        [, $message] = $this->collect($url, new Context([new UserMessage('hi')]));
        $this->assertSame('Error Code rate_limit_exceeded: Slow down', $message->errorMessage);

        $url = $this->serve([['type' => 'error', 'message' => 'no code at all']], named: false);
        [, $message] = $this->collect($url, new Context([new UserMessage('hi')]));
        $this->assertSame('Error Code undefined: no code at all', $message->errorMessage);
    }

    /** Upstream's `response.failed` text, each of its three forms. */
    public function testAFailedResponseSaysCodeAndMessageAsUpstreamWritesThem(): void
    {
        // `${error.code || "unknown"}: ${error.message || "no message"}` — pig used to write
        // "The response failed: <message>", dropping the code a person needs to look the failure up.
        $url = $this->serve([['type' => 'response.failed', 'response' => [
            'status' => 'failed', 'error' => ['code' => 'server_error', 'message' => 'The model crashed'],
        ]]]);
        [, $message] = $this->collect($url, new Context([new UserMessage('hi')]));
        $this->assertSame('server_error: The model crashed', $message->errorMessage);
        $this->assertSame('failed', $message->rawStopReason);

        $url = $this->serve([['type' => 'response.failed', 'response' => ['status' => 'failed', 'error' => ['code' => '']]]]);
        [, $message] = $this->collect($url, new Context([new UserMessage('hi')]));
        $this->assertSame('unknown: no message', $message->errorMessage);

        $url = $this->serve([['type' => 'response.failed', 'response' => ['status' => 'failed', 'incomplete_details' => ['reason' => 'max_output_tokens']]]]);
        [, $message] = $this->collect($url, new Context([new UserMessage('hi')]));
        $this->assertSame('incomplete: max_output_tokens', $message->errorMessage);

        $url = $this->serve([['type' => 'response.failed', 'response' => ['status' => 'failed']]]);
        [, $message] = $this->collect($url, new Context([new UserMessage('hi')]));
        $this->assertSame('Unknown error (no error details in response)', $message->errorMessage);
    }

    // ---- how a stream ends ----------------------------------------------------------------

    public function testABodyThatEndsWithNoTerminalEventIsAnErrorNotAnAnswer(): void
    {
        // Upstream's `processResponsesStream()`: "OpenAI Responses stream ended before a terminal
        // response event". pig started the turn at `stop`, so a connection cut after the text had
        // streamed — no `response.completed` — handed back half an answer as a finished one, with
        // no usage, and the agent went on as if the model had said all it meant to.
        $url = $this->serve([
            ['type' => 'response.output_item.added', 'item' => ['type' => 'message', 'id' => 'msg_1']],
            ['type' => 'response.output_text.delta', 'delta' => 'The answer is'],
        ]);

        [$types, $message] = $this->collect($url, new Context([new UserMessage('hi')]));

        $this->assertSame('ErrorEvent', $types[array_key_last($types)]);
        $this->assertSame(StopReason::Error, $message->stopReason);
        $this->assertSame('OpenAI Responses stream ended before a terminal response event', $message->errorMessage);
    }

    public function testDataThatIsNotJsonFailsUnlessTheDoneSentinelEndedTheStream(): void
    {
        // Everything now goes through the SDK's rules, and the SDK stops at `data: [DONE]` before it
        // parses anything — so a compatible server that sends one after `response.completed` ends
        // cleanly instead of with "malformed server-sent event JSON".
        $body = 'data: ' . json_encode(['type' => 'response.completed', 'response' => ['status' => 'completed']]) . "\n\ndata: [DONE]\n\ndata: not json\n\n";
        $url = $this->server->start(["HTTP/1.1 200 OK\r\nContent-Type: text/event-stream\r\nContent-Length: " . strlen($body) . "\r\n\r\n" . $body]);

        [, $message] = $this->collect($url, new Context([new UserMessage('hi')]));

        $this->assertSame(StopReason::Stop, $message->stopReason);
        $this->assertNull($message->errorMessage);

        // Without the sentinel, data that is not JSON is the SDK's `SyntaxError`, not skipped: pig
        // used to skip it and carry on.
        $this->server = new CannedServer();
        $body = "data: not json\n\n" . 'data: ' . json_encode(['type' => 'response.completed', 'response' => ['status' => 'completed']]) . "\n\n";
        $url = $this->server->start(["HTTP/1.1 200 OK\r\nContent-Type: text/event-stream\r\nContent-Length: " . strlen($body) . "\r\n\r\n" . $body]);

        [, $message] = $this->collect($url, new Context([new UserMessage('hi')]));

        $this->assertSame('Error reading response: malformed server-sent event JSON.', $message->errorMessage);
    }

    public function testTheFinishedArgumentsReplaceTheDeltasAndSendWhatTheyMissed(): void
    {
        // Upstream's `response.function_call_arguments.done` arm: the whole arguments replace what
        // the deltas built, and the tail the deltas never sent goes out as one more delta — so a
        // listener that rebuilds the call from deltas ends with the same JSON as the call itself.
        $url = $this->serve([
            ['type' => 'response.output_item.added', 'item' => ['type' => 'function_call', 'id' => 'fc_1', 'call_id' => 'call_1', 'name' => 'read']],
            ['type' => 'response.function_call_arguments.delta', 'delta' => '{"path":'],
            ['type' => 'response.function_call_arguments.done', 'arguments' => '{"path":"a.txt"}'],
            ['type' => 'response.output_item.done', 'item' => ['type' => 'function_call', 'id' => 'fc_1', 'call_id' => 'call_1', 'name' => 'read']],
            ['type' => 'response.completed', 'response' => ['status' => 'completed']],
        ]);

        [$deltas, $message] = Async::run(function () use ($url): array {
            $stream = (new OpenAiResponses())->stream($this->model(baseUrl: $url), new Context([new UserMessage('hi')]), new OpenAiOptions(apiKey: 'test-key'));
            $deltas = [];

            foreach ($stream as $event) {
                if ($event instanceof \Pig\Ai\ToolCallDeltaEvent) {
                    $deltas[] = $event->delta;
                }
            }

            return [$deltas, $stream->result()->await()];
        });

        $this->assertSame(['{"path":', '"a.txt"}'], $deltas);
        $this->assertSame(['path' => 'a.txt'], $message->content[0]->arguments ?? null);
        $this->assertSame(StopReason::ToolUse, $message->stopReason);
    }

    public function testEncryptedReasoningOnlyTheTerminalResponseCarriesIsBackfilled(): void
    {
        // Upstream's `backfillReasoningSignatures()`: "Azure OpenAI can omit
        // reasoning.encrypted_content from response.output_item.done and provide it only in
        // response.completed.response.output." Without it the stored item had no encrypted
        // content, so the next turn replayed reasoning the model could not resume from.
        $url = $this->serve([
            ['type' => 'response.output_item.added', 'item' => ['type' => 'reasoning', 'id' => 'rs_1']],
            ['type' => 'response.output_item.done', 'item' => ['type' => 'reasoning', 'id' => 'rs_1', 'summary' => []]],
            ['type' => 'response.completed', 'response' => ['status' => 'completed', 'output' => [
                ['type' => 'reasoning', 'id' => 'rs_1', 'encrypted_content' => 'ENC'],
                ['type' => 'reasoning', 'id' => 'rs_unknown', 'encrypted_content' => 'NOT-OURS'],
            ]]],
        ]);

        [, $message] = $this->collect($url, new Context([new UserMessage('hi')]));

        $block = $message->content[0];
        $this->assertInstanceOf(ThinkingContent::class, $block);
        $this->assertSame(
            ['type' => 'reasoning', 'id' => 'rs_1', 'summary' => [], 'encrypted_content' => 'ENC'],
            json_decode((string) $block->thinkingSignature, true),
        );

        // One the item already carried is left as it came.
        $url = $this->serve([
            ['type' => 'response.output_item.added', 'item' => ['type' => 'reasoning', 'id' => 'rs_1']],
            ['type' => 'response.output_item.done', 'item' => ['type' => 'reasoning', 'id' => 'rs_1', 'encrypted_content' => 'FIRST']],
            ['type' => 'response.completed', 'response' => ['status' => 'completed', 'output' => [
                ['type' => 'reasoning', 'id' => 'rs_1', 'encrypted_content' => 'SECOND'],
            ]]],
        ]);
        [, $message] = $this->collect($url, new Context([new UserMessage('hi')]));
        $this->assertSame('FIRST', json_decode((string) $message->content[0]->thinkingSignature, true)['encrypted_content'] ?? null);
    }

    public function testAReasoningSummaryIsAskedForAsTheCallerSaysAndDefaultsToAuto(): void
    {
        // Upstream's `reasoningSummary` option: sent as `reasoning.summary`, `auto` when not given,
        // and given alone it asks for `medium` effort. pig always sent `auto`.
        [, $body] = $this->capture($this->model(reasoning: true), new Context([new UserMessage('hi')]), new OpenAiOptions(apiKey: 'test-key', reasoning: ReasoningEffort::High));
        $this->assertSame(['effort' => 'high', 'summary' => 'auto'], $body['reasoning']);

        [, $body] = $this->capture($this->model(reasoning: true), new Context([new UserMessage('hi')]), new OpenAiOptions(apiKey: 'test-key', reasoning: ReasoningEffort::Low, reasoningSummary: 'detailed'));
        $this->assertSame(['effort' => 'low', 'summary' => 'detailed'], $body['reasoning']);

        [, $body] = $this->capture($this->model(reasoning: true), new Context([new UserMessage('hi')]), new OpenAiOptions(apiKey: 'test-key', reasoningSummary: 'concise'));
        $this->assertSame(['effort' => 'medium', 'summary' => 'concise'], $body['reasoning']);
        $this->assertSame(['reasoning.encrypted_content'], $body['include']);
    }

    public function testTheSystemPromptIsASystemTurnWhereTheCompatSaysThereIsNoDeveloperRole(): void
    {
        // Upstream's `instructionRole`: `developer` for a reasoning model unless its compat says
        // `supportsDeveloperRole: false`. pig read only `reasoning`, so a compatible endpoint that
        // knows no `developer` role got one anyway.
        $context = new Context([new UserMessage('hi')], 'Be brief.');

        [, $body] = $this->capture($this->model(reasoning: true), $context);
        $this->assertSame('developer', $body['input'][0]['role']);

        [, $body] = $this->capture($this->model(reasoning: true, compat: new OpenAiCompat(developerRole: false)), $context);
        $this->assertSame('system', $body['input'][0]['role']);
    }

    public function testASignInWithChatGptUsageLimitPointsAtTheUsagePage(): void
    {
        // Upstream: "Sign in with ChatGPT shares the subscription's usage limit with other apps",
        // so an error carrying `subscription_sharing_usage_limit_exceeded` gets
        // "\nCheck your ChatGPT usage: https://chatgpt.com/settings/usage" — whether it came as the
        // refused request's body or in the stream.
        $body = '{"error":{"message":"You have hit your usage limit.","code":"subscription_sharing_usage_limit_exceeded"}}';
        $url = $this->server->start([
            "HTTP/1.1 429 Too Many Requests\r\nContent-Type: application/json\r\nContent-Length: " . strlen($body) . "\r\n\r\n" . $body,
        ]);
        [, $message] = $this->collect($url, new Context([new UserMessage('hi')]));
        $this->assertStringContainsString('You have hit your usage limit.', (string) $message->errorMessage);
        $this->assertStringEndsWith("\nCheck your ChatGPT usage: https://chatgpt.com/settings/usage", (string) $message->errorMessage);

        $this->server = new CannedServer();
        $url = $this->serve([['type' => 'response.failed', 'response' => ['status' => 'failed', 'error' => [
            'code' => 'subscription_sharing_usage_limit_exceeded', 'message' => 'Limit reached',
        ]]]]);
        [, $message] = $this->collect($url, new Context([new UserMessage('hi')]));
        $this->assertSame(
            "subscription_sharing_usage_limit_exceeded: Limit reached\nCheck your ChatGPT usage: https://chatgpt.com/settings/usage",
            $message->errorMessage,
        );

        // Any other error is left alone.
        $this->server = new CannedServer();
        $url = $this->serve([['type' => 'response.failed', 'response' => ['status' => 'failed', 'error' => ['code' => 'server_error', 'message' => 'x']]]]);
        [, $message] = $this->collect($url, new Context([new UserMessage('hi')]));
        $this->assertSame('server_error: x', $message->errorMessage);
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
                // The compat too — the same hazard as `OpenAiCompletionsTest::send()`'s field-by-field
                // copy: a field left off here makes the feature look broken in whichever test needs it.
                $this->model($url, $model->reasoning, $model->acceptsImages(), $model->id, $model->compat),
                $context,
                new OpenAiOptions(temperature: $temperature, apiKey: 'test-key', reasoning: $reasoning),
            );

            foreach ($stream as $ignored) {
                // Drain it; what is under test is what went out.
            }

            $stream->result()->await();
        });
    }

    /** @param array<string, string|null> $levels */
    private function model(
        string $baseUrl = 'http://127.0.0.1:1',
        bool $reasoning = false,
        bool $images = true,
        string $id = 'test-model',
        ?OpenAiCompat $compat = null,
        array $levels = [],
        string $provider = 'openai',
    ): Model {
        return new Model(
            $id,
            'Test Model',
            Api::OpenAiResponses,
            $provider,
            rtrim($baseUrl, '/'),
            200_000,
            64_000,
            $reasoning,
            $images ? ['text', 'image'] : ['text'],
            new Pricing(input: 1.0, output: 2.0),
            compat: $compat,
            thinkingLevelMap: $levels,
        );
    }

    /**
     * One request with whatever model and options a test needs, every field of the model kept and
     * only its base URL replaced with the canned server's.
     *
     * @return array{0: string, 1: array<string, mixed>} the head and the decoded body that went out
     */
    private function capture(Model $model, Context $context, ?OpenAiOptions $options = null): array
    {
        $this->server = new CannedServer();
        $url = $this->serve([['type' => 'response.completed', 'response' => ['status' => 'completed']]]);
        $model = new Model(
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
            $model->headers,
            $model->compat,
            $model->thinkingLevelMap,
        );

        Async::run(function () use ($model, $context, $options): void {
            $stream = (new OpenAiResponses())->stream($model, $context, $options ?? new OpenAiOptions(apiKey: 'test-key'));

            foreach ($stream as $ignored) {
            }
        });

        return [$this->server->receivedHead(), $this->server->receivedJson()];
    }

    /** @param list<array<string, mixed>> $events */
    private function serve(array $events, bool $named = true): string
    {
        $pieces = ["HTTP/1.1 200 OK\r\nContent-Type: text/event-stream\r\nTransfer-Encoding: chunked\r\n\r\n"];

        foreach ($events as $event) {
            // `$named`: the `event:` line OpenAI writes, which a compatible server may leave out.
            $body = ($named ? "event: {$event['type']}\n" : '') . 'data: ' . json_encode($event) . "\n\n";
            $pieces[] = sprintf("%x\r\n%s\r\n", strlen($body), $body);
        }

        $pieces[] = "0\r\n\r\n";

        return $this->server->start($pieces);
    }
}
