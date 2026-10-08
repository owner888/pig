<?php

declare(strict_types=1);

namespace Pig\Ai\Providers;

use Pig\Ai\AnthropicCompat;
use Pig\Ai\Api;
use Pig\Ai\AssistantMessage;
use Pig\Ai\AssistantMessageDiagnostic;
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
use Pig\Ai\Utils\ConstrainedSampling;
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

    /** Upstream's `isAnthropicEffort()`: the effort names a managed-effort turn can be replayed with. */
    private const array ANTHROPIC_EFFORTS = ['low', 'medium', 'high', 'xhigh', 'max'];

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
        // Upstream: `providerThinkingLevel = model.compat?.supportsMidConvoEffort ? (options?.effort
        // ?? "high") : undefined`, on the output from the start — so a failed turn records it too.
        if (self::compat($model)?->supportsMidConvoEffort === true) {
            $builder->setProviderThinkingLevel($options?->effort ?? 'high');
        }
        $signal = $options?->signal;
        // A subscription token's tools go out under Claude Code's names and come back under
        // them too, so the name on a `tool_use` block is mapped back to the tool this request
        // declared. Decided once here, because `dispatch()` has no options to ask.
        $tools = ClaudeCode::isToken($options?->apiKey ?? '') ? $context->tools : [];
        // What the API says it rewrote in the request, from `message_start` or a later
        // `message_delta` — the last one wins, as upstream's single variable does.
        $transformations = null;

        try {
            $response = $this->http->send($this->request($model, $context, $options), $signal);

            if (!$response->isSuccessful()) {
                throw new ProviderError($this->explain($response->status, $response->body->all()));
            }

            $stream->push(new StartEvent($builder->snapshot()));
            $parser = new SseParser();

            foreach ($response->body as $chunk) {
                foreach ($parser->feed($chunk) as $event) {
                    $transformations = $this->dispatch($event, $model, $builder, $stream, $tools) ?? $transformations;
                }
            }

            // An abort mid-stream ends the body quietly; say so rather than reporting success.
            $signal?->throwIfAborted();

            // Upstream records these only on a turn that finished, after its own throw for an
            // error stop reason; pig does not throw for those (a refusal is an error message, not
            // an exception), so the same condition is spelled out.
            $stop = $builder->stopReason();
            if ($transformations !== null && $transformations !== [] && $stop !== StopReason::Error && $stop !== StopReason::Aborted) {
                $builder->addDiagnostic(self::inputTransformations($transformations));
            }

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
     * @param list<Tool> $tools the request's tools when they went out under Claude Code's names, else empty
     * @return list<mixed>|null the event's `input_transformations`, when it carried a list of them
     */
    private function dispatch(SseEvent $event, Model $model, AssistantMessageBuilder $builder, AssistantMessageEventStream $stream, array $tools = []): ?array
    {
        $data = json_decode($event->data, true);

        if (!is_array($data)) {
            return null;
        }

        match ($event->type) {
            'message_start' => $this->onMessageStart($data, $model, $builder),
            'content_block_start' => $this->onBlockStart($data, $builder, $stream, $tools),
            'content_block_delta' => $this->onBlockDelta($data, $builder, $stream),
            'content_block_stop' => $this->onBlockStop($data, $builder, $stream),
            'message_delta' => $this->onMessageDelta($data, $builder),
            // ping and message_stop carry nothing this port needs; an error arrives as a
            // non-2xx status or as a stream that stops, both handled by the caller.
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
            $builder->setStopReason($this->stopReason($reason));
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
        // Upstream's `createClient()` tries Copilot first: its Claude models speak this API, and
        // the key is a Copilot token sent as a bearer — never a Claude Code subscription, whatever
        // it happens to look like.
        $isCopilot = $model->provider === 'github-copilot';
        // A subscription token authenticates as Claude Code: a bearer header, Claude Code's
        // user agent, and the two betas in front — see `ClaudeCode`.
        $isOAuth = !$isCopilot && ClaudeCode::isToken($apiKey);

        $headers = [
            // Upstream's `createClient()` default headers, in all three of its arms (Copilot, a
            // subscription token, an API key): `accept` and `anthropic-dangerous-direct-browser-access:
            // true` — the SDK refuses to run in a browser without the second, and upstream sends it
            // from everywhere. The SDK adds the other two itself.
            'accept' => 'application/json',
            'anthropic-dangerous-direct-browser-access' => 'true',
            'content-type' => 'application/json',
            'anthropic-version' => self::VERSION,
            // Copilot: upstream's `authToken: apiKey`, which the SDK sends as a bearer.
            ...match (true) {
                $isCopilot => ['authorization' => 'Bearer ' . $apiKey],
                $isOAuth => ClaudeCode::headers($apiKey),
                default => ['x-api-key' => $apiKey],
            },
            ...$model->headers,
            // After the model's own, as upstream merges them (`model.headers, dynamicHeaders`):
            // `X-Initiator`, `Openai-Intent` and `Copilot-Vision-Request`, the same three the two
            // OpenAI providers send. Empty for every other provider.
            ...Copilot::headers($model, $context),
        ];

        // Upstream sends the betas as the request's `betas`, which the SDK writes as `anthropic-beta`
        // over any default header of that name — so one header, whatever case the model's own was
        // written in, and none at all when the list is empty.
        $headers = array_filter(
            $headers,
            static fn (string|int $name): bool => strtolower((string) $name) !== 'anthropic-beta',
            ARRAY_FILTER_USE_KEY,
        );
        $betas = $this->betaFeatures($model, $context, $isOAuth, $options);

        if ($betas !== []) {
            $headers['anthropic-beta'] = implode(',', $betas);
        }

        return new Request(
            'POST',
            $this->endpoint($model, $apiKey) . '/v1/messages',
            $headers,
            $this->encode($this->body($model, $context, $options, $isOAuth)),
        );
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
     * Not ported, because pig has neither feature: `server-side-fallback` (`allowedFallbackModels`)
     * and `inline-tools` (native mid-conversation tool changes). Upstream also reads `options.headers`;
     * pig's options carry no headers.
     *
     * @return list<string>
     */
    private function betaFeatures(Model $model, Context $context, bool $isOAuth, ?AnthropicOptions $options): array
    {
        $configured = false;
        $configuredFeatures = null;

        foreach ($model->headers as $name => $value) {
            if (strtolower((string) $name) === 'anthropic-beta') {
                $configured = true;
                $configuredFeatures = $value;
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
        if ($context->tools !== [] && !($compat?->supportsEagerToolInputStreaming ?? true)) {
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

        if ($compat?->supportsMidConvoEffort === true) {
            $features[] = self::MID_CONVERSATION_OUTPUT_CONFIG_BETA;
            $features[] = self::THINKING_BINDING_CONTROLS_BETA;
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
    private function body(Model $model, Context $context, ?AnthropicOptions $options, bool $isOAuth): array
    {
        $compat = self::compat($model);
        $midConvoEffort = $compat?->supportsMidConvoEffort === true;
        // Upstream's `activeEffort = options?.effort ?? "high"`, said to a managed-effort model in the
        // system message that closes the conversation.
        $activeEffort = $options?->effort ?? 'high';
        $body = [
            'model' => $model->id,
            'messages' => $this->messages($context, $model, $isOAuth, $midConvoEffort ? $activeEffort : null),
            'max_tokens' => $options?->maxTokens ?? intdiv($model->maxTokens, 3),
            'stream' => true,
        ];

        $system = $this->system($context, $isOAuth);

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

        if ($context->tools !== []) {
            // Upstream's `supportsStrictTools: model.compat?.supportsStrictTools ?? false`, which its
            // generated catalogue sets on every `anthropic` provider model (`Models` does the same),
            // and `supportsEagerToolInputStreaming ?? true`.
            $supportsStrictTools = $compat?->strictTools ?? false;
            $supportsEagerToolInputStreaming = $compat?->supportsEagerToolInputStreaming ?? true;
            $body['tools'] = array_map(
                fn (Tool $tool): array => $this->tool($tool, $isOAuth, $supportsEagerToolInputStreaming, $supportsStrictTools),
                $context->tools,
            );
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

        return $body;
    }

    /** @return list<array<string, mixed>> */
    private function system(Context $context, bool $isOAuth): array
    {
        $blocks = [];

        // An OAuth token is Claude Code's, and Anthropic requires the matching identity.
        if ($isOAuth) {
            $blocks[] = $this->cachedText(ClaudeCode::IDENTITY);
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
     * @return list<array<string, mixed>>
     */
    private function messages(Context $context, Model $model, bool $isOAuth = false, ?string $activeEffort = null): array
    {
        $out = [];
        // Upstream's `assistantLevels`: the index in `$out` of each such earlier turn, and its effort.
        $assistantLevels = [];
        $messages = TransformMessages::apply($context->messages, $model, self::normalizeToolCallId(...));
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
                $blocks = $this->assistantBlocks($message, $isOAuth);

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

        $this->cacheLastUserBlock($out);

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
    private function assistantBlocks(AssistantMessage $message, bool $isOAuth = false): array
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
