<?php

declare(strict_types=1);

namespace Pig\Ai\Providers;

use Pig\Ai\AssistantMessage;
use Pig\Ai\Context;
use Pig\Ai\DoneEvent;
use Pig\Ai\ErrorEvent;
use Pig\Ai\Http\HttpClient;
use Pig\Ai\Http\Request;
use Pig\Ai\Http\SseParser;
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
use Pig\Async\Async;
use Throwable;

/**
 * Google's Generative Language API — Gemini, streamed.
 *
 * The fourth shape, and the one furthest from the other three. A chunk carries a list of
 * *parts*, and a part is text, or thinking (text with `thought: true` on it), or a whole
 * function call. So:
 *
 * - **A tool call arrives complete**, arguments and all, in one part. It is opened,
 *   delivered and closed in the same breath, because there is nothing to stream.
 * - **Thinking and text are the same field**, told apart by a flag, so a block ends when
 *   that flag changes — the same boundary problem as `openai-completions`, one field over.
 * - **Tool results are addressed by name as well as id**, and consecutive ones are merged
 *   into a single user turn, which is what the API wants.
 *
 * Upstream hands this to `@google/genai`. There is none here, so the REST endpoint is
 * called directly: `:streamGenerateContent?alt=sse`, which is what that package does
 * underneath.
 *
 * Ported from upstream's `providers/google.ts` and `providers/google-shared.ts`. Not
 * ported: `google-gemini-cli`, which is the same protocol behind Google's OAuth device
 * flow — the protocol here is most of it, and the sign-in is the rest.
 */
final class Google
{
    /** An id Gemini did not give us has to be invented, and be unique in the message. */
    private static int $invented = 0;

    public function __construct(private readonly HttpClient $http = new HttpClient())
    {
    }

    /** Returns at once; the response fills in as it arrives. */
    public function stream(Model $model, Context $context, ?GoogleOptions $options = null): AssistantMessageEventStream
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
        ?GoogleOptions $options,
    ): void {
        $builder = new AssistantMessageBuilder($model);
        $signal = $options?->signal;
        $open = null;

        try {
            $response = $this->http->send($this->request($model, $context, $options), $signal);

            if (!$response->isSuccessful()) {
                throw new ProviderError($this->explain($response->status, $response->body->all()));
            }

            $stream->push(new StartEvent($builder->snapshot()));
            $parser = new SseParser();

            foreach ($response->body as $chunk) {
                foreach ($parser->feed($chunk) as $event) {
                    $data = json_decode($event->data, true);

                    if (is_array($data)) {
                        $open = $this->onChunk($data, $builder, $stream, $open);
                    }
                }
            }

            $this->close($builder, $stream, $open);
            $signal?->throwIfAborted();

            $message = $builder->snapshot();
            $stream->push(new DoneEvent($message->stopReason, $message));
            $stream->end();
        } catch (Throwable $error) {
            // A provider never throws at its caller: the failure is the stream's result.
            $builder->fail($error->getMessage(), $signal?->aborted() ?? false);
            $failed = $builder->snapshot();
            $stream->push(new ErrorEvent($failed->stopReason, $failed));
            $stream->end();
        }
    }

    /**
     * @param array<string, mixed> $data
     * @param array{0: int, 1: string}|null $open
     * @return array{0: int, 1: string}|null
     */
    private function onChunk(
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
                $open = $this->onPart($part, $builder, $stream, $open);
            }
        }

        if (is_string($candidate['finishReason'] ?? null)) {
            $reason = $this->stopReason($candidate['finishReason']);

            // A turn that called a tool is finished with the turn, not the task, and
            // Gemini says STOP for both.
            $builder->setStopReason($this->hasToolCall($builder->snapshot()) ? StopReason::ToolUse : $reason);
        }

        if (is_array($data['usageMetadata'] ?? null)) {
            $builder->setUsage($this->usage($data['usageMetadata']));
        }

        return $open;
    }

    /**
     * @param array<string, mixed> $part
     * @param array{0: int, 1: string}|null $open
     * @return array{0: int, 1: string}|null
     */
    private function onPart(
        array $part,
        AssistantMessageBuilder $builder,
        AssistantMessageEventStream $stream,
        ?array $open,
    ): ?array {
        if (is_array($part['functionCall'] ?? null)) {
            // A call arrives whole, so the block opens, fills and closes here.
            $this->close($builder, $stream, $open);

            return $this->wholeCall($part, $builder, $stream);
        }

        $text = $part['text'] ?? null;

        if (!is_string($text) || $text === '') {
            return $open;
        }

        // The one field, told apart by a flag. Thinking and answer look identical
        // otherwise, and a block ends where the flag changes.
        $kind = ($part['thought'] ?? false) === true ? 'thinking' : 'text';

        if ($open === null || $open[1] !== $kind) {
            $this->close($builder, $stream, $open);
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
    private function wholeCall(array $part, AssistantMessageBuilder $builder, AssistantMessageEventStream $stream): ?array
    {
        $call = $part['functionCall'];
        $name = (string) ($call['name'] ?? '');
        $index = $builder->startToolCall($builder->nextWire(), $this->callId($call, $name, $builder), $name);

        $arguments = (string) json_encode($call['args'] ?? new \stdClass());
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
    private function callId(array $call, string $name, AssistantMessageBuilder $builder): string
    {
        $given = $call['id'] ?? null;

        if (is_string($given) && $given !== '' && !$this->hasCallId($builder->snapshot(), $given)) {
            return $given;
        }

        return $name . '_' . Timestamp::nowMs() . '_' . ++self::$invented;
    }

    private function hasCallId(AssistantMessage $message, string $id): bool
    {
        foreach ($message->content as $block) {
            if ($block instanceof ToolCall && $block->id === $id) {
                return true;
            }
        }

        return false;
    }

    private function hasToolCall(AssistantMessage $message): bool
    {
        foreach ($message->content as $block) {
            if ($block instanceof ToolCall) {
                return true;
            }
        }

        return false;
    }

    /** @param array{0: int, 1: string}|null $open */
    private function close(AssistantMessageBuilder $builder, AssistantMessageEventStream $stream, ?array $open): void
    {
        if ($open === null) {
            return;
        }

        [$index, $kind] = $open;
        $snapshot = $builder->snapshot();

        $stream->push($kind === 'thinking'
            ? new ThinkingEndEvent($index, $builder->textOf($index), $snapshot)
            : new TextEndEvent($index, $builder->textOf($index), $snapshot));
    }

    /** @param array<string, mixed> $usage */
    private function usage(array $usage): Usage
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

    /**
     * Gemini has twenty finish reasons and eighteen of them are "no".
     *
     * Safety blocks, recitation, a malformed call, a language it will not answer in —
     * all of them mean the turn produced nothing usable, which is an error however
     * politely it is phrased.
     */
    private function stopReason(string $reason): StopReason
    {
        return match ($reason) {
            'STOP' => StopReason::Stop,
            'MAX_TOKENS' => StopReason::Length,
            default => StopReason::Error,
        };
    }

    private function explain(int $status, string $body): string
    {
        $decoded = json_decode($body, true);
        $message = is_array($decoded) ? ($decoded['error']['message'] ?? null) : null;

        return "google returned {$status}: " . (is_string($message) ? $message : trim($body));
    }

    // ---- the request ---------------------------------------------------------------------

    private function request(Model $model, Context $context, ?GoogleOptions $options): Request
    {
        $headers = [
            'accept' => 'text/event-stream',
            'content-type' => 'application/json',
            'x-goog-api-key' => $options?->apiKey ?? '',
            ...$model->headers,
        ];

        // `alt=sse` is what turns this from one enormous JSON array into a stream.
        $url = rtrim($model->baseUrl, '/') . '/models/' . rawurlencode($model->id) . ':streamGenerateContent?alt=sse';

        return new Request('POST', $url, $headers, $this->encode($this->body($model, $context, $options)));
    }

    /** @param array<string, mixed> $body */
    private function encode(array $body): string
    {
        $json = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            throw new ProviderError('Cannot encode the request: ' . json_last_error_msg());
        }

        return $json;
    }

    /** @return array<string, mixed> */
    private function body(Model $model, Context $context, ?GoogleOptions $options): array
    {
        $body = ['contents' => $this->contents($model, $context)];
        $config = [];

        if ($options?->temperature !== null) {
            $config['temperature'] = $options->temperature;
        }

        if ($options?->maxTokens !== null) {
            $config['maxOutputTokens'] = $options->maxTokens;
        }

        if ($context->systemPrompt !== null && $context->systemPrompt !== '') {
            $body['systemInstruction'] = ['parts' => [['text' => Utf8::sanitize($context->systemPrompt)]]];
        }

        if ($context->tools !== []) {
            $body['tools'] = [['functionDeclarations' => array_map($this->tool(...), $context->tools)]];

            if ($options?->toolChoice !== null) {
                $body['toolConfig'] = [
                    'functionCallingConfig' => ['mode' => strtoupper($options->toolChoice)],
                ];
            }
        }

        if ($model->reasoning) {
            $config['thinkingConfig'] = $this->thinking($options);
        }

        if ($config !== []) {
            $body['generationConfig'] = $config;
        }

        return $body;
    }

    /**
     * What to tell Gemini about thinking.
     *
     * Saying nothing is not the same as saying no: Gemini thinks by default, so a turn
     * that did not ask for it has to ask for none. Gemini 3 takes a level and ignores a
     * budget; 2.5 takes a budget. `Stream` works out which; this sends whichever arrived.
     *
     * @return array<string, mixed>
     */
    private function thinking(?GoogleOptions $options): array
    {
        if ($options === null || !$options->thinkingEnabled) {
            return ['thinkingBudget' => 0];
        }

        $config = ['includeThoughts' => true];

        if ($options->thinkingLevel !== null) {
            $config['thinkingLevel'] = $options->thinkingLevel;
        } elseif ($options->thinkingBudget !== null) {
            $config['thinkingBudget'] = $options->thinkingBudget;
        }

        return $config;
    }

    /** @return array<string, mixed> */
    private function tool(Tool $tool): array
    {
        return [
            'name' => $tool->name,
            'description' => $tool->description,
            'parameters' => $tool->parameters,
        ];
    }

    /**
     * The conversation, as Gemini's `contents`.
     *
     * @return list<array<string, mixed>>
     */
    private function contents(Model $model, Context $context): array
    {
        $contents = [];

        foreach (TransformMessages::apply($context->messages, $model) as $message) {
            if ($message instanceof UserMessage) {
                $parts = $this->parts($message->content, $model);

                if ($parts !== []) {
                    $contents[] = ['role' => 'user', 'parts' => $parts];
                }

                continue;
            }

            if ($message instanceof AssistantMessage) {
                $parts = $this->assistantParts($message);

                if ($parts !== []) {
                    $contents[] = ['role' => 'model', 'parts' => $parts];
                }

                continue;
            }

            if ($message instanceof ToolResultMessage) {
                $this->addResult($contents, $message, $model);
            }
        }

        return $contents;
    }

    /**
     * @param list<mixed> $content
     * @return list<array<string, mixed>>
     */
    private function parts(array $content, Model $model): array
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
    private function assistantParts(AssistantMessage $message): array
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
                    'args' => $block->arguments === [] ? new \stdClass() : $block->arguments,
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
    private function addResult(array &$contents, ToolResultMessage $message, Model $model): void
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

        if ($last !== null && $last['role'] === 'user' && $this->holdsResults($last)) {
            $contents[count($contents) - 1]['parts'][] = ['functionResponse' => $response];
        } else {
            $contents[] = ['role' => 'user', 'parts' => [['functionResponse' => $response]]];
        }

        if ($images !== [] && !$nested) {
            $contents[] = ['role' => 'user', 'parts' => [['text' => 'Tool result image:'], ...$images]];
        }
    }

    /** @param array<string, mixed> $content */
    private function holdsResults(array $content): bool
    {
        foreach ($content['parts'] ?? [] as $part) {
            if (isset($part['functionResponse'])) {
                return true;
            }
        }

        return false;
    }
}
