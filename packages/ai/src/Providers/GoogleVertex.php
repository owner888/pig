<?php

declare(strict_types=1);

namespace Pig\Ai\Providers;

use Pig\Ai\DoneEvent;
use Pig\Ai\ErrorEvent;
use Pig\Ai\Http\HttpClient;
use Pig\Ai\Http\Request;
use Pig\Ai\Http\Response;
use Pig\Ai\Model;
use Pig\Ai\ProviderError;
use Pig\Ai\StartEvent;
use Pig\Ai\StopReason;
use Pig\Ai\StreamOptions;
use Pig\Ai\TranscriptContext;
use Pig\Ai\Utils\AssistantMessageEventStream;
use Pig\Ai\Utils\ErrorBody;
use Pig\Ai\Utils\GaxiosError;
use Pig\Ai\Utils\GoogleAuth;
use Pig\Ai\Utils\Headers;
use Pig\Ai\Utils\PigUserAgent;
use Pig\Ai\Utils\ProviderHttpError;
use Pig\Ai\Utils\ProviderRetry;
use Pig\Ai\Utils\SdkHeaders;
use Pig\Ai\Utils\Text;
use Pig\Ai\Utils\Transcript;
use Pig\Ai\Utils\Utf8;
use Pig\Async\AbortSignal;
use Pig\Async\Async;
use Throwable;

/**
 * Gemini on Vertex AI, streamed — upstream's `api/google-vertex.ts`.
 *
 * The same `@google/genai` SDK as `Google`, built with `vertexai: true`, so the chunks, the parts, the
 * stop reasons and the usage are Gemini's and are read by the same code (`GoogleShared`,
 * `Google::sdkChunks()`). What is Vertex's own is where it sends and how it authenticates:
 *
 * - **an API key** (a Vertex express key, or `GOOGLE_CLOUD_API_KEY`) goes to
 *   `https://aiplatform.googleapis.com/v1/publishers/google/models/<id>:streamGenerateContent` as
 *   `x-goog-api-key`; a placeholder like `<authenticated>` or `gcp-vertex-credentials` is no key;
 * - **otherwise Application Default Credentials** (`Utils\GoogleAuth`) with a project and a location —
 *   the options, else `GOOGLE_CLOUD_PROJECT`/`GCLOUD_PROJECT` and `GOOGLE_CLOUD_LOCATION`, else an
 *   error naming them — to `https://<location>-aiplatform.googleapis.com/v1/projects/<project>/locations/<location>/publishers/google/models/<id>:streamGenerateContent`
 *   (`aiplatform.googleapis.com` for `global`, `aiplatform.<us|eu>.rep.googleapis.com` for the two
 *   multi-regions) with the token as `authorization`;
 * - **a model `baseUrl` without `{location}`** in it replaces the host as a collection — no project path
 *   — and drops the `v1` when it already carries a version.
 */
final class GoogleVertex
{
    /** Upstream's `API_VERSION`. */
    private const string API_VERSION = 'v1';

    /** Upstream's `GCP_VERTEX_CREDENTIALS_MARKER`. */
    private const string GCP_VERTEX_CREDENTIALS_MARKER = 'gcp-vertex-credentials';

    /** `@google/genai`'s `MULTI_REGIONAL_LOCATIONS`. */
    private const array MULTI_REGIONAL_LOCATIONS = ['us', 'eu'];

    /**
     * @param \Closure(?string): GoogleAuth|null $auth how ADC is reached for a key file — the seam a
     *        test uses to point the token endpoint at itself
     */
    public function __construct(
        private readonly HttpClient $http = new HttpClient(),
        private readonly ?\Closure $auth = null,
    ) {
    }

    /** Returns at once; the response fills in as it arrives. */
    public function stream(Model $model, TranscriptContext $context, ?GoogleVertexOptions $options = null): AssistantMessageEventStream
    {
        $stream = new AssistantMessageEventStream();

        Async::spawn(function () use ($stream, $model, $context, $options): void {
            $this->run($stream, $model, $context, $options);
        });

        return $stream;
    }

    private function run(AssistantMessageEventStream $stream, Model $model, TranscriptContext $context, ?GoogleVertexOptions $options): void
    {
        $normalizedContext = Transcript::collapseSystemMessages($context);
        $builder = new AssistantMessageBuilder($model);
        $builder->setStopReason(StopReason::Pending);
        $signal = $options?->signal;
        $open = null;

        try {
            $apiKey = self::resolveApiKey($options);
            // "Create the client using either a Vertex API key, if provided, or ADC with project and location"
            $client = $apiKey !== null
                ? ['apiKey' => $apiKey, 'project' => null, 'location' => null]
                : ['apiKey' => null, 'project' => self::resolveProject($options), 'location' => self::resolveLocation($options)];
            $params = self::buildParams($model, $normalizedContext, $options);
            $nextParams = $options?->onPayload !== null ? ($options->onPayload)($params, $model) : null;

            if ($nextParams !== null) {
                $params = (array) $nextParams;
            }

            $request = $this->request($model, $client, $options, $params);
            $keyFilename = StreamOptions::providerEnvValue('GOOGLE_APPLICATION_CREDENTIALS', $options?->env);

            $response = ProviderRetry::retryProviderRequest(
                fn (): Response => $this->send($request, $client['apiKey'] === null, $keyFilename, $signal),
                $options?->maxRetries,
                $options?->maxRetryDelayMs,
                $signal,
            );

            $stream->push(new StartEvent($builder->snapshot()));

            foreach (Google::sdkChunks($response->body) as $data) {
                if ($options?->onProviderStreamEvent !== null) {
                    ($options->onProviderStreamEvent)($data, $model);
                }

                if (is_array($data)) {
                    // "Vertex uses the same @google/genai GenerateContentResponse type as Gemini.
                    // responseId is documented there as an output-only identifier for each response."
                    if ($builder->responseId() === null && is_string($data['responseId'] ?? null) && $data['responseId'] !== '') {
                        $builder->setResponseId($data['responseId']);
                    }

                    $open = GoogleShared::onChunk($data, $builder, $stream, $open);
                }
            }

            GoogleShared::close($builder, $stream, $open);

            if ($signal?->aborted() ?? false) {
                throw new ProviderError('Request was aborted');
            }

            if ($builder->stopReason() === StopReason::Pending) {
                throw new ProviderError('Google Vertex stream ended without a finish reason');
            }

            if ($builder->stopReason() === StopReason::Error || $builder->stopReason() === StopReason::Aborted) {
                $raw = $builder->rawStopReason();

                throw new ProviderError($raw !== null && $raw !== '' ? "Provider stopped with: {$raw}" : 'An unknown error occurred');
            }

            $message = $builder->snapshot();
            $stream->push(new DoneEvent($message->stopReason, $message));
            $stream->end();
        } catch (Throwable $error) {
            $builder->fail(SdkRequest::errorMessage($error), $signal?->aborted() ?? false);
            $failed = $builder->snapshot();
            $stream->push(new ErrorEvent($failed->stopReason, $failed));
            $stream->end();
        }
    }

    /**
     * One attempt of the SDK's `requestStream()`: the auth headers first (ADC's token, fetched inside
     * the call as the SDK fetches it), then the fetch. A token failure that carries a status is a
     * provider error to `retryGoogleRequest()`, as gaxios's `GaxiosError` is there.
     *
     * @param array{0: string, 1: array<string, string>, 2: string} $request url, headers, body
     */
    private function send(array $request, bool $adc, ?string $keyFilename, ?AbortSignal $signal): Response
    {
        [$url, $headers, $body] = $request;

        if ($adc) {
            try {
                $authHeaders = $this->auth !== null ? ($this->auth)($keyFilename)->requestHeaders($signal) : (new GoogleAuth($keyFilename))->requestHeaders($signal);
            } catch (GaxiosError $error) {
                throw new ProviderHttpError($error->getMessage(), $error->status, null, $error);
            }

            foreach ($authHeaders as $name => $value) {
                $headers[$name] ??= $value;
            }
        }

        try {
            $response = $this->http->send(new Request('POST', $url, $headers, $body), $signal);
        } catch (Throwable $error) {
            if ($signal?->aborted() ?? false) {
                throw $error;
            }

            throw new ProviderError('fetch failed', previous: $error);
        }

        if (!$response->isSuccessful()) {
            throw new ProviderHttpError(
                ErrorBody::genaiApiError($response->status, $response->reason, $response->header('content-type'), $response->body->all()),
                $response->status,
            );
        }

        return $response;
    }

    /**
     * The request the SDK's `ApiClient` builds for `generateContentStream()` with upstream's client
     * options: the URL (`getRequestUrlInternal()`, the project path when `shouldPrependVertexProjectPath()`
     * says so, `tModel()`'s `publishers/google/models/<id>`, `?alt=sse`), the headers — the SDK's
     * (`User-Agent` and `x-goog-api-client`, `Content-Type`) with upstream's `httpOptions.headers` laid
     * over them (`User-Agent: <pig>` then the model's and the request's), the API key last — and the
     * body, `generateContentParametersToVertex()` of the params.
     *
     * @param array{apiKey: string|null, project: string|null, location: string|null} $client
     * @param array<string, mixed> $params
     * @return array{0: string, 1: array<string, string>, 2: string}
     */
    private function request(Model $model, array $client, ?GoogleVertexOptions $options, array $params): array
    {
        $customBaseUrl = self::resolveCustomBaseUrl($model->baseUrl);
        $location = $client['location'];

        $baseUrl = match (true) {
            $customBaseUrl !== null => $customBaseUrl,
            ($client['apiKey'] !== null && $client['project'] === null) || $location === 'global' => 'https://aiplatform.googleapis.com/',
            in_array($location, self::MULTI_REGIONAL_LOCATIONS, true) => "https://aiplatform.{$location}.rep.googleapis.com/",
            default => "https://{$location}-aiplatform.googleapis.com/",
        };
        $apiVersion = $customBaseUrl !== null && self::baseUrlIncludesApiVersion($customBaseUrl) ? '' : self::API_VERSION;
        $resource = self::tModel((string) ($params['model'] ?? $model->id));
        $prependProjectLocation = $customBaseUrl === null
            && $client['project'] !== null
            && $location !== null
            && !str_starts_with($resource, 'projects/');

        $url = rtrim($baseUrl, '/')
            . ($apiVersion !== '' ? '/' . $apiVersion : '')
            . ($prependProjectLocation ? "/projects/{$client['project']}/locations/{$location}" : '')
            . '/' . $resource . ':streamGenerateContent?alt=sse';

        $merged = ['User-Agent' => PigUserAgent::get()];

        foreach ([$model->headers, $options?->headers ?? []] as $source) {
            foreach ($source as $name => $value) {
                $merged[(string) $name] = $value;
            }
        }

        $sdkHeaders = [
            'User-Agent' => SdkHeaders::googleApiClient(),
            'x-goog-api-client' => SdkHeaders::googleApiClient(),
            'Content-Type' => 'application/json',
        ];

        foreach (Headers::providerHeadersToRecord($merged) ?? [] as $name => $value) {
            $sdkHeaders[$name] = $value;
        }

        $headers = [];

        foreach ($sdkHeaders as $name => $value) {
            $name = strtolower($name);
            $headers[$name] = isset($headers[$name]) ? "{$headers[$name]}, {$value}" : $value;
        }

        if ($client['apiKey'] !== null) {
            $headers['x-goog-api-key'] ??= $client['apiKey'];
        }

        return [$url, $headers, self::encode(self::paramsToWire($params))];
    }

    /** `tModel()` for Vertex: `publishers/…`, `projects/…` and `models/…` as given, `a/b` as a publisher's. */
    private static function tModel(string $model): string
    {
        if ($model === '' || str_contains($model, '..') || str_contains($model, '?') || str_contains($model, '&')) {
            throw new ProviderError($model === '' ? 'model is required and must be a string' : 'invalid model parameter');
        }

        if (str_starts_with($model, 'publishers/') || str_starts_with($model, 'projects/') || str_starts_with($model, 'models/')) {
            return $model;
        }

        if (str_contains($model, '/')) {
            [$publisher, $name] = explode('/', $model, 2);

            return "publishers/{$publisher}/models/" . explode('/', $name)[0];
        }

        return "publishers/google/models/{$model}";
    }

    /** Upstream's `resolveCustomBaseUrl()`: a base URL with the catalogue's `{location}` in it is none. */
    private static function resolveCustomBaseUrl(string $baseUrl): ?string
    {
        $trimmed = trim($baseUrl);

        return $trimmed === '' || str_contains($trimmed, '{location}') ? null : $trimmed;
    }

    /** Upstream's `baseUrlIncludesApiVersion()`: a `v1`, `v1beta`, `v1beta1` path segment. */
    private static function baseUrlIncludesApiVersion(string $baseUrl): bool
    {
        $path = parse_url($baseUrl, PHP_URL_PATH);

        if (!is_string($path) && parse_url($baseUrl) === false) {
            return preg_match('#(?:^|/)v\d+(?:beta\d*)?(?:/|$)#', $baseUrl) === 1;
        }

        foreach (explode('/', (string) $path) as $part) {
            if (preg_match('/^v\d+(?:beta\d*)?$/', $part) === 1) {
                return true;
            }
        }

        return false;
    }

    /** Upstream's `resolveApiKey()`. */
    private static function resolveApiKey(?GoogleVertexOptions $options): ?string
    {
        $apiKey = $options?->apiKey !== null ? trim($options->apiKey) : '';

        if ($apiKey === '' || $apiKey === self::GCP_VERTEX_CREDENTIALS_MARKER || preg_match('/^<[^>]+>$/', $apiKey) === 1) {
            return null;
        }

        return $apiKey;
    }

    /** Upstream's `resolveProject()`. */
    private static function resolveProject(?GoogleVertexOptions $options): string
    {
        $project = ($options?->project !== null && $options->project !== '' ? $options->project : null)
            ?? StreamOptions::providerEnvValue('GOOGLE_CLOUD_PROJECT', $options?->env)
            ?? StreamOptions::providerEnvValue('GCLOUD_PROJECT', $options?->env);

        return $project
            ?? throw new ProviderError('Vertex AI requires a project ID. Set GOOGLE_CLOUD_PROJECT/GCLOUD_PROJECT or pass project in options.');
    }

    /** Upstream's `resolveLocation()`. */
    private static function resolveLocation(?GoogleVertexOptions $options): string
    {
        $location = ($options?->location !== null && $options->location !== '' ? $options->location : null)
            ?? StreamOptions::providerEnvValue('GOOGLE_CLOUD_LOCATION', $options?->env);

        return $location
            ?? throw new ProviderError('Vertex AI requires a location. Set GOOGLE_CLOUD_LOCATION or pass location in options.');
    }

    /**
     * Upstream's `buildParams()` in `google-vertex.ts` — the same as `google-generative-ai.ts`'s, which
     * is what `Google::params()` ports.
     *
     * @return array<string, mixed>
     */
    private static function buildParams(Model $model, TranscriptContext $context, ?GoogleVertexOptions $options): array
    {
        $initialSystemMessage = Transcript::getInitialSystemMessage($context->messages);
        $currentTools = Transcript::getCurrentTools($context->messages);
        $config = [];

        if ($options?->temperature !== null) {
            $config['temperature'] = $options->temperature;
        }

        if ($options?->maxTokens !== null) {
            $config['maxOutputTokens'] = $options->maxTokens;
        }

        $supportsStrictMode = GoogleShared::supportsGoogleStrictToolSampling($model->id);
        $functionCallingMode = $currentTools !== []
            ? GoogleShared::resolveGoogleFunctionCallingMode($currentTools, $options?->toolChoice, $supportsStrictMode)
            : null;
        $systemInstruction = $initialSystemMessage !== null ? Text::getSystemMessageText($initialSystemMessage) : '';

        if ($systemInstruction !== '') {
            $config['systemInstruction'] = Utf8::sanitize($systemInstruction);
        }

        if ($currentTools !== []) {
            $config['tools'] = GoogleShared::convertTools($currentTools, false, $supportsStrictMode);
        }

        if ($functionCallingMode !== null) {
            $config['toolConfig'] = ['functionCallingConfig' => ['mode' => $functionCallingMode]];
        }

        if ($model->reasoning && $options?->thinkingEnabled === true) {
            $thinkingConfig = ['includeThoughts' => true];

            if ($options->thinkingLevel !== null) {
                $thinkingConfig['thinkingLevel'] = $options->thinkingLevel;
            } elseif ($options->thinkingBudget !== null) {
                $thinkingConfig['thinkingBudget'] = $options->thinkingBudget;
            }

            $config['thinkingConfig'] = $thinkingConfig;
        } elseif ($model->reasoning && $options?->thinkingEnabled === false) {
            $config['thinkingConfig'] = GoogleShared::disabledGoogleThinkingConfig($model);
        }

        if ($options?->signal?->aborted() ?? false) {
            throw new ProviderError('Request aborted');
        }

        return [
            'model' => $model->id,
            'contents' => GoogleShared::contents($model, $context),
            'config' => $config,
        ];
    }

    /**
     * The SDK's `generateContentParametersToVertex()`: `contents`, the config's request-level keys
     * lifted out (`serviceTier`, `systemInstruction` as a user `Content`, `safetySettings`, `tools`,
     * `toolConfig`, `labels`, `cachedContent`, `modelArmorConfig`), `generationConfig` with the rest —
     * Vertex also takes `routingConfig`, `modelSelectionConfig` (as `modelConfig`) and `audioTimestamp`,
     * and refuses `enableEnhancedCivicAnswers` by name, as the SDK does.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private static function paramsToWire(array $params): array
    {
        $wire = [];

        if (is_array($params['contents'] ?? null)) {
            $wire['contents'] = array_map(self::contentToVertex(...), array_values($params['contents']));
        }

        $config = $params['config'] ?? null;

        if (!is_array($config)) {
            return $wire;
        }

        $generation = [];

        foreach ($config as $key => $value) {
            if ($value === null) {
                continue;
            }

            match ($key) {
                'systemInstruction' => $wire['systemInstruction'] = is_string($value)
                    ? ['parts' => [['text' => $value]], 'role' => 'user']
                    : self::contentToVertex($value),
                'serviceTier', 'safetySettings', 'tools', 'toolConfig', 'labels', 'cachedContent', 'modelArmorConfig' => $wire[$key] = $value,
                'modelSelectionConfig' => $generation['modelConfig'] = $value,
                'enableEnhancedCivicAnswers' => throw new ProviderError('enableEnhancedCivicAnswers parameter is only supported in Gemini Developer API mode, not in Gemini Enterprise Agent Platform mode.'),
                'temperature', 'topP', 'topK', 'candidateCount', 'maxOutputTokens', 'stopSequences',
                'responseLogprobs', 'logprobs', 'presencePenalty', 'frequencyPenalty', 'seed',
                'responseMimeType', 'responseSchema', 'responseJsonSchema', 'routingConfig', 'responseModalities',
                'mediaResolution', 'speechConfig', 'audioTimestamp', 'thinkingConfig', 'audioTranscriptionConfig',
                'imageConfig' => $generation[$key] = $value,
                default => null,
            };
        }

        $wire['generationConfig'] = $generation === [] ? new \stdClass() : $generation;

        return $wire;
    }

    /**
     * The SDK's `contentToVertex()`: `parts` (each through `partToVertex()`) before `role`.
     *
     * @return array<string, mixed>
     */
    private static function contentToVertex(mixed $content): array
    {
        $out = [];

        if (is_array($content) && is_array($content['parts'] ?? null)) {
            $out['parts'] = array_map(self::partToVertex(...), array_values($content['parts']));
        }

        if (is_array($content) && ($content['role'] ?? null) !== null) {
            $out['role'] = $content['role'];
        }

        return $out;
    }

    /**
     * The SDK's `partToVertex()`: the members Vertex has, in its order, the ones it has not — and a
     * `toolCall`, `toolResponse` or `partMetadata` part, which only the Gemini API takes — refused.
     *
     * @return array<string, mixed>
     */
    private static function partToVertex(mixed $part): array
    {
        if (!is_array($part)) {
            return [];
        }

        foreach (['toolCall', 'toolResponse', 'partMetadata'] as $geminiOnly) {
            if (array_key_exists($geminiOnly, $part)) {
                throw new ProviderError("{$geminiOnly} parameter is only supported in Gemini Developer API mode, not in Gemini Enterprise Agent Platform mode.");
            }
        }

        $out = [];

        foreach (['mediaResolution', 'audioTranscription', 'codeExecutionResult', 'executableCode', 'fileData', 'functionCall', 'functionResponse', 'inlineData', 'text', 'thought', 'thoughtSignature', 'videoMetadata', 'mediaProcessing'] as $key) {
            if (($part[$key] ?? null) !== null) {
                $out[$key] = $part[$key];
            }
        }

        return $out;
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
