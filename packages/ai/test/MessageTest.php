<?php

declare(strict_types=1);

namespace Pig\Ai\Test;

use PHPUnit\Framework\TestCase;
use Pig\Ai\Api;
use Pig\Ai\AssistantMessage;
use Pig\Ai\AssistantMessageDiagnostic;
use Pig\Ai\Cost;
use Pig\Ai\DiagnosticErrorInfo;
use Pig\Ai\ImageContent;
use Pig\Ai\Model;
use Pig\Ai\Providers\TransformMessages;
use Pig\Ai\ReasoningEffort;
use Pig\Ai\StopReason;
use Pig\Ai\TextContent;
use Pig\Ai\ThinkingContent;
use Pig\Ai\ToolCall;
use Pig\Ai\ToolResultMessage;
use Pig\Ai\Usage;
use Pig\Ai\UserMessage;
use Pig\Ai\Utils\MessageJson;

final class MessageTest extends TestCase
{
    public function testAUserMessageWrapsABareStringInATextBlock(): void
    {
        $message = new UserMessage('what changed in this file?');

        $this->assertCount(1, $message->content);
        $this->assertInstanceOf(TextContent::class, $message->content[0]);
        $this->assertSame('what changed in this file?', $message->content[0]->text);
    }

    public function testAUserMessageKeepsAContentListAsGiven(): void
    {
        $blocks = [new TextContent('look at this'), new ImageContent('AAAA', 'image/png')];
        $message = new UserMessage($blocks);

        $this->assertSame($blocks, $message->content);
    }

    public function testMessagesStampThemselvesUnlessToldOtherwise(): void
    {
        $before = (int) (microtime(true) * 1000);
        $stamped = new UserMessage('now');
        $explicit = new UserMessage('then', 1_700_000_000_000);

        $this->assertGreaterThanOrEqual($before, $stamped->timestamp);
        $this->assertSame(1_700_000_000_000, $explicit->timestamp);
    }

    public function testToolCallsPicksOutOnlyToolCallsAndKeepsOrder(): void
    {
        $message = $this->assistant([
            new ThinkingContent('the user wants the file read'),
            new ToolCall('call_1', 'read', ['path' => 'a.php']),
            new TextContent('reading now'),
            new ToolCall('call_2', 'read', ['path' => 'b.php']),
        ]);

        $calls = $message->toolCalls();

        $this->assertCount(2, $calls);
        $this->assertSame('call_1', $calls[0]->id);
        $this->assertSame('call_2', $calls[1]->id);
    }

    public function testToolCallsIsEmptyWhenThereAreNone(): void
    {
        $this->assertSame([], $this->assistant([new TextContent('just talking')])->toolCalls());
    }

    public function testOnlyErrorAndAbortedCountAsFailures(): void
    {
        $this->assertTrue(StopReason::Error->isFailure());
        $this->assertTrue(StopReason::Aborted->isFailure());
        $this->assertFalse(StopReason::Stop->isFailure());
        $this->assertFalse(StopReason::ToolUse->isFailure());
        $this->assertFalse(StopReason::Length->isFailure());
    }

    public function testTheRawStopReasonSurvivesTheTripToJsonAndBack(): void
    {
        $message = new AssistantMessage(
            [new TextContent('')],
            Api::GoogleGenerativeAi,
            'google',
            'gemini-2.5-pro',
            new Usage(),
            StopReason::Error,
            'Provider stopped with: MALFORMED_FUNCTION_CALL',
            1_700_000_000_000,
            'MALFORMED_FUNCTION_CALL',
        );

        $encoded = MessageJson::encode($message);

        $this->assertSame('MALFORMED_FUNCTION_CALL', $encoded['rawStopReason'] ?? null);
        $this->assertSame('MALFORMED_FUNCTION_CALL', MessageJson::decode($encoded)->rawStopReason);
    }

    public function testAMessageWithNoRawStopReasonIsWrittenWithoutTheKey(): void
    {
        // Upstream's field is optional and `JSON.stringify` drops an undefined one, so the key is
        // absent rather than null — the same shape a session file from before the field had.
        $encoded = MessageJson::encode($this->assistant([new TextContent('hi')]));

        $this->assertArrayNotHasKey('rawStopReason', $encoded);
        $this->assertNull(MessageJson::decode($encoded)->rawStopReason);
    }

    public function testEveryNewOptionalFieldSurvivesTheTripToJsonAndBack(): void
    {
        // The fields upstream added after the version pig was ported from — `responseId`,
        // `responseModel`, `endTurn`, `diagnostics`, the two usage splits and a redacted thinking
        // block. Each is written by hand in `MessageJson`, so each needs its own trip: a field the
        // encoder forgets is a field every resumed session silently loses.
        $message = new AssistantMessage(
            [new ThinkingContent('[Reasoning redacted]', 'OPAQUE', true), new TextContent('hi')],
            Api::AnthropicMessages,
            'anthropic',
            'claude-sonnet-4-5',
            new Usage(10, 20, 30, 40, 100, new Cost(), reasoning: 5, cacheWrite1h: 15),
            StopReason::Stop,
            null,
            1_700_000_000_000,
            'end_turn',
            'msg_01',
            'claude-sonnet-4-5-20250929',
            false,
            [
                new AssistantMessageDiagnostic(
                    'anthropic_input_transformations',
                    1_700_000_000_001,
                    details: ['transformations' => [['type' => 'drop', 'path' => 'messages.0']]],
                ),
                new AssistantMessageDiagnostic(
                    'recovered',
                    1_700_000_000_002,
                    new DiagnosticErrorInfo('socket closed', 'Error', null, 'ECONNRESET'),
                ),
            ],
        );

        // Through a real JSON string, as a session file is, not just the array.
        $encoded = json_decode((string) json_encode(MessageJson::encode($message)), true);
        $decoded = MessageJson::decode($encoded);

        $this->assertSame('msg_01', $decoded->responseId);
        $this->assertSame('claude-sonnet-4-5-20250929', $decoded->responseModel);
        // `false` is a value, not an absence — it has to come back as false and not as null.
        $this->assertFalse($decoded->endTurn);
        $this->assertSame(5, $decoded->usage->reasoning);
        $this->assertSame(15, $decoded->usage->cacheWrite1h);

        $thinking = $decoded->content[0];
        $this->assertInstanceOf(ThinkingContent::class, $thinking);
        $this->assertTrue($thinking->redacted);
        $this->assertSame('OPAQUE', $thinking->thinkingSignature);

        $this->assertCount(2, $decoded->diagnostics ?? []);
        $this->assertSame('anthropic_input_transformations', $decoded->diagnostics[0]->type);
        $this->assertSame(1_700_000_000_001, $decoded->diagnostics[0]->timestamp);
        $this->assertNull($decoded->diagnostics[0]->error);
        $this->assertSame(['transformations' => [['type' => 'drop', 'path' => 'messages.0']]], $decoded->diagnostics[0]->details);
        $this->assertSame('socket closed', $decoded->diagnostics[1]->error?->message);
        $this->assertSame('ECONNRESET', $decoded->diagnostics[1]->error?->code);
        $this->assertNull($decoded->diagnostics[1]->details);

        // And the absent parts of a diagnostic stay absent, as upstream's undefined ones do.
        $this->assertArrayNotHasKey('error', $encoded['diagnostics'][0]);
        $this->assertArrayNotHasKey('details', $encoded['diagnostics'][1]);
        $this->assertArrayNotHasKey('stack', $encoded['diagnostics'][1]['error']);
    }

    public function testAMessageWithNoneOfTheNewFieldsIsWrittenWithoutTheirKeys(): void
    {
        // Upstream leaves an undefined field out rather than writing a null, so a message that has
        // none of these is written exactly as one from before they existed — and comes back with
        // all of them unknown rather than zero or false.
        $encoded = MessageJson::encode($this->assistant([new ThinkingContent('hm', 'SIG'), new TextContent('hi')]));

        foreach (['responseId', 'responseModel', 'endTurn', 'diagnostics'] as $key) {
            $this->assertArrayNotHasKey($key, $encoded);
        }

        $this->assertArrayNotHasKey('reasoning', $encoded['usage']);
        $this->assertArrayNotHasKey('cacheWrite1h', $encoded['usage']);
        $this->assertArrayNotHasKey('redacted', $encoded['content'][0]);

        $decoded = MessageJson::decode($encoded);

        $this->assertNull($decoded->responseId);
        $this->assertNull($decoded->responseModel);
        $this->assertNull($decoded->endTurn);
        $this->assertNull($decoded->diagnostics);
        $this->assertNull($decoded->usage->reasoning);
        $this->assertNull($decoded->usage->cacheWrite1h);
        $this->assertInstanceOf(ThinkingContent::class, $decoded->content[0]);
        $this->assertNull($decoded->content[0]->redacted);
    }

    public function testAZeroReasoningSplitIsWrittenBecauseZeroIsWhatTheProviderSaid(): void
    {
        // A provider that reports the split and found no reasoning says 0; one that reports no
        // split says nothing. Upstream keeps the difference, so the 0 is a value and is written.
        $encoded = MessageJson::encodeUsage(new Usage(1, 2, reasoning: 0, cacheWrite1h: 0));

        $this->assertSame(0, $encoded['reasoning'] ?? null);
        $this->assertSame(0, $encoded['cacheWrite1h'] ?? null);
    }

    public function testRewritingAMessageForAnotherProviderKeepsItsOptionalFields(): void
    {
        // `TransformMessages` builds a new message when it rewrites content for another provider,
        // and every field has to be copied by hand — `rawStopReason` was nearly lost there once.
        $message = new AssistantMessage(
            [new ThinkingContent('[Reasoning redacted]', 'OPAQUE', true), new ThinkingContent('why', 'SIG'), new TextContent('so')],
            Api::AnthropicMessages,
            'anthropic',
            'claude-sonnet-4-5',
            new Usage(reasoning: 3, cacheWrite1h: 2),
            StopReason::Stop,
            null,
            1_700_000_000_000,
            'end_turn',
            'msg_01',
            'claude-sonnet-4-5-20250929',
            true,
            [new AssistantMessageDiagnostic('t', 1)],
        );

        $google = new Model('gemini-2.5-pro', 'Gemini', Api::GoogleGenerativeAi, 'google', 'http://127.0.0.1:1', 1_000_000, 8_192);
        $rewritten = TransformMessages::apply([$message], $google)[0];

        $this->assertInstanceOf(AssistantMessage::class, $rewritten);
        $this->assertNotSame($message, $rewritten, 'the content was rewritten');
        $this->assertSame('end_turn', $rewritten->rawStopReason);
        $this->assertSame('msg_01', $rewritten->responseId);
        $this->assertSame('claude-sonnet-4-5-20250929', $rewritten->responseModel);
        $this->assertTrue($rewritten->endTurn);
        $this->assertSame($message->diagnostics, $rewritten->diagnostics);
        $this->assertSame($message->usage, $rewritten->usage);
        // The redacted block is gone and the readable one became text.
        $this->assertCount(2, $rewritten->content);
        $this->assertInstanceOf(TextContent::class, $rewritten->content[0]);
        $this->assertStringContainsString('why', $rewritten->content[0]->text);
    }

    public function testAnotherModelOfTheSameProviderAndApiGetsNoSignaturesBack(): void
    {
        // Upstream's `isSameModel` compares provider, API *and* model id. Pig used to stop at
        // provider and API, so `/model` from one Claude to another replayed thinking signed by
        // the first one — and a signature is only valid for the model that made it.
        $message = $this->assistant([
            new ThinkingContent('why', 'SIG'),
            new ThinkingContent('', 'ENCRYPTED-ONLY'),
            new TextContent('so', 'TEXT-SIG'),
            new ToolCall('c1', 'read', ['path' => 'a'], 'THOUGHT-SIG'),
        ]);
        $opus = new Model('claude-opus-4-1', 'Opus', Api::AnthropicMessages, 'anthropic', 'http://127.0.0.1:1', 200_000, 8_192);

        $rewritten = TransformMessages::apply([$message], $opus)[0];

        $this->assertInstanceOf(AssistantMessage::class, $rewritten);
        $this->assertCount(3, $rewritten->content);

        // The thought becomes plain text, with no `<thinking>` tags for the model to mimic, and
        // a signed block with no text has nothing left to carry, so it goes.
        $this->assertEquals(new TextContent('why'), $rewritten->content[0]);

        // Text keeps its words but not its signature; the call keeps everything but its thought
        // signature.
        $this->assertEquals(new TextContent('so'), $rewritten->content[1]);
        $this->assertEquals(new ToolCall('c1', 'read', ['path' => 'a']), $rewritten->content[2]);
    }

    public function testTheSameModelGetsItsSignaturesBackAndOnlyLosesThinkingWithNothingInIt(): void
    {
        $sonnet = new Model('claude-sonnet-4-5', 'Sonnet', Api::AnthropicMessages, 'anthropic', 'http://127.0.0.1:1', 200_000, 8_192);

        $intact = $this->assistant([new ThinkingContent('why', 'SIG'), new TextContent('so', 'TEXT-SIG')]);

        $this->assertSame($intact, TransformMessages::apply([$intact], $sonnet)[0], 'nothing to rewrite');

        // Signed but empty is kept — OpenAI's encrypted reasoning has no text, and the signature
        // is what is replayed. Unsigned and empty carries nothing, so upstream drops it even for
        // the model that wrote it.
        $signed = new ThinkingContent('', 'ENCRYPTED-ONLY');
        $rewritten = TransformMessages::apply([$this->assistant([$signed, new ThinkingContent('  '), new TextContent('so')])], $sonnet)[0];

        $this->assertInstanceOf(AssistantMessage::class, $rewritten);
        $this->assertCount(2, $rewritten->content);
        $this->assertSame($signed, $rewritten->content[0]);
    }

    public function testAModelThatCannotSeeIsToldAnImageWasLeftOutAndARunIsOneLine(): void
    {
        // Upstream's `downgradeUnsupportedImages()`. pig dropped the images in each provider and
        // said nothing, so a text-only model asked "what does this screenshot show?" answered as
        // if no screenshot existed. Two images in a row — or an image right after the placeholder
        // itself, which is what a history that went through here once looks like — are one line.
        $textOnly = new Model('deepseek-chat', 'DeepSeek', Api::OpenAiCompletions, 'deepseek', 'http://127.0.0.1:1', 64_000, 8_192, input: ['text']);
        $user = '(image omitted: model does not support images)';
        $tool = '(tool image omitted: model does not support images)';

        $out = TransformMessages::apply([
            new UserMessage([
                new TextContent('look'),
                new ImageContent('AAA', 'image/png'),
                new ImageContent('BBB', 'image/png'),
                new TextContent('and'),
                new TextContent($user),
                new ImageContent('CCC', 'image/png'),
            ], 5),
            $this->assistant([new ToolCall('c1', 'read', ['path' => 'a.png'])]),
            new ToolResultMessage('c1', 'read', [new ImageContent('DDD', 'image/png')], false, null, 7),
        ], $textOnly);

        $this->assertInstanceOf(UserMessage::class, $out[0]);
        $this->assertEquals(
            [new TextContent('look'), new TextContent($user), new TextContent('and'), new TextContent($user)],
            $out[0]->content,
        );
        $this->assertSame(5, $out[0]->timestamp);

        // A tool result says so in its own words, and keeps everything else it had.
        $this->assertInstanceOf(ToolResultMessage::class, $out[2]);
        $this->assertEquals([new TextContent($tool)], $out[2]->content);
        $this->assertSame(['c1', 'read', false, 7], [$out[2]->toolCallId, $out[2]->toolName, $out[2]->isError, $out[2]->timestamp]);
    }

    public function testAModelThatCanSeeGetsItsImagesUntouched(): void
    {
        // Upstream's `if (model.input.includes("image")) return messages`.
        $vision = new Model('claude-sonnet-4-5', 'Sonnet', Api::AnthropicMessages, 'anthropic', 'http://127.0.0.1:1', 200_000, 8_192, input: ['text', 'image']);
        $message = new UserMessage([new TextContent('look'), new ImageContent('AAA', 'image/png')]);

        $this->assertSame($message, TransformMessages::apply([$message], $vision)[0]);
    }

    public function testOnlyXhighClampsDown(): void
    {
        $this->assertSame(ReasoningEffort::High, ReasoningEffort::Xhigh->clampToHigh());
        $this->assertSame(ReasoningEffort::Low, ReasoningEffort::Low->clampToHigh());
        $this->assertSame(ReasoningEffort::High, ReasoningEffort::High->clampToHigh());
    }

    /** @param list<\Pig\Ai\AssistantContent> $content */
    private function assistant(array $content): AssistantMessage
    {
        return new AssistantMessage(
            $content,
            Api::AnthropicMessages,
            'anthropic',
            'claude-sonnet-4-5',
            new Usage(),
            StopReason::ToolUse,
        );
    }
}
