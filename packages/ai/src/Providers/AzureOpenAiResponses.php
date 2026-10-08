<?php

declare(strict_types=1);

namespace Pig\Ai\Providers;

use Pig\Ai\DoneEvent;
use Pig\Ai\ErrorEvent;
use Pig\Ai\Http\HttpClient;
use Pig\Ai\Http\Request;
use Pig\Ai\Http\Response;
use Pig\Ai\Model;
use Pig\Ai\OpenAiCompat;
use Pig\Ai\ProviderError;
use Pig\Ai\StartEvent;
use Pig\Ai\StopReason;
use Pig\Ai\StreamOptions;
use Pig\Ai\TranscriptContext;
use Pig\Ai\Utils\AssistantMessageEventStream;
use Pig\Ai\Utils\ConstrainedSampling;
use Pig\Ai\Utils\ErrorBody;
use Pig\Ai\Utils\Headers;
use Pig\Ai\Utils\PigUserAgent;
use Pig\Ai\Utils\ProviderRetry;
use Pig\Ai\Utils\SdkHeaders;
use Pig\Ai\Utils\Transcript;
use Pig\Async\Async;
use Throwable;

/**
 * The Responses API on an Azure OpenAI resource — upstream's `api/azure-openai-responses.ts`.
 *
 * The conversation and the stream are `OpenAiResponsesShared`'s, as they are `OpenAiResponses`'.
 * What is Azure's own:
 *
 * - **Where it goes** is worked out per request (`AzureOpenAiConfig`): the catalogue's Azure rows
 *   have no base URL, because every Azure customer has their own resource. Upstream builds an
 *   `AzureOpenAI` client over it, and that client sends `POST <baseURL>/responses?api-version=<v>`
 *   — the Responses API is not one of the SDK's deployment-scoped paths, so no `/deployments/…`.
 * - **The deployment is the model.** `params.model` is `resolveDeploymentName()`, which is the
 *   model's id unless something maps it, so a resource that deployed `gpt-5.4` as `prod` is sent
 *   `"model": "prod"` and priced as gpt-5.4.
 * - **The key is `api-key`, not `Authorization`** — `AzureOpenAI.authHeaders()`.
 * - **Strict mode is on unless the compat says not**, where `openai-responses` has it off
 *   (`supportsStrictMode: model.compat?.supportsStrictMode ?? true`); `prompt_cache_key` goes out
 *   whatever the cache retention; there is no service tier, no ChatGPT sign-in, no `prompt_cache_*`
 *   retention fields and no Copilot or session-affinity headers.
 *
 * Errors read `Azure OpenAI API error (<status>): …`.
 */
final class AzureOpenAiResponses
{
    /** Upstream's `AZURE_TOOL_CALL_PROVIDERS`. */
    private const array AZURE_TOOL_CALL_PROVIDERS = ['openai', 'openai-codex', 'opencode', 'azure'];

    /** Upstream's `OPENAI_RESPONSES_MIN_OUTPUT_TOKENS`: "OpenAI Responses rejects max_output_tokens below 16". */
    private const int OPENAI_RESPONSES_MIN_OUTPUT_TOKENS = 16;

    public function __construct(private readonly HttpClient $http = new HttpClient())
    {
    }

    /** Returns at once; the response fills in as it arrives. */
    public function stream(Model $model, TranscriptContext $context, ?AzureOpenAiResponsesOptions $options = null): AssistantMessageEventStream
    {
        $stream = new AssistantMessageEventStream();
        $compat = self::compat($model);
        $normalizedContext = Transcript::resolveTranscript($context, $compat?->supportsMidConvoSystemMessages ?? false);

        Async::spawn(function () use ($stream, $model, $normalizedContext, $options): void {
            $this->run($stream, $model, $normalizedContext, $options);
        });

        return $stream;
    }

    private function run(AssistantMessageEventStream $stream, Model $model, TranscriptContext $context, ?AzureOpenAiResponsesOptions $options): void
    {
        $deploymentName = AzureOpenAiConfig::resolveDeploymentName($model, $options);
        $builder = new AssistantMessageBuilder($model);
        $builder->setStopReason(StopReason::Pending);
        $signal = $options?->signal;

        try {
            $apiKey = $options?->apiKey;

            if ($apiKey === null || $apiKey === '') {
                throw new ProviderError("No API key for provider: {$model->provider}");
            }

            // `createClient()` — resolved before anything else, so an endpoint nobody configured is
            // this turn's error rather than a request to nowhere.
            $config = AzureOpenAiConfig::resolveAzureConfig($model, $options);
            $grammar = ConstrainedSampling::createGrammarToolInputProperties(
                Transcript::getDeclaredTools($context->messages),
                self::compat($model)?->grammarTools ?? false,
            );
            $params = self::buildParams($model, $context, $options, $deploymentName, $grammar);
            $nextParams = $options?->onPayload !== null ? ($options->onPayload)($params, $model) : null;

            if ($nextParams !== null) {
                $params = (array) $nextParams;
            }

            $timeoutMs = $options?->timeoutMs ?? SdkHeaders::STAINLESS_DEFAULT_TIMEOUT_MS;
            $response = ProviderRetry::retryProviderRequest(
                fn (): Response => SdkRequest::send(
                    $this->http,
                    self::request($model, $options, $config, $params, $apiKey, $timeoutMs),
                    $signal,
                    $timeoutMs,
                    static fn (int $status, string $body): array => self::explain($status, $body),
                ),
                $options?->maxRetries,
                $options?->maxRetryDelayMs,
                $signal,
            );

            if ($options?->onResponse !== null) {
                ($options->onResponse)(['status' => $response->status, 'headers' => $response->headers], $model);
            }

            $stream->push(new StartEvent($builder->snapshot()));

            OpenAiResponsesShared::processResponsesStream(OpenAiResponses::events($response->body), $builder, $stream, $model, [
                'onProviderStreamEvent' => $options?->onProviderStreamEvent,
                'grammarToolInputProperties' => $grammar,
            ]);

            if ($signal?->aborted() ?? false) {
                throw new ProviderError('Request was aborted');
            }

            if ($builder->stopReason() === StopReason::Pending) {
                throw new ProviderError('Azure OpenAI Responses stream ended without a stop reason');
            }

            $stop = $builder->stopReason();

            if ($stop === StopReason::Aborted || $stop === StopReason::Error) {
                $message = $builder->errorMessage();

                throw new ProviderError($message !== null && $message !== '' ? $message : 'An unknown error occurred');
            }

            $message = $builder->snapshot();
            $stream->push(new DoneEvent($message->stopReason, $message));
            $stream->end();
        } catch (Throwable $error) {
            // Upstream's `formatAzureOpenAIError()`: `formatProviderError(normalizeProviderError(error),
            // "Azure OpenAI API error")` — a refused request's status and body (`explain()`), any other
            // error's message as it is.
            $builder->fail(SdkRequest::errorMessage($error), $signal?->aborted() ?? false);
            $failed = $builder->snapshot();
            $stream->push(new ErrorEvent($failed->stopReason, $failed));
            $stream->end();
        }
    }

    /**
     * The `openai` SDK's `APIError` for a refused request, and what upstream's catch makes of it.
     *
     * @return array{0: string, 1: string}
     */
    private static function explain(int $status, string $body): array
    {
        $norm = ErrorBody::openAiApiError($status, $body);

        return [$norm['message'], ErrorBody::format($norm, 'Azure OpenAI API error')];
    }

    /**
     * One attempt's request, as `client.responses.create(params)` builds it on upstream's
     * `AzureOpenAI` client: `POST <baseURL>/responses?api-version=<v>` (the client's `defaultQuery`),
     * the SDK's headers with `api-key` for the auth, then `defaultHeaders` — `User-Agent: pig (…)`,
     * the model's headers, `options.headers` last.
     *
     * @param array{baseUrl: string, apiVersion: string} $config
     * @param array<string, mixed> $params
     */
    private static function request(Model $model, ?AzureOpenAiResponsesOptions $options, array $config, array $params, string $apiKey, int $timeoutMs): Request
    {
        $headers = ['User-Agent' => PigUserAgent::get()];

        foreach ($model->headers as $name => $value) {
            $headers[(string) $name] = $value;
        }

        foreach ($options?->headers ?? [] as $name => $value) {
            $headers[(string) $name] = $value;
        }

        $built = Headers::build(
            [
                ...SdkHeaders::stainless('OpenAI/JS ' . SdkHeaders::OPENAI_SDK_VERSION, SdkHeaders::OPENAI_SDK_VERSION, $timeoutMs),
                'OpenAI-Organization' => StreamOptions::providerEnvValue('OPENAI_ORG_ID', null),
                'OpenAI-Project' => StreamOptions::providerEnvValue('OPENAI_PROJECT_ID', null),
            ],
            // `AzureOpenAI.authHeaders()`: `{"api-key": this.apiKey}` for a static key.
            ['api-key' => $apiKey],
            $headers,
            ['content-type' => 'application/json'],
        );

        return new Request(
            'POST',
            rtrim($config['baseUrl'], '/') . '/responses?' . http_build_query(['api-version' => $config['apiVersion']], '', '&', PHP_QUERY_RFC3986),
            $built['values'],
            self::encode($params),
        );
    }

    /**
     * Upstream's `buildParams(model, context, options, deploymentName, grammarToolInputProperties)`.
     *
     * @param array<string, string> $grammar
     * @return array<string, mixed>
     */
    private static function buildParams(Model $model, TranscriptContext $context, ?AzureOpenAiResponsesOptions $options, string $deploymentName, array $grammar): array
    {
        $compat = self::compat($model);
        $supportsAdditionalTools = $compat?->supportsAdditionalTools ?? false;
        $supportsToolSearch = $compat?->supportsToolSearch ?? false;
        $transcriptTools = Transcript::resolveTranscriptTools($context->messages, $supportsAdditionalTools || $supportsToolSearch);
        $toolOptions = [
            'supportsStrictMode' => $compat?->strictMode ?? true,
            'supportsOpenAIGrammarTools' => $compat?->grammarTools ?? false,
        ];
        $messages = OpenAiResponsesShared::convertResponsesMessages($model, $context, self::AZURE_TOOL_CALL_PROVIDERS, [
            'grammarToolInputProperties' => $grammar,
            'supportsMidConvoSystemMessages' => $compat?->supportsMidConvoSystemMessages ?? false,
            'supportsAdditionalTools' => $supportsAdditionalTools,
            'supportsToolSearch' => $supportsToolSearch,
            'toolOptions' => $toolOptions,
        ]);

        $params = [
            'model' => $deploymentName,
            'input' => $messages,
            'stream' => true,
        ];

        // `prompt_cache_key: clampOpenAIPromptCacheKey(options?.sessionId)` — whatever the cache
        // retention says, and left out of the JSON when there is no session.
        $promptCacheKey = OpenAiPromptCache::clampOpenAIPromptCacheKey($options?->sessionId);

        if ($promptCacheKey !== null) {
            $params['prompt_cache_key'] = $promptCacheKey;
        }

        $params['store'] = false;

        // `if (options?.maxTokens)` — a 0 sends nothing.
        if ($options?->maxTokens) {
            $params['max_output_tokens'] = max($options->maxTokens, self::OPENAI_RESPONSES_MIN_OUTPUT_TOKENS);
        }

        if ($options?->temperature !== null) {
            $params['temperature'] = $options->temperature;
        }

        if ($transcriptTools['requestTools'] !== []) {
            $params['tools'] = OpenAiResponsesShared::convertResponsesTools($transcriptTools['requestTools'], $toolOptions);
        }

        if ($options?->toolChoice !== null) {
            $params['tool_choice'] = $options->toolChoice;
        }

        // `options?.reasoningEffort ?? (options?.reasoningSummary ? "medium" : undefined)`.
        $reasoningSummary = $options?->reasoningSummary;
        $hasSummary = $reasoningSummary !== null && $reasoningSummary !== '';
        $level = $options?->reasoningEffort?->value;
        $reasoningEffort = $level ?? ($hasSummary ? 'medium' : null);

        if ($model->reasoning) {
            if ($reasoningEffort !== null) {
                $params['reasoning'] = [
                    // `options?.reasoningEffort ? (model.thinkingLevelMap?.[effort] ?? effort) : reasoningEffort`.
                    'effort' => $level !== null ? ($model->thinkingLevelMap[$level] ?? $level) : $reasoningEffort,
                    'summary' => $hasSummary ? $reasoningSummary : 'auto',
                ];
                $params['include'] = ['reasoning.encrypted_content'];
            } elseif ($model->hasThinkingLevel('off')) {
                // `else if (model.thinkingLevelMap?.off !== null)`: `{effort: map.off ?? "none"}`.
                $params['reasoning'] = ['effort' => $model->thinkingLevelMap['off'] ?? 'none'];
            }
        }

        // Upstream merges `resolveSamplingParams()` last; pig has no sampling parameters on a model
        // or a request, so there is nothing to merge.
        return $params;
    }

    /** The model's `OpenAiCompat` (upstream's `OpenAIResponsesCompat`), or null. */
    private static function compat(Model $model): ?OpenAiCompat
    {
        return $model->compat instanceof OpenAiCompat ? $model->compat : null;
    }

    /** @param array<string, mixed> $body */
    private static function encode(array $body): string
    {
        $json = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            throw new ProviderError('Cannot encode the request: ' . json_last_error_msg());
        }

        return $json;
    }
}
