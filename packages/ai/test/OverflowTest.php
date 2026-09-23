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
use Pig\Ai\Utils\Overflow;

/**
 * Did the conversation outgrow the window?
 *
 * Every string here is one a provider really sends — they are the examples in the table
 * itself. A test written against invented messages would only prove the regex matches what
 * the regex was written for, and the thing that breaks in practice is a provider rewording.
 */
final class OverflowTest extends TestCase
{
    private static function failed(string $error): AssistantMessage
    {
        return new AssistantMessage(
            [new TextContent('')],
            Api::AnthropicMessages,
            'anthropic',
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

    public function testTheBodilessFourHundredsCerebrasAndMistralSend(): void
    {
        // No explanation at all, which is why the status is all there is to go on.
        $this->assertTrue(Overflow::happened(self::failed('400 status code (no body)')));
        $this->assertTrue(Overflow::happened(self::failed('413 status code (no body)')));
        $this->assertTrue(Overflow::happened(self::failed('429 status code (no body)')));
    }

    public function testAFourHundredWithAnExplanationIsNotAssumedToBeAnOverflow(): void
    {
        // The guess is only for the bodiless ones. A 400 that says what is wrong has said it.
        $this->assertFalse(Overflow::happened(self::failed('Anthropic returned 400: invalid tool schema')));
    }

    public function testAnOrdinaryFailureIsNotAnOverflow(): void
    {
        $this->assertFalse(Overflow::happened(self::failed('Anthropic returned 503: overloaded')));
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
