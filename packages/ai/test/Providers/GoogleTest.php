<?php

declare(strict_types=1);

namespace Pig\Ai\Test\Providers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pig\Ai\Api;
use Pig\Ai\AssistantMessage;
use Pig\Ai\Context;
use Pig\Ai\ImageContent;
use Pig\Ai\Model;
use Pig\Ai\Models;
use Pig\Ai\Pricing;
use Pig\Ai\Providers\Google;
use Pig\Ai\Providers\GoogleOptions;
use Pig\Ai\Providers\GoogleShared;
use Pig\Ai\ReasoningEffort;
use Pig\Ai\SimpleStreamOptions;
use Pig\Ai\StopReason;
use Pig\Ai\Stream;
use Pig\Ai\TextContent;
use Pig\Ai\ThinkingContent;
use Pig\Ai\Tool;
use Pig\Ai\ToolCall;
use Pig\Ai\ToolResultMessage;
use Pig\Ai\Usage;
use Pig\Ai\UserMessage;
use Pig\Ai\Utils\PigUserAgent;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\Test\CannedServer;
use Pig\Ai\Utils\Transcript;

/**
 * Gemini, against a server answering from a script.
 *
 * The shape furthest from the other three: a chunk carries *parts*, a tool call arrives
 * whole, and thinking is text with a flag on it.
 */
final class GoogleTest extends TestCase
{
    private CannedServer $server;

    #[\Override]
    protected function setUp(): void
    {
        Loop::reset();
        $this->server = new CannedServer();
    }

    // ---- reading the stream ------------------------------------------------------------

    public function testATextResponseArrivesAsEventsAndThenAsAMessage(): void
    {
        $url = $this->serve([
            ['candidates' => [['content' => ['parts' => [['text' => 'Hel']]]]]],
            ['candidates' => [['content' => ['parts' => [['text' => 'lo']]], 'finishReason' => 'STOP']]],
            ['usageMetadata' => ['promptTokenCount' => 12, 'candidatesTokenCount' => 3, 'totalTokenCount' => 15]],
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
        $this->assertSame(StopReason::Stop, $message->stopReason);
    }

    public function testThinkingIsTextWithAFlagOnIt(): void
    {
        $url = $this->serve([
            ['candidates' => [['content' => ['parts' => [
                ['text' => 'let me think', 'thought' => true, 'thoughtSignature' => 'SIG'],
            ]]]]],
            ['candidates' => [['content' => ['parts' => [['text' => 'the answer']]], 'finishReason' => 'STOP']]],
        ]);

        [$types, $message] = $this->collect($url, new Context([new UserMessage('hi')]));

        $this->assertInstanceOf(ThinkingContent::class, $message->content[0]);
        $this->assertSame('let me think', $message->content[0]->thinking);
        $this->assertSame('SIG', $message->content[0]->thinkingSignature);
        $this->assertSame('the answer', $message->content[1]->text);

        // Same field, so the flag changing is the only thing that ends a block.
        $this->assertContains('ThinkingEndEvent', $types);
        $this->assertContains('TextStartEvent', $types);
    }

    public function testAToolCallArrivesWholeAndIsOpenedAndClosedAtOnce(): void
    {
        $url = $this->serve([
            ['candidates' => [['content' => ['parts' => [
                ['functionCall' => ['id' => 'c1', 'name' => 'read', 'args' => ['path' => 'a.php']]],
            ]], 'finishReason' => 'STOP']]],
        ]);

        [$types, $message] = $this->collect($url, new Context([new UserMessage('hi')]));

        $this->assertInstanceOf(ToolCall::class, $message->content[0]);
        $this->assertSame('c1', $message->content[0]->id);
        $this->assertSame(['path' => 'a.php'], $message->content[0]->arguments);

        // Nothing to stream, so all three events come from the one part.
        $this->assertSame([
            'StartEvent',
            'ToolCallStartEvent',
            'ToolCallDeltaEvent',
            'ToolCallEndEvent',
            'DoneEvent',
        ], $types);
    }

    public function testACallWithNoIdIsGivenOneAndTwoCallsNeverShareIt(): void
    {
        $url = $this->serve([
            ['candidates' => [['content' => ['parts' => [
                ['functionCall' => ['name' => 'read', 'args' => ['path' => 'a']]],
                ['functionCall' => ['name' => 'read', 'args' => ['path' => 'b']]],
            ]], 'finishReason' => 'STOP']]],
        ]);

        [, $message] = $this->collect($url, new Context([new UserMessage('hi')]));

        // Gemini often sends none, and a result has to be addressed to something.
        $this->assertNotSame('', $message->content[0]->id);
        $this->assertNotSame($message->content[0]->id, $message->content[1]->id);
    }

    public function testARepeatedIdIsReplacedRatherThanKept(): void
    {
        $url = $this->serve([
            ['candidates' => [['content' => ['parts' => [
                ['functionCall' => ['id' => 'same', 'name' => 'read', 'args' => []]],
                ['functionCall' => ['id' => 'same', 'name' => 'read', 'args' => []]],
            ]], 'finishReason' => 'STOP']]],
        ]);

        [, $message] = $this->collect($url, new Context([new UserMessage('hi')]));

        $this->assertSame('same', $message->content[0]->id);
        $this->assertNotSame('same', $message->content[1]->id);
    }

    public function testATurnThatCalledAToolIsFinishedWithTheTurnAndNotTheTask(): void
    {
        $url = $this->serve([
            ['candidates' => [['content' => ['parts' => [
                ['functionCall' => ['id' => 'c1', 'name' => 'read', 'args' => []]],
            ]], 'finishReason' => 'STOP']]],
        ]);

        [, $message] = $this->collect($url, new Context([new UserMessage('hi')]));

        // Gemini says STOP either way, so the content is what tells them apart.
        $this->assertSame(StopReason::ToolUse, $message->stopReason);
        // The mapped reason changed; the raw one is still what Gemini said.
        $this->assertSame('STOP', $message->rawStopReason);
    }

    public function testACallCutOffByTheTokenLimitIsALengthNotAToolUse(): void
    {
        // Upstream: only STOP with a call in it becomes `toolUse`. A call that MAX_TOKENS cut off
        // is not one to run.
        $url = $this->serve([
            ['candidates' => [['content' => ['parts' => [
                ['functionCall' => ['id' => 'c1', 'name' => 'echo', 'args' => ['value' => 'truncated']]],
            ]], 'finishReason' => 'MAX_TOKENS']]],
        ]);

        [, $message] = $this->collect($url, new Context([new UserMessage('hi')]));

        $this->assertSame(StopReason::Length, $message->stopReason);
        $this->assertSame('MAX_TOKENS', $message->rawStopReason);
        $this->assertNotSame([], $message->toolCalls());
    }

    public function testAMalformedCallIsAnErrorEvenWithACallPartInIt(): void
    {
        // Gemini said the call was malformed; running it anyway because a part came with it
        // would be the opposite of what it said.
        $url = $this->serve([
            ['candidates' => [['content' => ['parts' => [
                ['functionCall' => ['id' => 'c1', 'name' => 'echo', 'args' => ['value' => 'truncated']]],
            ]], 'finishReason' => 'MALFORMED_FUNCTION_CALL']]],
        ]);

        [, $message] = $this->collect($url, new Context([new UserMessage('hi')]));

        $this->assertSame(StopReason::Error, $message->stopReason);
        $this->assertSame('Provider stopped with: MALFORMED_FUNCTION_CALL', $message->errorMessage);
        // **The failed message still carries the raw reason.** It is set before the throw, and
        // `fail()` only overwrites the mapped reason and the error text — upstream's catch does the
        // same to its one mutable output object.
        $this->assertSame('MALFORMED_FUNCTION_CALL', $message->rawStopReason);
    }

    public function testASafetyBlockIsAnErrorHoweverPolitelyItIsPhrased(): void
    {
        $url = $this->serve([
            ['candidates' => [['content' => ['parts' => [['text' => 'well']]], 'finishReason' => 'SAFETY']]],
        ]);

        [, $message] = $this->collect($url, new Context([new UserMessage('hi')]));

        // Eighteen of Gemini's twenty finish reasons mean the turn produced nothing
        // usable; only STOP and MAX_TOKENS do not.
        $this->assertSame(StopReason::Error, $message->stopReason);

        // **And it says which one**, in upstream's words (`google-raw-stop-reason.test.ts`).
        $this->assertSame('Provider stopped with: SAFETY', $message->errorMessage);
    }

    /**
     * @param string $reason a finish reason that means the turn produced nothing usable
     * @param string $expected what the turn should say it was
     */
    #[DataProvider('finishReasonsThatMeanNothingUsable')]
    public function testAFinishReasonThatMeansNothingUsableSaysWhichOneItWas(
        string $reason,
        string $expected,
    ): void {
        $url = $this->serve([['candidates' => [['finishReason' => $reason]]]]);

        [, $message] = $this->collect($url, new Context([new UserMessage('hi')]));

        $this->assertSame(StopReason::Error, $message->stopReason);
        $this->assertSame($expected, $message->errorMessage);
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function finishReasonsThatMeanNothingUsable(): iterable
    {
        // One per family, because the reasons are a flat enum and a `match` that got one of
        // them would look like it had them all.
        yield 'a safety block' => ['SAFETY', 'Provider stopped with: SAFETY'];
        yield 'recitation' => ['RECITATION', 'Provider stopped with: RECITATION'];
        yield 'a malformed call' => [
            'MALFORMED_FUNCTION_CALL',
            'Provider stopped with: MALFORMED_FUNCTION_CALL',
        ];
        yield 'a reason this pig has never heard of' => [
            'SOMETHING_NEW',
            'Provider stopped with: SOMETHING_NEW',
        ];
    }

    public function testTheTwoReasonsThatAreNotFailuresStillSayNothing(): void
    {
        // The other half of the rule, so the fix cannot become "every finish reason throws":
        // `MAX_TOKENS` is a turn that ran out of room and `STOP` is one that finished, and
        // neither is an error to be explained.
        foreach ([['MAX_TOKENS', StopReason::Length], ['STOP', StopReason::Stop]] as [$reason, $expected]) {
            $this->server = new CannedServer();
            $url = $this->serve([
                ['candidates' => [['content' => ['parts' => [['text' => 'well']]], 'finishReason' => $reason]]],
            ]);

            [, $message] = $this->collect($url, new Context([new UserMessage('hi')]));

            $this->assertSame($expected, $message->stopReason);
            $this->assertNull($message->errorMessage);
        }
    }

    public function testARefusedTurnKeepsWhatItSaidAndWhatItCost(): void
    {
        // The reason the usage is read before the finish reason: they ride on the same chunk,
        // and a turn Gemini refused was still billed for its input. Text in the first chunk and
        // the refusal in the second, which is also the shape that leaves a block open.
        $url = $this->serve([
            ['candidates' => [['content' => ['parts' => [['text' => 'here is the start of an ans']]]]]],
            [
                'candidates' => [['finishReason' => 'SAFETY']],
                'usageMetadata' => [
                    'promptTokenCount' => 40,
                    'candidatesTokenCount' => 7,
                    'totalTokenCount' => 47,
                ],
            ],
        ]);

        [$types, $message] = $this->collect($url, new Context([new UserMessage('hi')]));

        $this->assertSame(40, $message->usage->input);
        $this->assertSame(7, $message->usage->output);

        // Partial text survives, because `fail()` only sets the reason and keeps the blocks.
        $this->assertSame('here is the start of an ans', self::textOf($message));

        // And the open block is closed on the way out, so the stream is still well formed —
        // a consumer that pairs start with end does not have to guess.
        $this->assertSame(
            ['StartEvent', 'TextStartEvent', 'TextDeltaEvent', 'TextEndEvent', 'ErrorEvent'],
            $types,
        );
    }

    public function testARefusedPromptIsAFailureAndNotAnEmptySuccess(): void
    {
        // It comes back as a 200 with nothing in it but the reason. Upstream's Gemini path does not
        // read `promptFeedback`: no candidate means no finish reason, and that is its error. pig
        // used to say `Gemini refused the prompt: SAFETY` here, its own words; that rule now lives
        // only in the Antigravity extension, which is pig's own.
        $url = $this->serve([['promptFeedback' => ['blockReason' => 'SAFETY']]]);

        [$types, $message] = $this->collect($url, new Context([new UserMessage('hi')]));

        $this->assertContains('ErrorEvent', $types);
        $this->assertSame('Google stream ended without a finish reason', $message->errorMessage);
    }

    /** @return iterable<string, array{0: string, 1: string, 2: string}> */
    public static function refusedRequests(): iterable
    {
        // `@google/genai` 2.21.0's `throwErrorIfNotOK()`: a JSON response is `JSON.stringify` of the
        // parsed body — compact, in the body's own key order — and anything else is wrapped as
        // `{error: {message, code, status: statusText}}`. Upstream's `formatProviderError()` leaves
        // that message alone. pig used to write `google returned <status>: <error.message>`.
        yield 'a JSON error' => [
            "HTTP/1.1 429 Too Many Requests\r\nContent-Type: application/json; charset=UTF-8\r\n",
            "{\n  \"error\": {\n    \"code\": 429,\n    \"message\": \"Resource exhausted\",\n    \"status\": \"RESOURCE_EXHAUSTED\"\n  }\n}\n",
            '{"error":{"code":429,"message":"Resource exhausted","status":"RESOURCE_EXHAUSTED"}}',
        ];
        yield 'not JSON' => [
            "HTTP/1.1 503 Service Unavailable\r\nContent-Type: text/html\r\n",
            '<html>down</html>',
            '{"error":{"message":"<html>down</html>","code":503,"status":"Service Unavailable"}}',
        ];
        // `response.json()` throws, and V8's `SyntaxError` is the error upstream reports.
        yield 'said JSON, is not' => [
            "HTTP/1.1 502 Bad Gateway\r\nContent-Type: application/json\r\n",
            '<html>bad gateway</html>',
            'Unexpected token \'<\', "<html>bad "... is not valid JSON',
        ];
    }

    #[DataProvider('refusedRequests')]
    public function testARefusedRequestReadsAsTheGenaiSdkWritesIt(string $head, string $body, string $expected): void
    {
        $url = $this->server->start([$head . 'Content-Length: ' . strlen($body) . "\r\n\r\n" . $body]);

        [, $message] = $this->collect($url, new Context([new UserMessage('hi')]));

        $this->assertSame(StopReason::Error, $message->stopReason);
        $this->assertSame($expected, $message->errorMessage);
    }

    /** @return iterable<string, array{0: list<string>, 1: string}> */
    public static function brokenStreams(): iterable
    {
        // The SDK's `processStreamResponse()` and the `chunk.json()` after it. pig read the body
        // with its own SSE parser and skipped whatever did not decode.
        yield 'a payload that is not JSON' => [["data: {\"candidates\": [oops]}\n\n"], 'Unexpected token \'o\', ..."idates": [oops]}" is not valid JSON'];
        yield 'an empty payload' => [["data:\n\n"], 'Unexpected end of JSON input'];
        // `data:` lines are not joined as an event stream joins them: the event's remainder is one payload.
        yield 'two data lines' => [["data: {\"a\":1}\ndata: {\"b\":2}\n\n"], 'Unexpected non-whitespace character after JSON at position 8 (line 2 column 1)'];
        // A refusal sent as a bare JSON object inside a 200 stream.
        yield 'an error object in the stream' => [
            ['{"error":{"code":429,"message":"Quota","status":"RESOURCE_EXHAUSTED"}}'],
            'got status: RESOURCE_EXHAUSTED. {"error":{"code":429,"message":"Quota","status":"RESOURCE_EXHAUSTED"}}',
        ];
        yield 'a body cut mid-event' => [["data: {\"candidates\": []}"], 'Incomplete JSON segment at the end'];
        // An event that does not start with `data:` is passed over whole, its data line too.
        yield 'an event line first' => [["event: message\ndata: {\"candidates\":[{\"finishReason\":\"STOP\"}]}\n\n"], 'Google stream ended without a finish reason'];
    }

    /** @param list<string> $pieces */
    #[DataProvider('brokenStreams')]
    public function testTheStreamIsReadAsTheGenaiSdkReadsIt(array $pieces, string $expected): void
    {
        $url = $this->serveRaw($pieces);

        [$types, $message] = $this->collect($url, new Context([new UserMessage('hi')]));

        $this->assertSame('ErrorEvent', end($types));
        $this->assertSame($expected, $message->errorMessage);
    }

    public function testEventsEndAtAnyOfTheSdksThreeDelimitersAndMayBeSplitAcrossReads(): void
    {
        $text = static fn (string $t, ?string $finish = null): string => (string) json_encode(['candidates' => [array_filter([
            'content' => ['parts' => [['text' => $t]]],
            'finishReason' => $finish,
        ])]]);
        $url = $this->serveRaw([
            'data: ' . $text('a') . "\r\r",
            'data: ' . substr($text('é'), 0, 40),
            substr($text('é'), 40) . "\r\n\r\n",
            ": a comment\n\n",
            'data: ' . $text('c', 'STOP') . "\n\n",
        ]);

        [, $message] = $this->collect($url, new Context([new UserMessage('hi')]));

        $this->assertSame(StopReason::Stop, $message->stopReason);
        $this->assertSame('aéc', self::textOf($message));
    }

    public function testTheUserAgentIsPis(): void
    {
        // `createClient()`: `{"User-Agent": getPiUserAgent(), ...model.headers}` as the SDK's headers.
        $this->send(new Context([new UserMessage('hi')]));

        $this->assertStringContainsString('user-agent: ' . PigUserAgent::get() . "\r\n", $this->server->receivedHead());
    }

    public function testACallsThoughtSignatureSurvivesIntoTheMessage(): void
    {
        $url = $this->serve([
            ['candidates' => [['content' => ['parts' => [[
                'functionCall' => ['name' => 'read', 'args' => ['path' => 'a.php']],
                'thoughtSignature' => 'SIG-1',
            ]]], 'finishReason' => 'STOP']]],
        ]);

        [, $message] = $this->collect($url, new Context([new UserMessage('hi')]));
        $call = $message->content[0];

        $this->assertInstanceOf(ToolCall::class, $call);

        // Collected by the provider, written back by `GoogleShared::messages()`, and dropped in
        // between: `AssistantMessageBuilder` built every `ToolCall` without it. Gemini 3 wants its
        // own thought context back with the call that came out of it.
        $this->assertSame('SIG-1', $call->thoughtSignature);
    }

    public function testAPartCarryingBothTextAndACallLosesNeither(): void
    {
        // A `Part` is a one-of by convention and not by schema, and upstream reads both fields of
        // one part — text first, then the call. pig checked for the call first and returned, so any
        // text sharing the part was dropped on the floor.
        $url = $this->serve([
            ['candidates' => [['content' => ['parts' => [[
                'text' => 'let me look at that',
                'functionCall' => ['name' => 'read', 'args' => ['path' => 'a.php']],
            ]]], 'finishReason' => 'STOP']]],
        ]);

        [$types, $message] = $this->collect($url, new Context([new UserMessage('hi')]));

        $this->assertSame([
            'StartEvent',
            'TextStartEvent',
            'TextDeltaEvent',
            'TextEndEvent',
            'ToolCallStartEvent',
            'ToolCallDeltaEvent',
            'ToolCallEndEvent',
            'DoneEvent',
        ], $types);

        $this->assertCount(2, $message->content);
        $this->assertSame('let me look at that', $message->content[0]->text);
        $this->assertSame('read', $message->content[1]->name);
        $this->assertSame(StopReason::ToolUse, $message->stopReason);
    }

    public function testThinkingTokensAreCountedAsOutputBecauseTheyAreBilledAsOutput(): void
    {
        // Gemini's own arithmetic: `promptTokenCount` *includes* the cached tokens, and
        // `totalTokenCount` is prompt + candidates + thoughts — so 100 + 10 + 40, with the 20
        // cached already inside the 100. The first version of this fixture said 170, which is what
        // pig computed rather than what Google sends, and that is what hid the bug below.
        $url = $this->serve([
            ['usageMetadata' => [
                'promptTokenCount' => 100,
                'candidatesTokenCount' => 10,
                'thoughtsTokenCount' => 40,
                'cachedContentTokenCount' => 20,
                'totalTokenCount' => 150,
            ]],
        ]);

        [, $message] = $this->collect($url, new Context([new UserMessage('hi')]));

        $this->assertSame(50, $message->usage->output);
        $this->assertSame(20, $message->usage->cacheRead);

        // The cached 20 are taken out of the prompt, as upstream does: input is what was paid for
        // at the input rate. Left in, they were priced twice — once as input, once as cache read.
        $this->assertSame(80, $message->usage->input);

        // The number Google sent, not a sum of the parts: adding them up counts the cached tokens
        // twice, and `Compaction::contextTokens()` believes this figure — so a conversation with
        // most of its prompt cached looked far fuller than it was and got compacted early.
        $this->assertSame(150, $message->usage->totalTokens);
    }

    // ---- writing the request --------------------------------------------------------------

    public function testTheRequestNamesTheModelInTheUrlAndStreamsAsSse(): void
    {
        $this->send(new Context([new UserMessage('hi')]));

        $head = $this->server->receivedHead();

        // Without `alt=sse` this is one enormous JSON array rather than a stream.
        $this->assertStringContainsString('/models/test-model:streamGenerateContent?alt=sse', $head);
        $this->assertStringContainsString('x-goog-api-key: test-key', strtolower($head));
    }

    public function testTheSystemPromptIsAnInstructionRatherThanATurn(): void
    {
        $this->send(new Context([new UserMessage('hi')], 'be helpful'));

        $body = $this->server->receivedJson();

        $this->assertSame('be helpful', $body['systemInstruction']['parts'][0]['text']);
        $this->assertSame('user', $body['contents'][0]['role']);
    }

    public function testTheAssistantIsCalledTheModel(): void
    {
        $context = new Context([
            new UserMessage('hi'),
            $this->assistant([new TextContent('hello')]),
            new UserMessage('go on'),
        ]);

        $this->send($context);

        $this->assertSame('model', $this->server->receivedJson()['contents'][1]['role']);
    }

    public function testTheDirectApiSendsTheSchemaAsWrittenInParametersJsonSchema(): void
    {
        // Upstream's `convertTools(tools, false, …)` for the direct API: `parametersJsonSchema`,
        // full JSON Schema, untouched. This used to assert the meta-declarations were stripped,
        // because pig sent the legacy OpenAPI `parameters`, which answers `Unknown name "$schema"`
        // to the schema every MCP server writes. The stripping lives on for the Code Assist path
        // (next test); here the field that takes JSON Schema is the one used.
        $schema = [
            '$schema' => 'http://json-schema.org/draft-07/schema#',
            'type' => 'object',
            'properties' => ['path' => ['type' => 'string', '$comment' => 'absolute'], 'opts' => ['$ref' => '#/$defs/opts']],
            '$defs' => ['opts' => ['type' => 'object']],
            'required' => ['path'],
            'additionalProperties' => false,
        ];
        $context = new Context([new UserMessage('hi')], tools: [new Tool('read_text_file', 'Read a file', $schema)]);

        $this->send($context);

        $declared = $this->server->receivedJson()['tools'][0]['functionDeclarations'][0];

        $this->assertArrayNotHasKey('parameters', $declared);
        $this->assertSame($schema, $declared['parametersJsonSchema']);
    }

    public function testTheCodeAssistToolsStillStripJsonSchemaMetaDeclarations(): void
    {
        // What the TypeScript MCP SDK emits for every tool: `$schema` at the top and `$defs`
        // underneath. The legacy `parameters` is an OpenAPI 3.0 schema and answers
        // `Unknown name "$schema"` — a 400 for every request, from the moment one MCP server
        // connected. `GoogleShared::tools()` is what `pig-antigravity` sends, and it still uses it.
        $tools = GoogleShared::tools([new Tool('read_text_file', 'Read a file', [
            '$schema' => 'http://json-schema.org/draft-07/schema#',
            'type' => 'object',
            'properties' => ['path' => ['type' => 'string', '$comment' => 'absolute'], 'opts' => ['$ref' => '#/$defs/opts']],
            '$defs' => ['opts' => ['type' => 'object']],
            'required' => ['path'],
            'additionalProperties' => false,
        ])]);

        $declared = $tools[0]['functionDeclarations'][0]['parameters'];

        $this->assertArrayNotHasKey('$schema', $declared);
        $this->assertArrayNotHasKey('$defs', $declared);
        $this->assertArrayNotHasKey('$comment', $declared['properties']['path'], 'at every depth');
        $this->assertSame(['path'], $declared['required'], 'everything else stays');
        $this->assertFalse($declared['additionalProperties']);
    }

    public function testGeminiThreeCallsAStrictToolInValidatedMode(): void
    {
        // Upstream's `resolveGoogleFunctionCallingMode()`: a tool that asks for strict sampling,
        // on a model that has it (`supportsGoogleStrictToolSampling()`: Gemini 3+), makes the
        // mode `VALIDATED`, and the schema goes in its strict form — every property required,
        // an optional one widened to take null. pig had no such mode and sent no `toolConfig`.
        $strict = new Tool('read', 'Read a file', [
            'type' => 'object',
            'properties' => ['path' => ['type' => 'string'], 'limit' => ['type' => 'integer']],
            'required' => ['path'],
        ], ['type' => 'json_schema', 'strict' => 'prefer']);

        $this->send(new Context([new UserMessage('hi')], tools: [$strict]), $this->model(id: 'gemini-3-pro-preview'));
        $sent = $this->server->receivedJson();

        $this->assertSame(['functionCallingConfig' => ['mode' => 'VALIDATED']], $sent['toolConfig']);
        $this->assertSame([
            'type' => 'object',
            'properties' => ['path' => ['type' => 'string'], 'limit' => ['anyOf' => [['type' => 'integer'], ['type' => 'null']]]],
            'required' => ['path', 'limit'],
            'additionalProperties' => false,
        ], $sent['tools'][0]['functionDeclarations'][0]['parametersJsonSchema']);

        // `none` and `any` are said as asked even then.
        $this->server = new CannedServer();
        $this->send(
            new Context([new UserMessage('hi')], tools: [$strict]),
            $this->model(id: 'gemini-3-pro-preview'),
            new GoogleOptions(apiKey: 'test-key', toolChoice: 'any'),
        );
        $this->assertSame('ANY', $this->server->receivedJson()['toolConfig']['functionCallingConfig']['mode']);
    }

    public function testBeforeGeminiThreeOrWithoutAStrictToolThereIsNoValidatedMode(): void
    {
        $strict = new Tool('read', 'Read a file', ['type' => 'object', 'properties' => ['path' => ['type' => 'string']]], ['type' => 'json_schema', 'strict' => 'prefer']);
        $plain = new Tool('read', 'Read a file', ['type' => 'object', 'properties' => ['path' => ['type' => 'string']]]);

        // Gemini 2.5 has no strict sampling: a `prefer` tool falls back, the schema goes as
        // written, and with no tool choice no mode is said at all.
        $this->send(new Context([new UserMessage('hi')], tools: [$strict]), $this->model(id: 'gemini-2.5-pro'));
        $sent = $this->server->receivedJson();
        $this->assertArrayNotHasKey('toolConfig', $sent);
        $this->assertSame($strict->parameters, $sent['tools'][0]['functionDeclarations'][0]['parametersJsonSchema']);

        // Gemini 3 with no tool asking for it: the same.
        $this->server = new CannedServer();
        $this->send(new Context([new UserMessage('hi')], tools: [$plain]), $this->model(id: 'gemini-3-pro-preview'));
        $this->assertArrayNotHasKey('toolConfig', $this->server->receivedJson());
    }

    public function testFunctionCallingModeDecisionIsUpstreams(): void
    {
        $strict = new Tool('t', 'd', ['type' => 'object', 'properties' => []], ['type' => 'json_schema', 'strict' => 'prefer']);
        $plain = new Tool('t', 'd', ['type' => 'object', 'properties' => []]);

        $this->assertTrue(GoogleShared::supportsGoogleStrictToolSampling('gemini-3-flash'));
        $this->assertTrue(GoogleShared::supportsGoogleStrictToolSampling('Gemini-Live-3.1'));
        $this->assertFalse(GoogleShared::supportsGoogleStrictToolSampling('gemini-2.5-pro'));
        $this->assertFalse(GoogleShared::supportsGoogleStrictToolSampling('claude-sonnet-4-5'));

        $this->assertSame('VALIDATED', GoogleShared::resolveGoogleFunctionCallingMode([$plain, $strict], null, true));
        $this->assertSame('VALIDATED', GoogleShared::resolveGoogleFunctionCallingMode([$strict], 'auto', true));
        $this->assertSame('NONE', GoogleShared::resolveGoogleFunctionCallingMode([$strict], 'none', true));
        $this->assertSame('AUTO', GoogleShared::resolveGoogleFunctionCallingMode([$strict], 'auto', false));
        // `mapToolChoice()`'s default arm: an unknown choice is AUTO, not upper-cased.
        $this->assertSame('AUTO', GoogleShared::resolveGoogleFunctionCallingMode([$plain], 'required', true));
        $this->assertNull(GoogleShared::resolveGoogleFunctionCallingMode([$plain], null, true));
    }

    public function testARequiredStrictToolFailsTheTurnWhereStrictIsUnavailable(): void
    {
        // `strict: "require"` is upstream's "never send me loose": no strict mode, or a schema with
        // no strict form, is an error rather than a silent fallback.
        $require = new Tool('t', 'd', ['type' => 'object', 'properties' => []], ['type' => 'json_schema', 'strict' => 'require']);
        $unstrictable = new Tool('u', 'd', ['type' => 'object', 'properties' => ['x' => ['oneOf' => [['type' => 'string']]]]], ['type' => 'json_schema', 'strict' => 'require']);

        try {
            GoogleShared::resolveGoogleFunctionCallingMode([$require], null, false);
            $this->fail('expected a refusal');
        } catch (\Pig\Ai\ProviderError $error) {
            $this->assertSame('Tool "t" requires JSON-schema constrained sampling, but strict tools are unsupported.', $error->getMessage());
        }

        try {
            GoogleShared::resolveGoogleFunctionCallingMode([$unstrictable], null, true);
            $this->fail('expected a refusal');
        } catch (\Pig\Ai\ProviderError $error) {
            $this->assertSame('Tool "u" requires JSON-schema constrained sampling, but oneOf schemas are unsupported.', $error->getMessage());
        }

        // A `prefer` tool with the same schema simply is not strict.
        $prefer = new Tool('u', 'd', $unstrictable->parameters, ['type' => 'json_schema', 'strict' => 'prefer']);
        $this->assertNull(GoogleShared::resolveGoogleFunctionCallingMode([$prefer], null, true));
    }

    public function testConsecutiveToolResultsAreMergedIntoOneTurn(): void
    {
        $context = new Context([
            new UserMessage('hi'),
            $this->assistant([new ToolCall('c1', 'read', []), new ToolCall('c2', 'read', [])]),
            new ToolResultMessage('c1', 'read', [new TextContent('one')]),
            new ToolResultMessage('c2', 'read', [new TextContent('two')]),
        ]);

        $this->send($context);

        $contents = $this->server->receivedJson()['contents'];
        $last = $contents[count($contents) - 1];

        $this->assertCount(3, $contents);
        $this->assertCount(2, $last['parts']);
        $this->assertSame('one', $last['parts'][0]['functionResponse']['response']['output']);
        $this->assertSame('two', $last['parts'][1]['functionResponse']['response']['output']);
    }

    public function testAFailedToolResultGoesUnderErrorRatherThanOutput(): void
    {
        $context = new Context([
            new UserMessage('hi'),
            $this->assistant([new ToolCall('c1', 'read', [])]),
            new ToolResultMessage('c1', 'read', [new TextContent('no such file')], true),
        ]);

        $this->send($context);

        $contents = $this->server->receivedJson()['contents'];
        $response = $contents[count($contents) - 1]['parts'][0]['functionResponse']['response'];

        $this->assertSame('no such file', $response['error']);
        $this->assertFalse(array_key_exists('output', $response));
    }

    public function testAnUnsignedThoughtFromThisModelGoesBackAsAThought(): void
    {
        // Upstream's `convertMessages()`: a thought from the same provider and model goes back as
        // `thought: true` whether or not it has a signature, and only the signature is left off.
        // pig used to send it as `<thinking>…</thinking>` text instead, which upstream never does:
        // tags in the history teach the model to write them into its own answers.
        $context = new Context([
            new UserMessage('hi'),
            $this->assistant([new ThinkingContent('mine', null), new TextContent('so')]),
            new UserMessage('go on'),
        ]);

        $this->send($context);

        $parts = $this->server->receivedJson()['contents'][1]['parts'];

        $this->assertSame(['thought' => true, 'text' => 'mine'], $parts[0]);
        $this->assertSame(['text' => 'so'], $parts[1]);
    }

    public function testAnotherModelsThoughtGoesBackAsPlainTextWithNoTags(): void
    {
        // The other arm: a thought from any other model is ordinary text, untagged — the
        // signature is meaningless here, and the tags were pig's invention.
        $context = new Context([
            new UserMessage('hi'),
            new AssistantMessage(
                [new ThinkingContent('from another model', 'U0lHMQ==')],
                Api::AnthropicMessages,
                'anthropic',
                'claude-sonnet-4-5',
                new Usage(),
                StopReason::Stop,
            ),
            new UserMessage('go on'),
        ]);

        $this->send($context);

        $this->assertSame([['text' => 'from another model']], $this->server->receivedJson()['contents'][1]['parts']);
    }

    public function testASignedThoughtGoesBackAsAThought(): void
    {
        // Base64, because that is what a real signature is and the only kind that goes back now:
        // this used to say `SIG`, which Gemini's `TYPE_BYTES` field would refuse.
        $context = new Context([
            new UserMessage('hi'),
            $this->assistant([new ThinkingContent('mine', 'U0lHMQ==')]),
            new UserMessage('go on'),
        ]);

        $this->send($context);

        $part = $this->server->receivedJson()['contents'][1]['parts'][0];

        $this->assertTrue($part['thought']);
        $this->assertSame('U0lHMQ==', $part['thoughtSignature']);
    }

    public function testACallsSignatureGoesBackWithTheCall(): void
    {
        // Base64 for the same reason as above; this used to be `SIG-1`, which is not.
        $context = new Context([
            new UserMessage('hi'),
            $this->assistant([new ToolCall('c1', 'read', ['path' => 'a.php'], 'U0lHLTE=')]),
            new UserMessage('go on'),
        ]);

        $this->send($context);

        $part = $this->server->receivedJson()['contents'][1]['parts'][0];

        // The other end of the signature that used to be dropped on the way in: this half was
        // written and could not be reached, because nothing ever produced a call that had one.
        $this->assertSame('read', $part['functionCall']['name']);
        $this->assertSame('U0lHLTE=', $part['thoughtSignature']);
    }

    public function testASignatureThatIsNotBase64IsLeftOffRatherThanSent(): void
    {
        // Upstream's `isValidThoughtSignature()`: Google declares the field `TYPE_BYTES`, so a
        // signature that is not base64 — wrong length, or a character outside the alphabet — is a
        // 400 for the whole request. The part still goes; only the signature is dropped.
        $context = new Context([
            new UserMessage('hi'),
            $this->assistant([
                new ThinkingContent('mine', 'U0lHMQ'),
                new TextContent('so', 'not-base64!'),
                new ToolCall('c1', 'read', [], 'U0lH-TE='),
            ]),
            new ToolResultMessage('c1', 'read', [new TextContent('ok')]),
        ]);

        $this->send($context);

        $parts = $this->server->receivedJson()['contents'][1]['parts'];

        $this->assertSame(['thought' => true, 'text' => 'mine'], $parts[0]);
        $this->assertSame(['text' => 'so'], $parts[1]);
        $this->assertArrayNotHasKey('thoughtSignature', $parts[2]);
    }

    public function testATextBlocksSignatureGoesBackAsThePartsThoughtSignature(): void
    {
        // Gemini signs answer text too, and wants it back on the part it came on. pig dropped a
        // text block's `textSignature` here — and an empty text part with nothing but a signature
        // in it went with the empty-text rule. Upstream keeps that one: dropping it breaks the
        // reasoning chain and the model intermittently ends a turn mid-task with nothing in it.
        $context = new Context([
            new UserMessage('hi'),
            $this->assistant([new TextContent('so', 'U0lHMQ=='), new TextContent('', 'U0lHMg==')]),
            new UserMessage('go on'),
        ]);

        $this->send($context);

        $this->assertSame(
            [['text' => 'so', 'thoughtSignature' => 'U0lHMQ=='], ['text' => '', 'thoughtSignature' => 'U0lHMg==']],
            $this->server->receivedJson()['contents'][1]['parts'],
        );
    }

    public function testAnotherModelOfTheSameProviderGetsNoSignatureBack(): void
    {
        // Upstream's `isSameProviderAndModel`: provider and model id. A signature is the model's
        // own, so gemini-2.5-pro's means nothing to the model asked here even though both are
        // `google`; `TransformMessages` strips them first, and this checks the two together.
        $context = new Context([
            new UserMessage('hi'),
            new AssistantMessage(
                [new TextContent('so', 'U0lHMQ=='), new ToolCall('c1', 'read', [], 'U0lHMg==')],
                Api::GoogleGenerativeAi,
                'google',
                'gemini-2.5-pro',
                new Usage(),
                StopReason::ToolUse,
            ),
            new ToolResultMessage('c1', 'read', [new TextContent('ok')]),
        ]);

        $this->send($context);

        $this->assertStringNotContainsString('thoughtSignature', (string) json_encode($this->server->receivedJson()['contents']));
    }

    public function testATextPartsSignatureIsKeptOnTheTextBlockAndALaterDeltaDoesNotWipeIt(): void
    {
        // The receiving half of the one above: upstream keeps a text part's `thoughtSignature` as
        // the block's `textSignature`, through `retainThoughtSignature()`, so the next delta of the
        // same block — which usually has none — does not lose it. pig kept only a thought's.
        $url = $this->serve([
            ['candidates' => [['content' => ['parts' => [['text' => 'the ', 'thoughtSignature' => 'U0lHMQ==']]]]]],
            ['candidates' => [['content' => ['parts' => [['text' => 'answer']]], 'finishReason' => 'STOP']]],
        ]);

        [, $message] = $this->collect($url, new Context([new UserMessage('hi')]));

        $this->assertEquals([new TextContent('the answer', 'U0lHMQ==')], $message->content);
    }

    public function testAnImageGoesInlineAndBecomesAPlaceholderForAModelThatCannotSeeOne(): void
    {
        $context = new Context([new UserMessage([new TextContent('look'), new ImageContent('AAA', 'image/png')])]);

        $this->send($context, $this->model(images: true));
        $this->assertSame('image/png', $this->server->receivedJson()['contents'][0]['parts'][1]['inlineData']['mimeType']);

        $this->server = new CannedServer();
        $this->send($context, $this->model(images: false));
        // Was a count of 1: the image was dropped without a word. Upstream's
        // `downgradeUnsupportedImages()` leaves a line saying one was there, so the model can say
        // it cannot see it rather than answer as though nothing had been attached.
        $this->assertSame(
            [['text' => 'look'], ['text' => '(image omitted: model does not support images)']],
            $this->server->receivedJson()['contents'][0]['parts'],
        );
    }

    /**
     * Upstream's `hasText` is the joined text being non-empty, so an image result whose only text
     * block is "" still gets "(see attached image)" — pig counted the empty block as text and sent
     * "". With nothing at all, upstream's Google converter sends "", not a placeholder.
     */
    public function testAnImageResultWithOnlyEmptyTextStillSaysThereIsAnImage(): void
    {
        $context = new Context([
            new UserMessage('hi'),
            $this->assistant([new ToolCall('c1', 'shot', [])]),
            new ToolResultMessage('c1', 'shot', [new TextContent(''), new ImageContent('AAA', 'image/png')]),
        ]);

        $this->send($context, $this->model(images: true, id: 'gemini-3-pro-preview'));
        $contents = $this->server->receivedJson()['contents'];

        $this->assertSame('(see attached image)', $contents[count($contents) - 1]['parts'][0]['functionResponse']['response']['output']);

        $this->server = new CannedServer();
        $this->send(new Context([
            new UserMessage('hi'),
            $this->assistant([new ToolCall('c1', 'bash', [])]),
            new ToolResultMessage('c1', 'bash', []),
        ]), $this->model(id: 'gemini-3-pro-preview'));
        $contents = $this->server->receivedJson()['contents'];

        $this->assertSame('', $contents[count($contents) - 1]['parts'][0]['functionResponse']['response']['output']);
    }

    public function testOnlyGeminiThreeTakesImagesInsideAToolResult(): void
    {
        $context = new Context([
            new UserMessage('hi'),
            $this->assistant([new ToolCall('c1', 'shot', [])]),
            new ToolResultMessage('c1', 'shot', [new ImageContent('AAA', 'image/png')]),
        ]);

        $this->send($context, $this->model(images: true, id: 'gemini-3-pro-preview'));
        $contents = $this->server->receivedJson()['contents'];
        $this->assertCount(1, $contents[count($contents) - 1]['parts'][0]['functionResponse']['parts']);

        $this->server = new CannedServer();
        $this->send($context, $this->model(images: true, id: 'gemini-2.5-pro'));
        $contents = $this->server->receivedJson()['contents'];

        // Older models have nowhere to put it, so it follows as a turn of its own.
        $this->assertSame('Tool result image:', $contents[count($contents) - 1]['parts'][0]['text']);
    }

    public function testGeminiThreeAndLaterAndEveryModelThatIsNotGeminiTakeImagesInsideTheResult(): void
    {
        // Upstream's `supportsMultimodalFunctionResponse()` reads the Gemini major version and
        // answers `>= 3`, and answers **true for a model that is not Gemini at all** — Claude
        // behind Cloud Code Assist. pig asked `str_contains($id, 'gemini-3')`, which sent Claude's
        // images, and a later Gemini's, as a separate user turn instead.
        $context = new Context([
            new UserMessage('hi'),
            $this->assistant([new ToolCall('c1', 'shot', [])]),
            new ToolResultMessage('c1', 'shot', [new ImageContent('AAA', 'image/png')]),
        ]);

        foreach (['claude-sonnet-4-5', 'gemini-4-pro'] as $id) {
            $this->server = new CannedServer();
            $this->send($context, $this->model(images: true, id: $id));
            $contents = $this->server->receivedJson()['contents'];

            $this->assertCount(1, $contents[count($contents) - 1]['parts'][0]['functionResponse']['parts'] ?? [], $id);
        }
    }

    public function testACallsIdIsSentOnlyToAModelThatRequiresOne(): void
    {
        // Upstream's `requiresToolCallId()`: Claude, gpt-oss and Gemini 3+ behind Google's APIs
        // need the `id` on a `functionCall` and its `functionResponse`; for the rest upstream
        // leaves it off, and pig sent it to every model.
        $context = new Context([
            new UserMessage('hi'),
            $this->assistant([new ToolCall('c1', 'read', ['path' => 'a.php'])]),
            new ToolResultMessage('c1', 'read', [new TextContent('ok')]),
        ]);

        foreach (['gemini-3-pro-preview' => true, 'claude-sonnet-4-5' => true, 'gpt-oss-120b-medium' => true,
            'gemini-2.5-pro' => false, 'test-model' => false] as $id => $wanted) {
            $this->server = new CannedServer();
            $this->send($context, $this->model(id: $id));
            $contents = $this->server->receivedJson()['contents'];

            $this->assertSame($wanted, isset($contents[1]['parts'][0]['functionCall']['id']), "{$id}: the call");
            $this->assertSame($wanted, isset($contents[2]['parts'][0]['functionResponse']['id']), "{$id}: the result");
        }
    }

    public function testAnotherModelsIdIsMadeSafeOnlyForAModelThatIsSentIt(): void
    {
        // Upstream's `normalizeToolCallId` here does nothing unless `requiresToolCallId()` — an id
        // that is never sent needs no shape. Where it is sent, a Responses API `call_id|item_id`
        // from `/model` gpt-5 to Gemini 3 becomes `[a-zA-Z0-9_-]`, at most 64, on both halves.
        $long = 'call_abc|fc_' . str_repeat('x+/=', 30);
        $context = new Context([
            new UserMessage('hi'),
            new AssistantMessage(
                [new ToolCall($long, 'read', ['path' => 'a.php'])],
                Api::OpenAiResponses,
                'openai',
                'gpt-5',
                new Usage(),
                StopReason::ToolUse,
            ),
            new ToolResultMessage($long, 'read', [new TextContent('ok')]),
        ]);

        $this->send($context, $this->model(id: 'gemini-3-pro-preview'));
        $contents = $this->server->receivedJson()['contents'];
        $safe = substr('call_abc_fc_' . str_repeat('x___', 30), 0, 64);

        $this->assertSame($safe, $contents[1]['parts'][0]['functionCall']['id']);
        $this->assertSame($safe, $contents[2]['parts'][0]['functionResponse']['id']);
    }

    public function testToolsGoOutAsFunctionDeclarations(): void
    {
        $tool = new Tool('read', 'Read a file', ['type' => 'object', 'properties' => []]);

        $this->send(new Context([new UserMessage('hi')], null, [$tool]));

        $this->assertSame('read', $this->server->receivedJson()['tools'][0]['functionDeclarations'][0]['name']);
    }

    // ---- thinking, which is said two different ways -------------------------------------------

    public function testAskingForNoThinkingSendsAZeroBudget(): void
    {
        $this->send(new Context([new UserMessage('hi')]), $this->model(reasoning: true), new GoogleOptions(apiKey: 'test-key', thinkingEnabled: false));

        // Gemini thinks by default, so saying no has to be said.
        $this->assertSame(0, $this->server->receivedJson()['generationConfig']['thinkingConfig']['thinkingBudget']);
    }

    public function testOptionsThatSayNothingAboutThinkingSendNoThinkingConfig(): void
    {
        // Upstream's `buildParams()` sends a `thinkingConfig` only when `options.thinking` is there —
        // the asked-for one when enabled, the disabled one when not — so options with no `thinking`
        // leave the model to its default. This test asserted the disabled config for that case,
        // which upstream does not send; `Stream::simple()` always says, so the agent's requests are
        // unchanged.
        $this->send(new Context([new UserMessage('hi')]), $this->model(reasoning: true));

        $this->assertArrayNotHasKey('thinkingConfig', $this->server->receivedJson()['generationConfig'] ?? []);
    }

    public function testGeminiTwoPointFiveIsGivenABudgetInTokens(): void
    {
        $options = $this->translate(Models::get('gemini-2.5-pro'), ReasoningEffort::High);

        $this->assertTrue($options->thinkingEnabled);
        $this->assertSame(32768, $options->thinkingBudget);
        $this->assertNull($options->thinkingLevel);
    }

    public function testFlashHasALowerCeilingThanPro(): void
    {
        $this->assertSame(24576, $this->translate(Models::get('gemini-2.5-flash'), ReasoningEffort::High)->thinkingBudget);
    }

    public function testGeminiThreeIsGivenALevelAndNoBudget(): void
    {
        $options = $this->translate(Models::get('gemini-3-flash-preview'), ReasoningEffort::Medium);

        $this->assertSame('MEDIUM', $options->thinkingLevel);
        $this->assertNull($options->thinkingBudget);
    }

    public function testAStreamThatEndsWithoutAFinishReasonIsAnErrorNotAnAnswer(): void
    {
        // Upstream's output starts at `stopReason: "pending"`, and a stream that ends with no
        // `finishReason` throws `Google stream ended without a finish reason`. pig's builder started
        // at `stop`, so a connection cut mid-answer came back as a finished one.
        $url = $this->serve([['candidates' => [['content' => ['parts' => [['text' => 'half a sen']]]]]]]);

        [$types, $message] = $this->collect($url, new Context([new UserMessage('hi')]));

        $this->assertSame('ErrorEvent', end($types));
        $this->assertSame(StopReason::Error, $message->stopReason);
        $this->assertSame('Google stream ended without a finish reason', $message->errorMessage);
        $this->assertSame('half a sen', $message->content[0]->text);
    }

    public function testAnErrorFinishReasonIsReadToTheEndOfTheStreamBeforeItEndsTheTurn(): void
    {
        // Upstream records the error reason and reads on; the end-of-stream check throws `Provider
        // stopped with: <raw reason>`. Usage that arrives after it is therefore still the turn's.
        $url = $this->serve([
            ['candidates' => [['content' => ['parts' => [['text' => 'x']]], 'finishReason' => 'SAFETY']]],
            ['usageMetadata' => ['promptTokenCount' => 40, 'candidatesTokenCount' => 1, 'totalTokenCount' => 41]],
        ]);

        [, $message] = $this->collect($url, new Context([new UserMessage('hi')]));

        $this->assertSame('Provider stopped with: SAFETY', $message->errorMessage);
        $this->assertSame('SAFETY', $message->rawStopReason);
        $this->assertSame(40, $message->usage->input);
    }

    public function testEveryGemini3ModelInTheTableTakesALevelAndNoneABudget(): void
    {
        // Measured, not read: one request per model and level against the public endpoint on
        // 2026-10-01 — every 3.x id in the table accepts `thinkingLevel` LOW/MEDIUM/HIGH, and the
        // old `3-pro`/`3-flash` check matched exactly one of them. See CLAUDE.md, "Gemini 3.x on
        // the public endpoint". A model this check misses is one that is asked for `thinkingBudget:
        // -1` and ignores the level entirely, which is what every 3.x model but one did.
        $budgeted = [];

        foreach (Models::all() as $model) {
            if ($model->provider !== 'google' || !$model->reasoning || !str_contains($model->id, 'gemini-3')) {
                continue;
            }

            $options = $this->translate($model, ReasoningEffort::Medium);

            // A level, whichever one the row's map clamps `medium` to: Nano Banana 2 Lite's has no
            // MEDIUM, so it is asked for HIGH, as upstream's `clampThinkingLevel()` asks.
            if ($options->thinkingLevel === null || $options->thinkingBudget !== null) {
                $budgeted[] = $model->id;
            }
        }

        $this->assertSame([], $budgeted);
        $this->assertSame('HIGH', $this->translate(Models::find('google', 'gemini-3.1-flash-lite-image'), ReasoningEffort::Medium)->thinkingLevel);
    }

    public function testAModelThatRefusesALevelSaysSoInItsRowRatherThanAtTheProvider(): void
    {
        // The same measurement: `thinkingBudget: 0` is a 400 on the Pro models ("only works in
        // thinking mode") and on 3.5 Flash Lite, and MINIMAL is "not supported" on five of nine.
        // The row carries that so `ThinkingLevel::clampedFor()` moves the level *before* a request
        // is built — the generator's override is the one place the fact is written down.
        $pro = Models::find('google', 'gemini-3.1-pro-preview');
        $flash = Models::find('google', 'gemini-3.8-flash');
        $lite = Models::find('google', 'gemini-3.5-flash-lite');
        $older = Models::find('google', 'gemini-3.5-flash');

        $this->assertNotNull($pro);
        $this->assertNotNull($flash);
        $this->assertNotNull($lite);
        $this->assertNotNull($older);

        foreach ([$pro, $flash] as $model) {
            $this->assertFalse($model->hasThinkingLevel('off'), $model->id);
            $this->assertFalse($model->hasThinkingLevel('minimal'), $model->id);
            $this->assertTrue($model->hasThinkingLevel('low'), $model->id);
        }

        $this->assertFalse($lite->hasThinkingLevel('off'));
        $this->assertTrue($lite->hasThinkingLevel('minimal'), 'measured: 3.5 Flash Lite takes MINIMAL');

        // And a 3.x model the endpoint accepts everything on carries the catalogue's verified
        // efforts — every level from MINIMAL to HIGH, and no `off`, which models.dev does not list
        // for it. It used to carry no map, and with no map `off` was a zero budget.
        foreach (['minimal', 'low', 'medium', 'high'] as $level) {
            $this->assertTrue($older->hasThinkingLevel($level), $level);
        }

        $this->assertFalse($older->hasThinkingLevel('off'));

        // MEDIUM really is a level on Pro now (111 thinking tokens against LOW 87 and HIGH 142), so
        // upstream's fold of Pro to two levels is gone with the model it was measured on.
        $this->assertSame('MEDIUM', $this->translate($pro, ReasoningEffort::Medium)->thinkingLevel);
    }

    public function testTheLatestAliasesAndGemmaFourTakeALevelToo(): void
    {
        // Upstream's `usesGoogleThinkingLevel()` is a regex and three names, not "the id contains
        // gemini-3": `gemini-flash-latest`, `gemini-flash-lite-latest` and Gemma 4 (`gemma-4-*` and
        // `gemma4-*`) take a level. pig sent them `thinkingBudget: -1` — think as much as you like —
        // so `--thinking low` on any of them changed nothing.
        foreach (['gemini-flash-latest', 'gemini-flash-lite-latest'] as $id) {
            $options = $this->translate(Models::find('google', $id), ReasoningEffort::Low);

            $this->assertSame('LOW', $options->thinkingLevel, $id);
            $this->assertNull($options->thinkingBudget, $id);
        }

        // Gemma 4 takes a level too — but only MINIMAL and HIGH, which upstream's generator writes as
        // its map (`getGoogleThinkingLevelMap()`), so `low` clamps up to HIGH as upstream's
        // `clampThinkingLevel()` clamps it. pig used to send it LOW.
        foreach (['gemma-4-31b-it', 'gemma-4-26b-a4b-it'] as $id) {
            $options = $this->translate(Models::find('google', $id), ReasoningEffort::Low);

            $this->assertSame('HIGH', $options->thinkingLevel, $id);
            $this->assertNull($options->thinkingBudget, $id);
            $this->assertSame('MINIMAL', $this->translate(Models::find('google', $id), ReasoningEffort::Minimal)->thinkingLevel, $id);
        }

        $this->assertSame('HIGH', $this->translate($this->model(reasoning: true, id: 'gemma4-e4b'), ReasoningEffort::High)->thinkingLevel);

        // And a Gemini 3 id that is neither Pro nor Flash is not one: the regex asks for the family.
        $this->assertSame(-1, $this->translate($this->model(reasoning: true, id: 'gemini-3-ultra'), ReasoningEffort::High)->thinkingBudget);
    }

    public function testThinkingOffOnALevelModelWithNoOffIsItsLowestLevelAndNotABudgetOfZero(): void
    {
        // Upstream's `getDisabledGoogleThinkingConfig()`. Gemini 3.1 Pro answers `thinkingBudget: 0`
        // with a 400 ("only works in thinking mode") and 3.5 Flash Lite with "invalid argument", so
        // a request that asks for no thinking — a hook's or an export's, which carry no level and
        // are not clamped by the agent — failed outright on them. Upstream sends the level `off`
        // clamps to instead; a model that has `off` keeps the zero budget.
        $wanted = [
            'gemini-3.1-pro-preview' => ['thinkingLevel' => 'LOW'],
            'gemini-3.5-flash-lite' => ['thinkingLevel' => 'MINIMAL'],
            // Its verified efforts list no `none`, so it has no `off` either now.
            'gemini-3.5-flash' => ['thinkingLevel' => 'MINIMAL'],
            'gemini-2.5-pro' => ['thinkingBudget' => 0],
        ];

        foreach ($wanted as $id => $config) {
            $model = Models::find('google', $id);
            $this->assertNotNull($model, $id);

            $this->server = new CannedServer();
            $this->send(new Context([new UserMessage('hi')]), $model, new GoogleOptions(apiKey: 'test-key', thinkingEnabled: false));

            $this->assertSame($config, $this->server->receivedJson()['generationConfig']['thinkingConfig'], $id);
        }
    }

    public function testABudgetIsReadFromTheLevelTheMapResolvedTo(): void
    {
        // Upstream's `getGoogleBudget()` takes the level after `resolveGoogleThinkingLevel()`; pig
        // took the level that was asked for, so a row that says "high means medium here" was still
        // given the high budget.
        $model = new Model('gemini-2.5-pro', 'x', Api::GoogleGenerativeAi, 'google', 'http://127.0.0.1:1', 1, 1, true, thinkingLevelMap: ['high' => 'MEDIUM']);

        $this->assertSame(8192, $this->translate($model, ReasoningEffort::High)->thinkingBudget);
    }

    public function testFlashLiteHasItsOwnFloor(): void
    {
        // Upstream gives `2.5-flash-lite` its own table — 512 at minimal — ahead of the `2.5-flash`
        // table its id would otherwise match.
        $this->assertSame(512, $this->translate(Models::get('gemini-2.5-flash-lite'), ReasoningEffort::Minimal)->thinkingBudget);
        $this->assertSame(128, $this->translate(Models::get('gemini-2.5-flash'), ReasoningEffort::Minimal)->thinkingBudget);
    }

    public function testAMapToALevelGoogleDoesNotHaveIsRefusedByName(): void
    {
        // Upstream's `resolveGoogleThinkingLevel()` throws rather than send a level Google has never
        // heard of.
        $model = new Model('gemini-3.8-flash', 'x', Api::GoogleGenerativeAi, 'google', 'http://127.0.0.1:1', 1, 1, true, thinkingLevelMap: ['high' => 'max']);

        $this->expectExceptionMessage('Unsupported Google thinking level mapping for google/gemini-3.8-flash: high -> max');
        $this->translate($model, ReasoningEffort::High);
    }

    public function testAModelWithNoPublishedCeilingIsLeftToDecide(): void
    {
        $unknown = $this->model(reasoning: true, id: 'gemini-9.9-experimental');

        // -1 is "you decide", which beats a number nobody published.
        $this->assertSame(-1, $this->translate($unknown, ReasoningEffort::High)->thinkingBudget);
    }

    // ---- scaffolding -------------------------------------------------------------------------

    private function translate(?Model $model, ReasoningEffort $effort): GoogleOptions
    {
        $this->assertNotNull($model);

        // No `setAccessible(true)`: it has had no effect since PHP 8.1 — reflection reaches a private
        // method by itself — and PHP 8.5 deprecates the call, which `phpunit.xml`'s
        // `failOnDeprecation` turns into a failure. It was the one call of its kind in the tree.
        $method = new \ReflectionMethod(Stream::class, 'translate');
        // The conversation is the third argument now: upstream's `buildBaseOptions()` clamps the
        // answer's ceiling to the room the context leaves, so the translation needs to see it.
        $options = $method->invoke(null, $model, new \Pig\Ai\TranscriptContext([]), new SimpleStreamOptions(apiKey: 'k', reasoning: $effort));

        $this->assertInstanceOf(GoogleOptions::class, $options);

        return $options;
    }

    /** @param list<mixed> $content */
    private function assistant(array $content): AssistantMessage
    {
        return new AssistantMessage(
            $content,
            Api::GoogleGenerativeAi,
            'google',
            'test-model',
            new Usage(),
            StopReason::Stop,
        );
    }

    private static function textOf(AssistantMessage $message): string
    {
        $text = '';

        foreach ($message->content as $block) {
            if ($block instanceof TextContent) {
                $text .= $block->text;
            }
        }

        return $text;
    }

    public function testTheFirstResponseIdIsKeptAndThoughtsAreTheReasoningSplit(): void
    {
        // Upstream keeps the first non-empty `responseId` of the stream, and reports
        // `thoughtsTokenCount` as the reasoning share of an output that already includes it.
        $url = $this->serve([
            ['responseId' => 'resp-a', 'candidates' => [['content' => ['parts' => [['text' => 'hi']]]]]],
            [
                'responseId' => 'resp-b',
                'candidates' => [['content' => ['parts' => []], 'finishReason' => 'STOP']],
                'usageMetadata' => [
                    'promptTokenCount' => 10,
                    'candidatesTokenCount' => 3,
                    'thoughtsTokenCount' => 7,
                    'totalTokenCount' => 20,
                ],
            ],
        ]);

        [, $message] = $this->collect($url, new Context([new UserMessage('hi')]));

        $this->assertSame('resp-a', $message->responseId);
        $this->assertSame(7, $message->usage->reasoning);
        $this->assertSame(10, $message->usage->output);
    }

    /** @return array{0: list<string>, 1: AssistantMessage} */
    private function collect(string $url, Context $context): array
    {
        return Async::run(function () use ($url, $context): array {
            $stream = (new Google())->stream(
                $this->model(baseUrl: $url),
                Transcript::normalizeContext($context),
                new GoogleOptions(apiKey: 'test-key'),
            );
            $types = [];

            foreach ($stream as $event) {
                $types[] = (new \ReflectionClass($event))->getShortName();
            }

            return [$types, $stream->result()->await()];
        });
    }

    public function testWhatTheTurnAsksForReachesTheRequest(): void
    {
        // Three options that the mutation sweep found nothing following to the wire here, while
        // the Code Assist provider — the sibling that shares `GoogleShared` — had all three
        // pinned. The first shape from `CLAUDE.md`'s index, on a request body.
        $this->send(
            new Context([new UserMessage('hi')]),
            $this->model(reasoning: true),
            new GoogleOptions(
                temperature: 0.4,
                maxTokens: 321,
                apiKey: 'test-key',
                thinkingEnabled: true,
                thinkingBudget: 2048,
            ),
        );

        $config = $this->server->receivedJson()['generationConfig'];

        $this->assertSame(0.4, $config['temperature']);
        $this->assertSame(321, $config['maxOutputTokens'], 'an output cap nobody sends is an unbounded answer');
        $this->assertSame(2048, $config['thinkingConfig']['thinkingBudget']);

        // Asking to think asks for the summaries with it, or the thoughts are billed and never
        // arrive — and `includeThoughts` was asserted for Code Assist and not here.
        $this->assertTrue($config['thinkingConfig']['includeThoughts']);
    }

    public function testNoneOfThemIsSentWhenNobodyAskedForOne(): void
    {
        // The other half, so the fix cannot become "always send a number": a default the
        // provider never asked for is a setting somebody did not choose.
        $this->send(new Context([new UserMessage('hi')]));

        $config = $this->server->receivedJson()['generationConfig'];

        $this->assertArrayNotHasKey('temperature', $config);
        $this->assertArrayNotHasKey('maxOutputTokens', $config);
    }

    private function send(Context $context, ?Model $model = null, ?GoogleOptions $options = null): void
    {
        $url = $this->serve([['candidates' => [['content' => ['parts' => [['text' => 'ok']]], 'finishReason' => 'STOP']]]]);
        $model ??= $this->model(reasoning: true);
        $options ??= new GoogleOptions(apiKey: 'test-key');

        Async::run(function () use ($url, $context, $model, $options): void {
            $stream = (new Google())->stream(
                $this->model($url, $model->reasoning, $model->acceptsImages(), $model->id, $model->thinkingLevelMap),
                Transcript::normalizeContext($context),
                $options,
            );

            foreach ($stream as $ignored) {
                // Drain it; what is under test is what went out.
            }

            $stream->result()->await();
        });
    }

    /** @param array<string, string|null> $thinkingLevelMap */
    private function model(
        string $baseUrl = 'http://127.0.0.1:1',
        bool $reasoning = false,
        bool $images = true,
        string $id = 'test-model',
        array $thinkingLevelMap = [],
    ): Model {
        return new Model(
            $id,
            'Test Model',
            Api::GoogleGenerativeAi,
            'google',
            rtrim($baseUrl, '/'),
            1_000_000,
            8_192,
            $reasoning,
            $images ? ['text', 'image'] : ['text'],
            new Pricing(input: 1.0, output: 2.0),
            thinkingLevelMap: $thinkingLevelMap,
        );
    }

    /** @param list<string> $pieces each written to the socket on its own, as one HTTP chunk */
    private function serveRaw(array $pieces): string
    {
        $out = ["HTTP/1.1 200 OK\r\nContent-Type: text/event-stream\r\nTransfer-Encoding: chunked\r\n\r\n"];

        foreach ($pieces as $piece) {
            $out[] = sprintf("%x\r\n%s\r\n", strlen($piece), $piece);
        }

        $out[] = "0\r\n\r\n";

        return $this->server->start($out);
    }

    /** @param list<array<string, mixed>> $chunks */
    private function serve(array $chunks): string
    {
        $pieces = ["HTTP/1.1 200 OK\r\nContent-Type: text/event-stream\r\nTransfer-Encoding: chunked\r\n\r\n"];

        foreach ($chunks as $chunk) {
            $body = 'data: ' . json_encode($chunk) . "\n\n";
            $pieces[] = sprintf("%x\r\n%s\r\n", strlen($body), $body);
        }

        $pieces[] = "0\r\n\r\n";

        return $this->server->start($pieces);
    }
}
