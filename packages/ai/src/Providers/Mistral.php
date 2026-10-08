<?php

declare(strict_types=1);

namespace Pig\Ai\Providers;

use Pig\Ai\AssistantMessage;
use Pig\Ai\Context;
use Pig\Ai\DoneEvent;
use Pig\Ai\ErrorEvent;
use Pig\Ai\Http\HttpClient;
use Pig\Ai\Http\Request;
use Pig\Ai\ImageContent;
use Pig\Ai\Model;
use Pig\Ai\ProviderError;
use Pig\Ai\StartEvent;
use Pig\Ai\StopReason;
use Pig\Ai\TextContent;
use Pig\Ai\TextDeltaEvent;
use Pig\Ai\TextEndEvent;
use Pig\Ai\TextStartEvent;
use Pig\Ai\ThinkingContent;
use Pig\Ai\ThinkingDeltaEvent;
use Pig\Ai\ThinkingEndEvent;
use Pig\Ai\ThinkingStartEvent;
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
use Pig\Ai\Utils\ErrorBody;
use Pig\Ai\Utils\ShortHash;
use Pig\Ai\Utils\Utf8;
use Pig\Async\Async;
use Throwable;

/**
 * Mistral's own chat API, streamed — upstream's `api/mistral-conversations.ts`.
 *
 * Upstream moved Mistral off the OpenAI-compatible path onto this, and pig followed: Mistral's
 * models used to go through `OpenAiCompletions` with four Mistral-only rules detected from the
 * host (nine-character tool ids, the tool result's name, thinking replayed as text, `max_tokens`),
 * none of which upstream's completions provider has any more. What this API does differently is
 * all here instead:
 *
 * - **Thinking is a content chunk**, `{type: "thinking", thinking: [{type: "text", text}]}`, in the
 *   streamed delta and in a replayed assistant turn alike — not a field beside the content.
 * - **How hard to think** is `reasoning_effort` for a model whose `thinkingLevelMap` names its
 *   efforts (Mistral Small 4, Medium 3.5), and `prompt_mode: "reasoning"` for a reasoning model with
 *   no map (Magistral) — upstream's `streamSimple()`, which `Stream::mistralSimple()` ports.
 * - **Tool call ids are exactly nine alphanumeric characters**: another provider's id is replaced
 *   by a hash of itself (`createMistralToolCallIdNormalizer()`), collision-checked, so a call and
 *   its result come out the same.
 * - **Prompt caching** is `prompt_cache_key` plus an `x-affinity` header, both the session id.
 * - A refused request reads `Mistral API error (<status>): <body>`.
 *
 * Upstream calls `fetch` itself rather than going through Mistral's SDK, and parses the event
 * stream by hand; so does this, with `HttpClient` and the same event-boundary rules
 * (`readMistralEvents()`), not `SseParser`.
 *
 * Not ported, with the reason: upstream's `AbortSignal.timeout(options.timeoutMs ?? 60_000)` — an
 * absolute deadline over the request and the whole stream — because pig's stream options have no
 * `timeoutMs` and `HttpClient` carries its own read timeout; its `User-Agent: pi/<version>`, which
 * no pig provider sends; and the `onPayload`, `onResponse` and `onProviderStreamEvent` hooks, which
 * pig's stream options do not have for any provider.
 */
final class Mistral
{
    /** Upstream's `MISTRAL_TOOL_CALL_ID_LENGTH`. */
    private const int MISTRAL_TOOL_CALL_ID_LENGTH = 9;

    /** Upstream's `MAX_MISTRAL_ERROR_BODY_CHARS`. */
    private const int MAX_MISTRAL_ERROR_BODY_CHARS = 4000;

    /** Upstream's `findMistralEventBoundary()` pattern: a blank line in any line-ending spelling. */
    private const string EVENT_BOUNDARY = "/\r\n\r\n|\r\n\r|\r\n\n|\r\r\n|\n\r\n|\r\r|\n\r|\n\n/";

    private const int JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;

    public function __construct(private readonly HttpClient $http = new HttpClient())
    {
    }

    /** Returns at once; the response fills in as it arrives. */
    public function stream(Model $model, Context $context, ?MistralOptions $options = null): AssistantMessageEventStream
    {
        $stream = new AssistantMessageEventStream();

        Async::spawn(function () use ($stream, $model, $context, $options): void {
            $this->run($stream, $model, $context, $options);
        });

        return $stream;
    }

    private function run(
        AssistantMessageEventStream $stream,
        Model $model,
        Context $context,
        ?MistralOptions $options,
    ): void {
        // Upstream's `createOutput()`: `stopReason: "pending"` until a `finish_reason` arrives.
        $builder = new AssistantMessageBuilder($model);
        $builder->setStopReason(StopReason::Pending);
        $signal = $options?->signal;

        try {
            $apiKey = $options?->apiKey;

            if ($apiKey === null || $apiKey === '') {
                throw new ProviderError("No API key for provider: {$model->provider}");
            }

            $normalizeMistralToolCallId = self::createMistralToolCallIdNormalizer();
            $transformedMessages = TransformMessages::apply(
                $context->messages,
                $model,
                static fn (string $id): string => $normalizeMistralToolCallId($id),
            );

            $payload = self::buildChatPayload($model, $context, $transformedMessages, $options);
            $response = $this->http->send(new Request(
                'POST',
                rtrim($model->baseUrl, '/') . '/v1/chat/completions',
                self::buildMistralHeaders($model, $apiKey, $options),
                self::encode($payload),
            ), $signal);

            if (!$response->isSuccessful()) {
                throw new ProviderError(self::formatMistralHttpError($response->status, $response->body->all(), $response->reason));
            }

            $stream->push(new StartEvent($builder->snapshot()));
            $this->consumeChatStream($builder, $stream, self::readMistralEvents($response->body));

            if ($signal?->aborted() ?? false) {
                throw new ProviderError('Request was aborted');
            }

            if ($builder->stopReason() === StopReason::Pending) {
                throw new ProviderError('Mistral stream ended without a finish reason');
            }

            if ($builder->stopReason() === StopReason::Aborted || $builder->stopReason() === StopReason::Error) {
                $message = $builder->errorMessage();

                throw new ProviderError($message !== null && $message !== '' ? $message : 'An unknown error occurred');
            }

            $message = $builder->snapshot();
            $stream->push(new DoneEvent($message->stopReason, $message));
            $stream->end();
        } catch (Throwable $error) {
            // A provider never throws at its caller: the failure is the stream's result.
            // `formatMistralError()` for anything but a refused request is the error's own message.
            $builder->fail($error->getMessage(), $signal?->aborted() ?? false);
            $failed = $builder->snapshot();
            $stream->push(new ErrorEvent($failed->stopReason, $failed));
            $stream->end();
        }
    }

    /**
     * Upstream's `formatMistralError()` for its `MistralHttpError`: the trimmed body, cut at 4,000
     * characters, or — for an empty one — the error's message, which is the status text or
     * `Request failed with status <status>`.
     */
    private static function formatMistralHttpError(int $status, string $body, string $statusText): string
    {
        $bodyText = trim($body);

        if ($bodyText !== '') {
            return "Mistral API error ({$status}): " . ErrorBody::truncateErrorText($bodyText, self::MAX_MISTRAL_ERROR_BODY_CHARS);
        }

        $message = $statusText !== '' ? $statusText : "Request failed with status {$status}";

        return "Mistral API error ({$status}): {$message}";
    }

    /**
     * Upstream's `createMistralToolCallIdNormalizer()`: each id maps to one nine-character id, and a
     * derived id another id already owns is derived again with the next attempt number.
     *
     * @return \Closure(string): string
     */
    private static function createMistralToolCallIdNormalizer(): \Closure
    {
        $idMap = [];
        $reverseMap = [];

        return static function (string $id) use (&$idMap, &$reverseMap): string {
            // Keys prefixed so that a numeric id stays a string key, as a `Map` keeps it.
            $existing = $idMap['k' . $id] ?? null;

            if ($existing !== null) {
                return $existing;
            }

            $attempt = 0;

            while (true) {
                $candidate = self::deriveMistralToolCallId($id, $attempt);
                $owner = $reverseMap['k' . $candidate] ?? null;

                if ($owner === null || $owner === $id) {
                    $idMap['k' . $id] = $candidate;
                    $reverseMap['k' . $candidate] = $id;

                    return $candidate;
                }

                $attempt++;
            }
        };
    }

    /** Upstream's `deriveMistralToolCallId()`. */
    private static function deriveMistralToolCallId(string $id, int $attempt): string
    {
        $normalized = (string) preg_replace('/[^a-zA-Z0-9]/', '', $id);

        if ($attempt === 0 && strlen($normalized) === self::MISTRAL_TOOL_CALL_ID_LENGTH) {
            return $normalized;
        }

        $seedBase = $normalized !== '' ? $normalized : $id;
        $seed = $attempt === 0 ? $seedBase : "{$seedBase}:{$attempt}";

        return substr((string) preg_replace('/[^a-zA-Z0-9]/', '', ShortHash::of($seed)), 0, self::MISTRAL_TOOL_CALL_ID_LENGTH);
    }

    /**
     * Upstream's `buildMistralHeaders()`: the four fixed headers, the model's own and then the
     * request's over them (by name, whatever the case), and `x-affinity` — the session id — when
     * caching is on and neither set one.
     *
     * @return array<string, string>
     */
    private static function buildMistralHeaders(Model $model, string $apiKey, ?MistralOptions $options): array
    {
        $headers = [
            'accept' => 'text/event-stream',
            'authorization' => "Bearer {$apiKey}",
            'content-type' => 'application/json',
        ];

        foreach ($model->headers as $name => $value) {
            $headers[strtolower($name)] = $value;
        }

        if (self::shouldUsePromptCaching($options) && !array_key_exists('x-affinity', $headers)) {
            $headers['x-affinity'] = (string) $options?->sessionId;
        }

        return $headers;
    }

    /** Upstream's `shouldUsePromptCaching()`: not `cacheRetention: "none"`, and a session id. */
    private static function shouldUsePromptCaching(?MistralOptions $options): bool
    {
        return $options?->cacheRetention !== 'none' && $options?->sessionId !== null && $options->sessionId !== '';
    }

    /** @param array<string, mixed> $payload */
    private static function encode(array $payload): string
    {
        $json = json_encode($payload, self::JSON_FLAGS);

        if ($json === false) {
            throw new ProviderError('Cannot encode the request: ' . json_last_error_msg());
        }

        return $json;
    }

    /**
     * Upstream's `buildChatPayload()` written straight in the wire's names — what its
     * `toMistralWirePayload()` produces, key order included: the renamed keys after the rest.
     *
     * @param list<mixed> $messages the transformed conversation
     * @return array<string, mixed>
     */
    private static function buildChatPayload(Model $model, Context $context, array $messages, ?MistralOptions $options): array
    {
        $payload = [
            'model' => $model->id,
            'stream' => true,
            'messages' => self::toChatMessages($context->systemPrompt, $messages, $model->acceptsImages()),
        ];

        if ($context->tools !== []) {
            $payload['tools'] = self::toFunctionTools($context->tools);
        }

        if ($options?->temperature !== null) {
            $payload['temperature'] = $options->temperature;
        }

        if ($options?->maxTokens !== null) {
            $payload['max_tokens'] = $options->maxTokens;
        }

        if ($options?->toolChoice !== null && $options->toolChoice !== '') {
            $payload['tool_choice'] = $options->toolChoice;
        }

        if ($options?->reasoningEffort !== null && $options->reasoningEffort !== '') {
            $payload['reasoning_effort'] = $options->reasoningEffort;
        }

        if ($options?->promptMode !== null && $options->promptMode !== '') {
            $payload['prompt_mode'] = $options->promptMode;
        }

        if (self::shouldUsePromptCaching($options)) {
            $payload['prompt_cache_key'] = $options?->sessionId;
        }

        return $payload;
    }

    /**
     * Upstream's `toFunctionTools()`: `resolveJsonSchemaStrictSampling(tool, true)` — this API always
     * has strict mode, so a tool that asks for JSON-schema sampling goes strict when its schema has
     * a strict form — and `strict` always sent, `false` for every other tool.
     *
     * @param list<Tool> $tools
     * @return list<array<string, mixed>>
     */
    private static function toFunctionTools(array $tools): array
    {
        return array_map(static function (Tool $tool): array {
            $strict = ConstrainedSampling::resolveJsonSchemaStrictSampling($tool, true);

            return [
                'type' => 'function',
                'function' => [
                    'name' => $tool->name,
                    'description' => $tool->description,
                    'parameters' => ConstrainedSampling::getJsonSchemaToolParameters($tool, $strict),
                    'strict' => $strict ?? false,
                ],
            ];
        }, $tools);
    }

    /**
     * Upstream's `toChatMessages()`, in the wire's names. The system prompt is pig's
     * `Context::$systemPrompt` where upstream's is the leading system message.
     *
     * @param list<mixed> $messages
     * @return list<array<string, mixed>>
     */
    private static function toChatMessages(?string $systemPrompt, array $messages, bool $supportsImages): array
    {
        $result = [];

        if ($systemPrompt !== null && $systemPrompt !== '') {
            $result[] = ['role' => 'system', 'content' => Utf8::sanitize($systemPrompt)];
        }

        foreach ($messages as $msg) {
            if ($msg instanceof UserMessage) {
                $hadImages = false;
                $content = [];

                foreach ($msg->content as $item) {
                    if ($item instanceof ImageContent) {
                        $hadImages = true;

                        if ($supportsImages) {
                            $content[] = ['type' => 'image_url', 'image_url' => "data:{$item->mimeType};base64,{$item->data}"];
                        }

                        continue;
                    }

                    if ($item instanceof TextContent) {
                        $content[] = ['type' => 'text', 'text' => Utf8::sanitize($item->text)];
                    }
                }

                if ($content !== []) {
                    $result[] = ['role' => 'user', 'content' => $content];

                    continue;
                }

                if ($hadImages && !$supportsImages) {
                    $result[] = ['role' => 'user', 'content' => '(image omitted: model does not support images)'];
                }

                continue;
            }

            if ($msg instanceof AssistantMessage) {
                $contentParts = [];
                $toolCalls = [];

                foreach ($msg->content as $block) {
                    if ($block instanceof TextContent) {
                        if (trim($block->text) !== '') {
                            $contentParts[] = ['type' => 'text', 'text' => Utf8::sanitize($block->text)];
                        }

                        continue;
                    }

                    if ($block instanceof ThinkingContent) {
                        if (trim($block->thinking) !== '') {
                            $contentParts[] = [
                                'type' => 'thinking',
                                'thinking' => [['type' => 'text', 'text' => Utf8::sanitize($block->thinking)]],
                            ];
                        }

                        continue;
                    }

                    if ($block instanceof ToolCall) {
                        $toolCalls[] = [
                            'id' => $block->id,
                            'type' => 'function',
                            // `JSON.stringify(block.arguments || {})`: `{}`, not `[]`, for none.
                            'function' => [
                                'name' => $block->name,
                                'arguments' => $block->arguments === [] ? '{}' : (string) json_encode($block->arguments, self::JSON_FLAGS),
                            ],
                            'index' => 0,
                        ];
                    }
                }

                $assistantMessage = ['role' => 'assistant', 'prefix' => false];

                if ($contentParts !== []) {
                    $assistantMessage['content'] = $contentParts;
                }

                if ($toolCalls !== []) {
                    $assistantMessage['tool_calls'] = $toolCalls;
                }

                if ($contentParts !== [] || $toolCalls !== []) {
                    $result[] = $assistantMessage;
                }

                continue;
            }

            if (!$msg instanceof ToolResultMessage) {
                continue;
            }

            $texts = [];
            $hasImages = false;

            foreach ($msg->content as $part) {
                if ($part instanceof TextContent) {
                    $texts[] = Utf8::sanitize($part->text);
                } elseif ($part instanceof ImageContent) {
                    $hasImages = true;
                }
            }

            $toolContent = [['type' => 'text', 'text' => self::buildToolResultText(implode("\n", $texts), $hasImages, $supportsImages, $msg->isError)]];

            foreach ($msg->content as $part) {
                if ($supportsImages && $part instanceof ImageContent) {
                    $toolContent[] = ['type' => 'image_url', 'image_url' => "data:{$part->mimeType};base64,{$part->data}"];
                }
            }

            $result[] = [
                'role' => 'tool',
                'name' => $msg->toolName,
                'content' => $toolContent,
                'tool_call_id' => $msg->toolCallId,
            ];
        }

        return $result;
    }

    /** Upstream's `buildToolResultText()`. */
    private static function buildToolResultText(string $text, bool $hasImages, bool $supportsImages, bool $isError): string
    {
        $trimmed = trim($text);
        $errorPrefix = $isError ? '[tool error] ' : '';

        if ($trimmed !== '') {
            $imageSuffix = $hasImages && !$supportsImages ? "\n[tool image omitted: model does not support images]" : '';

            return "{$errorPrefix}{$trimmed}{$imageSuffix}";
        }

        if ($hasImages) {
            if ($supportsImages) {
                return $isError ? '[tool error] (see attached image)' : '(see attached image)';
            }

            return $isError
                ? '[tool error] (image omitted: model does not support images)'
                : '(image omitted: model does not support images)';
        }

        return $isError ? '[tool error] (no tool output)' : '(no tool output)';
    }

    /**
     * Upstream's `readMistralEvents()`: the body cut at blank lines — any of the eight spellings of
     * one — and each piece parsed by `parseMistralEvent()`, until `[DONE]` or the end of the body,
     * whose remainder is parsed when it holds anything but whitespace.
     *
     * @param iterable<string> $body
     * @return iterable<array<string, mixed>>
     */
    private static function readMistralEvents(iterable $body): iterable
    {
        $buffer = '';

        foreach ($body as $chunk) {
            $buffer .= $chunk;

            while (preg_match(self::EVENT_BOUNDARY, $buffer, $match, PREG_OFFSET_CAPTURE) === 1) {
                [$boundary, $index] = $match[0];
                $event = self::parseMistralEvent(substr($buffer, 0, $index));
                $buffer = substr($buffer, $index + strlen($boundary));

                if ($event === true) {
                    return;
                }

                if ($event !== null) {
                    yield $event;
                }
            }
        }

        if (trim($buffer) !== '') {
            $event = self::parseMistralEvent($buffer);

            if (is_array($event)) {
                yield $event;
            }
        }
    }

    /**
     * Upstream's `parseMistralEvent()`: the `data:` lines, each without the field name and its
     * leading whitespace, joined by newlines and trimmed. Nothing is null, `[DONE]` is true, and
     * anything else must be a JSON object with a `choices` list — `Invalid Mistral streaming event`
     * when it is not, and the JSON parser's own error when it is not JSON (PHP's words where
     * upstream's are V8's).
     *
     * @return array<string, mixed>|true|null
     */
    private static function parseMistralEvent(string $raw): array|true|null
    {
        $lines = preg_split("/\r\n|\r|\n/", $raw) ?: [];
        $data = [];

        foreach ($lines as $line) {
            if (str_starts_with($line, 'data:')) {
                $data[] = ltrim(substr($line, 5));
            }
        }

        $data = trim(implode("\n", $data));

        if ($data === '') {
            return null;
        }

        if ($data === '[DONE]') {
            return true;
        }

        $parsed = json_decode($data, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new ProviderError(json_last_error_msg());
        }

        if (!is_array($parsed) || array_is_list($parsed) || !is_array($parsed['choices'] ?? null) || !array_is_list($parsed['choices'])) {
            throw new ProviderError('Invalid Mistral streaming event');
        }

        return $parsed;
    }

    /**
     * Upstream's `consumeChatStream()`.
     *
     * @param iterable<array<string, mixed>> $events
     */
    private function consumeChatStream(
        AssistantMessageBuilder $builder,
        AssistantMessageEventStream $stream,
        iterable $events,
    ): void {
        // The text or thinking block being written, as [content index, kind].
        $currentBlock = null;
        // Upstream's `toolBlocksByKey`: a call's stream `index`, or its id when it has none => its
        // content index. Prefixed keys, so `0` and `"0"` stay apart as they do in a `Map`.
        $toolBlocksByKey = [];

        $finishCurrentBlock = static function (?array $block) use ($builder, $stream): void {
            if ($block === null) {
                return;
            }

            [$index, $kind] = $block;
            $stream->push($kind === 'thinking'
                ? new ThinkingEndEvent($index, $builder->textOf($index), $builder->snapshot())
                : new TextEndEvent($index, $builder->textOf($index), $builder->snapshot()));
        };

        foreach ($events as $chunk) {
            // "Mistral's streamed CompletionChunk carries an id field. Keep the first non-empty one,
            // mirroring how OpenAI-style streaming exposes a stable response identifier per stream."
            if (($builder->responseId() ?? '') === '' && is_string($chunk['id'] ?? null) && $chunk['id'] !== '') {
                $builder->setResponseId($chunk['id']);
            }

            if (is_array($chunk['usage'] ?? null)) {
                $usage = $chunk['usage'];
                $promptTokens = self::number($usage['prompt_tokens'] ?? null);
                $cachedPromptTokens = self::getMistralCachedPromptTokens($usage, $promptTokens);
                $input = max(0, $promptTokens - $cachedPromptTokens);
                $output = self::number($usage['completion_tokens'] ?? null);
                $total = self::number($usage['total_tokens'] ?? null);

                $builder->setUsage(new Usage(
                    $input,
                    $output,
                    $cachedPromptTokens,
                    0,
                    $total !== 0 ? $total : $input + $output + $cachedPromptTokens,
                ));
            }

            $choice = $chunk['choices'][0] ?? null;

            if (!is_array($choice)) {
                continue;
            }

            $finishReason = $choice['finish_reason'] ?? null;

            if (is_string($finishReason) && $finishReason !== '') {
                $builder->setRawStopReason($finishReason);
                [$stopReason, $errorMessage] = self::mapChatStopReason($finishReason);
                $builder->setStopReason($stopReason);

                if ($errorMessage !== null) {
                    $builder->setErrorMessage($errorMessage);
                }
            }

            $delta = is_array($choice['delta'] ?? null) ? $choice['delta'] : [];
            $content = $delta['content'] ?? null;

            if ($content !== null) {
                foreach (is_string($content) ? [$content] : (is_array($content) ? $content : []) as $item) {
                    if (is_string($item)) {
                        $textDelta = Utf8::sanitize($item);

                        // "GLM models on Mistral send empty content deltas around thinking and tool
                        // calls. Opening a block for them splits thinking into multiple blocks, which
                        // Mistral rejects on replay."
                        if ($textDelta === '') {
                            continue;
                        }

                        $currentBlock = $this->appendTo($builder, $stream, $currentBlock, 'text', $textDelta, $finishCurrentBlock);

                        continue;
                    }

                    if (!is_array($item)) {
                        continue;
                    }

                    if (($item['type'] ?? null) === 'thinking') {
                        $pieces = [];

                        foreach (is_array($item['thinking'] ?? null) ? $item['thinking'] : [] as $part) {
                            $text = is_array($part) && is_string($part['text'] ?? null) ? $part['text'] : '';

                            if ($text !== '') {
                                $pieces[] = $text;
                            }
                        }

                        $thinkingDelta = Utf8::sanitize(implode('', $pieces));

                        if ($thinkingDelta === '') {
                            continue;
                        }

                        $currentBlock = $this->appendTo($builder, $stream, $currentBlock, 'thinking', $thinkingDelta, $finishCurrentBlock);

                        continue;
                    }

                    if (($item['type'] ?? null) === 'text') {
                        $textDelta = Utf8::sanitize(is_string($item['text'] ?? null) ? $item['text'] : '');

                        if ($textDelta === '') {
                            continue;
                        }

                        $currentBlock = $this->appendTo($builder, $stream, $currentBlock, 'text', $textDelta, $finishCurrentBlock);
                    }
                }
            }

            foreach (is_array($delta['tool_calls'] ?? null) ? $delta['tool_calls'] : [] as $toolCall) {
                if (!is_array($toolCall)) {
                    continue;
                }

                if ($currentBlock !== null) {
                    $finishCurrentBlock($currentBlock);
                    $currentBlock = null;
                }

                $streamIndex = is_int($toolCall['index'] ?? null) ? $toolCall['index'] : null;
                $id = $toolCall['id'] ?? null;
                $callId = is_string($id) && $id !== '' && $id !== 'null'
                    ? $id
                    : self::deriveMistralToolCallId('toolcall:' . ($streamIndex ?? 0), 0);
                $key = $streamIndex !== null ? "i:{$streamIndex}" : "s:{$callId}";
                $function = is_array($toolCall['function'] ?? null) ? $toolCall['function'] : [];
                $index = $toolBlocksByKey[$key] ?? null;

                if ($index === null) {
                    $index = $builder->startToolCall($builder->nextWire(), $callId, is_string($function['name'] ?? null) ? $function['name'] : '');
                    $toolBlocksByKey[$key] = $index;
                    $stream->push(new ToolCallStartEvent($index, $builder->snapshot()));
                }

                $arguments = $function['arguments'] ?? null;
                $argsDelta = is_string($arguments)
                    ? $arguments
                    : (is_array($arguments) && $arguments !== [] ? (string) json_encode($arguments, self::JSON_FLAGS) : '{}');
                $builder->append($index, 'json', $argsDelta);
                $stream->push(new ToolCallDeltaEvent($index, $argsDelta, $builder->snapshot()));
            }
        }

        $finishCurrentBlock($currentBlock);

        // Every call ends when the stream does, in the order they began — upstream's last loop.
        foreach ($toolBlocksByKey as $index) {
            $stream->push(new ToolCallEndEvent($index, $builder->toolCallOf($index), $builder->snapshot()));
        }
    }

    /**
     * Append to the current text or thinking block, opening one of that kind first — and ending the
     * one of the other kind — when the current block is not it.
     *
     * @param array{0: int, 1: string}|null $currentBlock
     * @param \Closure(array{0: int, 1: string}|null): void $finishCurrentBlock
     * @return array{0: int, 1: string}
     */
    private function appendTo(
        AssistantMessageBuilder $builder,
        AssistantMessageEventStream $stream,
        ?array $currentBlock,
        string $kind,
        string $delta,
        \Closure $finishCurrentBlock,
    ): array {
        if ($currentBlock === null || $currentBlock[1] !== $kind) {
            $finishCurrentBlock($currentBlock);
            $index = $kind === 'thinking' ? $builder->startThinking($builder->nextWire()) : $builder->startText($builder->nextWire());
            $currentBlock = [$index, $kind];
            $stream->push($kind === 'thinking'
                ? new ThinkingStartEvent($index, $builder->snapshot())
                : new TextStartEvent($index, $builder->snapshot()));
        }

        $builder->append($currentBlock[0], 'text', $delta);
        $stream->push($kind === 'thinking'
            ? new ThinkingDeltaEvent($currentBlock[0], $delta, $builder->snapshot())
            : new TextDeltaEvent($currentBlock[0], $delta, $builder->snapshot()));

        return $currentBlock;
    }

    /**
     * Upstream's `getMistralCachedPromptTokens()`: the first of six spellings present, a finite
     * number or 0, kept between 0 and the prompt.
     *
     * @param array<string, mixed> $usage
     */
    private static function getMistralCachedPromptTokens(array $usage, int $promptTokens): int
    {
        $candidates = [
            $usage['promptTokensDetails']['cachedTokens'] ?? null,
            $usage['prompt_tokens_details']['cached_tokens'] ?? null,
            $usage['promptTokenDetails']['cachedTokens'] ?? null,
            $usage['prompt_token_details']['cached_tokens'] ?? null,
            $usage['numCachedTokens'] ?? null,
            $usage['num_cached_tokens'] ?? null,
        ];
        $raw = 0;

        // `a ?? b ?? …`: the first that is not null.
        foreach ($candidates as $candidate) {
            if ($candidate !== null) {
                $raw = $candidate;

                break;
            }
        }

        $cachedTokens = (is_int($raw) || is_float($raw)) && is_finite((float) $raw) ? (int) $raw : 0;

        return min($promptTokens, max(0, $cachedTokens));
    }

    /** `value || 0` for a token count. */
    private static function number(mixed $value): int
    {
        return is_int($value) || is_float($value) ? (int) $value : 0;
    }

    /**
     * Upstream's `mapChatStopReason()`: `stop`, `length`/`model_length`, `tool_calls`; `error` is a
     * retryable server error ("server error" is what makes it so); anything else is an error that
     * says which.
     *
     * @return array{0: StopReason, 1: string|null}
     */
    private static function mapChatStopReason(string $reason): array
    {
        return match ($reason) {
            'stop' => [StopReason::Stop, null],
            'length', 'model_length' => [StopReason::Length, null],
            'tool_calls' => [StopReason::ToolUse, null],
            'error' => [StopReason::Error, 'Provider stopped with: error (server error)'],
            default => [StopReason::Error, "Provider stopped with: {$reason}"],
        };
    }
}
