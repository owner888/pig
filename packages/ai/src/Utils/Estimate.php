<?php

declare(strict_types=1);

namespace Pig\Ai\Utils;

use Pig\Ai\AssistantMessage;
use Pig\Ai\Context;
use Pig\Ai\ImageContent;
use Pig\Ai\StopReason;
use Pig\Ai\TextContent;
use Pig\Ai\ThinkingContent;
use Pig\Ai\Tool;
use Pig\Ai\ToolCall;
use Pig\Ai\ToolResultMessage;
use Pig\Ai\Usage;
use Pig\Ai\UserMessage;

/**
 * Upstream's `utils/estimate.ts`, the half of it `clampMaxTokensToContext()` reads: how many tokens
 * a conversation already takes.
 *
 * The last finished assistant turn's usage is the measured size of everything up to it, and what
 * came after it is estimated at 3.5 characters a token (an image as 4,800 characters). With no
 * usable turn the whole conversation is estimated.
 *
 * **Where pig's shape differs**: upstream's transcript opens with a system message carrying the
 * prompt and the tools (`createInitialSystemMessage()`, timestamp 0); pig keeps both on `Context`.
 * They are counted here as that message would be — the prompt's text plus the tools' JSON — at
 * the same place, first and with timestamp 0. Lengths are UTF-16 code units, which is what
 * JavaScript's `.length` counts.
 */
final class Estimate
{
    private const float CHARS_PER_TOKEN = 3.5;

    private const int ESTIMATED_IMAGE_CHARS = 4800;

    /** Upstream's `estimateContextTokens(context).tokens`. */
    public static function contextTokens(Context $context): int
    {
        // [timestamp, tokens, usage when it is an assistant turn whose usage applies]
        $entries = [];
        $systemText = $context->systemPrompt ?? '';

        if ($systemText !== '' || $context->tools !== []) {
            $entries[] = [0, self::textTokens($systemText) + self::toolsTokens($context->tools), null, null];
        }

        foreach ($context->messages as $message) {
            if ($message instanceof UserMessage || $message instanceof AssistantMessage || $message instanceof ToolResultMessage) {
                $entries[] = [$message->timestamp, self::messageTokens($message), $message instanceof AssistantMessage ? $message : null];
            }
        }

        // Upstream's `getLastAssistantUsageInfo()`: the latest finished turn whose usage is not
        // older than a message placed before it (a compaction summary, say).
        $latestPrefixTimestamp = PHP_INT_MIN;
        $usageIndex = null;

        foreach ($entries as $index => [$timestamp, , $assistant]) {
            if ($assistant !== null
                && $assistant->timestamp >= $latestPrefixTimestamp
                && $assistant->stopReason !== StopReason::Aborted
                && $assistant->stopReason !== StopReason::Error
                && self::usageTokens($assistant->usage) > 0) {
                $usageIndex = $index;
            }

            $latestPrefixTimestamp = max($latestPrefixTimestamp, $timestamp);
        }

        if ($usageIndex === null) {
            return array_sum(array_column($entries, 1));
        }

        $tokens = self::usageTokens($entries[$usageIndex][2]->usage);

        for ($i = $usageIndex + 1, $end = count($entries); $i < $end; $i++) {
            $tokens += $entries[$i][1];
        }

        return $tokens;
    }

    /** Upstream's `calculateContextTokens()`. */
    private static function usageTokens(Usage $usage): int
    {
        return $usage->totalTokens ?: $usage->input + $usage->output + $usage->cacheRead + $usage->cacheWrite;
    }

    private static function messageTokens(UserMessage|AssistantMessage|ToolResultMessage $message): int
    {
        if (!$message instanceof AssistantMessage) {
            $chars = 0;

            foreach ($message->content as $block) {
                $chars += $block instanceof TextContent
                    ? self::length($block->text)
                    : ($block instanceof ImageContent ? self::ESTIMATED_IMAGE_CHARS : 0);
            }

            return (int) ceil($chars / self::CHARS_PER_TOKEN);
        }

        $chars = 0;

        foreach ($message->content as $block) {
            $chars += match (true) {
                $block instanceof TextContent => self::length($block->text),
                $block instanceof ThinkingContent => self::length($block->thinking),
                $block instanceof ToolCall => self::length($block->name) + self::length(self::json($block->arguments === [] ? new \stdClass() : $block->arguments)),
                default => 0,
            };
        }

        return (int) ceil($chars / self::CHARS_PER_TOKEN);
    }

    private static function textTokens(string $text): int
    {
        return (int) ceil(self::length($text) / self::CHARS_PER_TOKEN);
    }

    /**
     * Upstream's `estimateToolsTokens()`: the tools' JSON, as `JSON.stringify` writes the `Tool`
     * objects — name, description, parameters, and `constrainedSampling` when set.
     *
     * @param list<Tool> $tools
     */
    private static function toolsTokens(array $tools): int
    {
        if ($tools === []) {
            return 0;
        }

        $encoded = array_map(static fn (Tool $tool): array => [
            'name' => $tool->name,
            'description' => $tool->description,
            'parameters' => $tool->parameters,
        ] + ($tool->constrainedSampling === null ? [] : ['constrainedSampling' => $tool->constrainedSampling]), $tools);

        return self::textTokens(self::json($encoded));
    }

    private static function json(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /** JavaScript's `.length`: UTF-16 code units. */
    private static function length(string $text): int
    {
        if ($text === '') {
            return 0;
        }

        return intdiv(strlen((string) mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')), 2);
    }
}
