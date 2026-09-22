<?php

declare(strict_types=1);

namespace Pig\Ai\Providers;

use Pig\Ai\AssistantMessage;
use Pig\Ai\Context;
use Pig\Ai\DoneEvent;
use Pig\Ai\ErrorEvent;
use Pig\Ai\Http\HttpClient;
use Pig\Ai\Http\Request;
use Pig\Ai\Http\SseEvent;
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
 * The Anthropic Messages API, streamed.
 *
 * Upstream hands this to `@anthropic-ai/sdk`, which owns the HTTP and the SSE framing.
 * There is no such package here, so the request is built by hand and the response goes
 * through HttpClient and SseParser. Everything below the event names is ours; the event
 * names, the field names and the order they arrive in are Anthropic's.
 */
final class Anthropic
{
    private const string VERSION = '2023-06-01';

    private const string FINE_GRAINED_STREAMING = 'fine-grained-tool-streaming-2025-05-14';

    private const string INTERLEAVED_THINKING = 'interleaved-thinking-2025-05-14';

    /** Anthropic wants tool ids matching ^[a-zA-Z0-9_-]+$ and rejects the request otherwise. */
    private const string ID_PATTERN = '/[^a-zA-Z0-9_-]/';

    public function __construct(private readonly HttpClient $http = new HttpClient())
    {
    }

    /** Returns at once; the response fills in as it arrives. */
    public function stream(Model $model, Context $context, ?AnthropicOptions $options = null): AssistantMessageEventStream
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
        ?AnthropicOptions $options,
    ): void {
        $builder = new AssistantMessageBuilder($model);
        $signal = $options?->signal;

        try {
            $response = $this->http->send($this->request($model, $context, $options), $signal);

            if (!$response->isSuccessful()) {
                throw new ProviderError($this->explain($response->status, $response->body->all()));
            }

            $stream->push(new StartEvent($builder->snapshot()));
            $parser = new SseParser();

            foreach ($response->body as $chunk) {
                foreach ($parser->feed($chunk) as $event) {
                    $this->dispatch($event, $builder, $stream);
                }
            }

            // An abort mid-stream ends the body quietly; say so rather than reporting success.
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

    private function dispatch(SseEvent $event, AssistantMessageBuilder $builder, AssistantMessageEventStream $stream): void
    {
        $data = json_decode($event->data, true);

        if (!is_array($data)) {
            return;
        }

        match ($event->type) {
            'message_start' => $this->onMessageStart($data, $builder),
            'content_block_start' => $this->onBlockStart($data, $builder, $stream),
            'content_block_delta' => $this->onBlockDelta($data, $builder, $stream),
            'content_block_stop' => $this->onBlockStop($data, $builder, $stream),
            'message_delta' => $this->onMessageDelta($data, $builder),
            // ping and message_stop carry nothing this port needs; an error arrives as a
            // non-2xx status or as a stream that stops, both handled by the caller.
            default => null,
        };
    }

    /** @param array<string, mixed> $data */
    private function onMessageStart(array $data, AssistantMessageBuilder $builder): void
    {
        // Captured here as well as at the end, so an aborted run still knows its input cost.
        $builder->setUsage($this->usage($data['message']['usage'] ?? []));
    }

    /** @param array<string, mixed> $data */
    private function onBlockStart(array $data, AssistantMessageBuilder $builder, AssistantMessageEventStream $stream): void
    {
        $wire = (int) ($data['index'] ?? 0);
        $block = $data['content_block'] ?? [];

        match ($block['type'] ?? '') {
            'text' => $stream->push(new TextStartEvent($builder->startText($wire), $builder->snapshot())),
            'thinking' => $stream->push(new ThinkingStartEvent($builder->startThinking($wire), $builder->snapshot())),
            'tool_use' => $stream->push(new ToolCallStartEvent(
                $builder->startToolCall($wire, (string) ($block['id'] ?? ''), (string) ($block['name'] ?? '')),
                $builder->snapshot(),
            )),
            default => null,
        };
    }

    /** @param array<string, mixed> $data */
    private function onBlockDelta(array $data, AssistantMessageBuilder $builder, AssistantMessageEventStream $stream): void
    {
        $index = $builder->indexOf((int) ($data['index'] ?? 0));

        if ($index === null) {
            return;
        }

        $delta = $data['delta'] ?? [];

        match ($delta['type'] ?? '') {
            'text_delta' => $this->appendText($builder, $stream, $index, (string) ($delta['text'] ?? '')),
            'thinking_delta' => $this->appendThinking($builder, $stream, $index, (string) ($delta['thinking'] ?? '')),
            'input_json_delta' => $this->appendJson($builder, $stream, $index, (string) ($delta['partial_json'] ?? '')),
            // The signature authenticates the thinking block; it is collected but never shown.
            'signature_delta' => $builder->append($index, 'signature', (string) ($delta['signature'] ?? '')),
            default => null,
        };
    }

    private function appendText(AssistantMessageBuilder $builder, AssistantMessageEventStream $stream, int $index, string $delta): void
    {
        $builder->append($index, 'text', $delta);
        $stream->push(new TextDeltaEvent($index, $delta, $builder->snapshot()));
    }

    private function appendThinking(AssistantMessageBuilder $builder, AssistantMessageEventStream $stream, int $index, string $delta): void
    {
        $builder->append($index, 'text', $delta);
        $stream->push(new ThinkingDeltaEvent($index, $delta, $builder->snapshot()));
    }

    private function appendJson(AssistantMessageBuilder $builder, AssistantMessageEventStream $stream, int $index, string $delta): void
    {
        $builder->append($index, 'json', $delta);
        $stream->push(new ToolCallDeltaEvent($index, $delta, $builder->snapshot()));
    }

    /** @param array<string, mixed> $data */
    private function onBlockStop(array $data, AssistantMessageBuilder $builder, AssistantMessageEventStream $stream): void
    {
        $index = $builder->indexOf((int) ($data['index'] ?? 0));

        if ($index === null) {
            return;
        }

        $snapshot = $builder->snapshot();
        $block = $snapshot->content[$index];

        match (true) {
            $block instanceof ThinkingContent => $stream->push(
                new ThinkingEndEvent($index, $builder->textOf($index), $snapshot),
            ),
            $block instanceof ToolCall => $stream->push(
                new ToolCallEndEvent($index, $builder->toolCallOf($index), $snapshot),
            ),
            default => $stream->push(new TextEndEvent($index, $builder->textOf($index), $snapshot)),
        };
    }

    /** @param array<string, mixed> $data */
    private function onMessageDelta(array $data, AssistantMessageBuilder $builder): void
    {
        $reason = $data['delta']['stop_reason'] ?? null;

        if (is_string($reason)) {
            $builder->setStopReason($this->stopReason($reason));
        }

        $builder->setUsage($this->usage($data['usage'] ?? []));
    }

    /** @param array<string, mixed> $usage */
    private function usage(array $usage): Usage
    {
        // Anthropic reports the components and no total; withTotalTokens() adds it up.
        return new Usage(
            (int) ($usage['input_tokens'] ?? 0),
            (int) ($usage['output_tokens'] ?? 0),
            (int) ($usage['cache_read_input_tokens'] ?? 0),
            (int) ($usage['cache_creation_input_tokens'] ?? 0),
        );
    }

    private function stopReason(string $reason): StopReason
    {
        return match ($reason) {
            'end_turn' => StopReason::Stop,
            'max_tokens' => StopReason::Length,
            'tool_use' => StopReason::ToolUse,
            'refusal' => StopReason::Error,
            // pause_turn asks for a resubmit; treating it as a normal stop is good enough.
            'pause_turn' => StopReason::Stop,
            // We send no stop sequences, so this should not arrive.
            'stop_sequence' => StopReason::Stop,
            default => StopReason::Stop,
        };
    }

    private function explain(int $status, string $body): string
    {
        $decoded = json_decode($body, true);
        $message = is_array($decoded) ? ($decoded['error']['message'] ?? null) : null;

        return "Anthropic returned {$status}: " . (is_string($message) ? $message : trim($body));
    }

    private function request(Model $model, Context $context, ?AnthropicOptions $options): Request
    {
        $apiKey = $options?->apiKey ?? '';
        // An OAuth token authenticates as Claude Code and goes in a bearer header instead.
        $isOAuth = str_contains($apiKey, 'sk-ant-oat');

        $beta = [self::FINE_GRAINED_STREAMING];

        if ($options?->interleavedThinking ?? true) {
            $beta[] = self::INTERLEAVED_THINKING;
        }

        if ($isOAuth) {
            array_unshift($beta, 'oauth-2025-04-20');
        }

        $headers = [
            'accept' => 'application/json',
            'content-type' => 'application/json',
            'anthropic-version' => self::VERSION,
            'anthropic-beta' => implode(',', $beta),
            ...($isOAuth ? ['authorization' => "Bearer {$apiKey}"] : ['x-api-key' => $apiKey]),
            ...$model->headers,
        ];

        return new Request(
            'POST',
            rtrim($model->baseUrl, '/') . '/v1/messages',
            $headers,
            $this->encode($this->body($model, $context, $options, $isOAuth)),
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
    private function body(Model $model, Context $context, ?AnthropicOptions $options, bool $isOAuth): array
    {
        $body = [
            'model' => $model->id,
            'messages' => $this->messages($context, $model),
            'max_tokens' => $options?->maxTokens ?? intdiv($model->maxTokens, 3),
            'stream' => true,
        ];

        $system = $this->system($context, $isOAuth);

        if ($system !== []) {
            $body['system'] = $system;
        }

        if ($options?->temperature !== null) {
            $body['temperature'] = $options->temperature;
        }

        if ($context->tools !== []) {
            $body['tools'] = array_map($this->tool(...), $context->tools);
        }

        if (($options?->thinkingEnabled ?? false) && $model->reasoning) {
            $body['thinking'] = ['type' => 'enabled', 'budget_tokens' => $options->thinkingBudgetTokens];
        }

        return $body;
    }

    /** @return list<array<string, mixed>> */
    private function system(Context $context, bool $isOAuth): array
    {
        $blocks = [];

        // An OAuth token is Claude Code's, and Anthropic requires the matching identity.
        if ($isOAuth) {
            $blocks[] = $this->cachedText("You are Claude Code, Anthropic's official CLI for Claude.");
        }

        if ($context->systemPrompt !== null && $context->systemPrompt !== '') {
            $blocks[] = $this->cachedText(Utf8::sanitize($context->systemPrompt));
        }

        return $blocks;
    }

    /** @return array<string, mixed> */
    private function cachedText(string $text): array
    {
        return ['type' => 'text', 'text' => $text, 'cache_control' => ['type' => 'ephemeral']];
    }

    /** @return array<string, mixed> */
    private function tool(Tool $tool): array
    {
        return [
            'name' => $tool->name,
            'description' => $tool->description,
            'input_schema' => [
                'type' => 'object',
                'properties' => $tool->parameters['properties'] ?? new \stdClass(),
                'required' => $tool->parameters['required'] ?? [],
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function messages(Context $context, Model $model): array
    {
        $out = [];
        $messages = $context->messages;
        $count = count($messages);

        for ($i = 0; $i < $count; $i++) {
            $message = $messages[$i];

            if ($message instanceof UserMessage) {
                $blocks = $this->userBlocks($message, $model);

                if ($blocks !== []) {
                    $out[] = ['role' => 'user', 'content' => $blocks];
                }

                continue;
            }

            if ($message instanceof AssistantMessage) {
                $blocks = $this->assistantBlocks($message);

                if ($blocks !== []) {
                    $out[] = ['role' => 'assistant', 'content' => $blocks];
                }

                continue;
            }

            if ($message instanceof ToolResultMessage) {
                // Anthropic wants every consecutive result in one user turn.
                $results = [];

                while ($i < $count && $messages[$i] instanceof ToolResultMessage) {
                    $results[] = $this->toolResult($messages[$i]);
                    $i++;
                }

                $i--;
                $out[] = ['role' => 'user', 'content' => $results];
            }
        }

        $this->cacheLastUserBlock($out);

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function userBlocks(UserMessage $message, Model $model): array
    {
        $blocks = [];

        foreach ($message->content as $content) {
            if ($content instanceof ImageContent) {
                if ($model->acceptsImages()) {
                    $blocks[] = $this->image($content);
                }

                continue;
            }

            if ($content instanceof TextContent && trim($content->text) !== '') {
                $blocks[] = ['type' => 'text', 'text' => Utf8::sanitize($content->text)];
            }
        }

        return $blocks;
    }

    /** @return list<array<string, mixed>> */
    private function assistantBlocks(AssistantMessage $message): array
    {
        $blocks = [];

        foreach ($message->content as $content) {
            if ($content instanceof TextContent) {
                if (trim($content->text) !== '') {
                    $blocks[] = ['type' => 'text', 'text' => Utf8::sanitize($content->text)];
                }

                continue;
            }

            if ($content instanceof ThinkingContent) {
                if (trim($content->thinking) === '') {
                    continue;
                }

                // Thinking with no signature — an aborted stream leaves that behind — is
                // rejected by the API, and sending it as <thinking> text teaches the model
                // to imitate the tags. Plain text keeps the content and neither problem.
                $blocks[] = $content->thinkingSignature === null || trim($content->thinkingSignature) === ''
                    ? ['type' => 'text', 'text' => Utf8::sanitize($content->thinking)]
                    : [
                        'type' => 'thinking',
                        'thinking' => Utf8::sanitize($content->thinking),
                        'signature' => $content->thinkingSignature,
                    ];

                continue;
            }

            if ($content instanceof ToolCall) {
                $blocks[] = [
                    'type' => 'tool_use',
                    'id' => $this->toolCallId($content->id),
                    'name' => $content->name,
                    'input' => $content->arguments === [] ? new \stdClass() : $content->arguments,
                ];
            }
        }

        return $blocks;
    }

    /** @return array<string, mixed> */
    private function toolResult(ToolResultMessage $message): array
    {
        return [
            'type' => 'tool_result',
            'tool_use_id' => $this->toolCallId($message->toolCallId),
            'content' => $this->resultContent($message),
            'is_error' => $message->isError,
        ];
    }

    /** @return string|list<array<string, mixed>> */
    private function resultContent(ToolResultMessage $message): string|array
    {
        $images = array_filter($message->content, static fn ($block): bool => $block instanceof ImageContent);

        if ($images === []) {
            $texts = array_map(
                static fn ($block): string => $block instanceof TextContent ? $block->text : '',
                $message->content,
            );

            return Utf8::sanitize(implode("\n", $texts));
        }

        $blocks = [];

        foreach ($message->content as $block) {
            $blocks[] = $block instanceof ImageContent
                ? $this->image($block)
                : ['type' => 'text', 'text' => Utf8::sanitize($block instanceof TextContent ? $block->text : '')];
        }

        // An image-only result needs something to hang the images on.
        $hasText = array_filter($blocks, static fn (array $block): bool => $block['type'] === 'text') !== [];

        if (!$hasText) {
            array_unshift($blocks, ['type' => 'text', 'text' => '(see attached image)']);
        }

        return $blocks;
    }

    /** @return array<string, mixed> */
    private function image(ImageContent $image): array
    {
        return [
            'type' => 'image',
            'source' => ['type' => 'base64', 'media_type' => $image->mimeType, 'data' => $image->data],
        ];
    }

    private function toolCallId(string $id): string
    {
        return preg_replace(self::ID_PATTERN, '_', $id) ?? $id;
    }

    /**
     * Mark the end of the conversation so the prefix can be cached next turn.
     *
     * @param list<array<string, mixed>> $messages
     */
    private function cacheLastUserBlock(array &$messages): void
    {
        $last = count($messages) - 1;

        if ($last < 0 || $messages[$last]['role'] !== 'user' || !is_array($messages[$last]['content'])) {
            return;
        }

        $blocks = $messages[$last]['content'];
        $lastBlock = count($blocks) - 1;

        if ($lastBlock >= 0 && in_array($blocks[$lastBlock]['type'], ['text', 'image', 'tool_result'], true)) {
            $blocks[$lastBlock]['cache_control'] = ['type' => 'ephemeral'];
            $messages[$last]['content'] = $blocks;
        }
    }
}
