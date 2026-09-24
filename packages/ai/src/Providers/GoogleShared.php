<?php

declare(strict_types=1);

namespace Pig\Ai\Providers;

use Pig\Ai\AssistantMessage;
use Pig\Ai\Context;
use Pig\Ai\ImageContent;
use Pig\Ai\Model;
use Pig\Ai\ProviderError;
use Pig\Ai\StopReason;
use Pig\Ai\TextContent;
use Pig\Ai\TextDeltaEvent;
use Pig\Ai\TextEndEvent;
use Pig\Ai\TextStartEvent;
use Pig\Ai\ThinkingContent;
use Pig\Ai\ThinkingDeltaEvent;
use Pig\Ai\ThinkingEndEvent;
use Pig\Ai\ThinkingStartEvent;
use Pig\Ai\Timestamp;
use Pig\Ai\Tool;
use Pig\Ai\ToolCall;
use Pig\Ai\ToolCallDeltaEvent;
use Pig\Ai\ToolCallEndEvent;
use Pig\Ai\ToolCallStartEvent;
use Pig\Ai\ToolResultMessage;
use Pig\Ai\Usage;
use Pig\Ai\UserMessage;
use Pig\Ai\Utils\AssistantMessageEventStream;
use Pig\Ai\Utils\Utf8;
use stdClass;

/**
 * What a Gemini request and a Gemini chunk look like, for the two providers that send them.
 *
 * Upstream's `providers/google-shared.ts`, and it exists here for the reason it exists there:
 * **two providers speak this shape.** `Google` is the public Generative Language API and
 * `GoogleGeminiCli` is Google Cloud Code Assist, which wraps the same request in a project
 * envelope and returns the same chunk one key deeper. pig had only the first, so all of this sat
 * in `Google` as private methods; the second one arriving is what makes upstream's split the
 * right shape here too.
 *
 * What is **not** here is each provider's own business: where it sends, how it authenticates,
 * how it words a failure, and how it assembles the body around these pieces. Upstream shares
 * less than this — it keeps the chunk walk in each provider — but the chunk is the same shape
 * one key deeper, and two walks over it are two things that can come to disagree about what a
 * Gemini answer is.
 */
final class GoogleShared
{
    /** An id Gemini did not give us has to be invented, and be unique in the message. */
    private static int $invented = 0;

    // ---- the request ---------------------------------------------------------------------

    /**
     * The conversation, as Gemini's `contents`.
     *
     * @return list<array<string, mixed>>
     */
    public static function contents(Model $model, Context $context): array
    {
        $contents = [];

        foreach (TransformMessages::apply($context->messages, $model) as $message) {
            if ($message instanceof UserMessage) {
                $parts = self::parts($message->content, $model);

                if ($parts !== []) {
                    $contents[] = ['role' => 'user', 'parts' => $parts];
                }

                continue;
            }

            if ($message instanceof AssistantMessage) {
                $parts = self::assistantParts($message);

                if ($parts !== []) {
                    $contents[] = ['role' => 'model', 'parts' => $parts];
                }

                continue;
            }

            if ($message instanceof ToolResultMessage) {
                self::addResult($contents, $message, $model);
            }
        }

        return $contents;
    }

    /**
     * The tools, in the one-element list Gemini wants them in.
     *
     * @param list<Tool> $tools
     * @return list<array<string, mixed>>
     */
    public static function tools(array $tools): array
    {
        return [['functionDeclarations' => array_map(self::tool(...), $tools)]];
    }

    /** @return array<string, mixed> */
    public static function toolConfig(string $choice): array
    {
        return ['functionCallingConfig' => ['mode' => strtoupper($choice)]];
    }

    /** @return array<string, mixed> */
    public static function systemInstruction(string $prompt): array
    {
        return ['parts' => [['text' => Utf8::sanitize($prompt)]]];
    }

    // ---- the response --------------------------------------------------------------------

    /**
     * One chunk, as a Gemini candidate.
     *
     * Code Assist's chunks arrive under a `response` key; **the caller unwraps**, so that this
     * is handed the same thing from both providers rather than having to know which one it is
     * talking for.
     *
     * @param array<string, mixed> $data
     * @param array{0: int, 1: string}|null $open
     * @return array{0: int, 1: string}|null
     */
    public static function onChunk(
        array $data,
        AssistantMessageBuilder $builder,
        AssistantMessageEventStream $stream,
        ?array $open,
    ): ?array {
        // A blocked prompt comes back as a 200 with nothing in it but the reason.
        $blocked = $data['promptFeedback']['blockReason'] ?? null;

        if (is_string($blocked)) {
            throw new ProviderError("Gemini refused the prompt: {$blocked}");
        }

        $candidate = $data['candidates'][0] ?? [];

        foreach ($candidate['content']['parts'] ?? [] as $part) {
            if (is_array($part)) {
                $open = self::onPart($part, $builder, $stream, $open);
            }
        }

        if (is_string($candidate['finishReason'] ?? null)) {
            $reason = self::stopReason($candidate['finishReason']);

            // A turn that called a tool is finished with the turn, not the task, and
            // Gemini says STOP for both.
            $builder->setStopReason(self::hasToolCall($builder->snapshot()) ? StopReason::ToolUse : $reason);
        }

        if (is_array($data['usageMetadata'] ?? null)) {
            $builder->setUsage(self::usage($data['usageMetadata']));
        }

        return $open;
    }

    /** @param array{0: int, 1: string}|null $open */
    public static function close(
        AssistantMessageBuilder $builder,
        AssistantMessageEventStream $stream,
        ?array $open,
    ): void {
        if ($open === null) {
            return;
        }

        [$index, $kind] = $open;
        $snapshot = $builder->snapshot();

        $stream->push($kind === 'thinking'
            ? new ThinkingEndEvent($index, $builder->textOf($index), $snapshot)
            : new TextEndEvent($index, $builder->textOf($index), $snapshot));
    }

    /**
     * Gemini has twenty finish reasons and eighteen of them are "no".
     *
     * Safety blocks, recitation, a malformed call, a language it will not answer in —
     * all of them mean the turn produced nothing usable, which is an error however
     * politely it is phrased.
     */
    public static function stopReason(string $reason): StopReason
    {
        return match ($reason) {
            'STOP' => StopReason::Stop,
            'MAX_TOKENS' => StopReason::Length,
            default => StopReason::Error,
        };
    }

    /** @param array<string, mixed> $usage */
    public static function usage(array $usage): Usage
    {
        // Thinking is billed as output and reported separately, so it is added in here
        // rather than left out of what the turn cost.
        return new Usage(
            (int) ($usage['promptTokenCount'] ?? 0),
            (int) ($usage['candidatesTokenCount'] ?? 0) + (int) ($usage['thoughtsTokenCount'] ?? 0),
            (int) ($usage['cachedContentTokenCount'] ?? 0),
            0,
            (int) ($usage['totalTokenCount'] ?? 0),
        );
    }

    // ---- the parts of a chunk ------------------------------------------------------------

    /**
     * @param array<string, mixed> $part
     * @param array{0: int, 1: string}|null $open
     * @return array{0: int, 1: string}|null
     */
    private static function onPart(
        array $part,
        AssistantMessageBuilder $builder,
        AssistantMessageEventStream $stream,
        ?array $open,
    ): ?array {
        if (is_array($part['functionCall'] ?? null)) {
            // A call arrives whole, so the block opens, fills and closes here.
            self::close($builder, $stream, $open);

            return self::wholeCall($part, $builder, $stream);
        }

        $text = $part['text'] ?? null;

        if (!is_string($text) || $text === '') {
            return $open;
        }

        // The one field, told apart by a flag. Thinking and answer look identical
        // otherwise, and a block ends where the flag changes.
        $kind = ($part['thought'] ?? false) === true ? 'thinking' : 'text';

        if ($open === null || $open[1] !== $kind) {
            self::close($builder, $stream, $open);
            $index = $kind === 'thinking' ? $builder->startThinking($builder->nextWire()) : $builder->startText($builder->nextWire());
            $stream->push($kind === 'thinking'
                ? new ThinkingStartEvent($index, $builder->snapshot())
                : new TextStartEvent($index, $builder->snapshot()));
            $open = [$index, $kind];
        }

        $builder->append($open[0], 'text', $text);

        // The signature authenticates the thought and has to go back with it.
        if ($kind === 'thinking' && is_string($part['thoughtSignature'] ?? null)) {
            $builder->setSignature($open[0], $part['thoughtSignature']);
        }

        $stream->push($kind === 'thinking'
            ? new ThinkingDeltaEvent($open[0], $text, $builder->snapshot())
            : new TextDeltaEvent($open[0], $text, $builder->snapshot()));

        return $open;
    }

    /** @param array<string, mixed> $part */
    private static function wholeCall(
        array $part,
        AssistantMessageBuilder $builder,
        AssistantMessageEventStream $stream,
    ): ?array {
        $call = $part['functionCall'];
        $name = (string) ($call['name'] ?? '');
        $index = $builder->startToolCall($builder->nextWire(), self::callId($call, $name, $builder), $name);

        $arguments = (string) json_encode($call['args'] ?? new stdClass());
        $builder->append($index, 'json', $arguments);

        if (is_string($part['thoughtSignature'] ?? null)) {
            $builder->setSignature($index, $part['thoughtSignature']);
        }

        $stream->push(new ToolCallStartEvent($index, $builder->snapshot()));
        $stream->push(new ToolCallDeltaEvent($index, $arguments, $builder->snapshot()));
        $stream->push(new ToolCallEndEvent($index, $builder->toolCallOf($index), $builder->snapshot()));

        return null;
    }

    /**
     * An id for a call, invented when Gemini gives none.
     *
     * It often gives none, and a result has to be addressed to something. Two calls in a
     * message sharing an id is the same problem, so a repeat is replaced too.
     *
     * @param array<string, mixed> $call
     */
    private static function callId(array $call, string $name, AssistantMessageBuilder $builder): string
    {
        $given = $call['id'] ?? null;

        if (is_string($given) && $given !== '' && !self::hasCallId($builder->snapshot(), $given)) {
            return $given;
        }

        return $name . '_' . Timestamp::nowMs() . '_' . ++self::$invented;
    }

    private static function hasCallId(AssistantMessage $message, string $id): bool
    {
        foreach ($message->content as $block) {
            if ($block instanceof ToolCall && $block->id === $id) {
                return true;
            }
        }

        return false;
    }

    private static function hasToolCall(AssistantMessage $message): bool
    {
        foreach ($message->content as $block) {
            if ($block instanceof ToolCall) {
                return true;
            }
        }

        return false;
    }

    // ---- the parts of a request ----------------------------------------------------------

    /** @return array<string, mixed> */
    private static function tool(Tool $tool): array
    {
        return [
            'name' => $tool->name,
            'description' => $tool->description,
            'parameters' => $tool->parameters,
        ];
    }

    /**
     * @param list<mixed> $content
     * @return list<array<string, mixed>>
     */
    private static function parts(array $content, Model $model): array
    {
        $parts = [];

        foreach ($content as $block) {
            if ($block instanceof TextContent) {
                $parts[] = ['text' => Utf8::sanitize($block->text)];

                continue;
            }

            if ($block instanceof ImageContent && $model->acceptsImages()) {
                $parts[] = ['inlineData' => ['mimeType' => $block->mimeType, 'data' => $block->data]];
            }
        }

        return $parts;
    }

    /** @return list<array<string, mixed>> */
    private static function assistantParts(AssistantMessage $message): array
    {
        $parts = [];

        foreach ($message->content as $block) {
            if ($block instanceof TextContent) {
                // An empty text part upsets some models served through this API.
                if (trim($block->text) !== '') {
                    $parts[] = ['text' => Utf8::sanitize($block->text)];
                }

                continue;
            }

            if ($block instanceof ThinkingContent) {
                // A thought without its signature cannot be replayed as a thought — it
                // is rejected — so it goes back as tagged text instead of being dropped.
                $parts[] = $block->thinkingSignature !== null && $block->thinkingSignature !== ''
                    ? [
                        'thought' => true,
                        'text' => Utf8::sanitize($block->thinking),
                        'thoughtSignature' => $block->thinkingSignature,
                    ]
                    : ['text' => "<thinking>\n" . Utf8::sanitize($block->thinking) . "\n</thinking>"];

                continue;
            }

            if ($block instanceof ToolCall) {
                $part = ['functionCall' => [
                    'id' => $block->id,
                    'name' => $block->name,
                    'args' => $block->arguments === [] ? new stdClass() : $block->arguments,
                ]];

                if ($block->thoughtSignature !== null && $block->thoughtSignature !== '') {
                    $part['thoughtSignature'] = $block->thoughtSignature;
                }

                $parts[] = $part;
            }
        }

        return $parts;
    }

    /**
     * Add a tool result, merging it into the user turn before it when there is one.
     *
     * Consecutive results belong to one turn here, the way they do for Anthropic — the
     * Cloud Code endpoint requires it, and the public one accepts it either way.
     *
     * @param list<array<string, mixed>> $contents
     */
    private static function addResult(array &$contents, ToolResultMessage $message, Model $model): void
    {
        $text = [];
        $images = [];

        foreach ($message->content as $block) {
            if ($block instanceof TextContent) {
                $text[] = $block->text;
            } elseif ($block instanceof ImageContent && $model->acceptsImages()) {
                $images[] = ['inlineData' => ['mimeType' => $block->mimeType, 'data' => $block->data]];
            }
        }

        $value = $text !== [] ? Utf8::sanitize(implode("\n", $text)) : ($images !== [] ? '(see attached image)' : '');

        // Gemini 3 takes images inside the response; older models have nowhere to put
        // them and need a user turn of their own.
        $nested = str_contains($model->id, 'gemini-3');

        $response = [
            'id' => $message->toolCallId,
            'name' => $message->toolName,
            'response' => $message->isError ? ['error' => $value] : ['output' => $value],
        ];

        if ($images !== [] && $nested) {
            $response['parts'] = $images;
        }

        $last = $contents[count($contents) - 1] ?? null;

        if ($last !== null && $last['role'] === 'user' && self::holdsResults($last)) {
            $contents[count($contents) - 1]['parts'][] = ['functionResponse' => $response];
        } else {
            $contents[] = ['role' => 'user', 'parts' => [['functionResponse' => $response]]];
        }

        if ($images !== [] && !$nested) {
            $contents[] = ['role' => 'user', 'parts' => [['text' => 'Tool result image:'], ...$images]];
        }
    }

    /** @param array<string, mixed> $content */
    private static function holdsResults(array $content): bool
    {
        foreach ($content['parts'] ?? [] as $part) {
            if (isset($part['functionResponse'])) {
                return true;
            }
        }

        return false;
    }
}
