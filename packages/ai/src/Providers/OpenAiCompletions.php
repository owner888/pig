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
use Pig\Ai\OpenAiCompat;
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
 * The OpenAI chat-completions API, streamed — and the six other providers that speak it.
 *
 * Groq, Cerebras, xAI, Zai, Mistral and OpenRouter all answer this shape, which is why
 * it is worth more than the one provider in its name. Where they differ they differ
 * quietly, so the differences live in `OpenAiCompat` as a table rather than in `if`s here.
 *
 * Structurally unlike `Anthropic` in one way that matters: Anthropic numbers its content
 * blocks and says when each opens and closes. This does not. A block is open until
 * something of a different kind arrives, so the boundaries are worked out here — which is
 * the only real complexity in the file.
 *
 * Upstream hands this to the `openai` package. There is none here, so the request is
 * built by hand and the response goes through HttpClient and SseParser, exactly as the
 * Anthropic provider does.
 *
 * Ported from upstream's `providers/openai-completions.ts`.
 */
final class OpenAiCompletions
{
    /** Where reasoning arrives, depending on who is answering. */
    private const array REASONING_FIELDS = ['reasoning_content', 'reasoning', 'reasoning_text'];

    /** Mistral wants tool ids exactly this long, alphanumeric, and rejects anything else. */
    private const int MISTRAL_ID_LENGTH = 9;

    private const string MISTRAL_PADDING = 'ABCDEFGHI';

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

        // The block that is open, as [index, kind, id]. Nothing in the protocol says a
        // block has ended, so it ends when the next thing is not the same kind.
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
                    // The stream ends with a literal `[DONE]`, which is not JSON.
                    if (trim($event->data) === '[DONE]') {
                        continue;
                    }

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
     * @param array{0: int, 1: string, 2: string}|null $open
     * @return array{0: int, 1: string, 2: string}|null
     */
    private function onChunk(
        array $data,
        AssistantMessageBuilder $builder,
        AssistantMessageEventStream $stream,
        ?array $open,
    ): ?array {
        if (isset($data['usage']) && is_array($data['usage'])) {
            $builder->setUsage($this->usage($data['usage']));
        }

        $choice = $data['choices'][0] ?? null;

        if (!is_array($choice)) {
            return $open;
        }

        if (is_string($choice['finish_reason'] ?? null)) {
            $builder->setStopReason($this->stopReason($choice['finish_reason']));
        }

        $delta = $choice['delta'] ?? null;

        if (!is_array($delta)) {
            return $open;
        }

        $text = $delta['content'] ?? null;

        if (is_string($text) && $text !== '') {
            $open = $this->onText($text, $builder, $stream, $open);
        }

        foreach (self::REASONING_FIELDS as $field) {
            $thinking = $delta[$field] ?? null;

            if (is_string($thinking) && $thinking !== '') {
                $open = $this->onThinking($thinking, $field, $builder, $stream, $open);
            }
        }

        foreach ($delta['tool_calls'] ?? [] as $call) {
            if (is_array($call)) {
                $open = $this->onToolCall($call, $builder, $stream, $open);
            }
        }

        // After the calls, because it names one of them.
        if (is_array($delta['reasoning_details'] ?? null)) {
            $this->onReasoningDetails($delta['reasoning_details'], $builder);
        }

        return $open;
    }

    /**
     * OpenRouter's encrypted reasoning, filed against the call it belongs to.
     *
     * A reasoning model reached through OpenRouter returns its chain of thought as an opaque blob
     * rather than as text, addressed to a tool call by id, and it has to go back out with that call
     * or the next turn starts the reasoning over. Both halves of this were missing: nothing read the
     * field and nothing wrote it, so multi-step tool use through OpenRouter lost the model's
     * reasoning between every turn. Upstream reads it here and writes it in `assistant()`.
     *
     * The whole detail is kept, not just its data, because that is what goes back.
     *
     * @param list<mixed> $details
     */
    private function onReasoningDetails(array $details, AssistantMessageBuilder $builder): void
    {
        foreach ($details as $detail) {
            if (!is_array($detail) || ($detail['type'] ?? null) !== 'reasoning.encrypted') {
                continue;
            }

            $id = $detail['id'] ?? null;

            if (!is_string($id) || $id === '' || !is_string($detail['data'] ?? null)) {
                continue;
            }

            $index = $builder->indexOfToolCall($id);

            if ($index !== null) {
                $builder->setSignature($index, (string) json_encode($detail));
            }
        }
    }

    /**
     * @param array{0: int, 1: string, 2: string}|null $open
     * @return array{0: int, 1: string, 2: string}
     */
    private function onText(
        string $delta,
        AssistantMessageBuilder $builder,
        AssistantMessageEventStream $stream,
        ?array $open,
    ): array {
        if ($open === null || $open[1] !== 'text') {
            $this->close($builder, $stream, $open);
            $index = $builder->startText($builder->nextWire());
            $stream->push(new TextStartEvent($index, $builder->snapshot()));
            $open = [$index, 'text', ''];
        }

        $builder->append($open[0], 'text', $delta);
        $stream->push(new TextDeltaEvent($open[0], $delta, $builder->snapshot()));

        return $open;
    }

    /**
     * @param string $field which of the three reasoning fields this arrived in, kept so
     *        the same one is used when the message is sent back
     * @param array{0: int, 1: string, 2: string}|null $open
     * @return array{0: int, 1: string, 2: string}
     */
    private function onThinking(
        string $delta,
        string $field,
        AssistantMessageBuilder $builder,
        AssistantMessageEventStream $stream,
        ?array $open,
    ): array {
        if ($open === null || $open[1] !== 'thinking') {
            $this->close($builder, $stream, $open);
            $index = $builder->startThinking($builder->nextWire());
            $builder->append($index, 'signature', $field);
            $stream->push(new ThinkingStartEvent($index, $builder->snapshot()));
            $open = [$index, 'thinking', ''];
        }

        $builder->append($open[0], 'text', $delta);
        $stream->push(new ThinkingDeltaEvent($open[0], $delta, $builder->snapshot()));

        return $open;
    }

    /**
     * @param array<string, mixed> $call
     * @param array{0: int, 1: string, 2: string}|null $open
     * @return array{0: int, 1: string, 2: string}
     */
    private function onToolCall(
        array $call,
        AssistantMessageBuilder $builder,
        AssistantMessageEventStream $stream,
        ?array $open,
    ): array {
        $id = (string) ($call['id'] ?? '');
        $name = (string) ($call['function']['name'] ?? '');

        // A new id while a call is open means a second call, not more of the first: some
        // providers send two in one chunk.
        if ($open === null || $open[1] !== 'toolCall' || ($id !== '' && $open[2] !== '' && $open[2] !== $id)) {
            $this->close($builder, $stream, $open);
            $index = $builder->startToolCall($builder->nextWire(), $id, $name);
            $stream->push(new ToolCallStartEvent($index, $builder->snapshot()));
            $open = [$index, 'toolCall', $id];
        }

        $builder->setToolCall($open[0], $id, $name);
        $open[2] = $id === '' ? $open[2] : $id;

        $arguments = $call['function']['arguments'] ?? null;

        if (is_string($arguments) && $arguments !== '') {
            $builder->append($open[0], 'json', $arguments);
            $stream->push(new ToolCallDeltaEvent($open[0], $arguments, $builder->snapshot()));
        }

        return $open;
    }

    /** @param array{0: int, 1: string, 2: string}|null $open */
    private function close(AssistantMessageBuilder $builder, AssistantMessageEventStream $stream, ?array $open): void
    {
        if ($open === null) {
            return;
        }

        [$index, $kind] = $open;
        $snapshot = $builder->snapshot();

        match ($kind) {
            'thinking' => $stream->push(new ThinkingEndEvent($index, $builder->textOf($index), $snapshot)),
            'toolCall' => $stream->push(new ToolCallEndEvent($index, $builder->toolCallOf($index), $snapshot)),
            default => $stream->push(new TextEndEvent($index, $builder->textOf($index), $snapshot)),
        };
    }

    /** @param array<string, mixed> $usage */
    private function usage(array $usage): Usage
    {
        $cached = (int) ($usage['prompt_tokens_details']['cached_tokens'] ?? 0);
        $reasoning = (int) ($usage['completion_tokens_details']['reasoning_tokens'] ?? 0);

        // `prompt_tokens` includes the cached ones, so they are taken back out: input
        // here means what was actually paid for at the input rate.
        $input = max(0, (int) ($usage['prompt_tokens'] ?? 0) - $cached);

        // Reasoning tokens are billed as output and some providers (Groq) leave them out
        // of the total, so the total is added up here rather than read.
        return new Usage($input, (int) ($usage['completion_tokens'] ?? 0) + $reasoning, $cached, 0);
    }

    private function stopReason(string $reason): StopReason
    {
        return match ($reason) {
            'stop' => StopReason::Stop,
            'length' => StopReason::Length,
            'tool_calls', 'function_call' => StopReason::ToolUse,
            'content_filter' => StopReason::Error,
            default => StopReason::Stop,
        };
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
            // After the model's own, which is upstream's order: a registry entry cannot turn off
            // the headers Copilot needs to accept the request at all.
            ...Copilot::headers($model, $context),
        ];

        return new Request(
            'POST',
            $this->endpoint($model, $options?->apiKey, '/chat/completions'),
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
        $compat = $model->compat ?? OpenAiCompat::detect($model->baseUrl);

        $body = [
            'model' => $model->id,
            'messages' => $this->messages($model, $context, $compat),
            'stream' => true,
            'stream_options' => ['include_usage' => true],
        ];

        if ($compat->store) {
            $body['store'] = false;
        }

        if ($options?->maxTokens !== null) {
            $body[$compat->maxTokensField] = $options->maxTokens;
        }

        if ($options?->temperature !== null) {
            $body['temperature'] = $options->temperature;
        }

        if ($context->tools !== []) {
            $body['tools'] = array_map($this->tool(...), $context->tools);
        } elseif ($this->hasToolHistory($context->messages)) {
            // A conversation holding tool calls is rejected by some proxies unless the
            // tools field is present, even with nothing in it.
            $body['tools'] = [];
        }

        if ($options?->toolChoice !== null) {
            $body['tool_choice'] = $options->toolChoice;
        }

        if ($options?->reasoning !== null && $model->reasoning && $compat->reasoningEffort) {
            $body['reasoning_effort'] = $options->reasoning->value;
        }

        return $body;
    }

    /** @param list<mixed> $messages */
    private function hasToolHistory(array $messages): bool
    {
        foreach ($messages as $message) {
            if ($message instanceof ToolResultMessage) {
                return true;
            }

            if (!$message instanceof AssistantMessage) {
                continue;
            }

            foreach ($message->content as $block) {
                if ($block instanceof ToolCall) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    private function tool(Tool $tool): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $tool->name,
                'description' => $tool->description,
                'parameters' => $tool->parameters,
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function messages(Model $model, Context $context, OpenAiCompat $compat): array
    {
        $out = [];

        if ($context->systemPrompt !== null && $context->systemPrompt !== '') {
            // A reasoning model reads `developer` as the stronger of the two roles; the
            // strict endpoints have never heard of it.
            $role = $model->reasoning && $compat->developerRole ? 'developer' : 'system';
            $out[] = ['role' => $role, 'content' => Utf8::sanitize($context->systemPrompt)];
        }

        $previous = null;

        foreach (TransformMessages::apply($context->messages, $model) as $message) {
            if ($compat->assistantAfterToolResult
                && $previous instanceof ToolResultMessage
                && $message instanceof UserMessage
            ) {
                $out[] = ['role' => 'assistant', 'content' => 'I have processed the tool results.'];
            }

            foreach ($this->convert($message, $model, $compat) as $converted) {
                $out[] = $converted;
            }

            $previous = $message;
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function convert(mixed $message, Model $model, OpenAiCompat $compat): array
    {
        return match (true) {
            $message instanceof UserMessage => $this->user($message, $model),
            $message instanceof AssistantMessage => $this->assistant($message, $model, $compat),
            $message instanceof ToolResultMessage => $this->toolResult($message, $model, $compat),
            default => [],
        };
    }

    /** @return list<array<string, mixed>> */
    private function user(UserMessage $message, Model $model): array
    {
        $parts = [];

        foreach ($message->content as $block) {
            if ($block instanceof TextContent) {
                $parts[] = ['type' => 'text', 'text' => Utf8::sanitize($block->text)];

                continue;
            }

            if ($block instanceof ImageContent && $model->acceptsImages()) {
                $parts[] = [
                    'type' => 'image_url',
                    'image_url' => ['url' => "data:{$block->mimeType};base64,{$block->data}"],
                ];
            }
        }

        // An empty turn is rejected outright, and a user message that was nothing but an
        // image the model cannot see has nothing left in it.
        return $parts === [] ? [] : [['role' => 'user', 'content' => $parts]];
    }

    /** @return list<array<string, mixed>> */
    private function assistant(AssistantMessage $message, Model $model, OpenAiCompat $compat): array
    {
        $text = [];
        $thinking = [];
        $calls = [];

        foreach ($message->content as $block) {
            if ($block instanceof TextContent && trim($block->text) !== '') {
                $text[] = Utf8::sanitize($block->text);
            } elseif ($block instanceof ThinkingContent && trim($block->thinking) !== '') {
                $thinking[] = $block;
            } elseif ($block instanceof ToolCall) {
                $calls[] = $block;
            }
        }

        if ($compat->thinkingAsText && $thinking !== []) {
            // Some endpoints have no field for it, so reasoning goes in front of the text
            // it led to, tagged, rather than being dropped.
            $tagged = array_map(
                static fn (ThinkingContent $block): string => "<thinking>\n{$block->thinking}\n</thinking>",
                $thinking,
            );
            $text = [...$tagged, ...$text];
            $thinking = [];
        }

        // Mistral rejects a null content and every endpoint rejects an assistant turn
        // that has neither content nor calls.
        $out = ['role' => 'assistant', 'content' => $compat->assistantAfterToolResult ? '' : null];

        if ($text !== []) {
            // Copilot answers an array by re-answering every earlier prompt, so its text
            // goes as one string.
            $out['content'] = $model->provider === 'github-copilot'
                ? implode('', $text)
                : array_map(static fn (string $one): array => ['type' => 'text', 'text' => $one], $text);
        }

        foreach ($thinking as $block) {
            // Sent back in the field it arrived in, which is what the signature holds.
            if ($block->thinkingSignature !== null && $block->thinkingSignature !== '') {
                $out[$block->thinkingSignature] = ($out[$block->thinkingSignature] ?? '') . $block->thinking;
            }
        }

        if ($calls !== []) {
            $out['tool_calls'] = array_map(
                fn (ToolCall $call): array => [
                    'id' => $this->toolId($call->id, $compat),
                    'type' => 'function',
                    'function' => ['name' => $call->name, 'arguments' => $this->encode($call->arguments)],
                ],
                $calls,
            );

            // The encrypted reasoning that came with these calls, handed back as it arrived —
            // see `onReasoningDetails()`. Anything that will not decode is left out rather than
            // sent as a string: the field is a list of objects and OpenRouter rejects it otherwise.
            $details = [];

            foreach ($calls as $call) {
                $decoded = $call->thoughtSignature === null ? null : json_decode($call->thoughtSignature, true);

                if (is_array($decoded)) {
                    $details[] = $decoded;
                }
            }

            if ($details !== []) {
                $out['reasoning_details'] = $details;
            }
        }

        $empty = ($out['content'] === null || $out['content'] === '' || $out['content'] === [])
            && !isset($out['tool_calls']);

        return $empty ? [] : [$out];
    }

    /** @return list<array<string, mixed>> */
    private function toolResult(ToolResultMessage $message, Model $model, OpenAiCompat $compat): array
    {
        $text = [];
        $images = [];

        foreach ($message->content as $block) {
            if ($block instanceof TextContent) {
                $text[] = $block->text;
            } elseif ($block instanceof ImageContent) {
                $images[] = $block;
            }
        }

        $result = [
            'role' => 'tool',
            'content' => Utf8::sanitize($text === [] ? '(see attached image)' : implode("\n", $text)),
            'tool_call_id' => $this->toolId($message->toolCallId, $compat),
        ];

        if ($compat->toolResultName && $message->toolName !== '') {
            $result['name'] = $message->toolName;
        }

        $out = [$result];

        if ($images === [] || !$model->acceptsImages()) {
            return $out;
        }

        // A tool result has nowhere to put an image, so the images follow as a user turn
        // of their own.
        $parts = [['type' => 'text', 'text' => 'Attached image(s) from tool result:']];

        foreach ($images as $image) {
            $parts[] = [
                'type' => 'image_url',
                'image_url' => ['url' => "data:{$image->mimeType};base64,{$image->data}"],
            ];
        }

        $out[] = ['role' => 'user', 'content' => $parts];

        return $out;
    }

    /**
     * A tool id the endpoint will accept.
     *
     * Mistral wants exactly nine alphanumeric characters. Padded deterministically rather
     * than randomly, because the call and its result are shortened separately and have to
     * come out the same.
     */
    private function toolId(string $id, OpenAiCompat $compat): string
    {
        if (!$compat->mistralToolIds) {
            return $id;
        }

        $clean = (string) preg_replace('/[^a-zA-Z0-9]/', '', $id);

        return strlen($clean) >= self::MISTRAL_ID_LENGTH
            ? substr($clean, 0, self::MISTRAL_ID_LENGTH)
            : $clean . substr(self::MISTRAL_PADDING, 0, self::MISTRAL_ID_LENGTH - strlen($clean));
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
