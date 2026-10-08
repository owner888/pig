<?php

declare(strict_types=1);

namespace Pig\Ai\Test\Utils;

use PHPUnit\Framework\TestCase;
use Pig\Ai\Api;
use Pig\Ai\AssistantMessage;
use Pig\Ai\Context;
use Pig\Ai\Model;
use Pig\Ai\Providers\TransformMessages;
use Pig\Ai\StopReason;
use Pig\Ai\SystemMessage;
use Pig\Ai\TextContent;
use Pig\Ai\Tool;
use Pig\Ai\ToolCall;
use Pig\Ai\ToolReference;
use Pig\Ai\ToolResultMessage;
use Pig\Ai\TranscriptContext;
use Pig\Ai\Usage;
use Pig\Ai\UserMessage;
use Pig\Ai\Utils\Estimate;
use Pig\Ai\Utils\MessageJson;
use Pig\Ai\Utils\Text;
use Pig\Ai\Utils\Transcript;
use stdClass;

/**
 * Upstream's `utils/transcript.ts` and `utils/text.ts`: the prompt and the tool set as a transcript
 * of system messages, and the replay that turns them back into one.
 */
final class TranscriptTest extends TestCase
{
    public function testAContextWithNeitherPromptNorToolsStaysEmpty(): void
    {
        $this->assertSame([], Transcript::normalizeContext(new Context([]))->messages);
        $this->assertNull(Transcript::createInitialSystemMessage('', []));
    }

    public function testThePromptAndTheToolsBecomeTheLeadingSystemMessageStampedZero(): void
    {
        $user = new UserMessage('hi');
        $transcript = Transcript::normalizeContext(new Context([$user], 'be brief', [self::tool('read')]));

        $this->assertCount(2, $transcript->messages);
        $this->assertInstanceOf(SystemMessage::class, $transcript->messages[0]);
        $this->assertSame('be brief', $transcript->messages[0]->content);
        $this->assertSame(['read'], self::names($transcript->messages[0]->toolsAdded ?? []));
        $this->assertSame(0, $transcript->messages[0]->timestamp);
        $this->assertSame($user, $transcript->messages[1]);

        // Tools without a prompt still lead, with `content: ""`; a prompt without tools has no
        // `toolsAdded` at all.
        $this->assertSame('', Transcript::createInitialSystemMessage(null, [self::tool('read')])?->content);
        $this->assertNull(Transcript::createInitialSystemMessage('p', [])?->toolsAdded);
    }

    public function testReplayAppendsContentPatchesSectionsAndResolvesTools(): void
    {
        $messages = [
            new SystemMessage('base', ['tools' => '<tools>a</tools>', 'cwd' => '<cwd>/x</cwd>'], [self::tool('a'), self::tool('b')], null, 5),
            new UserMessage('hi'),
            new SystemMessage('also this', ['tools' => '<tools>a, c</tools>', 'cwd' => null], [self::tool('c')], [new ToolReference('b')], 9),
        ];

        $current = Transcript::getCurrentSystemMessage($messages);

        $this->assertNotNull($current);
        $this->assertSame("base\n\nalso this", $current->content);
        $this->assertSame(['tools' => '<tools>a, c</tools>'], $current->sections);
        $this->assertSame(['a', 'c'], self::names($current->toolsAdded ?? []));
        $this->assertSame(5, $current->timestamp, 'the first system message stamps the replay');
        $this->assertSame("base\n\nalso this\n\n<tools>a, c</tools>", Transcript::getCurrentSystemPrompt($messages));
    }

    public function testARemovedThenAddedToolMovesToTheEndAsAMapDeleteAndSetDoes(): void
    {
        $messages = [
            new SystemMessage('', null, [self::tool('a'), self::tool('b')]),
            new SystemMessage('', null, [self::tool('a', 'changed')], [new ToolReference('a')]),
        ];

        $this->assertSame(['b', 'a'], self::names(Transcript::getCurrentTools($messages)));
        $this->assertSame('changed', Transcript::getCurrentTools($messages)[1]->description);
    }

    public function testTheTextAndTheUpdateRenderAsUpstreamWordsThem(): void
    {
        $leading = new SystemMessage([new TextContent('base')], ['a' => 'A', 'gone' => null, 'b' => '']);
        $this->assertSame("base\n\nA", Text::getSystemMessageText($leading), 'null and empty sections are left out');

        $update = new SystemMessage('more', ['rules' => '- be kind', 'skills' => null]);
        $this->assertSame(
            "more\n\nUpdated system prompt section \"rules\":\n\n- be kind\n\nRemoved system prompt section \"skills\".",
            Text::renderSystemMessageUpdate($update),
        );
    }

    public function testCollapsingPutsTheReplayFirstAndDropsEveryLaterSystemMessage(): void
    {
        $user = new UserMessage('hi');
        $context = new TranscriptContext([new SystemMessage('base'), $user, new SystemMessage('more')]);

        $collapsed = Transcript::collapseSystemMessages($context);

        $this->assertCount(2, $collapsed->messages);
        $this->assertSame("base\n\nmore", Text::getSystemMessageText($collapsed->messages[0]));
        $this->assertSame($user, $collapsed->messages[1]);
        $this->assertSame($context, Transcript::resolveTranscript($context, true), 'a model that takes them keeps them in place');
        $this->assertCount(2, Transcript::resolveTranscript($context, null)->messages);
    }

    public function testAChangedDefinitionIsARemovalAndAnAdditionAndAnEmptyObjectIsNotAChange(): void
    {
        $live = [new Tool('a', 'A', ['type' => 'object', 'properties' => new stdClass()]), self::tool('b')];
        // As a session file gives them back: `{}` read as `[]`.
        $fromFile = [new Tool('a', 'A', ['type' => 'object', 'properties' => []]), self::tool('b', 'old')];

        $changes = Transcript::getToolStateChanges($fromFile, $live);

        $this->assertSame(['b'], self::names($changes['toolsAdded']));
        $this->assertSame(['b'], array_map(static fn (ToolReference $r): string => $r->name, $changes['toolsRemoved']));
        $this->assertTrue(Transcript::declarationsEqual($live[0], $fromFile[0]));
    }

    public function testToolsAnchorAtTheirSystemMessageOnlyWhenNothingWasRemovedOrRedeclared(): void
    {
        $additive = [new SystemMessage('', null, [self::tool('a')]), new UserMessage('hi'), new SystemMessage('', null, [self::tool('b')])];

        $this->assertSame(['requestTools' => [$additive[0]->toolsAdded[0]], 'anchorsAdditions' => true], Transcript::resolveTranscriptTools($additive, true));
        $this->assertFalse(Transcript::hasNonAdditiveToolChanges($additive));
        $this->assertSame(['a', 'b'], self::names(Transcript::resolveTranscriptTools($additive, false)['requestTools']));

        $removing = [...$additive, new SystemMessage('', null, null, [new ToolReference('a')])];
        $this->assertTrue(Transcript::hasNonAdditiveToolChanges($removing));
        $this->assertSame(['requestTools' => [$additive[2]->toolsAdded[0]], 'anchorsAdditions' => false], Transcript::resolveTranscriptTools($removing, true));

        $redeclaring = [...$additive, new SystemMessage('', null, [self::tool('a', 'new')])];
        $this->assertTrue(Transcript::hasNonAdditiveToolChanges($redeclaring));
        $this->assertTrue(Transcript::hasToolRedefinitions($redeclaring));
        $this->assertSame(['a', 'b'], self::names(Transcript::getDeclaredTools($redeclaring)));
    }

    public function testASystemMessageGoesToJsonAndBackWithAnEmptySchemaObjectKept(): void
    {
        $message = new SystemMessage(
            '',
            ['preamble' => 'hi', 'gone' => null],
            [new Tool('list', 'List', ['type' => 'object', 'properties' => new stdClass(), 'required' => []], ['type' => 'json_schema', 'strict' => 'prefer'])],
            [new ToolReference('old')],
            42,
        );

        $json = (string) json_encode(MessageJson::encode($message));
        $this->assertSame(
            '{"role":"system","content":"","sections":{"preamble":"hi","gone":null},"toolsAdded":[{"name":"list","description":"List","parameters":{"type":"object","properties":{},"required":[]},"constrainedSampling":{"type":"json_schema","strict":"prefer"}}],"toolsRemoved":[{"name":"old"}],"timestamp":42}',
            $json,
        );

        $back = MessageJson::decode(json_decode($json, true));
        $this->assertInstanceOf(SystemMessage::class, $back);
        $this->assertSame($json, (string) json_encode(MessageJson::encode($back)), 'the properties come back as an object, not a list');

        // The optional fields only when set, as `JSON.stringify` drops an undefined one.
        $this->assertSame('{"role":"system","content":"p","timestamp":0}', (string) json_encode(MessageJson::encode(new SystemMessage('p', timestamp: 0))));
        $this->assertSame('', MessageJson::decode(['role' => 'system', 'content' => null])?->content, 'a null content reads as ""');
    }

    public function testASystemMessageBetweenACallAndItsResultIsHeldUntilTheResultsAreIn(): void
    {
        $call = new AssistantMessage([new ToolCall('c1', 'read', [])], Api::AnthropicMessages, 'anthropic', 'm', new Usage(), StopReason::ToolUse);
        $update = new SystemMessage('', null, [self::tool('late')]);
        $result = new ToolResultMessage('c1', 'read', [new TextContent('ok')]);
        $user = new UserMessage('next');

        $out = TransformMessages::apply([$call, $update, $result, $user], self::model());

        $this->assertSame([$call, $result, $update, $user], $out);

        // Answered by a synthetic result when the call never got one, and still after it.
        $out = TransformMessages::apply([$call, $update, $user], self::model());
        $this->assertCount(4, $out);
        $this->assertInstanceOf(ToolResultMessage::class, $out[1]);
        $this->assertSame($update, $out[2]);
        $this->assertSame($user, $out[3]);
    }

    public function testASystemMessageIsEstimatedAsItsTextAndItsToolsJson(): void
    {
        $tool = self::tool('read');
        $message = new SystemMessage('abcdefg', ['s' => 'hij'], [$tool], [new ToolReference('x')]);
        $expected = (int) ceil(strlen("abcdefg\n\nhij") / 3.5)
            + (int) ceil(strlen((string) json_encode([MessageJson::encodeTool($tool)])) / 3.5)
            + (int) ceil(strlen('[{"name":"x"}]') / 3.5);

        $this->assertSame($expected, Estimate::contextTokens([$message]));
    }

    private static function tool(string $name, string $description = 'does a thing'): Tool
    {
        return new Tool($name, $description, ['type' => 'object', 'properties' => ['path' => ['type' => 'string']]]);
    }

    /**
     * @param list<Tool> $tools
     * @return list<string>
     */
    private static function names(array $tools): array
    {
        return array_map(static fn (Tool $tool): string => $tool->name, $tools);
    }

    private static function model(): Model
    {
        return new Model('m', 'M', Api::AnthropicMessages, 'anthropic', 'http://127.0.0.1:1', 1000, 100, input: ['text', 'image']);
    }
}
