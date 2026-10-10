<?php

declare(strict_types=1);

namespace Pig\Agent;

use Pig\Ai\AnthropicCompat;
use Pig\Ai\DoneEvent;
use Pig\Ai\ErrorEvent;
use Pig\Ai\Http\HttpClient;
use Pig\Ai\Http\Request;
use Pig\Ai\Model;
use Pig\Ai\Pricing;
use Pig\Ai\PricingTier;
use Pig\Ai\Providers\AssistantMessageBuilder;
use Pig\Ai\SimpleStreamOptions;
use Pig\Ai\StartEvent;
use Pig\Ai\StopReason;
use Pig\Ai\TextDeltaEvent;
use Pig\Ai\TextEndEvent;
use Pig\Ai\TextStartEvent;
use Pig\Ai\ThinkingDeltaEvent;
use Pig\Ai\ThinkingEndEvent;
use Pig\Ai\ThinkingStartEvent;
use Pig\Ai\ToolCall;
use Pig\Ai\ToolCallDeltaEvent;
use Pig\Ai\ToolCallEndEvent;
use Pig\Ai\ToolCallStartEvent;
use Pig\Ai\TranscriptContext;
use Pig\Ai\Usage;
use Pig\Ai\Utils\AssistantMessageEventStream;
use Pig\Ai\Utils\JsJson;
use Pig\Ai\Utils\MessageJson;
use Pig\Ai\Utils\TextDecoder;
use Pig\Async\AbortError;
use Pig\Async\AbortSignal;
use Pig\Async\Async;
use Throwable;

/**
 * Upstream's `agent/proxy.ts`: talk to a gateway server instead of to a provider.
 *
 * The server holds the provider keys and makes the call; pig posts the whole request to it and
 * reads events back. That is the opposite trade from `Ai\Http\Proxy`, which is the other thing
 * called a proxy in this repository and does the opposite: it carries pig's own encrypted bytes to
 * the provider and cannot read them. **This one hands the conversation and the system prompt to
 * whoever runs the gateway.** For a team that does not want keys on laptops that is the point; for
 * anybody else it is a reason not to use it, and the choice belongs to the person deploying it, so
 * nothing here turns it on — it is a `streamFn`, passed in:
 *
 * ```php
 * $proxy = new StreamProxy('https://genai.example.com', $token);
 * $agent = new Agent(new AgentOptions(streamFn: $proxy->stream(...)));
 * ```
 *
 * The wire shape is upstream's exactly, so a gateway written for pi serves pig unchanged:
 * `POST {proxyUrl}/api/stream`, `Authorization: Bearer …`, and a body of
 * `{model, context: {messages}, options}` — the context as upstream's normalised transcript, whose
 * first message is the system prompt and the tools. That is why the request is built from
 * `Ai\Utils\MessageJson` — the same encoder the session file uses, because upstream sends the same
 * objects to both and a second hand-written notion of a message would drift from it.
 *
 * `model` goes over the wire whole, `baseUrl` and all, because upstream's server reads the
 * provider and api off it to decide who to call. A gateway is therefore as trusted as the provider
 * would be: it is told which endpoint pig thinks it is talking to.
 *
 * Not ported: upstream's `validateToolCall(tools, call)` has no caller there either, and its
 * `ProxyAssistantMessageEvent` union is a TypeScript type — the event classes in `pig/ai` are the
 * counterpart, and `Ai\Utils\JsonSchema` is where a shape gets checked.
 */
final class StreamProxy
{
    /** Upstream's path, and not configurable there either. */
    private const string PATH = '/api/stream';

    public function __construct(
        private readonly string $proxyUrl,
        private readonly string $authToken,
        private readonly HttpClient $http = new HttpClient(),
    ) {
    }

    /**
     * Returns at once; the response fills in as it arrives.
     *
     * The signature is `AgentOptions::$streamFn`'s, so `$proxy->stream(...)` is the whole of the
     * wiring. A provider's own `stream()` looks the same on purpose.
     */
    public function stream(Model $model, TranscriptContext $context, ?SimpleStreamOptions $options = null): AssistantMessageEventStream
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
        TranscriptContext $context,
        ?SimpleStreamOptions $options,
    ): void {
        // Upstream reaches into `pi-ai/dist/utils/json-parse.js` here with a comment saying it is
        // an internal import. This is the same reach: `AssistantMessageBuilder` is `@internal` to
        // `pig/ai`, and writing a second accumulator would be a second answer to "what does a
        // half-finished tool call look like".
        $builder = new AssistantMessageBuilder($model);
        // Upstream's partial starts at `stopReason: "pending"`; only `done` or `error` replace it.
        $builder->setStopReason(StopReason::Pending);
        $signal = $options?->signal;
        $reading = false;

        try {
            $response = $this->http->send($this->request($model, $context, $options), $signal);

            if (!$response->isSuccessful()) {
                throw new AgentError($this->explain($response->status, $response->reason, $response->body->all()));
            }

            $stream->push(new StartEvent($builder->snapshot()));
            $reading = true;

            $finished = false;

            foreach ($this->lines($response->body, $signal) as $line) {
                if ($this->dispatch($line, $builder, $stream)) {
                    $finished = true;

                    // `done` and `error` both end the stream, and pushing onto an ended stream is
                    // not a thing to try. A gateway with anything to say after them is finished
                    // saying it.
                    break;
                }
            }

            // `if (options.signal?.aborted) throw new Error("Request aborted by user")` — the reader
            // was cancelled with that reason, so its last read came back done.
            if ($signal?->aborted() ?? false) {
                throw new AgentError('Request aborted by user');
            }

            // A gateway that stopped without a `done` left a turn half-finished. Upstream's words:
            // "A clean EOF without a done/error event means the server dropped the response
            // mid-stream. Surface it as an error instead of leaving consumers waiting on a result
            // that never arrives."
            if (!$finished) {
                throw new AgentError('Connection closed by proxy server before the response completed');
            }

            $message = $builder->snapshot();
            $stream->push(new DoneEvent($message->stopReason, $message));
            $stream->end();
        } catch (Throwable $error) {
            // Upstream's catch, and every provider's here: a stream function never throws at its
            // caller, the failure is the stream's result.
            // An abort before the response is the fetch's own rejection, the DOMException "This
            // operation was aborted"; one while the body is read is "Request aborted by user".
            $builder->fail(
                $error instanceof AbortError ? ($reading ? 'Request aborted by user' : 'This operation was aborted') : $error->getMessage(),
                $signal?->aborted() ?? false,
            );
            $failed = $builder->snapshot();
            $stream->push(new ErrorEvent($failed->stopReason, $failed));
            $stream->end();
        }
    }

    /**
     * The request, in upstream's shape.
     *
     * `options` is upstream's `buildProxyRequestOptions()`, the fields of it pig's options have, in
     * its order — `temperature`, `maxTokens`, `reasoning` (the enum's own string, upstream's word for
     * the same level), `cacheRetention`, `sessionId`, `headers` (nulls and all: a null deletes a
     * header on the server's side), `metadata`, `transport`, `thinkingBudgets` and
     * `maxRetryDelayMs` — each left out when unset, as `JSON.stringify` leaves out an `undefined`.
     *
     * `samplingParams` is not sent: no model in pig carries any.
     *
     * The request's own headers are upstream's two, `Authorization` and `Content-Type`; it sends no
     * `Accept`.
     *
     * `JSON_INVALID_UTF8_SUBSTITUTE` because a conversation holds whatever the tools read, and
     * `read` and `bash` hand back a file's own bytes. Without it one latin-1 log made
     * `json_encode` answer false and this threw — **and then threw on every later turn too**,
     * because the result stays in the conversation and compacting it away needs a model call
     * through here. The five other places a conversation is encoded all had an answer to this
     * already: the four providers sanitise each text block, and `SessionManager` and `RpcMode`
     * pass this same flag, the latter with the reason written beside it.
     *
     * The flag rather than `Utf8::sanitize()` per field, which is the providers' answer: this
     * encodes the whole request in one call, so a flag cannot miss a field somebody adds later.
     * The `=== false` guard stays for what the flag does not cover — a recursive structure, or
     * a float that is not a number.
     */
    private function request(Model $model, TranscriptContext $context, ?SimpleStreamOptions $options): Request
    {
        $body = json_encode([
            'model' => self::encodeModel($model),
            'context' => self::encodeContext($context),
            'options' => (object) array_filter([
                'temperature' => $options?->temperature,
                'maxTokens' => $options?->maxTokens,
                'reasoning' => $options?->reasoning?->value,
                'cacheRetention' => $options?->cacheRetention,
                'sessionId' => $options?->sessionId,
                'headers' => $options?->headers,
                'metadata' => $options?->metadata,
                'transport' => $options?->transport,
                'thinkingBudgets' => $options?->thinkingBudgets,
                'maxRetryDelayMs' => $options?->maxRetryDelayMs,
            ], static fn (mixed $value): bool => $value !== null),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        if ($body === false) {
            throw new AgentError('The conversation could not be encoded for the proxy');
        }

        return new Request(
            'POST',
            rtrim($this->proxyUrl, '/') . self::PATH,
            [
                'Authorization' => 'Bearer ' . $this->authToken,
                'Content-Type' => 'application/json',
            ],
            $body,
        );
    }

    /**
     * The model, in upstream's `Model` shape.
     *
     * **`cost`, not `pricing`** — pig renamed the field and the wire keeps upstream's name, because
     * the server reading this was written against upstream's interface.
     *
     * `headers` goes too, when the model has any, and that is worth knowing before using this: a
     * custom provider declared in `models.json` with an extra auth header hands that header to the
     * gateway. Upstream sends the model object whole and so does this; a gateway is trusted with
     * the conversation already, and a model whose headers pig withheld would fail at the gateway
     * for a reason nobody could see. `compat` goes for the same reason — without it the server
     * re-detects it from the URL and a deliberate override would silently stop applying. `input`,
 * `contextWindow` and `maxTokens` are sent as upstream orders them, not because order matters to
 * JSON but because a diff against `types.ts` is how the next person checks this.
     *
     * `thinkingLevelMap` goes when the model has one — nulls and all, since a null is "this level
     * does not exist" and the server clamps by it — `cost.tiers` when the price has tiers, and
     * `inputLimits` and `promptCache` when the model has them, under `BaseModel`'s and `Model`'s
     * positions in `types.ts`.
     *
     * @return array<string, mixed>
     */
    private static function encodeModel(Model $model): array
    {
        $encoded = [
            'id' => $model->id,
            'name' => $model->name,
            'api' => $model->api->value,
            'provider' => $model->provider,
            'baseUrl' => $model->baseUrl,
            'reasoning' => $model->reasoning,
            ...($model->thinkingLevelMap === [] ? [] : ['thinkingLevelMap' => $model->thinkingLevelMap]),
            'input' => $model->input,
            ...($model->inputLimits === null ? [] : ['inputLimits' => $model->inputLimits]),
            'cost' => self::encodeCost($model->pricing),
            ...($model->promptCache === null ? [] : ['promptCache' => $model->promptCache]),
            'contextWindow' => $model->contextWindow,
            'maxTokens' => $model->maxTokens,
        ];

        if ($model->headers !== []) {
            $encoded['headers'] = $model->headers;
        }

        if ($model->compat instanceof AnthropicCompat) {
            // Upstream's `AnthropicMessagesCompat` names, again only the keys the model says.
            $encoded['compat'] = array_filter([
                'forceAdaptiveThinking' => $model->compat->forceAdaptiveThinking,
                'supportsStrictTools' => $model->compat->strictTools,
                'supportsTemperature' => $model->compat->supportsTemperature,
                'supportsEagerToolInputStreaming' => $model->compat->supportsEagerToolInputStreaming,
                'supportsMidConvoEffort' => $model->compat->supportsMidConvoEffort,
                'supportsLongCacheRetention' => $model->compat->supportsLongCacheRetention,
                'sendSessionAffinityHeaders' => $model->compat->sendSessionAffinityHeaders,
                'sessionAffinityFormat' => $model->compat->sessionAffinityFormat,
                'supportsCacheControlOnTools' => $model->compat->supportsCacheControlOnTools,
                'allowEmptySignature' => $model->compat->allowEmptySignature,
                'allowedFallbackModels' => $model->compat->allowedFallbackModels === null ? null : array_map(
                    static fn (array $fallback): array => [
                        'provider' => $fallback['provider'],
                        'model' => $fallback['model'],
                        'cost' => self::encodeCost($fallback['cost']),
                    ],
                    $model->compat->allowedFallbackModels,
                ),
                'supportsMidConvoSystemMessages' => $model->compat->supportsMidConvoSystemMessages,
                'supportsMidConvoToolChanges' => $model->compat->supportsMidConvoToolChanges,
            ], static fn (mixed $value): bool => $value !== null);
        } elseif ($model->compat !== null) {
            // Upstream's key names, one per pig field, and
            // `CustomModels` reads and writes the same names, so a `models.json`, a session file
            // and this request all say the same thing. **Only the keys the model says**: upstream
            // sends `model.compat` as it is, and a key it leaves undefined is absent from the
            // JSON, so the server's `getCompat()` detects that one for itself. Writing a null —
            // or a default — in its place would be pig deciding what upstream leaves to detection.
            $encoded['compat'] = array_filter([
                'supportsStore' => $model->compat->store,
                'supportsDeveloperRole' => $model->compat->developerRole,
                'supportsReasoningEffort' => $model->compat->reasoningEffort,
                'maxTokensField' => $model->compat->maxTokensField,
                'requiresToolResultName' => $model->compat->toolResultName,
                'requiresAssistantAfterToolResult' => $model->compat->assistantAfterToolResult,
                'requiresThinkingAsText' => $model->compat->thinkingAsText,
                'requiresReasoningContentOnAssistantMessages' => $model->compat->reasoningContentOnAssistantMessages,
                'supportsStrictMode' => $model->compat->strictMode,
                'thinkingFormat' => $model->compat->thinkingFormat,
                // `{}` and not `[]` when empty: the server reads an object.
                'chatTemplateKwargs' => $model->compat->chatTemplateKwargs === [] ? new \stdClass() : $model->compat->chatTemplateKwargs,
                'chatTemplateArgs' => $model->compat->chatTemplateArgs === [] ? new \stdClass() : $model->compat->chatTemplateArgs,
                'openRouterRouting' => $model->compat->openRouterRouting === [] ? new \stdClass() : $model->compat->openRouterRouting,
                'vercelGatewayRouting' => $model->compat->vercelGatewayRouting === [] ? new \stdClass() : $model->compat->vercelGatewayRouting,
                'supportsOpenAIGrammarTools' => $model->compat->grammarTools,
                // The Responses keys, under `OpenAIResponsesCompat`'s names.
                'sessionAffinityFormat' => $model->compat->sessionAffinityFormat,
                'supportsLongCacheRetention' => $model->compat->supportsLongCacheRetention,
                'supportsExplicitPromptCacheMode' => $model->compat->supportsExplicitPromptCacheMode,
                'supportsMaxOutputTokens' => $model->compat->supportsMaxOutputTokens,
                'sendSessionAffinityHeaders' => $model->compat->sendSessionAffinityHeaders,
                'cacheControlFormat' => $model->compat->cacheControlFormat,
                'supportsUsageInStreaming' => $model->compat->supportsUsageInStreaming,
                'supportsFinishReason' => $model->compat->supportsFinishReason,
                'zaiToolStream' => $model->compat->zaiToolStream,
                'thinkingTokenBudgetField' => $model->compat->thinkingTokenBudgetField,
                'supportsThinkingTokenBudget' => $model->compat->supportsThinkingTokenBudget,
                'vllmPriority' => $model->compat->vllmPriority,
                'supportsMidConvoSystemMessages' => $model->compat->supportsMidConvoSystemMessages,
                'supportsMidConvoToolAdditions' => $model->compat->supportsMidConvoToolAdditions,
                'supportsToolSearch' => $model->compat->supportsToolSearch,
                'supportsAdditionalTools' => $model->compat->supportsAdditionalTools,
            ], static fn (mixed $value): bool => $value !== null);
        }

        return $encoded;
    }

    /**
     * Upstream's `ModelCost`: the four rates, and `tiers` only when there are any.
     *
     * @return array<string, mixed>
     */
    private static function encodeCost(Pricing $pricing): array
    {
        return [
            'input' => $pricing->input,
            'output' => $pricing->output,
            'cacheRead' => $pricing->cacheRead,
            'cacheWrite' => $pricing->cacheWrite,
            ...($pricing->tiers === [] ? [] : ['tiers' => array_map(static fn (PricingTier $tier): array => [
                'inputTokensAbove' => $tier->inputTokensAbove,
                'input' => $tier->input,
                'output' => $tier->output,
                'cacheRead' => $tier->cacheRead,
                'cacheWrite' => $tier->cacheWrite,
            ], $pricing->tiers)]),
        ];
    }

    /**
     * The context as upstream sends it: the `TranscriptContext` itself, `{messages}`, whose system
     * messages — the leading one with the prompt and `toolsAdded`, and any later ones that change
     * them — go as the session file writes them (`MessageJson`). A tool is upstream's `Tool`: name,
     * description, parameters, and `constrainedSampling` when it has one.
     *
     * @return array<string, mixed>
     */
    private static function encodeContext(TranscriptContext $context): array
    {
        return ['messages' => array_values(array_filter(array_map(
            MessageJson::encode(...),
            $context->messages,
        ), static fn (?array $message): bool => $message !== null))];
    }

    /**
     * The `data:` payloads, one per line.
     *
     * **Not `SseParser`, deliberately** — and the reason is not that upstream does it this way.
     * Splitting on `\n` and treating each `data:` line as one whole event is an *assumption*, and a
     * sound one: `JSON.stringify` never emits a newline, so an event is always exactly one line.
     * Since a blank line does not start with `data:`, this reader also handles a properly framed
     * stream — it is the superset. A strict SSE parser is not: it waits for the blank line and
     * joins consecutive `data:` lines into one event, so against a gateway that sends events back
     * to back it stalls or glues two together. The gateway's source is not in either repository, so
     * which of the two wires it sends cannot be checked, and only one of the two readers is right
     * either way.
     *
     * **A line is an event only when it starts with `data: `, space included** — upstream's
     * `processLine()`: `if (!line.startsWith("data: ")) return; const data = line.slice(6).trim()`.
     * pig used to take `data:{…}` without the space too, which upstream passes over; a gateway that
     * wrote that would have worked here and ended "before the response completed" in pi, so the
     * two disagreed about the same server. They agree now.
     *
     * @param iterable<string> $body
     * @return iterable<string>
     */
    private function lines(iterable $body, ?AbortSignal $signal): iterable
    {
        $buffer = '';
        // `decoder.decode(value, {stream: true})`: a leading byte-order mark dropped, a character cut
        // between reads held back, malformed bytes U+FFFD.
        $decoder = new TextDecoder();

        try {
            foreach ($body as $chunk) {
                // "if (options.signal?.aborted) throw new Error("Request aborted by user")", after
                // each read.
                if ($signal?->aborted() ?? false) {
                    throw new AgentError('Request aborted by user');
                }

                $buffer .= $decoder->decode($chunk);
                $pieces = explode("\n", $buffer);
                $buffer = (string) array_pop($pieces);

                foreach ($pieces as $line) {
                    $data = self::payload($line);

                    if ($data !== null) {
                        yield $data;
                    }
                }
            }
        } catch (AbortError) {
            // The abort handler cancels the reader, so the pending read comes back done rather
            // than failing; the check after the loop says why.
            return;
        }

        // The last line, for a server that ends without a newline: `buffer += decoder.decode()`.
        $buffer .= $decoder->decode('', false);
        $data = self::payload($buffer);

        if ($data !== null) {
            yield $data;
        }
    }

    /**
     * One `data: ` line's payload, or null for anything else — `line.slice(6).trim()`, JavaScript's
     * trim, so a `\r` from a server writing CRLF goes with it.
     */
    private static function payload(string $line): ?string
    {
        if (!str_starts_with($line, 'data: ')) {
            return null;
        }

        $data = JsJson::trim(substr($line, 6));

        return $data === '' ? null : $data;
    }

    /** @return bool true for `done` or `error`, the two that end the stream */
    private function dispatch(string $data, AssistantMessageBuilder $builder, AssistantMessageEventStream $stream): bool
    {
        // Upstream's `JSON.parse(data)`, which throws on a payload that is not JSON and so ends the
        // turn through the catch with V8's `SyntaxError` text (`JsJson`). A `: keep-alive` comment
        // and a blank `data: ` never get here (`payload()` drops them), so what is left is a broken
        // event.
        $event = JsJson::parse($data);

        if (!is_array($event)) {
            $event = [];
        }

        $wire = isset($event['contentIndex']) ? (int) $event['contentIndex'] : -1;
        $delta = (string) ($event['delta'] ?? '');
        $type = $event['type'] ?? null;

        // `done` and `error` first, because they are the two that answer true.
        if ($type === 'done') {
            $this->done($builder, $stream, $event);

            return true;
        }

        if ($type === 'error') {
            $this->failed($builder, $stream, $event);

            return true;
        }

        match ($type) {
            'start' => $stream->push(new StartEvent($builder->snapshot())),
            'text_start' => $stream->push(new TextStartEvent(
                $builder->startText($wire),
                $builder->snapshot(),
            )),
            'thinking_start' => $stream->push(new ThinkingStartEvent(
                $builder->startThinking($wire),
                $builder->snapshot(),
            )),
            'toolcall_start' => $stream->push(new ToolCallStartEvent(
                $builder->startToolCall($wire, (string) ($event['id'] ?? ''), (string) ($event['toolName'] ?? '')),
                $builder->snapshot(),
            )),
            // Thinking accumulates in the same field as text; only the block's type tells them
            // apart, which is `AssistantMessageBuilder`'s rule and not this file's invention.
            'text_delta' => $this->append($builder, $stream, $wire, 'text', $delta, 'text_delta'),
            'thinking_delta' => $this->append($builder, $stream, $wire, 'text', $delta, 'thinking_delta'),
            'toolcall_delta' => $this->append($builder, $stream, $wire, 'json', $delta, 'toolcall_delta'),
            'text_end' => $this->end($builder, $stream, $wire, 'text_end', $event),
            'thinking_end' => $this->end($builder, $stream, $wire, 'thinking_end', $event),
            'toolcall_end' => $this->toolCallEnd($builder, $stream, $wire, $event),
            // Upstream warns to the console for an unknown type. There is no console to warn to
            // during a turn — it would land in the middle of the drawn screen — and an event this
            // does not know is one it has nothing to do about.
            default => null,
        };

        return false;
    }

    /**
     * A delta against a block the gateway opened.
     *
     * Upstream throws `Received text_delta for non-text content` (and the same for thinking and
     * tool calls) when the block at that index is missing or of another kind, and that throw ends
     * the turn through its catch. pig used to say `The proxy sent … for a block it never opened`,
     * and wrote a delta into a block of the wrong kind.
     */
    private function append(
        AssistantMessageBuilder $builder,
        AssistantMessageEventStream $stream,
        int $wire,
        string $field,
        string $delta,
        string $type,
    ): void {
        $index = $builder->indexOf($wire);

        if ($index === null || !self::isKind($builder, $index, $type)) {
            throw new AgentError(self::wrongKind($type));
        }

        $builder->append($index, $field, $delta);

        $stream->push(match ($type) {
            'thinking_delta' => new ThinkingDeltaEvent($index, $delta, $builder->snapshot()),
            'toolcall_delta' => new ToolCallDeltaEvent($index, $delta, $builder->snapshot()),
            default => new TextDeltaEvent($index, $delta, $builder->snapshot()),
        });
    }

    /** @param array<string, mixed> $event */
    private function end(
        AssistantMessageBuilder $builder,
        AssistantMessageEventStream $stream,
        int $wire,
        string $type,
        array $event,
    ): void {
        $index = $builder->indexOf($wire);

        // Upstream: `Received text_end for non-text content`, `Received thinking_end for
        // non-thinking content`.
        if ($index === null || !self::isKind($builder, $index, $type)) {
            throw new AgentError(self::wrongKind($type));
        }

        // One field for both, as upstream has it: `content.textSignature = proxyEvent.contentSignature`
        // (or `thinkingSignature`) — assigned whatever it is, so an event without one leaves none.
        $builder->setSignature($index, is_string($event['contentSignature'] ?? null) ? $event['contentSignature'] : '');

        $stream->push(match ($type) {
            'thinking_end' => new ThinkingEndEvent($index, $builder->textOf($index), $builder->snapshot()),
            'toolcall_end' => new ToolCallEndEvent($index, $builder->toolCallOf($index), $builder->snapshot()),
            default => new TextEndEvent($index, $builder->textOf($index), $builder->snapshot()),
        });
    }

    /** Whether the block at `$index` is the kind an event of `$type` is for. */
    private static function isKind(AssistantMessageBuilder $builder, int $index, string $type): bool
    {
        $block = $builder->snapshot()->content[$index] ?? null;

        return match ($type) {
            'text_delta', 'text_end' => $block instanceof \Pig\Ai\TextContent,
            'thinking_delta', 'thinking_end' => $block instanceof \Pig\Ai\ThinkingContent,
            default => $block instanceof ToolCall,
        };
    }

    /** Upstream's words for an event against a block of another kind. */
    private static function wrongKind(string $type): string
    {
        return match ($type) {
            'text_delta' => 'Received text_delta for non-text content',
            'text_end' => 'Received text_end for non-text content',
            'thinking_delta' => 'Received thinking_delta for non-thinking content',
            'thinking_end' => 'Received thinking_end for non-thinking content',
            default => 'Received toolcall_delta for non-toolCall content',
        };
    }

    /**
     * Upstream's `toolcall_end` arm: `Object.assign(content, proxyEvent.toolCall)` — the server's
     * finished call (id, name, arguments, and whatever else a `ToolCall` carries) over what the
     * deltas built, scratch JSON dropped — and **nothing at all, not a throw**, when the block at
     * that index is not a tool call: `return undefined`, where the text and thinking arms throw.
     * pig used to close the call with only the deltas' arguments and ignore the event's call.
     *
     * @param array<string, mixed> $event
     */
    private function toolCallEnd(AssistantMessageBuilder $builder, AssistantMessageEventStream $stream, int $wire, array $event): void
    {
        $index = $builder->indexOf($wire);

        if ($index === null || $builder->snapshot()->content[$index] instanceof ToolCall === false) {
            return;
        }

        $call = is_array($event['toolCall'] ?? null) ? $event['toolCall'] : [];
        $builder->setToolCall(
            $index,
            is_string($call['id'] ?? null) ? $call['id'] : '',
            is_string($call['name'] ?? null) ? $call['name'] : '',
        );

        if (is_array($call['arguments'] ?? null)) {
            $builder->setJson($index, $call['arguments'] === [] ? '{}' : (string) json_encode($call['arguments'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
        }

        if (is_string($call['thoughtSignature'] ?? null)) {
            $builder->setSignature($index, $call['thoughtSignature']);
        }

        if (is_string($call['namespace'] ?? null)) {
            $builder->setNamespace($index, $call['namespace']);
        }

        $stream->push(new ToolCallEndEvent($index, $builder->toolCallOf($index), $builder->snapshot()));
    }

    /** @param array<string, mixed> $event */
    private function done(AssistantMessageBuilder $builder, AssistantMessageEventStream $stream, array $event): void
    {
        // Upstream's server sends stop, length or toolUse here, never error — that is the other
        // event. An unknown word is treated as a plain stop rather than as a failure: the turn did
        // finish, and refusing a finished turn over a word loses the work.
        $builder->setStopReason(StopReason::tryFrom((string) ($event['reason'] ?? '')) ?? StopReason::Stop);
        $builder->setUsage(self::usage($event['usage'] ?? []), priced: true);
        self::providerThinkingLevel($builder, $event);
        $message = $builder->snapshot();
        $stream->push(new DoneEvent($message->stopReason, $message));
        $stream->end();
    }

    /**
     * Upstream's `done` and `error` arms: `if (proxyEvent.providerThinkingLevel !== undefined)
     * partial.providerThinkingLevel = proxyEvent.providerThinkingLevel` — the native effort the
     * server's provider was asked for, which the agent reads to know whether a mid-conversation
     * effort change still has to be sent.
     *
     * @param array<string, mixed> $event
     */
    private static function providerThinkingLevel(AssistantMessageBuilder $builder, array $event): void
    {
        if (is_string($event['providerThinkingLevel'] ?? null)) {
            $builder->setProviderThinkingLevel($event['providerThinkingLevel']);
        }
    }

    /** @param array<string, mixed> $event */
    private function failed(AssistantMessageBuilder $builder, AssistantMessageEventStream $stream, array $event): void
    {
        $builder->setUsage(self::usage($event['usage'] ?? []), priced: true);
        self::providerThinkingLevel($builder, $event);
        // `partial.stopReason = proxyEvent.reason; partial.errorMessage = proxyEvent.errorMessage` —
        // an event without a message leaves the turn without one, as upstream's does. pig used to
        // write `The proxy reported an error with no message` in its place.
        $builder->setStopReason(($event['reason'] ?? '') === 'aborted' ? StopReason::Aborted : StopReason::Error);
        $builder->setErrorMessage(is_string($event['errorMessage'] ?? null) ? $event['errorMessage'] : null);
        $failed = $builder->snapshot();
        $stream->push(new ErrorEvent($failed->stopReason, $failed));
        $stream->end();
    }

    /**
     * The usage a gateway reports.
     *
     * Trusted as sent, **cost included** — `setUsage(…, priced: true)`, which is upstream assigning
     * `partial.usage = proxyEvent.usage` whole. The gateway made the call and knows what it paid;
     * pig knows only what the model's public price list says, so repricing here reports a number
     * nobody was charged: list price for a gateway on its own deal, and a bill for one running
     * flat-rate. That repricing is right for the four providers, none of which sends a cost at all,
     * and this is the one caller that gets one.
     *
     * The other side of trusting the wire: a gateway that sends no `cost` is reported as costing
     * nothing. Upstream's own type requires the field, so an absent one is taken at its word rather
     * than guessed at from a price list pig has no reason to think applies.
     *
     * The token counts are still added up when the gateway sends no total — that one is arithmetic
     * on numbers it did send.
     *
     * @param mixed $usage
     */
    private static function usage(mixed $usage): Usage
    {
        return MessageJson::decodeUsage(is_array($usage) ? $usage : []);
    }

    /**
     * A non-2xx in upstream's words: `Proxy error: ${response.status} ${response.statusText}`, or
     * `Proxy error: ${errorData.error}` when the body is JSON with a truthy `error` — whatever its
     * type, as a template literal prints it. It used to drop the status text and read only a
     * string `error`.
     */
    private function explain(int $status, string $reason, string $body): string
    {
        try {
            $errorData = JsJson::parse($body, false);
        } catch (\JsonException) {
            $errorData = null;
        }

        $error = $errorData instanceof \stdClass ? ($errorData->error ?? null) : null;

        if ($error !== null && $error !== false && $error !== '' && $error !== 0 && $error !== 0.0) {
            return 'Proxy error: ' . JsJson::toString($error);
        }

        return "Proxy error: {$status} {$reason}";
    }

}
