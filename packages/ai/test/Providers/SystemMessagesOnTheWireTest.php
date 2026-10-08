<?php

declare(strict_types=1);

namespace Pig\Ai\Test\Providers;

use PHPUnit\Framework\TestCase;
use Pig\Ai\AnthropicCompat;
use Pig\Ai\Api;
use Pig\Ai\AssistantMessage;
use Pig\Ai\Model;
use Pig\Ai\OpenAiCompat;
use Pig\Ai\Providers\Anthropic;
use Pig\Ai\Providers\AnthropicOptions;
use Pig\Ai\Providers\Google;
use Pig\Ai\Providers\GoogleOptions;
use Pig\Ai\Providers\Mistral;
use Pig\Ai\Providers\MistralOptions;
use Pig\Ai\Providers\OpenAiCompletions;
use Pig\Ai\Providers\OpenAiOptions;
use Pig\Ai\Providers\OpenAiResponses;
use Pig\Ai\StopReason;
use Pig\Ai\SystemMessage;
use Pig\Ai\TextContent;
use Pig\Ai\Tool;
use Pig\Ai\ToolReference;
use Pig\Ai\TranscriptContext;
use Pig\Ai\Usage;
use Pig\Ai\UserMessage;
use Pig\Ai\Utils\ShortHash;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\Test\CannedServer;

/**
 * A transcript whose system messages change the prompt and the tools mid-conversation, as each
 * provider sends it — upstream's per-API handling of `SystemMessage`: in place where the model
 * takes system messages after the conversation started (`supportsMidConvoSystemMessages`), with
 * its tools anchored where it can (Anthropic's `inline-tools`, Kimi's tool-bearing system message,
 * the Responses API's `additional_tools` or a client-executed tool search), and folded into the
 * leading prompt everywhere else.
 */
final class SystemMessagesOnTheWireTest extends TestCase
{
    private CannedServer $server;

    #[\Override]
    protected function setUp(): void
    {
        Loop::reset();
        $this->server = new CannedServer();
    }

    // ---- Anthropic --------------------------------------------------------------------------

    public function testAnthropicWithNativeToolChangesSendsTheUpdateInPlaceWithItsToolsByValue(): void
    {
        $body = $this->anthropic(new AnthropicCompat(supportsMidConvoSystemMessages: true, supportsMidConvoToolChanges: true));

        // The leading message is the `system` field; the tool list is the initial tools and the
        // placeholder, and never changes after that.
        $this->assertSame('base prompt', $body['system'][0]['text']);
        $this->assertSame(['read', '__pi_deferred_placeholder__'], array_column($body['tools'], 'name'));
        $this->assertTrue($body['tools'][1]['defer_loading']);
        $this->assertSame(['type' => 'ephemeral'], $body['tools'][0]['cache_control'], 'the breakpoint on the last initial tool, before the placeholder');
        $this->assertContains('inline-tools-2026-09-15', explode(',', (string) $this->header('anthropic-beta')));

        // The update goes as a system turn before the next assistant message — here the end, so
        // after the user message it sat in front of — with its text, the removal and the addition.
        $this->assertSame(['user', 'assistant', 'user', 'system'], array_column($body['messages'], 'role'));
        $system = $body['messages'][3]['content'];
        $this->assertSame("also this\n\nUpdated system prompt section \"rules\":\n\n- be kind", $system[0]['text']);
        $this->assertSame(['type' => 'tool_removal', 'tool' => ['type' => 'tool_reference', 'name' => 'read']], $system[1]);
        $this->assertSame('tool_addition', $system[2]['type']);
        $this->assertSame('write', $system[2]['tool']['definition']['name']);
        $this->assertSame(['type' => 'ephemeral'], $system[2]['cache_control'], 'the last block of the last message carries the breakpoint');
    }

    public function testAnthropicWithTextOnlyUpdatesSendsTheCurrentToolList(): void
    {
        // Copilot and OpenCode forward mid-conversation system messages but refuse tool blocks.
        $body = $this->anthropic(new AnthropicCompat(supportsMidConvoSystemMessages: true));

        $this->assertSame(['write'], array_column($body['tools'], 'name'));
        $this->assertSame(['user', 'assistant', 'user', 'system'], array_column($body['messages'], 'role'));
        $this->assertCount(1, $body['messages'][3]['content']);
        $this->assertStringNotContainsString('inline-tools', (string) $this->header('anthropic-beta'));
    }

    public function testAnthropicWithoutMidConversationSystemMessagesFoldsThemIntoTheSystemField(): void
    {
        $body = $this->anthropic(null);

        $this->assertSame("base prompt\n\nalso this\n\n- be kind", $body['system'][0]['text']);
        $this->assertSame(['write'], array_column($body['tools'], 'name'));
        $this->assertSame(['user', 'assistant', 'user'], array_column($body['messages'], 'role'));
    }

    // ---- OpenAI completions ------------------------------------------------------------------

    public function testKimiGetsItsToolBearingSystemMessageAndTheUpdateInPlace(): void
    {
        $body = $this->completions(
            new OpenAiCompat(supportsMidConvoSystemMessages: true, supportsMidConvoToolAdditions: true),
            self::additiveTranscript(),
        );

        $this->assertSame(['read'], array_column(array_column($body['tools'], 'function'), 'name'));
        $this->assertSame(['system', 'user', 'assistant', 'system', 'system', 'user'], array_column($body['messages'], 'role'));
        $this->assertSame('base prompt', $body['messages'][0]['content']);
        $this->assertSame('write', $body['messages'][3]['tools'][0]['function']['name']);
        $this->assertArrayNotHasKey('content', $body['messages'][3]);
        $this->assertSame('also this', $body['messages'][4]['content']);
    }

    public function testACompletionsModelWithoutSupportGetsOneSystemMessageAndTheCurrentTools(): void
    {
        $body = $this->completions(null, self::additiveTranscript());

        $this->assertSame(['read', 'write'], array_column(array_column($body['tools'], 'function'), 'name'));
        $this->assertSame(['system', 'user', 'assistant', 'user'], array_column($body['messages'], 'role'));
        $this->assertSame("base prompt\n\nalso this", $body['messages'][0]['content']);
    }

    // ---- OpenAI Responses --------------------------------------------------------------------

    public function testAdditionalToolsAreLoadedWhereTheSystemMessageStands(): void
    {
        $body = $this->responses(new OpenAiCompat(supportsMidConvoSystemMessages: true, supportsAdditionalTools: true, supportsToolSearch: true));

        $this->assertSame(['read'], array_column($body['tools'], 'name'));
        $this->assertSame(['additional_tools', null], [$body['input'][3]['type'] ?? null, $body['input'][4]['type'] ?? null]);
        $this->assertSame('developer', $body['input'][3]['role']);
        $this->assertSame('write', $body['input'][3]['tools'][0]['name']);
        $this->assertSame(['system', 'also this'], [$body['input'][4]['role'], $body['input'][4]['content']]);
    }

    public function testToolSearchLoadsThemWithACompletedClientSearchWhenThereAreNoAdditionalTools(): void
    {
        $body = $this->responses(new OpenAiCompat(supportsMidConvoSystemMessages: true, supportsToolSearch: true));
        $callId = 'pi_tool_load_' . ShortHash::of('system:2:write');

        $this->assertSame([
            'type' => 'tool_search_call',
            'call_id' => $callId,
            'execution' => 'client',
            'status' => 'completed',
            'arguments' => ['query' => 'write', 'limit' => 1],
        ], $body['input'][3]);
        $this->assertSame('tool_search_output', $body['input'][4]['type']);
        $this->assertSame($callId, $body['input'][4]['call_id']);
        $this->assertTrue($body['input'][4]['tools'][0]['defer_loading']);
    }

    public function testAResponsesModelWithoutSupportGetsTheReplayedPromptAndTools(): void
    {
        $body = $this->responses(null);

        $this->assertSame(['read', 'write'], array_column($body['tools'], 'name'));
        $this->assertSame("base prompt\n\nalso this", $body['input'][0]['content']);
        $this->assertSame(['system', 'user', 'assistant', 'user'], array_map(static fn (array $item): ?string => $item['role'] ?? null, $body['input']));
    }

    // ---- Gemini and Mistral -------------------------------------------------------------------

    public function testGeminiAlwaysFoldsThemIntoTheSystemInstruction(): void
    {
        $url = $this->refusing();
        $model = new Model('gemini-2.5-flash', 'G', Api::GoogleGenerativeAi, 'google', rtrim($url, '/'), 1_000_000, 8_000);

        Async::run(fn () => (new Google())->stream($model, self::removingTranscript(), new GoogleOptions(apiKey: 'k'))->result()->await());
        $body = $this->server->receivedJson();

        $this->assertSame("base prompt\n\nalso this\n\n- be kind", $body['systemInstruction']['parts'][0]['text']);
        $this->assertSame(['write'], array_column($body['tools'][0]['functionDeclarations'], 'name'));
        $this->assertSame(['user', 'model', 'user'], array_column($body['contents'], 'role'));
    }

    public function testMistralSendsTheUpdateAsASystemMessageWhereItsCompatSaysItCan(): void
    {
        $body = $this->mistral(new OpenAiCompat(supportsMidConvoSystemMessages: true));

        $this->assertSame(['system', 'user', 'assistant', 'system', 'user'], array_column($body['messages'], 'role'));
        $this->assertSame("also this\n\nUpdated system prompt section \"rules\":\n\n- be kind", $body['messages'][3]['content']);

        $body = $this->mistral(null);
        $this->assertSame(['system', 'user', 'assistant', 'user'], array_column($body['messages'], 'role'));
        $this->assertSame("base prompt\n\nalso this\n\n- be kind", $body['messages'][0]['content']);
    }

    // ---- helpers -------------------------------------------------------------------------------

    /** The prompt and `read`, a turn, then `rules` added and `read` swapped for `write`. */
    private static function removingTranscript(): TranscriptContext
    {
        return new TranscriptContext([
            new SystemMessage('base prompt', null, [self::tool('read')], null, 0),
            new UserMessage('hi'),
            self::answer(),
            new SystemMessage('also this', ['rules' => '- be kind'], [self::tool('write')], [new ToolReference('read')]),
            new UserMessage('and now'),
        ]);
    }

    /** The prompt and `read`, a turn, then `write` added and nothing removed. */
    private static function additiveTranscript(): TranscriptContext
    {
        return new TranscriptContext([
            new SystemMessage('base prompt', null, [self::tool('read')], null, 0),
            new UserMessage('hi'),
            self::answer(),
            new SystemMessage('also this', null, [self::tool('write')]),
            new UserMessage('and now'),
        ]);
    }

    /** @return array<string, mixed> */
    private function anthropic(?AnthropicCompat $compat): array
    {
        $url = $this->refusing();
        $model = new Model('claude-test', 'C', Api::AnthropicMessages, 'anthropic', rtrim($url, '/'), 200_000, 8_000, compat: $compat);

        Async::run(fn () => (new Anthropic())->stream($model, self::removingTranscript(), new AnthropicOptions(apiKey: 'test-key'))->result()->await());

        return $this->server->receivedJson();
    }

    /** @return array<string, mixed> */
    private function completions(?OpenAiCompat $compat, TranscriptContext $context): array
    {
        $url = $this->refusing();
        $model = new Model('kimi-k3', 'K', Api::OpenAiCompletions, 'moonshotai', rtrim($url, '/'), 200_000, 8_000, compat: $compat);

        Async::run(fn () => (new OpenAiCompletions())->stream($model, $context, new OpenAiOptions(apiKey: 'k'))->result()->await());

        return $this->server->receivedJson();
    }

    /** @return array<string, mixed> */
    private function responses(?OpenAiCompat $compat): array
    {
        $url = $this->refusing();
        $model = new Model('gpt-test', 'G', Api::OpenAiResponses, 'openai', rtrim($url, '/'), 200_000, 8_000, compat: $compat);

        Async::run(fn () => (new OpenAiResponses())->stream($model, self::additiveTranscript(), new OpenAiOptions(apiKey: 'sk-k'))->result()->await());

        return $this->server->receivedJson();
    }

    /** @return array<string, mixed> */
    private function mistral(?OpenAiCompat $compat): array
    {
        $url = $this->refusing();
        $model = new Model('mistral-test', 'M', Api::MistralConversations, 'mistral', rtrim($url, '/'), 128_000, 8_000, compat: $compat);

        Async::run(fn () => (new Mistral())->stream($model, self::removingTranscript(), new MistralOptions(apiKey: 'k'))->result()->await());

        return $this->server->receivedJson();
    }

    /** A server that refuses whatever it is sent; what is under test is what it was sent. */
    private function refusing(): string
    {
        $this->server = new CannedServer();

        return $this->server->start(["HTTP/1.1 400 Bad Request\r\nContent-Length: 2\r\n\r\n{}"]);
    }

    /** One header of the request that went out, by its lowercase name. */
    private function header(string $name): ?string
    {
        foreach (explode("\r\n", $this->server->receivedHead()) as $line) {
            [$key, $value] = array_pad(explode(':', $line, 2), 2, null);

            if ($value !== null && strtolower(trim($key)) === $name) {
                return trim($value);
            }
        }

        return null;
    }

    private static function answer(): AssistantMessage
    {
        return new AssistantMessage([new TextContent('hello')], Api::AnthropicMessages, 'other', 'other-model', new Usage(), StopReason::Stop);
    }

    private static function tool(string $name): Tool
    {
        return new Tool($name, "the {$name} tool", ['type' => 'object', 'properties' => ['path' => ['type' => 'string']], 'required' => ['path']]);
    }
}
