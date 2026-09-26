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
        $this->assertStringContainsString('anthropic-beta: oauth-2025-04-20', $head);

        // And the identity the token was issued to has to be the first thing in the system
        // prompt, ahead of whatever this session's own prompt says.
        $this->assertSame("You are Claude Code, Anthropic's official CLI for Claude.", $body['system'][0]['text']);
        $this->assertSame('be brief', $body['system'][1]['text']);
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
        // Sending the beta without the token would be claiming an identity this request has
        // no right to, and the system block goes with it.
        $this->assertStringNotContainsString('oauth-2025-04-20', $head);
        $this->assertSame('be brief', $this->server->receivedJson()['system'][0]['text']);
    }

    public function testAgainstTheRealApi(): void
    {
        if (getenv('PIG_NETWORK_TESTS') !== '1') {
            self::markTestSkipped('set PIG_NETWORK_TESTS=1 to run tests that reach the internet');
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
    private function fromAnthropic(array $content): AssistantMessage
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

    /** @return array<string, mixed> the request body this provider sent */
    private function sendAndCapture(Context $context): array
    {
        $url = $this->serveStream([['message_delta', ['delta' => ['stop_reason' => 'end_turn'], 'usage' => []]]]);

        Async::run(function () use ($url, $context): void {
            foreach ($this->anthropic()->stream($this->model($url), $context, $this->options()) as $ignored) {
            }
        });

        return $this->server->receivedJson();
    }

    /** @return array{0: list<string>, 1: AssistantMessage} */
    private function collect(string $url, Context $context): array
    {
        return Async::run(function () use ($url, $context): array {
            $stream = $this->anthropic()->stream($this->model($url), $context, $this->options());
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

    private function model(string $baseUrl = 'http://127.0.0.1:1'): Model
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
            pricing: new Pricing(input: 3.0, output: 15.0),
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
