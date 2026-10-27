<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;
use Pig\CodingAgent\Interactive\TokenSpeed;

final class TokenSpeedTest extends TestCase
{
    private float $now = 0.0;

    private function meter(): TokenSpeed
    {
        $this->now = 0.0;

        return new TokenSpeed(fn (): float => $this->now);
    }

    /** One answer: 800ms to the first chunk, then 500 tokens over two seconds of streaming. */
    private function answer(TokenSpeed $meter, int $tokens, float $ttft = 800.0, float $streaming = 2000.0, int $chunks = 10): ?string
    {
        $this->assertSame('⚡ …', $meter->start());
        $start = $this->now;
        for ($i = 0; $i < $chunks; $i++) {
            $this->now = $start + $ttft + $streaming * $i / max(1, $chunks - 1);
            $meter->chunk();
        }

        return $meter->end($tokens);
    }

    public function testTheRateIsOverTheStreamingAndTheWaitIsTheTimeToFirstToken(): void
    {
        $this->assertSame('⚡ 250 tok/s · TTFT 800ms', $this->answer($this->meter(), 500));
    }

    public function testTheSessionsAverageIsShownWhenItDiffersFromThisAnswer(): void
    {
        $meter = $this->meter();
        $this->answer($meter, 500);

        // 100 tokens over 2s: 50 tok/s; the session's 600 over 4s is 150.
        $this->assertSame('⚡ 50.0 tok/s · avg 150 · TTFT 1.20s', $this->answer($meter, 100, ttft: 1200.0));
    }

    public function testAnAnswerInOnePieceIsTimedFromTheRequest(): void
    {
        $meter = $this->meter();
        $meter->start();
        $this->now = 400.0;
        $meter->chunk();

        $this->assertSame('⚡ 100 tok/s · TTFT 400ms', $meter->end(40));
    }

    public function testAnImpossibleRateSaysHowManyTokensInstead(): void
    {
        // 9,000 tokens in under a second of "streaming" arrived buffered; the rate would only time the network.
        $this->assertSame('⚡ 9.0k tok · TTFT 800ms', $this->answer($this->meter(), 9000, streaming: 500.0));
    }

    public function testNoTokensIsACountAndAnEndWithNoStartIsNothing(): void
    {
        $meter = $this->meter();
        $this->assertNull($meter->end(10));
        $this->assertSame('⚡ 0 tok', $this->answer($meter, 0));
    }

    public function testResetForgetsTheAverage(): void
    {
        $meter = $this->meter();
        $this->answer($meter, 500);
        $meter->reset();

        $this->assertSame('⚡ 50.0 tok/s · TTFT 800ms', $this->answer($meter, 100));
    }
}
