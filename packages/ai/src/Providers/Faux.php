<?php

declare(strict_types=1);

namespace Pig\Ai\Providers;

use Pig\Ai\Api;
use Pig\Ai\AssistantMessage;
use Pig\Ai\StopReason;
use Pig\Ai\TextContent;
use Pig\Ai\ThinkingContent;
use Pig\Ai\Timestamp;
use Pig\Ai\ToolCall;
use Pig\Ai\Usage;

/**
 * Upstream's `providers/faux.ts`, the module-level half: the content helpers, the scripted
 * message, and `fauxProvider()` — a provider that answers from a queue of scripted messages, streams
 * each in token-sized pieces, and estimates its usage from the text, for tests built on a provider
 * rather than on a canned server.
 *
 * The provider itself is `FauxProvider`. A scripted message speaks `Api::Extension` (upstream's
 * `"faux"` api id is the provider's own; in pig that is what an extension's protocol is) and is
 * rewritten to the provider and model it is streamed for.
 */
final class Faux
{
    public const string DEFAULT_PROVIDER = 'faux';

    public const string DEFAULT_MODEL_ID = 'faux-1';

    public static function fauxText(string $text): TextContent
    {
        return new TextContent($text);
    }

    public static function fauxThinking(string $thinking): ThinkingContent
    {
        return new ThinkingContent($thinking);
    }

    /** @param array<string, mixed> $arguments */
    public static function fauxToolCall(string $name, array $arguments, ?string $id = null): ToolCall
    {
        return new ToolCall($id ?? self::randomId('tool'), $name, $arguments);
    }

    /**
     * Upstream's `fauxAssistantMessage(content, options)`: a string is one text block, a block is a
     * list of one; `stop` unless told otherwise; zero usage until the provider estimates it.
     *
     * @param string|TextContent|ThinkingContent|ToolCall|list<TextContent|ThinkingContent|ToolCall> $content
     */
    public static function fauxAssistantMessage(
        string|TextContent|ThinkingContent|ToolCall|array $content,
        StopReason $stopReason = StopReason::Stop,
        ?string $errorMessage = null,
        ?string $responseId = null,
        ?int $timestamp = null,
    ): AssistantMessage {
        return new AssistantMessage(
            match (true) {
                is_string($content) => [self::fauxText($content)],
                is_array($content) => $content,
                default => [$content],
            },
            Api::Extension,
            self::DEFAULT_PROVIDER,
            self::DEFAULT_MODEL_ID,
            new Usage(),
            $stopReason,
            $errorMessage,
            $timestamp ?? Timestamp::nowMs(),
            responseId: $responseId,
        );
    }

    /**
     * "Faux provider for tests built on explicit `Models` collections" — upstream's
     * `fauxProvider(options)`. Register `->provider()` with `Extension\ProviderRegistry` to reach it
     * through `Stream`, or call `->stream()` on it directly.
     *
     * @param list<array{id: string, name?: string, reasoning?: bool, input?: list<string>, inputLimits?: array<string, mixed>, cost?: array{input: float, output: float, cacheRead: float, cacheWrite: float}, contextWindow?: int, maxTokens?: int}> $models
     *        upstream's `FauxModelDefinition`s; none gives the one default model
     * @param array{min?: int, max?: int} $tokenSize
     */
    public static function fauxProvider(
        string $provider = self::DEFAULT_PROVIDER,
        array $models = [],
        ?float $tokensPerSecond = null,
        array $tokenSize = [],
    ): FauxProvider {
        return new FauxProvider($provider, $models, $tokensPerSecond, $tokenSize);
    }

    /** Upstream's `randomId(prefix)`: `<prefix>:<now>:<random base 36>`. */
    public static function randomId(string $prefix): string
    {
        return $prefix . ':' . Timestamp::nowMs() . ':' . base_convert((string) random_int(0, PHP_INT_MAX), 10, 36);
    }
}
