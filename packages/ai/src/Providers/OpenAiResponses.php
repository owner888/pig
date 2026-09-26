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
use Pig\Ai\Utils\Oauth\GithubCopilot;
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
use Pig\Ai\Utils\Utf8;
use Pig\Async\Async;
use Throwable;

/**
 * OpenAI's Responses API, streamed — what gpt-5 and codex speak.
 *
 * The third shape in three providers, and the one that is least like a chat. A response
 * is a list of *items* — a reasoning item, a message item, a function call — and the
 * stream says when each opens and closes, which makes the block boundaries easy after
 * `openai-completions`, where nothing says anything.
 *
 * Two things here have no counterpart in the other two providers:
 *
 * - **A reasoning item is sent back whole.** What arrives as text is a *summary*; the
 *   reasoning itself is encrypted and opaque, and the model wants its own item back
 *   verbatim on the next turn or it re-reasons from nothing. So the whole item is kept
 *   as the thinking block's signature and replayed as it came.
 * - **A tool call has two ids.** `call_id` is what a result is addressed to, `id` is the
 *   item's own. Both have to go back, so they travel joined as `call_id|id` and are
 *   split again on the way out.
 *
 * Ported from upstream's `providers/openai-responses.ts`.
 */
final class OpenAiResponses
{
    /** OpenAI rejects an id over this, and its own ids can arrive longer. */
    private const int MAX_ID_LENGTH = 64;

    public function __construct(private readonly HttpClient $http = new HttpClient())
    {
    }

    /** Returns at once; the response fills in as it arrives. */
    public function stream(Model $model, Context $context, ?OpenAiOptions $options = null): AssistantMessageEventStream
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
        ?OpenAiOptions $options,
    ): void {
        $builder = new AssistantMessageBuilder($model);
        $signal = $options?->signal;

        // The item that is open, as [index, kind]. Unlike chat-completions, the stream
        // says when one starts and stops, so this is bookkeeping rather than guesswork.
        $open = null;

        try {
            $response = $this->http->send($this->request($model, $context, $options), $signal);

            if (!$response->isSuccessful()) {
                throw new ProviderError($this->explain($model, $response->status, $response->body->all()));
            }

            $stream->push(new StartEvent($builder->snapshot()));
            $parser = new SseParser();

            foreach ($response->body as $chunk) {
                foreach ($parser->feed($chunk) as $event) {
                    $data = json_decode($event->data, true);

                    if (is_array($data)) {
                        $open = $this->dispatch($data, $builder, $stream, $open);
                    }
                }
            }

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
    private function dispatch(
        array $data,
        AssistantMessageBuilder $builder,
        AssistantMessageEventStream $stream,
        ?array $open,
    ): ?array {
        return match ($data['type'] ?? '') {
            'response.output_item.added' => $this->onItemStart($data, $builder, $stream),
            'response.output_item.done' => $this->onItemEnd($data, $builder, $stream, $open),
            'response.reasoning_summary_text.delta' => $this->onDelta($data, $builder, $stream, $open, 'thinking'),
            // One summary part ending and the next beginning is a paragraph break, and
            // nothing else in the stream says so.
            'response.reasoning_summary_part.done' => $this->onBreak($builder, $stream, $open),
            'response.output_text.delta', 'response.refusal.delta' => $this->onDelta($data, $builder, $stream, $open, 'text'),
            'response.function_call_arguments.delta' => $this->onArguments($data, $builder, $stream, $open),
            'response.completed' => $this->onCompleted($data, $builder, $open),
            'error' => throw new ProviderError($this->errorText($data)),
            'response.failed' => throw new ProviderError($this->failureText($data)),
            default => $open,
        };
    }

    /**
     * @param array<string, mixed> $data
     * @return array{0: int, 1: string}|null
     */
    private function onItemStart(array $data, AssistantMessageBuilder $builder, AssistantMessageEventStream $stream): ?array
    {
        $item = $data['item'] ?? [];
        $wire = $builder->nextWire();

        return match ($item['type'] ?? '') {
            'reasoning' => $this->opened('thinking', $builder->startThinking($wire), $stream, $builder),
            'message' => $this->opened('text', $builder->startText($wire), $stream, $builder),
            'function_call' => $this->openedCall($item, $builder, $stream, $wire),
            default => null,
        };
    }

    /** @return array{0: int, 1: string} */
    private function opened(string $kind, int $index, AssistantMessageEventStream $stream, AssistantMessageBuilder $builder): array
    {
        $stream->push($kind === 'thinking'
            ? new ThinkingStartEvent($index, $builder->snapshot())
            : new TextStartEvent($index, $builder->snapshot()));

        return [$index, $kind];
    }

    /**
     * @param array<string, mixed> $item
     * @return array{0: int, 1: string}
     */
    private function openedCall(array $item, AssistantMessageBuilder $builder, AssistantMessageEventStream $stream, int $wire): array
    {
        $index = $builder->startToolCall(
            $wire,
            $this->joinIds((string) ($item['call_id'] ?? ''), (string) ($item['id'] ?? '')),
            (string) ($item['name'] ?? ''),
        );

        // Arguments can arrive complete on the opening item rather than as deltas.
        $arguments = $item['arguments'] ?? null;

        if (is_string($arguments) && $arguments !== '') {
            $builder->append($index, 'json', $arguments);
        }

        $stream->push(new ToolCallStartEvent($index, $builder->snapshot()));

        return [$index, 'toolCall'];
    }

    /**
     * @param array<string, mixed> $data
     * @param array{0: int, 1: string}|null $open
     * @return array{0: int, 1: string}|null
     */
    private function onDelta(
        array $data,
        AssistantMessageBuilder $builder,
        AssistantMessageEventStream $stream,
        ?array $open,
        string $kind,
    ): ?array {
        $delta = $data['delta'] ?? null;

        if ($open === null || $open[1] !== $kind || !is_string($delta) || $delta === '') {
            return $open;
        }

        $builder->append($open[0], 'text', $delta);
        $stream->push($kind === 'thinking'
            ? new ThinkingDeltaEvent($open[0], $delta, $builder->snapshot())
            : new TextDeltaEvent($open[0], $delta, $builder->snapshot()));

        return $open;
    }

    /**
     * @param array{0: int, 1: string}|null $open
     * @return array{0: int, 1: string}|null
     */
    private function onBreak(AssistantMessageBuilder $builder, AssistantMessageEventStream $stream, ?array $open): ?array
    {
        if ($open === null || $open[1] !== 'thinking') {
            return $open;
        }

        $builder->append($open[0], 'text', "\n\n");
        $stream->push(new ThinkingDeltaEvent($open[0], "\n\n", $builder->snapshot()));

        return $open;
    }

    /**
     * @param array<string, mixed> $data
     * @param array{0: int, 1: string}|null $open
     * @return array{0: int, 1: string}|null
     */
    private function onArguments(
        array $data,
        AssistantMessageBuilder $builder,
        AssistantMessageEventStream $stream,
        ?array $open,
    ): ?array {
        $delta = $data['delta'] ?? null;

        if ($open === null || $open[1] !== 'toolCall' || !is_string($delta) || $delta === '') {
            return $open;
        }

        $builder->append($open[0], 'json', $delta);
        $stream->push(new ToolCallDeltaEvent($open[0], $delta, $builder->snapshot()));

        return $open;
    }

    /**
     * @param array<string, mixed> $data
     * @param array{0: int, 1: string}|null $open
     */
    private function onItemEnd(
        array $data,
        AssistantMessageBuilder $builder,
        AssistantMessageEventStream $stream,
        ?array $open,
    ): ?array {
        if ($open === null) {
            return null;
        }

        [$index, $kind] = $open;
        $item = $data['item'] ?? [];

        if ($kind === 'thinking') {
            // The whole item, kept verbatim: the summary is what a person reads, and the
            // model wants its own encrypted reasoning back or it starts over.
            $builder->setSignature($index, (string) json_encode($item));
            $stream->push(new ThinkingEndEvent($index, $builder->textOf($index), $builder->snapshot()));

            return null;
        }

        if ($kind === 'text') {
            $builder->setSignature($index, (string) ($item['id'] ?? ''));
            $stream->push(new TextEndEvent($index, $builder->textOf($index), $builder->snapshot()));

            return null;
        }

        $builder->setToolCall(
            $index,
            $this->joinIds((string) ($item['call_id'] ?? ''), (string) ($item['id'] ?? '')),
            (string) ($item['name'] ?? ''),
        );

        // The finished item's own arguments are the call, and they were being ignored: the deltas
        // are usually the same JSON, but a stream that sent none — which this API allows and a
        // compatible endpoint does — left the call with no arguments at all. Replaced rather than
        // appended, because the usual case is the same JSON arriving twice. Upstream reads the
        // item here too, and reads nothing else.
        $arguments = $item['arguments'] ?? null;

        if (is_string($arguments) && $arguments !== '') {
            $builder->setJson($index, $arguments);
        }

        $stream->push(new ToolCallEndEvent($index, $builder->toolCallOf($index), $builder->snapshot()));

        return null;
    }

    /**
     * @param array<string, mixed> $data
     * @param array{0: int, 1: string}|null $open
     * @return array{0: int, 1: string}|null
     */
    private function onCompleted(array $data, AssistantMessageBuilder $builder, ?array $open): ?array
    {
        $response = $data['response'] ?? [];

        if (is_array($response['usage'] ?? null)) {
            $builder->setUsage($this->usage($response['usage']));
        }

        $reason = $this->stopReason((string) ($response['status'] ?? ''));

        // A response that made tool calls is finished with the turn, not with the task,
        // and the status alone does not say which.
        if ($reason === StopReason::Stop && $this->hasToolCall($builder->snapshot())) {
            $reason = StopReason::ToolUse;
        }

        $builder->setStopReason($reason);

        return $open;
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

    /** @param array<string, mixed> $usage */
    private function usage(array $usage): Usage
    {
        $cached = (int) ($usage['input_tokens_details']['cached_tokens'] ?? 0);

        // `input_tokens` counts the cached ones too, so they come back out.
        return new Usage(
            max(0, (int) ($usage['input_tokens'] ?? 0) - $cached),
            (int) ($usage['output_tokens'] ?? 0),
            $cached,
            0,
            (int) ($usage['total_tokens'] ?? 0),
        );
    }

    private function stopReason(string $status): StopReason
    {
        return match ($status) {
            'completed' => StopReason::Stop,
            'incomplete' => StopReason::Length,
            'failed', 'cancelled' => StopReason::Error,
            // in_progress and queued should not arrive on a completed response.
            default => StopReason::Stop,
        };
    }

    /** @param array<string, mixed> $data */
    private function errorText(array $data): string
    {
        $code = $data['code'] ?? null;
        $message = $data['message'] ?? 'unknown error';

        return is_string($code) ? "Error {$code}: {$message}" : (string) $message;
    }

    /** @param array<string, mixed> $data */
    private function failureText(array $data): string
    {
        $message = $data['response']['error']['message'] ?? null;

        return 'The response failed: ' . (is_string($message) ? $message : 'no reason given');
    }

    private function explain(Model $model, int $status, string $body): string
    {
        $decoded = json_decode($body, true);
        $message = is_array($decoded) ? ($decoded['error']['message'] ?? null) : null;

        return "{$model->provider} returned {$status}: " . (is_string($message) ? $message : trim($body));
    }

    // ---- the request ---------------------------------------------------------------------

    private function request(Model $model, Context $context, ?OpenAiOptions $options): Request
    {
        $headers = [
            'accept' => 'text/event-stream',
            'content-type' => 'application/json',
            'authorization' => 'Bearer ' . ($options?->apiKey ?? ''),
            ...$model->headers,
        ];

        return new Request(
            'POST',
            $this->endpoint($model, $options?->apiKey, '/responses'),
            $headers,
            $this->encode($this->body($model, $context, $options)),
        );
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
    private function body(Model $model, Context $context, ?OpenAiOptions $options): array
    {
        $input = $this->input($model, $context);

        $body = [
            'model' => $model->id,
            'input' => $input,
            'stream' => true,
        ];

        if ($options?->maxTokens !== null) {
            $body['max_output_tokens'] = $options->maxTokens;
        }

        if ($options?->temperature !== null) {
            $body['temperature'] = $options->temperature;
        }

        if ($context->tools !== []) {
            $body['tools'] = array_map($this->tool(...), $context->tools);
        }

        if (!$model->reasoning) {
            return $body;
        }

        if ($options?->reasoning !== null) {
            $body['reasoning'] = ['effort' => $options->reasoning->value, 'summary' => 'auto'];

            // Without this the encrypted reasoning never comes back, and a thinking block
            // with nothing to replay is a thinking block that costs a turn to rebuild.
            $body['include'] = ['reasoning.encrypted_content'];

            return $body;
        }

        // gpt-5 has no way to say "do not reason". Upstream found that this is what
        // turning it off looks like; there is no documented alternative.
        if (str_starts_with($model->id, 'gpt-5')) {
            $body['input'][] = [
                'role' => 'developer',
                'content' => [['type' => 'input_text', 'text' => '# Juice: 0 !important']],
            ];
        }

        return $body;
    }

    /** @return array<string, mixed> */
    private function tool(Tool $tool): array
    {
        return [
            'type' => 'function',
            'name' => $tool->name,
            'description' => $tool->description,
            'parameters' => $tool->parameters,
            'strict' => null,
        ];
    }

    /**
     * The conversation as a list of items.
     *
     * @return list<array<string, mixed>>
     */
    private function input(Model $model, Context $context): array
    {
        $items = [];

        if ($context->systemPrompt !== null && $context->systemPrompt !== '') {
            $items[] = [
                'role' => $model->reasoning ? 'developer' : 'system',
                'content' => Utf8::sanitize($context->systemPrompt),
            ];
        }

        $position = 0;

        // Which calls actually went out. An aborted turn's calls are dropped below, and
        // a result addressed to a call this never sent is rejected outright — see the
        // note in CLAUDE.md.
        $sent = [];

        foreach (TransformMessages::apply($context->messages, $model) as $message) {
            foreach ($this->convert($message, $model, $position, $sent) as $item) {
                $items[] = $item;
            }

            $position++;
        }

        return $items;
    }

    /**
     * @param array<string, true> $sent the calls emitted so far, added to as they are
     * @return list<array<string, mixed>>
     */
    private function convert(mixed $message, Model $model, int $position, array &$sent): array
    {
        return match (true) {
            $message instanceof UserMessage => $this->user($message, $model),
            $message instanceof AssistantMessage => $this->assistant($message, $position, $sent),
            $message instanceof ToolResultMessage => $this->toolResult($message, $model, $sent),
            default => [],
        };
    }

    /** @return list<array<string, mixed>> */
    private function user(UserMessage $message, Model $model): array
    {
        $parts = $this->parts($message->content, $model);

        return $parts === [] ? [] : [['role' => 'user', 'content' => $parts]];
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
                $parts[] = ['type' => 'input_text', 'text' => Utf8::sanitize($block->text)];

                continue;
            }

            if ($block instanceof ImageContent && $model->acceptsImages()) {
                $parts[] = [
                    'type' => 'input_image',
                    'detail' => 'auto',
                    'image_url' => "data:{$block->mimeType};base64,{$block->data}",
                ];
            }
        }

        return $parts;
    }

    /**
     * @param array<string, true> $sent
     * @return list<array<string, mixed>>
     */
    private function assistant(AssistantMessage $message, int $position, array &$sent): array
    {
        $items = [];

        // A turn that failed produced blocks that were never completed. Sending a half
        // reasoning item back asks the model to continue from something it never finished.
        $failed = $message->stopReason === StopReason::Error;

        foreach ($message->content as $block) {
            if ($block instanceof ThinkingContent) {
                if (!$failed && $block->thinkingSignature !== null && $block->thinkingSignature !== '') {
                    $item = json_decode($block->thinkingSignature, true);

                    if (is_array($item)) {
                        $items[] = $item;
                    }
                }

                continue;
            }

            if ($block instanceof TextContent) {
                $items[] = [
                    'type' => 'message',
                    'role' => 'assistant',
                    'content' => [['type' => 'output_text', 'text' => Utf8::sanitize($block->text), 'annotations' => []]],
                    'status' => 'completed',
                    'id' => $this->messageId($block->textSignature, $position),
                ];

                continue;
            }

            if ($block instanceof ToolCall && !$failed) {
                [$callId, $itemId] = $this->splitIds($block->id);
                $sent[$callId] = true;

                $items[] = [
                    'type' => 'function_call',
                    'id' => $itemId,
                    'call_id' => $callId,
                    'name' => $block->name,
                    'arguments' => $this->encode($block->arguments),
                ];
            }
        }

        return $items;
    }

    /**
     * @param array<string, true> $sent
     * @return list<array<string, mixed>>
     */
    private function toolResult(ToolResultMessage $message, Model $model, array $sent): array
    {
        [$callId] = $this->splitIds($message->toolCallId);

        // A result for a call that was not sent is rejected, and it is not the result
        // that was wrong — the call it answers was dropped for being half-finished.
        if (!isset($sent[$callId])) {
            return [];
        }

        $text = [];
        $images = [];

        foreach ($message->content as $block) {
            if ($block instanceof TextContent) {
                $text[] = $block->text;
            } elseif ($block instanceof ImageContent) {
                $images[] = $block;
            }
        }

        $items = [[
            'type' => 'function_call_output',
            'call_id' => $callId,
            'output' => Utf8::sanitize($text === [] ? '(see attached image)' : implode("\n", $text)),
        ]];

        $parts = $this->parts($images, $model);

        if ($parts !== []) {
            // A function call output has nowhere to put an image, so they follow as a
            // user turn of their own.
            $items[] = [
                'role' => 'user',
                'content' => [['type' => 'input_text', 'text' => 'Attached image(s) from tool result:'], ...$parts],
            ];
        }

        return $items;
    }

    /**
     * An id OpenAI will take back.
     *
     * Its own ids can arrive longer than it accepts, so an overlong one is replaced by a
     * hash of itself: the same message has to come back under the same id every turn, and
     * a counter would renumber the conversation every time something earlier was dropped.
     */
    private function messageId(?string $signature, int $position): string
    {
        if ($signature === null || $signature === '') {
            return "msg_{$position}";
        }

        return strlen($signature) <= self::MAX_ID_LENGTH
            ? $signature
            : 'msg_' . substr(hash('xxh128', $signature), 0, 24);
    }

    /** The two ids a tool call has, as one that pig can carry. */
    private function joinIds(string $callId, string $itemId): string
    {
        return $callId . '|' . $itemId;
    }

    /** @return array{0: string, 1: string} */
    private function splitIds(string $id): array
    {
        $at = strpos($id, '|');

        // A call from another provider has one id. Reusing it for both is what upstream
        // does, and OpenAI only ever compares it with itself.
        return $at === false ? [$id, $id] : [substr($id, 0, $at), substr($id, $at + 1)];
    }

    /**
     * Where to send it, which for Copilot the token decides.
     *
     * A Copilot token's claims carry `proxy-ep=proxy.individual.githubcopilot.com` — or a
     * business account's host, or an enterprise one's — and the registry's
     * `api.individual.githubcopilot.com` is only the default for an account that has said
     * nothing. Sending to the default anyway is a 404 on somebody else's plan, so the token is
     * asked. Every other provider's base URL is a fact about the provider and is used as it is.
     */
    private function endpoint(Model $model, ?string $apiKey, string $path): string
    {
        $base = $model->provider === 'github-copilot' && $apiKey !== null && $apiKey !== ''
            ? GithubCopilot::baseUrl($apiKey)
            : $model->baseUrl;

        return rtrim($base, '/') . $path;
    }
}
