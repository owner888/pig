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
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\Test\CannedServer;

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

        // **And it says which one.** This assertion is the whole of the find below: the case
        // passed for months on the line above alone, because `mapStopReason` answers `error`
        // and leaves `errorMessage` null — which is an error with no text in it.
        $this->assertSame('Gemini stopped with nothing usable: SAFETY', $message->errorMessage);
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
        yield 'a safety block' => ['SAFETY', 'Gemini stopped with nothing usable: SAFETY'];
        yield 'recitation' => ['RECITATION', 'Gemini stopped with nothing usable: RECITATION'];
        yield 'a malformed call' => [
            'MALFORMED_FUNCTION_CALL',
            'Gemini stopped with nothing usable: MALFORMED_FUNCTION_CALL',
        ];
        yield 'a reason this pig has never heard of' => [
            'SOMETHING_NEW',
            'Gemini stopped with nothing usable: SOMETHING_NEW',
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
        // It comes back as a 200 with nothing in it but the reason.
        $url = $this->serve([['promptFeedback' => ['blockReason' => 'SAFETY']]]);

        [$types, $message] = $this->collect($url, new Context([new UserMessage('hi')]));

        $this->assertContains('ErrorEvent', $types);
        $this->assertStringContainsString('SAFETY', (string) $message->errorMessage);
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

    public function testAThoughtWithNoSignatureGoesBackAsTextRatherThanBeingDropped(): void
    {
        $context = new Context([
            new UserMessage('hi'),
            $this->assistant([new ThinkingContent('from another model', null), new TextContent('so')]),
            new UserMessage('go on'),
        ]);

        $this->send($context);

        $parts = $this->server->receivedJson()['contents'][1]['parts'];

        // A thought without its signature is rejected as a thought, so it travels as
        // tagged text instead of being lost.
        $this->assertFalse(array_key_exists('thought', $parts[0]));
        $this->assertStringContainsString('<thinking>', $parts[0]['text']);
    }

    public function testASignedThoughtGoesBackAsAThought(): void
    {
        $context = new Context([
            new UserMessage('hi'),
            $this->assistant([new ThinkingContent('mine', 'SIG')]),
            new UserMessage('go on'),
        ]);

        $this->send($context);

        $part = $this->server->receivedJson()['contents'][1]['parts'][0];

        $this->assertTrue($part['thought']);
        $this->assertSame('SIG', $part['thoughtSignature']);
    }

    public function testACallsSignatureGoesBackWithTheCall(): void
    {
        $context = new Context([
            new UserMessage('hi'),
            $this->assistant([new ToolCall('c1', 'read', ['path' => 'a.php'], 'SIG-1')]),
            new UserMessage('go on'),
        ]);

        $this->send($context);

        $part = $this->server->receivedJson()['contents'][1]['parts'][0];

        // The other end of the signature that used to be dropped on the way in: this half was
        // written and could not be reached, because nothing ever produced a call that had one.
        $this->assertSame('read', $part['functionCall']['name']);
        $this->assertSame('SIG-1', $part['thoughtSignature']);
    }

    public function testAnImageGoesInlineAndIsLeftOutForAModelThatCannotSeeOne(): void
    {
        $context = new Context([new UserMessage([new TextContent('look'), new ImageContent('AAA', 'image/png')])]);

        $this->send($context, $this->model(images: true));
        $this->assertSame('image/png', $this->server->receivedJson()['contents'][0]['parts'][1]['inlineData']['mimeType']);

        $this->server = new CannedServer();
        $this->send($context, $this->model(images: false));
        $this->assertCount(1, $this->server->receivedJson()['contents'][0]['parts']);
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

    public function testToolsGoOutAsFunctionDeclarations(): void
    {
        $tool = new Tool('read', 'Read a file', ['type' => 'object', 'properties' => []]);

        $this->send(new Context([new UserMessage('hi')], null, [$tool]));

        $this->assertSame('read', $this->server->receivedJson()['tools'][0]['functionDeclarations'][0]['name']);
    }

    // ---- thinking, which is said two different ways -------------------------------------------

    public function testNotAskingToThinkMeansAskingForNone(): void
    {
        $this->send(new Context([new UserMessage('hi')]), $this->model(reasoning: true));

        // Gemini thinks by default, so saying nothing is not the same as saying no.
        $this->assertSame(0, $this->server->receivedJson()['generationConfig']['thinkingConfig']['thinkingBudget']);
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
        // `gemini-3-flash-preview`, because it is the only public model left whose id the level
        // check matches — see the test below, which is about exactly that.
        $options = $this->translate(Models::get('gemini-3-flash-preview'), ReasoningEffort::Medium);

        $this->assertSame('MEDIUM', $options->thinkingLevel);
        $this->assertNull($options->thinkingBudget);
    }

    public function testTheLevelPathOnlyStillCatchesOnePublicModel(): void
    {
        // **A drift, pinned rather than papered over.** `Stream::gemini()` picks the level path
        // with `str_contains($id, '3-pro') || str_contains($id, '3-flash')`, and the generated
        // table has moved on to `gemini-3.1-pro-preview`, `gemini-3.5-flash`, `gemini-3.8-flash`
        // — none of which contain either string. So every Gemini 3.x model but the one preview
        // id takes the *budget* path and is sent `thinkingBudget: -1`.
        //
        // Whether that is wrong depends on what Google's public endpoint accepts for 3.5 and
        // 3.8, which is not a question this suite can answer. What it can do is fail the moment
        // somebody acts on the answer, so the decision does not get lost.
        //
        // `geminiLevel()`'s pro branch — `gemini-3-pro` gets two levels rather than four — is
        // unreachable for every model in the table for the same reason. This test replaced the
        // one that covered it, which only ever passed because its fixture was a *Gemini CLI*
        // model: the one table where the literal `gemini-3-pro-preview` still existed.
        $levelled = [];
        $budgeted = [];

        foreach (Models::all() as $model) {
            if ($model->provider !== 'google' || !$model->reasoning || !str_contains($model->id, 'gemini-3')) {
                continue;
            }

            $options = $this->translate($model, ReasoningEffort::Medium);
            $options->thinkingLevel !== null ? $levelled[] = $model->id : $budgeted[] = $model->id;
        }

        $this->assertSame(['gemini-3-flash-preview'], $levelled);
        $this->assertContains('gemini-3.8-flash', $budgeted);
        $this->assertContains('gemini-3.1-pro-preview', $budgeted);
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
        $options = $method->invoke(null, $model, new SimpleStreamOptions(apiKey: 'k', reasoning: $effort));

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

    /** @return array{0: list<string>, 1: AssistantMessage} */
    private function collect(string $url, Context $context): array
    {
        return Async::run(function () use ($url, $context): array {
            $stream = (new Google())->stream(
                $this->model(baseUrl: $url),
                $context,
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
                $this->model($url, $model->reasoning, $model->acceptsImages(), $model->id),
                $context,
                $options,
            );

            foreach ($stream as $ignored) {
                // Drain it; what is under test is what went out.
            }

            $stream->result()->await();
        });
    }

    private function model(
        string $baseUrl = 'http://127.0.0.1:1',
        bool $reasoning = false,
        bool $images = true,
        string $id = 'test-model',
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
        );
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
