<?php

declare(strict_types=1);

namespace Pig\Ai\Providers;

use JsonException;
use Pig\Ai\ClassifierBoolAnswer;
use Pig\Ai\ClassifierBoolQuestion;
use Pig\Ai\ClassifierChoiceAnswer;
use Pig\Ai\ClassifierChoiceQuestion;
use Pig\Ai\ClassifierContext;
use Pig\Ai\ClassifierModel;
use Pig\Ai\ClassifierOptions;
use Pig\Ai\ClassifierResult;
use Pig\Ai\ClassifierScoreAnswer;
use Pig\Ai\ClassifierScoreQuestion;
use Pig\Ai\Http\HttpClient;
use Pig\Ai\Http\Request;
use Pig\Ai\Http\Response;
use Pig\Ai\ProviderError;
use Pig\Ai\StopReason;
use Pig\Ai\Timestamp;
use Pig\Ai\Usage;
use Pig\Ai\Utils\ErrorBody;
use Pig\Ai\Utils\Headers;
use Pig\Ai\Utils\JsJson;
use Pig\Ai\Utils\ProviderHttpError;
use Pig\Ai\Utils\ProviderRetry;
use Pig\Ai\Utils\TextDecoder;
use Pig\Async\AbortController;
use Pig\Async\AbortSignal;
use Pig\Async\Loop;
use stdClass;
use Throwable;

/**
 * Upstream's `api/system-one-shared.ts`: one System One classification over a transport — the
 * request (`wireRequest()`: a public `bool` question goes out as TypeSafe's wire-level `noul`), the
 * POST with retries, and the answers read back and checked against the questions asked.
 *
 * The HTTP half (`postJson()`, `requestHeaders()`, `parseUsage()`, `requiredNumber()`) is what
 * upstream moved into `classifier-shared.ts` for the Decisions API; it stays here under the name
 * the first caller gave it, and `OpenAiDecisions` is its second.
 *
 * Failures are the result, never thrown: `stopReason` error (or aborted, when the caller's signal
 * was) and `formatProviderError(normalizeProviderError(error), "<label> error")` as the message — so
 * a refusal reads `System One API error (401): <body>`. A usage the service reported is kept even
 * when its answers are malformed: "a request with malformed answers was still billed".
 */
final class SystemOneShared
{
    /** "Runs one System One classification over the given transport." */
    public static function classifySystemOne(
        SystemOneTransport $transport,
        ClassifierModel $model,
        ClassifierContext $context,
        ?ClassifierOptions $options,
        HttpClient $http,
    ): ClassifierResult {
        $timestamp = Timestamp::nowMs();
        $usage = null;

        try {
            if ($model->api !== $transport->api()) {
                throw new ProviderError("Unsupported classifier API: {$model->api->value}");
            }

            if ($context->images !== []) {
                throw new ProviderError("{$transport->label()} does not support image input");
            }

            $apiKey = $options?->apiKey;

            if ($apiKey === null || $apiKey === '') {
                throw new ProviderError("No API key for provider: {$model->provider}");
            }

            $payload = $transport->payload($model, self::wireRequest($context));
            $transformed = $options?->onPayload !== null ? ($options->onPayload)($payload, $model) : null;

            if ($transformed !== null) {
                $payload = $transformed;
            }

            $headers = self::requestHeaders($model, $apiKey, $options?->headers);
            $body = self::encode($payload);
            [$response, $decoded] = ProviderRetry::retryProviderRequest(
                static fn (): array => self::postJson($http, $transport->url($model), $headers, $body, $options?->signal, $options?->timeoutMs, $transport->label()),
                $options?->maxRetries ?? 2,
                $options?->maxRetryDelayMs,
                $options?->signal,
            );

            if ($options?->onResponse !== null) {
                ($options->onResponse)(['status' => $response->status, 'headers' => $response->headers], $model);
            }

            $result = $transport->output($decoded);
            // "Set before parsing answers: a request with malformed answers was still billed."
            $usage = self::parseUsage($result->usage ?? null, $model);
            $answers = self::parseAnswers($transport->label(), $result->answers ?? null, $context);

            return new ClassifierResult($model->api, $model->provider, $model->id, $answers, StopReason::Stop, $timestamp, $usage);
        } catch (Throwable $error) {
            return new ClassifierResult(
                $model->api,
                $model->provider,
                $model->id,
                [],
                ($options?->signal?->aborted() ?? false) ? StopReason::Aborted : StopReason::Error,
                $timestamp,
                $usage,
                ErrorBody::format(ErrorBody::normalizeProviderError($error), "{$transport->label()} error"),
            );
        }
    }

    /** Upstream's `isRecord()`: a JSON object — decoded here as `stdClass`. */
    public static function isRecord(mixed $value): bool
    {
        return $value instanceof stdClass;
    }

    /**
     * One attempt of upstream's `fetch(url, {method: "POST", headers, body, signal})` and
     * `next.json()`, as both the System One and the llama.cpp APIs make it: `AbortSignal.timeout(timeoutMs)`
     * beside the caller's signal for the request and the body, a refusal as `httpError()` — `<label>
     * returned <status>` with the status, the headers and the body text — and a timeout that was not
     * the caller's as `timeoutError()`, "Request timed out after <ms>ms". Both carry a status (or
     * none) and headers, so `retryProviderRequest()` retries them as it retries an SDK's errors; a
     * fetch that never got a response is Node's `fetch failed`, which it does not.
     *
     * @param array<string, string> $headers
     * @return array{0: Response, 1: mixed} the response and its body, objects as `stdClass`
     */
    public static function postJson(HttpClient $http, string $url, array $headers, string $body, ?AbortSignal $signal, ?int $timeoutMs, string $label): array
    {
        $signal?->throwIfAborted();
        $requestSignal = $signal;
        $timer = null;
        $listener = null;
        $timedOut = false;

        if ($timeoutMs !== null) {
            $controller = new AbortController();
            $timer = Loop::get()->delay(max(0, $timeoutMs) / 1000, static function () use ($controller, &$timedOut): void {
                $timedOut = true;
                $controller->abort('The operation was aborted due to timeout');
            });
            $listener = $signal?->onAbort(static function (string $reason) use ($controller): void {
                $controller->abort($reason);
            });
            $requestSignal = $controller->signal;
        }

        $responded = false;

        try {
            $response = $http->send(new Request('POST', $url, $headers, $body), $requestSignal);
            $responded = true;
            $text = (new TextDecoder())->decode($response->body->all(), false);

            if (!$response->isSuccessful()) {
                throw new ProviderHttpError("{$label} returned {$response->status}", $response->status, $response->headers, body: $text);
            }

            return [$response, JsJson::parse($text, false)];
        } catch (ProviderHttpError | JsonException $error) {
            throw $error;
        } catch (Throwable $error) {
            if ($timedOut && !($signal?->aborted() ?? false)) {
                throw new ProviderHttpError("Request timed out after {$timeoutMs}ms", previous: $error);
            }

            if ($signal?->aborted() ?? false) {
                throw $error;
            }

            // Node's fetch: `fetch failed` before a response, undici's `terminated` while its body is read.
            throw new ProviderError($responded ? SdkRequest::BODY_TERMINATED : 'fetch failed', previous: $error);
        } finally {
            if ($timer !== null) {
                Loop::get()->cancel($timer);
            }

            if ($listener !== null) {
                $signal?->removeListener($listener);
            }
        }
    }

    /** `JSON.stringify(payload)`. */
    public static function encode(mixed $payload): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            throw new ProviderError('Cannot encode the request: ' . json_last_error_msg());
        }

        return $json;
    }

    /**
     * Upstream's `requiredNumber()`: a finite number, else `<label> returned an invalid <field>`.
     */
    public static function requiredNumber(string $label, mixed $value, string $field): float
    {
        if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value)) {
            throw new ProviderError("{$label} returned an invalid {$field}");
        }

        return (float) $value;
    }

    /** @return array<string, float> */
    private static function probabilities(string $label, mixed $value, string $id): array
    {
        if (!self::isRecord($value)) {
            throw new ProviderError("{$label} returned invalid probabilities for {$id}");
        }

        $out = [];

        foreach (get_object_vars($value) as $key => $probability) {
            $out[(string) $key] = self::requiredNumber($label, $probability, "probability for {$id}.{$key}");
        }

        return $out;
    }

    /**
     * Upstream's `parseAnswers()`: an answer of the asked type for every question, in the order the
     * questions were asked — `noul` back to the public `bool`.
     *
     * @return array<string, ClassifierChoiceAnswer|ClassifierScoreAnswer|ClassifierBoolAnswer>
     */
    private static function parseAnswers(string $label, mixed $value, ClassifierContext $context): array
    {
        if (!self::isRecord($value)) {
            throw new ProviderError("{$label} returned an unexpected response");
        }

        $answers = [];

        foreach ($context->questions as $id => $question) {
            $id = (string) $id;
            $answer = property_exists($value, $id) ? $value->{$id} : null;

            if (!self::isRecord($answer)) {
                throw new ProviderError("{$label} did not return an answer for {$id}");
            }

            $type = $answer->type ?? null;

            if ($question instanceof ClassifierChoiceQuestion) {
                if ($type !== 'choice' || !is_string($answer->choice ?? null)) {
                    throw new ProviderError("{$label} did not return a choice answer for {$id}");
                }

                $answers[$id] = new ClassifierChoiceAnswer(
                    $answer->choice,
                    self::probabilities($label, $answer->probabilities ?? null, $id),
                    self::requiredNumber($label, $answer->confidence ?? null, "confidence for {$id}"),
                );
            } elseif ($question instanceof ClassifierScoreQuestion) {
                if ($type !== 'score') {
                    throw new ProviderError("{$label} did not return a score answer for {$id}");
                }

                $answers[$id] = new ClassifierScoreAnswer(
                    self::requiredNumber($label, $answer->score ?? null, "score for {$id}"),
                    self::requiredNumber($label, $answer->confidence ?? null, "confidence for {$id}"),
                );
            } else {
                if ($type !== 'noul') {
                    throw new ProviderError("{$label} did not return a bool answer for {$id}");
                }

                $answers[$id] = new ClassifierBoolAnswer(self::requiredNumber($label, $answer->noul ?? null, "probability for {$id}"));
            }
        }

        return $answers;
    }

    /** Upstream's `tokenCount()`: a finite positive number, else 0. */
    private static function tokenCount(mixed $value): int
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value) && $value > 0 ? (int) $value : 0;
    }

    /**
     * Upstream's `parseUsage()`: "Usage from System One's `{ input_tokens, output_tokens }`, priced
     * from the model catalog like chat usage. A missing or malformed usage object leaves the result
     * without usage instead of failing it."
     */
    public static function parseUsage(mixed $value, ClassifierModel $model): ?Usage
    {
        if (!self::isRecord($value) || (!property_exists($value, 'input_tokens') && !property_exists($value, 'output_tokens'))) {
            return null;
        }

        $input = self::tokenCount($value->input_tokens ?? null);
        $output = self::tokenCount($value->output_tokens ?? null);

        return (new Usage($input, $output, 0, 0, $input + $output))->withCost($model);
    }

    /**
     * Upstream's `wireRequest()`: "Maps public `bool` questions to TypeSafe's wire-level `noul` type."
     *
     * @return array{state: array<string, mixed>|stdClass, questions: stdClass}
     */
    private static function wireRequest(ClassifierContext $context): array
    {
        $questions = new stdClass();

        foreach ($context->questions as $id => $question) {
            $questions->{(string) $id} = match (true) {
                $question instanceof ClassifierChoiceQuestion => ['type' => 'choice', 'instructions' => $question->instructions, 'criteria' => (object) $question->criteria],
                $question instanceof ClassifierScoreQuestion => ['type' => 'score', 'instructions' => $question->instructions, 'criteria' => array_values($question->criteria)],
                $question instanceof ClassifierBoolQuestion => ['type' => 'noul', 'instructions' => $question->instructions, 'criteria' => (object) $question->criteria],
            };
        }

        return ['state' => $context->stateForJson(), 'questions' => $questions];
    }

    /**
     * Upstream's `requestHeaders()`: `{authorization: Bearer <key>, content-type: application/json}`,
     * the model's, then the caller's — merged whatever the case, a null removing one.
     *
     * @param array<string, string|null>|null $optionsHeaders
     * @return array<string, string>
     */
    public static function requestHeaders(ClassifierModel $model, string $apiKey, ?array $optionsHeaders): array
    {
        return Headers::providerHeadersToRecord(
            ['authorization' => "Bearer {$apiKey}", 'content-type' => 'application/json'],
            $model->headers,
            $optionsHeaders,
        ) ?? [];
    }
}
