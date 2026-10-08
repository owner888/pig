<?php

declare(strict_types=1);

namespace Pig\Ai\Providers;

use Pig\Ai\DoneEvent;
use Pig\Ai\ErrorEvent;
use Pig\Ai\Http\HttpClient;
use Pig\Ai\Http\Response;
use Pig\Ai\Http\Request;
use Pig\Ai\Http\SseParser;
use Pig\Ai\Model;
use Pig\Ai\OpenAiCompat;
use Pig\Ai\Utils\Oauth\GithubCopilot;
use Pig\Ai\ProviderError;
use Pig\Ai\StartEvent;
use Pig\Ai\StopReason;
use Pig\Ai\TranscriptContext;
use Pig\Ai\Usage;
use Pig\Ai\Utils\AssistantMessageEventStream;
use Pig\Ai\Utils\ConstrainedSampling;
use Pig\Ai\Utils\ErrorBody;
use Pig\Ai\Utils\Headers;
use Pig\Ai\Utils\ProviderRetry;
use Pig\Ai\Utils\SdkHeaders;
use Pig\Ai\Utils\PigUserAgent;
use Pig\Ai\Utils\Transcript;
use Pig\Async\AbortError;
use Pig\Async\Async;
use Throwable;

/**
 * OpenAI's Responses API, streamed — what gpt-5 speaks.
 *
 * The third shape in three providers, and the one that is least like a chat. A response
 * is a list of *items* — a reasoning item, a message item, a function call — and the
 * stream says when each opens and closes, which makes the block boundaries easy after
 * `openai-completions`, where nothing says anything.
 *
 * The conversation it sends and the stream it reads are `OpenAiResponsesShared`, which Azure's
 * and the Codex backend's APIs share; this is the request: the endpoint, the headers, the body's
 * cache, reasoning and sign-in-with-ChatGPT fields.
 *
 * Ported from upstream's `api/openai-responses.ts`.
 */
final class OpenAiResponses
{
    /**
     * Upstream's `OPENAI_TOOL_CALL_PROVIDERS`: the providers whose `call_id|item_id` pair is kept
     * as a pair when it comes from another model. Copilot is not one of them.
     */
    private const array TOOL_CALL_PROVIDERS = ['openai', 'openai-codex', 'opencode'];

    /** Upstream's `OPENAI_RESPONSES_MIN_OUTPUT_TOKENS`: "OpenAI Responses rejects max_output_tokens below 16". */
    private const int MIN_OUTPUT_TOKENS = 16;

    /** Upstream's `CHATGPT_USAGE_URL`, appended to a Sign in with ChatGPT usage-limit error. */
    private const string CHATGPT_USAGE_URL = 'https://chatgpt.com/settings/usage';

    public function __construct(private readonly HttpClient $http = new HttpClient())
    {
    }

    /** Returns at once; the response fills in as it arrives. */
    public function stream(Model $model, TranscriptContext $context, ?OpenAiOptions $options = null): AssistantMessageEventStream
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
        ?OpenAiOptions $options,
    ): void {
        // Upstream's `normalizedContext = resolveTranscript(context, getCompat(model).
        // supportsMidConvoSystemMessages)`, which everything below is handed.
        $context = Transcript::resolveTranscript($context, self::compat($model)?->supportsMidConvoSystemMessages ?? false);
        $builder = new AssistantMessageBuilder($model);
        // Upstream's `stopReason: "pending"`: only a terminal event's status replaces it.
        $builder->setStopReason(StopReason::Pending);
        $signal = $options?->signal;

        try {
            // Upstream's `grammarToolInputProperties`: tool name => the property a grammar tool's raw
            // input lives in, for the tools this request sends as OpenAI custom tools. Read when a
            // `custom_tool_call` arrives and when one is replayed, so both directions agree.
            $grammar = ConstrainedSampling::createGrammarToolInputProperties(Transcript::getDeclaredTools($context->messages), self::supportsGrammarTools($model));
            // Upstream's `getClientApiKey()`: the key, or `"unused"` when an `authorization` or
            // `cf-aig-authorization` header in `options.headers` carries the auth.
            $apiKey = self::getClientApiKey($model->provider, $options?->apiKey, $options?->headers);

            // `buildParams()`, then `onPayload`, whose answer replaces the params.
            $params = $this->body($model, $context, $options, $grammar);
            $nextParams = $options?->onPayload !== null ? ($options->onPayload)($params, $model) : null;

            if ($nextParams !== null) {
                $params = (array) $nextParams;
            }

            // `retryProviderRequest(() => client.responses.create(params, {signal, timeout,
            // maxRetries: 0}).withResponse(), {maxRetries, maxRetryDelayMs, signal})`.
            $timeoutMs = $options?->timeoutMs ?? SdkHeaders::STAINLESS_DEFAULT_TIMEOUT_MS;
            $response = ProviderRetry::retryProviderRequest(
                fn (): Response => SdkRequest::send(
                    $this->http,
                    $this->request($model, $context, $options, $params, $apiKey, $timeoutMs),
                    $signal,
                    $timeoutMs,
                    fn (int $status, string $body): array => $this->explain($model, $status, $body),
                ),
                $options?->maxRetries,
                $options?->maxRetryDelayMs,
                $signal,
            );

            if ($options?->onResponse !== null) {
                ($options->onResponse)(['status' => $response->status, 'headers' => $response->headers], $model);
            }

            $stream->push(new StartEvent($builder->snapshot()));

            // `processResponsesStream(openaiStream, output, stream, model, {onProviderStreamEvent,
            // serviceTier, grammarToolInputProperties, applyServiceTierPricing})`. An abort mid-stream
            // ended the SDK's iteration quietly ("Abort errors … are non-fatal"), so its checks run
            // first, as upstream's do, and only then `stream()`'s "Request was aborted".
            OpenAiResponsesShared::processResponsesStream(self::events($response->body), $builder, $stream, $model, [
                'onProviderStreamEvent' => $options?->onProviderStreamEvent,
                'serviceTier' => $options?->serviceTier,
                'grammarToolInputProperties' => $grammar,
                'applyServiceTierPricing' => static fn (AssistantMessageBuilder $builder, ?string $serviceTier) => self::applyServiceTierPricing($builder, $model, $serviceTier),
            ]);

            if ($signal?->aborted() ?? false) {
                throw new ProviderError('Request was aborted');
            }

            if ($builder->stopReason() === StopReason::Pending) {
                throw new ProviderError('OpenAI Responses stream ended without a stop reason');
            }

            // Upstream's `stream()`: an `error` or `aborted` stop reason — a failed or cancelled
            // status, or an `incomplete` one for any reason but the output cap — ends the turn as
            // an error with the message `finalizeResponse()` recorded.
            $stop = $builder->stopReason();

            if ($stop === StopReason::Error || $stop === StopReason::Aborted) {
                $message = $builder->errorMessage();

                throw new ProviderError($message !== null && $message !== '' ? $message : 'An unknown error occurred');
            }

            $message = $builder->snapshot();
            $stream->push(new DoneEvent($message->stopReason, $message));
            $stream->end();
        } catch (Throwable $error) {
            // A provider never throws at its caller: the failure is the stream's result.
            $message = SdkRequest::errorMessage($error);

            // Upstream: "Sign in with ChatGPT shares the subscription's usage limit with other apps."
            // Its test is the formatted message, which for a refused request is the SDK's JSON of the
            // error object, code included — `explain()` writes the same.
            if (str_contains($message, 'subscription_sharing_usage_limit_exceeded')) {
                $message .= "\nCheck your ChatGPT usage: " . self::CHATGPT_USAGE_URL;
            }

            $builder->fail($message, $signal?->aborted() ?? false);
            $failed = $builder->snapshot();
            $stream->push(new ErrorEvent($failed->stopReason, $failed));
            $stream->end();
        }
    }

    /**
     * Upstream's `applyServiceTierPricing()`: the cost scaled by the tier the response reports, else
     * the one asked for — `flex` 0.5, `priority` and `fast` 2 (2.5 for gpt-5.5), anything else as
     * it is.
     */
    private static function applyServiceTierPricing(AssistantMessageBuilder $builder, Model $model, ?string $serviceTier): void
    {
        $multiplier = match ($serviceTier) {
            'flex' => 0.5,
            'priority', 'fast' => $model->id === 'gpt-5.5' ? 2.5 : 2.0,
            default => 1.0,
        };

        if ($multiplier === 1.0) {
            return;
        }

        $usage = $builder->snapshot()->usage;
        $cost = $usage->cost;
        $input = $cost->input * $multiplier;
        $output = $cost->output * $multiplier;
        $cacheRead = $cost->cacheRead * $multiplier;
        $cacheWrite = $cost->cacheWrite * $multiplier;

        $builder->setUsage(new Usage(
            $usage->input,
            $usage->output,
            $usage->cacheRead,
            $usage->cacheWrite,
            $usage->totalTokens,
            new \Pig\Ai\Cost($input, $output, $cacheRead, $cacheWrite, $input + $output + $cacheRead + $cacheWrite),
            $usage->reasoning,
            $usage->cacheWrite1h,
        ), priced: true);
    }
    /**
     * Upstream's `catch`: `formatProviderError(normalizeProviderError(error), "<OpenAI | provider> API
     * error")` over the `openai` SDK's `APIError` — `OpenAI API error (429): {"message":…,"code":…}`,
     * the error object as JSON, or `… (502): 502 <body text>` when the body is not a JSON error. It
     * used to be pig's own `<provider> returned <status>: <error.message>`, which dropped the code.
     */
    /** @return array{0: string, 1: string} the SDK `APIError` message, and the turn's `errorMessage` */
    private function explain(Model $model, int $status, string $body): array
    {
        $norm = ErrorBody::openAiApiError($status, $body);

        return [$norm['message'], ErrorBody::format($norm, ($model->provider === 'openai' ? 'OpenAI' : $model->provider) . ' API error')];
    }

    // ---- the request ---------------------------------------------------------------------

    /**
     * Upstream's `getClientApiKey()`.
     *
     * @param array<string, string|null>|null $headers
     */
    private static function getClientApiKey(string $provider, ?string $apiKey, ?array $headers): string
    {
        if ($apiKey !== null && $apiKey !== '') {
            return $apiKey;
        }

        if (Headers::has($headers, 'authorization') || Headers::has($headers, 'cf-aig-authorization')) {
            return 'unused';
        }

        throw new ProviderError("No API key for provider: {$provider}");
    }

    /**
     * The `openai` SDK's own iteration over the body: an abort while it is being read ends the
     * stream quietly ("Abort errors … are non-fatal").
     *
     * @param iterable<string> $body
     * @return \Generator<int, string>
     */
    private static function untilAborted(iterable $body): \Generator
    {
        try {
            foreach ($body as $chunk) {
                yield $chunk;
            }
        } catch (AbortError) {
            return;
        }
    }

    /**
     * The decoded events of the body, as the `openai` SDK's `Stream` yields them: the SSE parsed,
     * each event's JSON read (`ErrorBody::openAiStreamEvent()`, which also raises an error event),
     * and the completion sentinel `[DONE]` — `if (sse.data === '[DONE]') break` — the end of the
     * stream, whatever follows, before the JSON parse that would refuse it.
     *
     * @param iterable<string> $body
     * @return \Generator<int, array<string, mixed>>
     */
    public static function events(iterable $body): \Generator
    {
        $parser = new SseParser();

        foreach (self::untilAborted($body) as $chunk) {
            foreach ($parser->feed($chunk) as $event) {
                if ($event->data === '[DONE]') {
                    return;
                }

                yield ErrorBody::openAiStreamEvent($event->type, $event->data);
            }
        }
    }

    /**
     * One attempt's request, as `client.responses.create(params)` builds it with upstream's
     * `createClient()`: `POST <baseURL>/responses` and the `openai` SDK's headers
     * (`OpenAiCompletions::sdkHeaders()`) over upstream's `defaultHeaders` — `User-Agent: pig (…)`,
     * the model's headers, Copilot's, the session ones, and `options.headers` last.
     *
     * @param array<string, mixed> $params
     */
    private function request(Model $model, TranscriptContext $context, ?OpenAiOptions $options, array $params, string $apiKey, int $timeoutMs): Request
    {
        // Upstream's `{"User-Agent": getPiUserAgent(), ...model.headers}`, then Copilot's: its models
        // speak this API, so this is the provider that has to send them. See `Copilot`.
        $headers = ['User-Agent' => PigUserAgent::get()];

        foreach ([$model->headers, Copilot::headers($model, $context)] as $source) {
            foreach ($source as $name => $value) {
                $headers[(string) $name] = $value;
            }
        }

        // Upstream's `createClient()`: the session id, when caching is on (`cacheSessionId`), as the
        // compat's `sessionAffinityFormat` names it — `x-session-id` for OpenRouter; otherwise
        // `x-client-request-id`, and `session_id` too for the `openai` format. After the model's
        // and Copilot's headers, as upstream assigns them.
        $sessionId = $options?->resolvedCacheRetention() === 'none' ? null : $options?->sessionId;

        if ($sessionId !== null && $sessionId !== '') {
            $format = self::compat($model)?->sessionAffinityFormat
                ?? ($model->provider === 'openrouter' || str_contains($model->baseUrl, 'openrouter.ai') ? 'openrouter' : 'openai');

            if ($format === 'openrouter') {
                $headers['x-session-id'] = $sessionId;
            } else {
                if ($format === 'openai') {
                    $headers['session_id'] = $sessionId;
                }

                $headers['x-client-request-id'] = $sessionId;
            }
        }

        // "Merge options headers last so they can override defaults".
        foreach ($options?->headers ?? [] as $name => $value) {
            $headers[(string) $name] = $value;
        }

        return new Request(
            'POST',
            $this->endpoint($model, $options?->apiKey, '/responses'),
            OpenAiCompletions::sdkHeaders($apiKey, $headers, $timeoutMs),
            $this->encode($params),
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
    private function body(Model $model, TranscriptContext $context, ?OpenAiOptions $options, array $grammar = []): array
    {
        $compat = self::compat($model);
        // Upstream's `resolveTranscriptTools(context.messages, compat.supportsAdditionalTools ||
        // compat.supportsToolSearch)`: where later tools can be loaded in place, the request's
        // `tools` is the initial set; otherwise it is the current one.
        $transcriptTools = Transcript::resolveTranscriptTools(
            $context->messages,
            ($compat?->supportsAdditionalTools ?? false) || ($compat?->supportsToolSearch ?? false),
        );
        $toolOptions = [
            // `supportsStrictMode: model.compat?.supportsStrictMode ?? false` — on for OpenAI's own
            // models, which carry it in their `compat` (`Models`), and off for every other endpoint
            // of this API unless its `compat` says so.
            'supportsStrictMode' => $compat?->strictMode ?? false,
            'supportsOpenAIGrammarTools' => self::supportsGrammarTools($model),
        ];
        $input = OpenAiResponsesShared::convertResponsesMessages($model, $context, self::TOOL_CALL_PROVIDERS, [
            'grammarToolInputProperties' => $grammar,
            'supportsMidConvoSystemMessages' => $compat?->supportsMidConvoSystemMessages ?? false,
            'supportsAdditionalTools' => $compat?->supportsAdditionalTools ?? false,
            'supportsToolSearch' => $compat?->supportsToolSearch ?? false,
            'toolOptions' => $toolOptions,
        ]);
        $cacheRetention = ($options ?? new OpenAiOptions())->resolvedCacheRetention();
        $supportsLongCacheRetention = $compat?->supportsLongCacheRetention ?? true;
        $supportsExplicitPromptCacheMode = $compat?->supportsExplicitPromptCacheMode ?? false;
        // Upstream's `isChatGPTSignIn()`: "Sign in with ChatGPT rejects these request fields" —
        // OpenAI's own endpoint with a credential that is not an `sk-` API key.
        $omitUnsupportedFields = $model->provider === 'openai'
            && $model->baseUrl === 'https://api.openai.com/v1'
            && $options?->apiKey !== null
            && !str_starts_with($options->apiKey, 'sk-');

        $body = [
            'model' => $model->id,
            'input' => $input,
            'stream' => true,
        ];

        // Upstream: `prompt_cache_key: cacheRetention === "none" ? undefined :
        // clampOpenAIPromptCacheKey(options?.sessionId)` — the session id, cut to 64 code points.
        if ($cacheRetention !== 'none' && $options?->sessionId !== null) {
            $body['prompt_cache_key'] = OpenAiPromptCache::clampOpenAIPromptCacheKey($options->sessionId);
        }

        // `getPromptCacheRetention()`: `24h` for `long`, where the model takes long retention and
        // has no explicit cache mode.
        if (!$omitUnsupportedFields && $cacheRetention === 'long' && $supportsLongCacheRetention && !$supportsExplicitPromptCacheMode) {
            $body['prompt_cache_retention'] = '24h';
        }

        // `getPromptCacheOptions()`, for the GPT-5.6-and-later models that take it: `none` asks for
        // the explicit mode (nothing cached unless asked), `long` for a 30-minute TTL.
        if (!$omitUnsupportedFields && $supportsExplicitPromptCacheMode) {
            if ($cacheRetention === 'none') {
                $body['prompt_cache_options'] = ['mode' => 'explicit'];
            } elseif ($cacheRetention === 'long' && $supportsLongCacheRetention) {
                $body['prompt_cache_options'] = ['ttl' => '30m'];
            }
        }

        // Upstream's `store: false` on every request: the conversation is replayed whole each turn
        // (reasoning items included), so nothing needs keeping on OpenAI's side.
        $body['store'] = false;

        // `if (options?.maxTokens && compat.supportsMaxOutputTokens && !omitUnsupportedFields)
        // params.max_output_tokens = Math.max(options.maxTokens, 16)` — the API refuses less than 16,
        // and a 0 sends nothing.
        if ($options?->maxTokens && ($compat?->supportsMaxOutputTokens ?? true) && !$omitUnsupportedFields) {
            $body['max_output_tokens'] = max($options->maxTokens, self::MIN_OUTPUT_TOKENS);
        }

        if ($options?->temperature !== null && !$omitUnsupportedFields) {
            $body['temperature'] = $options->temperature;
        }

        if ($options?->serviceTier !== null) {
            $body['service_tier'] = $options->serviceTier;
        }

        if ($transcriptTools['requestTools'] !== []) {
            $body['tools'] = OpenAiResponsesShared::convertResponsesTools($transcriptTools['requestTools'], $toolOptions);
        }

        if ($options?->toolChoice !== null) {
            $body['tool_choice'] = $options->toolChoice;
        }

        // Upstream: `options?.reasoningEffort ?? (options?.reasoningSummary ? "medium" : undefined)` —
        // asking for a summary alone asks for `medium` effort, sent as it is rather than mapped.
        $reasoningSummary = $options?->reasoningSummary;
        $hasSummary = $reasoningSummary !== null && $reasoningSummary !== '';

        // Upstream's `samplingParams` are merged into the request last here
        // (`resolveSamplingParams()`); pig has no sampling parameters on a model or a request, so
        // there is nothing to merge.
        if (!$model->reasoning) {
            return $body;
        }

        if ($options?->reasoning !== null || $hasSummary) {
            // Upstream: `model.thinkingLevelMap?.[options.reasoningEffort] ?? options.reasoningEffort`
            // — what the model calls the level, its own name when the map says nothing (`??`, so a
            // null entry also sends the name; `Stream::simple()` has clamped such a level away).
            $level = $options?->reasoning?->value;
            $body['reasoning'] = [
                'effort' => $level !== null ? ($model->thinkingLevelMap[$level] ?? $level) : 'medium',
                // `options?.reasoningSummary || "auto"`.
                'summary' => $hasSummary ? $reasoningSummary : 'auto',
            ];

            // Without this the encrypted reasoning never comes back, and a thinking block
            // with nothing to replay is a thinking block that costs a turn to rebuild.
            $body['include'] = ['reasoning.encrypted_content'];
        } elseif ($model->provider !== 'github-copilot' && $model->hasThinkingLevel('off')) {
            // Upstream's off arm: `model.provider !== "github-copilot" && model.thinkingLevelMap?.off
            // !== null` sends `reasoning: {effort: map.off ?? "none"}` — `none` for gpt-5.1 and later,
            // nothing for a model whose map says it cannot be switched off (`off: null`, every
            // other gpt-5). This replaces the `# Juice: 0 !important` developer message pig used to
            // append for every gpt-5, which upstream no longer sends.
            $body['reasoning'] = ['effort' => $model->thinkingLevelMap['off'] ?? 'none'];
        }

        if ($model->provider === 'xai') {
            $body['include'] = ['reasoning.encrypted_content'];
        }

        return $body;
    }

    /** The model's `OpenAiCompat`, or null — a compat of another API's type says nothing here. */
    private static function compat(Model $model): ?OpenAiCompat
    {
        return $model->compat instanceof OpenAiCompat ? $model->compat : null;
    }

    /**
     * Upstream's `supportsOpenAIGrammarTools: model.compat?.supportsOpenAIGrammarTools ?? false` —
     * on for `gpt-5`-and-later models of `openai` and Copilot, which carry it in their `compat`
     * (`Models`), and off for every other endpoint unless its `compat` says so.
     */
    private static function supportsGrammarTools(Model $model): bool
    {
        return $model->compat instanceof OpenAiCompat && ($model->compat->grammarTools ?? false);
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
