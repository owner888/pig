<?php

declare(strict_types=1);

namespace Pig\Ai\Test\Providers;

use Closure;
use PHPUnit\Framework\TestCase;
use Pig\Ai\Api;
use Pig\Ai\AssistantMessage;
use Pig\Ai\Context;
use Pig\Ai\Model;
use Pig\Ai\Providers\OpenAiCodexResponses;
use Pig\Ai\Providers\OpenAiCodexResponsesOptions;
use Pig\Ai\StopReason;
use Pig\Ai\Stream;
use Pig\Ai\TextContent;
use Pig\Ai\UserMessage;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\Test\WebSocketServer;

/**
 * Codex's WebSocket transport, against a loopback server that speaks both it and SSE: upstream's
 * `auto` — the session's connection kept and continued with `previous_response_id`, SSE after a
 * handshake that fails or takes too long, and only SSE for the rest of that session; `sse` never
 * trying it; and a connection that closes once the answer has started failing the turn rather
 * than sending it again.
 */
final class OpenAiCodexWebSocketTest extends TestCase
{
    /** A token whose payload names account `acc_123`, as the recordings use. */
    private const string TOKEN = 'eyJhbGciOiJSUzI1NiIsInR5cCI6IkpXVCJ9.eyJodHRwczovL2FwaS5vcGVuYWkuY29tL2F1dGgiOnsiY2hhdGdwdF9hY2NvdW50X2lkIjoiYWNjXzEyMyJ9LCJleHAiOjF9.sig';

    private WebSocketServer $server;

    #[\Override]
    protected function setUp(): void
    {
        Loop::reset();
        OpenAiCodexResponses::closeWebSocketSessions();
        OpenAiCodexResponses::resetWebSocketDebugStats();
        $this->server = new WebSocketServer();
    }

    #[\Override]
    protected function tearDown(): void
    {
        OpenAiCodexResponses::closeWebSocketSessions();
        OpenAiCodexResponses::resetWebSocketDebugStats();
        $this->server->stop();
    }

    public function testAutoAnswersOverTheWebSocketWithItsOwnHeaders(): void
    {
        $base = $this->server->start($this->answering(['Hello']));
        [$message] = $this->converse($base, [['Hi']], 'sess-1');

        self::assertSame(StopReason::Stop, $message->stopReason);
        self::assertSame('Hello', self::text($message));
        self::assertSame([], $this->server->httpRequests);
        self::assertCount(1, $this->server->upgrades);

        $headers = $this->server->upgrades[0];
        self::assertSame('responses_websockets=2026-02-06', $headers['openai-beta']);
        self::assertSame('sess-1', $headers['session-id']);
        self::assertSame('sess-1', $headers['x-client-request-id']);
        self::assertSame('Bearer ' . self::TOKEN, $headers['authorization']);
        self::assertSame('acc_123', $headers['chatgpt-account-id']);
        self::assertArrayNotHasKey('accept', $headers);
        self::assertArrayNotHasKey('content-type', $headers);

        $sent = $this->server->bodies()[0];
        self::assertSame('response.create', $sent['type']);
        self::assertSame('gpt-5.5', $sent['model']);
        self::assertFalse($sent['store']);
        self::assertArrayNotHasKey('previous_response_id', $sent);
    }

    public function testTheNextTurnOfTheSessionReusesTheConnectionAndSendsOnlyWhatIsNew(): void
    {
        $base = $this->server->start($this->answering(['First', 'Second']));
        [$first, $second] = $this->converse($base, [['Hi'], ['Hi', 'First', 'And then?']], 'sess-2');

        self::assertSame('First', self::text($first));
        self::assertSame('Second', self::text($second));
        self::assertCount(1, $this->server->upgrades, 'one connection for both turns');
        self::assertSame([0, 0], array_column($this->server->messages, 'connection'));

        [$full, $delta] = $this->server->bodies();
        self::assertArrayNotHasKey('previous_response_id', $full);
        self::assertSame('resp_0', $delta['previous_response_id']);
        self::assertCount(1, $delta['input'], 'only the new user message');
        self::assertSame('user', $delta['input'][0]['role']);

        $stats = OpenAiCodexResponses::webSocketDebugStats('sess-2');
        self::assertSame(2, $stats['requests']);
        self::assertSame(1, $stats['connectionsCreated']);
        self::assertSame(1, $stats['connectionsReused']);
        self::assertSame(1, $stats['deltaRequests']);
        self::assertSame(1, $stats['fullContextRequests']);
    }

    public function testPlainWebSocketSendsTheWholeConversationEachTime(): void
    {
        $base = $this->server->start($this->answering(['First', 'Second']));
        $this->converse($base, [['Hi'], ['Hi', 'First', 'And then?']], 'sess-3', 'websocket');

        [, $second] = $this->server->bodies();
        self::assertArrayNotHasKey('previous_response_id', $second);
        self::assertCount(3, $second['input']);
    }

    public function testARefusedHandshakeFallsBackToSseAndTheSessionStaysThere(): void
    {
        $this->server->upgrade = 'refuse';
        $base = $this->server->start($this->answering([]), self::sse('Over SSE'));
        [$first, $second] = $this->converse($base, [['Hi'], ['Hi', 'Over SSE', 'Again']], 'sess-4');

        self::assertSame('Over SSE', self::text($first));
        self::assertSame('Over SSE', self::text($second));
        self::assertCount(1, $this->server->upgrades, 'not tried again for the session');
        self::assertCount(2, $this->server->httpRequests);

        $diagnostic = $first->diagnostics[0] ?? null;
        self::assertNotNull($diagnostic);
        self::assertSame('provider_transport_failure', $diagnostic->type);
        self::assertSame('Received network error or non-101 status code.', $diagnostic->error?->message);
        self::assertSame('auto', $diagnostic->details['configuredTransport']);
        self::assertSame('sse', $diagnostic->details['fallbackTransport']);
        self::assertSame('before_message_stream_start', $diagnostic->details['phase']);
        self::assertNull($second->diagnostics);

        $stats = OpenAiCodexResponses::webSocketDebugStats('sess-4');
        self::assertSame(1, $stats['websocketFailures']);
        self::assertSame(2, $stats['sseFallbacks']);
        self::assertTrue($stats['websocketFallbackActive']);
    }

    public function testAHandshakeThatNeverAnswersTimesOutIntoSse(): void
    {
        $this->server->upgrade = 'ignore';
        $base = $this->server->start($this->answering([]), self::sse('Late'));
        [$message] = $this->converse($base, [['Hi']], 'sess-5', websocketConnectTimeoutMs: 100);

        self::assertSame('Late', self::text($message));
        self::assertSame('WebSocket connect timeout after 100ms', $message->diagnostics[0]->error?->message);
    }

    public function testSseNeverTriesTheWebSocket(): void
    {
        $base = $this->server->start($this->answering([]), self::sse('Plain'));
        [$message] = $this->converse($base, [['Hi']], 'sess-6', 'sse');

        self::assertSame('Plain', self::text($message));
        self::assertSame([], $this->server->upgrades);
    }

    public function testAConnectionThatClosesAfterTheAnswerStartedFailsTheTurn(): void
    {
        $base = $this->server->start(static function (mixed $message, Closure $send, Closure $close): void {
            $send(['type' => 'response.created', 'response' => ['id' => 'resp_x']]);
            $close(1011, 'boom');
        }, self::sse('never'));
        [$message] = $this->converse($base, [['Hi']], 'sess-7');

        self::assertSame(StopReason::Error, $message->stopReason);
        self::assertSame('WebSocket closed 1011 boom', $message->errorMessage);
        self::assertSame([], $this->server->httpRequests, 'not sent again over SSE');
        self::assertSame('after_message_stream_start', $message->diagnostics[0]->details['phase']);
    }

    public function testAMissingContinuationIsSentAgainInFull(): void
    {
        $turn = 0;
        $base = $this->server->start(function (mixed $message, Closure $send) use (&$turn): void {
            if (isset($message['previous_response_id'])) {
                $send(['type' => 'error', 'code' => 'previous_response_not_found', 'message' => 'gone']);

                return;
            }

            self::reply($send, 'resp_' . $turn, $turn === 0 ? 'First' : 'Second');
            $turn++;
        });
        [, $second] = $this->converse($base, [['Hi'], ['Hi', 'First', 'And then?']], 'sess-8');

        self::assertSame('Second', self::text($second));
        $bodies = $this->server->bodies();
        self::assertCount(3, $bodies);
        self::assertArrayHasKey('previous_response_id', $bodies[1]);
        self::assertArrayNotHasKey('previous_response_id', $bodies[2]);
        self::assertCount(3, $bodies[2]['input']);
    }

    /**
     * A server that answers each request with the next of $texts, as `resp_<n>`.
     *
     * @param list<string> $texts
     */
    private function answering(array $texts): Closure
    {
        $turn = 0;

        return static function (mixed $message, Closure $send) use (&$turn, $texts): void {
            self::reply($send, 'resp_' . $turn, $texts[$turn] ?? '');
            $turn++;
        };
    }

    private static function reply(Closure $send, string $id, string $text): void
    {
        foreach (self::events($id, $text) as $event) {
            $send($event);
        }
    }

    /** @return list<array<string, mixed>> */
    private static function events(string $id, string $text): array
    {
        $item = ['type' => 'message', 'id' => "msg_{$id}", 'role' => 'assistant', 'content' => []];

        return [
            ['type' => 'response.created', 'response' => ['id' => $id]],
            ['type' => 'response.output_item.added', 'output_index' => 0, 'item' => $item],
            ['type' => 'response.output_text.delta', 'output_index' => 0, 'delta' => $text],
            ['type' => 'response.output_item.done', 'output_index' => 0, 'item' => [...$item, 'content' => [['type' => 'output_text', 'text' => $text]]]],
            ['type' => 'response.completed', 'response' => ['id' => $id, 'status' => 'completed', 'usage' => ['input_tokens' => 10, 'output_tokens' => 2, 'total_tokens' => 12]]],
        ];
    }

    private static function sse(string $text): Closure
    {
        return static fn (array $request): array => [
            200,
            ['content-type' => 'text/event-stream'],
            implode('', array_map(static fn (array $event): string => 'data: ' . json_encode($event) . "\n\n", self::events('resp_sse', $text))),
        ];
    }

    /**
     * Each turn's conversation — user, assistant, user… — sent in turn on one session.
     *
     * @param list<list<string>> $turns
     * @return list<AssistantMessage>
     */
    private function converse(string $base, array $turns, string $sessionId, ?string $transport = null, ?int $websocketConnectTimeoutMs = null): array
    {
        $model = new Model('gpt-5.5', 'GPT-5.5', Api::OpenAiCodexResponses, 'openai-codex', "{$base}/backend-api", 272000, 128000);
        $results = [];
        $answers = [];

        Async::run(function () use ($model, $turns, $sessionId, $transport, $websocketConnectTimeoutMs, &$results, &$answers): void {
            foreach ($turns as $turn) {
                $messages = [];

                foreach ($turn as $i => $text) {
                    $messages[] = $i % 2 === 0 ? new UserMessage($text) : $answers[intdiv($i, 2)];
                }

                $stream = Stream::start($model, new Context($messages, 'Be brief.'), new OpenAiCodexResponsesOptions(
                    apiKey: self::TOKEN,
                    sessionId: $sessionId,
                    transport: $transport,
                    websocketConnectTimeoutMs: $websocketConnectTimeoutMs,
                ));

                foreach ($stream as $event) {
                    // drained for the result
                }

                $message = $stream->result()->await();
                $results[] = $message;
                $answers[] = $message;
            }
        });

        return $results;
    }

    private static function text(AssistantMessage $message): string
    {
        return implode('', array_map(
            static fn (mixed $block): string => $block instanceof TextContent ? $block->text : '',
            $message->content,
        ));
    }
}
