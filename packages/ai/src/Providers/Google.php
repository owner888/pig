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
use Pig\Ai\TranscriptContext;
use Pig\Ai\Utils\AssistantMessageEventStream;
use Pig\Ai\Utils\ErrorBody;
use Pig\Ai\Utils\Headers;
use Pig\Ai\Utils\JsJson;
use Pig\Ai\Utils\PigUserAgent;
use Pig\Ai\Utils\ProviderHttpError;
use Pig\Ai\Utils\ProviderRetry;
use Pig\Ai\Utils\SdkHeaders;
use Pig\Ai\Utils\TextDecoder;
use Pig\Ai\Utils\Text;
use Pig\Ai\Utils\Transcript;
use Pig\Ai\Utils\Utf8;
use Pig\Async\AbortSignal;
use Pig\Async\Async;
use Throwable;

/**
 * Google's Generative Language API — Gemini, streamed.
 *
 * The fourth shape, and the one furthest from the other three. A chunk carries a list of
 * *parts*, and a part is text, or thinking (text with `thought: true` on it), or a whole
 * function call. So:
 *
 * - **A tool call arrives complete**, arguments and all, in one part. It is opened,
 *   delivered and closed in the same breath, because there is nothing to stream.
 * - **Thinking and text are the same field**, told apart by a flag, so a block ends when
 *   that flag changes — the same boundary problem as `openai-completions`, one field over.
 * - **Tool results are addressed by name as well as id**, and consecutive ones are merged
 *   into a single user turn, which is what the API wants.
 *
 * Upstream hands this to `@google/genai`. There is none here, so the REST endpoint is
 * called directly: `:streamGenerateContent?alt=sse`, which is what that package does
 * underneath.
 *
 * Ported from upstream's `providers/google.ts`. What a Gemini request and a Gemini chunk look
 * like lives in `GoogleShared`, because Code Assist sends the same request and answers with the
 * same chunk one key deeper — see that file. What is here is this endpoint's own business: where
 * it sends, how it authenticates, how it words a failure, and the body it assembles around those
 * shared pieces.
 */
final class Google
{
    public function __construct(private readonly HttpClient $http = new HttpClient())
    {
    }

    /** Returns at once; the response fills in as it arrives. */
    public function stream(Model $model, TranscriptContext $context, ?GoogleOptions $options = null): AssistantMessageEventStream
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
        ?GoogleOptions $options,
    ): void {
        $builder = new AssistantMessageBuilder($model);
        // Upstream's `stopReason: "pending"`: only a `finishReason` replaces it.
        $builder->setStopReason(StopReason::Pending);
        $signal = $options?->signal;
        $open = null;

        try {
            $apiKey = $options?->apiKey;

            if ($apiKey === null || $apiKey === '') {
                throw new ProviderError("No API key for provider: {$model->provider}");
            }

            // `buildParams()` — the SDK's `GenerateContentParameters` — then `onPayload`, whose answer
            // replaces them; the SDK turns what is left into the request body (`paramsToWire()`).
            $params = $this->params($model, $context, $options);
            $nextParams = $options?->onPayload !== null ? ($options->onPayload)($params, $model) : null;

            if ($nextParams !== null) {
                $params = (array) $nextParams;
            }

            $request = $this->request($model, $apiKey, $options, self::paramsToWire($params));

            // `retryGoogleRequest(() => client.models.generateContentStream(params), options)`: the
            // SDK's `ApiError` has a status and no headers, which is enough for the shared policy.
            $response = ProviderRetry::retryProviderRequest(
                fn (): Response => $this->send($request, $signal),
                $options?->maxRetries,
                $options?->maxRetryDelayMs,
                $signal,
            );

            $stream->push(new StartEvent($builder->snapshot()));

            foreach (self::sdkChunks($response->body) as $data) {
                if ($options?->onProviderStreamEvent !== null) {
                    ($options->onProviderStreamEvent)($data, $model);
                }

                if (is_array($data)) {
                    // Upstream keeps the first non-empty `responseId` of the stream. Here and
                    // not in `GoogleShared`, because `google-generative-ai.ts` is where upstream
                    // does it; the Code Assist extension that shares the chunk code follows a
                    // different upstream file.
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

            // Upstream's checks after the stream: a body that ended without a `finishReason` is a
            // cut connection, not an answer — it used to come back as a clean `stop`; and an error
            // reason (a safety block, a malformed call, …) ends the turn with the reason itself.
            if ($builder->stopReason() === StopReason::Pending) {
                throw new ProviderError('Google stream ended without a finish reason');
            }

            if ($builder->stopReason() === StopReason::Error || $builder->stopReason() === StopReason::Aborted) {
                $raw = $builder->rawStopReason();

                throw new ProviderError($raw !== null && $raw !== '' ? "Provider stopped with: {$raw}" : 'An unknown error occurred');
            }

            $message = $builder->snapshot();
            $stream->push(new DoneEvent($message->stopReason, $message));
            $stream->end();
        } catch (Throwable $error) {
            // A provider never throws at its caller: the failure is the stream's result. An abort
            // while the body is read is the fetch's DOMException, "This operation was aborted".
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
     * The SDK's `streamApiCall()` for one attempt: a failed fetch is Node's `TypeError: fetch
     * failed`, which carries no status and so is not retried; a response that is not ok is the
     * SDK's `ApiError` (`throwErrorIfNotOK()`), with its status — and, after `retryGoogleRequest()`
     * has added it, an undefined `headers`.
     */
    private function send(Request $request, ?AbortSignal $signal): Response
    {
        try {
            $response = $this->http->send($request, $signal);
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
     * The chunks as `@google/genai` 2.21.0 hands them to upstream (`processStreamResponse()` and the
     * `chunk.json()` after it), which is not an event-stream parser:
     *
     * - each piece read off the socket is first tried as JSON on its own, and one that is an object
     *   with an `error` whose `code` is 400–599 throws `got status: <status>. <the piece's JSON>` — a
     *   refusal Google sent with a 200;
     * - events end at the first `\n\n`, `\r\r` or `\r\n\r\n`; an event that, trimmed, starts with
     *   `data:` is the rest of it, trimmed — **one** payload, later lines and all — and anything else
     *   (a comment, an `event:` line first) is passed over;
     * - the payload is `JSON.parse`d, so one that is not JSON ends the turn with V8's message;
     * - a body that ends with anything but white space left over is `Incomplete JSON segment at the
     *   end`.
     *
     * pig used to read this body with its own SSE parser and skip what did not decode, so a garbled
     * chunk went missing from the answer instead of failing the turn the way upstream's does.
     *
     * Public for `GoogleVertex`, which reads the same SDK's same stream.
     *
     * @internal
     * @param iterable<string> $body
     * @return iterable<mixed> each payload, decoded with objects as arrays
     */
    public static function sdkChunks(iterable $body): iterable
    {
        $buffer = '';
        // `decoder.decode(value, {stream: true})`: a character cut between two reads waits, and a
        // byte-order mark at the start of the body is dropped.
        $decoder = new TextDecoder();

        foreach ($body as $bytes) {
            $chunkString = $decoder->decode($bytes);

            try {
                $chunkJson = JsJson::parse($chunkString, false);
            } catch (\JsonException) {
                $chunkJson = null;
            }

            // `'error' in chunkJson`, then `code >= 400 && code < 600` as JavaScript compares.
            if ($chunkJson instanceof \stdClass && property_exists($chunkJson, 'error')) {
                $error = $chunkJson->error;
                $code = self::jsNumber(is_object($error) && property_exists($error, 'code') ? $error->code : null);

                if ($code !== null && $code >= 400 && $code < 600) {
                    $status = is_object($error) && property_exists($error, 'status') ? JsJson::toString($error->status) : 'undefined';

                    throw new ProviderError("got status: {$status}. " . JsJson::stringify($chunkJson));
                }
            }

            $buffer .= $chunkString;

            while (true) {
                $index = null;
                $length = 0;

                foreach (["\n\n", "\r\r", "\r\n\r\n"] as $delimiter) {
                    $at = strpos($buffer, $delimiter);

                    if ($at !== false && ($index === null || $at < $index)) {
                        $index = $at;
                        $length = strlen($delimiter);
                    }
                }

                if ($index === null) {
                    break;
                }

                $event = JsJson::trim(substr($buffer, 0, $index));
                $buffer = substr($buffer, $index + $length);

                if (str_starts_with($event, 'data:')) {
                    // `chunk.json()`: V8's `SyntaxError` for a payload that is not JSON.
                    yield JsJson::parse(JsJson::trim(substr($event, 5)));
                }
            }
        }

        if (JsJson::trim($buffer) !== '') {
            throw new ProviderError('Incomplete JSON segment at the end');
        }
    }

    /** JavaScript's `ToNumber` for a decoded JSON value, null where it is `NaN`. */
    private static function jsNumber(mixed $value): ?float
    {
        return match (true) {
            is_int($value), is_float($value) => (float) $value,
            is_bool($value) => $value ? 1.0 : 0.0,
            $value === null => 0.0,
            is_string($value) => JsJson::trim($value) === '' ? 0.0 : (is_numeric(JsJson::trim($value)) ? (float) JsJson::trim($value) : null),
            default => null,
        };
    }

    // ---- the request ---------------------------------------------------------------------

    /**
     * The request `client.models.generateContentStream()` sends with upstream's `createClient()`:
     * `POST <baseUrl>/models/<model>:streamGenerateContent?alt=sse` (`apiVersion: ""` — the base URL
     * already has it), and the headers the SDK builds — its defaults (`User-Agent` and
     * `x-goog-api-client` both `SdkHeaders::googleApiClient()`, `Content-Type`), `Object.assign`ed
     * with upstream's `httpOptions.headers` (`providerHeadersToRecord({"User-Agent": getPiUserAgent(),
     * ...model.headers, ...options.headers})`), appended one by one into a `Headers` — so two
     * spellings of one name are joined with ", " — and `x-goog-api-key` last unless already there.
     * No `Accept`: the SDK sends none for this call.
     *
     * @param array<string, mixed> $body
     */
    private function request(Model $model, string $apiKey, ?GoogleOptions $options, array $body): Request
    {
        $merged = ['User-Agent' => PigUserAgent::get()];

        foreach ([$model->headers, $options?->headers ?? []] as $source) {
            foreach ($source as $name => $value) {
                $merged[(string) $name] = $value;
            }
        }

        $httpHeaders = Headers::providerHeadersToRecord($merged) ?? [];
        $sdkHeaders = [
            'User-Agent' => SdkHeaders::googleApiClient(),
            'x-goog-api-client' => SdkHeaders::googleApiClient(),
            'Content-Type' => 'application/json',
        ];

        foreach ($httpHeaders as $name => $value) {
            $sdkHeaders[$name] = $value;
        }

        $headers = [];

        foreach ($sdkHeaders as $name => $value) {
            $name = strtolower($name);
            $headers[$name] = isset($headers[$name]) ? "{$headers[$name]}, {$value}" : $value;
        }

        $headers['x-goog-api-key'] ??= $apiKey;

        // `alt=sse` is what turns this from one enormous JSON array into a stream.
        $url = rtrim($model->baseUrl, '/') . '/models/' . rawurlencode($model->id) . ':streamGenerateContent?alt=sse';

        return new Request('POST', $url, $headers, $this->encode($body));
    }

    /**
     * Upstream's `buildParams()`: the SDK's `GenerateContentParameters`, `{model, contents, config}`
     * — what `onPayload` is shown. An already-aborted signal is refused here, as upstream's is.
     *
     * @return array<string, mixed>
     */
    private function params(Model $model, TranscriptContext $context, ?GoogleOptions $options): array
    {
        // Upstream's `normalizedContext = collapseSystemMessages(context)` in `stream()`: Gemini has
        // no mid-conversation system messages, so the replayed prompt is the `systemInstruction`
        // and the replayed tool set is `tools`.
        $context = Transcript::collapseSystemMessages($context);
        $initialSystemMessage = Transcript::getInitialSystemMessage($context->messages);
        $currentTools = Transcript::getCurrentTools($context->messages);
        $config = [];

        if ($options?->temperature !== null) {
            $config['temperature'] = $options->temperature;
        }

        if ($options?->maxTokens !== null) {
            $config['maxOutputTokens'] = $options->maxTokens;
        }

        $systemInstruction = $initialSystemMessage !== null ? Text::getSystemMessageText($initialSystemMessage) : '';

        if ($systemInstruction !== '') {
            $config['systemInstruction'] = Utf8::sanitize($systemInstruction);
        }

        // Upstream's `buildParams()`: Gemini 3+ takes strict tools, and a strict tool makes the
        // calling mode `VALIDATED` unless the caller said `none` or `any`. The schema goes as
        // `parametersJsonSchema`, full JSON Schema as written (`convertTools(tools, false, …)`).
        $supportsStrictMode = GoogleShared::supportsGoogleStrictToolSampling($model->id);
        $functionCallingMode = $currentTools !== []
            ? GoogleShared::resolveGoogleFunctionCallingMode($currentTools, $options?->toolChoice, $supportsStrictMode)
            : null;

        if ($currentTools !== []) {
            $config['tools'] = GoogleShared::convertTools($currentTools, false, $supportsStrictMode);
        }

        if ($functionCallingMode !== null) {
            $config['toolConfig'] = ['functionCallingConfig' => ['mode' => $functionCallingMode]];
        }

        // Upstream: `options.thinking?.enabled && model.reasoning` sends the asked-for config, and
        // `model.reasoning && options.thinking && !options.thinking.enabled` the disabled one — so
        // options with no `thinking` at all send no `thinkingConfig`.
        if ($model->reasoning && $options?->thinkingEnabled !== null) {
            $config['thinkingConfig'] = $this->thinking($model, $options);
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
     * The SDK's `generateContentParametersToMldev()` for the fields a request carries: `contents`,
     * then what `generateContentConfigToMldev()` lifts out of `config` to the top level
     * (`serviceTier`, `systemInstruction` — a string becomes `{parts: [{text}], role: "user"}`, as
     * `tContent()` makes it — `safetySettings`, `tools`, `toolConfig`, `cachedContent`), and
     * `generationConfig` with the rest. Keys the SDK does not know stay out, as they do there;
     * the parts and schemas themselves are sent as they are, which is the shape they already have.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private static function paramsToWire(array $params): array
    {
        $wire = [];

        if (($params['contents'] ?? null) !== null) {
            $wire['contents'] = $params['contents'];
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
                    : $value,
                'serviceTier', 'safetySettings', 'tools', 'toolConfig', 'cachedContent' => $wire[$key] = $value,
                'temperature', 'topP', 'topK', 'candidateCount', 'maxOutputTokens', 'stopSequences',
                'responseLogprobs', 'logprobs', 'presencePenalty', 'frequencyPenalty', 'seed',
                'responseMimeType', 'responseSchema', 'responseJsonSchema', 'responseModalities',
                'mediaResolution', 'speechConfig', 'audioTimestamp', 'thinkingConfig', 'imageConfig',
                'enableEnhancedCivicAnswers' => $generation[$key] = $value,
                default => null,
            };
        }

        $wire['generationConfig'] = $generation === [] ? new \stdClass() : $generation;

        return $wire;
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
     * What to tell Gemini about thinking.
     *
     * Saying no is not saying nothing: Gemini thinks by default, so a turn that asked for no
     * thinking has to ask for none — upstream's `getDisabledGoogleThinkingConfig()`,
     * which is a zero budget except for a level model that has no `off`, which gets the lowest
     * level it has. A level model takes a level and ignores a budget; 2.5 takes a budget.
     * `Stream` works out which; this sends whichever arrived.
     *
     * @return array<string, mixed>
     */
    private function thinking(Model $model, ?GoogleOptions $options): array
    {
        if ($options === null || $options->thinkingEnabled !== true) {
            return GoogleShared::disabledGoogleThinkingConfig($model);
        }

        $config = ['includeThoughts' => true];

        if ($options->thinkingLevel !== null) {
            $config['thinkingLevel'] = $options->thinkingLevel;
        } elseif ($options->thinkingBudget !== null) {
            $config['thinkingBudget'] = $options->thinkingBudget;
        }

        return $config;
    }

}
