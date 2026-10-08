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
use Pig\Ai\Utils\ShortHash;
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

    /**
     * Upstream's `OPENAI_TOOL_CALL_PROVIDERS`: the providers whose `call_id|item_id` pair is kept
     * as a pair when it comes from another model. Copilot is not one of them.
     */
    private const array TOOL_CALL_PROVIDERS = ['openai', 'openai-codex', 'opencode'];

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
            // Upstream takes the response id from here and again from the terminal event.
            'response.created' => $this->onCreated($data, $builder, $open),
            'response.output_item.added' => $this->onItemStart($data, $builder, $stream),
            'response.output_item.done' => $this->onItemEnd($data, $builder, $stream, $open),
            'response.reasoning_summary_text.delta' => $this->onDelta($data, $builder, $stream, $open, 'thinking'),
            // One summary part ending and the next beginning is a paragraph break, and
            // nothing else in the stream says so.
            'response.reasoning_summary_part.done' => $this->onBreak($builder, $stream, $open),
            'response.output_text.delta', 'response.refusal.delta' => $this->onDelta($data, $builder, $stream, $open, 'text'),
            'response.function_call_arguments.delta' => $this->onArguments($data, $builder, $stream, $open),
            // **Both terminal events, and `response.incomplete` is the one that was missing.** It
            // is what the Responses API sends when the answer was cut off — `max_output_tokens`
            // reached, most often — and without it here the stream simply ended: `onCompleted()`
            // never ran, so the status was never read, `stopReason()`'s own `'incomplete' =>
            // Length` arm was unreachable, and **the usage on that event was never taken**. A turn
            // truncated by the output cap therefore came back as a clean `stop` carrying **no
            // usage at all** — the half-sentence read as the finished answer, and the turn cost
            // nothing in `/session` and the footer. Measured against the real API with a 16-token
            // budget: `stop` after 0 output tokens. Upstream handles neither event.
            'response.completed', 'response.incomplete' => $this->onCompleted($data, $builder, $open),
            'error' => $this->raise($this->errorText($data), $data, $builder),
            'response.failed' => $this->raise($this->failureText($data), $data, $builder),
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
        $item = $data['item'] ?? [];

        // Upstream's `applyMessagePhaseStopReason(item)`, run on every finished item: a message
        // marked `final_answer` sets `stop`. The terminal event's own mapping overwrites it
        // afterwards (`onCompleted()`, upstream's `finalizeResponse()`), so the net effect is
        // nil whenever the stream ends properly; it is here because upstream does it.
        if (($item['type'] ?? null) === 'message' && ($item['phase'] ?? null) === 'final_answer') {
            $builder->setStopReason(StopReason::Stop);
        }

        if ($open === null) {
            return null;
        }

        [$index, $kind] = $open;

        if ($kind === 'thinking') {
            // The whole item, kept verbatim: the summary is what a person reads, and the
            // model wants its own encrypted reasoning back or it starts over.
            $builder->setSignature($index, (string) json_encode($item));
            $stream->push(new ThinkingEndEvent($index, $builder->textOf($index), $builder->snapshot()));

            return null;
        }

        if ($kind === 'text') {
            // Upstream rebuilds the text from the finished item rather than trusting the deltas:
            // `item.content?.map((c) => (c.type === "output_text" ? c.text : c.refusal)).join("") || ""`.
            // Every `output_text` part and every `refusal` part, in order, joined with nothing —
            // the refusal is text the person must see. **The streamed text is discarded**, even
            // when the item carries no content at all, which upstream turns into "".
            $text = '';

            foreach (is_array($item['content'] ?? null) ? $item['content'] : [] as $part) {
                $piece = !is_array($part) ? null
                    : (($part['type'] ?? null) === 'output_text' ? ($part['text'] ?? null) : ($part['refusal'] ?? null));
                // JS `join()` writes undefined and null as ''.
                $text .= is_string($piece) ? $piece : '';
            }

            $builder->setText($index, $text);

            // Upstream's `encodeTextSignatureV1(item.id, item.phase ?? undefined)`: the id *and* the
            // phase, so `assistant()` can send the phase back on the same message next turn.
            $builder->setSignature($index, self::encodeTextSignatureV1($item));
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
    private function onCreated(array $data, AssistantMessageBuilder $builder, ?array $open): ?array
    {
        $id = $data['response']['id'] ?? null;

        if (is_string($id)) {
            $builder->setResponseId($id);
        }

        return $open;
    }

    /**
     * @param array<string, mixed> $data
     * @param array{0: int, 1: string}|null $open
     * @return array{0: int, 1: string}|null
     */
    private function onCompleted(array $data, AssistantMessageBuilder $builder, ?array $open): ?array
    {
        $response = $data['response'] ?? [];

        if (is_string($response['id'] ?? null) && $response['id'] !== '') {
            $builder->setResponseId($response['id']);
        }

        if (is_array($response['usage'] ?? null)) {
            $builder->setUsage($this->usage($response['usage']));
        }

        $status = (string) ($response['status'] ?? '');
        $incomplete = $response['incomplete_details']['reason'] ?? null;

        // Upstream's `rawStopReason`: the status, and for a cut-off answer why it was cut off —
        // `incomplete.max_output_tokens` or `incomplete.content_filter`, which the mapped `length`
        // cannot tell apart.
        $builder->setRawStopReason(is_string($incomplete) && $incomplete !== '' ? "{$status}.{$incomplete}" : $status);

        $reason = $this->stopReason($status);

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

        // `input_tokens` counts the cached ones too, so they come back out. Reasoning is a part
        // of `output_tokens` already, and kept beside it — 0 when not reported, as upstream does.
        return new Usage(
            max(0, (int) ($usage['input_tokens'] ?? 0) - $cached),
            (int) ($usage['output_tokens'] ?? 0),
            $cached,
            0,
            (int) ($usage['total_tokens'] ?? 0),
            reasoning: (int) ($usage['output_tokens_details']['reasoning_tokens'] ?? 0),
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

    /**
     * What an `error` event says, whichever shape it says it in.
     *
     * The documented shape is flat — `{type, code, message, param}` — and **the live one is
     * nested**, which is what a run against the real API settled: an oversized prompt used to come
     * back as a bare `unknown error`, with no `Error <code>:` in front of it either, so neither
     * field was at the documented path; with both paths read it is
     * `Error context_length_exceeded: Your input exceeds the context window of this model.` So
     * `{type: "error", error: {message, code}}` is what arrives, and reading only the documented
     * shape loses the whole message.
     *
     * The fallback is **the payload itself** rather than a sentence that describes nothing: an
     * error nobody can act on is worse than an ugly one, and the raw JSON is what says which shape
     * to read next time — which is how the nesting above was established rather than guessed.
     * `Overflow`'s table needs the provider's own words to match against, so a message that goes
     * missing here is a conversation that could have been compacted and instead died: with the
     * message back, `/exceeds the context window/i` matches, which is OpenAI's own row in that
     * table and had never been verified against the API before.
     *
     * @param array<string, mixed> $data
     */
    private function errorText(array $data): string
    {
        $nested = is_array($data['error'] ?? null) ? $data['error'] : [];
        $code = $data['code'] ?? $nested['code'] ?? null;
        $message = $data['message'] ?? $nested['message'] ?? null;

        if (!is_string($message) || $message === '') {
            return 'an error with no message in it: ' . (json_encode($data) ?: 'unreadable');
        }

        return is_string($code) ? "Error {$code}: {$message}" : $message;
    }

    /**
     * Throw for a failed response, keeping its status as the raw stop reason first.
     *
     * Upstream sets `output.rawStopReason = event.response?.status` before throwing, and the error
     * message it then builds is the same object, so the status survives into the failed turn. Here
     * the builder is what `fail()` snapshots, so setting it on the builder does the same. A bare
     * `error` event has no response and therefore leaves it as it was, as upstream's does.
     *
     * @param array<string, mixed> $data
     */
    private function raise(string $message, array $data, AssistantMessageBuilder $builder): never
    {
        $status = $data['response']['status'] ?? null;

        if (is_string($status)) {
            $builder->setRawStopReason($status);
        }

        throw new ProviderError($message);
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
            // Copilot's models speak this API, not completions, so this is the provider that has to
            // send them — and it was the one that did not. See `Copilot`.
            ...Copilot::headers($model, $context),
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

        // Upstream's `msgIndex`. It counts the messages that went out, not the messages that came
        // in: upstream's `continue` for a user turn with no content and an assistant turn with no
        // output skips its `msgIndex++`, so neither moves the count. Upstream's leading system
        // message does not count either; pig's system prompt is `Context::$systemPrompt` and never
        // in this list, so there is nothing to skip for it.
        $msgIndex = 0;
        $normalizeToolCallId = fn (string $id, Model $target, AssistantMessage $source): string
            => $this->normalizeToolCallId($id, $model, $source);

        foreach (TransformMessages::apply($context->messages, $model, $normalizeToolCallId) as $message) {
            $converted = $this->convert($message, $model, $msgIndex);

            foreach ($converted as $item) {
                $items[] = $item;
            }

            // Upstream tests the user content *before* conversion (`content.length === 0`) and the
            // assistant output after it, so these are the two tests, each where upstream has it.
            $skipped = ($message instanceof UserMessage && $message->content === [])
                || ($message instanceof AssistantMessage && $converted === []);

            if (!$skipped) {
                $msgIndex++;
            }
        }

        return $items;
    }

    /** @return list<array<string, mixed>> */
    private function convert(mixed $message, Model $model, int $msgIndex): array
    {
        return match (true) {
            $message instanceof UserMessage => $this->user($message, $model),
            $message instanceof AssistantMessage => $this->assistant($message, $model, $msgIndex),
            $message instanceof ToolResultMessage => $this->toolResult($message, $model),
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
     * Upstream's assistant arm of `convertResponsesMessages()`.
     *
     * A turn that errored or was aborted never reaches here — `TransformMessages` drops it whole,
     * calls and all — so nothing in this arm asks how the turn ended.
     *
     * @return list<array<string, mixed>>
     */
    private function assistant(AssistantMessage $message, Model $model, int $msgIndex): array
    {
        $items = [];
        $textBlockIndex = 0;

        // Upstream's `isDifferentModel`: this provider and API, another model.
        $differentModel = $message->provider === $model->provider
            && $message->api === $model->api
            && $message->model !== $model->id;

        foreach ($message->content as $block) {
            if ($block instanceof ThinkingContent) {
                if ($block->thinkingSignature !== null && $block->thinkingSignature !== '') {
                    $item = json_decode($block->thinkingSignature, true);

                    if (is_array($item)) {
                        $items[] = $item;
                    }
                }

                continue;
            }

            if ($block instanceof TextContent) {
                $parsedSignature = self::parseTextSignature($block->textSignature);
                $fallbackMessageId = $textBlockIndex === 0 ? "msg_pi_{$msgIndex}" : "msg_pi_{$msgIndex}_{$textBlockIndex}";
                $textBlockIndex++;

                // Upstream's comment: OpenAI requires id to be max 64 characters.
                $msgId = $parsedSignature['id'] ?? null;

                if ($msgId === null || $msgId === '') {
                    $msgId = $fallbackMessageId;
                } elseif (strlen($msgId) > self::MAX_ID_LENGTH) {
                    $msgId = 'msg_' . ShortHash::of($msgId);
                }

                $items[] = [
                    'type' => 'message',
                    'role' => 'assistant',
                    'content' => [['type' => 'output_text', 'text' => Utf8::sanitize($block->text), 'annotations' => []]],
                    'status' => 'completed',
                    'id' => $msgId,
                    // Upstream's `phase: parsedSignature?.phase`, which is `undefined` — and so not
                    // in the JSON at all — unless the signature carried one.
                    ...(isset($parsedSignature['phase']) ? ['phase' => $parsedSignature['phase']] : []),
                ];

                continue;
            }

            if ($block instanceof ToolCall) {
                [$callId, $itemId] = $this->splitIds($block->id);

                // Upstream's comment: OpenAI tracks which item ids were paired with an `rs_…`
                // reasoning item, and another model's reasoning is not sent back — so for a
                // different model the id is left out, which avoids that pairing check the way a
                // foreign call does. And an id that is not `fc_…` is refused outright.
                if ($differentModel || $itemId === null || !str_starts_with($itemId, 'fc_')) {
                    $itemId = null;
                }

                $items[] = [
                    'type' => 'function_call',
                    // Left out for a call this API never issued — see `splitIds()`.
                    ...($itemId === null ? [] : ['id' => $itemId]),
                    'call_id' => $callId,
                    'name' => $block->name,
                    // `{}` and not `[]` — see the same line in `OpenAiCompletions`.
                    'arguments' => $block->arguments === [] ? '{}' : $this->encode($block->arguments),
                ];
            }
        }

        return $items;
    }

    /** @return list<array<string, mixed>> */
    private function toolResult(ToolResultMessage $message, Model $model): array
    {
        [$callId] = $this->splitIds($message->toolCallId);

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
     * Upstream's `encodeTextSignatureV1()`: a finished message item's id and phase, as the JSON
     * `TextSignatureV1` (`{"v":1,"id":…,"phase":…}`) that `parseTextSignature()` reads back.
     *
     * `phase` is written only when it is truthy, as upstream's `if (phase)`. An item with no `id`
     * gives `{"v":1}`, which is what `JSON.stringify` makes of `id: undefined`.
     *
     * @param array<string, mixed> $item
     */
    private static function encodeTextSignatureV1(array $item): string
    {
        $payload = ['v' => 1];

        if (array_key_exists('id', $item)) {
            $payload['id'] = $item['id'];
        }

        $phase = $item['phase'] ?? null;

        if ($phase !== null && $phase !== '' && $phase !== false) {
            $payload['phase'] = $phase;
        }

        return (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Upstream's `parseTextSignature()`: a `TextSignatureV1` JSON, or the plain id string that
     * sessions written before it hold.
     *
     * Null for no signature. A JSON with `v: 1` and a string `id` gives that id, and its phase
     * only when it is `commentary` or `final_answer`; anything else — including text that starts
     * with `{` and is not that JSON — is a legacy plain id, whole.
     *
     * @return array{id: string, phase?: string}|null
     */
    private static function parseTextSignature(?string $signature): ?array
    {
        if ($signature === null || $signature === '') {
            return null;
        }

        if (str_starts_with($signature, '{')) {
            $parsed = json_decode($signature, true);

            // `parsed.v === 1`: a JSON number, which PHP may decode as int or float.
            if (is_array($parsed)
                && (is_int($parsed['v'] ?? null) || is_float($parsed['v'] ?? null)) && $parsed['v'] == 1
                && is_string($parsed['id'] ?? null)
            ) {
                $phase = $parsed['phase'] ?? null;

                if ($phase === 'commentary' || $phase === 'final_answer') {
                    return ['id' => $parsed['id'], 'phase' => $phase];
                }

                return ['id' => $parsed['id']];
            }
        }

        return ['id' => $signature];
    }

    /**
     * Upstream's `normalizeToolCallId` in `convertResponsesMessages()`: another model's call id,
     * made into one this API takes. Handed to `TransformMessages`, so this model's own ids never
     * pass through it, and a result follows its call.
     *
     * Each part is sanitised to `[a-zA-Z0-9_-]`, cut to 64 and stripped of trailing `_`. Only for
     * openai, openai-codex and opencode is a `call_id|item_id` pair kept as a pair — and then a
     * call from another provider or API gets an item id of `fc_` and a hash, since the one it
     * carries was minted elsewhere, and any item id is made to start `fc_`. For every other
     * provider, Copilot included, the whole id is sanitised as one, so its `|` becomes `_` and
     * no item id is sent.
     */
    private function normalizeToolCallId(string $id, Model $model, AssistantMessage $source): string
    {
        if (!in_array($model->provider, self::TOOL_CALL_PROVIDERS, true) || !str_contains($id, '|')) {
            return self::normalizeIdPart($id);
        }

        // `id.split("|")` destructured into two: a third part, if any, is dropped.
        [$callId, $itemId] = explode('|', $id, 3);
        $normalizedCallId = self::normalizeIdPart($callId);
        $isForeignToolCall = $source->provider !== $model->provider || $source->api !== $model->api;
        $normalizedItemId = $isForeignToolCall ? self::foreignItemId($itemId) : self::normalizeIdPart($itemId);

        // OpenAI Responses API requires item id to start with "fc" (upstream's comment).
        if (!str_starts_with($normalizedItemId, 'fc_')) {
            $normalizedItemId = self::normalizeIdPart("fc_{$normalizedItemId}");
        }

        return "{$normalizedCallId}|{$normalizedItemId}";
    }

    /** Upstream's `normalizeIdPart()`. */
    private static function normalizeIdPart(string $part): string
    {
        $sanitized = (string) preg_replace('/[^a-zA-Z0-9_-]/', '_', $part);

        return rtrim(substr($sanitized, 0, self::MAX_ID_LENGTH), '_');
    }

    /** Upstream's `buildForeignResponsesItemId()`: `fc_` and upstream's `shortHash()`, cut to 64. */
    private static function foreignItemId(string $itemId): string
    {
        return substr('fc_' . ShortHash::of($itemId), 0, self::MAX_ID_LENGTH);
    }

    /** The two ids a tool call has, as one that pig can carry. */
    private function joinIds(string $callId, string $itemId): string
    {
        return $callId . '|' . $itemId;
    }

    /**
     * The call id, and the item's own id when this call came from here.
     *
     * **A call from another provider has one id, and the item id is then null rather than a copy
     * of it** — because OpenAI validates the *shape* of `id`: it must begin with `fc`. Reusing the
     * call id gets `400 Invalid 'input[1].id': 'toolu_…'. Expected an ID that begins with 'fc'`,
     * which is a whole conversation refused, and it is what pig did until a live run said so. The
     * sentence that used to be here — "reusing it for both is what upstream does, and OpenAI only
     * ever compares it with itself" — was wrong in both halves.
     *
     * Upstream writes `id: toolCall.id.split("|")[1]`, and for an id with no `|` that is
     * **`undefined`**, which `JSON.stringify` leaves out of the object entirely. So upstream sends
     * no `id` at all here and OpenAI accepts the item. PHP has no `undefined` and no index that
     * evaluates to a missing field, so the omission has to be deliberate — *the third shape from
     * CLAUDE.md's index, on a value that does not exist in one of the two languages.*
     *
     * Which conversations this reaches: a dangling call `TransformMessages` invented a result for,
     * and any history carried over from another provider — `/model` from Anthropic to gpt-5 with a
     * tool call in it, which is the case `TransformMessages` exists to make possible.
     *
     * @return array{0: string, 1: string|null}
     */
    private function splitIds(string $id): array
    {
        $at = strpos($id, '|');

        return $at === false ? [$id, null] : [substr($id, 0, $at), substr($id, $at + 1)];
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
