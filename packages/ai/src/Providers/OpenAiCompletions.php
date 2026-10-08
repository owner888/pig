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
use Pig\Ai\Utils\ConstrainedSampling;
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
    /** Upstream's `OPENAI_PROMPT_CACHE_KEY_MAX_LENGTH`, in code points (`clampOpenAIPromptCacheKey()`). */
    private const int PROMPT_CACHE_KEY_MAX_LENGTH = 64;

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
            // Upstream's `grammarToolInputProperties`, as in `OpenAiResponses`: tool name => the
            // property a grammar tool's raw input lives in, for the tools sent as custom tools.
            $grammar = ConstrainedSampling::createGrammarToolInputProperties(
                $context->tools,
                OpenAiCompat::resolve($model)->grammarTools ?? false,
            );
            $response = $this->http->send($this->request($model, $context, $options, $grammar), $signal);

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
                        $open = $this->onChunk($data, $model, $builder, $stream, $open, $replay, $grammar);
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
        array $grammar = [],
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
                $open = $this->onToolCall($call, $builder, $stream, $open, $grammar);
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
     * One tool-call delta. A `custom` delta with no `function` is a grammar tool's call (upstream's
     * `ensureToolCallBlock()` and its `custom?.input` arm): its raw input is kept as the arguments
     * `{<property>: <input>}` — the property the tool's schema requires, or `input` for a tool this
     * request did not send as a grammar tool — and streamed as that object's JSON deltas, which
     * `close()` finishes. The custom state rides on `$open` as its fourth entry.
     *
     * @param array<string, mixed> $call
     * @param array{0: int, 1: string, 2: string, 3?: array{property: string, buffer: array{input: string, started: bool, closed: bool}}}|null $open
     * @param array<string, string> $grammar
     * @return array{0: int, 1: string, 2: string, 3?: array{property: string, buffer: array{input: string, started: bool, closed: bool}}}
     */
    private function onToolCall(
        array $call,
        AssistantMessageBuilder $builder,
        AssistantMessageEventStream $stream,
        ?array $open,
        array $grammar = [],
    ): array {
        $id = (string) ($call['id'] ?? '');
        $function = is_array($call['function'] ?? null) ? $call['function'] : null;
        $custom = is_array($call['custom'] ?? null) ? $call['custom'] : null;
        $name = (string) ($function['name'] ?? $custom['name'] ?? '');
        $isCustom = $custom !== null && $function === null;

        // A new id while a call is open means a second call, not more of the first: some
        // providers send two in one chunk.
        if ($open === null || $open[1] !== 'toolCall' || ($id !== '' && $open[2] !== '' && $open[2] !== $id)) {
            $this->close($builder, $stream, $open);
            $index = $builder->startToolCall($builder->nextWire(), $id, $name);
            $open = [$index, 'toolCall', $id];

            if ($isCustom) {
                $open[3] = ['property' => $grammar[$name] ?? 'input', 'buffer' => ConstrainedSampling::newGrammarToolInputJsonBuffer()];
                $builder->setJson($index, self::customArguments($open[3]['property'], ''));
            }

            $stream->push(new ToolCallStartEvent($index, $builder->snapshot()));
        }

        $builder->setToolCall($open[0], $id, $name);
        $open[2] = $id === '' ? $open[2] : $id;

        // Upstream: a call that started as a function call and turns out to be a custom one.
        if ($isCustom && !isset($open[3])) {
            $property = $grammar[$builder->toolCallOf($open[0])->name] ?? 'input';
            $open[3] = ['property' => $property, 'buffer' => ConstrainedSampling::newGrammarToolInputJsonBuffer()];
            $builder->setJson($open[0], self::customArguments($property, ''));
        }

        $arguments = $function['arguments'] ?? null;
        $input = $custom['input'] ?? null;

        if (is_string($arguments) && $arguments !== '') {
            $builder->append($open[0], 'json', $arguments);
            $stream->push(new ToolCallDeltaEvent($open[0], $arguments, $builder->snapshot()));
        } elseif (isset($open[3]) && is_string($input) && $input !== '') {
            $delta = $this->appendCustomInput($builder, $open, self::customInput($builder, $open) . $input, false);

            if ($delta !== null && $delta !== '') {
                $stream->push(new ToolCallDeltaEvent($open[0], $delta, $builder->snapshot()));
            }
        }

        return $open;
    }

    /**
     * Upstream's `appendCustomToolCallInput()`: the input run through the JSON buffer, the arguments
     * set to `{<property>: <input>}`, and the JSON delta to stream back, or null.
     *
     * @param array{0: int, 1: string, 2: string, 3: array{property: string, buffer: array{input: string, started: bool, closed: bool}}} $open
     */
    private function appendCustomInput(AssistantMessageBuilder $builder, array &$open, string $next, bool $close): ?string
    {
        $delta = ConstrainedSampling::appendGrammarToolInputJsonDelta($open[3]['buffer'], $open[3]['property'], $next, $close);
        $builder->setJson($open[0], self::customArguments($open[3]['property'], $next));

        return $delta;
    }

    /**
     * Upstream's `getCustomToolCallInput()`.
     *
     * @param array{0: int, 1: string, 2: string, 3: array{property: string, buffer: array{input: string, started: bool, closed: bool}}} $open
     */
    private static function customInput(AssistantMessageBuilder $builder, array $open): string
    {
        $value = $builder->toolCallOf($open[0])->arguments[$open[3]['property']] ?? null;

        return is_string($value) ? $value : '';
    }

    /** `{ [property]: input }` as the JSON the builder keeps a call's arguments in. */
    private static function customArguments(string $property, string $input): string
    {
        $object = new \stdClass();
        $object->{$property} = $input;

        return (string) json_encode($object, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /** @param array{0: int, 1: string, 2: string, 3?: array{property: string, buffer: array{input: string, started: bool, closed: bool}}}|null $open */
    private function close(AssistantMessageBuilder $builder, AssistantMessageEventStream $stream, ?array $open): void
    {
        if ($open === null) {
            return;
        }

        [$index, $kind] = $open;

        // Upstream's `finishBlock()` for a custom call: the JSON closed with the input as it stands.
        if ($kind === 'toolCall' && isset($open[3])) {
            $delta = $this->appendCustomInput($builder, $open, self::customInput($builder, $open), true);

            if ($delta !== null) {
                $stream->push(new ToolCallDeltaEvent($index, $delta, $builder->snapshot()));
            }
        }

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

    /** @param array<string, string> $grammar see `run()` */
    private function request(Model $model, Context $context, ?OpenAiOptions $options, array $grammar = []): Request
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

        // Upstream's `createClient()`: the session id, when caching is on (`cacheSessionId`), as
        // headers — but only where `sendSessionAffinityHeaders` says (detected: OpenRouter), and
        // then `x-session-id` for the `openrouter` format, otherwise `x-client-request-id` and
        // `x-session-affinity`, with `session_id` too for `openai`.
        $sessionId = ($options ?? new OpenAiOptions())->resolvedCacheRetention() === 'none' ? null : $options?->sessionId;
        $compat = OpenAiCompat::resolve($model);

        if ($sessionId !== null && $sessionId !== '' && $compat->sendSessionAffinityHeaders) {
            if ($compat->sessionAffinityFormat === 'openrouter') {
                $headers['x-session-id'] = $sessionId;
            } else {
                if ($compat->sessionAffinityFormat === 'openai') {
                    $headers['session_id'] = $sessionId;
                }

                $headers['x-client-request-id'] = $sessionId;
                $headers['x-session-affinity'] = $sessionId;
            }
        }

        return new Request(
            'POST',
            $this->endpoint($model, $options?->apiKey, '/chat/completions'),
            $headers,
            $this->encode($this->body($model, $context, $options, $grammar)),
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

    /**
     * @param array<string, string> $grammar see `run()`
     * @return array<string, mixed>
     */
    private function body(Model $model, Context $context, ?OpenAiOptions $options, array $grammar = []): array
    {
        $compat = OpenAiCompat::resolve($model);
        $cacheRetention = ($options ?? new OpenAiOptions())->resolvedCacheRetention();
        $supportsLongCacheRetention = $compat->supportsLongCacheRetention ?? true;

        $body = [
            'model' => $model->id,
            'messages' => $this->messages($model, $context, $compat, $grammar),
            'stream' => true,
        ];

        // Upstream: `prompt_cache_key` — the session id, cut to 64 code points — for OpenAI's own
        // endpoint unless caching is off, and anywhere for `long` where the endpoint takes long
        // retention; `prompt_cache_retention: "24h"` for that second case. An undefined key is left
        // out of the JSON, which is what null does here.
        if ($options?->sessionId !== null
            && ((str_contains($model->baseUrl, 'api.openai.com') && $cacheRetention !== 'none')
                || ($cacheRetention === 'long' && $supportsLongCacheRetention))) {
            $body['prompt_cache_key'] = mb_substr($options->sessionId, 0, self::PROMPT_CACHE_KEY_MAX_LENGTH);
        }

        if ($cacheRetention === 'long' && $supportsLongCacheRetention) {
            $body['prompt_cache_retention'] = '24h';
        }

        $body['stream_options'] = ['include_usage' => true];

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
            $body['tools'] = array_map(fn (Tool $tool): array => $this->tool($tool, $compat), $context->tools);
        } elseif ($this->hasToolHistory($context->messages)) {
            // A conversation holding tool calls is rejected by some proxies unless the
            // tools field is present, even with nothing in it.
            $body['tools'] = [];
        }

        // Upstream's `getCompatCacheControl()` and `applyAnthropicCacheControl()`: an endpoint that
        // takes Anthropic's `cache_control` (OpenRouter's `anthropic/…` models) gets it on the system
        // prompt, the last tool and the last conversation message with text, `ttl: "1h"` for `long`.
        if ($compat->cacheControlFormat === 'anthropic' && $cacheRetention !== 'none') {
            $cacheControl = ['type' => 'ephemeral', ...($cacheRetention === 'long' && $supportsLongCacheRetention ? ['ttl' => '1h'] : [])];
            self::applyAnthropicCacheControl($body, $cacheControl);
        }

        if ($options?->toolChoice !== null) {
            $body['tool_choice'] = $options->toolChoice;
        }

        $this->thinking($body, $model, $compat, $options?->reasoning?->value);
        $this->routing($body, $model);

        return $body;
    }

    /**
     * Upstream's `applyAnthropicCacheControl()`: `addCacheControlToSystemPrompt()` (the first
     * `system` or `developer` message), `addCacheControlToLastTool()`, then
     * `addCacheControlToLastConversationMessage()` (the last `user`, `assistant` or `tool` message
     * whose text could take it, walking back past one that could not).
     *
     * @param array<string, mixed> $body
     * @param array<string, string> $cacheControl
     */
    private static function applyAnthropicCacheControl(array &$body, array $cacheControl): void
    {
        foreach ($body['messages'] as $i => $message) {
            if ($message['role'] === 'system' || $message['role'] === 'developer') {
                self::addCacheControlToTextContent($body['messages'][$i], $cacheControl);

                break;
            }
        }

        if (isset($body['tools']) && $body['tools'] !== []) {
            $body['tools'][count($body['tools']) - 1]['cache_control'] = $cacheControl;
        }

        for ($i = count($body['messages']) - 1; $i >= 0; $i--) {
            $role = $body['messages'][$i]['role'];

            if (($role === 'user' || $role === 'assistant' || $role === 'tool')
                && self::addCacheControlToTextContent($body['messages'][$i], $cacheControl)) {
                return;
            }
        }
    }

    /**
     * Upstream's `addCacheControlToTextContent()`: a non-empty string becomes one text part carrying
     * the cache control; in a list of parts, the last text part takes it. False when there was
     * nowhere to put it — an empty string, no content, or no text part.
     *
     * @param array<string, mixed> $message
     * @param array<string, string> $cacheControl
     */
    private static function addCacheControlToTextContent(array &$message, array $cacheControl): bool
    {
        $content = $message['content'] ?? null;

        if (is_string($content)) {
            if ($content === '') {
                return false;
            }

            $message['content'] = [['type' => 'text', 'text' => $content, 'cache_control' => $cacheControl]];

            return true;
        }

        if (!is_array($content)) {
            return false;
        }

        for ($i = count($content) - 1; $i >= 0; $i--) {
            if (($content[$i]['type'] ?? null) === 'text') {
                $message['content'][$i]['cache_control'] = $cacheControl;

                return true;
            }
        }

        return false;
    }

    /**
     * Upstream's two routing blocks in `buildParams()`, after the thinking fields and read off
     * **the model's own compat**, not the resolved one — `model.compat?.openRouterRouting` — so
     * only a model that says it sends it.
     *
     * The JS test is truthiness, and an object is truthy even when empty: a `models.json` block
     * with `"openRouterRouting": {}` sends `provider: {}`, and that is kept — `!== null` here, and
     * `{}` rather than `[]` on the wire. Vercel's goes out only when `only` or `order` is there
     * (an empty list counts, as an empty JS array is truthy too), with just those two keys.
     *
     * @param array<string, mixed> $body
     */
    private function routing(array &$body, Model $model): void
    {
        $compat = $model->compat instanceof OpenAiCompat ? $model->compat : null;

        if ($compat?->openRouterRouting !== null) {
            $body['provider'] = $compat->openRouterRouting === [] ? new \stdClass() : $compat->openRouterRouting;
        }

        $routing = $compat?->vercelGatewayRouting;

        if ($routing !== null && (isset($routing['only']) || isset($routing['order']))) {
            $gateway = [];

            if (isset($routing['only'])) {
                $gateway['only'] = $routing['only'];
            }

            if (isset($routing['order'])) {
                $gateway['order'] = $routing['order'];
            }

            $body['providerOptions'] = ['gateway' => $gateway];
        }
    }

    /**
     * Upstream's `thinkingFormat` arms in `buildParams()`, one per format, in its order and with its
     * operators. `$effort` is the requested level's wire name, null when thinking is off.
     *
     * The JS operators each have one PHP spelling here, because `thinkingLevelMap` has three states
     * (see `Model::$thinkingLevelMap`):
     * - `map[e] ?? e` is `$map[$e] ?? $e` — a null entry sends the level's own name;
     * - `mapped === undefined ? e : mapped`, then `typeof === "string"`, is `Model::thinkingEffort()`
     *   then `is_string()` — a null entry sends nothing;
     * - `map?.off !== null` is "`off` is absent or a string".
     *
     * Keyed by the effort rather than the thinking level, because pig converts one to the other a
     * layer above the provider (`ThinkingLevel::toReasoning()`), where upstream still has the level.
     * The two spell every level the same, `minimal` included.
     *
     * @param array<string, mixed> $body
     */
    private function thinking(array &$body, Model $model, OpenAiCompat $compat, ?string $effort): void
    {
        $map = $model->thinkingLevelMap;
        $supportsReasoningEffort = (bool) $compat->reasoningEffort;
        $offIsNotNull = !array_key_exists('off', $map) || $map['off'] !== null;
        $budget = $this->thinkingBudget($body, $model, $effort);

        if ($compat->thinkingFormat === 'zai' && $model->reasoning) {
            $body['thinking'] = $effort !== null ? ['type' => 'enabled', 'clear_thinking' => false] : ['type' => 'disabled'];

            if ($effort !== null && $supportsReasoningEffort) {
                $mapped = $model->thinkingEffort($effort);

                if (is_string($mapped)) {
                    $body['reasoning_effort'] = $mapped;
                }
            }
        } elseif ($compat->thinkingFormat === 'qwen' && $model->reasoning) {
            $body['enable_thinking'] = $effort !== null;

            if ($effort !== null && $supportsReasoningEffort) {
                $mapped = $map[$effort] ?? $effort;

                if (is_string($mapped)) {
                    $body['reasoning_effort'] = $mapped;
                }
            }
        } elseif ($compat->thinkingFormat === 'qwen-chat-template' && $model->reasoning) {
            $body['chat_template_kwargs'] = ['enable_thinking' => $effort !== null, 'preserve_thinking' => true];
        } elseif ($compat->thinkingFormat === 'chat-template' && $model->reasoning) {
            $kwargs = self::chatTemplateValues($model, $effort, $compat->chatTemplateKwargs ?? [], $budget);

            if ($kwargs !== null) {
                $body['chat_template_kwargs'] = $kwargs;
            }
        } elseif ($compat->thinkingFormat === 'baseten' && $model->reasoning) {
            $args = self::chatTemplateValues($model, $effort, $compat->chatTemplateArgs ?? [], $budget);

            if ($args !== null) {
                $body['chat_template_args'] = $args;
            }

            if ($supportsReasoningEffort) {
                // `mapped = requested ? map[requested] : map.off`, undefined when absent; then
                // `mapped === undefined ? requested : mapped`.
                $key = $effort ?? 'off';
                $mapped = array_key_exists($key, $map) ? $map[$key] : $effort;

                if (is_string($mapped)) {
                    $body['reasoning_effort'] = $mapped;
                }
            }
        } elseif ($compat->thinkingFormat === 'deepseek' && $model->reasoning) {
            if ($effort !== null) {
                $body['thinking'] = ['type' => 'enabled'];
            } elseif ($offIsNotNull) {
                $body['thinking'] = ['type' => 'disabled'];
            }

            if ($effort !== null && $supportsReasoningEffort) {
                $body['reasoning_effort'] = $map[$effort] ?? $effort;
            }
        } elseif ($compat->thinkingFormat === 'openrouter' && $model->reasoning) {
            if ($effort !== null) {
                $body['reasoning'] = ['effort' => $map[$effort] ?? $effort];
            } elseif ($offIsNotNull) {
                $body['reasoning'] = ['effort' => $map['off'] ?? 'none'];
            }
        } elseif ($compat->thinkingFormat === 'ant-ling' && $model->reasoning && $effort !== null) {
            $mapped = $map[$effort] ?? null;

            if (is_string($mapped)) {
                $body['reasoning'] = ['effort' => $mapped];
            }
        } elseif ($compat->thinkingFormat === 'together' && $model->reasoning) {
            $body['reasoning'] = ['enabled' => $effort !== null];

            if ($effort !== null && $supportsReasoningEffort) {
                $body['reasoning_effort'] = $map[$effort] ?? $effort;
            }
        } elseif ($compat->thinkingFormat === 'string-thinking' && $model->reasoning) {
            if ($effort !== null) {
                $body['thinking'] = $map[$effort] ?? $effort;
            } elseif ($offIsNotNull) {
                $body['thinking'] = $map['off'] ?? 'none';
            }
        } elseif ($effort !== null && $model->reasoning && $supportsReasoningEffort) {
            // OpenAI-style `reasoning_effort` — upstream's two default arms. A `minimal` the map
            // calls null is filtered out upstream of here (`ThinkingLevel::supportedBy()`).
            $body['reasoning_effort'] = $map[$effort] ?? $effort;
        } elseif ($effort === null && $model->reasoning && $supportsReasoningEffort) {
            // Thinking is off, and some endpoints want to be told so in their own word for it.
            // Only a string: `off => null` means this model has no way to be told.
            $off = $map['off'] ?? null;

            if (is_string($off)) {
                $body['reasoning_effort'] = $off;
            }
        }
    }

    /**
     * Upstream's `resolveClampedThinkingBudget()`: the default budget for the level (upstream's
     * `DEFAULT_THINKING_BUDGETS`, `xhigh` clamped to `high`), cut so 1,024 tokens of the response
     * ceiling are left for the answer, or null when that leaves nothing or thinking is off.
     * pig's `OpenAiOptions` carries no `thinkingBudgets` of the caller's own, so the defaults are
     * all there is. Only `{"$var": "thinking.budget"}` reads it — pig does not port upstream's
     * separate `thinkingTokenBudgetField`.
     *
     * @param array<string, mixed> $body
     */
    private function thinkingBudget(array $body, Model $model, ?string $effort): ?int
    {
        if ($effort === null || !$model->reasoning) {
            return null;
        }

        $ceiling = $body['max_tokens'] ?? $body['max_completion_tokens'] ?? $model->maxTokens;
        $level = in_array($effort, ['xhigh', 'max'], true) ? 'high' : $effort;
        $budget = min(
            ['minimal' => 1024, 'low' => 2048, 'medium' => 8192, 'high' => 16384][$level] ?? 16384,
            max(0, $ceiling - 1024),
        );

        return $budget > 0 ? $budget : null;
    }

    /**
     * Upstream's `buildChatTemplateValues()` with `resolveChatTemplateKwargValue()`: a plain value
     * goes as it is (null included); a `{"$var": …}` is filled in, and left out when it resolves to
     * upstream's `undefined`. Null when nothing is left.
     *
     * @param array<string, mixed> $values
     * @return array<string, mixed>|null
     */
    private static function chatTemplateValues(Model $model, ?string $effort, array $values, ?int $budget): ?array
    {
        $resolved = [];

        foreach ($values as $key => $value) {
            // `typeof value !== "object" || value === null`: a plain value. An empty object read from
            // `models.json` arrives as `stdClass` (`CustomModels` keeps `{}` as `{}`), and is an
            // object to upstream like any other — one with no `$var`.
            if ($value instanceof \stdClass) {
                $value = (array) $value;
            }

            if (!is_array($value)) {
                $resolved[$key] = $value;

                continue;
            }

            if ($effort === null && ($value['omitWhenOff'] ?? false) === true) {
                continue;
            }

            $variable = $value['$var'] ?? null;

            if ($variable === 'thinking.enabled') {
                $resolved[$key] = $effort !== null;
            } elseif ($variable === 'thinking.budget') {
                if ($budget !== null) {
                    $resolved[$key] = $budget;
                }
            } else {
                // `mapped = effort ? map[effort] : map.off`; undefined gives the effort (itself
                // undefined when off), a string gives the string, null gives nothing.
                $mapKey = $effort ?? 'off';
                $mapped = array_key_exists($mapKey, $model->thinkingLevelMap) ? $model->thinkingLevelMap[$mapKey] : $effort;

                if (is_string($mapped)) {
                    $resolved[$key] = $mapped;
                }
            }
        }

        return $resolved !== [] ? $resolved : null;
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

    /**
     * Upstream's `convertTools()`. A grammar tool the endpoint takes (`supportsOpenAIGrammarTools`,
     * which nothing turns on for this API by default) goes out as `{type: "custom", custom: {name,
     * description, format: {type: "grammar", grammar: {syntax, definition}}}}`.
     *
     * `compat.supportsStrictMode !== false` decides both whether a `constrainedSampling` tool can go
     * strict and whether `strict` is sent at all — "only include strict if provider supports it.
     * Some reject unknown fields." Where it is sent it is `strict ?? false`, so every tool of such
     * a provider carries the field, strict or not.
     *
     * @return array<string, mixed>
     */
    private function tool(Tool $tool, OpenAiCompat $compat): array
    {
        $grammar = ConstrainedSampling::resolveGrammarConstrainedSampling($tool, $compat->grammarTools ?? false);

        if ($grammar !== null) {
            return [
                'type' => 'custom',
                'custom' => [
                    'name' => $tool->name,
                    'description' => $tool->description,
                    'format' => [
                        'type' => 'grammar',
                        'grammar' => ['syntax' => $grammar['format'], 'definition' => $grammar['definition']],
                    ],
                ],
            ];
        }

        $supportsStrictMode = $compat->strictMode !== false;
        $strict = ConstrainedSampling::resolveJsonSchemaStrictSampling($tool, $supportsStrictMode);
        $function = [
            'name' => $tool->name,
            'description' => $tool->description,
            'parameters' => ConstrainedSampling::getJsonSchemaToolParameters($tool, $strict),
        ];

        if ($supportsStrictMode) {
            $function['strict'] = $strict ?? false;
        }

        return ['type' => 'function', 'function' => $function];
    }

    /**
     * @param array<string, string> $grammar
     * @return list<array<string, mixed>>
     */
    private function messages(Model $model, Context $context, OpenAiCompat $compat, array $grammar = []): array
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

            foreach ($this->convert($message, $model, $compat, $grammar) as $converted) {
                $out[] = $converted;
            }

            $previous = $message;
        }

        return $out;
    }

    /**
     * @param array<string, string> $grammar
     * @return list<array<string, mixed>>
     */
    private function convert(mixed $message, Model $model, OpenAiCompat $compat, array $grammar = []): array
    {
        return match (true) {
            $message instanceof UserMessage => $this->user($message, $model),
            $message instanceof AssistantMessage => $this->assistant($message, $model, $compat, $grammar),
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

        // An empty turn is rejected outright. (An image the model cannot see no longer empties
        // a turn: `TransformMessages` has already put a placeholder line in its place.)
        return $parts === [] ? [] : [['role' => 'user', 'content' => $parts]];
    }

    /**
     * @param array<string, string> $grammar
     * @return list<array<string, mixed>>
     */
    private function assistant(AssistantMessage $message, Model $model, OpenAiCompat $compat, array $grammar = []): array
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
                // A grammar tool's call goes back as the custom call it was — upstream's
                // `grammarToolInputProperties.get(tc.name)` arm — with its raw input.
                fn (ToolCall $call): array => isset($grammar[$call->name])
                    ? [
                        'id' => $this->toolId($call->id, $compat),
                        'type' => 'custom',
                        'custom' => [
                            'name' => $call->name,
                            'input' => Utf8::sanitize(ConstrainedSampling::getGrammarToolInput($call->name, $call->arguments, $grammar[$call->name])),
                        ],
                    ]
                    : [
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

        // Upstream's `requiresReasoningContentOnAssistantMessages` arm, in its place — after the
        // reasoning field and the details, before the empty-turn check (which looks only at
        // content and calls, so this alone never keeps a turn). `model.reasoning` is the model's
        // capability, not whether this request thinks: upstream reads it that way. A turn that
        // already carries `reasoning_content` — its own thinking, written above — keeps it.
        if ($compat->reasoningContentOnAssistantMessages && $model->reasoning && !array_key_exists('reasoning_content', $out)) {
            $out['reasoning_content'] = '';
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

        // Upstream: `hasText ? textResult : hasImages ? "(see attached image)" : "(no tool output)"`,
        // where `hasText` is the *joined* text being non-empty — so a result whose only text block
        // is "" counts as having none. pig said "(see attached image)" for every result without
        // text, which told the model to look at an image that was not there.
        $joined = implode("\n", $text);

        $result = [
            'role' => 'tool',
            'content' => Utf8::sanitize($joined !== '' ? $joined : ($images !== [] ? '(see attached image)' : '(no tool output)')),
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
