<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pig\Ai\Api;
use Pig\Ai\AssistantMessage;
use Pig\Ai\StopReason;
use Pig\Ai\TextContent;
use Pig\Ai\Usage;
use Pig\Ai\Utils\Overflow;
use Pig\CodingAgent\Session\Retry;

/**
 * Is this failure worth trying again?
 *
 * The messages are real ones — what a provider actually writes — because the whole class is a
 * reader of provider prose, and a test written against invented strings would prove that the
 * regex matches the strings the regex was written for.
 */
final class RetryTest extends TestCase
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

    private static function answered(int $input = 0, int $cacheRead = 0): AssistantMessage
    {
        return new AssistantMessage(
            [new TextContent('here you go')],
            Api::AnthropicMessages,
            'anthropic',
            'claude-test',
            new Usage(input: $input, cacheRead: $cacheRead),
            StopReason::Stop,
        );
    }

    // ---- the status code ----------------------------------------------------------------

    /** @return iterable<string, array{0: int, 1: bool}> */
    public static function statuses(): iterable
    {
        yield 'rate limited' => [429, true];
        yield 'overloaded' => [529, true];
        yield 'request timeout' => [408, true];
        yield 'internal error' => [500, true];
        yield 'bad gateway' => [502, true];
        yield 'unavailable' => [503, true];
        yield 'gateway timeout' => [504, true];

        // Everything below is about the request, and the request will not change by being
        // sent again. A 401 retried three times is three more rejections and fourteen seconds.
        yield 'bad request' => [400, false];
        yield 'no key' => [401, false];
        yield 'forbidden' => [403, false];
        yield 'no such model' => [404, false];
        yield 'too large' => [413, false];
        yield 'unprocessable' => [422, false];
    }

    #[DataProvider('statuses')]
    public function testTheStatusCodeDecides(int $status, bool $expected): void
    {
        $message = self::failed("Anthropic returned {$status}: something went wrong");

        $this->assertSame($expected, Retry::worthRetrying($message));
    }

    public function testTheStatusIsReadFromTheShapeEveryProviderWrites(): void
    {
        // All four providers build this line the same way, which is what makes reading the
        // number out of it something better than a guess.
        $this->assertSame(429, Retry::statusOf('Anthropic returned 429: rate limit exceeded'));
        $this->assertSame(503, Retry::statusOf('google returned 503: The model is overloaded.'));
        $this->assertSame(500, Retry::statusOf('groq returned 500: internal'));
        $this->assertSame(502, Retry::statusOf('openai returned 502: bad gateway'));
    }

    public function testAFailureThatNeverReachedHttpHasNoStatus(): void
    {
        $this->assertNull(Retry::statusOf('Connection reset by peer'));
    }

    public function testANumberThatIsNotAStatusIsNotReadAsOne(): void
    {
        // The word "returned" is what makes it a status line. A model id with digits in it,
        // or a token count, is not a verdict about the request.
        $this->assertNull(Retry::statusOf('claude-3-5-sonnet-20241022 is not available'));
        $this->assertNull(Retry::statusOf('prompt is too long: 213462 tokens > 200000 maximum'));
    }

    // ---- no status at all ---------------------------------------------------------------

    /** @return iterable<string, array{0: string}> */
    public static function transportFailures(): iterable
    {
        yield 'reset' => ['Connection reset by peer'];
        yield 'closed' => ['connection closed before the response was complete'];
        yield 'timeout' => ['Request timed out after 120s'];
        yield 'broken pipe' => ['Broken pipe writing to the socket'];
        yield 'stream cut' => ['stream ended without a stop reason'];
        yield 'overloaded, worded' => ['The model is overloaded, please try again'];
    }

    #[DataProvider('transportFailures')]
    public function testAFailureWithNoStatusFallsBackToWhatItSays(string $error): void
    {
        // A socket that died has no verdict to read, and these are real failures that go
        // away on their own — exactly the kind worth waiting out.
        $this->assertTrue(Retry::worthRetrying(self::failed($error)));
    }

    public function testSomethingWithNoStatusAndNothingFamiliarIsNotRetried(): void
    {
        $this->assertFalse(Retry::worthRetrying(self::failed('The API key is not valid')));
        $this->assertFalse(Retry::worthRetrying(self::failed('Cannot encode the request')));
    }

    // ---- not a failure at all -----------------------------------------------------------

    public function testAnAnswerIsNotRetried(): void
    {
        $this->assertFalse(Retry::worthRetrying(self::answered()));
    }

    public function testAnAbortIsNotRetried(): void
    {
        $aborted = new AssistantMessage(
            [new TextContent('')],
            Api::AnthropicMessages,
            'anthropic',
            'claude-test',
            new Usage(),
            StopReason::Aborted,
            'Request was aborted',
        );

        // Somebody pressed escape. Sending it again is the opposite of what they asked for.
        $this->assertFalse(Retry::worthRetrying($aborted));
    }

    // ---- the one that looks retryable and is not --------------------------------------

    public function testAnOverflowIsNeverRetried(): void
    {
        $overflow = self::failed('Anthropic returned 429: prompt is too long: 213462 tokens > 200000 maximum');

        // 429 on its own would be waited out. This one is 429 *because* the request is too
        // big, and the request will be exactly as big in four seconds. Compaction answers it.
        $this->assertTrue(Overflow::happened($overflow));
        $this->assertFalse(Retry::worthRetrying($overflow));
    }

    public function testTheSilentOverflowIsNotRetriedEither(): void
    {
        // z.ai answers successfully having been sent more than the window holds; nothing
        // failed, so there is nothing to retry, and the usage is the only evidence.
        $silent = self::answered(input: 250_000);

        $this->assertTrue(Overflow::happened($silent, 200_000));
        $this->assertFalse(Retry::worthRetrying($silent, 200_000));
    }

    // ---- the waiting --------------------------------------------------------------------

    public function testTheDelayDoubles(): void
    {
        $this->assertSame(2.0, Retry::delayFor(1));
        $this->assertSame(4.0, Retry::delayFor(2));
        $this->assertSame(8.0, Retry::delayFor(3));
    }

    public function testTheBaseIsSettable(): void
    {
        $this->assertSame(0.5, Retry::delayFor(1, 0.5));
        $this->assertSame(2.0, Retry::delayFor(3, 0.5));
    }

    public function testAttemptZeroDoesNotAskForHalfAWait(): void
    {
        // `2 ** -1` is 0.5, which would make a nonsense of "the first wait is the base".
        $this->assertSame(2.0, Retry::delayFor(0));
    }
}
