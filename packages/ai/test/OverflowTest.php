<?php

declare(strict_types=1);

namespace Pig\Ai\Test;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pig\Ai\Api;
use Pig\Ai\AssistantMessage;
use Pig\Ai\StopReason;
use Pig\Ai\TextContent;
use Pig\Ai\Usage;
use Pig\Ai\Context;
use Pig\Ai\Model;
use Pig\Ai\Pricing;
use Pig\Ai\Providers\OpenAiCompletions;
use Pig\Ai\Providers\OpenAiOptions;
use Pig\Ai\UserMessage;
use Pig\Ai\Utils\Overflow;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\Test\CannedServer;

/**
 * Did the conversation outgrow the window?
 *
 * Every string here is one a provider really sends — they are the examples in the table
 * itself. A test written against invented messages would only prove the regex matches what
 * the regex was written for, and the thing that breaks in practice is a provider rewording.
 */
final class OverflowTest extends TestCase
{
    /**
     * A loop of this test's own.
     *
     * **Every other class that turns the loop does this, and these two were the exceptions.** The
     * loop is a singleton that outlives a test, so a class starting without this inherits whatever
     * watchers the previous test left behind — and a watcher whose stream has since gone reports
     * `Reader r1 watches a closed stream` from inside *this* test's `Async::run()`, naming a test
     * that did nothing wrong. It showed up as two `OverflowTest` errors under real PHPUnit and not
     * under the verification shim, which runs the same files in a different order.
     */
    #[\Override]
    protected function setUp(): void
    {
        Loop::reset();
    }
    private static function failed(string $error, string $provider = 'anthropic'): AssistantMessage
    {
        return new AssistantMessage(
            [new TextContent('')],
            Api::AnthropicMessages,
            $provider,
            'claude-test',
            new Usage(),
            StopReason::Error,
            $error,
        );
    }

    /** @return iterable<string, array{0: string}> */
    public static function realMessages(): iterable
    {
        yield 'Anthropic' => ['prompt is too long: 213462 tokens > 200000 maximum'];
        yield 'z.ai CN' => ['400: {"code":"1261","message":"Prompt exceeds max length"}'];
        yield 'OpenAI' => ['Your input exceeds the context window of this model'];
        yield 'Google' => ['The input token count (1196265) exceeds the maximum number of tokens allowed (1048575)'];
        yield 'xAI' => ["This model's maximum prompt length is 131072 but the request contains 537812 tokens"];
        yield 'Groq' => ['Please reduce the length of the messages or completion'];
        yield 'OpenRouter' => ["This endpoint's maximum context length is 8192 tokens. However, you requested about 9000"];
        yield 'Copilot' => ['prompt token count of 90000 exceeds the limit of 64000'];
        yield 'llama.cpp' => ['the request exceeds the available context size, try increasing it'];
        yield 'LM Studio' => ['tokens to keep from the initial prompt is greater than the context length'];
    }

    #[DataProvider('realMessages')]
    public function testEachProvidersOwnWordingIsRecognised(string $error): void
    {
        $this->assertTrue(Overflow::happened(self::failed($error)));
    }

    public function testTheBodilessFourHundredsOnlyCountForCerebras(): void
    {
        // Upstream's `CEREBRAS_BODYLESS_OVERFLOW_PATTERN`, gated on `message.provider === "cerebras"`.
        // pig used to take any provider's bodiless 400/413/429 for an overflow (and its own
        // `<who> returned 4xx:` shape too): a bodiless 429 is a rate limit, and compacting a
        // conversation because some gateway said nothing is a guess upstream stopped making.
        $cerebras = static fn (string $error): AssistantMessage => self::failed($error, 'cerebras');

        $this->assertTrue(Overflow::happened($cerebras('400 status code (no body)')));
        $this->assertTrue(Overflow::happened($cerebras('413 status code (no body)')));
        $this->assertFalse(Overflow::happened($cerebras('429 status code (no body)')));
        $this->assertFalse(Overflow::happened(self::failed('400 status code (no body)')));
        $this->assertFalse(Overflow::happened(self::failed('413 status code (no body)')));
    }

    public function testCerebrasBodilessFourHundredThroughARealProvider(): void
    {
        // Through a real provider rather than by quoting the string, so the pattern and the message
        // that has to match it cannot drift apart: the completions provider writes the `openai`
        // SDK's `400 status code (no body)` (`Utils\ErrorBody`).
        $message = $this->failedRequest(400, '');

        $this->assertSame('400 status code (no body)', $message->errorMessage);
        $this->assertTrue(Overflow::happened($message));
        $this->assertTrue(Overflow::happened($this->failedRequest(413, '')));
        $this->assertFalse(Overflow::happened($this->failedRequest(429, '')));
    }

    public function testAFiveHundredWithNoBodyIsNotAnOverflowEither(): void
    {
        // Only the statuses that mean "too much" are guessed at; a 500 is the provider
        // having a bad day, and compacting the conversation would be answering the wrong question.
        $this->assertFalse(Overflow::happened($this->failedRequest(500, '')));
    }

    /** @return iterable<string, array{0: string}> */
    public static function upstreamsLaterExamples(): iterable
    {
        // The examples upstream's table gained after pig's copy was made, from its own comment.
        yield 'Anthropic byte size' => ['413 {"error":{"type":"request_too_large","message":"Request exceeds the maximum size"}}'];
        yield 'LiteLLM' => ["Requested token count exceeds the model's maximum context length of 131072 tokens"];
        yield 'OpenAI-compatible' => ["Input length (265330) exceeds model's maximum context length (262144)."];
        yield 'Poolside' => ['Input length 300000 exceeds the maximum allowed input length of 262144 tokens.'];
        yield 'Together AI' => ["The input (300000 tokens) is longer than the model's context length (262144 tokens)."];
        yield 'MiniMax' => ['invalid params, context window exceeds limit'];
        yield 'Kimi For Coding' => ['Your request exceeded model token limit: 262144 (requested: 300000)'];
        yield 'DS4' => ['Prompt has 300,000 tokens, but the configured context size is 262,144 tokens'];
        yield 'Mistral' => ['Mistral API error (400): {"object":"error","message":"Prompt contains 300000 tokens and 0 draft tokens, too large for model with 262144 maximum context length","type":"invalid_request_message_error"}'];
        yield 'z.ai' => ['{"code":"1261","message":"Prompt too long"}'];
        yield 'DashScope' => ['Range of input length should be [1, 258048]'];
        yield 'Ollama' => ['prompt too long; exceeded max context length by 1200 tokens'];
        yield 'Bedrock' => ['Input is too long for requested model.'];
    }

    #[DataProvider('upstreamsLaterExamples')]
    public function testUpstreamsLaterExamplesAreRecognised(string $error): void
    {
        $this->assertTrue(Overflow::happened(self::failed($error)));
    }

    public function testThrottlingThatMentionsTokensIsNotAnOverflow(): void
    {
        // Upstream's `NON_OVERFLOW_PATTERNS`: Bedrock's throttling says "Too many tokens", which the
        // generic fallback would otherwise take for an overflow.
        $this->assertFalse(Overflow::happened(self::failed('Throttling error: Too many tokens, please wait before trying again.')));
        $this->assertFalse(Overflow::happened(self::failed('rate limit: too many tokens per minute')));
        $this->assertTrue(Overflow::happened(self::failed('too many tokens in the prompt')));
    }

    public function testALengthStopThatFilledTheWindowWithNothingWrittenIsAnOverflow(): void
    {
        // Upstream's third case (Xiaomi MiMo): the prompt was cut to fit, nothing was written.
        $filled = new AssistantMessage([new TextContent('')], Api::OpenAiCompletions, 'xiaomi', 'mimo', new Usage(198_100, 0), StopReason::Length);
        $this->assertTrue(Overflow::happened($filled, 200_000));
        // Something written, or a window not nearly full, is an ordinary length stop.
        $this->assertFalse(Overflow::happened(new AssistantMessage([new TextContent('x')], Api::OpenAiCompletions, 'xiaomi', 'mimo', new Usage(198_100, 5), StopReason::Length), 200_000));
        $this->assertFalse(Overflow::happened(new AssistantMessage([new TextContent('')], Api::OpenAiCompletions, 'xiaomi', 'mimo', new Usage(150_000, 0), StopReason::Length), 200_000));
        $this->assertFalse(Overflow::happened($filled));
    }

    /** A real failed request, so the message under test is the one pig actually produces. */
    private function failedRequest(int $status, string $body): AssistantMessage
    {
        $server = new CannedServer();
        $url = $server->start(["HTTP/1.1 {$status} Nope\r\nContent-Type: application/json\r\n\r\n", $body]);

        $model = new Model(
            'test-model',
            'Test',
            Api::OpenAiCompletions,
            'cerebras',
            rtrim($url, '/'),
            8_192,
            4_096,
            pricing: new Pricing(),
        );

        return Async::run(static function () use ($model): AssistantMessage {
            $stream = (new OpenAiCompletions())->stream($model, new Context([new UserMessage('hi')]), new OpenAiOptions(apiKey: 'k'));

            foreach ($stream as $ignored) {
                // Drain it; the failure is the result.
            }

            return $stream->result()->await();
        });
    }

    public function testAFourHundredWithAnExplanationIsNotAssumedToBeAnOverflow(): void
    {
        // The guess is only for the bodiless ones. A 400 that says what is wrong has said it.
        $this->assertFalse(Overflow::happened(self::failed('400 {"type":"error","error":{"type":"invalid_request_error","message":"invalid tool schema"}}')));
    }

    public function testAnOrdinaryFailureIsNotAnOverflow(): void
    {
        $this->assertFalse(Overflow::happened(self::failed('529 {"type":"error","error":{"type":"overloaded_error","message":"Overloaded"}}')));
        $this->assertFalse(Overflow::happened(self::failed('The API key is not valid')));
    }

    public function testAnAnswerWithNoErrorIsNotAnOverflow(): void
    {
        $answer = new AssistantMessage(
            [new TextContent('here you go')],
            Api::AnthropicMessages,
            'anthropic',
            'claude-test',
            new Usage(input: 100),
            StopReason::Stop,
        );

        $this->assertFalse(Overflow::happened($answer, 200_000));
    }

    // ---- the silent one -----------------------------------------------------------------

    public function testAnAnswerBilledForMoreThanTheWindowHoldsIsAnOverflow(): void
    {
        $answer = new AssistantMessage(
            [new TextContent('here you go')],
            Api::AnthropicMessages,
            'anthropic',
            'claude-test',
            new Usage(input: 150_000, cacheRead: 100_000),
            StopReason::Stop,
        );

        // z.ai takes the oversized request and answers anyway. Nothing failed, so nothing
        // said anything — the usage report is the only evidence there is.
        $this->assertTrue(Overflow::happened($answer, 200_000));
    }

    public function testCachedInputCountsTowardsTheWindow(): void
    {
        $answer = new AssistantMessage(
            [new TextContent('ok')],
            Api::AnthropicMessages,
            'anthropic',
            'claude-test',
            new Usage(input: 10, cacheRead: 250_000),
            StopReason::Stop,
        );

        // Cached or not, it is in the window: a cache read is tokens the model was given.
        $this->assertTrue(Overflow::happened($answer, 200_000));
    }

    public function testAPromptThatExactlyFillsTheWindowIsNotAnOverflow(): void
    {
        $answer = new AssistantMessage(
            [new TextContent('ok')],
            Api::AnthropicMessages,
            'anthropic',
            'claude-test',
            new Usage(input: 199_000, cacheRead: 1_000),
            StopReason::Stop,
        );

        // The boundary, and it only goes one way: a conversation that fitted exactly was
        // answered, and calling that an overflow summarises a conversation that was fine —
        // which is the whole cost of a guess on this branch.
        $this->assertFalse(Overflow::happened($answer, 200_000));

        $overBySomething = new AssistantMessage(
            [new TextContent('ok')],
            Api::AnthropicMessages,
            'anthropic',
            'claude-test',
            new Usage(input: 199_001, cacheRead: 1_000),
            StopReason::Stop,
        );

        $this->assertTrue(Overflow::happened($overBySomething, 200_000));
    }

    public function testWithNoWindowGivenTheSilentCaseCannotBeAsked(): void
    {
        $answer = new AssistantMessage(
            [new TextContent('ok')],
            Api::AnthropicMessages,
            'anthropic',
            'claude-test',
            new Usage(input: 250_000),
            StopReason::Stop,
        );

        // Not false because it is fine — false because there is nothing to compare against,
        // and inventing a window would be worse than saying nothing.
        $this->assertFalse(Overflow::happened($answer));
        $this->assertFalse(Overflow::happened($answer, 0));
    }

    // ---- the table itself ---------------------------------------------------------------

    public function testEveryPatternSaysWhoItIsFor(): void
    {
        // The table is only maintainable if each row can be checked against the provider it
        // was written for. A row with no owner is one nobody can verify or safely remove.
        foreach (Overflow::patterns() as [$pattern, $who]) {
            $this->assertNotSame('', trim($who), "no provider named for {$pattern}");
        }
    }

    public function testEveryPatternIsAValidRegex(): void
    {
        foreach (Overflow::patterns() as [$pattern, $who]) {
            $handled = true;
            set_error_handler(static function () use (&$handled): bool {
                $handled = false;

                return true;
            });
            preg_match($pattern, 'anything');
            restore_error_handler();

            $this->assertTrue($handled, "{$who}'s pattern does not compile: {$pattern}");
        }
    }
}
