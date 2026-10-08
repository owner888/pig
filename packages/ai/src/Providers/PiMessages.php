<?php

declare(strict_types=1);

namespace Pig\Ai\Providers;

use Generator;
use Pig\Ai\AssistantMessage;
use Pig\Ai\AssistantMessageDiagnostic;
use Pig\Ai\DiagnosticErrorInfo;
use Pig\Ai\DoneEvent;
use Pig\Ai\ErrorEvent;
use Pig\Ai\Http\HttpClient;
use Pig\Ai\Http\Request;
use Pig\Ai\Http\Response;
use Pig\Ai\Model;
use Pig\Ai\ProviderError;
use Pig\Ai\StopReason;
use Pig\Ai\StreamOptions;
use Pig\Ai\Timestamp;
use Pig\Ai\TranscriptContext;
use Pig\Ai\Usage;
use Pig\Ai\Utils\AssistantMessageEventStream;
use Pig\Ai\Utils\Headers;
use Pig\Ai\Utils\JsJson;
use Pig\Ai\Utils\MessageJson;
use Pig\Ai\Utils\TextDecoder;
use Pig\Async\AbortSignal;
use Pig\Async\Async;
use stdClass;
use Throwable;

/**
 * pi's own message protocol, sent straight to a backend — upstream's `api/pi-messages.ts`.
 *
 * "The request is a single POST of `{ model, context, options }` to `<baseUrl>/messages`, the
 * response is an SSE stream of serialized assistant-message events plus a terminal `done`/`error`
 * event. This is the wire protocol spoken by the Radius gateway, but any backend implementing it can
 * be used, e.g. via a models.json custom provider with `"api": "pi-messages"`."
 *
 * So nothing is translated on the way out — the context goes as pi writes its messages
 * (`Utils\MessageJson`, the session file's shape), and the options as the five fields upstream
 * picks — and on the way back each event names its block by `contentIndex` and is applied to the
 * message as it says (`createEventConverter()`). The usage, the stop reason and the response id are
 * the backend's, not worked out here: the backend priced the turn.
 *
 * A refused request is `<status> <statusText>: <error.message or body>( (<code>))`, with the
 * response kept in a `pi_messages_response_failure` diagnostic; a `rewrite` on the terminal event
 * (a gateway policy that changed the request) is a `pi_messages_rewrite` diagnostic.
 */
final class PiMessages
{
    /** Upstream's `truncateDiagnosticString()` limit. */
    private const int MAX_DIAGNOSTIC_STRING = 8192;

    public function __construct(private readonly HttpClient $http = new HttpClient())
    {
    }

    /** Returns at once; the response fills in as it arrives. */
    public function stream(Model $model, TranscriptContext $context, ?PiMessagesOptions $options = null): AssistantMessageEventStream
    {
        $stream = new AssistantMessageEventStream();

        Async::spawn(function () use ($stream, $model, $context, $options): void {
            $this->run($stream, $model, $context, $options);
        });

        return $stream;
    }

    private function run(AssistantMessageEventStream $stream, Model $model, TranscriptContext $context, ?PiMessagesOptions $options): void
    {
        $converter = new PiMessagesEventConverter($model);
        $signal = $options?->signal;

        try {
            $apiKey = $options?->apiKey;

            if ($apiKey === null || $apiKey === '') {
                throw new ProviderError("No API key provided for provider \"{$model->provider}\"");
            }

            $url = rtrim($model->baseUrl, '/') . '/messages';

            if ($options?->debug === true) {
                $url .= '?debug=1';
            }

            $payload = [
                'model' => $model->id,
                'context' => ['messages' => array_values(array_filter(array_map(MessageJson::encode(...), $context->messages)))],
                // The five fields, an undefined one left out of the JSON.
                'options' => array_filter([
                    'temperature' => $options?->temperature,
                    'maxTokens' => $options?->maxTokens,
                    'reasoning' => $options?->reasoning,
                    'cacheRetention' => self::resolveCacheRetention($options),
                    'sessionId' => $options?->sessionId,
                    'toolChoice' => $options?->toolChoice,
                ], static fn (mixed $value): bool => $value !== null),
            ];

            if ($payload['options'] === []) {
                $payload['options'] = new stdClass();
            }

            $nextPayload = $options?->onPayload !== null ? ($options->onPayload)($payload, $model) : null;

            if ($nextPayload !== null) {
                $payload = $nextPayload;
            }

            $response = $this->fetch(new Request(
                'POST',
                $url,
                Headers::providerHeadersToRecord(
                    ['authorization' => "Bearer {$apiKey}", 'accept' => 'text/event-stream', 'content-type' => 'application/json'],
                    $options?->headers,
                ) ?? [],
                self::encode($payload),
            ), $signal);

            if ($options?->onResponse !== null) {
                ($options->onResponse)(['status' => $response->status, 'headers' => $response->headers], $model);
            }

            if (!$response->isSuccessful()) {
                $body = (new TextDecoder())->decode($response->body->all(), false);

                throw self::createPiMessagesResponseError($model, $url, $response, $body);
            }

            foreach (self::readPiMessagesEvents($response) as $piEvent) {
                if ($options?->onProviderStreamEvent !== null) {
                    ($options->onProviderStreamEvent)($piEvent, $model);
                }

                $event = $converter->convert($piEvent);

                if ($event === null) {
                    continue;
                }

                $stream->push($event);

                if ($event instanceof DoneEvent || $event instanceof ErrorEvent) {
                    $stream->end();

                    return;
                }
            }

            throw new ProviderError("{$model->provider} stream ended without a terminal event");
        } catch (Throwable $error) {
            $event = self::createErrorEvent($model, $error, $signal?->aborted() ?? false);
            $stream->push($event);
            $stream->end();
        }
    }

    /**
     * Upstream's `fetch(url, {method, headers, body, signal})`: no timeout, the caller's signal on
     * the request and the body, and Node's `fetch failed` for a request that got no response.
     */
    private function fetch(Request $request, ?AbortSignal $signal): Response
    {
        $signal?->throwIfAborted();

        try {
            return $this->http->send($request, $signal);
        } catch (Throwable $error) {
            if ($signal?->aborted() ?? false) {
                throw $error;
            }

            throw new ProviderError('fetch failed', previous: $error);
        }
    }

    /**
     * Upstream's `resolveCacheRetention()` for this API: "Backend defaults apply when unset; only the
     * legacy env opt-in is mapped" — the option, else `long` for `PI_CACHE_RETENTION=long`, else
     * nothing at all (never `short`).
     */
    private static function resolveCacheRetention(?StreamOptions $options): ?string
    {
        if ($options?->cacheRetention !== null && $options->cacheRetention !== '') {
            return $options->cacheRetention;
        }

        return StreamOptions::providerEnvValue('PI_CACHE_RETENTION', $options?->env) === 'long' ? 'long' : null;
    }

    /**
     * Upstream's `readPiMessagesEvents()`: the body decoded as it comes, `\r\n` made `\n`, split into
     * frames at each blank line, and whatever is left when the body ends read as one more frame.
     *
     * @return Generator<int, array<string, mixed>>
     */
    private static function readPiMessagesEvents(Response $response): Generator
    {
        $decoder = new TextDecoder();
        $buffer = '';

        foreach ($response->body as $value) {
            $buffer = str_replace("\r\n", "\n", $buffer . $decoder->decode($value, true));

            while (($split = strpos($buffer, "\n\n")) !== false) {
                $event = self::parsePiMessagesEvent(substr($buffer, 0, $split));

                if ($event !== null) {
                    yield $event;
                }

                $buffer = substr($buffer, $split + 2);
            }
        }

        $buffer = str_replace("\r\n", "\n", $buffer . $decoder->decode('', false));

        while (($split = strpos($buffer, "\n\n")) !== false) {
            $event = self::parsePiMessagesEvent(substr($buffer, 0, $split));

            if ($event !== null) {
                yield $event;
            }

            $buffer = substr($buffer, $split + 2);
        }

        if (JsJson::trim($buffer) !== '') {
            $event = self::parsePiMessagesEvent($buffer);

            if ($event !== null) {
                yield $event;
            }
        }
    }

    /**
     * Upstream's `parsePiMessagesEvent()`: the **first** `data:` line's value, trimmed, parsed — V8's
     * own error when it is not JSON; nothing for a frame with no data, an empty one, or `[DONE]`.
     *
     * @return array<string, mixed>|null
     */
    private static function parsePiMessagesEvent(string $raw): ?array
    {
        $data = null;

        foreach (explode("\n", $raw) as $line) {
            if (str_starts_with($line, 'data:')) {
                $data = JsJson::trim(substr($line, 5));

                break;
            }
        }

        if ($data === null || $data === '' || $data === '[DONE]') {
            return null;
        }

        $event = JsJson::parse($data);

        return is_array($event) ? $event : ['type' => null];
    }

    /**
     * Upstream's `createPiMessagesResponseError()` and `formatPiMessagesResponseError()`:
     * `<status> <statusText>: <error.message, else the body>` and ` (<error.code>)` when there is one,
     * with the diagnostic details — the provider, model, URL, status, the parsed `error` or the body
     * (cut at 8,192), and the time.
     */
    private static function createPiMessagesResponseError(Model $model, string $url, Response $response, string $body): PiMessagesResponseError
    {
        $errorBody = self::parsePiMessagesErrorBody($body);
        $error = $errorBody['error'] ?? null;
        $message = is_array($error) && is_string($error['message'] ?? null) ? $error['message'] : null;
        $code = is_array($error) && is_string($error['code'] ?? null) ? $error['code'] : null;
        $suffix = $message ?? $body;
        $codeSuffix = $code !== null && $code !== '' ? " ({$code})" : '';

        return new PiMessagesResponseError("{$response->status} {$response->reason}: {$suffix}{$codeSuffix}", $code, [
            'version' => 1,
            'provider' => $model->provider,
            'model' => $model->id,
            'url' => $url,
            'status' => $response->status,
            'statusText' => $response->reason,
            ...($error === null ? [] : ['error' => $error]),
            ...($errorBody !== null ? [] : ['body' => self::truncateDiagnosticString($body)]),
            'timestampMs' => Timestamp::nowMs(),
        ]);
    }

    /**
     * Upstream's `parsePiMessagesErrorBody()`: the body when it is JSON with an object `error`.
     *
     * @return array<string, mixed>|null
     */
    private static function parsePiMessagesErrorBody(string $body): ?array
    {
        $parsed = json_decode($body, true);

        if (!is_array($parsed) || array_is_list($parsed) && $parsed !== []) {
            return null;
        }

        $error = $parsed['error'] ?? null;

        return is_array($error) && ($error === [] || !array_is_list($error)) ? $parsed : null;
    }

    /** Upstream's `truncateDiagnosticString()`. */
    private static function truncateDiagnosticString(string $value): string
    {
        return mb_strlen($value) > self::MAX_DIAGNOSTIC_STRING ? mb_substr($value, 0, self::MAX_DIAGNOSTIC_STRING) . '…' : $value;
    }

    /**
     * Upstream's `createErrorEvent()`: a fresh message — no content, no usage — with the error's
     * message, `aborted` when the signal was, and for a refused request (not an aborted one) a
     * `pi_messages_response_failure` diagnostic.
     */
    private static function createErrorEvent(Model $model, Throwable $error, bool $aborted): ErrorEvent
    {
        $reason = $aborted ? StopReason::Aborted : StopReason::Error;
        $diagnostics = null;

        if (!$aborted && $error instanceof PiMessagesResponseError) {
            $diagnostics = [new AssistantMessageDiagnostic(
                'pi_messages_response_failure',
                Timestamp::nowMs(),
                new DiagnosticErrorInfo($error->getMessage(), 'PiMessagesResponseError', $error->getTraceAsString(), $error->piCode),
                $error->diagnosticDetails,
            )];
        }

        $message = new AssistantMessage(
            [],
            $model->api,
            $model->provider,
            $model->id,
            new Usage(0, 0, 0, 0, 0),
            $reason,
            SdkRequest::errorMessage($error),
            diagnostics: $diagnostics,
        );

        return new ErrorEvent($reason, $message);
    }

    /** @param mixed $payload */
    private static function encode(mixed $payload): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            throw new ProviderError('Cannot encode the request: ' . json_last_error_msg());
        }

        return $json;
    }
}
