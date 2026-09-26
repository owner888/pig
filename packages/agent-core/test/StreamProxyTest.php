<?php

declare(strict_types=1);

namespace Pig\Agent\Test;

use PHPUnit\Framework\TestCase;
use Closure;
use Pig\Agent\AgentContext;
use Pig\Agent\AgentLoop;
use Pig\Agent\AgentLoopConfig;
use Pig\Agent\AgentTool;
use Pig\Agent\AgentToolResult;
use Pig\Agent\StreamProxy;
use Pig\Ai\Api;
use Pig\Ai\AssistantMessage;
use Pig\Ai\Context;
use Pig\Ai\DoneEvent;
use Pig\Ai\ErrorEvent;
use Pig\Ai\Http\HttpClient;
use Pig\Ai\Model;
use Pig\Ai\OpenAiCompat;
use Pig\Ai\Pricing;
use Pig\Ai\ReasoningEffort;
use Pig\Ai\SimpleStreamOptions;
use Pig\Ai\StopReason;
use Pig\Ai\TextContent;
use Pig\Ai\TextDeltaEvent;
use Pig\Ai\TextEndEvent;
use Pig\Ai\ThinkingContent;
use Pig\Ai\ToolCall;
use Pig\Ai\ToolCallEndEvent;
use Pig\Ai\ToolResultMessage;
use Pig\Ai\UserMessage;
use Pig\Ai\Tool;
use Pig\Ai\Usage;
use Pig\Async\AbortController;
use Pig\Async\AbortSignal;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\Test\CannedServer;
use RuntimeException;

/** The tool the gateway asks for in the loop case, so the arguments it was handed can be checked. */
final class GatewayTool implements AgentTool
{
    /** @var array<string, mixed> */
    public array $calledWith = [];

    public function definition(): Tool
    {
        return new Tool('read', 'Read a file', [
            'type' => 'object',
            'properties' => ['path' => ['type' => 'string']],
            'required' => ['path'],
        ]);
    }

    public function label(): string
    {
        return 'Read';
    }

    public function execute(
        string $toolCallId,
        array $arguments,
        ?AbortSignal $signal = null,
        ?Closure $onUpdate = null,
    ): AgentToolResult {
        $this->calledWith = $arguments;

        return new AgentToolResult([new TextContent('hello')]);
    }
}

/**
 * Talking to a gateway instead of to a provider — upstream's `agent/proxy.ts`.
 *
 * The events a gateway sends are upstream's, with the `partial` field stripped to save bandwidth,
 * so the thing under test is the rebuilding: pig has to end up with the message the provider would
 * have produced, out of events that carry only deltas.
 */
final class StreamProxyTest extends TestCase
{
    private CannedServer $server;

    /** @var list<resource> */
    private array $listening = [];

    /** Every request body the gateway received, decoded, in order. */
    private array $gatewayRequests = [];

    #[\Override]
    protected function setUp(): void
    {
        Loop::reset();
        HttpClient::useProxy(null);
        $this->server = new CannedServer();
        $this->listening = [];
        $this->gatewayRequests = [];
    }

    #[\Override]
    protected function tearDown(): void
    {
        foreach ($this->listening as $stream) {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $this->listening = [];
    }

    private static function model(?OpenAiCompat $compat = null, array $headers = []): Model
    {
        return new Model(
            'gateway-model',
            'Gateway Model',
            Api::AnthropicMessages,
            'anthropic',
            'https://api.anthropic.com',
            200_000,
            8_192,
            true,
            ['text', 'image'],
            new Pricing(3.0, 15.0, 0.3, 3.75),
            $headers,
            $compat,
        );
    }

    /** @param list<array<string, mixed>> $events */
    private static function sse(array $events): string
    {
        $body = '';

        foreach ($events as $event) {
            // Back to back, with no blank line between them — which is what upstream's client
            // reads and what a strict SSE parser would get wrong.
            $body .= 'data: ' . json_encode($event) . "\n";
        }

        return "HTTP/1.1 200 OK\r\nContent-Type: text/event-stream\r\n"
            . 'Content-Length: ' . strlen($body) . "\r\n\r\n" . $body;
    }

    /**
     * @param list<array<string, mixed>> $events
     * @return array{0: AssistantMessage, 1: list<object>}
     */
    private function turn(array $events, ?Context $context = null, ?SimpleStreamOptions $options = null): array
    {
        $url = $this->server->start([self::sse($events)]);
        $proxy = new StreamProxy(rtrim($url, '/'), 'token-abc');
        $model = self::model();
        $context = $context ?? new Context([new UserMessage([new TextContent('hi')])], 'be brief');

        return Async::run(static function () use ($proxy, $model, $context, $options): array {
            $stream = $proxy->stream($model, $context, $options);
            $seen = [];

            foreach ($stream as $event) {
                $seen[] = $event;
            }

            $message = $stream->result()->await();

            return [$message, $seen];
        });
    }

    private static function usage(): array
    {
        return [
            'input' => 11,
            'output' => 7,
            'cacheRead' => 0,
            'cacheWrite' => 0,
            'totalTokens' => 18,
            'cost' => ['input' => 0, 'output' => 0, 'cacheRead' => 0, 'cacheWrite' => 0, 'total' => 0],
        ];
    }

    // ---- the request ---------------------------------------------------------------------------

    public function testItPostsToApiStreamWithTheBearerToken(): void
    {
        $this->turn([['type' => 'done', 'reason' => 'stop', 'usage' => self::usage()]]);

        $head = $this->server->receivedHead();

        $this->assertStringContainsString('POST /api/stream HTTP/1.1', $head);
        $this->assertStringContainsString('authorization: Bearer token-abc', $head);
        $this->assertStringContainsString('content-type: application/json', $head);
    }

    public function testTheModelGoesOverTheWireInUpstreamsShape(): void
    {
        $this->turn([['type' => 'done', 'reason' => 'stop', 'usage' => self::usage()]]);

        $model = $this->server->receivedJson()['model'];

        // `cost`, not `pricing`: pig renamed the field and the wire keeps the name the server was
        // written against. It reads back as the int 3 because JSON has one number type and 3.0
        // encodes as `3` — upstream's `JSON.stringify` does exactly the same, so the wire matches.
        $this->assertSame(3, $model['cost']['input']);
        $this->assertSame(0.3, $model['cost']['cacheRead'], 'and a real fraction stays one');
        $this->assertArrayNotHasKey('pricing', $model);
        $this->assertSame('anthropic-messages', $model['api'], 'the enum as its own string');
        $this->assertSame('https://api.anthropic.com', $model['baseUrl']);
        $this->assertSame(['text', 'image'], $model['input']);
        $this->assertSame(200_000, $model['contextWindow']);
    }

    public function testHeadersAndCompatAreOnlySentWhenTheModelHasThem(): void
    {
        $this->turn([['type' => 'done', 'reason' => 'stop', 'usage' => self::usage()]]);

        $plain = $this->server->receivedJson()['model'];

        $this->assertArrayNotHasKey('headers', $plain);
        $this->assertArrayNotHasKey('compat', $plain);

        $this->setUp();
        $url = $this->server->start([self::sse([['type' => 'done', 'reason' => 'stop', 'usage' => self::usage()]])]);
        $proxy = new StreamProxy(rtrim($url, '/'), 't');
        $model = self::model(new OpenAiCompat(store: false, maxTokensField: 'max_tokens'), ['X-Org' => 'acme']);
        $context = new Context([new UserMessage([new TextContent('hi')])]);

        Async::run(static function () use ($proxy, $model, $context): void {
            foreach ($proxy->stream($model, $context) as $ignored) {
                // Drain.
            }
        });

        $sent = $this->server->receivedJson()['model'];

        $this->assertSame(['X-Org' => 'acme'], $sent['headers']);
        // Upstream's eight key names, all eight, so a `models.json` and this say the same thing.
        $this->assertFalse($sent['compat']['supportsStore']);
        $this->assertSame('max_tokens', $sent['compat']['maxTokensField']);
        $this->assertFalse($sent['compat']['requiresMistralToolIds']);
    }

    public function testTheContextGoesOverTheWireAsTheSessionFileWritesIt(): void
    {
        $context = new Context(
            [
                new UserMessage([new TextContent('read it')]),
                new AssistantMessage(
                    [new ToolCall('call-1', 'read', ['path' => 'a.txt'])],
                    Api::AnthropicMessages,
                    'anthropic',
                    'gateway-model',
                    new Usage(),
                    StopReason::ToolUse,
                ),
                new ToolResultMessage('call-1', 'read', [new TextContent('contents')], false, ['lines' => 1]),
            ],
            'be brief',
            [new Tool('read', 'Read a file', ['type' => 'object', 'required' => ['path']])],
        );

        $this->turn([['type' => 'done', 'reason' => 'stop', 'usage' => self::usage()]], $context);

        $sent = $this->server->receivedJson()['context'];

        // The same encoder the session file uses, which is the point of `MessageJson` existing.
        $this->assertSame('be brief', $sent['systemPrompt']);
        $this->assertSame(['user', 'assistant', 'toolResult'], array_column($sent['messages'], 'role'));
        $this->assertSame('toolCall', $sent['messages'][1]['content'][0]['type']);
        $this->assertSame(['path' => 'a.txt'], $sent['messages'][1]['content'][0]['arguments']);
        $this->assertSame('call-1', $sent['messages'][2]['toolCallId']);
        $this->assertSame(['lines' => 1], $sent['messages'][2]['details']);
        $this->assertSame([['name' => 'read', 'description' => 'Read a file', 'parameters' => [
            'type' => 'object',
            'required' => ['path'],
        ]]], $sent['tools']);
    }

    public function testAToolResultThatIsNotUtf8StillReachesTheGateway(): void
    {
        $context = new Context(
            [
                new UserMessage([new TextContent('cat the log')]),
                new ToolResultMessage('call-1', 'read', [new TextContent("header\n\x80stray\nfooter\n")], false),
            ],
            'be brief',
        );

        [$message] = $this->turn([['type' => 'done', 'reason' => 'stop', 'usage' => self::usage()]], $context);

        // `read` and `bash` hand back a file's own bytes, so one latin-1 log made
        // `json_encode` answer false and the turn died with "could not be encoded" — and
        // then so did every turn after it, because the result stays in the conversation.
        $this->assertSame(StopReason::Stop, $message->stopReason, $message->errorMessage ?? '');

        $text = $this->server->receivedJson()['context']['messages'][1]['content'][0]['text'];
        $this->assertStringContainsString('header', $text);
        $this->assertStringContainsString('footer', $text);
    }

    public function testTheOptionsGoOverAsThreeFields(): void
    {
        $this->turn(
            [['type' => 'done', 'reason' => 'stop', 'usage' => self::usage()]],
            null,
            new SimpleStreamOptions(temperature: 0.5, maxTokens: 900, reasoning: ReasoningEffort::High),
        );

        $options = $this->server->receivedJson()['options'];

        $this->assertSame(0.5, $options['temperature']);
        $this->assertSame(900, $options['maxTokens']);
        $this->assertSame('high', $options['reasoning'], 'the enum as its own string, as upstream sends it');
    }

    // ---- rebuilding the message ----------------------------------------------------------------

    public function testTextIsRebuiltFromDeltasThatCarryNoPartial(): void
    {
        [$message, $events] = $this->turn([
            ['type' => 'start'],
            ['type' => 'text_start', 'contentIndex' => 0],
            ['type' => 'text_delta', 'contentIndex' => 0, 'delta' => 'one '],
            ['type' => 'text_delta', 'contentIndex' => 0, 'delta' => 'two'],
            ['type' => 'text_end', 'contentIndex' => 0],
            ['type' => 'done', 'reason' => 'stop', 'usage' => self::usage()],
        ]);

        $this->assertSame(StopReason::Stop, $message->stopReason);
        $this->assertCount(1, $message->content);
        $this->assertInstanceOf(TextContent::class, $message->content[0]);
        $this->assertSame('one two', $message->content[0]->text);

        // Every event pig hands on carries a real partial, because that is what the agent loop and
        // the UI read; the gateway sent none.
        $deltas = array_values(array_filter($events, static fn (object $e): bool => $e instanceof TextDeltaEvent));
        $this->assertCount(2, $deltas);
        $this->assertSame('one ', $deltas[0]->partial->content[0]->text, 'the partial after the first delta');
        $this->assertSame('one two', $deltas[1]->partial->content[0]->text);
    }

    public function testTheUsageAndStopReasonComeFromDone(): void
    {
        [$message] = $this->turn([
            ['type' => 'start'],
            ['type' => 'text_start', 'contentIndex' => 0],
            ['type' => 'text_delta', 'contentIndex' => 0, 'delta' => 'x'],
            ['type' => 'text_end', 'contentIndex' => 0],
            ['type' => 'done', 'reason' => 'length', 'usage' => self::usage()],
        ]);

        $this->assertSame(StopReason::Length, $message->stopReason);
        $this->assertSame(11, $message->usage->input);
        $this->assertSame(7, $message->usage->output);
    }

    public function testTheGatewaysOwnCostIsKeptAndNotRepricedFromTheListPrice(): void
    {
        // The gateway knows what it paid; pig only knows what the model's public price list says.
        // Upstream assigns the usage whole for this reason, and the builder's repricing — right for
        // the four providers, which send no cost at all — was overwriting the one caller that sends
        // one. A gateway on its own deal was reported at list price, and one running flat-rate was
        // reported as owing money.
        $usage = [
            ...self::usage(),
            'cost' => ['input' => 0.5, 'output' => 0.25, 'cacheRead' => 0.0, 'cacheWrite' => 0.0, 'total' => 0.75],
        ];

        [$message] = $this->turn([['type' => 'done', 'reason' => 'stop', 'usage' => $usage]]);

        $this->assertSame(0.5, $message->usage->cost->input);
        $this->assertSame(0.25, $message->usage->cost->output);
        $this->assertSame(0.75, $message->usage->cost->total);
    }

    public function testAGatewayThatSaysNothingAboutCostIsReportedAsCostingNothing(): void
    {
        // The other side of trusting the wire, and upstream's contract: `usage.cost` is required of
        // a conforming gateway, so an absent one is taken at its word rather than guessed at. The
        // model's price list would say 0.000033 for these counts, which is a number pig made up.
        [$message] = $this->turn([['type' => 'done', 'reason' => 'stop', 'usage' => self::usage()]]);

        $this->assertSame(0.0, $message->usage->cost->total);
        $this->assertSame(0.0, $message->usage->cost->input);
    }

    public function testATokenTotalTheGatewayLeftOutIsStillFilledIn(): void
    {
        // Not part of the cost question: the counts are added up when the gateway sends no total,
        // the same as for a provider that omits it, because that one is arithmetic on numbers the
        // gateway did send.
        $usage = [...self::usage(), 'totalTokens' => 0];

        [$message] = $this->turn([['type' => 'done', 'reason' => 'stop', 'usage' => $usage]]);

        $this->assertSame(18, $message->usage->totalTokens);
    }

    public function testThinkingKeepsItsSignature(): void
    {
        [$message] = $this->turn([
            ['type' => 'thinking_start', 'contentIndex' => 0],
            ['type' => 'thinking_delta', 'contentIndex' => 0, 'delta' => 'hmm'],
            ['type' => 'thinking_end', 'contentIndex' => 0, 'contentSignature' => 'sig-1'],
            ['type' => 'done', 'reason' => 'stop', 'usage' => self::usage()],
        ]);

        $this->assertInstanceOf(ThinkingContent::class, $message->content[0]);
        $this->assertSame('hmm', $message->content[0]->thinking);
        // One field closes text and thinking alike upstream; the block's own type decides what it
        // means, and losing it means the next turn is a different message to Anthropic.
        $this->assertSame('sig-1', $message->content[0]->thinkingSignature);
    }

    public function testTextKeepsItsSignatureToo(): void
    {
        [$message] = $this->turn([
            ['type' => 'text_start', 'contentIndex' => 0],
            ['type' => 'text_delta', 'contentIndex' => 0, 'delta' => 'x'],
            ['type' => 'text_end', 'contentIndex' => 0, 'contentSignature' => 'msg-7'],
            ['type' => 'done', 'reason' => 'stop', 'usage' => self::usage()],
        ]);

        $this->assertInstanceOf(TextContent::class, $message->content[0]);
        $this->assertSame('msg-7', $message->content[0]->textSignature);
    }

    public function testAToolCallIsRebuiltFromItsJsonDeltas(): void
    {
        [$message, $events] = $this->turn([
            ['type' => 'toolcall_start', 'contentIndex' => 0, 'id' => 'call-9', 'toolName' => 'read'],
            ['type' => 'toolcall_delta', 'contentIndex' => 0, 'delta' => '{"path":"a'],
            ['type' => 'toolcall_delta', 'contentIndex' => 0, 'delta' => '.txt"}'],
            ['type' => 'toolcall_end', 'contentIndex' => 0],
            ['type' => 'done', 'reason' => 'toolUse', 'usage' => self::usage()],
        ]);

        $this->assertSame(StopReason::ToolUse, $message->stopReason);
        $this->assertInstanceOf(ToolCall::class, $message->content[0]);
        $this->assertSame('call-9', $message->content[0]->id);
        $this->assertSame('read', $message->content[0]->name);
        $this->assertSame(['path' => 'a.txt'], $message->content[0]->arguments);

        // `toolcall_end` carries only the index on the wire — the tool call it names is the one pig
        // built, which is the whole reason the deltas had to be accumulated rather than forwarded.
        $ends = array_values(array_filter($events, static fn (object $e): bool => $e instanceof ToolCallEndEvent));
        $this->assertSame('read', $ends[0]->toolCall->name);
    }

    public function testAHalfFinishedToolCallStillParses(): void
    {
        // The reason `PartialJson` exists: a UI shows arguments while they stream.
        [, $events] = $this->turn([
            ['type' => 'toolcall_start', 'contentIndex' => 0, 'id' => 'c', 'toolName' => 'read'],
            ['type' => 'toolcall_delta', 'contentIndex' => 0, 'delta' => '{"path":"a'],
            ['type' => 'toolcall_end', 'contentIndex' => 0],
            ['type' => 'done', 'reason' => 'toolUse', 'usage' => self::usage()],
        ]);

        $ends = array_values(array_filter($events, static fn (object $e): bool => $e instanceof ToolCallEndEvent));

        $this->assertSame(['path' => 'a'], $ends[0]->toolCall->arguments);
    }

    public function testSeveralBlocksKeepTheirOwnIndexes(): void
    {
        [$message] = $this->turn([
            ['type' => 'thinking_start', 'contentIndex' => 0],
            ['type' => 'thinking_delta', 'contentIndex' => 0, 'delta' => 'think'],
            ['type' => 'thinking_end', 'contentIndex' => 0],
            ['type' => 'text_start', 'contentIndex' => 1],
            ['type' => 'text_delta', 'contentIndex' => 1, 'delta' => 'say'],
            ['type' => 'text_end', 'contentIndex' => 1],
            ['type' => 'toolcall_start', 'contentIndex' => 2, 'id' => 'c', 'toolName' => 'bash'],
            ['type' => 'toolcall_delta', 'contentIndex' => 2, 'delta' => '{"command":"ls"}'],
            ['type' => 'toolcall_end', 'contentIndex' => 2],
            ['type' => 'done', 'reason' => 'toolUse', 'usage' => self::usage()],
        ]);

        $this->assertInstanceOf(ThinkingContent::class, $message->content[0]);
        $this->assertInstanceOf(TextContent::class, $message->content[1]);
        $this->assertInstanceOf(ToolCall::class, $message->content[2]);
        $this->assertSame(['command' => 'ls'], $message->content[2]->arguments);
    }

    // ---- when it goes wrong --------------------------------------------------------------------

    public function testAnErrorEventBecomesAFailedMessageAndNotAnException(): void
    {
        [$message, $events] = $this->turn([
            ['type' => 'text_start', 'contentIndex' => 0],
            ['type' => 'text_delta', 'contentIndex' => 0, 'delta' => 'half '],
            ['type' => 'error', 'reason' => 'error', 'errorMessage' => 'upstream rate limit', 'usage' => self::usage()],
        ]);

        // A stream function never throws at its caller; the failure is the result.
        $this->assertSame(StopReason::Error, $message->stopReason);
        $this->assertSame('upstream rate limit', $message->errorMessage);
        $this->assertSame('half ', $message->content[0]->text, 'and what had arrived is kept');
        $this->assertInstanceOf(ErrorEvent::class, $events[count($events) - 1]);
    }

    public function testAnAbortedErrorEventIsAbortedAndNotAnError(): void
    {
        [$message] = $this->turn([
            ['type' => 'error', 'reason' => 'aborted', 'errorMessage' => 'cancelled', 'usage' => self::usage()],
        ]);

        // The agent loop treats the two differently: an abort is a turn somebody stopped, an error
        // is one that failed, and only the second is worth retrying.
        $this->assertSame(StopReason::Aborted, $message->stopReason);
    }

    public function testANon2xxCarriesTheServersOwnMessage(): void
    {
        $url = $this->server->start([
            "HTTP/1.1 402 Payment Required\r\nContent-Type: application/json\r\nContent-Length: 30\r\n\r\n"
            . '{"error":"quota exhausted"}   ',
        ]);
        $proxy = new StreamProxy(rtrim($url, '/'), 't');
        $model = self::model();
        $context = new Context([new UserMessage([new TextContent('hi')])]);

        $message = Async::run(static function () use ($proxy, $model, $context): AssistantMessage {
            $stream = $proxy->stream($model, $context);

            foreach ($stream as $ignored) {
                // Drain.
            }

            return $stream->result()->await();
        });

        // Upstream's wording, because a gateway's own message is the useful half.
        $this->assertSame(StopReason::Error, $message->stopReason);
        $this->assertSame('Proxy error: quota exhausted', $message->errorMessage);
    }

    public function testANon2xxWithNoErrorFieldNamesTheStatus(): void
    {
        $url = $this->server->start(["HTTP/1.1 502 Bad Gateway\r\nContent-Length: 4\r\n\r\nnope"]);
        $proxy = new StreamProxy(rtrim($url, '/'), 't');
        $model = self::model();
        $context = new Context([new UserMessage([new TextContent('hi')])]);

        $message = Async::run(static function () use ($proxy, $model, $context): AssistantMessage {
            $stream = $proxy->stream($model, $context);

            foreach ($stream as $ignored) {
                // Drain.
            }

            return $stream->result()->await();
        });

        $this->assertSame('Proxy error: 502', $message->errorMessage);
    }

    public function testAStreamThatEndsWithoutDoneIsAFailureAndNotASuccess(): void
    {
        [$message] = $this->turn([
            ['type' => 'text_start', 'contentIndex' => 0],
            ['type' => 'text_delta', 'contentIndex' => 0, 'delta' => 'half'],
        ]);

        // The failure this rules out: `stopReason` defaults to `stop`, so a gateway that died
        // mid-sentence would otherwise look like a model that finished one.
        $this->assertSame(StopReason::Error, $message->stopReason);
        $this->assertStringContainsString('without a done event', (string) $message->errorMessage);
    }

    public function testADeltaForABlockNobodyOpenedIsNamed(): void
    {
        [$message] = $this->turn([
            ['type' => 'text_delta', 'contentIndex' => 3, 'delta' => 'x'],
        ]);

        // Upstream throws `Received text_delta for non-text content` and its catch turns that into
        // an error event. Same shape, one step earlier: a block nobody opened has no index at all.
        $this->assertSame(StopReason::Error, $message->stopReason);
        $this->assertStringContainsString('text_delta for a block it never opened (3)', (string) $message->errorMessage);
    }

    public function testALineThatIsNotJsonIsSkippedRatherThanFatal(): void
    {
        $body = "data: not json at all\n"
            . ': a keep-alive comment' . "\n"
            . 'data: ' . json_encode(['type' => 'text_start', 'contentIndex' => 0]) . "\n"
            . 'data: ' . json_encode(['type' => 'text_delta', 'contentIndex' => 0, 'delta' => 'ok']) . "\n"
            . 'data: ' . json_encode(['type' => 'text_end', 'contentIndex' => 0]) . "\n"
            . 'data: ' . json_encode(['type' => 'done', 'reason' => 'stop', 'usage' => self::usage()]) . "\n";
        $url = $this->server->start([
            "HTTP/1.1 200 OK\r\nContent-Length: " . strlen($body) . "\r\n\r\n" . $body,
        ]);
        $proxy = new StreamProxy(rtrim($url, '/'), 't');
        $model = self::model();
        $context = new Context([new UserMessage([new TextContent('hi')])]);

        $message = Async::run(static function () use ($proxy, $model, $context): AssistantMessage {
            $stream = $proxy->stream($model, $context);

            foreach ($stream as $ignored) {
                // Drain.
            }

            return $stream->result()->await();
        });

        // Upstream lets `JSON.parse` throw here, which kills a working turn over a keep-alive.
        $this->assertSame(StopReason::Stop, $message->stopReason);
        $this->assertSame('ok', $message->content[0]->text);
    }

    public function testAnAbortEndsTheTurnAsAborted(): void
    {
        $url = $this->server->start([
            "HTTP/1.1 200 OK\r\nContent-Type: text/event-stream\r\n\r\n",
            'data: ' . json_encode(['type' => 'text_start', 'contentIndex' => 0]) . "\n",
            'data: ' . json_encode(['type' => 'text_delta', 'contentIndex' => 0, 'delta' => 'one']) . "\n",
        ], closeAfter: false);
        $proxy = new StreamProxy(rtrim($url, '/'), 't');
        $model = self::model();
        $context = new Context([new UserMessage([new TextContent('hi')])]);
        $controller = new AbortController();

        $message = Async::run(static function () use ($proxy, $model, $context, $controller): AssistantMessage {
            $stream = $proxy->stream($model, $context, new SimpleStreamOptions(signal: $controller->signal));

            Loop::get()->delay(0.05, static fn () => $controller->abort('stopped'));

            foreach ($stream as $ignored) {
                // Drain.
            }

            return $stream->result()->await();
        });

        $this->assertSame(StopReason::Aborted, $message->stopReason);
    }

    public function testAProxyUrlWithATrailingSlashDoesNotDoubleIt(): void
    {
        $url = $this->server->start([self::sse([['type' => 'done', 'reason' => 'stop', 'usage' => self::usage()]])]);
        $proxy = new StreamProxy($url, 't');
        $model = self::model();
        $context = new Context([new UserMessage([new TextContent('hi')])]);

        Async::run(static function () use ($proxy, $model, $context): void {
            foreach ($proxy->stream($model, $context) as $ignored) {
                // Drain.
            }
        });

        // `CannedServer` hands back a URL ending in `/`, which is exactly how somebody would paste
        // a gateway address.
        $this->assertStringContainsString('POST /api/stream HTTP/1.1', $this->server->receivedHead());
    }

    public function testAnUnknownEventTypeIsIgnoredRatherThanFatal(): void
    {
        [$message] = $this->turn([
            ['type' => 'text_start', 'contentIndex' => 0],
            ['type' => 'somethingNewer', 'contentIndex' => 0, 'payload' => 1],
            ['type' => 'text_delta', 'contentIndex' => 0, 'delta' => 'fine'],
            ['type' => 'text_end', 'contentIndex' => 0],
            ['type' => 'done', 'reason' => 'stop', 'usage' => self::usage()],
        ]);

        // A gateway newer than this pig has to keep working, which is `JsonSchema`'s rule about
        // unknown keywords applied to a wire protocol.
        $this->assertSame(StopReason::Stop, $message->stopReason);
        $this->assertSame('fine', $message->content[0]->text);
    }

    public function testAnUnknownDoneReasonIsAPlainStop(): void
    {
        [$message] = $this->turn([
            ['type' => 'text_start', 'contentIndex' => 0],
            ['type' => 'text_delta', 'contentIndex' => 0, 'delta' => 'done though'],
            ['type' => 'text_end', 'contentIndex' => 0],
            ['type' => 'done', 'reason' => 'refusal', 'usage' => self::usage()],
        ]);

        // The turn did finish. Refusing it over a word nobody here knows loses the work.
        $this->assertSame(StopReason::Stop, $message->stopReason);
        $this->assertSame('done though', $message->content[0]->text);
    }

    public function testDataWithNoSpaceAfterTheColonIsStillAnEvent(): void
    {
        // The event-stream spec makes that space optional and strips exactly one. Upstream's
        // `proxy.ts` requires it (`startsWith("data: ")`, `slice(6)`) while its own
        // `google-gemini-cli.ts` does not (`startsWith("data:")`, `slice(5).trim()`) — the same
        // twelve lines written twice, one of them right. Putting upstream's rule back here is not a
        // thought experiment: the `done` line below has three spaces, so it still passes
        // `startsWith("data: ")` while the three content lines do not — and the turn comes back
        // `stopReason: stop` with **zero content**. A successful-looking empty answer is the worst
        // shape a failure can take, which is why this one costs a divergence.
        $body = 'data:' . json_encode(['type' => 'text_start', 'contentIndex' => 0]) . "\n"
            . 'data:' . json_encode(['type' => 'text_delta', 'contentIndex' => 0, 'delta' => 'tight']) . "\n"
            . 'data:' . json_encode(['type' => 'text_end', 'contentIndex' => 0]) . "\n"
            . "data:   " . json_encode(['type' => 'done', 'reason' => 'stop', 'usage' => self::usage()]) . "\n";
        $url = $this->server->start([
            "HTTP/1.1 200 OK\r\nContent-Length: " . strlen($body) . "\r\n\r\n" . $body,
        ]);
        $proxy = new StreamProxy(rtrim($url, '/'), 't');
        $model = self::model();
        $context = new Context([new UserMessage([new TextContent('hi')])]);

        $message = Async::run(static function () use ($proxy, $model, $context): AssistantMessage {
            $stream = $proxy->stream($model, $context);

            foreach ($stream as $ignored) {
                // Drain.
            }

            return $stream->result()->await();
        });

        $this->assertSame(StopReason::Stop, $message->stopReason);
        $this->assertSame('tight', $message->content[0]->text);
    }

    public function testCrlfFramingIsReadToo(): void
    {
        // An event stream is specified in terms of CRLF, LF or CR, and a server behind a proxy that
        // rewrites line endings is not pig's business to argue with.
        $body = "data: " . json_encode(['type' => 'text_start', 'contentIndex' => 0]) . "\r\n"
            . 'data: ' . json_encode(['type' => 'text_delta', 'contentIndex' => 0, 'delta' => 'crlf']) . "\r\n"
            . 'data: ' . json_encode(['type' => 'text_end', 'contentIndex' => 0]) . "\r\n"
            . 'data: ' . json_encode(['type' => 'done', 'reason' => 'stop', 'usage' => self::usage()]) . "\r\n";
        $url = $this->server->start([
            "HTTP/1.1 200 OK\r\nContent-Length: " . strlen($body) . "\r\n\r\n" . $body,
        ]);
        $proxy = new StreamProxy(rtrim($url, '/'), 't');
        $model = self::model();
        $context = new Context([new UserMessage([new TextContent('hi')])]);

        $message = Async::run(static function () use ($proxy, $model, $context): AssistantMessage {
            $stream = $proxy->stream($model, $context);

            foreach ($stream as $ignored) {
                // Drain.
            }

            return $stream->result()->await();
        });

        $this->assertSame(StopReason::Stop, $message->stopReason);
        $this->assertSame('crlf', $message->content[0]->text);
    }

    public function testABlankLineBetweenEventsChangesNothing(): void
    {
        // The other possible wire: a gateway that frames properly. The line reader handles it
        // because a blank line does not start with `data:` — which is why it is the superset and a
        // strict parser is not.
        $body = '';

        foreach ([
            ['type' => 'text_start', 'contentIndex' => 0],
            ['type' => 'text_delta', 'contentIndex' => 0, 'delta' => 'framed'],
            ['type' => 'text_end', 'contentIndex' => 0],
            ['type' => 'done', 'reason' => 'stop', 'usage' => self::usage()],
        ] as $event) {
            $body .= 'data: ' . json_encode($event) . "\n\n";
        }

        $url = $this->server->start([
            "HTTP/1.1 200 OK\r\nContent-Length: " . strlen($body) . "\r\n\r\n" . $body,
        ]);
        $proxy = new StreamProxy(rtrim($url, '/'), 't');
        $model = self::model();
        $context = new Context([new UserMessage([new TextContent('hi')])]);

        $message = Async::run(static function () use ($proxy, $model, $context): AssistantMessage {
            $stream = $proxy->stream($model, $context);

            foreach ($stream as $ignored) {
                // Drain.
            }

            return $stream->result()->await();
        });

        $this->assertSame(StopReason::Stop, $message->stopReason);
        $this->assertSame('framed', $message->content[0]->text);
    }

    public function testTheLastLineIsReadEvenWithoutATrailingNewline(): void
    {
        $body = 'data: ' . json_encode(['type' => 'text_start', 'contentIndex' => 0]) . "\n"
            . 'data: ' . json_encode(['type' => 'text_delta', 'contentIndex' => 0, 'delta' => 'x']) . "\n"
            . 'data: ' . json_encode(['type' => 'text_end', 'contentIndex' => 0]) . "\n"
            . 'data: ' . json_encode(['type' => 'done', 'reason' => 'stop', 'usage' => self::usage()]);
        $url = $this->server->start([
            "HTTP/1.1 200 OK\r\nContent-Length: " . strlen($body) . "\r\n\r\n" . $body,
        ]);
        $proxy = new StreamProxy(rtrim($url, '/'), 't');
        $model = self::model();
        $context = new Context([new UserMessage([new TextContent('hi')])]);

        $message = Async::run(static function () use ($proxy, $model, $context): AssistantMessage {
            $stream = $proxy->stream($model, $context);

            foreach ($stream as $ignored) {
                // Drain.
            }

            return $stream->result()->await();
        });

        $this->assertSame(StopReason::Stop, $message->stopReason, 'the done event was the last line');
        $this->assertSame('x', $message->content[0]->text);
    }

    public function testAnEventSplitAcrossTwoChunksIsOneEvent(): void
    {
        $line = 'data: ' . json_encode(['type' => 'text_delta', 'contentIndex' => 0, 'delta' => 'split']) . "\n";
        $done = 'data: ' . json_encode(['type' => 'done', 'reason' => 'stop', 'usage' => self::usage()]) . "\n";
        $start = 'data: ' . json_encode(['type' => 'text_start', 'contentIndex' => 0]) . "\n";
        $url = $this->server->start([
            "HTTP/1.1 200 OK\r\nContent-Type: text/event-stream\r\n\r\n" . $start . substr($line, 0, 20),
            substr($line, 20) . $done,
        ]);
        $proxy = new StreamProxy(rtrim($url, '/'), 't');
        $model = self::model();
        $context = new Context([new UserMessage([new TextContent('hi')])]);

        $message = Async::run(static function () use ($proxy, $model, $context): AssistantMessage {
            $stream = $proxy->stream($model, $context);

            foreach ($stream as $ignored) {
                // Drain.
            }

            return $stream->result()->await();
        });

        // A TCP read boundary is not an event boundary, which is the one thing a hand-rolled line
        // reader gets wrong.
        $this->assertSame('split', $message->content[0]->text);
    }

    // ---- as a streamFn -------------------------------------------------------------------------

    public function testItDrivesAWholeAgentLoopIncludingATool(): void
    {
        // The one thing the cases above cannot show: that this satisfies the `streamFn` contract
        // `AgentLoop` actually calls. Two turns, a tool between them, over HTTP.
        $queue = [
            self::sse([
                ['type' => 'toolcall_start', 'contentIndex' => 0, 'id' => 'call-1', 'toolName' => 'read'],
                ['type' => 'toolcall_delta', 'contentIndex' => 0, 'delta' => '{"path":"a.txt"}'],
                ['type' => 'toolcall_end', 'contentIndex' => 0],
                ['type' => 'done', 'reason' => 'toolUse', 'usage' => self::usage()],
            ]),
            self::sse([
                ['type' => 'text_start', 'contentIndex' => 0],
                ['type' => 'text_delta', 'contentIndex' => 0, 'delta' => 'it says hello'],
                ['type' => 'text_end', 'contentIndex' => 0],
                ['type' => 'done', 'reason' => 'stop', 'usage' => self::usage()],
            ]),
        ];

        $url = $this->gateway($queue);
        $proxy = new StreamProxy(rtrim($url, '/'), 't');
        $model = self::model();
        $tool = new GatewayTool();

        $messages = Async::run(static function () use ($proxy, $model, $tool): array {
            $stream = AgentLoop::start(
                [new UserMessage([new TextContent('what does a.txt say?')])],
                new AgentContext([], 'be brief', [$tool]),
                new AgentLoopConfig(
                    model: $model,
                    convertToLlm: static fn (array $messages): array => array_values(array_filter(
                        $messages,
                        static fn ($m): bool => $m instanceof UserMessage
                            || $m instanceof AssistantMessage
                            || $m instanceof ToolResultMessage,
                    )),
                    apiKey: 'unused-by-a-gateway',
                ),
                null,
                $proxy->stream(...),
            );

            foreach ($stream as $ignored) {
                // Drain.
            }

            return $stream->result()->await();
        });

        $roles = array_map(static fn (object $m): string => match (true) {
            $m instanceof UserMessage => 'user',
            $m instanceof AssistantMessage => 'assistant',
            $m instanceof ToolResultMessage => 'toolResult',
            default => 'other',
        }, $messages);

        $this->assertSame(['user', 'assistant', 'toolResult', 'assistant'], $roles);
        $this->assertSame('a.txt', $tool->calledWith['path'] ?? null, 'the loop ran the tool the gateway asked for');
        $this->assertSame('it says hello', $messages[3]->content[0]->text);
        $this->assertSame(StopReason::Stop, $messages[3]->stopReason);

        // Two requests, and the second one carried the tool result back — which is the part a
        // gateway that ignores its input cannot prove on its own.
        $this->assertCount(2, $this->gatewayRequests);
        $this->assertSame(
            ['user'],
            array_column($this->gatewayRequests[0]['context']['messages'], 'role'),
        );
        $this->assertSame(
            ['user', 'assistant', 'toolResult'],
            array_column($this->gatewayRequests[1]['context']['messages'], 'role'),
        );
        $this->assertSame('hello', $this->gatewayRequests[1]['context']['messages'][2]['content'][0]['text']);
    }

    /**
     * A gateway that answers each connection with the next canned response.
     *
     * `CannedServer` replays one response per `start()`, and a loop with a tool in it makes two
     * requests — the second would otherwise get the first turn's tool call again, for ever.
     *
     * @param list<string> $responses
     * @return string the base URL, with a trailing slash
     */
    private function gateway(array $responses): string
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);

        if ($server === false) {
            throw new RuntimeException("Cannot open gateway: {$errstr}");
        }

        stream_set_blocking($server, false);
        $this->listening[] = $server;
        $pending = $responses;

        Loop::get()->onReadable($server, function ($listening) use (&$pending): void {
            $connection = stream_socket_accept($listening, 0);

            if ($connection === false) {
                return;
            }

            stream_set_blocking($connection, false);
            $this->listening[] = $connection;
            $response = array_shift($pending) ?? "HTTP/1.1 500 Out Of Turns\r\nContent-Length: 0\r\n\r\n";
            $request = '';
            $reader = null;

            $reader = Loop::get()->onReadable(
                $connection,
                function ($peer) use (&$request, &$reader, $response): void {
                    $data = fread($peer, 65536);

                    if ($data === false || $data === '') {
                        return;
                    }

                    $request .= $data;
                    $head = strstr($request, "\r\n\r\n", true);

                    if ($head === false) {
                        return;
                    }

                    // Wait for the whole body: a turn's context is far more than one read.
                    if (preg_match('/content-length: (\d+)/i', $head, $match) === 1
                        && strlen($request) - strlen($head) - 4 < (int) $match[1]) {
                        return;
                    }

                    Loop::get()->cancel((string) $reader);
                    $decoded = json_decode((string) substr((string) strstr($request, "\r\n\r\n"), 4), true);
                    $this->gatewayRequests[] = is_array($decoded) ? $decoded : [];
                    fwrite($peer, $response);
                    Loop::get()->delay(0.01, static fn () => fclose($peer));
                },
            );
        });

        return 'http://' . stream_socket_get_name($server, false) . '/';
    }

    public function testEventsArrivingBackToBackAreNotGluedTogether(): void
    {
        // No blank lines anywhere, which is what upstream's server sends. A strict SSE parser
        // would join these into one event and lose all but the last.
        [$message, $events] = $this->turn([
            ['type' => 'text_start', 'contentIndex' => 0],
            ['type' => 'text_delta', 'contentIndex' => 0, 'delta' => 'a'],
            ['type' => 'text_delta', 'contentIndex' => 0, 'delta' => 'b'],
            ['type' => 'text_delta', 'contentIndex' => 0, 'delta' => 'c'],
            ['type' => 'text_end', 'contentIndex' => 0],
            ['type' => 'done', 'reason' => 'stop', 'usage' => self::usage()],
        ]);

        $this->assertSame('abc', $message->content[0]->text);
        $this->assertCount(
            3,
            array_filter($events, static fn (object $e): bool => $e instanceof TextDeltaEvent),
        );
        $this->assertInstanceOf(TextEndEvent::class, $events[count($events) - 2]);
        $this->assertInstanceOf(DoneEvent::class, $events[count($events) - 1]);
    }
}
