<?php

declare(strict_types=1);

namespace Pig\Ai\Providers;

use Generator;
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
use Pig\Ai\TranscriptContext;
use Pig\Ai\Usage;
use Pig\Ai\Cost;
use Pig\Ai\Timestamp;
use Pig\Ai\Utils\AssistantMessageEventStream;
use Pig\Ai\Utils\ConstrainedSampling;
use Pig\Ai\Utils\Headers;
use Pig\Ai\Utils\JsJson;
use Pig\Ai\Utils\PigUserAgent;
use Pig\Ai\Utils\Text;
use Pig\Ai\Utils\TextDecoder;
use Pig\Ai\Utils\Transcript;
use Pig\Async\AbortController;
use Pig\Async\AbortError;
use Pig\Async\AbortSignal;
use Pig\Async\Async;
use Pig\Async\Deferred;
use Pig\Async\Loop;
use Throwable;

/**
 * The Responses API on ChatGPT's Codex backend, signed in with a ChatGPT Plus/Pro subscription —
 * upstream's `api/openai-codex-responses.ts`.
 *
 * The conversation and the stream are `OpenAiResponsesShared`'s. What is the backend's own:
 *
 * - **The key is a ChatGPT OAuth access token** (`Utils\Oauth\OpenAiCodex`), and the account it
 *   belongs to — the JWT's `https://api.openai.com/auth.chatgpt_account_id` claim — goes beside it
 *   as `chatgpt-account-id`, with `originator: pi`.
 * - **The leading system prompt is `instructions`**, not an input item ("You are a helpful
 *   assistant." when there is none), and the body always says `store: false`, `text.verbosity`,
 *   `include: ["reasoning.encrypted_content"]`, `tool_choice` (`auto`) and `parallel_tool_calls`.
 *   There is no `max_output_tokens`: the backend takes none.
 * - **Its own retry loop**, not `retryProviderRequest()`: `maxRetries` attempts (none by default),
 *   a 429/5xx or a rate-limit text retried after `retry-after-ms`/`retry-after` or a doubling second,
 *   a terminal quota message never; and a ChatGPT usage limit worded as one (`parseErrorResponse()`).
 * - **Its own SSE reader** (`parseSSE()`): frames split on a blank line, `data:` lines only, a
 *   frame that is not JSON is `Invalid Codex SSE JSON: …`; and the events mapped on the way
 *   (`mapCodexEvents()`): an `error` event or `response.failed` is a `Codex error`, and
 *   `response.done` / `.completed` / `.incomplete` end the stream as one `response.completed`,
 *   carrying `end_turn`.
 *
 * **What is not here: the WebSocket transport.** Upstream's default `transport` is `auto`, which
 * tries a cached `wss://…/codex/responses` connection first (with `previous_response_id`
 * continuation) and falls back to SSE; pig has no WebSocket client in `pig/ai`, so it speaks SSE
 * only — what upstream does with `transport: "sse"`. And the SSE body goes out uncompressed: upstream
 * zstd-compresses it where `node:zlib` has `zstdCompressSync` and sends it plain where it does not
 * ("Callers fall back to sending the uncompressed JSON"); PHP has no zstd without an extension.
 */
final class OpenAiCodexResponses
{
    /** Upstream's `DEFAULT_CODEX_BASE_URL`. */
    public const string DEFAULT_CODEX_BASE_URL = 'https://chatgpt.com/backend-api';

    /** Upstream's `JWT_CLAIM_PATH`. */
    public const string JWT_CLAIM_PATH = 'https://api.openai.com/auth';

    /** Upstream's `DEFAULT_MAX_RETRIES`. */
    private const int DEFAULT_MAX_RETRIES = 0;

    /** Upstream's `BASE_DELAY_MS`. */
    private const int BASE_DELAY_MS = 1000;

    /** Upstream's `DEFAULT_MAX_RETRY_DELAY_MS`. */
    private const int DEFAULT_MAX_RETRY_DELAY_MS = 60_000;

    /** Upstream's `CODEX_TOOL_CALL_PROVIDERS`. */
    private const array CODEX_TOOL_CALL_PROVIDERS = ['openai', 'openai-codex', 'opencode'];

    /** Upstream's `CODEX_RESPONSE_STATUSES`. */
    private const array CODEX_RESPONSE_STATUSES = ['completed', 'incomplete', 'failed', 'cancelled', 'queued', 'in_progress'];

    private const string ABORTED = 'Request was aborted';

    public function __construct(private readonly HttpClient $http = new HttpClient())
    {
    }

    /** Returns at once; the response fills in as it arrives. */
    public function stream(Model $model, TranscriptContext $context, ?OpenAiCodexResponsesOptions $options = null): AssistantMessageEventStream
    {
        $stream = new AssistantMessageEventStream();
        $normalizedContext = Transcript::resolveTranscript($context, self::compat($model)?->supportsMidConvoSystemMessages ?? false);

        Async::spawn(function () use ($stream, $model, $normalizedContext, $options): void {
            $this->run($stream, $model, $normalizedContext, $options);
        });

        return $stream;
    }

    private function run(AssistantMessageEventStream $stream, Model $model, TranscriptContext $context, ?OpenAiCodexResponsesOptions $options): void
    {
        $builder = new AssistantMessageBuilder($model);
        $builder->setStopReason(StopReason::Pending);
        $signal = $options?->signal;

        try {
            $apiKey = $options?->apiKey;

            if ($apiKey === null || $apiKey === '') {
                throw new ProviderError("No API key for provider: {$model->provider}");
            }

            $accountId = self::extractAccountId($apiKey);
            $grammar = ConstrainedSampling::createGrammarToolInputProperties(
                Transcript::getDeclaredTools($context->messages),
                self::compat($model)?->grammarTools ?? false,
            );
            // `options?.cacheRetention === "none" ? undefined : options?.sessionId` — the option as
            // given, not resolved: `PI_CACHE_RETENTION` plays no part here.
            $cacheSessionId = $options?->cacheRetention === 'none' ? null : $options?->sessionId;
            $codexSessionId = OpenAiPromptCache::clampOpenAIPromptCacheKey($cacheSessionId);
            $body = self::buildRequestBody($model, $context, $options, $codexSessionId, $grammar);
            $nextBody = $options?->onPayload !== null ? ($options->onPayload)($body, $model) : null;

            if ($nextBody !== null) {
                $body = (array) $nextBody;
            }

            $sseHeaders = self::buildSSEHeaders($model->headers, $options?->headers, $accountId, $apiKey, $codexSessionId);
            $bodyJson = self::encode($body);
            $httpTimeoutMs = self::normalizeTimeoutMs($options?->timeoutMs);

            $response = null;
            $lastError = null;
            $maxRetries = $options?->maxRetries ?? self::DEFAULT_MAX_RETRIES;

            for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
                if ($signal?->aborted() ?? false) {
                    throw new ProviderError(self::ABORTED);
                }

                try {
                    $response = $this->fetch(new Request('POST', self::resolveCodexUrl($model->baseUrl), $sseHeaders, $bodyJson), $signal, $httpTimeoutMs);

                    if ($options?->onResponse !== null) {
                        ($options->onResponse)(['status' => $response->status, 'headers' => $response->headers], $model);
                    }

                    if ($response->isSuccessful()) {
                        break;
                    }

                    $errorText = (new TextDecoder())->decode($response->body->all(), false);

                    if ($attempt < $maxRetries && self::isRetryableError($response->status, $errorText)) {
                        $retryAfterDelayMs = self::getRetryAfterDelayMs($response);
                        $delayMs = $retryAfterDelayMs === null
                            ? self::BASE_DELAY_MS * 2 ** $attempt
                            : self::validateRetryDelayMs($retryAfterDelayMs, $options);

                        self::sleep($delayMs, $signal);

                        continue;
                    }

                    // "Parse error for friendly message on final attempt or non-retryable error".
                    $info = self::parseErrorResponse($response->status, $response->reason, $errorText);

                    throw new ProviderError($info['friendlyMessage'] ?? $info['message']);
                } catch (Throwable $error) {
                    if ($error instanceof AbortError || $error->getMessage() === self::ABORTED) {
                        throw new ProviderError(self::ABORTED, previous: $error);
                    }

                    $lastError = $error;

                    // "Network errors are retryable" — and so, as upstream has it, is any error the
                    // attempt threw, a refused request's friendly message included, unless it names a
                    // usage limit or was a server's retry delay past the cap.
                    if ($attempt < $maxRetries
                        && !($lastError instanceof RetryDelayExceededError)
                        && !str_contains($lastError->getMessage(), 'usage limit')
                    ) {
                        self::sleep(self::BASE_DELAY_MS * 2 ** $attempt, $signal);

                        continue;
                    }

                    throw $lastError;
                }
            }

            if ($response === null || !$response->isSuccessful()) {
                throw $lastError ?? new ProviderError('Failed after retries');
            }

            $stream->push(new StartEvent($builder->snapshot()));

            // `processStream()`: the SSE, mapped, through the shared reader, priced by Codex's own
            // service tier rules.
            OpenAiResponsesShared::processResponsesStream(
                self::mapCodexEvents(self::parseSSE($response, $signal), $builder, $model, $options?->onProviderStreamEvent),
                $builder,
                $stream,
                $model,
                [
                    'serviceTier' => $options?->serviceTier,
                    'grammarToolInputProperties' => $grammar,
                    'resolveServiceTier' => self::resolveCodexServiceTier(...),
                    'applyServiceTierPricing' => static fn (AssistantMessageBuilder $builder, ?string $serviceTier) => self::applyServiceTierPricing($builder, $model, $serviceTier),
                ],
            );

            if ($signal?->aborted() ?? false) {
                throw new ProviderError(self::ABORTED);
            }

            // Upstream's `assertSuccessfulOutput()`.
            if ($builder->stopReason() === StopReason::Pending) {
                throw new ProviderError('Codex stream ended without a stop reason');
            }

            if ($builder->stopReason() === StopReason::Error || $builder->stopReason() === StopReason::Aborted) {
                $message = $builder->errorMessage();

                throw new ProviderError($message !== null && $message !== '' ? $message : 'An unknown error occurred');
            }

            $message = $builder->snapshot();
            $stream->push(new DoneEvent($message->stopReason, $message));
            $stream->end();
        } catch (Throwable $error) {
            // `formatProviderError(normalizeProviderError(error))`: none of these carries a status,
            // so the message as it is.
            $builder->fail(SdkRequest::errorMessage($error), $signal?->aborted() ?? false);
            $failed = $builder->snapshot();
            $stream->push(new ErrorEvent($failed->stopReason, $failed));
            $stream->end();
        }
    }

    /**
     * The `fetch()` of one attempt, with upstream's header deadline: "Codex SSE response headers
     * timed out after <ms>ms" when it passed and the caller's own signal did not abort; Node's
     * `fetch failed` for any other failure to get a response; the abort as an `AbortError`. A
     * timeout of 0 or none sets no deadline. The caller's signal reaches the body as well.
     */
    private function fetch(Request $request, ?AbortSignal $signal, ?int $httpTimeoutMs): Response
    {
        $signal?->throwIfAborted();
        $combined = new AbortController();
        $timedOut = false;
        $timer = $httpTimeoutMs !== null && $httpTimeoutMs > 0
            ? Loop::get()->delay($httpTimeoutMs / 1000, static function () use ($combined, &$timedOut): void {
                $timedOut = true;
                $combined->abort('The operation was aborted due to timeout');
            })
            : null;
        $listener = $signal?->onAbort(static function (string $reason) use ($combined): void {
            $combined->abort($reason);
        });

        try {
            return $this->http->send($request, $combined->signal);
        } catch (Throwable $error) {
            if ($listener !== null) {
                $signal?->removeListener($listener);
            }

            if ($timedOut && !($signal?->aborted() ?? false)) {
                throw new ProviderError("Codex SSE response headers timed out after {$httpTimeoutMs}ms", previous: $error);
            }

            if ($signal?->aborted() ?? false) {
                throw new AbortError(self::ABORTED, 0, $error);
            }

            throw new ProviderError('fetch failed', previous: $error);
        } finally {
            if ($timer !== null) {
                Loop::get()->cancel($timer);
            }
        }
    }

    // ---- request building ------------------------------------------------------------------

    /**
     * Upstream's `buildRequestBody(model, context, options, cacheSessionId, grammarToolInputProperties)`.
     *
     * @param array<string, string> $grammar
     * @return array<string, mixed>
     */
    private static function buildRequestBody(Model $model, TranscriptContext $context, ?OpenAiCodexResponsesOptions $options, ?string $cacheSessionId, array $grammar): array
    {
        $compat = self::compat($model);
        $supportsStrictMode = $compat?->strictMode ?? true;
        $supportsGrammarTools = $compat?->grammarTools ?? false;
        $supportsAdditionalTools = $compat?->supportsAdditionalTools ?? false;
        $supportsToolSearch = $compat?->supportsToolSearch ?? false;
        $transcriptTools = Transcript::resolveTranscriptTools($context->messages, $supportsAdditionalTools || $supportsToolSearch);
        $toolOptions = ['strict' => null, 'supportsStrictMode' => $supportsStrictMode, 'supportsOpenAIGrammarTools' => $supportsGrammarTools];
        $messages = OpenAiResponsesShared::convertResponsesMessages($model, $context, self::CODEX_TOOL_CALL_PROVIDERS, [
            'includeSystemPrompt' => false,
            'grammarToolInputProperties' => $grammar,
            'supportsMidConvoSystemMessages' => $compat?->supportsMidConvoSystemMessages ?? false,
            'supportsAdditionalTools' => $supportsAdditionalTools,
            'supportsToolSearch' => $supportsToolSearch,
            'toolOptions' => $toolOptions,
        ]);

        $initialSystemMessage = Transcript::getInitialSystemMessage($context->messages);
        $instructions = $initialSystemMessage !== null ? Text::getSystemMessageText($initialSystemMessage) : '';
        $textVerbosity = $options?->textVerbosity;
        $body = [
            'model' => $model->id,
            'store' => false,
            'stream' => true,
            'instructions' => $instructions !== '' ? $instructions : 'You are a helpful assistant.',
            'input' => $messages,
            'text' => ['verbosity' => $textVerbosity !== null && $textVerbosity !== '' ? $textVerbosity : 'low'],
            'include' => ['reasoning.encrypted_content'],
            // `prompt_cache_key: cacheSessionId` — undefined, and so left out, when there is none.
            ...($cacheSessionId !== null ? ['prompt_cache_key' => $cacheSessionId] : []),
            'tool_choice' => $options?->toolChoice ?? 'auto',
            'parallel_tool_calls' => true,
        ];

        if ($options?->temperature !== null) {
            $body['temperature'] = $options->temperature;
        }

        if ($options?->serviceTier !== null) {
            $body['service_tier'] = $options->serviceTier;
        }

        if ($transcriptTools['requestTools'] !== []) {
            $body['tools'] = OpenAiResponsesShared::convertResponsesTools($transcriptTools['requestTools'], $toolOptions);
        }

        $reasoningEffort = $options?->reasoningEffort;

        if ($reasoningEffort !== null) {
            // `"none"` is the map's `off` — itself when the map does not say — and any other level the
            // map's word for it, its own name when the map has none (`??`, so a null entry too).
            $effort = $reasoningEffort === 'none'
                ? (array_key_exists('off', $model->thinkingLevelMap) ? $model->thinkingLevelMap['off'] : 'none')
                : ($model->thinkingLevelMap[$reasoningEffort] ?? $reasoningEffort);

            if ($effort !== null) {
                $body['reasoning'] = [
                    'effort' => $effort,
                    'summary' => $options->reasoningSummary ?? 'auto',
                ];
            }
        } elseif ($model->reasoning && $model->hasThinkingLevel('off')) {
            $body['reasoning'] = ['effort' => $model->thinkingLevelMap['off'] ?? 'none'];
        }

        return $body;
    }

    /**
     * Upstream's `getServiceTierCostMultiplier()` and `applyServiceTierPricing()`: `flex` 0.5,
     * `priority` 2 (2.5 for gpt-5.5) — no `fast` here, which the Responses API's rule has.
     */
    private static function applyServiceTierPricing(AssistantMessageBuilder $builder, Model $model, ?string $serviceTier): void
    {
        $multiplier = match ($serviceTier) {
            'flex' => 0.5,
            'priority' => $model->id === 'gpt-5.5' ? 2.5 : 2.0,
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
            new Cost($input, $output, $cacheRead, $cacheWrite, $input + $output + $cacheRead + $cacheWrite),
            $usage->reasoning,
            $usage->cacheWrite1h,
        ), priced: true);
    }

    /**
     * Upstream's `resolveCodexServiceTier()`: a response that says `default` to a request for `flex`
     * or `priority` is priced as the request; otherwise the response's tier, else the request's.
     */
    private static function resolveCodexServiceTier(?string $responseServiceTier, ?string $requestServiceTier): ?string
    {
        if ($responseServiceTier === 'default' && ($requestServiceTier === 'flex' || $requestServiceTier === 'priority')) {
            return $requestServiceTier;
        }

        return $responseServiceTier ?? $requestServiceTier;
    }

    /**
     * Upstream's `resolveCodexUrl()`: the base URL (the default when blank) less its trailing
     * slashes, with `/codex/responses` — or `/responses` after a `/codex`, or nothing when it is there.
     */
    public static function resolveCodexUrl(?string $baseUrl = null): string
    {
        $raw = $baseUrl !== null && trim($baseUrl) !== '' ? $baseUrl : self::DEFAULT_CODEX_BASE_URL;
        $normalized = rtrim($raw, '/');

        if (str_ends_with($normalized, '/codex/responses')) {
            return $normalized;
        }

        if (str_ends_with($normalized, '/codex')) {
            return "{$normalized}/responses";
        }

        return "{$normalized}/codex/responses";
    }

    // ---- retries ---------------------------------------------------------------------------

    /** Upstream's `isTerminalRateLimitError()`. */
    private static function isTerminalRateLimitError(string $errorText): bool
    {
        return preg_match('/GoUsageLimitError|FreeUsageLimitError|Monthly usage limit reached|available balance|insufficient_quota|out of budget|quota exceeded|billing/i', $errorText) === 1;
    }

    /** Upstream's `isRetryableError()`. */
    private static function isRetryableError(int $status, string $errorText): bool
    {
        if ($status === 429 && self::isTerminalRateLimitError($errorText)) {
            return false;
        }

        if (in_array($status, [429, 500, 502, 503, 504], true)) {
            return true;
        }

        return preg_match('/rate.?limit|overloaded|service.?unavailable|upstream.?connect|connection.?refused/i', $errorText) === 1;
    }

    /**
     * Upstream's `getRetryAfterDelayMs()`: `retry-after-ms` as a number, else `retry-after` as
     * seconds, else as a date — `Number()` and `Date.parse()`, so an empty `retry-after-ms` is 0.
     */
    private static function getRetryAfterDelayMs(Response $response): ?float
    {
        $retryAfterMs = $response->header('retry-after-ms');

        if ($retryAfterMs !== null) {
            $millis = self::jsNumber($retryAfterMs);

            if (is_finite($millis)) {
                return max(0.0, $millis);
            }
        }

        $retryAfter = $response->header('retry-after');

        if ($retryAfter === null || $retryAfter === '') {
            return null;
        }

        $seconds = self::jsNumber($retryAfter);

        if (is_finite($seconds)) {
            return max(0.0, $seconds * 1000);
        }

        $date = strtotime($retryAfter);

        if ($date !== false) {
            return max(0.0, $date * 1000.0 - Timestamp::nowMs());
        }

        return null;
    }

    /** JavaScript's `Number(string)` for the decimal forms a header carries: blank is 0, junk is NaN. */
    private static function jsNumber(string $value): float
    {
        $trimmed = JsJson::trim($value);

        if ($trimmed === '') {
            return 0.0;
        }

        if (preg_match('/^[+-]?(?:\d+\.?\d*|\.\d+)(?:[eE][+-]?\d+)?$/', $trimmed) === 1) {
            return (float) $trimmed;
        }

        return match ($trimmed) {
            'Infinity', '+Infinity' => INF,
            '-Infinity' => -INF,
            default => NAN,
        };
    }

    /** Upstream's `validateRetryDelayMs()`. */
    private static function validateRetryDelayMs(float $delayMs, ?OpenAiCodexResponsesOptions $options): float
    {
        $maxRetryDelayMs = $options?->maxRetryDelayMs ?? self::DEFAULT_MAX_RETRY_DELAY_MS;

        if ($maxRetryDelayMs > 0 && $delayMs > $maxRetryDelayMs) {
            throw new RetryDelayExceededError(sprintf(
                'Server requested %ds retry delay (max: %ds)',
                (int) ceil($delayMs / 1000),
                (int) ceil($maxRetryDelayMs / 1000),
            ));
        }

        return $delayMs;
    }

    /** Upstream's `sleep(ms, signal)`: `Request was aborted` when the signal is, before or during. */
    private static function sleep(float $ms, ?AbortSignal $signal): void
    {
        if ($signal?->aborted() ?? false) {
            throw new ProviderError(self::ABORTED);
        }

        $done = new Deferred();
        $timer = Loop::get()->delay(max(0.0, $ms) / 1000, static function () use ($done): void {
            if (!$done->isComplete()) {
                $done->complete(true);
            }
        });
        $listener = $signal?->onAbort(static function () use ($done, $timer): void {
            Loop::get()->cancel($timer);

            if (!$done->isComplete()) {
                $done->complete(false);
            }
        });

        try {
            if ($done->future->await() !== true) {
                throw new ProviderError(self::ABORTED);
            }
        } finally {
            if ($listener !== null) {
                $signal?->removeListener($listener);
            }
        }
    }

    /** Upstream's `normalizeTimeoutMs()`. */
    private static function normalizeTimeoutMs(?int $value): ?int
    {
        if ($value === null) {
            return null;
        }

        if ($value < 0) {
            throw new ProviderError("Invalid timeoutMs: {$value}");
        }

        return $value;
    }

    // ---- response processing ---------------------------------------------------------------

    /**
     * Upstream's `mapCodexEvents()`: each event to `onProviderStreamEvent` first, then — an event with
     * no string `type` skipped; `error` and `response.failed` thrown as Codex errors; `response.done`,
     * `.completed` and `.incomplete` handed on as one `response.completed` with the status normalised
     * and `end_turn` kept, after which nothing more is read; anything else as it came.
     *
     * @param iterable<mixed> $events
     * @return Generator<int, array<string, mixed>>
     */
    private static function mapCodexEvents(iterable $events, AssistantMessageBuilder $builder, Model $model, ?\Closure $onProviderStreamEvent): Generator
    {
        foreach ($events as $event) {
            if ($onProviderStreamEvent !== null) {
                try {
                    $onProviderStreamEvent($event, $model);
                } catch (Throwable $error) {
                    // "Keep callback failures out of Codex's WebSocket retry and SSE fallback path."
                    throw new ProviderStreamEventCallbackError($error);
                }
            }

            // A frame that parsed to something other than an object has no `type` to read.
            if (!is_array($event)) {
                continue;
            }

            $type = is_string($event['type'] ?? null) ? $event['type'] : null;

            if ($type === null || $type === '') {
                continue;
            }

            if ($type === 'error') {
                [$code, $message] = self::extractCodexEventError($event);
                $detail = $message !== null && $message !== '' ? $message : ($code !== null && $code !== '' ? $code : JsJson::stringify($event));

                throw new CodexApiError("Codex error: {$detail}", $code, $event);
            }

            if ($type === 'response.failed') {
                $error = is_array($event['response']['error'] ?? null) ? $event['response']['error'] : [];
                $code = is_string($error['code'] ?? null) ? $error['code'] : null;
                $message = $error['message'] ?? null;

                throw new CodexApiError(is_string($message) && $message !== '' ? $message : 'Codex response failed', $code, $event);
            }

            if ($type === 'response.done' || $type === 'response.completed' || $type === 'response.incomplete') {
                $response = $event['response'] ?? null;

                if (is_array($response) && is_bool($response['end_turn'] ?? null)) {
                    $builder->setEndTurn($response['end_turn']);
                }

                if (is_array($response)) {
                    // `{...response, status: normalizeCodexStatus(response.status)}`: an unknown status
                    // is undefined, which reads as no status at all.
                    $status = $response['status'] ?? null;
                    unset($response['status']);

                    if (is_string($status) && in_array($status, self::CODEX_RESPONSE_STATUSES, true)) {
                        $response['status'] = $status;
                    }
                }

                yield [...$event, 'type' => 'response.completed', 'response' => $response];

                return;
            }

            yield $event;
        }
    }

    /**
     * Upstream's `extractCodexEventError()`: `code` and `message` from the event, else from its
     * nested `error`.
     *
     * @param array<string, mixed> $event
     * @return array{0: string|null, 1: string|null}
     */
    private static function extractCodexEventError(array $event): array
    {
        $nested = is_array($event['error'] ?? null) ? $event['error'] : null;
        $code = is_string($event['code'] ?? null) ? $event['code'] : (is_string($nested['code'] ?? null) ? $nested['code'] : null);
        $message = is_string($event['message'] ?? null) ? $event['message'] : (is_string($nested['message'] ?? null) ? $nested['message'] : null);

        return [$code, $message];
    }

    /**
     * Upstream's `parseSSE()`: the body decoded as it arrives, split into frames at each `\n\n`, a
     * frame's `data:` lines (each trimmed after the prefix) joined by `\n` and trimmed, `[DONE]` and
     * an empty data skipped, the rest `JSON.parse`d — `Invalid Codex SSE JSON: <V8's message>` when it
     * is not JSON. The end of the body ends a frame that had no blank line after it. An abort —
     * between reads or during one — is `Request was aborted`.
     *
     * @return Generator<int, mixed>
     */
    private static function parseSSE(Response $response, ?AbortSignal $signal): Generator
    {
        $decoder = new TextDecoder();
        $buffer = '';

        try {
            foreach ($response->body as $value) {
                if ($signal?->aborted() ?? false) {
                    throw new ProviderError(self::ABORTED);
                }

                $buffer .= $decoder->decode($value, true);

                yield from self::frames($buffer);
            }
        } catch (AbortError $error) {
            throw new ProviderError(self::ABORTED, previous: $error);
        }

        if ($signal?->aborted() ?? false) {
            throw new ProviderError(self::ABORTED);
        }

        $buffer .= $decoder->decode('', false);

        // "Treat EOF as terminating the residual SSE frame."
        if (JsJson::trim($buffer) !== '') {
            $buffer .= "\n\n";
        }

        yield from self::frames($buffer);
    }

    /**
     * Every whole frame in `$buffer`, which keeps what is left.
     *
     * @return Generator<int, mixed>
     */
    private static function frames(string &$buffer): Generator
    {
        while (($idx = strpos($buffer, "\n\n")) !== false) {
            $chunk = substr($buffer, 0, $idx);
            $buffer = substr($buffer, $idx + 2);
            $dataLines = [];

            foreach (explode("\n", $chunk) as $line) {
                if (str_starts_with($line, 'data:')) {
                    $dataLines[] = JsJson::trim(substr($line, 5));
                }
            }

            if ($dataLines === []) {
                continue;
            }

            $data = JsJson::trim(implode("\n", $dataLines));

            if ($data === '' || $data === '[DONE]') {
                continue;
            }

            try {
                $parsed = JsJson::parse($data);
            } catch (Throwable $cause) {
                throw new CodexProtocolError('Invalid Codex SSE JSON: ' . self::formatThrownValue($cause), $data, $cause);
            }

            yield $parsed;
        }
    }

    /** Upstream's `formatThrownValue()` for an `Error`: its message (V8's, for a `SyntaxError`). */
    private static function formatThrownValue(Throwable $value): string
    {
        return $value->getMessage();
    }

    // ---- errors ----------------------------------------------------------------------------

    /**
     * Upstream's `parseErrorResponse()`: the body (else the status text, else `Request failed`) as
     * the message, and — for a JSON `error` whose `code` or `type` says a usage or rate limit, or any
     * 429 — "You have hit your ChatGPT usage limit (<plan> plan). Try again in ~<n> min." as the
     * friendly message. The error's own `message` wins over both when it has one.
     *
     * @return array{message: string, friendlyMessage: string|null}
     */
    private static function parseErrorResponse(int $status, string $statusText, string $raw): array
    {
        $message = $raw !== '' ? $raw : ($statusText !== '' ? $statusText : 'Request failed');
        $friendlyMessage = null;
        $parsed = json_decode($raw, true);
        $err = is_array($parsed) && !array_is_list($parsed) ? ($parsed['error'] ?? null) : null;

        // `if (err)`: any object, or a value that is truthy — a string error has no fields to read.
        if (self::truthy($err)) {
            $fields = is_array($err) ? $err : [];
            $code = self::truthyString($fields['code'] ?? null) ?? self::truthyString($fields['type'] ?? null) ?? '';

            if (preg_match('/usage_limit_reached|usage_not_included|rate_limit_exceeded/i', $code) === 1 || $status === 429) {
                $planType = self::truthyString($fields['plan_type'] ?? null);
                $plan = $planType !== null ? ' (' . strtolower($planType) . ' plan)' : '';
                $resetsAt = $fields['resets_at'] ?? null;
                $mins = (is_int($resetsAt) || is_float($resetsAt)) && self::truthy($resetsAt)
                    ? (int) max(0, self::jsRound(($resetsAt * 1000 - Timestamp::nowMs()) / 60000))
                    : null;
                $when = $mins !== null ? " Try again in ~{$mins} min." : '';
                $friendlyMessage = trim("You have hit your ChatGPT usage limit{$plan}.{$when}");
            }

            $message = self::truthyString($fields['message'] ?? null) ?? $friendlyMessage ?? $message;
        }

        return ['message' => $message, 'friendlyMessage' => $friendlyMessage];
    }

    /** A non-empty string, or null — JavaScript's `value || …` for a field that should be a string. */
    private static function truthyString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /** `Math.round()`: halves go up, toward positive infinity. */
    private static function jsRound(float $value): float
    {
        return floor($value + 0.5);
    }

    // ---- auth and headers ------------------------------------------------------------------

    /**
     * Upstream's `extractAccountId()`: the JWT's `https://api.openai.com/auth.chatgpt_account_id`.
     * Anything short of three parts, a payload `atob()` or `JSON.parse()` refuses, or no account id
     * is `Failed to extract accountId from token`.
     */
    public static function extractAccountId(string $token): string
    {
        $parts = explode('.', $token);
        $payload = count($parts) === 3 ? self::decodeJwtPayload($parts[1]) : null;
        $claims = is_array($payload) ? ($payload[self::JWT_CLAIM_PATH] ?? null) : null;
        $accountId = is_array($claims) ? ($claims['chatgpt_account_id'] ?? null) : null;

        if (!self::truthy($accountId)) {
            throw new ProviderError('Failed to extract accountId from token');
        }

        return is_string($accountId) ? $accountId : JsJson::toString($accountId);
    }

    /**
     * `JSON.parse(atob(payload))`, or null where either throws. `atob()` is forgiving base64 — ASCII
     * whitespace removed, the padding optional — but the standard alphabet only, so a payload in
     * base64url (`-`, `_`) is refused, as upstream's is.
     */
    public static function decodeJwtPayload(string $payload): mixed
    {
        $data = (string) preg_replace('/[\t\n\f\r ]/', '', $payload);

        if (strlen($data) % 4 === 0) {
            $data = (string) preg_replace('/={1,2}$/', '', $data);
        }

        if (strlen($data) % 4 === 1 || preg_match('/^[A-Za-z0-9+\/]*$/', $data) !== 1) {
            return null;
        }

        $decoded = base64_decode($data, false);

        if ($decoded === false) {
            return null;
        }

        try {
            return JsJson::parse($decoded);
        } catch (Throwable) {
            // `decodeJwt()` and `extractAccountId()` both answer a payload that is not JSON with
            // their own outcome — null, or the one error above — rather than with the parse error.
            return null;
        }
    }

    /** JavaScript truthiness of a decoded JSON value. */
    private static function truthy(mixed $value): bool
    {
        return $value !== null && $value !== false && $value !== '' && $value !== 0 && $value !== 0.0;
    }

    /**
     * Upstream's `buildBaseCodexHeaders()` and `buildSSEHeaders()`: the model's headers, the
     * options' over them (a null deletes), then the backend's own — `Authorization`,
     * `chatgpt-account-id`, `originator: pi`, `User-Agent` — and the SSE ones: `OpenAI-Beta:
     * responses=experimental`, `accept`, `content-type`, and the session as `session-id` and
     * `x-client-request-id`.
     *
     * @param array<string, string> $initHeaders
     * @param array<string, string|null>|null $additionalHeaders
     * @return array<string, string>
     */
    private static function buildSSEHeaders(array $initHeaders, ?array $additionalHeaders, string $accountId, string $token, ?string $sessionId): array
    {
        $built = Headers::build(
            $initHeaders,
            $additionalHeaders,
            [
                'Authorization' => "Bearer {$token}",
                'chatgpt-account-id' => $accountId,
                'originator' => 'pi',
                'User-Agent' => PigUserAgent::get(),
            ],
            [
                'OpenAI-Beta' => 'responses=experimental',
                'accept' => 'text/event-stream',
                'content-type' => 'application/json',
            ],
            $sessionId !== null && $sessionId !== '' ? ['session-id' => $sessionId, 'x-client-request-id' => $sessionId] : null,
        );

        return $built['values'];
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
