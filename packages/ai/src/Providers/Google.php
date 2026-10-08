<?php

declare(strict_types=1);

namespace Pig\Ai\Providers;

use Pig\Ai\Context;
use Pig\Ai\DoneEvent;
use Pig\Ai\ErrorEvent;
use Pig\Ai\Http\HttpClient;
use Pig\Ai\Http\Request;
use Pig\Ai\Model;
use Pig\Ai\ProviderError;
use Pig\Ai\StartEvent;
use Pig\Ai\StopReason;
use Pig\Ai\Utils\AssistantMessageEventStream;
use Pig\Ai\Utils\ErrorBody;
use Pig\Ai\Utils\JsJson;
use Pig\Ai\Utils\PigUserAgent;
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
    public function stream(Model $model, Context $context, ?GoogleOptions $options = null): AssistantMessageEventStream
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
        ?GoogleOptions $options,
    ): void {
        $builder = new AssistantMessageBuilder($model);
        // Upstream's `stopReason: "pending"`: only a `finishReason` replaces it.
        $builder->setStopReason(StopReason::Pending);
        $signal = $options?->signal;
        $open = null;

        try {
            $response = $this->http->send($this->request($model, $context, $options), $signal);

            if (!$response->isSuccessful()) {
                throw new ProviderError(ErrorBody::genaiApiError(
                    $response->status,
                    $response->reason,
                    $response->header('content-type'),
                    $response->body->all(),
                ));
            }

            $stream->push(new StartEvent($builder->snapshot()));

            foreach (self::sdkChunks($response->body) as $data) {
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
            $signal?->throwIfAborted();

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
            // A provider never throws at its caller: the failure is the stream's result.
            $builder->fail($error->getMessage(), $signal?->aborted() ?? false);
            $failed = $builder->snapshot();
            $stream->push(new ErrorEvent($failed->stopReason, $failed));
            $stream->end();
        }
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
     * @param iterable<string> $body
     * @return iterable<mixed> each payload, decoded with objects as arrays
     */
    private static function sdkChunks(iterable $body): iterable
    {
        $buffer = '';
        // `TextDecoder.decode(value, {stream: true})`: a character cut between two reads waits here.
        $pending = '';

        foreach ($body as $bytes) {
            [$chunkString, $pending] = self::decodeStreaming($pending . $bytes);

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

    /**
     * The complete UTF-8 characters at the front of `$bytes` (anything malformed as U+FFFD), and
     * the start of a character still being read at the end.
     *
     * @return array{0: string, 1: string}
     */
    private static function decodeStreaming(string $bytes): array
    {
        $length = strlen($bytes);

        // A lead byte in the last three whose sequence runs past the end is held back.
        for ($back = 1; $back <= min(3, $length); $back++) {
            $byte = ord($bytes[$length - $back]);

            if ($byte < 0x80) {
                break;
            }

            if ($byte >= 0xC0) {
                $needs = $byte >= 0xF0 ? 4 : ($byte >= 0xE0 ? 3 : 2);

                if ($needs > $back) {
                    return [JsJson::decodeUtf8(substr($bytes, 0, $length - $back)), substr($bytes, $length - $back)];
                }

                break;
            }
        }

        return [JsJson::decodeUtf8($bytes), ''];
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

    private function request(Model $model, Context $context, ?GoogleOptions $options): Request
    {
        $headers = [
            'accept' => 'text/event-stream',
            'content-type' => 'application/json',
            'x-goog-api-key' => $options?->apiKey ?? '',
            // Upstream's `createClient()`: `{"User-Agent": getPiUserAgent(), ...model.headers}`.
            'User-Agent' => PigUserAgent::get(),
            ...$model->headers,
        ];

        // `alt=sse` is what turns this from one enormous JSON array into a stream.
        $url = rtrim($model->baseUrl, '/') . '/models/' . rawurlencode($model->id) . ':streamGenerateContent?alt=sse';

        return new Request('POST', $url, $headers, $this->encode($this->body($model, $context, $options)));
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
    private function body(Model $model, Context $context, ?GoogleOptions $options): array
    {
        $body = ['contents' => GoogleShared::contents($model, $context)];
        $config = [];

        if ($options?->temperature !== null) {
            $config['temperature'] = $options->temperature;
        }

        if ($options?->maxTokens !== null) {
            $config['maxOutputTokens'] = $options->maxTokens;
        }

        if ($context->systemPrompt !== null && $context->systemPrompt !== '') {
            $body['systemInstruction'] = GoogleShared::systemInstruction($context->systemPrompt);
        }

        // Upstream's `buildParams()`: Gemini 3+ takes strict tools, and a strict tool makes the
        // calling mode `VALIDATED` unless the caller said `none` or `any`. The schema goes as
        // `parametersJsonSchema`, full JSON Schema as written (`convertTools(tools, false, …)`).
        $supportsStrictMode = GoogleShared::supportsGoogleStrictToolSampling($model->id);
        $functionCallingMode = $context->tools !== []
            ? GoogleShared::resolveGoogleFunctionCallingMode($context->tools, $options?->toolChoice, $supportsStrictMode)
            : null;

        if ($context->tools !== []) {
            $body['tools'] = GoogleShared::convertTools($context->tools, false, $supportsStrictMode);
        }

        if ($functionCallingMode !== null) {
            $body['toolConfig'] = ['functionCallingConfig' => ['mode' => $functionCallingMode]];
        }

        if ($model->reasoning) {
            $config['thinkingConfig'] = $this->thinking($model, $options);
        }

        if ($config !== []) {
            $body['generationConfig'] = $config;
        }

        return $body;
    }

    /**
     * What to tell Gemini about thinking.
     *
     * Saying nothing is not the same as saying no: Gemini thinks by default, so a turn
     * that did not ask for it has to ask for none — upstream's `getDisabledGoogleThinkingConfig()`,
     * which is a zero budget except for a level model that has no `off`, which gets the lowest
     * level it has. A level model takes a level and ignores a budget; 2.5 takes a budget.
     * `Stream` works out which; this sends whichever arrived.
     *
     * @return array<string, mixed>
     */
    private function thinking(Model $model, ?GoogleOptions $options): array
    {
        if ($options === null || !$options->thinkingEnabled) {
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
