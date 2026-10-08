<?php

declare(strict_types=1);

namespace Pig\Ai\Test\Providers;

use PHPUnit\Framework\TestCase;
use Pig\Ai\Api;
use Pig\Ai\AssistantMessage;
use Pig\Ai\Context;
use Pig\Ai\DoneEvent;
use Pig\Ai\ErrorEvent;
use Pig\Ai\Model;
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
                    new ToolResultMessage('call_a!b', 'read', [new TextContent('file body')]),
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
        $this->assertStringContainsString('fine-grained-tool-streaming', $head);

        $this->assertSame('claude-sonnet-4-5', $body['model']);
        $this->assertTrue($body['stream']);
        // maxTokens unset, so a third of the model's ceiling.
        $this->assertSame(21_000, $body['max_tokens']);
        $this->assertSame('be brief', $body['system'][0]['text']);
        $this->assertSame('ephemeral', $body['system'][0]['cache_control']['type']);
        $this->assertSame('read', $body['tools'][0]['name']);
        $this->assertSame(['path'], $body['tools'][0]['input_schema']['required']);

        // Consecutive tool results collapse into one user turn, and ids are scrubbed to
        // the character set Anthropic accepts.
        $this->assertCount(2, $body['messages']);
        $this->assertSame('user', $body['messages'][1]['role']);
        $this->assertCount(2, $body['messages'][1]['content']);
        $this->assertSame('call_a_b', $body['messages'][1]['content'][0]['tool_use_id']);
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
        // — not turned into `<thinking>` text, since its only text is the placeholder.
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

        $this->assertSame(['thinking', 'text'], array_column($body['messages'][1]['content'], 'type'));
        $this->assertStringNotContainsString('ENCRYPTED-BLOB', (string) json_encode($body));

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

    public function testAnotherProvidersThinkingGoesBackAsTaggedTextRatherThanAsSignedThinking(): void
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
        $this->assertSame('text', $assistant['content'][0]['type']);
        $this->assertStringContainsString('<thinking>', $assistant['content'][0]['text']);
        $this->assertStringNotContainsString('GEMINI-SIG', (string) json_encode($body));
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
        // **The one place this file deliberately does more than upstream, and it had no test.**
        // Upstream inserts a synthetic result only when a *later* message arrives, so a
        // conversation that ends on a dangling call is unsendable there; `fillOrphanedCalls()`
        // flushes once more after the loop. The mutation sweep deleted that trailing `$flush();`
        // and nothing in the suite noticed — the case above has a user message after the call, so
        // it goes through the in-loop flush and says nothing about the end of the list.
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

    private function model(string $baseUrl = 'http://127.0.0.1:1', ?Pricing $pricing = null): Model
    {
        return new Model(
            'claude-sonnet-4-5',
            'Claude Sonnet 4.5',
            Api::AnthropicMessages,
            'anthropic',
            rtrim($baseUrl, '/'),
            200_000,
            63_000,
            reasoning: true,
            pricing: $pricing ?? new Pricing(input: 3.0, output: 15.0),
        );
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
