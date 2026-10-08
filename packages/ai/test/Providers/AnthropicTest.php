<?php

declare(strict_types=1);

namespace Pig\Ai\Test\Providers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pig\Ai\AnthropicCompat;
use Pig\Ai\Api;
use Pig\Ai\AssistantMessage;
use Pig\Ai\Context;
use Pig\Ai\DoneEvent;
use Pig\Ai\ErrorEvent;
use Pig\Ai\ImageContent;
use Pig\Ai\Model;
use Pig\Ai\Models;
use Pig\Ai\OpenAiCompat;
use Pig\Ai\Pricing;
use Pig\Ai\Providers\Anthropic;
use Pig\Ai\Providers\AnthropicOptions;
use Pig\Ai\Providers\ClaudeCode;
use Pig\Ai\StopReason;
use Pig\Ai\TextContent;
use Pig\Ai\TextDeltaEvent;
use Pig\Ai\ThinkingContent;
use Pig\Ai\Tool;
use Pig\Ai\ToolCall;
use Pig\Ai\ToolCallDeltaEvent;
use Pig\Ai\ToolCallEndEvent;
use Pig\Ai\ToolResultMessage;
use Pig\Ai\Usage;
use Pig\Ai\UserMessage;
use Pig\Async\AbortController;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\Test\CannedServer;

final class AnthropicTest extends TestCase
{
    private CannedServer $server;

    #[\Override]
    protected function setUp(): void
    {
        Loop::reset();
        $this->server = new CannedServer();
    }

    public function testATextResponseArrivesAsEventsAndThenAsAMessage(): void
    {
        $url = $this->serveStream([
            ['message_start', ['message' => ['usage' => ['input_tokens' => 12, 'cache_read_input_tokens' => 4]]]],
            ['content_block_start', ['index' => 0, 'content_block' => ['type' => 'text']]],
            ['content_block_delta', ['index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'Hel']]],
            ['content_block_delta', ['index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'lo']]],
            ['content_block_stop', ['index' => 0]],
            // Anthropic's documented shape: the final delta carries the output count and nothing
            // else. Repeating the input here — which this fixture used to do — is what hid a `?? 0`
            // that wiped out everything `message_start` had reported.
            ['message_delta', ['delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 5]]],
            ['message_stop', []],
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

        $this->assertSame(StopReason::Stop, $message->stopReason);
        // Anthropic's own word, beside the mapped one — several of its reasons map to `stop`.
        $this->assertSame('end_turn', $message->rawStopReason);
        $this->assertCount(1, $message->content);
        $this->assertInstanceOf(TextContent::class, $message->content[0]);
        $this->assertSame('Hello', $message->content[0]->text);

        // Anthropic reports components only; the total and the cost are worked out here.
        $this->assertSame(21, $message->usage->totalTokens);
        $this->assertSame(12, $message->usage->input);
        $this->assertSame(4, $message->usage->cacheRead);
        $this->assertSame(3.0 / 1_000_000 * 12, $message->usage->cost->input);
    }

    public function testAUsageFieldTheFinalDeltaDoesNotMentionKeepsWhatWasReported(): void
    {
        // `input_tokens: number | null` is the SDK's own type for this field: null means "not
        // reported", and read as zero it wipes out what `message_start` said. The input cost of
        // every turn vanished from `/stats`, and `Compaction::contextTokens()` — which believes
        // `totalTokens` — saw a conversation the size of its last answer, so auto-compaction never
        // fired on Anthropic at all.
        $url = $this->serveStream([
            ['message_start', ['message' => ['usage' => [
                'input_tokens' => 1000,
                'cache_read_input_tokens' => 200,
                'cache_creation_input_tokens' => 50,
            ]]]],
            ['content_block_start', ['index' => 0, 'content_block' => ['type' => 'text']]],
            ['content_block_delta', ['index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'ok']]],
            ['content_block_stop', ['index' => 0]],
            ['message_delta', ['delta' => ['stop_reason' => 'end_turn'], 'usage' => [
                'output_tokens' => 7,
                // Explicitly null, which is what the API sends when it has nothing to add.
                'input_tokens' => null,
            ]]],
            ['message_stop', []],
        ]);

        [, $message] = $this->collect($url, new Context([new UserMessage('hi')]));

        $this->assertSame(1000, $message->usage->input);
        $this->assertSame(200, $message->usage->cacheRead);
        $this->assertSame(50, $message->usage->cacheWrite);
        $this->assertSame(7, $message->usage->output, 'and what it did report is taken');
        $this->assertSame(1257, $message->usage->totalTokens);
    }

    public function testAFinalDeltaThatDoesReportTheCumulativeCountsWins(): void
    {
        // The other half: the field is nullable, not absent, so a stream that carries the running
        // totals must be believed rather than ignored.
        $url = $this->serveStream([
            ['message_start', ['message' => ['usage' => ['input_tokens' => 10]]]],
            ['content_block_start', ['index' => 0, 'content_block' => ['type' => 'text']]],
            ['content_block_stop', ['index' => 0]],
            ['message_delta', ['delta' => ['stop_reason' => 'end_turn'], 'usage' => [
                'input_tokens' => 1200,
                'output_tokens' => 3,
            ]]],
            ['message_stop', []],
        ]);

        [, $message] = $this->collect($url, new Context([new UserMessage('hi')]));

        $this->assertSame(1200, $message->usage->input);
    }

    public function testTextArrivesInPiecesNotAllAtOnce(): void
    {
        $url = $this->serveStream([
            ['content_block_start', ['index' => 0, 'content_block' => ['type' => 'text']]],
            ['content_block_delta', ['index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'one ']]],
            ['content_block_delta', ['index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'two']]],
            ['content_block_stop', ['index' => 0]],
            ['message_delta', ['delta' => ['stop_reason' => 'end_turn'], 'usage' => []]],
        ]);

        $partials = Async::run(function () use ($url): array {
            $seen = [];

            foreach ($this->anthropic()->stream($this->model($url), new Context([new UserMessage('hi')]), $this->options()) as $event) {
                if ($event instanceof TextDeltaEvent) {
                    // Every event carries a real snapshot, not a view of one mutating object.
                    $seen[] = $event->partial->content[0]->text;
                }
            }

            return $seen;
        });

        $this->assertSame(['one ', 'one two'], $partials);
    }

    public function testToolArgumentsFillInAsTheyStream(): void
    {
        $url = $this->serveStream([
            ['content_block_start', ['index' => 0, 'content_block' => ['type' => 'tool_use', 'id' => 'toolu_01', 'name' => 'read']]],
            ['content_block_delta', ['index' => 0, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '{"path"']]],
            ['content_block_delta', ['index' => 0, 'delta' => ['type' => 'input_json_delta', 'partial_json' => ': "/tmp/a.php"']]],
            ['content_block_delta', ['index' => 0, 'delta' => ['type' => 'input_json_delta', 'partial_json' => ', "limit": 10}']]],
            ['content_block_stop', ['index' => 0]],
            ['message_delta', ['delta' => ['stop_reason' => 'tool_use'], 'usage' => []]],
        ]);

        $seen = Async::run(function () use ($url): array {
            $partials = [];
            $final = null;

            foreach ($this->anthropic()->stream($this->model($url), new Context([new UserMessage('read it')]), $this->options()) as $event) {
                if ($event instanceof ToolCallDeltaEvent) {
                    $partials[] = $event->partial->content[0]->arguments;
                }

                if ($event instanceof ToolCallEndEvent) {
                    $final = $event->toolCall;
                }
            }

            return [$partials, $final];
        });

        // Halfway through, the key is there and the value is not yet.
        $this->assertSame([[], ['path' => '/tmp/a.php'], ['path' => '/tmp/a.php', 'limit' => 10]], $seen[0]);

        $this->assertInstanceOf(ToolCall::class, $seen[1]);
        $this->assertSame('toolu_01', $seen[1]->id);
        $this->assertSame('read', $seen[1]->name);
        $this->assertSame(['path' => '/tmp/a.php', 'limit' => 10], $seen[1]->arguments);
    }

    public function testThinkingKeepsItsSignature(): void
    {
        $url = $this->serveStream([
            ['content_block_start', ['index' => 0, 'content_block' => ['type' => 'thinking']]],
            ['content_block_delta', ['index' => 0, 'delta' => ['type' => 'thinking_delta', 'thinking' => 'let me look']]],
            ['content_block_delta', ['index' => 0, 'delta' => ['type' => 'signature_delta', 'signature' => 'sig123']]],
            ['content_block_stop', ['index' => 0]],
            ['message_delta', ['delta' => ['stop_reason' => 'end_turn'], 'usage' => []]],
        ]);

        [, $message] = $this->collect($url, new Context([new UserMessage('think')]));

        $this->assertInstanceOf(ThinkingContent::class, $message->content[0]);
        $this->assertSame('let me look', $message->content[0]->thinking);
        $this->assertSame('sig123', $message->content[0]->thinkingSignature);
    }

    public function testAnApiErrorBecomesAFailedMessageNotAnException(): void
    {
        $body = '{"type":"error","error":{"type":"authentication_error","message":"invalid key"}}';
        $url = $this->server->start([
            sprintf("HTTP/1.1 401 Unauthorized\r\nContent-Type: application/json\r\nContent-Length: %d\r\n\r\n%s", strlen($body), $body),
        ]);

        $events = Async::run(function () use ($url): array {
            $stream = $this->anthropic()->stream($this->model($url), new Context([new UserMessage('hi')]), $this->options());
            $seen = [];

            foreach ($stream as $event) {
                $seen[] = $event;
            }

            return $seen;
        });

        $this->assertCount(1, $events);
        $this->assertInstanceOf(ErrorEvent::class, $events[0]);
        $this->assertSame(StopReason::Error, $events[0]->error->stopReason);
        $this->assertStringContainsString('401', (string) $events[0]->error->errorMessage);
        $this->assertStringContainsString('invalid key', (string) $events[0]->error->errorMessage);
    }

    public function testAbortingLeavesAnAbortedMessage(): void
    {
        $url = $this->server->start([
            "HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\n\r\n",
            $this->chunk("event: content_block_start\ndata: " . json_encode(['index' => 0, 'content_block' => ['type' => 'text']]) . "\n\n"),
            // …and then nothing.
        ], closeAfter: false);

        $message = Async::run(function () use ($url): AssistantMessage {
            $controller = new AbortController();
            $stream = $this->anthropic()->stream(
                $this->model($url),
                new Context([new UserMessage('hi')]),
                $this->options($controller),
            );

            Loop::get()->delay(0.08, static fn () => $controller->abort('user pressed esc'));

            foreach ($stream as $ignored) {
                // Drain until the abort lands.
            }

            return $stream->result()->await();
        });

        $this->assertSame(StopReason::Aborted, $message->stopReason);
        $this->assertSame('user pressed esc', $message->errorMessage);
    }

    /**
     * An interrupted turn still reports what it cost.
     *
     * Anthropic sends usage at the *start* of a stream, so a turn escape stopped half way has real
     * numbers to report — and upstream's `tokens.test.ts` asserts exactly that, per provider, with
     * the two OpenAI APIs as the documented exception because they only report in the final chunk.
     * The rule was right here and untested: the abort case above serves a stream with no
     * `message_start` in it, so nothing would have caught a regression that dropped the usage. What
     * it would cost is a bill that misses every interrupted turn, and escape is not a rare key.
     */
    public function testAnAbortedTurnKeepsTheUsageThatHadAlreadyArrived(): void
    {
        $url = $this->server->start([
            "HTTP/1.1 200 OK\r\nContent-Type: text/event-stream\r\nTransfer-Encoding: chunked\r\n\r\n",
            $this->chunk("event: message_start\ndata: " . json_encode([
                'type' => 'message_start',
                'message' => ['usage' => ['input_tokens' => 1200, 'cache_read_input_tokens' => 0]],
            ]) . "\n\n"),
            $this->chunk("event: content_block_start\ndata: " . json_encode([
                'type' => 'content_block_start',
                'index' => 0,
                'content_block' => ['type' => 'text'],
            ]) . "\n\n"),
            $this->chunk("event: content_block_delta\ndata: " . json_encode([
                'type' => 'content_block_delta',
                'index' => 0,
                'delta' => ['type' => 'text_delta', 'text' => str_repeat('a poem ', 50)],
            ]) . "\n\n"),
            // …and then the connection sits there while the person presses escape.
        ], closeAfter: false);

        $message = Async::run(function () use ($url): AssistantMessage {
            $controller = new AbortController();
            $stream = $this->anthropic()->stream(
                $this->model($url),
                new Context([new UserMessage('write a long poem')]),
                $this->options($controller),
            );

            Loop::get()->delay(0.08, static fn () => $controller->abort('user pressed esc'));

            foreach ($stream as $ignored) {
                // Drain until the abort lands.
            }

            return $stream->result()->await();
        });

        $this->assertSame(StopReason::Aborted, $message->stopReason);
        $this->assertSame(1_200, $message->usage->input);

        // And the cost with it, since that is what `/session` and the footer add up.
        $this->assertSame(1_200 / 1_000_000 * 3.0, $message->usage->cost->input);
        $this->assertGreaterThan(0.0, $message->usage->cost->total);
    }

    public function testTheRequestIsShapedTheWayAnthropicWantsIt(): void
    {
        $url = $this->serveStream([['message_delta', ['delta' => ['stop_reason' => 'end_turn'], 'usage' => []]]]);

        Async::run(function () use ($url): void {
            $context = new Context(
                [
                    new UserMessage('first'),
                    new ToolResultMessage('call_a', 'read', [new TextContent('file body')]),
                    new ToolResultMessage('call_c', 'read', [new TextContent('other body')]),
                ],
                'be brief',
                [new Tool('read', 'Read a file', ['properties' => ['path' => ['type' => 'string']], 'required' => ['path']])],
            );

            foreach ($this->anthropic()->stream($this->model($url), $context, $this->options()) as $ignored) {
            }
        });

        $head = $this->server->receivedHead();
        $body = $this->server->receivedJson();

        $this->assertStringContainsString('POST /v1/messages HTTP/1.1', $head);
        $this->assertStringContainsString('anthropic-version: 2023-06-01', $head);
        $this->assertStringContainsString('x-api-key: test-key', $head);
        // Upstream streams a tool's input with `eager_input_streaming` on the tool now, not with the
        // `fine-grained-tool-streaming` beta on every request — see `testToolsAskForEagerInputStreamingInsteadOfTheFineGrainedBeta`.
        $this->assertStringNotContainsString('fine-grained-tool-streaming', $head);
        $this->assertStringContainsString('anthropic-dangerous-direct-browser-access: true', $head);

        $this->assertSame('claude-sonnet-4-5', $body['model']);
        $this->assertTrue($body['stream']);
        // maxTokens unset, so a third of the model's ceiling.
        $this->assertSame(21_000, $body['max_tokens']);
        $this->assertSame('be brief', $body['system'][0]['text']);
        $this->assertSame('ephemeral', $body['system'][0]['cache_control']['type']);
        $this->assertSame('read', $body['tools'][0]['name']);
        $this->assertSame(['path'], $body['tools'][0]['input_schema']['required']);

        // Consecutive tool results collapse into one user turn. (This case used to send `call_a!b`
        // and expect it scrubbed here; ids are now made safe by `TransformMessages`, for another
        // model's calls, and a result with no call is not one of those — see
        // `testAnotherModelsToolCallIdIsMadeSafeAndItsResultFollows`.)
        $this->assertCount(2, $body['messages']);
        $this->assertSame('user', $body['messages'][1]['role']);
        $this->assertCount(2, $body['messages'][1]['content']);
        $this->assertSame('call_a', $body['messages'][1]['content'][0]['tool_use_id']);
        $this->assertSame('file body', $body['messages'][1]['content'][0]['content']);

        // The final block carries the cache breakpoint for the next turn.
        $this->assertSame('ephemeral', $body['messages'][1]['content'][1]['cache_control']['type']);
    }

    public function testASubscriptionTokenGoesOutAsABearerTokenUnderClaudeCodesIdentity(): void
    {
        $url = $this->serveStream([['message_delta', ['delta' => ['stop_reason' => 'end_turn'], 'usage' => []]]]);

        Async::run(function () use ($url): void {
            $stream = $this->anthropic()->stream(
                $this->model($url),
                new Context([new UserMessage('hi')], 'be brief'),
                new AnthropicOptions(apiKey: 'sk-ant-oat01-abc'),
            );

            foreach ($stream as $ignored) {
            }
        });

        $head = $this->server->receivedHead();
        $body = $this->server->receivedJson();

        // The far end of `Utils\Oauth\Anthropic`: a subscription token is not an API key and
        // Anthropic refuses it in `x-api-key`.
        $this->assertStringContainsString('authorization: Bearer sk-ant-oat01-abc', $head);
        $this->assertStringNotContainsString('x-api-key', $head);
        // Both betas, Claude Code's first — pi 1.0's `getBetaFeatures()` order, and the one
        // upstream has sent since January 2026.
        $this->assertStringContainsString('anthropic-beta: claude-code-20250219,oauth-2025-04-20', $head);
        // And Claude Code's own user agent and app marker, which the API checks for on a token
        // it issued to Claude Code.
        $this->assertStringContainsString('user-agent: claude-cli/' . ClaudeCode::VERSION, $head);
        $this->assertStringContainsString('x-app: cli', $head);

        // And the identity the token was issued to has to be the first thing in the system
        // prompt, ahead of whatever this session's own prompt says.
        $this->assertSame("You are Claude Code, Anthropic's official CLI for Claude.", $body['system'][0]['text']);
        $this->assertSame('be brief', $body['system'][1]['text']);
    }

    public function testASubscriptionTokensToolsGoOutUnderClaudeCodesNamesAndComeBackUnderPigs(): void
    {
        // Claude Code spells its tools `Read`, `Bash`, `Edit`, `Write`; a request on its token
        // naming them `read` reads as somebody else's. So the declaration, the earlier calls in
        // the history and the call that comes back are all mapped — the last one back to the
        // name *this request* declared, so the loop finds the tool it registered.
        $url = $this->serveStream([
            ['content_block_start', ['index' => 0, 'content_block' => ['type' => 'tool_use', 'id' => 'toolu_02', 'name' => 'Read']]],
            ['content_block_delta', ['index' => 0, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '{"path": "b.php"}']]],
            ['content_block_stop', ['index' => 0]],
            ['message_delta', ['delta' => ['stop_reason' => 'tool_use'], 'usage' => []]],
        ]);

        $tools = [
            new Tool('read', 'Read a file', ['properties' => ['path' => ['type' => 'string']], 'required' => ['path']]),
            new Tool('bash', 'Run a command', ['properties' => ['command' => ['type' => 'string']], 'required' => ['command']]),
            new Tool('wc', 'Count lines', ['properties' => [], 'required' => []]),
        ];

        $final = Async::run(function () use ($url, $tools): ?ToolCall {
            $final = null;
            $context = new Context([
                new UserMessage('hi'),
                $this->fromAnthropic([new ToolCall('c1', 'bash', ['command' => 'ls'])]),
                new ToolResultMessage('c1', 'bash', [new TextContent('a.php')], false),
            ], 'be brief', $tools);

            foreach ($this->anthropic()->stream($this->model($url), $context, new AnthropicOptions(apiKey: 'sk-ant-oat01-abc')) as $event) {
                if ($event instanceof ToolCallEndEvent) {
                    $final = $event->toolCall;
                }
            }

            return $final;
        });

        $body = $this->server->receivedJson();

        // Declared under Claude Code's spelling; a tool Claude Code has no name for is left alone.
        $this->assertSame(['Read', 'Bash', 'wc'], array_column($body['tools'], 'name'));
        // The call already in the history goes out the same way, or the model is shown a call
        // to a tool it was never offered.
        $this->assertSame('Bash', $body['messages'][1]['content'][0]['name']);
        // And what comes back is pig's name again.
        $this->assertInstanceOf(ToolCall::class, $final);
        $this->assertSame('read', $final->name);
    }

    public function testAnApiKeysToolsKeepTheirOwnNames(): void
    {
        $body = $this->sendAndCapture(new Context(
            [new UserMessage('hi')],
            null,
            [new Tool('read', 'Read a file', ['properties' => ['path' => ['type' => 'string']], 'required' => ['path']])],
        ));

        // The mapping is a fact about the token and nothing else: with an API key the names are
        // pig's, because that is what the system prompt lists.
        $this->assertSame(['read'], array_column($body['tools'], 'name'));
    }

    public function testAnApiKeyDoesNotClaimToBeClaudeCode(): void
    {
        $url = $this->serveStream([['message_delta', ['delta' => ['stop_reason' => 'end_turn'], 'usage' => []]]]);

        Async::run(function () use ($url): void {
            foreach ($this->anthropic()->stream($this->model($url), new Context([new UserMessage('hi')], 'be brief'), $this->options()) as $ignored) {
            }
        });

        $head = $this->server->receivedHead();

        $this->assertStringContainsString('x-api-key: test-key', $head);
        $this->assertStringNotContainsString('authorization:', $head);
        // Sending the betas without the token would be claiming an identity this request has
        // no right to, and the system block, the user agent and the tool names go with it.
        $this->assertStringNotContainsString('oauth-2025-04-20', $head);
        $this->assertStringNotContainsString('claude-code-20250219', $head);
        $this->assertStringNotContainsString('claude-cli/', $head);
        $this->assertSame('be brief', $this->server->receivedJson()['system'][0]['text']);
    }

    public function testAgainstTheRealApi(): void
    {
        // Two switches, both on purpose: `PIG_NETWORK_TESTS=1` is the general one, and
        // `PIG_LIVE_ANTHROPIC=1` is this provider's own, so a run that turns network tests on for
        // the proxy or the socket does not also spend a request on somebody's Anthropic account.
        // `test/live.php` is the harness for that, run by hand.
        if (getenv('PIG_NETWORK_TESTS') !== '1' || getenv('PIG_LIVE_ANTHROPIC') !== '1') {
            self::markTestSkipped('set PIG_NETWORK_TESTS=1 and PIG_LIVE_ANTHROPIC=1 to reach the real API');
        }

        $key = getenv('ANTHROPIC_API_KEY');

        if (!is_string($key) || $key === '') {
            self::markTestSkipped('needs ANTHROPIC_API_KEY');
        }

        $model = new Model(
            getenv('PIG_MODEL') ?: 'claude-haiku-4-5-20251001',
            'live',
            Api::AnthropicMessages,
            'anthropic',
            'https://api.anthropic.com',
            200_000,
            64_000,
        );

        [$deltas, $message] = Async::run(function () use ($model, $key): array {
            $stream = $this->anthropic()->stream(
                $model,
                new Context([new UserMessage('Reply with exactly: pong')], 'Follow the instruction exactly.'),
                new AnthropicOptions(maxTokens: 16, apiKey: $key),
            );

            $count = 0;

            foreach ($stream as $event) {
                if ($event instanceof TextDeltaEvent) {
                    $count++;
                }
            }

            return [$count, $stream->result()->await()];
        });

        $this->assertSame(StopReason::Stop, $message->stopReason, (string) $message->errorMessage);
        $this->assertStringContainsString('pong', strtolower($message->content[0]->text));
        $this->assertGreaterThanOrEqual(1, $deltas);
        $this->assertGreaterThanOrEqual(1, $message->usage->output);
    }

    // ---- strict tools ------------------------------------------------------------------------

    /**
     * Upstream's `convertTools()`: on a model with `supportsStrictTools` — which its catalogue
     * gives every `anthropic` provider model, and `Models` does too — a tool asking for strict
     * sampling goes out with `strict: true` and its strict schema, the legacy three keys laid over
     * it. pig sent every tool as the legacy three, so the strict declaration on the built-in tools
     * did nothing here.
     */
    public function testAStrictToolGoesOutStrictToAnthropicItself(): void
    {
        [, $body] = $this->capture($this->model(compat: new AnthropicCompat(strictTools: true)), new Context([new UserMessage('hi')], tools: [new Tool('read', 'Read a file', [
            'type' => 'object',
            'properties' => ['path' => ['type' => 'string'], 'limit' => ['type' => 'number']],
            'required' => ['path'],
        ], ['type' => 'json_schema', 'strict' => 'prefer'])]), $this->options());

        $this->assertTrue($body['tools'][0]['strict']);
        $this->assertSame([
            'type' => 'object',
            'properties' => ['path' => ['type' => 'string'], 'limit' => ['anyOf' => [['type' => 'number'], ['type' => 'null']]]],
            'required' => ['path', 'limit'],
            'additionalProperties' => false,
        ], $body['tools'][0]['input_schema']);
    }

    /**
     * A schema using a keyword Anthropic's strict mode answers with a 400 for the whole request
     * (`minimum` here) is sent the old way — a `prefer` tool falls back rather than failing.
     */
    public function testAStrictToolWithAKeywordAnthropicRefusesFallsBackToTheLegacySchema(): void
    {
        $tool = new Tool('read', 'Read a file', [
            'type' => 'object',
            'properties' => ['path' => ['type' => 'string'], 'limit' => ['type' => 'number', 'minimum' => 1]],
            'required' => ['path'],
        ], ['type' => 'json_schema', 'strict' => 'prefer']);

        [, $body] = $this->capture($this->model(compat: new AnthropicCompat(strictTools: true)), new Context([new UserMessage('hi')], tools: [$tool]), $this->options());

        $this->assertArrayNotHasKey('strict', $body['tools'][0]);
        $this->assertSame(['path'], $body['tools'][0]['input_schema']['required']);
    }

    /**
     * Upstream's default is `supportsStrictTools: false`: a model whose compat does not say so —
     * an Anthropic-shaped endpoint of anybody else's — gets the same tool unchanged.
     */
    public function testAnotherProviderOnTheAnthropicApiGetsNoStrictTools(): void
    {
        $url = $this->serveStream([['message_delta', ['delta' => ['stop_reason' => 'end_turn'], 'usage' => []]]]);
        $model = new Model('claude-sonnet-4-5', 'Proxy', Api::AnthropicMessages, 'my-proxy', rtrim($url, '/'), 200_000, 63_000);
        $context = new Context([new UserMessage('hi')], tools: [new Tool('read', 'Read a file', [
            'type' => 'object',
            'properties' => ['path' => ['type' => 'string'], 'limit' => ['type' => 'number']],
            'required' => ['path'],
        ], ['type' => 'json_schema', 'strict' => 'prefer'])]);

        Async::run(function () use ($model, $context): void {
            foreach ($this->anthropic()->stream($model, $context, $this->options()) as $ignored) {
            }
        });

        $tool = $this->server->receivedJson()['tools'][0];

        $this->assertArrayNotHasKey('strict', $tool);
        $this->assertSame(['path'], $tool['input_schema']['required']);
    }

    // ---- adaptive thinking -------------------------------------------------------------------

    /**
     * Upstream's `forceAdaptiveThinking`: adaptive thinking plus `output_config.effort`, and no
     * interleaved-thinking beta, because adaptive thinking interleaves without it.
     */
    public function testAModelWhoseCompatSaysAdaptiveThinksAdaptively(): void
    {
        [$head, $body] = $this->capture(
            $this->model(compat: new AnthropicCompat(forceAdaptiveThinking: true)),
            new Context([new UserMessage('hi')]),
            new AnthropicOptions(apiKey: 'test-key', thinkingEnabled: true, thinkingBudgetTokens: 4096, effort: 'medium'),
        );

        // `display: "summarized"` is upstream's default on both arms (see the test below).
        $this->assertSame(['type' => 'adaptive', 'display' => 'summarized'], $body['thinking']);
        $this->assertSame(['effort' => 'medium'], $body['output_config']);
        $this->assertStringNotContainsString('interleaved-thinking', $head);
    }

    /**
     * Nothing at request time looks at the id any more: upstream decides adaptive by
     * `model.compat?.forceAdaptiveThinking === true` alone, and its generator sets that on the
     * built-in ids. pig matched four id fragments here, so a model the fragments named got adaptive
     * thinking whatever its compat said — and Opus 4.6, which they did not name, got a budget.
     */
    public function testWithoutTheFlagEvenAnAdaptiveIdGetsABudget(): void
    {
        [$head, $body] = $this->capture(
            $this->model(id: 'claude-opus-4-7'),
            new Context([new UserMessage('hi')]),
            new AnthropicOptions(apiKey: 'test-key', thinkingEnabled: true, thinkingBudgetTokens: 4096),
        );

        $this->assertSame(['type' => 'enabled', 'budget_tokens' => 4096, 'display' => 'summarized'], $body['thinking']);
        $this->assertStringContainsString('interleaved-thinking', $head);

        // And the built-in Opus 4.6 carries the flag, so it is sent adaptive.
        $opus = Models::find(Models::ANTHROPIC, 'claude-opus-4-6');
        $this->assertNotNull($opus);
        $this->server = new CannedServer();
        [, $body] = $this->capture(
            $this->model(id: 'claude-opus-4-6', compat: $opus->compat),
            new Context([new UserMessage('hi')]),
            new AnthropicOptions(apiKey: 'test-key', thinkingEnabled: true, thinkingBudgetTokens: 4096),
        );

        $this->assertSame(['type' => 'adaptive', 'display' => 'summarized'], $body['thinking']);
    }

    /**
     * The read that used to be there was `$model->compat?->forceAdaptiveThinking` on an
     * `OpenAiCompat`, a property it does not have: a PHP warning (a failure under this suite's
     * `failOnWarning`) for any thinking turn of an Anthropic model carrying a compat block — which a
     * `models.json` `compat` on an `anthropic-messages` model was. A compat of another API's type is
     * now simply not this provider's.
     */
    public function testAnAnthropicModelCarryingACompatBlockDoesNotWarn(): void
    {
        foreach ([new OpenAiCompat(store: false), new AnthropicCompat()] as $compat) {
            $this->server = new CannedServer();
            [, $body] = $this->capture(
                $this->model(compat: $compat),
                new Context([new UserMessage('hi')], tools: [new Tool('read', 'Read', ['type' => 'object', 'properties' => []], ['type' => 'json_schema', 'strict' => 'prefer'])]),
                new AnthropicOptions(apiKey: 'test-key', thinkingEnabled: true, thinkingBudgetTokens: 2048),
            );

            $this->assertSame(['type' => 'enabled', 'budget_tokens' => 2048, 'display' => 'summarized'], $body['thinking'], $compat::class);
            $this->assertArrayNotHasKey('strict', $body['tools'][0], $compat::class);
        }
    }

    /**
     * Upstream's `thinkingDisplay ?? "summarized"`, on the adaptive and the budget arm alike: Opus
     * 4.7 and later default to omitting the thinking text, and without this a thinking turn on them
     * streamed an empty block. A caller's own `omitted` is sent as asked.
     */
    public function testAThinkingTurnAsksForSummarizedThinkingUnlessTheCallerSaysOtherwise(): void
    {
        [, $body] = $this->capture(
            $this->model(compat: new AnthropicCompat(forceAdaptiveThinking: true)),
            new Context([new UserMessage('hi')]),
            new AnthropicOptions(apiKey: 'test-key', thinkingEnabled: true, thinkingDisplay: 'omitted'),
        );

        $this->assertSame(['type' => 'adaptive', 'display' => 'omitted'], $body['thinking']);

        $this->server = new CannedServer();
        [, $body] = $this->capture(
            $this->model(),
            new Context([new UserMessage('hi')]),
            new AnthropicOptions(apiKey: 'test-key', thinkingEnabled: true, thinkingBudgetTokens: 3000),
        );

        $this->assertSame('summarized', $body['thinking']['display']);
    }

    /**
     * Upstream: `thinkingEnabled === false && model.thinkingLevelMap?.off !== null` sends
     * `thinking: {type: "disabled"}`. pig sent nothing, which an adaptive model reads as "think
     * as you like" — so a turn with thinking switched off still thought, and was billed for it.
     */
    public function testThinkingSwitchedOffIsSaidRatherThanLeftToTheApi(): void
    {
        [, $body] = $this->capture(
            $this->model(compat: new AnthropicCompat(forceAdaptiveThinking: true)),
            new Context([new UserMessage('hi')]),
            new AnthropicOptions(apiKey: 'test-key', thinkingEnabled: false),
        );

        $this->assertSame(['type' => 'disabled'], $body['thinking']);
        $this->assertArrayNotHasKey('output_config', $body);
    }

    /**
     * The three states of the option and of the map, each where upstream has it: `off: null` in
     * the map means the model cannot be switched off and nothing is sent; a model that does not
     * reason is told nothing; and `thinkingEnabled` left unsaid (upstream's undefined) sends
     * nothing either — only `false` is "off".
     */
    public function testNothingIsSaidWhereOffIsNotALevelOrNothingWasAsked(): void
    {
        $model = $this->model();
        $cannotStop = new Model($model->id, $model->name, $model->api, $model->provider, $model->baseUrl, $model->contextWindow, $model->maxTokens, true, thinkingLevelMap: ['off' => null]);
        $cannotThink = new Model($model->id, $model->name, $model->api, $model->provider, $model->baseUrl, $model->contextWindow, $model->maxTokens, false);

        foreach ([
            'off is not a level' => [$cannotStop, new AnthropicOptions(apiKey: 'test-key', thinkingEnabled: false)],
            'not a reasoning model' => [$cannotThink, new AnthropicOptions(apiKey: 'test-key', thinkingEnabled: false)],
            'nothing asked' => [$model, new AnthropicOptions(apiKey: 'test-key')],
        ] as $case => [$subject, $options]) {
            $this->server = new CannedServer();
            [, $body] = $this->capture($subject, new Context([new UserMessage('hi')]), $options);

            $this->assertArrayNotHasKey('thinking', $body, $case);
        }
    }

    /**
     * Upstream routes Copilot's Claude through this API with the Copilot token as a **bearer**
     * (`authToken`), never `x-api-key` and never the Claude Code identity, and adds Copilot's
     * dynamic headers after the model's own — the same three the OpenAI providers send.
     */
    public function testACopilotClaudeAuthenticatesWithItsTokenAsABearerAndSendsCopilotsHeaders(): void
    {
        $model = Models::find(Models::COPILOT, 'claude-sonnet-4.6');
        $this->assertNotNull($model);
        $this->assertSame(Api::AnthropicMessages, $model->api);

        // An empty key keeps the model's own base URL (see `endpoint()`), so the canned server
        // sees the request; the header logic does not depend on the key's content.
        [$head, $body] = $this->capture(
            $model,
            new Context([new UserMessage([new TextContent('look'), new ImageContent('AAA', 'image/png')])]),
            new AnthropicOptions(apiKey: '', thinkingEnabled: true, effort: 'high'),
        );
        $head = strtolower($head);

        $this->assertStringContainsString("authorization: bearer \r\n", $head);
        $this->assertStringNotContainsString('x-api-key', $head);
        $this->assertStringContainsString('copilot-integration-id: vscode-chat', $head);
        $this->assertStringContainsString('x-initiator: user', $head);
        $this->assertStringContainsString('openai-intent: conversation-edits', $head);
        $this->assertStringContainsString('copilot-vision-request: true', $head);

        // Anthropic's thinking, which the completions route could not carry: Sonnet 4.6 is on
        // upstream's adaptive list, so its compat says so.
        $this->assertSame(['type' => 'adaptive', 'display' => 'summarized'], $body['thinking']);
        $this->assertSame(['effort' => 'high'], $body['output_config']);
    }

    /**
     * With a token, the host comes from its `proxy-ep=` claim, as for the two OpenAI providers:
     * the registry's `api.individual…` is only the default. Asserted on the URL rather than by
     * sending, since a real host cannot be served here.
     */
    public function testACopilotTokenDecidesWhereTheMessagesGo(): void
    {
        $model = Models::find(Models::COPILOT, 'claude-sonnet-4.6');
        $this->assertNotNull($model);

        $request = (new \ReflectionMethod(Anthropic::class, 'request'))->invoke(
            new Anthropic(),
            $model,
            new Context([new UserMessage('hi')]),
            new AnthropicOptions(apiKey: 'tid=1;proxy-ep=proxy.business.githubcopilot.com;exp=9'),
        );

        $this->assertSame('https://api.business.githubcopilot.com/v1/messages', $request->url);
        $this->assertSame('Bearer tid=1;proxy-ep=proxy.business.githubcopilot.com;exp=9', $request->headers['authorization']);
    }

    /**
     * Upstream gives an extension-registered model the `compat` its definition wrote and nothing
     * else (`{ ...definition, api, provider, baseUrl }`) — no id rule. So an adaptive id with no
     * compat gets a budget, and the same id with `forceAdaptiveThinking` gets adaptive thinking:
     * the registry passes the object through untouched.
     */
    public function testAnExtensionsAnthropicModelThinksAsItsOwnCompatSays(): void
    {
        $plain = new Model('claude-opus-4-7', 'Proxy Opus', Api::AnthropicMessages, 'my-ext', 'http://127.0.0.1:1', 200_000, 64_000, true);
        $adaptive = new Model('claude-opus-4-8', 'Proxy Opus', Api::AnthropicMessages, 'my-ext', 'http://127.0.0.1:1', 200_000, 64_000, true, compat: new AnthropicCompat(forceAdaptiveThinking: true));
        \Pig\Ai\Extension\ProviderRegistry::register(new \Pig\Ai\Extension\Provider('my-ext', 'My extension', [$plain, $adaptive]));

        try {
            foreach (['claude-opus-4-7' => 'enabled', 'claude-opus-4-8' => 'adaptive'] as $id => $type) {
                $registered = Models::find('my-ext', $id);
                $this->assertNotNull($registered);
                $this->server = new CannedServer();
                [, $body] = $this->capture($registered, new Context([new UserMessage('hi')]), new AnthropicOptions(apiKey: 'test-key', thinkingEnabled: true));

                $this->assertSame($type, $body['thinking']['type'], $id);
            }
        } finally {
            \Pig\Ai\Extension\ProviderRegistry::forget();
        }
    }

    /**
     * Upstream sends the interleaved-thinking beta only on a thinking turn of a reasoning model;
     * pig sent it on every request.
     */
    public function testATurnThatDoesNotThinkAsksForNoInterleavedThinking(): void
    {
        [$head] = $this->capture($this->model(), new Context([new UserMessage('hi')]), $this->options());

        $this->assertStringNotContainsString('interleaved-thinking', $head);
        // And no beta at all is left for such a request: the fine-grained one used to be sent on
        // every request and upstream sends it only for tools on a model without eager streaming.
        $this->assertStringNotContainsString('anthropic-beta', $head);
    }

    // ---- betas, eager input streaming, temperature -------------------------------------------

    public function testToolsAskForEagerInputStreamingInsteadOfTheFineGrainedBeta(): void
    {
        // Upstream's `convertTools()` puts `eager_input_streaming: true` on each tool (after the
        // description, before the schema) and `getBetaFeatures()` sends `fine-grained-tool-streaming`
        // only where a model does not take that field. pig sent the beta on every request and the
        // field never.
        $tool = new Tool('read', 'Read a file', ['type' => 'object', 'properties' => ['path' => ['type' => 'string']], 'required' => ['path']]);
        [$head, $body] = $this->capture($this->model(), new Context([new UserMessage('hi')], tools: [$tool]), $this->options());

        $this->assertSame(['name', 'description', 'eager_input_streaming', 'input_schema'], array_keys($body['tools'][0]));
        $this->assertTrue($body['tools'][0]['eager_input_streaming']);
        $this->assertStringNotContainsString('fine-grained-tool-streaming', $head);
    }

    public function testAModelWithoutEagerStreamingGetsTheFineGrainedBetaForItsTools(): void
    {
        // `supportsEagerToolInputStreaming: false` — upstream's generator writes it on three Copilot
        // Claudes. Tools then stream under the old beta, and the field is left off.
        $tool = new Tool('read', 'Read a file', ['type' => 'object', 'properties' => []]);
        $model = $this->model(compat: new AnthropicCompat(supportsEagerToolInputStreaming: false));
        [$head, $body] = $this->capture($model, new Context([new UserMessage('hi')], tools: [$tool]), $this->options());

        $this->assertStringContainsString('anthropic-beta: fine-grained-tool-streaming-2025-05-14', $head);
        $this->assertArrayNotHasKey('eager_input_streaming', $body['tools'][0]);

        // And with no tools there is nothing to stream, so no beta either.
        $this->server = new CannedServer();
        [$head] = $this->capture($model, new Context([new UserMessage('hi')]), $this->options());
        $this->assertStringNotContainsString('anthropic-beta', $head);

        $this->assertFalse(Models::find(Models::COPILOT, 'claude-haiku-4.5')?->compat?->supportsEagerToolInputStreaming);
    }

    public function testAModelsOwnAnthropicBetaHeaderIsTheWholeList(): void
    {
        // Upstream's `getBetaFeatures()`: an `anthropic-beta` among the model's headers, in any case,
        // replaces the computed list — split, trimmed, de-duplicated — and goes out once. pig sent
        // its own header and then the model's beside it.
        $model = new Model('claude-sonnet-4-5', 'S', Api::AnthropicMessages, 'anthropic', 'http://127.0.0.1:1', 200_000, 64_000, true, headers: ['Anthropic-Beta' => ' a, b,,a ']);
        [$head] = $this->capture($model, new Context([new UserMessage('hi')]), new AnthropicOptions(apiKey: 'test-key', thinkingEnabled: true));

        $this->assertSame(1, substr_count(strtolower($head), 'anthropic-beta:'));
        $this->assertStringContainsString('anthropic-beta: a,b', $head);
        $this->assertStringNotContainsString('interleaved-thinking', $head);
    }

    public function testTemperatureIsLeftOutWhileThinkingAndForAModelThatRefusesIt(): void
    {
        // Upstream: "Temperature is incompatible with extended thinking and unsupported on Claude
        // Opus 4.7+." pig sent whatever temperature it was given, and the API refused the request.
        [, $body] = $this->capture($this->model(), new Context([new UserMessage('hi')]), new AnthropicOptions(temperature: 0.3, apiKey: 'test-key'));
        $this->assertSame(0.3, $body['temperature']);

        $this->server = new CannedServer();
        [, $body] = $this->capture($this->model(), new Context([new UserMessage('hi')]), new AnthropicOptions(temperature: 0.3, apiKey: 'test-key', thinkingEnabled: true));
        $this->assertArrayNotHasKey('temperature', $body);

        $opus = Models::find(Models::ANTHROPIC, 'claude-opus-4-7');
        $this->assertNotNull($opus);
        $this->assertFalse($opus->compat?->supportsTemperature);
        $this->server = new CannedServer();
        [, $body] = $this->capture($opus, new Context([new UserMessage('hi')]), new AnthropicOptions(temperature: 0.3, apiKey: 'test-key', thinkingEnabled: false));
        $this->assertArrayNotHasKey('temperature', $body);
    }

    public function testAManagedEffortModelThinksAdaptivelyAndSaysItsEffortInSystemMessages(): void
    {
        // Upstream's `supportsMidConvoEffort` arm: always adaptive thinking with `block_binding`,
        // `output_config.effort: "high"` at the top, the two managed-effort betas, no temperature —
        // and an effort-only system message before each earlier turn of this provider that recorded
        // its effort, plus one with the effort asked for now at the end. pig had none of it, so an
        // Opus 5 turn said `thinking: {type: "disabled"}`-free adaptive thinking with no binding.
        $earlier = new AssistantMessage([new TextContent('first answer')], Api::AnthropicMessages, 'anthropic', 'claude-opus-5', new Usage(), StopReason::Stop, providerThinkingLevel: 'low');
        $foreign = new AssistantMessage([new TextContent('other answer')], Api::AnthropicMessages, 'github-copilot', 'claude-opus-5', new Usage(), StopReason::Stop, providerThinkingLevel: 'medium');
        $opus = Models::find(Models::ANTHROPIC, 'claude-opus-5');
        $this->assertNotNull($opus);
        $this->assertTrue($opus->compat?->supportsMidConvoEffort);

        [$head, $body] = $this->capture(
            $opus,
            new Context([new UserMessage('q1'), $earlier, new UserMessage('q2'), $foreign, new UserMessage('q3')]),
            new AnthropicOptions(temperature: 0.5, apiKey: 'test-key', thinkingEnabled: true, effort: 'xhigh'),
        );

        $this->assertSame(['type' => 'adaptive', 'display' => 'summarized', 'block_binding' => ['prefix_mismatch_behavior' => 'drop_block']], $body['thinking']);
        $this->assertSame(['effort' => 'high'], $body['output_config']);
        $this->assertArrayNotHasKey('temperature', $body);
        $this->assertStringContainsString('mid-conversation-output-config-2026-07-01,thinking-binding-controls-2026-08-01', $head);
        $this->assertSame(
            ['user', 'system', 'assistant', 'user', 'assistant', 'user', 'system'],
            array_column($body['messages'], 'role'),
        );
        $this->assertSame(['role' => 'system', 'content' => [], 'output_config' => ['effort' => 'low']], $body['messages'][1]);
        $this->assertSame(['role' => 'system', 'content' => [], 'output_config' => ['effort' => 'xhigh']], $body['messages'][6]);
        // The cache breakpoint stays on the last user turn, set before the system messages go in.
        $this->assertSame('ephemeral', $body['messages'][5]['content'][0]['cache_control']['type']);
    }

    public function testAManagedEffortTurnRecordsTheEffortItWasAskedFor(): void
    {
        // Upstream's `providerThinkingLevel = options?.effort ?? "high"` on the output — what the
        // next request replays in front of this turn.
        $url = $this->serveStream([['message_delta', ['delta' => ['stop_reason' => 'end_turn'], 'usage' => []]]]);
        $base = Models::find(Models::ANTHROPIC, 'claude-opus-5');
        $this->assertNotNull($base);
        $opus = new Model($base->id, $base->name, $base->api, $base->provider, rtrim($url, '/'), $base->contextWindow, $base->maxTokens, $base->reasoning, $base->input, $base->pricing, compat: $base->compat);

        $message = Async::run(fn (): AssistantMessage => $this->anthropic()->stream($opus, new Context([new UserMessage('hi')]), new AnthropicOptions(apiKey: 'test-key', effort: 'medium'))->result()->await());
        $this->assertSame('medium', $message->providerThinkingLevel);

        $plain = Async::run(fn (): AssistantMessage => $this->anthropic()->stream($this->model($this->serveStream([['message_delta', ['delta' => ['stop_reason' => 'end_turn'], 'usage' => []]]])), new Context([new UserMessage('hi')]), new AnthropicOptions(apiKey: 'test-key', effort: 'medium'))->result()->await());
        $this->assertNull($plain->providerThinkingLevel);
    }

    // ---- redacted thinking ----------------------------------------------------------------

    public function testARedactedThinkingBlockIsKeptAsThinkingWithItsPayloadInTheSignature(): void
    {
        // **This block used to be dropped**: `onBlockStart()` knew `text`, `thinking` and
        // `tool_use`, and Anthropic's `redacted_thinking` fell through to nothing. The turn then
        // went back without it, which is not the turn the model wrote. Upstream keeps it as a
        // thinking block whose text is a placeholder and whose signature is the opaque `data`.
        $url = $this->serveStream([
            ['message_start', ['message' => ['id' => 'msg_01', 'usage' => ['input_tokens' => 3]]]],
            ['content_block_start', ['index' => 0, 'content_block' => ['type' => 'redacted_thinking', 'data' => 'ENCRYPTED-BLOB']]],
            ['content_block_stop', ['index' => 0]],
            ['content_block_start', ['index' => 1, 'content_block' => ['type' => 'text']]],
            ['content_block_delta', ['index' => 1, 'delta' => ['type' => 'text_delta', 'text' => 'done']]],
            ['content_block_stop', ['index' => 1]],
            ['message_delta', ['delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 2]]],
        ]);

        [$types, $message] = $this->collect($url, new Context([new UserMessage('hi')]));

        $this->assertSame(
            ['StartEvent', 'ThinkingStartEvent', 'ThinkingEndEvent', 'TextStartEvent', 'TextDeltaEvent', 'TextEndEvent', 'DoneEvent'],
            $types,
        );

        $block = $message->content[0];
        $this->assertInstanceOf(ThinkingContent::class, $block);
        $this->assertTrue($block->redacted);
        $this->assertSame('ENCRYPTED-BLOB', $block->thinkingSignature);
        // What a screen or an export shows is the placeholder, never the payload.
        $this->assertSame('[Reasoning redacted]', $block->thinking);

        // And an ordinary thinking block says nothing about redaction, as upstream leaves it out.
        $this->assertNull((new ThinkingContent('x', 'sig'))->redacted);
    }

    public function testARedactedBlockGoesBackToTheSameModelAsRedactedThinking(): void
    {
        $body = $this->sendAndCapture(new Context([
            new UserMessage('hi'),
            $this->fromAnthropic([
                new ThinkingContent('[Reasoning redacted]', 'ENCRYPTED-BLOB', true),
                new TextContent('done'),
            ], StopReason::Stop),
            new UserMessage('go on'),
        ]));

        $this->assertSame(
            ['type' => 'redacted_thinking', 'data' => 'ENCRYPTED-BLOB'],
            $body['messages'][1]['content'][0],
        );
        $this->assertStringNotContainsString('[Reasoning redacted]', (string) json_encode($body));
    }

    public function testARedactedBlockFromAnotherModelOrProviderIsDroppedNotRewritten(): void
    {
        // Upstream `transform-messages.ts`: redacted thinking is encrypted for the model that
        // wrote it, so it is kept only when provider, API *and* model match, and dropped otherwise
        // — not turned into text, since its only text is the placeholder.
        $redacted = new ThinkingContent('[Reasoning redacted]', 'ENCRYPTED-BLOB', true);

        $otherModel = new AssistantMessage(
            [$redacted, new ThinkingContent('kept', 'SIG'), new TextContent('so')],
            Api::AnthropicMessages,
            'anthropic',
            'claude-opus-4-1',
            new Usage(),
            StopReason::Stop,
        );

        $body = $this->sendAndCapture(new Context([new UserMessage('hi'), $otherModel, new UserMessage('go on')]));

        // The signed thought beside it is no longer replayed as thinking either: its signature
        // is Opus's, and upstream keeps signatures only for the model that made them, so it goes
        // back as plain text with the signature left behind.
        $this->assertSame(
            [['type' => 'text', 'text' => 'kept'], ['type' => 'text', 'text' => 'so']],
            $body['messages'][1]['content'],
        );
        $this->assertStringNotContainsString('ENCRYPTED-BLOB', (string) json_encode($body));
        $this->assertStringNotContainsString('"SIG"', (string) json_encode($body));

        $this->server = new CannedServer();
        $body = $this->sendAndCapture(new Context([
            new UserMessage('hi'),
            $this->fromGoogle([$redacted, new TextContent('so')]),
            new UserMessage('go on'),
        ]));

        $this->assertSame([['type' => 'text', 'text' => 'so']], $body['messages'][1]['content']);
    }

    // ---- what the response says about itself -------------------------------------------------

    public function testTheMessageIdIsTheResponseIdAndAnotherModelIsTheResponseModel(): void
    {
        $url = $this->serveStream([
            ['message_start', ['message' => ['id' => 'msg_01', 'model' => 'claude-sonnet-4-5-20250929', 'usage' => []]]],
            ['message_delta', ['delta' => ['stop_reason' => 'end_turn'], 'usage' => []]],
        ]);

        [, $message] = $this->collect($url, new Context([new UserMessage('hi')]));

        $this->assertSame('msg_01', $message->responseId);
        $this->assertSame('claude-sonnet-4-5-20250929', $message->responseModel);
        $this->assertSame('claude-sonnet-4-5', $message->model, 'the requested model stays the model');

        // Upstream keeps the reported model only when it differs from the one asked for.
        $this->server = new CannedServer();
        $url = $this->serveStream([
            ['message_start', ['message' => ['id' => 'msg_02', 'model' => 'claude-sonnet-4-5', 'usage' => []]]],
            ['message_delta', ['delta' => ['stop_reason' => 'end_turn'], 'usage' => []]],
        ]);

        [, $same] = $this->collect($url, new Context([new UserMessage('hi')]));

        $this->assertSame('msg_02', $same->responseId);
        $this->assertNull($same->responseModel);
    }

    public function testThinkingTokensAreKeptAsTheReasoningShareOfTheOutput(): void
    {
        $url = $this->serveStream([
            ['message_start', ['message' => ['usage' => ['input_tokens' => 10]]]],
            ['message_delta', ['delta' => ['stop_reason' => 'end_turn'], 'usage' => [
                'output_tokens' => 50,
                'output_tokens_details' => ['thinking_tokens' => 30],
            ]]],
        ]);

        [, $message] = $this->collect($url, new Context([new UserMessage('hi')]));

        $this->assertSame(30, $message->usage->reasoning);
        $this->assertSame(50, $message->usage->output, 'a share of the output, not added to it');

        // Not reported, not known: null rather than a 0 nobody said.
        $this->server = new CannedServer();
        [, $plain] = $this->collect($this->serveStream([
            ['message_start', ['message' => ['usage' => ['input_tokens' => 10]]]],
            ['message_delta', ['delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 5]]],
        ]), new Context([new UserMessage('hi')]));

        $this->assertNull($plain->usage->reasoning);
    }

    // ---- one-hour cache writes (upstream anthropic-cache-write-1h-cost.test.ts) --------------

    public function testTheOneHourShareOfACacheWriteIsPricedAtTwiceTheInputRate(): void
    {
        // claude-opus-4-8's prices: input 5, cache write (five-minute) 6.25 per million. The
        // one-hour write is twice the input, 10. 600k * 6.25 + 400k * 10 = 3.75 + 4.0 = 7.75.
        [, $message] = $this->collect(
            $this->serveStream($this->cacheWriteEvents(['ephemeral_5m_input_tokens' => 600_000, 'ephemeral_1h_input_tokens' => 400_000])),
            new Context([new UserMessage('hi')]),
            $this->opusPricing(),
        );

        $this->assertSame(1_000_000, $message->usage->cacheWrite);
        $this->assertSame(400_000, $message->usage->cacheWrite1h);
        $this->assertEqualsWithDelta(7.75, $message->usage->cost->cacheWrite, 1e-10);
    }

    public function testAOneHourWriteReportedOnlyInTheFinalDeltaIsStillPricedAsOne(): void
    {
        // Upstream's #9210: Vercel's AI Gateway puts the cache counts on `message_delta` and not
        // on `message_start`, so the split has to be read from the delta as well.
        [, $message] = $this->collect($this->serveStream([
            ['message_start', ['message' => ['id' => 'msg_test', 'usage' => ['input_tokens' => 0, 'output_tokens' => 0]]]],
            ['message_delta', ['delta' => ['stop_reason' => 'end_turn'], 'usage' => [
                'input_tokens' => 3,
                'output_tokens' => 4,
                'cache_creation_input_tokens' => 6535,
                'cache_creation' => ['ephemeral_5m_input_tokens' => 0, 'ephemeral_1h_input_tokens' => 6535],
            ]]],
        ]), new Context([new UserMessage('hi')]), $this->opusPricing());

        $this->assertSame(6535, $message->usage->cacheWrite);
        $this->assertSame(6535, $message->usage->cacheWrite1h);
        $this->assertEqualsWithDelta(6535 * 5.0 * 2 / 1_000_000, $message->usage->cost->cacheWrite, 1e-10);
    }

    public function testWithNoSplitReportedTheWholeWriteIsPricedAtTheFiveMinuteRate(): void
    {
        [, $message] = $this->collect(
            $this->serveStream($this->cacheWriteEvents(null)),
            new Context([new UserMessage('hi')]),
            $this->opusPricing(),
        );

        $this->assertSame(1_000_000, $message->usage->cacheWrite);
        // 0 rather than unknown: upstream's `message_start` sets it with `|| 0`.
        $this->assertSame(0, $message->usage->cacheWrite1h);
        $this->assertEqualsWithDelta(6.25, $message->usage->cost->cacheWrite, 1e-10);
    }

    /**
     * @param array<string, int>|null $cacheCreation
     * @return list<array{0: string, 1: array<string, mixed>}>
     */
    private function cacheWriteEvents(?array $cacheCreation): array
    {
        $startUsage = [
            'input_tokens' => 100,
            'output_tokens' => 0,
            'cache_read_input_tokens' => 0,
            'cache_creation_input_tokens' => 1_000_000,
        ];

        if ($cacheCreation !== null) {
            $startUsage['cache_creation'] = $cacheCreation;
        }

        return [
            ['message_start', ['message' => ['id' => 'msg_test', 'usage' => $startUsage]]],
            ['content_block_start', ['index' => 0, 'content_block' => ['type' => 'text', 'text' => '']]],
            ['content_block_delta', ['index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'Hi']]],
            ['content_block_stop', ['index' => 0]],
            ['message_delta', ['delta' => ['stop_reason' => 'end_turn'], 'usage' => [
                'input_tokens' => 100,
                'output_tokens' => 5,
                'cache_read_input_tokens' => 0,
                'cache_creation_input_tokens' => 1_000_000,
            ]]],
            ['message_stop', []],
        ];
    }

    private function opusPricing(): Pricing
    {
        return new Pricing(input: 5.0, output: 25.0, cacheRead: 0.5, cacheWrite: 6.25);
    }

    // ---- diagnostics ------------------------------------------------------------------------

    public function testInputTransformationsTheApiReportsBecomeADiagnostic(): void
    {
        // Upstream's one Anthropic diagnostic: when the API says it rewrote the request, the turn
        // records what, cut down to type, path and reason — the latest list wins, wherever it came.
        [, $message] = $this->collect($this->serveStream([
            ['message_start', ['message' => ['usage' => [], 'input_transformations' => [['type' => 'ignored']]]]],
            ['message_delta', [
                'delta' => ['stop_reason' => 'end_turn'],
                'usage' => [],
                'input_transformations' => [
                    ['type' => 'drop', 'path' => 'messages.1.content.0', 'reason' => 'empty', 'extra' => 'not kept'],
                    ['type' => 'rewrite', 'reason' => null],
                ],
            ]],
        ]), new Context([new UserMessage('hi')]));

        $this->assertCount(1, $message->diagnostics ?? []);
        $diagnostic = $message->diagnostics[0];
        $this->assertSame('anthropic_input_transformations', $diagnostic->type);
        $this->assertNull($diagnostic->error);
        $this->assertSame([
            'transformations' => [
                ['type' => 'drop', 'path' => 'messages.1.content.0', 'reason' => 'empty'],
                ['type' => 'rewrite'],
            ],
        ], $diagnostic->details);
    }

    public function testNoTransformationsMeansNoDiagnostics(): void
    {
        [, $message] = $this->collect($this->serveStream([
            ['message_start', ['message' => ['usage' => [], 'input_transformations' => []]]],
            ['message_delta', ['delta' => ['stop_reason' => 'end_turn'], 'usage' => []]],
        ]), new Context([new UserMessage('hi')]));

        $this->assertNull($message->diagnostics);

        // Nor on a turn that failed: upstream throws first and never records them.
        $this->server = new CannedServer();
        [, $refused] = $this->collect($this->serveStream([
            ['message_start', ['message' => ['usage' => [], 'input_transformations' => [['type' => 'drop']]]]],
            ['message_delta', ['delta' => ['stop_reason' => 'refusal'], 'usage' => []]],
        ]), new Context([new UserMessage('hi')]));

        $this->assertSame(StopReason::Error, $refused->stopReason);
        $this->assertNull($refused->diagnostics);
    }

    // ---- a conversation another provider started -------------------------------------------

    public function testAnotherProvidersThinkingGoesBackAsPlainTextRatherThanAsSignedThinking(): void
    {
        // `/model gemini`, then `/model sonnet`. A thought is signed by the model that had it and
        // the signature means nothing here, so Anthropic rejects the request outright — which is
        // what `TransformMessages` exists to prevent, and this provider was the one of four that
        // did not call it.
        $body = $this->sendAndCapture(new Context([
            new UserMessage('hi'),
            $this->fromGoogle([new ThinkingContent('mine', 'GEMINI-SIG'), new TextContent('so')]),
            new UserMessage('go on'),
        ]));

        $assistant = $body['messages'][1];

        $this->assertSame('assistant', $assistant['role']);
        // Plain text, no `<thinking>` tags: upstream leaves them off so the model does not
        // learn to write them into its own answers.
        $this->assertSame(['type' => 'text', 'text' => 'mine'], $assistant['content'][0]);
        $this->assertStringNotContainsString('GEMINI-SIG', (string) json_encode($body));
    }

    public function testAnotherModelsToolCallIdIsMadeSafeAndItsResultFollows(): void
    {
        // `/model` from gpt-5 to sonnet mid-tool-use. A Responses API id is `call_id|item_id`, the
        // item half hundreds of characters with `|`, `+`, `/` and `=` in it, and Anthropic refuses
        // anything outside `^[a-zA-Z0-9_-]+$` or over 64 — the whole request, not the one call.
        // pig used to scrub the characters at send time and never cut the length, so a long
        // OpenAI id still went out over the limit. Upstream's `normalizeToolCallId()`: replace,
        // then cut to 64, and the result is renamed to match through `TransformMessages`' map.
        $long = 'call_abc|fc_' . str_repeat('x+/=', 30);
        $body = $this->sendAndCapture(new Context([
            new UserMessage('hi'),
            new AssistantMessage(
                [new ToolCall($long, 'read', ['path' => 'a.php'])],
                Api::OpenAiResponses,
                'openai',
                'gpt-5',
                new Usage(),
                StopReason::ToolUse,
            ),
            new ToolResultMessage($long, 'read', [new TextContent('contents')]),
        ]));

        $sent = $body['messages'][1]['content'][0]['id'];

        $this->assertSame(substr('call_abc_fc_' . str_repeat('x___', 30), 0, 64), $sent);
        $this->assertSame($sent, $body['messages'][2]['content'][0]['tool_use_id'], 'the result follows its call');
    }

    public function testThisModelsOwnToolCallIdGoesBackExactlyAsItCame(): void
    {
        // The rule runs only on another model's calls — upstream's `!isSameModel` guard — since
        // an id this model minted is one it accepts. Sending it back changed would address the
        // result to a call the model does not remember making.
        $body = $this->sendAndCapture(new Context([
            new UserMessage('hi'),
            $this->fromAnthropic([new ToolCall('toolu_01AbC-d_9', 'read', ['path' => 'a.php'])]),
            new ToolResultMessage('toolu_01AbC-d_9', 'read', [new TextContent('contents')]),
        ]));

        $this->assertSame('toolu_01AbC-d_9', $body['messages'][1]['content'][0]['id']);
        $this->assertSame('toolu_01AbC-d_9', $body['messages'][2]['content'][0]['tool_use_id']);
    }

    public function testACallLeftWithoutAResultGetsOneInventedForIt(): void
    {
        // An interrupted turn leaves this behind, and Anthropic refuses a `tool_use` with no
        // `tool_result` rather than ignoring it — so the next thing anybody types fails.
        $body = $this->sendAndCapture(new Context([
            new UserMessage('hi'),
            $this->fromAnthropic([new ToolCall('c1', 'read', ['path' => 'a.php'])]),
            new UserMessage('never mind, do something else'),
        ]));

        $results = $body['messages'][2]['content'];

        $this->assertSame('tool_result', $results[0]['type']);
        $this->assertSame('c1', $results[0]['tool_use_id']);
        $this->assertTrue($results[0]['is_error']);
        $this->assertStringContainsString('No result provided', (string) json_encode($results[0]['content']));
    }

    public function testTwoInterruptedTurnsGetOneInventedResultEach(): void
    {
        // `$flush()` empties what it flushed, and the mutation sweep found nothing noticing when
        // it does not: every case had a single turn with a call in it, so the two lines that clear
        // `$pending` and `$answered` between turns could both be deleted and the suite stayed
        // green. Without them the first turn's call is flushed again on every later turn — three
        // invented results for one interrupted call by the end of this conversation.
        $body = $this->sendAndCapture(new Context([
            new UserMessage('first'),
            $this->fromAnthropic([new ToolCall('c1', 'read', ['path' => 'a.php'])]),
            new UserMessage('never mind'),
            $this->fromAnthropic([new ToolCall('c2', 'read', ['path' => 'b.php'])]),
        ]));

        $results = [];

        foreach ($body['messages'] as $message) {
            foreach ($message['content'] as $block) {
                if (($block['type'] ?? null) === 'tool_result') {
                    $results[] = $block['tool_use_id'];
                }
            }
        }

        $this->assertSame(['c1', 'c2'], $results, 'one each, in order, and no repeats');
    }

    public function testAConversationThatEndsOnADanglingCallGetsOneToo(): void
    {
        // Upstream's closing `closePendingToolCalls()` after the loop, which `fillOrphanedCalls()`
        // has as its trailing `$flush();`. The mutation sweep deleted that line and nothing in the
        // suite noticed — the case above has a user message after the call, so it goes through
        // the in-loop flush and says nothing about the end of the list.
        //
        // How somebody gets here: escape during a tool call, then `/model`, which sends the
        // conversation exactly as it stands.
        $body = $this->sendAndCapture(new Context([
            new UserMessage('hi'),
            $this->fromAnthropic([new ToolCall('c1', 'read', ['path' => 'a.php'])]),
        ]));

        $last = $body['messages'][count($body['messages']) - 1];

        $this->assertSame('user', $last['role']);
        $this->assertSame('tool_result', $last['content'][0]['type']);
        $this->assertSame('c1', $last['content'][0]['tool_use_id']);
        $this->assertTrue($last['content'][0]['is_error']);
    }

    /** @return iterable<string, array{StopReason}> */
    public static function failedStops(): iterable
    {
        yield 'aborted' => [StopReason::Aborted];
        yield 'error' => [StopReason::Error];
    }

    #[DataProvider('failedStops')]
    public function testAFailedTurnIsNotReplayedAndNeitherAreItsCalls(StopReason $stop): void
    {
        // Upstream's `transformMessages()` skips an assistant turn that errored or was aborted,
        // whole: it is incomplete — reasoning with nothing after it, a call cut off mid-arguments
        // — and the model retries from the last turn that finished. pig replayed it, partial text
        // and all, and invented a "No result provided" for a call the model never finished making.
        $body = $this->sendAndCapture(new Context([
            new UserMessage('hi'),
            $this->fromAnthropic([new TextContent('half an ans'), new ToolCall('c1', 'read', ['pa' => ''])], $stop),
            new UserMessage('try again'),
        ]));

        $this->assertSame(['user', 'user'], array_column($body['messages'], 'role'));
        $this->assertStringNotContainsString('half an ans', (string) json_encode($body));
        $this->assertStringNotContainsString('c1', (string) json_encode($body), 'no call, and so no invented result');
    }

    public function testCallsPendingFromBeforeAFailedTurnAreStillAnswered(): void
    {
        // Upstream closes the pending calls *before* it checks the stop reason, so skipping the
        // failed turn does not skip the flush it triggers: the earlier turn's dangling call still
        // gets its result, ahead of the next real message.
        $body = $this->sendAndCapture(new Context([
            new UserMessage('hi'),
            $this->fromAnthropic([new ToolCall('c1', 'read', ['path' => 'a.php'])]),
            $this->fromAnthropic([new TextContent('')], StopReason::Error),
            new UserMessage('try again'),
        ]));

        $this->assertSame(['user', 'assistant', 'user', 'user'], array_column($body['messages'], 'role'));
        $this->assertSame('tool_result', $body['messages'][2]['content'][0]['type']);
        $this->assertSame('c1', $body['messages'][2]['content'][0]['tool_use_id']);
        $this->assertTrue($body['messages'][2]['content'][0]['is_error']);
    }

    /** @param list<mixed> $content */
    private function fromGoogle(array $content): AssistantMessage
    {
        return new AssistantMessage(
            $content,
            Api::GoogleGenerativeAi,
            'google',
            'gemini-2.5-pro',
            new Usage(),
            StopReason::Stop,
        );
    }

    /** @param list<mixed> $content */
    private function fromAnthropic(array $content, StopReason $stop = StopReason::ToolUse): AssistantMessage
    {
        return new AssistantMessage(
            $content,
            Api::AnthropicMessages,
            'anthropic',
            'claude-sonnet-4-5',
            new Usage(),
            $stop,
        );
    }

    /** @return array<string, mixed> the request body this provider sent */
    public function testATemperatureSomebodySetReachesTheRequest(): void
    {
        // **Nothing followed this option to the wire in any provider.** The mutation sweep over
        // `packages/ai/src/Providers` deleted the line that sets it in all five and no test
        // noticed, so `SimpleStreamOptions(temperature: …)` could have stopped reaching any
        // endpoint without a single case going red.
        $body = $this->sendAndCapture(new Context([new UserMessage('hi')]), 0.2);

        $this->assertSame(0.2, $body['temperature']);

        // And absent means absent, rather than a zero the provider would read as a setting.
        $this->assertArrayNotHasKey('temperature', $this->sendAndCapture(new Context([new UserMessage('hi')])));
    }

    public function testAToolCallWithNoArgumentsGoesOutAsAnObject(): void
    {
        // `input: []` is a list to Anthropic and it refuses the request — which is the trap entry
        // on an empty arguments list, whose fix touched four sites. **This one, on the provider
        // that does the refusing, had no test**: mutating `=== []` to `!== []` here passed the
        // whole suite.
        $body = $this->sendAndCapture(new Context([
            new UserMessage('hi'),
            $this->fromAnthropic([new ToolCall('c1', 'now', [])]),
            new ToolResultMessage('c1', 'now', [new TextContent('12:00')]),
        ]));

        $this->assertSame('tool_use', $body['messages'][1]['content'][0]['type']);

        // **Asserted on the raw body, because the decode cannot tell them apart**: PHP has one
        // array type, so `json_decode(assoc: true)` turns both `{}` and `[]` into the same value
        // — which is the third shape from `CLAUDE.md`'s index, and the reason a test that only
        // read `receivedJson()` would have passed either way.
        $this->assertStringContainsString('"input":{}', $this->server->received());
        $this->assertStringNotContainsString('"input":[]', $this->server->received());
    }

    private function sendAndCapture(Context $context, ?float $temperature = null): array
    {
        $url = $this->serveStream([['message_delta', ['delta' => ['stop_reason' => 'end_turn'], 'usage' => []]]]);
        $options = new AnthropicOptions(apiKey: 'test-key', temperature: $temperature);

        Async::run(function () use ($url, $context, $options): void {
            foreach ($this->anthropic()->stream($this->model($url), $context, $options) as $ignored) {
            }
        });

        return $this->server->receivedJson();
    }

    /** @return array{0: list<string>, 1: AssistantMessage} */
    private function collect(string $url, Context $context, ?Pricing $pricing = null): array
    {
        return Async::run(function () use ($url, $context, $pricing): array {
            $stream = $this->anthropic()->stream($this->model($url, $pricing), $context, $this->options());
            $types = [];

            foreach ($stream as $event) {
                $types[] = (new \ReflectionClass($event))->getShortName();
            }

            return [$types, $stream->result()->await()];
        });
    }

    private function anthropic(): Anthropic
    {
        return new Anthropic();
    }

    private function options(?AbortController $controller = null): AnthropicOptions
    {
        return new AnthropicOptions(apiKey: 'test-key', signal: $controller?->signal);
    }

    private function model(
        string $baseUrl = 'http://127.0.0.1:1',
        ?Pricing $pricing = null,
        string $id = 'claude-sonnet-4-5',
        OpenAiCompat|AnthropicCompat|null $compat = null,
    ): Model {
        return new Model(
            $id,
            'Claude Sonnet 4.5',
            Api::AnthropicMessages,
            'anthropic',
            rtrim($baseUrl, '/'),
            200_000,
            63_000,
            reasoning: true,
            pricing: $pricing ?? new Pricing(input: 3.0, output: 15.0),
            compat: $compat,
        );
    }

    /**
     * One request to the canned server with whatever model and options a test needs; the model's
     * base URL is replaced with the server's.
     *
     * @return array{0: string, 1: array<string, mixed>} the head and the decoded body that went out
     */
    private function capture(Model $model, Context $context, AnthropicOptions $options): array
    {
        $url = $this->serveStream([['message_delta', ['delta' => ['stop_reason' => 'end_turn'], 'usage' => []]]]);
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
            foreach ($this->anthropic()->stream($model, $context, $options) as $ignored) {
            }
        });

        return [$this->server->receivedHead(), $this->server->receivedJson()];
    }

    /** @param list<array{0: string, 1: array<string, mixed>}> $events */
    private function serveStream(array $events): string
    {
        $pieces = ["HTTP/1.1 200 OK\r\nContent-Type: text/event-stream\r\nTransfer-Encoding: chunked\r\n\r\n"];

        foreach ($events as [$type, $data]) {
            $pieces[] = $this->chunk("event: {$type}\ndata: " . json_encode(['type' => $type] + $data) . "\n\n");
        }

        $pieces[] = "0\r\n\r\n";

        return $this->server->start($pieces);
    }

    private function chunk(string $body): string
    {
        return sprintf("%x\r\n%s\r\n", strlen($body), $body);
    }
}
