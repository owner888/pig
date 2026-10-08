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
use Pig\Ai\Utils\ShortHash;
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

    /** `JSON.stringify`'s output for what is stored and replayed: no escaped slashes or Unicode. */
    private const int JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    /** Mistral wants tool ids exactly this long, alphanumeric, and rejects anything else. */
    private const int MISTRAL_ID_LENGTH = 9;

    private const string MISTRAL_PADDING = 'ABCDEFGHI';

    /** OpenAI's ceiling on a chat-completions tool call id. */
    private const int MAX_ID_LENGTH = 40;

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

        // Upstream's `streamedReasoningDetails`: the message's `reasoning_details`, merged as they
        // stream and written into the thinking block's signature once, at the end — and, beside
        // it, the thinking block made only to hold them, when no reasoning text came to make one.
        // See `onReasoningDetails()`.
        $replay = ['details' => null, 'detached' => null];

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
                        $open = $this->onChunk($data, $model, $builder, $stream, $open, $replay);
                    }
                }
            }

            self::applyStreamedReasoningDetails($builder, $replay);
            $this->close($builder, $stream, $open);

            if ($replay['detached'] !== null) {
                $stream->push(new ThinkingEndEvent($replay['detached'], $builder->textOf($replay['detached']), $builder->snapshot()));
            }

            $signal?->throwIfAborted();

            $message = $builder->snapshot();
            $stream->push(new DoneEvent($message->stopReason, $message));
            $stream->end();
        } catch (Throwable $error) {
            // Upstream applies the details in its `catch` too, so a failed turn keeps them.
            self::applyStreamedReasoningDetails($builder, $replay);

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
     * @param array{details: list<array<string, mixed>>|null, detached: int|null} $replay
     * @return array{0: int, 1: string, 2: string}|null
     */
    private function onChunk(
        array $data,
        Model $model,
        AssistantMessageBuilder $builder,
        AssistantMessageEventStream $stream,
        ?array $open,
        array &$replay,
    ): ?array {
        // Every chunk of one completion carries the same id; the first one that has it is kept,
        // as upstream's `||=` does. The model, likewise, but only when it is not the one asked
        // for — a router such as OpenRouter's `auto` names the model it picked here.
        if ($builder->responseId() === null && is_string($data['id'] ?? null) && $data['id'] !== '') {
            $builder->setResponseId($data['id']);
        }

        $answered = $data['model'] ?? null;

        if ($builder->responseModel() === null && is_string($answered) && $answered !== '' && $answered !== $model->id) {
            $builder->setResponseModel($answered);
        }

        if (isset($data['usage']) && is_array($data['usage'])) {
            $builder->setUsage($this->usage($data['usage']));
        }

        $choice = $data['choices'][0] ?? null;

        if (!is_array($choice)) {
            return $open;
        }

        // Upstream's fallback: some providers (Moonshot) put the usage on the choice instead
        // of the chunk, and it is read from there only when the chunk has none of its own.
        if (!is_array($data['usage'] ?? null) && is_array($choice['usage'] ?? null)) {
            $builder->setUsage($this->usage($choice['usage']));
        }

        if (is_string($choice['finish_reason'] ?? null)) {
            $builder->setRawStopReason($choice['finish_reason']);
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

        // Upstream's comment: use the first non-empty reasoning field to avoid duplication —
        // chutes.ai sends the same text in both `reasoning_content` and `reasoning`, and reading
        // every field put it in the thinking block twice.
        foreach (self::REASONING_FIELDS as $field) {
            $thinking = $delta[$field] ?? null;

            if (is_string($thinking) && $thinking !== '') {
                // Upstream's one provider rule here: opencode-go streams `reasoning` and takes it
                // back only as `reasoning_content`.
                $signature = $model->provider === 'opencode-go' && $field === 'reasoning' ? 'reasoning_content' : $field;
                $open = $this->onThinking($thinking, $signature, $builder, $stream, $open);

                break;
            }
        }

        foreach ($delta['tool_calls'] ?? [] as $call) {
            if (is_array($call)) {
                $open = $this->onToolCall($call, $builder, $stream, $open);
            }
        }

        if (is_array($delta['reasoning_details'] ?? null)) {
            $this->onReasoningDetails($delta['reasoning_details'], $builder, $stream, $replay);
        }

        return $open;
    }

    /**
     * Upstream's `reasoning_details` arm: replay metadata, not text anybody reads.
     *
     * OpenRouter streams a reasoning model's reasoning as a list of details — `reasoning.text`,
     * `reasoning.summary`, `reasoning.encrypted` — and wants the list back on the next request, or
     * the model starts its reasoning over. Upstream keeps the whole list for the message in the
     * thinking block's signature: consecutive text and summary deltas are merged into one entry,
     * an encrypted entry stays opaque and on its own (`appendOpenAIReasoningDetail()`).
     *
     * A detail needs a thinking block to live in, so one is made if none exists — upstream's
     * `ensureThinkingBlock("")`. **pig's structural difference**: upstream has one thinking block
     * per message and ends every block when the stream ends, while pig ends a block when something
     * of another kind arrives. The details usually come beside the calls, and opening a block the
     * ordinary way would end the call they came with — so a block made here is never the open one,
     * and its end event is pushed after the stream ends, which is where upstream pushes it too.
     *
     * @param list<mixed> $details
     * @param array{details: list<array<string, mixed>>|null, detached: int|null} $replay
     */
    private function onReasoningDetails(
        array $details,
        AssistantMessageBuilder $builder,
        AssistantMessageEventStream $stream,
        array &$replay,
    ): void {
        foreach ($details as $detail) {
            if (!self::isOpenAIReasoningDetail($detail)) {
                continue;
            }

            if ($builder->firstIndexOf('thinking') === null) {
                $index = $builder->startThinking($builder->nextWire());
                $replay['detached'] = $index;
                $stream->push(new ThinkingStartEvent($index, $builder->snapshot()));
            }

            $replay['details'] ??= [];
            self::appendOpenAIReasoningDetail($replay['details'], $detail);
        }
    }

    /**
     * Upstream's `applyStreamedReasoningDetails()`: the details, as JSON, become the signature of
     * the message's thinking block — pig's first one, which is the one upstream's single block is.
     *
     * Applied when the stream ends rather than when the block ends, because pig can end a thinking
     * block (text arrived) before the details stop arriving; that block's `ThinkingEndEvent`
     * carries the field name it streamed in, and the finished message carries the details.
     *
     * @param array{details: list<array<string, mixed>>|null, detached: int|null} $replay
     */
    private static function applyStreamedReasoningDetails(AssistantMessageBuilder $builder, array $replay): void
    {
        if ($replay['details'] === null) {
            return;
        }

        $index = $builder->firstIndexOf('thinking');

        if ($index !== null) {
            $builder->setSignature($index, (string) json_encode($replay['details'], self::JSON_FLAGS));
        }
    }

    /** Upstream's `isOpenAIReasoningDetail()`, with `isReasoningDetailObject()` and the common-field check. */
    private static function isOpenAIReasoningDetail(mixed $detail): bool
    {
        // A JSON object: PHP decodes one as an array that is not a list. `{}` decodes as `[]`, a
        // list — it has no `type`, so it is not a detail either way.
        if (!is_array($detail) || array_is_list($detail)) {
            return false;
        }

        // `hasValidCommonReasoningDetailFields()`: `id` absent, null or a string; `format` absent or
        // a string (null is not undefined); `index` absent or a number.
        if (array_key_exists('id', $detail) && $detail['id'] !== null && !is_string($detail['id'])) {
            return false;
        }

        if (array_key_exists('format', $detail) && !is_string($detail['format'])) {
            return false;
        }

        if (array_key_exists('index', $detail) && !is_int($detail['index']) && !is_float($detail['index'])) {
            return false;
        }

        return match ($detail['type'] ?? null) {
            'reasoning.summary' => is_string($detail['summary'] ?? null),
            'reasoning.encrypted' => is_string($detail['data'] ?? null),
            'reasoning.text' => is_string($detail['text'] ?? null)
                && (($detail['signature'] ?? null) === null || is_string($detail['signature'])),
            default => false,
        };
    }

    /**
     * Upstream's `appendOpenAIReasoningDetail()`.
     *
     * @param list<array<string, mixed>> $details
     * @param array<string, mixed> $detail
     */
    private static function appendOpenAIReasoningDetail(array &$details, array $detail): void
    {
        $last = array_key_last($details);
        $lastType = $last === null ? null : ($details[$last]['type'] ?? null);

        if ($detail['type'] === 'reasoning.text' && $lastType === 'reasoning.text') {
            $details[$last]['text'] .= $detail['text'];

            // `lastDetail.signature ||= detail.signature`
            if (self::falsy($details[$last]['signature'] ?? null)) {
                self::assignFrom($details[$last], 'signature', $detail);
            }

            self::fillMissingCommonReasoningDetailFields($details[$last], $detail);

            return;
        }

        if ($detail['type'] === 'reasoning.summary' && $lastType === 'reasoning.summary') {
            $details[$last]['summary'] .= $detail['summary'];
            self::fillMissingCommonReasoningDetailFields($details[$last], $detail);

            return;
        }

        $details[] = $detail;
    }

    /**
     * Upstream's `fillMissingCommonReasoningDetailFields()`: `id ??=`, `format ||=`, `index ??=`.
     *
     * @param array<string, mixed> $target
     * @param array<string, mixed> $source
     */
    private static function fillMissingCommonReasoningDetailFields(array &$target, array $source): void
    {
        if (($target['id'] ?? null) === null) {
            self::assignFrom($target, 'id', $source);
        }

        if (self::falsy($target['format'] ?? null)) {
            self::assignFrom($target, 'format', $source);
        }

        if (($target['index'] ?? null) === null) {
            self::assignFrom($target, 'index', $source);
        }
    }

    /** JavaScript's falsiness for the string-or-null fields above: `"0"` is truthy there. */
    private static function falsy(mixed $value): bool
    {
        return $value === null || $value === '' || $value === false;
    }

    /**
     * `target[key] = source[key]` with JavaScript's `undefined`: a key the source does not have is
     * one the target ends up without, since `JSON.stringify` leaves an `undefined` property out.
     *
     * @param array<string, mixed> $target
     * @param array<string, mixed> $source
     */
    private static function assignFrom(array &$target, string $key, array $source): void
    {
        if (array_key_exists($key, $source)) {
            $target[$key] = $source[$key];
        } else {
            unset($target[$key]);
        }
    }

    /**
     * Upstream's `parseOpenAIReasoningDetails()`: a thinking signature that is a non-empty JSON list
     * of details, or null.
     *
     * @return list<array<string, mixed>>|null
     */
    private static function parseOpenAIReasoningDetails(?string $signature): ?array
    {
        if ($signature === null || $signature === '') {
            return null;
        }

        $parsed = json_decode($signature, true);

        if (!is_array($parsed) || !array_is_list($parsed) || $parsed === []) {
            return null;
        }

        foreach ($parsed as $detail) {
            if (!self::isOpenAIReasoningDetail($detail)) {
                return null;
            }
        }

        return $parsed;
    }

    /**
     * Upstream's `parseLegacyEncryptedReasoningDetail()`: one `reasoning.encrypted` detail with a
     * non-empty id and data, as a tool call's `thoughtSignature` held it before the details moved to
     * the thinking block. Read, never written any more — sessions saved before still carry it.
     *
     * @return array<string, mixed>|null
     */
    private static function parseLegacyEncryptedReasoningDetail(?string $signature): ?array
    {
        if ($signature === null || $signature === '') {
            return null;
        }

        $parsed = json_decode($signature, true);

        return self::isOpenAIReasoningDetail($parsed)
            && $parsed['type'] === 'reasoning.encrypted'
            && is_string($parsed['id'] ?? null)
            && $parsed['id'] !== ''
            && $parsed['data'] !== ''
            ? $parsed
            : null;
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

    /**
     * Upstream's `parseChunkUsage()`.
     *
     * @param array<string, mixed> $usage
     */
    private function usage(array $usage): Usage
    {
        // Cache reads: OpenAI and OpenRouter put them in `prompt_tokens_details.cached_tokens`,
        // DeepSeek in `prompt_cache_hit_tokens`, Kimi in a top-level `cached_tokens` — first
        // one present wins, as upstream's `??` chain. Cache writes only come from
        // OpenRouter-compatible providers, as a count separate from the reads: they are not
        // subtracted from `cached_tokens`, or a spec-compliant provider is under-reported.
        $cacheRead = (int) ($usage['prompt_tokens_details']['cached_tokens']
            ?? $usage['prompt_cache_hit_tokens']
            ?? $usage['cached_tokens']
            ?? 0);
        $cacheWrite = (int) ($usage['prompt_tokens_details']['cache_write_tokens'] ?? 0);

        // `prompt_tokens` includes both, so they are taken back out: input here means what was
        // paid for at the input rate.
        $input = max(0, (int) ($usage['prompt_tokens'] ?? 0) - $cacheRead - $cacheWrite);

        // `completion_tokens` already includes the reasoning tokens, so it is the output as it
        // stands; adding `reasoning_tokens` again counts them twice. Upstream has no provider
        // branch here — a provider that left reasoning out of `completion_tokens` would be
        // under-counted there too. `reasoning` is the split, 0 when none was reported.
        $output = (int) ($usage['completion_tokens'] ?? 0);

        return new Usage(
            $input,
            $output,
            $cacheRead,
            $cacheWrite,
            $input + $output + $cacheRead + $cacheWrite,
            reasoning: (int) ($usage['completion_tokens_details']['reasoning_tokens'] ?? 0),
        );
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

        // Upstream's two default arms, and the operators are copied rather than tidied. The set
        // arm uses `??`, so a level the map calls *null* still sends the level's own name here,
        // while upstream's zai and baseten arms drop the field for the same null. That
        // inconsistency is upstream's; it costs nothing because a null level never reaches a
        // request — `ThinkingLevel::supportedBy()` has already kept it out of the picker.
        //
        // Keyed by the **effort** and not by the thinking level, because pig converts one to the
        // other a layer above the provider (`ThinkingLevel::toReasoning()`), where upstream still
        // has the level. The only key that differs is `minimal`, which pig has already sent as
        // `low` by this point — and a `minimal` the map calls null is filtered out upstream of
        // here anyway, so nothing reachable is lost.
        if ($options?->reasoning !== null && $model->reasoning && $compat->reasoningEffort) {
            $body['reasoning_effort'] = $model->thinkingEffort($options->reasoning->value) ?? $options->reasoning->value;
        } elseif ($options?->reasoning === null && $model->reasoning && $compat->reasoningEffort) {
            // Thinking is off, and some endpoints want to be told so in their own word for it.
            // Only a string: `off => null` means this model has no way to be told, and the field
            // is left out rather than guessed at.
            $off = $model->thinkingLevelMap['off'] ?? null;

            if (is_string($off)) {
                $body['reasoning_effort'] = $off;
            }
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

        $normalizeToolCallId = fn (string $id): string => $this->normalizeToolCallId($id, $model);

        foreach (TransformMessages::apply($context->messages, $model, $normalizeToolCallId) as $message) {
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
        $thinkingBlocks = [];
        $calls = [];

        foreach ($message->content as $block) {
            if ($block instanceof TextContent && trim($block->text) !== '') {
                $text[] = Utf8::sanitize($block->text);
            } elseif ($block instanceof ThinkingContent) {
                $thinkingBlocks[] = $block;
            } elseif ($block instanceof ToolCall) {
                $calls[] = $block;
            }
        }

        // Upstream's `preservedReasoningDetails`: the details from the first thinking block whose
        // signature is a list of them — empty thinking included, since a block made only to hold
        // encrypted details has no text — or else the encrypted details older sessions filed on
        // each tool call's `thoughtSignature`.
        $signedReasoningDetails = null;

        foreach ($thinkingBlocks as $block) {
            $signedReasoningDetails = self::parseOpenAIReasoningDetails($block->thinkingSignature);

            if ($signedReasoningDetails !== null) {
                break;
            }
        }

        $legacyReasoningDetails = [];

        foreach ($calls as $call) {
            $detail = self::parseLegacyEncryptedReasoningDetail($call->thoughtSignature);

            if ($detail !== null) {
                $legacyReasoningDetails[] = $detail;
            }
        }

        $preservedReasoningDetails = $signedReasoningDetails
            ?? ($legacyReasoningDetails !== [] ? $legacyReasoningDetails : null);

        $nonEmptyThinkingBlocks = array_values(array_filter(
            $thinkingBlocks,
            static fn (ThinkingContent $block): bool => trim($block->thinking) !== '',
        ));

        // Mistral rejects a null content and every endpoint rejects an assistant turn
        // that has neither content nor calls.
        $out = ['role' => 'assistant', 'content' => $compat->assistantAfterToolResult ? '' : null];

        if ($nonEmptyThinkingBlocks !== []) {
            if ($compat->thinkingAsText) {
                // Some endpoints have no field for it, so reasoning goes back as text rather than
                // being dropped. Upstream's `requiresThinkingAsText` arm, literally: every thought
                // joined by a blank line into **one** part, in front of the text parts, with no tags
                // so the model does not learn to mimic them — and as parts, the one arm upstream
                // does not send as a plain string.
                $out['content'] = [
                    ['type' => 'text', 'text' => implode("\n\n", array_map(
                        static fn (ThinkingContent $block): string => Utf8::sanitize($block->thinking),
                        $nonEmptyThinkingBlocks,
                    ))],
                    ...array_map(static fn (string $one): array => ['type' => 'text', 'text' => $one], $text),
                ];
            } else {
                if ($text !== []) {
                    // Always a plain string — see the arm below.
                    $out['content'] = implode('', $text);
                }

                // Upstream: `reasoning_details` is the structured alternative to a raw reasoning
                // field, so the field is written only when there are none. Its name is the first
                // thinking block's signature — the field it streamed in — and only when that is one
                // of the three; every thought goes in it, joined by a newline.
                if ($preservedReasoningDetails === null) {
                    $signature = $nonEmptyThinkingBlocks[0]->thinkingSignature;

                    if ($model->provider === 'opencode-go' && $signature === 'reasoning') {
                        $signature = 'reasoning_content';
                    }

                    if ($signature !== null && $signature !== '' && in_array($signature, self::REASONING_FIELDS, true)) {
                        $out[$signature] = implode("\n", array_map(
                            static fn (ThinkingContent $block): string => $block->thinking,
                            $nonEmptyThinkingBlocks,
                        ));
                    }
                }
            }
        } elseif ($text !== []) {
            // Always a plain string, for every endpoint — upstream's comment: the array of
            // `{type: "text"}` parts is non-standard, and some models (DeepSeek V3.2 via NVIDIA
            // NIM) mirror the structure in their output, nesting it deeper every turn.
            $out['content'] = implode('', $text);
        }

        if ($calls !== []) {
            $out['tool_calls'] = array_map(
                fn (ToolCall $call): array => [
                    'id' => $this->toolId($call->id, $compat),
                    'type' => 'function',
                    // `{}` and not `[]`: an empty PHP array is both, and `arguments` is an
                    // object. Anthropic's and Google's arms guard the same thing their own way.
                    'function' => [
                        'name' => $call->name,
                        'arguments' => $call->arguments === [] ? '{}' : $this->encode($call->arguments),
                    ],
                ],
                $calls,
            );
        }

        // Sent as the objects they arrived as: a string here is rejected.
        if ($preservedReasoningDetails !== null) {
            $out['reasoning_details'] = $preservedReasoningDetails;
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
     * Upstream's `normalizeToolCallId` for this API: another model's call id, made into one
     * chat completions takes. Handed to `TransformMessages`, so this model's own ids never pass
     * through it, and a result follows its call.
     *
     * A Responses API id is `call_id|item_id`, the item part 400 characters and more with `+`, `/`
     * and `=` in it — from openai, openai-codex, opencode, or Copilot's other API. Two calls in
     * one turn can share a `call_id` and differ by item, and this API wants distinct ids, so both
     * halves are kept: sanitised, joined by `_`, and if that runs past 40 the call id's head and
     * a hash of the whole id. Anything without a `|` is left alone, except that OpenAI itself
     * gets it cut to 40.
     */
    private function normalizeToolCallId(string $id, Model $model): string
    {
        $separatorIndex = strpos($id, '|');

        if ($separatorIndex !== false) {
            $callId = (string) preg_replace('/[^a-zA-Z0-9_-]/', '_', substr($id, 0, $separatorIndex));
            $itemId = (string) preg_replace('/[^a-zA-Z0-9_-]/', '_', substr($id, $separatorIndex + 1));
            $combinedId = $itemId !== '' ? "{$callId}_{$itemId}" : $callId;

            if (strlen($combinedId) <= self::MAX_ID_LENGTH) {
                return $combinedId;
            }

            $hash = substr(ShortHash::of($id), 0, 8);
            $prefix = substr($callId, 0, max(1, self::MAX_ID_LENGTH - strlen($hash) - 1));

            return "{$prefix}_{$hash}";
        }

        if ($model->provider === 'openai') {
            return strlen($id) > self::MAX_ID_LENGTH ? substr($id, 0, self::MAX_ID_LENGTH) : $id;
        }

        return $id;
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
