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
use Pig\Ai\Utils\ConstrainedSampling;
use Pig\Ai\Utils\Utf8;
use stdClass;

/**
 * What a Gemini request and a Gemini chunk look like, for the two providers that send them.
 *
 * Upstream's `providers/google-shared.ts`, and it exists here for the reason it exists there:
 * **two providers speak this shape.** `Google` is the public Generative Language API and
 * `Antigravity` is Google Code Assist, which wraps the same request in a project
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

        // Upstream's local `normalizeToolCallId`: only a model that is sent ids has them made safe.
        $normalizeToolCallId = static fn (string $id): string => self::requiresToolCallId($model->id)
            ? substr((string) preg_replace('/[^a-zA-Z0-9_-]/', '_', $id), 0, 64)
            : $id;

        foreach (TransformMessages::apply($context->messages, $model, $normalizeToolCallId) as $message) {
            if ($message instanceof UserMessage) {
                $parts = self::parts($message->content, $model);

                if ($parts !== []) {
                    $contents[] = ['role' => 'user', 'parts' => $parts];
                }

                continue;
            }

            if ($message instanceof AssistantMessage) {
                $parts = self::assistantParts($message, $model);

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

    // ---- thinking --------------------------------------------------------------------------

    /** The levels in upstream's `EXTENDED_THINKING_LEVELS` order, without `max`, which pig has no level for. */
    private const array THINKING_LEVELS = ['off', 'minimal', 'low', 'medium', 'high', 'xhigh'];

    /**
     * Upstream's `usesGoogleThinkingLevel()`: whether this model takes Gemini's named
     * `thinkingLevel` rather than a `thinkingBudget` in tokens.
     *
     * Upstream's comment: which levels it supports comes from the model's `thinkingLevelMap`; this
     * only selects the wire format. Gemini 3 Pro and Flash with or without a minor version
     * (`gemini-3-flash-preview`, `gemini-3.1-pro-preview`, `gemini-3.8-flash`), the two
     * `-latest` aliases, and Gemma 4 in both of its hosted spellings, `gemma-4-*` and `gemma4-*`.
     */
    public static function usesGoogleThinkingLevel(Model $model): bool
    {
        $id = strtolower($model->id);

        return preg_match('/gemini-3(?:\.\d+)?-(?:pro|flash)/', $id) === 1
            || $id === 'gemini-flash-latest'
            || $id === 'gemini-flash-lite-latest'
            || preg_match('/gemma-?4/', $id) === 1;
    }

    /**
     * Upstream's `resolveGoogleThinkingLevel()`: a level, through the model's `thinkingLevelMap`,
     * as one of Google's four. A mapping to anything else is refused by name, as upstream throws.
     */
    public static function resolveGoogleThinkingLevel(Model $model, string $level): string
    {
        $mapped = $model->thinkingLevelMap[$level] ?? null;
        $resolvedLevel = is_string($mapped) ? strtolower($mapped) : $level;

        return match ($resolvedLevel) {
            'minimal', 'low', 'medium', 'high' => $resolvedLevel,
            default => throw new ProviderError(
                "Unsupported Google thinking level mapping for {$model->provider}/{$model->id}: {$level} -> "
                . (array_key_exists($level, $model->thinkingLevelMap) ? ($mapped ?? 'null') : 'undefined'),
            ),
        };
    }

    /** Upstream's `toGoogleThinkingLevel()`: `minimal` → `MINIMAL`, and so on. */
    public static function toGoogleThinkingLevel(string $level): string
    {
        return strtoupper($level);
    }

    /**
     * Upstream's `getDisabledGoogleThinkingConfig()`: what "no thinking" is for this model.
     *
     * `thinkingBudget: 0`, unless the model takes a level and has no `off` — Gemini 3.1 Pro answers
     * a zero budget with a 400 ("only works in thinking mode") — in which case it is the level
     * `off` clamps to, upstream's `clampThinkingLevel(model, "off")`: the first level up from `off`
     * that the model has.
     *
     * @return array<string, mixed>
     */
    public static function disabledGoogleThinkingConfig(Model $model): array
    {
        if (!self::usesGoogleThinkingLevel($model)) {
            return ['thinkingBudget' => 0];
        }

        $fallback = self::clampOff($model);

        if ($fallback === 'off') {
            return ['thinkingBudget' => 0];
        }

        return ['thinkingLevel' => self::toGoogleThinkingLevel(self::resolveGoogleThinkingLevel($model, $fallback))];
    }

    /**
     * Upstream's `clampThinkingLevel(model, "off")`, over `getSupportedThinkingLevels()`.
     *
     * Here rather than `Pig\Agent\ThinkingLevel::clampedFor()`, which is the same search, because
     * this package cannot see that one. Only the search from `off` is needed, and from `off` it is
     * upward only: the first level the model has, which is also upstream's `availableLevels[0]`.
     */
    private static function clampOff(Model $model): string
    {
        if (!$model->reasoning) {
            return 'off';
        }

        foreach (self::THINKING_LEVELS as $level) {
            $supported = $level === 'xhigh' ? $model->supportsXhigh() : $model->hasThinkingLevel($level);

            if ($supported) {
                return $level;
            }
        }

        return 'off';
    }

    /**
     * Upstream's `requiresToolCallId()`: the models behind Google's APIs that need a call's `id`
     * on its `functionCall` and `functionResponse` — Claude, gpt-oss, and Gemini 3 and later.
     */
    public static function requiresToolCallId(string $modelId): bool
    {
        $geminiMajorVersion = self::geminiMajorVersion($modelId);

        return str_starts_with($modelId, 'claude-')
            || str_starts_with($modelId, 'gpt-oss-')
            || ($geminiMajorVersion !== null && $geminiMajorVersion >= 3);
    }

    /** Upstream's `getGeminiMajorVersion()`: `gemini-3.8-flash` is 3, `gemini-live-2.5-…` is 2, `claude-…` is null. */
    private static function geminiMajorVersion(string $modelId): ?int
    {
        return preg_match('/^gemini(?:-live)?-(\d+)/', strtolower($modelId), $match) === 1 ? (int) $match[1] : null;
    }

    /**
     * Upstream's `supportsMultimodalFunctionResponse()`: Gemini 3 and later take a tool result's
     * images inside the `functionResponse`; older Gemini needs them in a user turn of their own.
     * **A model that is not Gemini at all is true** — upstream's choice, read off its code.
     */
    private static function supportsMultimodalFunctionResponse(string $modelId): bool
    {
        $geminiMajorVersion = self::geminiMajorVersion($modelId);

        return $geminiMajorVersion !== null ? $geminiMajorVersion >= 3 : true;
    }

    /**
     * The tools, in the one-element list Gemini wants them in, for the Code Assist path
     * (`pig-antigravity`): the legacy `parameters` schema, meta-declarations stripped — upstream's
     * `convertTools(tools, true)` minus strict sampling, which upstream's surviving callers only
     * ask for on the direct API. The direct API goes through `convertTools()`.
     *
     * @param list<Tool> $tools
     * @return list<array<string, mixed>>
     */
    public static function tools(array $tools): array
    {
        return [['functionDeclarations' => array_map(self::tool(...), $tools)]];
    }

    /**
     * The Code Assist path's tool choice (`pig-antigravity`): the choice upper-cased into a mode.
     *
     * Not upstream's `resolveGoogleFunctionCallingMode()`, which only the direct API uses
     * (`Google::body()`): upstream no longer has a Code Assist provider to compare against, and the
     * one it had used the legacy `parameters` schema — see `tools()`.
     *
     * @return array<string, mixed>
     */
    public static function toolConfig(string $choice): array
    {
        return ['functionCallingConfig' => ['mode' => strtoupper($choice)]];
    }

    /**
     * Upstream's `convertTools(tools, useParameters = false, supportsStrictMode = true)`, literally.
     *
     * By default the schema goes in `parametersJsonSchema`, which takes full JSON Schema
     * (`anyOf`, `const`, `$schema` and the rest) as written. `$useParameters` sends the legacy
     * OpenAPI 3.0 `parameters` instead, stripped of JSON Schema's meta-declarations — what a Code
     * Assist endpoint translating to Anthropic's `input_schema` needs. A tool that goes strict
     * (`ConstrainedSampling`) is sent in its strict form either way.
     *
     * @param list<Tool> $tools
     * @return list<array<string, mixed>>|null null for no tools, upstream's `undefined`
     */
    public static function convertTools(array $tools, bool $useParameters = false, bool $supportsStrictMode = true): ?array
    {
        if ($tools === []) {
            return null;
        }

        return [[
            'functionDeclarations' => array_map(static function (Tool $tool) use ($useParameters, $supportsStrictMode): array {
                $strict = ConstrainedSampling::resolveJsonSchemaStrictSampling($tool, $supportsStrictMode);
                $parameters = ConstrainedSampling::getJsonSchemaToolParameters($tool, $strict);

                return [
                    'name' => $tool->name,
                    'description' => $tool->description,
                    ...($useParameters
                        ? ['parameters' => self::sanitizeForOpenApi($parameters)]
                        : ['parametersJsonSchema' => $parameters]),
                ];
            }, $tools),
        ]];
    }

    /** Upstream's `supportsGoogleStrictToolSampling()`: Gemini 3+ enforces required function parameters in validated tool-calling modes. */
    public static function supportsGoogleStrictToolSampling(string $modelId): bool
    {
        $majorVersion = self::geminiMajorVersion($modelId);

        return $majorVersion !== null && $majorVersion >= 3;
    }

    /** Upstream's `mapToolChoice()`: a tool choice as Gemini's `FunctionCallingConfigMode`; anything unknown is `AUTO`. */
    public static function mapToolChoice(string $choice): string
    {
        return match ($choice) {
            'none' => 'NONE',
            'any' => 'ANY',
            default => 'AUTO',
        };
    }

    /**
     * Upstream's `resolveGoogleFunctionCallingMode()`, literally.
     *
     * `none` and `any` are said as asked. Otherwise a tool that goes strict makes the mode
     * `VALIDATED` — Gemini then holds the call to the schema, required parameters included — and
     * with none, the choice is mapped when there is one and the mode left unsaid when not.
     *
     * @param list<Tool> $tools
     */
    public static function resolveGoogleFunctionCallingMode(array $tools, ?string $toolChoice, bool $supportsStrictMode): ?string
    {
        $useStrictMode = false;

        foreach ($tools as $tool) {
            if (ConstrainedSampling::resolveJsonSchemaStrictSampling($tool, $supportsStrictMode) === true) {
                $useStrictMode = true;

                break;
            }
        }

        if ($toolChoice === 'none' || $toolChoice === 'any') {
            return self::mapToolChoice($toolChoice);
        }

        if ($useStrictMode) {
            return 'VALIDATED';
        }

        // JS truthiness: an empty string is no choice.
        return $toolChoice !== null && $toolChoice !== '' ? self::mapToolChoice($toolChoice) : null;
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
        bool $deferErrors = false,
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

        // **Before the finish reason, because that one can throw.** The usage rides on the same
        // chunk as the reason, so reading it afterwards loses it for exactly the turns that failed
        // — and a turn Gemini refused was still billed for its input. Measured: with these two the
        // other way round, a safety block came back `input=0 output=0` on a prompt of 40 tokens.
        // The same fact `AnthropicTest::testAnAbortedTurnKeepsTheUsageThatHadAlreadyArrived` pins
        // from the other end: a turn that produced nothing did not therefore cost nothing.
        if (is_array($data['usageMetadata'] ?? null)) {
            $builder->setUsage(self::usage($data['usageMetadata']));
        }

        if (is_string($candidate['finishReason'] ?? null)) {
            // Set before anything can throw, so a turn that fails on the reason below still says
            // which reason it was: `fail()` leaves it alone, as upstream's catch leaves the field.
            $builder->setRawStopReason($candidate['finishReason']);

            // Upstream `google-generative-ai.ts`: the reason is mapped first, and only a STOP
            // with a tool call in it becomes `toolUse` — Gemini says STOP for a turn that called
            // a tool and for one that finished. MAX_TOKENS with a call in it stays `length` (the
            // call was cut off), and a MALFORMED_FUNCTION_CALL that still carried a call part
            // stays an error rather than running what Gemini itself said was malformed.
            $reason = self::stopReason($candidate['finishReason']);
            if ($reason === StopReason::Stop && self::hasToolCall($builder->snapshot())) {
                $reason = StopReason::ToolUse;
            }

            // `google-generative-ai.ts` records the error reason and reads the rest of the stream; its
            // end-of-stream check throws `Provider stopped with: <raw reason>` (`Google::run()`).
            if ($reason === StopReason::Error && !$deferErrors) {
                // Upstream throws `Provider stopped with: <raw reason>` here, and so does this:
                // the chunk that carries one of these reasons carries no message of its own, so
                // the reason is the only thing there is to say. `fail()` keeps the content, so
                // partial text survives. None of the reasons is in `Retry`'s word list, so none
                // is retried — upstream's `isRetryableAssistantError` agrees.
                self::close($builder, $stream, $open);

                throw new ProviderError("Provider stopped with: {$candidate['finishReason']}");
            }

            $builder->setStopReason($reason);
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
        // Upstream's arithmetic, from `google-generative-ai.ts`. `promptTokenCount` includes the
        // cached tokens, so they are taken back out: input is what was paid for at the input
        // rate. Thinking is billed as output and reported separately, so it is added in — and
        // kept as the reasoning split too. The total is Google's own `totalTokenCount`, which
        // already counts the cached tokens once inside the prompt.
        $cached = (int) ($usage['cachedContentTokenCount'] ?? 0);
        $thoughts = (int) ($usage['thoughtsTokenCount'] ?? 0);

        return new Usage(
            (int) ($usage['promptTokenCount'] ?? 0) - $cached,
            (int) ($usage['candidatesTokenCount'] ?? 0) + $thoughts,
            $cached,
            0,
            (int) ($usage['totalTokenCount'] ?? 0),
            reasoning: $thoughts,
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
        // Both fields of one part, text first, which is upstream's order. A `Part` is a one-of by
        // convention rather than by schema, and this used to check for the call first and return —
        // so text sharing a part with a call was dropped.
        $text = $part['text'] ?? null;

        if (is_string($text) && $text !== '') {
            $open = self::onText($part, $text, $builder, $stream, $open);
        }

        if (is_array($part['functionCall'] ?? null)) {
            // A call arrives whole, so the block opens, fills and closes here.
            self::close($builder, $stream, $open);

            return self::wholeCall($part, $builder, $stream);
        }

        return $open;
    }

    /**
     * @param array<string, mixed> $part
     * @param array{0: int, 1: string}|null $open
     * @return array{0: int, 1: string}
     */
    private static function onText(
        array $part,
        string $text,
        AssistantMessageBuilder $builder,
        AssistantMessageEventStream $stream,
        ?array $open,
    ): array {
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

        // The signature has to go back with the part it came on — a thought's, and a text part's
        // too: Gemini signs answer text as well, and the text block keeps it as its
        // `textSignature`. Upstream's `retainThoughtSignature()`: a later delta without one does
        // not wipe the one an earlier delta brought.
        $signature = $part['thoughtSignature'] ?? null;

        if (is_string($signature) && $signature !== '') {
            $builder->setSignature($open[0], $signature);
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

    /**
     * JSON Schema's meta-declarations, which Gemini's `parameters` (an OpenAPI 3.0 schema) refuses
     * with `Unknown name "$schema"`. Upstream's `JSON_SCHEMA_META_DECLARATIONS`.
     */
    private const array SCHEMA_META = ['$schema', '$id', '$anchor', '$dynamicAnchor', '$vocabulary', '$comment', '$defs', 'definitions'];

    /** @return array<string, mixed> */
    private static function tool(Tool $tool): array
    {
        return [
            'name' => $tool->name,
            'description' => $tool->description,
            'parameters' => self::sanitizeForOpenApi($tool->parameters),
        ];
    }

    /**
     * Strip the meta-declarations, at every depth — upstream's `sanitizeForOpenApi()`.
     *
     * No built-in tool carries one, which is how this stayed unported: every MCP server's schema
     * does (`"$schema": "http://json-schema.org/draft-07/schema#"` is what the TypeScript SDK
     * emits), and the first one connected made every Gemini request a 400.
     */
    public static function sanitizeForOpenApi(mixed $schema): mixed
    {
        if (!is_array($schema)) {
            return $schema;
        }

        if ($schema !== [] && array_is_list($schema)) {
            return array_map(self::sanitizeForOpenApi(...), $schema);
        }

        $result = [];

        foreach ($schema as $key => $value) {
            if (in_array($key, self::SCHEMA_META, true)) {
                continue;
            }

            $result[$key] = self::sanitizeForOpenApi($value);
        }

        return $result;
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

    /**
     * Upstream's assistant arm of `convertMessages()` in `google-shared.ts`, literally.
     *
     * Signatures only go back to the provider **and** model that minted them, and only when they
     * are base64 — Gemini declares the field `TYPE_BYTES`, so anything else is a 400. That is
     * upstream's own `isSameProviderAndModel`, narrower than `TransformMessages`' check (it leaves
     * the API out), and applied here on top of it the way upstream applies both.
     *
     * A thought from this same model goes back as a thought even without a signature, and a
     * thought from any other model as plain text — no `<thinking>` tags, so the model does not
     * learn to mimic them. A call's `id` goes only to a model `requiresToolCallId()` names.
     *
     * @return list<array<string, mixed>>
     */
    private static function assistantParts(AssistantMessage $message, Model $model): array
    {
        $parts = [];
        $sameProviderAndModel = $message->provider === $model->provider && $message->model === $model->id;

        foreach ($message->content as $block) {
            if ($block instanceof TextContent) {
                $signature = self::resolveThoughtSignature($sameProviderAndModel, $block->textSignature);

                // Skip empty text blocks — unless they carry a thought signature. Gemini can attach
                // the signature to a part whose visible text is empty and requires it echoed back;
                // dropping it breaks the reasoning chain (upstream's comment).
                if (trim($block->text) === '' && $signature === null) {
                    continue;
                }

                $part = ['text' => Utf8::sanitize($block->text)];

                if ($signature !== null) {
                    $part['thoughtSignature'] = $signature;
                }

                $parts[] = $part;

                continue;
            }

            if ($block instanceof ThinkingContent) {
                if ($sameProviderAndModel) {
                    $signature = self::resolveThoughtSignature($sameProviderAndModel, $block->thinkingSignature);

                    // Same rule as text: an empty thought is dropped only when it carries no signature.
                    if (trim($block->thinking) === '' && $signature === null) {
                        continue;
                    }

                    $part = ['thought' => true, 'text' => Utf8::sanitize($block->thinking)];

                    if ($signature !== null) {
                        $part['thoughtSignature'] = $signature;
                    }

                    $parts[] = $part;
                } elseif (trim($block->thinking) !== '') {
                    // Another provider's or model's thought: the signature is unusable, empty
                    // blocks stay dropped, and the text goes back untagged.
                    $parts[] = ['text' => Utf8::sanitize($block->thinking)];
                }

                continue;
            }

            if ($block instanceof ToolCall) {
                $part = ['functionCall' => [
                    'name' => $block->name,
                    'args' => $block->arguments === [] ? new stdClass() : $block->arguments,
                ]];

                if (self::requiresToolCallId($model->id)) {
                    $part['functionCall']['id'] = $block->id;
                }

                $signature = self::resolveThoughtSignature($sameProviderAndModel, $block->thoughtSignature);

                if ($signature !== null) {
                    $part['thoughtSignature'] = $signature;
                }

                $parts[] = $part;
            }
        }

        return $parts;
    }

    /** Upstream's `resolveThoughtSignature()`: only the same provider and model's, and only base64. */
    private static function resolveThoughtSignature(bool $sameProviderAndModel, ?string $signature): ?string
    {
        return $sameProviderAndModel && self::isValidThoughtSignature($signature) ? $signature : null;
    }

    /** Upstream's `isValidThoughtSignature()`: Google declares the field `TYPE_BYTES`, so base64. */
    private static function isValidThoughtSignature(?string $signature): bool
    {
        if ($signature === null || $signature === '') {
            return false;
        }

        if (strlen($signature) % 4 !== 0) {
            return false;
        }

        return preg_match('#^[A-Za-z0-9+/]+={0,2}$#D', $signature) === 1;
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

        // Upstream's `hasText` is `textResult.length > 0` — the joined text, so a lone empty text
        // block is no text — and its fallback for nothing at all is "", not a placeholder.
        $joined = implode("\n", $text);
        $value = $joined !== '' ? Utf8::sanitize($joined) : ($images !== [] ? '(see attached image)' : '');

        // Gemini 3+ takes images inside the response; older Gemini has nowhere to put them and
        // needs a user turn of their own.
        $nested = self::supportsMultimodalFunctionResponse($model->id);

        $response = [
            'name' => $message->toolName,
            'response' => $message->isError ? ['error' => $value] : ['output' => $value],
        ];

        if ($images !== [] && $nested) {
            $response['parts'] = $images;
        }

        if (self::requiresToolCallId($model->id)) {
            $response['id'] = $message->toolCallId;
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
