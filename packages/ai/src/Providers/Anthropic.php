<?php

declare(strict_types=1);

namespace Pig\Ai\Providers;

use Pig\Ai\AnthropicCompat;
use Pig\Ai\Api;
use Pig\Ai\AssistantMessage;
use Pig\Ai\AssistantMessageDiagnostic;
use Pig\Ai\DoneEvent;
use Pig\Ai\ErrorEvent;
use Pig\Ai\Http\HttpClient;
use Pig\Ai\Http\Request;
use Pig\Ai\Http\Response;
use Pig\Ai\Http\SseEvent;
use Pig\Ai\Http\SseParser;
use Pig\Ai\ImageContent;
use Pig\Ai\Model;
use Pig\Ai\ProviderError;
use Pig\Ai\StartEvent;
use Pig\Ai\StopReason;
use Pig\Ai\SystemMessage;
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
use Pig\Ai\TranscriptContext;
use Pig\Ai\Usage;
use Pig\Ai\UserMessage;
use Pig\Ai\Utils\AssistantMessageEventStream;
use Pig\Ai\Utils\ConstrainedSampling;
use Pig\Ai\Utils\ErrorBody;
use Pig\Ai\Utils\Headers;
use Pig\Ai\Utils\JsJson;
use Pig\Ai\Utils\ProviderHttpError;
use Pig\Ai\Utils\ProviderRetry;
use Pig\Ai\Utils\SdkHeaders;
use Pig\Ai\Utils\Text;
use Pig\Ai\Utils\Transcript;
use Pig\Ai\Utils\PigUserAgent;
use Pig\Ai\Utils\JsonRepair;
use Pig\Ai\Utils\Oauth\GithubCopilot;
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

    private const string FINE_GRAINED_TOOL_STREAMING_BETA = 'fine-grained-tool-streaming-2025-05-14';

    private const string INTERLEAVED_THINKING_BETA = 'interleaved-thinking-2025-05-14';

    private const string MID_CONVERSATION_OUTPUT_CONFIG_BETA = 'mid-conversation-output-config-2026-07-01';

    private const string THINKING_BINDING_CONTROLS_BETA = 'thinking-binding-controls-2026-08-01';

    private const string SERVER_SIDE_FALLBACK_BETA = 'server-side-fallback-2026-07-01';

    private const string INLINE_TOOLS_BETA = 'inline-tools-2026-09-15';

    /**
     * Upstream's `DEFERRED_TOOL_PLACEHOLDER`: "Stable deferred tool declared whenever native tool
     * changes are in use. Anthropic adds hidden prompt scaffolding for mid-conversation tool
     * changes; declaring this placeholder from the first request keeps that scaffolding in the
     * cached prefix, so the first tool change does not invalidate the cache (measured: full miss
     * without it). It is never activated and the model cannot see it."
     *
     * A method rather than a constant only because `new \stdClass()` is not allowed in one.
     *
     * @return array<string, mixed>
     */
    private static function deferredToolPlaceholder(): array
    {
        return [
            'name' => '__pi_deferred_placeholder__',
            'description' => 'Reserved placeholder. Never available. Never call this.',
            'input_schema' => ['type' => 'object', 'properties' => new \stdClass(), 'required' => []],
            'defer_loading' => true,
        ];
    }

    /** Upstream's `isAnthropicEffort()`: the effort names a managed-effort turn can be replayed with. */
    private const array ANTHROPIC_EFFORTS = ['low', 'medium', 'high', 'xhigh', 'max'];

    /** Upstream's `ANTHROPIC_MESSAGE_EVENTS`: the SSE event names that carry a stream event. */
    private const array MESSAGE_EVENTS = [
        'message_start',
        'message_delta',
        'message_stop',
        'content_block_start',
        'content_block_delta',
        'content_block_stop',
    ];

    /** Anthropic wants tool ids matching ^[a-zA-Z0-9_-]+$, at most 64 long, and rejects the request otherwise. */
    private const string ID_PATTERN = '/[^a-zA-Z0-9_-]/';

    private const int MAX_ID_LENGTH = 64;

    /** Upstream's `ANTHROPIC_STRICT_UNSUPPORTED_KEYWORDS`. */
    private const array STRICT_UNSUPPORTED_KEYWORDS = [
        'minimum',
        'maximum',
        'exclusiveMinimum',
        'exclusiveMaximum',
        'multipleOf',
        'maxItems',
        'uniqueItems',
        'minContains',
        'maxContains',
        'minProperties',
        'maxProperties',
    ];

    /** Upstream's `ANTHROPIC_STRICT_STRING_FORMATS`. */
    private const array STRICT_STRING_FORMATS = [
        'date-time',
        'time',
        'date',
        'duration',
        'email',
        'hostname',
        'uri',
        'ipv4',
        'ipv6',
        'uuid',
    ];

    public function __construct(private readonly HttpClient $http = new HttpClient())
    {
    }

    /** Returns at once; the response fills in as it arrives. */
    public function stream(Model $model, TranscriptContext $context, ?AnthropicOptions $options = null): AssistantMessageEventStream
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
        ?AnthropicOptions $options,
    ): void {
        // Upstream's `resolveTranscript(context, compat.supportsMidConvoSystemMessages)`: later system
        // messages stay in place for a model that takes them, and are folded into the leading one
        // otherwise. Everything below sees only the result.
        $context = Transcript::resolveTranscript($context, self::compat($model)?->supportsMidConvoSystemMessages ?? false);
        $currentTools = Transcript::getCurrentTools($context->messages);
        $builder = new AssistantMessageBuilder($model);
        // Upstream's `stopReason: "pending"` on the output from the start: only `message_delta`'s
        // `stop_reason` replaces it, so a stream that never sends one can be told from one that did.
        $builder->setStopReason(StopReason::Pending);
        // Upstream: `providerThinkingLevel = model.compat?.supportsMidConvoEffort ? (options?.effort
        // ?? "high") : undefined`, on the output from the start — so a failed turn records it too.
        if (self::compat($model)?->supportsMidConvoEffort === true) {
            $builder->setProviderThinkingLevel($options?->effort ?? 'high');
        }
        $signal = $options?->signal;
        // A subscription token's tools go out under Claude Code's names and come back under
        // them too, so the name on a `tool_use` block is mapped back to the tool this request
        // declared. Decided once here, because `dispatch()` has no options to ask.
        $isOAuth = $model->provider !== 'github-copilot' && ClaudeCode::isToken($options?->apiKey ?? '');
        $tools = $isOAuth ? $currentTools : [];
        // What the API says it rewrote in the request, from `message_start` or a later
        // `message_delta` — the last one wins, as upstream's single variable does.
        $transformations = null;

        try {
            // Upstream: `getAnthropicFederation()`, and without it `assertRequestAuth()` — a key or an
            // auth header in `options.headers` (header-owned auth) is what lets a request go.
            $apiKey = $options?->apiKey;
            $federation = AnthropicFederation::config($model, $apiKey, $options?->headers, $options?->env);

            if ($federation === null && !AnthropicFederation::hasRequestAuth($apiKey, $options?->headers)) {
                throw new ProviderError("No API key for provider: {$model->provider}");
            }

            $federationClient = $federation !== null
                ? AnthropicFederation::client($this->endpoint($model, $apiKey ?? ''), $federation, $this->http)
                : null;

            // `buildParams()`, then `onPayload` — whose answer replaces the params, `{...next, stream:
            // true}`.
            $params = $this->params($model, $context, $options, $isOAuth);
            $nextParams = $options?->onPayload !== null ? ($options->onPayload)($params, $model) : null;

            if ($nextParams !== null) {
                $params = (array) $nextParams;
                $params['stream'] = true;
            }

            // `retryProviderRequest(() => client.beta.messages.create(params, {signal, timeout,
            // maxRetries: 0}).asResponse(), {maxRetries, maxRetryDelayMs, signal})`.
            $timeoutMs = $options?->timeoutMs ?? SdkHeaders::STAINLESS_DEFAULT_TIMEOUT_MS;
            $response = ProviderRetry::retryProviderRequest(
                function () use ($model, $context, $options, $params, $isOAuth, $federationClient, $timeoutMs, $signal): Response {
                    try {
                        return SdkRequest::send(
                            $this->http,
                            $this->request($model, $context, $options, $params, $isOAuth, $federationClient?->getToken(), $timeoutMs),
                            $signal,
                            $timeoutMs,
                            $this->explain(...),
                        );
                    } catch (ProviderHttpError $error) {
                        // The SDK's `shouldRetry()`: a 401 from a request the token cache
                        // authenticated invalidates the token (with `maxRetries: 0` the retry
                        // itself never happens, but the next request exchanges afresh).
                        if ($error->status === 401) {
                            $federationClient?->invalidate();
                        }

                        throw $error;
                    }
                },
                $options?->maxRetries,
                $options?->maxRetryDelayMs,
                $signal,
            );

            if ($options?->onResponse !== null) {
                ($options->onResponse)(['status' => $response->status, 'headers' => $response->headers], $model);
            }

            $stream->push(new StartEvent($builder->snapshot()));
            $parser = new SseParser();
            // Upstream's `iterateAnthropicEvents()`: whether a `message_start` and a `message_stop`
            // went by, read off each parsed event's own `type`.
            $sawMessageStart = false;
            $sawMessageEnd = false;

            foreach ($response->body as $chunk) {
                foreach ($parser->feed($chunk) as $event) {
                    // `if (sse.event === "error") throw new Error(sse.data)` — the event's data,
                    // whole, is the message. An overloaded API sends this mid-stream with a 200.
                    if ($event->type === 'error') {
                        throw new ProviderError($event->data);
                    }

                    // `if (!ANTHROPIC_MESSAGE_EVENTS.has(sse.event ?? "")) continue` — `ping` and any
                    // event the protocol adds later are passed over unread.
                    if (!in_array($event->type, self::MESSAGE_EVENTS, true)) {
                        continue;
                    }

                    $data = self::parseEvent($event);

                    if ($options?->onProviderStreamEvent !== null) {
                        ($options->onProviderStreamEvent)($data, $model);
                    }

                    $type = is_array($data) ? ($data['type'] ?? null) : null;
                    $sawMessageStart = $sawMessageStart || $type === 'message_start';
                    $sawMessageEnd = $sawMessageEnd || $type === 'message_stop';
                    $transformations = $this->dispatch($event, $data, $model, $builder, $stream, $tools) ?? $transformations;
                }

                // `iterateSseMessages()`: `if (signal?.aborted) throw new Error("Request was aborted")`
                // before each read.
                if ($signal?->aborted() ?? false) {
                    throw new ProviderError('Request was aborted');
                }
            }

            // Upstream's check after the loop, before the two below.
            if ($signal?->aborted() ?? false) {
                throw new ProviderError('Request was aborted');
            }

            if ($sawMessageStart && !$sawMessageEnd) {
                throw new ProviderError('Anthropic stream ended before message_stop');
            }

            // A body that ended without a `stop_reason` — a connection cut, or a proxy that closed
            // it early — used to be a clean `stop` carrying a half-finished answer.
            if ($builder->stopReason() === StopReason::Pending) {
                throw new ProviderError('Anthropic stream ended without a stop reason');
            }

            // Upstream: `if (output.stopReason === "aborted" || output.stopReason === "error") throw
            // new Error(output.errorMessage || "An unknown error occurred")` — so a refusal or a
            // `sensitive` stop ends as an error event carrying its explanation, not as a `done`.
            $stop = $builder->stopReason();
            if ($stop === StopReason::Error || $stop === StopReason::Aborted) {
                $message = $builder->errorMessage();

                throw new ProviderError($message !== null && $message !== '' ? $message : 'An unknown error occurred');
            }

            // Upstream records these only on a turn that finished, after that throw.
            if ($transformations !== null && $transformations !== []) {
                $builder->addDiagnostic(self::inputTransformations($transformations));
            }

            $message = $builder->snapshot();
            $stream->push(new DoneEvent($message->stopReason, $message));
            $stream->end();
        } catch (Throwable $error) {
            // A provider never throws at its caller: the failure is the stream's result. An abort
            // that lands while the body is being read is the fetch's own rejection, the
            // DOMException "This operation was aborted".
            $builder->fail(
                SdkRequest::errorMessage($error),
                $signal?->aborted() ?? false,
            );
            $failed = $builder->snapshot();
            $stream->push(new ErrorEvent($failed->stopReason, $failed));
            $stream->end();
        }
    }

    /**
     * Upstream's `parseJsonWithRepair(sse.data)` inside `iterateAnthropicEvents()`: an event whose
     * data is not JSON even after the repair ends the turn with `Could not parse Anthropic SSE event
     * <event>: <why>; data=<data>; raw=<the event's lines joined by a literal \n>`. pig used to drop
     * such an event in silence and carry on, so a corrupted delta was simply missing from the answer.
     * `<why>` is V8's `SyntaxError` text (`JsJson`), as upstream's is.
     */
    private static function parseEvent(SseEvent $event): mixed
    {
        try {
            return JsonRepair::parse($event->data);
        } catch (\JsonException $error) {
            throw new ProviderError(
                "Could not parse Anthropic SSE event {$event->type}: {$error->getMessage()}; data={$event->data}; raw="
                    . implode('\n', $event->raw),
                previous: $error,
            );
        }
    }

    /**
     * @param list<Tool> $tools the request's tools when they went out under Claude Code's names, else empty
     * @return list<mixed>|null the event's `input_transformations`, when it carried a list of them
     */
    private function dispatch(SseEvent $event, mixed $data, Model $model, AssistantMessageBuilder $builder, AssistantMessageEventStream $stream, array $tools = []): ?array
    {
        if (!is_array($data)) {
            return null;
        }

        match ($event->type) {
            'message_start' => $this->onMessageStart($data, $model, $builder),
            'content_block_start' => $this->onBlockStart($data, $builder, $stream, $tools),
            'content_block_delta' => $this->onBlockDelta($data, $builder, $stream),
            'content_block_stop' => $this->onBlockStop($data, $builder, $stream),
            'message_delta' => $this->onMessageDelta($data, $builder),
            // ping and message_stop carry nothing this port needs; `run()` counts `message_stop`
            // and throws on an `error` event before it gets here.
            default => null,
        };

        $transformations = match ($event->type) {
            'message_start' => $data['message']['input_transformations'] ?? null,
            'message_delta' => $data['input_transformations'] ?? null,
            default => null,
        };

        return is_array($transformations) && array_is_list($transformations) ? $transformations : null;
    }

    /**
     * Upstream's `anthropic_input_transformations` diagnostic: what the API changed in the request,
     * each one cut down to its type, path and reason, with the ones it did not give left out.
     *
     * @param list<mixed> $transformations
     */
    private static function inputTransformations(array $transformations): AssistantMessageDiagnostic
    {
        $details = [];

        foreach ($transformations as $transformation) {
            $transformation = is_array($transformation) ? $transformation : [];
            $details[] = array_filter([
                'type' => $transformation['type'] ?? null,
                'path' => $transformation['path'] ?? null,
                'reason' => $transformation['reason'] ?? null,
            ], static fn (mixed $value): bool => $value !== null);
        }

        return new AssistantMessageDiagnostic(
            'anthropic_input_transformations',
            Timestamp::nowMs(),
            details: ['transformations' => $details],
        );
    }

    /** @param array<string, mixed> $data */
    private function onMessageStart(array $data, Model $requested, AssistantMessageBuilder $builder): void
    {
        $message = is_array($data['message'] ?? null) ? $data['message'] : [];

        if (is_string($message['id'] ?? null)) {
            $builder->setResponseId($message['id']);
        }

        // Upstream's rule: the model Anthropic names is kept only when it is not the one asked
        // for — an alias that resolved, or a fallback — so a message that has it is worth a look.
        $model = $message['model'] ?? null;

        if (is_string($model) && $model !== $requested->id) {
            $builder->setResponseModel($model);

            // Upstream's `usageModel`: a server-side fallback answered, so the turn is priced at
            // the fallback's `cost` from `allowedFallbackModels` — the entry for this provider and
            // that model — and at the requested model's when there is no such entry.
            foreach (self::compat($requested)?->allowedFallbackModels ?? [] as $fallback) {
                if (($fallback['provider'] ?? null) === $requested->provider && ($fallback['model'] ?? null) === $model) {
                    $builder->priceAs(new Model(
                        $model,
                        $requested->name,
                        $requested->api,
                        $requested->provider,
                        $requested->baseUrl,
                        $requested->contextWindow,
                        $requested->maxTokens,
                        $requested->reasoning,
                        $requested->input,
                        $fallback['cost'],
                        $requested->headers,
                        $requested->compat,
                        $requested->thinkingLevelMap,
                    ));

                    break;
                }
            }
        }

        // Captured here as well as at the end, so an aborted run still knows its input cost.
        // `cacheWrite1h` starts at 0 rather than unknown, as upstream's `|| 0` does here.
        $builder->setUsage(self::update(new Usage(cacheWrite1h: 0), $message['usage'] ?? []));
    }

    /**
     * @param array<string, mixed> $data
     * @param list<Tool> $tools
     */
    private function onBlockStart(array $data, AssistantMessageBuilder $builder, AssistantMessageEventStream $stream, array $tools = []): void
    {
        $wire = (int) ($data['index'] ?? 0);
        $block = $data['content_block'] ?? [];

        // Upstream's `fallback` block: the model that answers changed. Before any output that is
        // only news (the `message_start` model already says who answered); after some, the output
        // so far came from another model, which upstream refuses rather than mixing the two.
        if (($block['type'] ?? '') === 'fallback') {
            if ($builder->snapshot()->content !== []) {
                throw new ProviderError('Anthropic performed an unsupported mid-output model fallback');
            }

            return;
        }

        match ($block['type'] ?? '') {
            'text' => $stream->push(new TextStartEvent($builder->startText($wire), $builder->snapshot())),
            'thinking' => $stream->push(new ThinkingStartEvent($builder->startThinking($wire), $builder->snapshot())),
            // **These used to be dropped.** A redacted block is reasoning Anthropic withheld and
            // encrypted; it has to go back with the turn it came in, and with no arm here it
            // vanished — the next request replayed a turn that was not the one the model wrote.
            // Upstream keeps it as a thinking block with `redacted: true`.
            'redacted_thinking' => $stream->push(new ThinkingStartEvent(
                $builder->startRedactedThinking($wire, (string) ($block['data'] ?? '')),
                $builder->snapshot(),
            )),
            'tool_use' => $stream->push(new ToolCallStartEvent(
                $builder->startToolCall(
                    $wire,
                    (string) ($block['id'] ?? ''),
                    ClaudeCode::nameIn((string) ($block['name'] ?? ''), $tools),
                ),
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
            $builder->setRawStopReason($reason);
            [$stop, $errorMessage] = $this->stopReason($reason, $data['delta']['stop_details'] ?? null);
            $builder->setStopReason($stop);

            if ($errorMessage !== null) {
                $builder->setErrorMessage($errorMessage);
            }
        }

        // Merged with what `message_start` reported rather than replacing it — see `update()`.
        $builder->setUsage(self::update($builder->snapshot()->usage, $data['usage'] ?? []));
    }

    /**
     * The counts, with anything this report does not mention left as it was.
     *
     * **A field the delta does not carry means "no change", not "zero".** Anthropic's
     * `message_delta.usage` is typed `input_tokens: number | null` — the SDK's own words for "may
     * not be reported" — and the streaming documentation's own example carries nothing but
     * `output_tokens`. Read with a `?? 0`, as this and upstream both did, the final delta wipes out
     * the input, cache-read and cache-write counts that `message_start` had already reported.
     *
     * What that costs is not cosmetic: the input cost of every Anthropic turn disappears from
     * `/stats` and the footer, and `Compaction::contextTokens()` — which believes `totalTokens` —
     * sees a conversation the size of its last answer, so auto-compaction never fires on the
     * provider most of these conversations are had with. The turn still recovers when the API
     * rejects the request as too long, but late and by accident.
     *
     * Both API behaviours are handled by merging: a delta that does carry the cumulative numbers
     * updates them, and one that does not leaves them standing. Anthropic reports the components
     * and no total, which is what `AssistantMessageBuilder::setUsage()` adds up.
     *
     * The two optional splits merge the same way: the one-hour share of the cache write
     * (`cache_creation.ephemeral_1h_input_tokens` — Vercel's gateway sends it only on the delta)
     * and the thinking share of the output (`output_tokens_details.thinking_tokens`).
     *
     * @param array<string, mixed> $usage
     */
    private static function update(Usage $sofar, array $usage): Usage
    {
        return new Usage(
            self::count($usage, 'input_tokens', $sofar->input),
            self::count($usage, 'output_tokens', $sofar->output),
            self::count($usage, 'cache_read_input_tokens', $sofar->cacheRead),
            self::count($usage, 'cache_creation_input_tokens', $sofar->cacheWrite),
            reasoning: self::count((array) ($usage['output_tokens_details'] ?? []), 'thinking_tokens', $sofar->reasoning),
            cacheWrite1h: self::count((array) ($usage['cache_creation'] ?? []), 'ephemeral_1h_input_tokens', $sofar->cacheWrite1h),
        );
    }

    /**
     * @param array<string, mixed> $usage
     * @return ($sofar is int ? int : int|null)
     */
    private static function count(array $usage, string $key, ?int $sofar): ?int
    {
        $value = $usage[$key] ?? null;

        return is_numeric($value) ? (int) $value : $sofar;
    }

    /**
     * Upstream's `mapStopReason()`: the stop reason, and the error message for the two that are
     * errors. A refusal says why in `stop_details.explanation` when Anthropic gives one; `sensitive`
     * ("Content flagged by safety filters (not yet in SDK types)") has a fixed sentence. **A reason
     * this does not know throws** — upstream's "Unhandled stop reason", which ends the turn as an
     * error — where pig used to read anything new as a clean `stop`.
     *
     * @return array{0: StopReason, 1: string|null}
     */
    private function stopReason(string $reason, mixed $stopDetails = null): array
    {
        $explanation = is_array($stopDetails) ? ($stopDetails['explanation'] ?? null) : null;

        return match ($reason) {
            'end_turn' => [StopReason::Stop, null],
            'max_tokens' => [StopReason::Length, null],
            'tool_use' => [StopReason::ToolUse, null],
            // `stopDetails?.explanation || "The model refused to complete the request"`.
            'refusal' => [StopReason::Error, is_string($explanation) && $explanation !== '' ? $explanation : 'The model refused to complete the request'],
            // pause_turn asks for a resubmit; treating it as a normal stop is good enough.
            'pause_turn' => [StopReason::Stop, null],
            // We send no stop sequences, so this should not arrive.
            'stop_sequence' => [StopReason::Stop, null],
            'sensitive' => [StopReason::Error, 'Provider stopped with: sensitive'],
            default => throw new ProviderError("Unhandled stop reason: {$reason}"),
        };
    }

    /**
     * A refused request in upstream's words: the `@anthropic-ai/sdk` `APIError`'s message, which
     * upstream's catch prints as it is — `<status> <the body's JSON>` for Anthropic's own
     * `{"type":"error","error":{…}}`, `<status> <message>` for a body with a top-level `message`,
     * `<status> <text>` for one that is not JSON, `<status> status code (no body)` for none. It used
     * to be pig's own `Anthropic returned <status>: <error.message>`.
     */
    private function explain(int $status, string $body): string
    {
        return ErrorBody::anthropicApiError($status, $body);
    }

    /**
     * Upstream's `buildParams()`: the body, with `betas` (when there are any) after `stream`, where
     * upstream's params object carries it — so `onPayload` sees what upstream's sees. The SDK takes
     * `betas` back out as the `anthropic-beta` header (`request()`).
     *
     * @return array<string, mixed>
     */
    private function params(Model $model, TranscriptContext $context, ?AnthropicOptions $options, bool $isOAuth): array
    {
        // "Native tool changes keep the request-level tool list fixed and define every later tool
        // by value in a `tool_addition` block, which also expresses same-name redefinitions.
        // Anthropic rejects a tool list where every tool is deferred, so there must be an initial
        // active tool to anchor the placeholder. Otherwise the current tool list is sent."
        $compat = self::compat($model);
        $initialTools = Transcript::getInitialSystemMessage($context->messages)?->toolsAdded ?? [];
        $nativeToolChanges = ($compat?->supportsMidConvoSystemMessages ?? false)
            && ($compat?->supportsMidConvoToolChanges ?? false)
            && $initialTools !== [];
        $body = $this->body($model, $context, $options, $isOAuth, $nativeToolChanges);
        $betas = $this->betaFeatures($model, $context, $isOAuth, $nativeToolChanges, $options);

        if ($betas === []) {
            return $body;
        }

        $params = [];

        foreach ($body as $key => $value) {
            $params[$key] = $value;

            if ($key === 'stream') {
                $params['betas'] = $betas;
            }
        }

        return $params;
    }

    /**
     * One attempt's request, as `client.beta.messages.create(params, …)` builds it with upstream's
     * `createClient()` options: `POST <baseURL>/v1/messages?beta=true`, and the SDK's
     * `buildHeaders()` over its five sources, in order —
     *
     * 1. the SDK's own: `Accept`, its `User-Agent`, the `X-Stainless-*` set (`SdkHeaders`),
     *    `anthropic-dangerous-direct-browser-access: true` (upstream passes
     *    `dangerouslyAllowBrowser`) and `anthropic-version`;
     * 2. auth: `X-Api-Key` for an API key, `Authorization: Bearer` for Copilot's token, a
     *    subscription token, or the federated access token; nothing for header-owned auth;
     * 3. upstream's `defaultHeaders`, `mergeClientHeaders()`: `User-Agent: pig (…)`, `accept` and the
     *    browser header, then per arm the Claude Code identity, the session-affinity header, the
     *    model's headers, Copilot's dynamic headers, and `options.headers` last;
     * 4. the JSON body's `content-type`;
     * 5. the call's own: `anthropic-beta` from `betas` (and `anthropic-user-profile-id` /
     *    `anthropic-workspace-id` from those params, which only an `onPayload` could set).
     *
     * A later source replaces an earlier header of the same name, any case, and a null removes it.
     * A federated request then gets `oauth-2025-04-20` appended to its betas (`prepareRequest()`).
     * With neither `x-api-key` nor `authorization` left, and neither explicitly removed, the SDK's
     * `validateHeaders()` refuses.
     *
     * @param array<string, mixed> $params
     */
    private function request(Model $model, TranscriptContext $context, ?AnthropicOptions $options, array $params, bool $isOAuth, ?string $federationToken, int $timeoutMs): Request
    {
        $apiKey = $options?->apiKey;
        $isCopilot = $model->provider === 'github-copilot';
        $optionsHeaders = $options?->headers ?? [];

        // `const { betas, user_profile_id, workspace_id, ...body } = params`.
        $betas = $params['betas'] ?? null;
        $userProfileId = $params['user_profile_id'] ?? null;
        $workspaceId = $params['workspace_id'] ?? null;
        unset($params['betas'], $params['user_profile_id'], $params['workspace_id']);

        $sdkDefaults = [
            ...SdkHeaders::stainless('Anthropic/JS ' . SdkHeaders::ANTHROPIC_SDK_VERSION, SdkHeaders::ANTHROPIC_SDK_VERSION, $timeoutMs),
            'anthropic-dangerous-direct-browser-access' => 'true',
            'anthropic-version' => self::VERSION,
        ];

        $clientDefaults = ['accept' => 'application/json', 'anthropic-dangerous-direct-browser-access' => 'true'];

        if ($isCopilot) {
            // `authToken: apiKey ?? null`.
            $auth = $apiKey !== null ? ['Authorization' => "Bearer {$apiKey}"] : [];
            $defaultHeaders = self::mergeClientHeaders($clientDefaults, $model->headers, Copilot::headers($model, $context), $optionsHeaders);
        } elseif ($isOAuth) {
            $auth = ['Authorization' => "Bearer {$apiKey}"];
            $defaultHeaders = self::mergeClientHeaders(
                [...$clientDefaults, 'user-agent' => 'claude-cli/' . ClaudeCode::VERSION, 'x-app' => 'cli'],
                $model->headers,
                $optionsHeaders,
            );
        } else {
            // Upstream's API-key arm: the session id as a header when caching is on and the
            // model's compat asks for it — `x-session-id` for the `openrouter` format,
            // `x-session-affinity` otherwise.
            $sessionAffinityHeaders = [];
            $cacheSessionId = $options?->resolvedCacheRetention() === 'none' ? null : $options?->sessionId;

            if ($cacheSessionId !== null && $cacheSessionId !== '') {
                $isOpenRouter = $model->provider === 'openrouter' || str_contains($model->baseUrl, 'openrouter.ai');
                $compat = self::compat($model);

                if ($compat?->sendSessionAffinityHeaders ?? $isOpenRouter) {
                    $format = $compat?->sessionAffinityFormat ?? ($isOpenRouter ? 'openrouter' : null);
                    $sessionAffinityHeaders[$format === 'openrouter' ? 'x-session-id' : 'x-session-affinity'] = $cacheSessionId;
                }
            }

            $auth = match (true) {
                $federationToken !== null => ['Authorization' => "Bearer {$federationToken}"],
                $apiKey !== null => ['X-Api-Key' => $apiKey],
                default => [],
            };
            $defaultHeaders = self::mergeClientHeaders($clientDefaults, $sessionAffinityHeaders, $model->headers, $optionsHeaders);
        }

        $callHeaders = [];

        if ($betas !== null) {
            $callHeaders['anthropic-beta'] = is_array($betas) ? implode(',', array_map(JsJson::toString(...), $betas)) : JsJson::toString($betas);
        }

        if ($userProfileId !== null) {
            $callHeaders['anthropic-user-profile-id'] = JsJson::toString($userProfileId);
        }

        if ($workspaceId !== null) {
            $callHeaders['anthropic-workspace-id'] = JsJson::toString($workspaceId);
        }

        $built = Headers::build($sdkDefaults, $auth, $defaultHeaders, ['content-type' => 'application/json'], $callHeaders);
        $headers = $built['values'];

        if ($federationToken !== null) {
            // `prepareRequest()`: the token's beta joins whatever betas the request already has.
            $existing = isset($headers['anthropic-beta']) ? array_map(trim(...), explode(',', $headers['anthropic-beta'])) : [];

            if (!in_array(AnthropicFederation::OAUTH_API_BETA_HEADER, $existing, true)) {
                $headers['anthropic-beta'] = implode(',', [...$existing, AnthropicFederation::OAUTH_API_BETA_HEADER]);
            }
        } elseif (($headers['x-api-key'] ?? '') === '' && ($headers['authorization'] ?? '') === ''
            && !isset($built['nulls']['x-api-key']) && !isset($built['nulls']['authorization'])) {
            throw new ProviderError('Could not resolve authentication method. Expected one of apiKey, authToken, credentials, config, or profile to be set. Or for one of the "X-Api-Key" or "Authorization" headers to be explicitly omitted');
        }

        return new Request(
            'POST',
            $this->endpoint($model, $apiKey ?? '') . '/v1/messages?beta=true',
            $headers,
            $this->encode($params),
        );
    }

    /**
     * Upstream's `mergeClientHeaders()`: `mergeHeaders({"User-Agent": getPiUserAgent()}, ...)`, an
     * `Object.assign` — exact-name keys replaced in place, nulls kept for the SDK to act on.
     *
     * @param array<string, string|null> ...$headerSources
     * @return array<string, string|null>
     */
    private static function mergeClientHeaders(array ...$headerSources): array
    {
        $merged = ['User-Agent' => PigUserAgent::get()];

        foreach ($headerSources as $headers) {
            foreach ($headers as $name => $value) {
                $merged[(string) $name] = $value;
            }
        }

        return $merged;
    }

    /**
     * Upstream's `getBetaFeatures()`, line for line.
     *
     * An `anthropic-beta` the model's own headers carry is **the whole list**, split on commas — no
     * Claude Code betas added, nothing else — and a null one means none at all. Otherwise: the two
     * Claude Code betas for a subscription token; `fine-grained-tool-streaming` only for a request
     * with tools to a model that does **not** take per-tool `eager_input_streaming` (which is the
     * default, so this beta is normally gone — `tool()` asks for eager streaming instead);
     * `interleaved-thinking` for a non-adaptive thinking turn; and the two managed-effort betas for a
     * `supportsMidConvoEffort` model.
     *
     * `server-side-fallback` goes with a model whose compat lists `allowedFallbackModels`, and
     * `inline-tools` with a request that makes native mid-conversation tool changes (`params()`).
     * An `anthropic-beta` in `options.headers` counts as the model's does, read after it.
     *
     * @return list<string>
     */
    private function betaFeatures(Model $model, TranscriptContext $context, bool $isOAuth, bool $nativeToolChanges, ?AnthropicOptions $options): array
    {
        $configured = false;
        $configuredFeatures = null;

        // `for (const headers of [model.headers, options?.headers])` — the request's own wins.
        foreach ([$model->headers, $options?->headers ?? []] as $headers) {
            foreach ($headers as $name => $value) {
                if (strtolower((string) $name) === 'anthropic-beta') {
                    $configured = true;
                    $configuredFeatures = $value;
                }
            }
        }

        if ($configured && $configuredFeatures === null) {
            return [];
        }

        if ($configured) {
            $features = array_filter(
                array_map(trim(...), explode(',', (string) $configuredFeatures)),
                static fn (string $feature): bool => $feature !== '',
            );

            return array_values(array_unique($features));
        }

        $compat = self::compat($model);
        $features = [];

        if ($isOAuth) {
            array_push($features, ...ClaudeCode::BETAS);
        }

        // Upstream's `shouldUseFineGrainedToolStreamingBeta()`.
        if (Transcript::getCurrentTools($context->messages) !== [] && !($compat?->supportsEagerToolInputStreaming ?? true)) {
            $features[] = self::FINE_GRAINED_TOOL_STREAMING_BETA;
        }

        // `model.reasoning && options?.thinkingEnabled === true && (options.interleavedThinking ??
        // true) && model.compat?.forceAdaptiveThinking !== true` — adaptive thinking interleaves
        // without the beta, and a turn that does not think has nothing to interleave.
        if ($model->reasoning
            && $options?->thinkingEnabled === true
            && $options->interleavedThinking
            && $compat?->forceAdaptiveThinking !== true) {
            $features[] = self::INTERLEAVED_THINKING_BETA;
        }

        // Upstream's `shouldUseServerSideFallbackBeta()`.
        if (($compat?->allowedFallbackModels ?? []) !== []) {
            $features[] = self::SERVER_SIDE_FALLBACK_BETA;
        }

        if ($compat?->supportsMidConvoEffort === true) {
            $features[] = self::MID_CONVERSATION_OUTPUT_CONFIG_BETA;
            $features[] = self::THINKING_BINDING_CONTROLS_BETA;
        }

        if ($nativeToolChanges) {
            $features[] = self::INLINE_TOOLS_BETA;
        }

        return array_values(array_unique($features));
    }

    /** The model's `AnthropicCompat`, or null — a compat of another API's type says nothing here. */
    private static function compat(Model $model): ?AnthropicCompat
    {
        return $model->compat instanceof AnthropicCompat ? $model->compat : null;
    }

    /**
     * Where to send it, which for Copilot the token decides — the rule `OpenAiCompletions` and
     * `OpenAiResponses` already follow, and for the reason written there: the token's
     * `proxy-ep=` claim names the host a business or enterprise account answers at, and the
     * registry's base URL is only the default. Upstream rewrites the model's `baseUrl` from the
     * token in its registry; pig's registry knows nothing about credentials, so the provider asks.
     */
    private function endpoint(Model $model, string $apiKey): string
    {
        $base = $model->provider === 'github-copilot' && $apiKey !== ''
            ? GithubCopilot::baseUrl($apiKey)
            : $model->baseUrl;

        return rtrim($base, '/');
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
    private function body(Model $model, TranscriptContext $context, ?AnthropicOptions $options, bool $isOAuth, bool $nativeToolChanges = false): array
    {
        $compat = self::compat($model);
        $midConvoEffort = $compat?->supportsMidConvoEffort === true;
        // Upstream's `activeEffort = options?.effort ?? "high"`, said to a managed-effort model in the
        // system message that closes the conversation.
        $activeEffort = $options?->effort ?? 'high';
        $cacheControl = self::cacheControl($model, $options);
        // Upstream's `buildParams()`: the leading system message is the `system` field, and the
        // rest of the transcript is the conversation.
        $initialSystemMessage = Transcript::getInitialSystemMessage($context->messages);
        $initialSystemText = $initialSystemMessage !== null ? Text::getSystemMessageText($initialSystemMessage) : '';
        $supportsStrictTools = $compat?->strictTools ?? false;
        $supportsEagerToolInputStreaming = $compat?->supportsEagerToolInputStreaming ?? true;
        $convertToolDefinitions = $nativeToolChanges
            ? fn (array $tools): array => array_map(
                fn (Tool $tool): array => $this->tool($tool, $isOAuth, $supportsEagerToolInputStreaming, $supportsStrictTools),
                $tools,
            )
            : null;
        $body = [
            'model' => $model->id,
            'messages' => $this->messages($context, $model, $isOAuth, $midConvoEffort ? $activeEffort : null, $cacheControl, $initialSystemMessage !== null, $convertToolDefinitions),
            // Upstream's `max_tokens: options?.maxTokens ?? model.maxTokens`: the model's own ceiling
            // when nobody said. `Stream::simple()` always says — the ceiling clamped to the room the
            // context leaves, and on a budget-thinking turn raised by the budget — so this default
            // is for a caller of `start()` that did not.
            'max_tokens' => $options?->maxTokens ?? $model->maxTokens,
            'stream' => true,
        ];

        $system = $this->system($initialSystemText, $isOAuth, $cacheControl);

        if ($system !== []) {
            $body['system'] = $system;
        }

        // Upstream: "Temperature is incompatible with extended thinking and unsupported on Claude
        // Opus 4.7+." — `options?.temperature !== undefined && !options?.thinkingEnabled &&
        // model.compat?.supportsMidConvoEffort !== true && compat.supportsTemperature`, the last
        // defaulting to true and written false by the generator for the models that refuse it.
        if ($options?->temperature !== null
            && $options->thinkingEnabled !== true
            && !$midConvoEffort
            && ($compat?->supportsTemperature ?? true)) {
            $body['temperature'] = $options->temperature;
        }

        // Upstream's `supportsStrictTools: model.compat?.supportsStrictTools ?? false`, which its
        // generated catalogue sets on every `anthropic` provider model (`Models` does the same),
        // and `supportsEagerToolInputStreaming ?? true`.
        $toolCacheControl = ($compat?->supportsCacheControlOnTools ?? true) ? $cacheControl : null;
        $convertTools = function (array $tools) use ($isOAuth, $supportsEagerToolInputStreaming, $supportsStrictTools, $toolCacheControl): array {
            $converted = array_map(
                fn (Tool $tool): array => $this->tool($tool, $isOAuth, $supportsEagerToolInputStreaming, $supportsStrictTools),
                $tools,
            );

            // Upstream's `convertTools(…, toolCacheControl)`: the cache breakpoint on the **last**
            // tool, so the tool list is part of the cached prefix — unless the model's compat says
            // its endpoint refuses the field there (`supportsCacheControlOnTools`, default true).
            if ($toolCacheControl !== null && $converted !== []) {
                $converted[count($converted) - 1]['cache_control'] = $toolCacheControl;
            }

            return $converted;
        };

        if ($nativeToolChanges) {
            // "Initial tools stay active with the cache breakpoint on the last one, followed by the
            // placeholder. The list never changes afterwards: later tools are defined by value in
            // `tool_addition` blocks and withdrawn by `tool_removal`, so the cached prefix survives
            // every tool change."
            $body['tools'] = [...$convertTools($initialSystemMessage->toolsAdded ?? []), self::deferredToolPlaceholder()];
        } else {
            $tools = Transcript::getCurrentTools($context->messages);

            if ($tools !== []) {
                $body['tools'] = $convertTools($tools);
            }
        }

        // Upstream's `buildParams()` thinking block, arm for arm. Only a reasoning model is told
        // anything about thinking at all — except a managed-effort one, which upstream's comment
        // explains: "Managed effort models always use adaptive thinking so prefix mismatches can be
        // dropped instead of surfacing as persistent 400 responses." Its effort goes in the system
        // messages `messages()` adds, so the top-level one is always `high`.
        if ($midConvoEffort) {
            $body['thinking'] = [
                'type' => 'adaptive',
                'display' => $options?->thinkingDisplay ?? 'summarized',
                'block_binding' => ['prefix_mismatch_behavior' => 'drop_block'],
            ];
            $body['output_config'] = ['effort' => 'high'];
        } elseif ($model->reasoning) {
            if ($options?->thinkingEnabled === true) {
                // "Default to "summarized" so Opus 4.7 and Mythos Preview behave like older Claude 4
                // models (whose API default is also "summarized")" — on both arms, adaptive and budget.
                $display = $options->thinkingDisplay ?? 'summarized';

                // Upstream's `model.compat?.forceAdaptiveThinking === true`, and nothing else: no
                // model id is looked at here. The ids are upstream's generator list, applied once
                // where the built-in models are made (`AnthropicCompat::forBuiltIn()`, `Models`).
                if ($compat?->forceAdaptiveThinking === true) {
                    $body['thinking'] = ['type' => 'adaptive', 'display' => $display];
                    if ($options->effort !== null) {
                        $body['output_config'] = ['effort' => $options->effort];
                    }
                } else {
                    $body['thinking'] = [
                        'type' => 'enabled',
                        'budget_tokens' => $options->thinkingBudgetTokens ?: 1024,
                        'display' => $display,
                    ];
                }
            } elseif ($options?->thinkingEnabled === false && $model->hasThinkingLevel('off')) {
                // Upstream: `options?.thinkingEnabled === false && model.thinkingLevelMap?.off !==
                // null`. Off is said, not left to the API's default — a model that thinks unless
                // told otherwise (the adaptive ones) thought and billed for it on every turn pig
                // meant to have no thinking. A map whose `off` is null means the model cannot be
                // switched off, and then nothing is sent; `hasThinkingLevel()` is that test, absent
                // key and no map both counting as "has it".
                $body['thinking'] = ['type' => 'disabled'];
            }
        }

        // Upstream: `metadata.user_id`, when it is a string, and nothing else of the metadata.
        $userId = $options?->metadata['user_id'] ?? null;

        if (is_string($userId)) {
            $body['metadata'] = ['user_id' => $userId];
        }

        // Upstream: a string choice becomes `{type: choice}`; `{type: "tool", name}` goes as it is.
        if ($options?->toolChoice !== null && $options->toolChoice !== '' && $options->toolChoice !== []) {
            $body['tool_choice'] = is_string($options->toolChoice) ? ['type' => $options->toolChoice] : $options->toolChoice;
        }

        // Upstream: `fallbacks: allowedFallbackModels.map(({model}) => ({model}))`, only when there
        // are any — Anthropic rejects the field for a model with no permitted fallback targets.
        $fallbacks = $compat?->allowedFallbackModels ?? [];

        if ($fallbacks !== []) {
            $body['fallbacks'] = array_map(static fn (array $fallback): array => ['model' => $fallback['model']], $fallbacks);
        }

        return $body;
    }

    /**
     * Upstream's `getCacheControl()`: no breakpoints at all for `cacheRetention: none`; otherwise
     * `{type: "ephemeral"}`, with `ttl: "1h"` for `long` on a model whose compat does not say it
     * cannot (`supportsLongCacheRetention`, default true). No beta goes with the one-hour TTL.
     *
     * @return array{type: string, ttl?: string}|null
     */
    private static function cacheControl(Model $model, ?AnthropicOptions $options): ?array
    {
        $retention = $options?->resolvedCacheRetention() ?? (new AnthropicOptions())->resolvedCacheRetention();

        if ($retention === 'none') {
            return null;
        }

        $long = $retention === 'long' && (self::compat($model)?->supportsLongCacheRetention ?? true);

        return $long ? ['type' => 'ephemeral', 'ttl' => '1h'] : ['type' => 'ephemeral'];
    }

    /**
     * @param array<string, string>|null $cacheControl see `cacheControl()`
     * @return list<array<string, mixed>>
     */
    private function system(string $initialSystemText, bool $isOAuth, ?array $cacheControl): array
    {
        $blocks = [];

        // An OAuth token is Claude Code's, and Anthropic requires the matching identity.
        if ($isOAuth) {
            $blocks[] = $this->cachedText(ClaudeCode::IDENTITY, $cacheControl);
        }

        if ($initialSystemText !== '') {
            $blocks[] = $this->cachedText(Utf8::sanitize($initialSystemText), $cacheControl);
        }

        return $blocks;
    }

    /**
     * @param array<string, string>|null $cacheControl
     * @return array<string, mixed>
     */
    private function cachedText(string $text, ?array $cacheControl): array
    {
        return ['type' => 'text', 'text' => $text, ...($cacheControl === null ? [] : ['cache_control' => $cacheControl])];
    }

    /** @return array<string, mixed> */
    private function tool(Tool $tool, bool $isOAuth, bool $supportsEagerToolInputStreaming, bool $supportsStrictTools): array
    {
        // Upstream's `convertTools()`: a strict tool sends its whole strict schema with the legacy
        // three keys laid over it (`{ ...parameters, ...legacyInputSchema }`) and `strict: true`;
        // any other tool sends the legacy three alone, as before.
        $strict = ConstrainedSampling::resolveJsonSchemaStrictSampling(
            $tool,
            $supportsStrictTools,
            self::isStrictUnsupportedKeyword(...),
        );
        $parameters = ConstrainedSampling::getJsonSchemaToolParameters($tool, $strict);
        $legacyInputSchema = [
            'type' => 'object',
            'properties' => $parameters['properties'] ?? new \stdClass(),
            'required' => $parameters['required'] ?? [],
        ];

        $out = [
            'name' => $isOAuth ? ClaudeCode::nameOut($tool->name) : $tool->name,
            'description' => $tool->description,
        ];

        // Upstream's `...(supportsEagerToolInputStreaming ? { eager_input_streaming: true } : {})`:
        // the tool's input streams as the model writes it, which used to take the
        // `fine-grained-tool-streaming` beta on every request and now is the default per tool.
        if ($supportsEagerToolInputStreaming) {
            $out['eager_input_streaming'] = true;
        }

        if ($strict === true) {
            $out['strict'] = true;
        }

        $out['input_schema'] = $strict === true ? [...$parameters, ...$legacyInputSchema] : $legacyInputSchema;

        return $out;
    }

    /**
     * Upstream's `isAnthropicStrictUnsupportedKeyword`: what Anthropic's strict tool use answers
     * with a 400 for the whole request, so a `prefer` tool using any of it is sent non-strict.
     * https://platform.claude.com/docs/en/build-with-claude/structured-outputs#json-schema-limitations
     */
    private static function isStrictUnsupportedKeyword(string $key, mixed $value): bool
    {
        if (in_array($key, self::STRICT_UNSUPPORTED_KEYWORDS, true)) {
            return true;
        }

        if ($key === 'minItems') {
            return $value !== 0 && $value !== 1;
        }

        if ($key === 'format') {
            return !is_string($value) || !in_array($value, self::STRICT_STRING_FORMATS, true);
        }

        return false;
    }

    /**
     * The conversation, as Anthropic wants it.
     *
     * **Through `TransformMessages` first**, which the other three providers all did and this one
     * did not. Two things it fixes here, and both are refusals rather than degradations: a thought
     * another model had is signed by that model, and sending it on as a signed `thinking` block
     * makes Anthropic reject the whole request — so `/model gemini` followed by `/model sonnet`
     * broke the conversation. And a tool call left without a result, which is what an interrupted
     * turn leaves behind, is refused outright rather than ignored, so the next thing anybody typed
     * failed until the conversation was compacted past it.
     *
     * **A managed-effort model** (`AnthropicCompat::$supportsMidConvoEffort`) gets `$activeEffort`, and
     * then upstream's `insertThinkingLevelMessages()`: an effort-only system message (`{role:
     * "system", content: [], output_config: {effort}}`) in front of every earlier turn of this
     * provider on this API that recorded its `providerThinkingLevel`, and one more with the effort
     * asked for now at the end. The thinking each earlier turn did stays bound to the effort it was
     * done at, and the effort can change mid-conversation without a 400.
     *
     * **A later system message** (`SystemMessage`) reaches here only when the model takes them
     * natively — otherwise the transcript was collapsed into the leading message before this — and
     * goes as a `system` turn: its update text (`Text::renderSystemMessageUpdate()`), and for a
     * request with native tool changes (`$convertToolDefinitions`) a `tool_removal` per removed tool
     * that is not redefined and a `tool_addition` per added one. Upstream's comment: "Later system
     * messages are held back and emitted directly before the next assistant message (or at the end
     * of the transcript). Anthropic requires `tool_result` blocks to immediately follow their
     * `tool_use`, so a system message between them is rejected; this also mirrors where the
     * managed-effort system messages are inserted. As a result an update placed before a user
     * message in the transcript lands after it on the wire."
     *
     * @param array<string, string>|null $cacheControl the breakpoint for the last user or system
     *        block — see `cacheControl()`; null marks nothing
     * @param bool $dropInitialSystemMessage the transcript leads with the system prompt, which goes
     *        in the `system` field instead (`transformedMessages.slice(1)`)
     * @param (\Closure(list<Tool>): list<array<string, mixed>>)|null $convertToolDefinitions converts
     *        tool definitions for native `tool_addition` blocks; null when tool changes are not native
     * @return list<array<string, mixed>>
     */
    private function messages(TranscriptContext $context, Model $model, bool $isOAuth = false, ?string $activeEffort = null, ?array $cacheControl = null, bool $dropInitialSystemMessage = false, ?\Closure $convertToolDefinitions = null): array
    {
        $allowEmptySignature = self::compat($model)?->allowEmptySignature ?? false;
        $out = [];
        // Upstream's `assistantLevels`: the index in `$out` of each such earlier turn, and its effort.
        $assistantLevels = [];
        $messages = TransformMessages::apply($context->messages, $model, self::normalizeToolCallId(...));

        if ($dropInitialSystemMessage) {
            $messages = array_slice($messages, 1);
        }

        $count = count($messages);
        $pendingSystemMessages = [];
        $flushPendingSystemMessages = static function () use (&$out, &$pendingSystemMessages): void {
            array_push($out, ...$pendingSystemMessages);
            $pendingSystemMessages = [];
        };

        for ($i = 0; $i < $count; $i++) {
            $message = $messages[$i];

            if ($message instanceof SystemMessage) {
                $text = Text::renderSystemMessageUpdate($message);
                $blocks = [];

                if ($text !== '') {
                    $blocks[] = ['type' => 'text', 'text' => Utf8::sanitize($text)];
                }

                if ($convertToolDefinitions !== null) {
                    $added = $message->toolsAdded ?? [];
                    $redefined = array_flip(array_map(static fn (Tool $tool): string => $tool->name, $added));

                    foreach ($message->toolsRemoved ?? [] as $tool) {
                        // "A new definition under the same name replaces the old one, so no removal is needed."
                        if (isset($redefined[$tool->name])) {
                            continue;
                        }

                        $blocks[] = [
                            'type' => 'tool_removal',
                            'tool' => ['type' => 'tool_reference', 'name' => $isOAuth ? ClaudeCode::nameOut($tool->name) : $tool->name],
                        ];
                    }

                    foreach ($convertToolDefinitions($added) as $definition) {
                        $blocks[] = ['type' => 'tool_addition', 'tool' => ['type' => 'tool_definition', 'definition' => $definition]];
                    }
                }

                if ($blocks !== []) {
                    $pendingSystemMessages[] = ['role' => 'system', 'content' => $blocks];
                }

                continue;
            }

            if ($message instanceof UserMessage) {
                $blocks = $this->userBlocks($message, $model);

                if ($blocks !== []) {
                    $out[] = ['role' => 'user', 'content' => $blocks];
                }

                continue;
            }

            if ($message instanceof AssistantMessage) {
                $flushPendingSystemMessages();
                $blocks = $this->assistantBlocks($message, $isOAuth, $allowEmptySignature);

                if ($blocks !== []) {
                    if ($activeEffort !== null
                        && $message->api === Api::AnthropicMessages
                        && $message->provider === $model->provider
                        && in_array($message->providerThinkingLevel, self::ANTHROPIC_EFFORTS, true)) {
                        $assistantLevels[count($out)] = $message->providerThinkingLevel;
                    }

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

        $flushPendingSystemMessages();
        $this->cacheLastUserBlock($out, $cacheControl);

        if ($activeEffort === null) {
            return $out;
        }

        $messages = [];

        foreach ($out as $index => $message) {
            if (isset($assistantLevels[$index])) {
                $messages[] = ['role' => 'system', 'content' => [], 'output_config' => ['effort' => $assistantLevels[$index]]];
            }

            $messages[] = $message;
        }

        $messages[] = ['role' => 'system', 'content' => [], 'output_config' => ['effort' => $activeEffort]];

        return $messages;
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
    private function assistantBlocks(AssistantMessage $message, bool $isOAuth = false, bool $allowEmptySignature = false): array
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
                // Withheld reasoning goes back as Anthropic sent it: the encrypted payload, under
                // its own type. `TransformMessages` has already dropped it if this is not the
                // model that wrote it, which is the only one that can read it.
                if ($content->redacted === true) {
                    $blocks[] = ['type' => 'redacted_thinking', 'data' => $content->thinkingSignature ?? ''];

                    continue;
                }

                $hasThinkingSignature = $content->thinkingSignature !== null && trim($content->thinkingSignature) !== '';

                // Upstream skips a block only when it has neither thinking nor a signature: a
                // signed block with empty thinking (what `thinkingDisplay: omitted` sends back)
                // still goes, signature and all.
                if (trim($content->thinking) === '' && !$hasThinkingSignature) {
                    continue;
                }

                // Thinking with no signature — an aborted stream leaves that behind — is
                // rejected by the API, and sending it as <thinking> text teaches the model
                // to imitate the tags. Plain text keeps the content and neither problem. A model
                // whose compat says `allowEmptySignature` (an endpoint that emits and accepts empty
                // signatures) gets it back as thinking with `signature: ""`, as upstream sends it.
                $blocks[] = match (true) {
                    $hasThinkingSignature => [
                        'type' => 'thinking',
                        'thinking' => Utf8::sanitize($content->thinking),
                        'signature' => $content->thinkingSignature,
                    ],
                    $allowEmptySignature => ['type' => 'thinking', 'thinking' => Utf8::sanitize($content->thinking), 'signature' => ''],
                    default => ['type' => 'text', 'text' => Utf8::sanitize($content->thinking)],
                };

                continue;
            }

            if ($content instanceof ToolCall) {
                $blocks[] = [
                    'type' => 'tool_use',
                    'id' => $content->id,
                    // The name the model saw it declared under, or it answers "unknown tool".
                    'name' => $isOAuth ? ClaudeCode::nameOut($content->name) : $content->name,
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
            'tool_use_id' => $message->toolCallId,
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

    /**
     * Upstream's `normalizeToolCallId()`: another model's call id, made into one Anthropic takes.
     *
     * Handed to `TransformMessages`, so it reaches only calls from some other model — this
     * model's own ids already fit — and the result addressed to the call is renamed with it.
     * Anything outside `[a-zA-Z0-9_-]` becomes `_`, then it is cut to 64.
     */
    private static function normalizeToolCallId(string $id): string
    {
        return substr((string) preg_replace(self::ID_PATTERN, '_', $id), 0, self::MAX_ID_LENGTH);
    }

    /**
     * Mark the end of the conversation so the prefix can be cached next turn — with upstream's
     * `cacheControl`, so `cacheRetention: none` marks nothing and `long` marks it for an hour. The
     * last message is a user turn or, since a held system update goes at the end, a system one;
     * its last block is marked when it is text, an image, a result or a tool change.
     *
     * @param list<array<string, mixed>> $messages
     * @param array<string, string>|null $cacheControl
     */
    private function cacheLastUserBlock(array &$messages, ?array $cacheControl): void
    {
        $last = count($messages) - 1;

        if ($cacheControl === null || $last < 0 || !in_array($messages[$last]['role'], ['user', 'system'], true) || !is_array($messages[$last]['content'])) {
            return;
        }

        $blocks = $messages[$last]['content'];
        $lastBlock = count($blocks) - 1;

        if ($lastBlock >= 0 && in_array($blocks[$lastBlock]['type'], ['text', 'image', 'tool_result', 'tool_addition', 'tool_removal'], true)) {
            $blocks[$lastBlock]['cache_control'] = $cacheControl;
            $messages[$last]['content'] = $blocks;
        }
    }
}
