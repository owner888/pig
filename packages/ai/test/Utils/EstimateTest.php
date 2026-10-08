<?php

declare(strict_types=1);

namespace Pig\Ai\Test\Utils;

use PHPUnit\Framework\TestCase;
use Pig\Ai\Api;
use Pig\Ai\AssistantMessage;
use Pig\Ai\Context;
use Pig\Ai\ImageContent;
use Pig\Ai\StopReason;
use Pig\Ai\TextContent;
use Pig\Ai\Tool;
use Pig\Ai\Usage;
use Pig\Ai\UserMessage;
use Pig\Ai\Utils\Estimate;

/**
 * Upstream's `estimateContextTokens()`, which `Stream::simple()` now reads to cut the answer's
 * ceiling to the room the conversation leaves (`clampMaxTokensToContext()`).
 */
final class EstimateTest extends TestCase
{
    public function testWithNoUsageEverythingIsEstimatedAtThreeAndAHalfCharactersAToken(): void
    {
        // The prompt and the tools count as upstream's leading system message; an image is 4,800
        // characters. 7 characters of prompt → 2; 35 of text + an image (4,835) → 1,382.
        $context = new Context(
            [new UserMessage([new TextContent(str_repeat('a', 35)), new ImageContent('AAAA', 'image/png')])],
            'be nice',
        );

        $this->assertSame(2 + 1_382, Estimate::contextTokens($context));
    }

    public function testTheToolsAreCountedAsTheirJson(): void
    {
        $tool = new Tool('read', 'Read', ['type' => 'object']);
        $json = '[{"name":"read","description":"Read","parameters":{"type":"object"}}]';

        $this->assertSame((int) ceil(strlen($json) / 3.5), Estimate::contextTokens(new Context([], null, [$tool])));
    }

    public function testTheLastFinishedTurnsUsageIsTheMeasureOfEverythingBeforeIt(): void
    {
        // Measured beats estimated: the turn's own count stands for the conversation up to it,
        // and only what came after is estimated. An errored turn's usage is not used.
        $context = new Context([
            new UserMessage(str_repeat('a', 700), 1),
            $this->turn(new Usage(totalTokens: 5_000), StopReason::Stop, 2),
            new UserMessage(str_repeat('b', 35), 3),
            $this->turn(new Usage(totalTokens: 9_999), StopReason::Error, 4),
        ]);

        // 5,000 + 'b'×35 (10) + the errored turn's own text (2 characters → 1).
        $this->assertSame(5_011, Estimate::contextTokens($context));
    }

    public function testLengthsAreUtf16CodeUnitsAsJavaScriptCountsThem(): void
    {
        // Seven characters outside the BMP, two code units each: 14 / 3.5 → 4 — where counting
        // characters would say 2 and counting bytes 8.
        $this->assertSame(4, Estimate::contextTokens(new Context([new UserMessage(str_repeat('😀', 7))])));
    }

    private function turn(Usage $usage, StopReason $stop, int $timestamp): AssistantMessage
    {
        return new AssistantMessage([new TextContent('ok')], Api::AnthropicMessages, 'anthropic', 'm', $usage, $stop, timestamp: $timestamp);
    }
}
