<?php

declare(strict_types=1);

namespace Pig\Ai\Providers;

use Generator;
use Pig\Ai\AssistantMessageDiagnostic;
use Pig\Ai\Context;
use Pig\Ai\DiagnosticErrorInfo;
use Pig\Ai\DoneEvent;
use Pig\Ai\ErrorEvent;
use Pig\Ai\Http\HttpClient;
use Pig\Ai\Http\Request;
use Pig\Ai\Http\Response;
use Pig\Ai\Http\WebSocket;
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
use Pig\Ai\Utils\Uuid;
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
 * - **The WebSocket transport first**, as upstream's default `transport` (`auto`) has it: a
 *   `wss://…/codex/responses` connection (`Http\WebSocket`) kept per session, with
 *   `previous_response_id` continuation, and SSE when it fails before the answer starts — after
 *   which the session stays on SSE. `sse` skips it; `websocket` uses it without the continuation.
 *
 * The SSE body goes out uncompressed: upstream
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

    /** Upstream's `DEFAULT_WEBSOCKET_CONNECT_TIMEOUT_MS`. */
    private const int DEFAULT_WEBSOCKET_CONNECT_TIMEOUT_MS = 15_000;

    private const int WEBSOCKET_MESSAGE_TOO_BIG_CLOSE_CODE = 1009;

    private const string WEBSOCKET_CONNECTION_LIMIT_REACHED_CODE = 'websocket_connection_limit_reached';

    private const string PREVIOUS_RESPONSE_NOT_FOUND_CODE = 'previous_response_not_found';

    private const string OPENAI_BETA_RESPONSES_WEBSOCKETS = 'responses_websockets=2026-02-06';

    /** Upstream's `SESSION_WEBSOCKET_CACHE_TTL_MS`: how long a session's connection waits unused. */
    private const int SESSION_WEBSOCKET_CACHE_TTL_MS = 5 * 60 * 1000;

    /** Upstream's `SESSION_WEBSOCKET_MAX_AGE_MS`: how old a connection may be and still be reused. */
    private const int SESSION_WEBSOCKET_MAX_AGE_MS = 55 * 60 * 1000;

    /**
     * Upstream's `websocketSessionCache`: each session's connection, by account. Process-wide, as
     * upstream's module state is — every request builds its own provider.
     *
     * @var array<string, array<string, CachedWebSocketConnection>>
     */
    private static array $websocketSessionCache = [];

    /** @var array<string, array<string, mixed>> upstream's `websocketDebugStats` */
    private static array $websocketDebugStats = [];

    /** @var array<string, true> upstream's `websocketSseFallbackSessions` */
    private static array $websocketSseFallbackSessions = [];

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
            $websocketConnectTimeoutMs = self::normalizeTimeoutMs($options?->websocketConnectTimeoutMs);
            $transport = $options?->transport !== null && $options->transport !== '' ? $options->transport : 'auto';
            $startEmitted = false;
            $websocketDisabledForSession = $transport !== 'sse' && self::isWebSocketSseFallbackActive($cacheSessionId);

            if ($websocketDisabledForSession) {
                self::recordWebSocketSseFallback($cacheSessionId);
            }

            if ($transport !== 'sse' && !$websocketDisabledForSession) {
                $websocketRequestId = $codexSessionId !== null && $codexSessionId !== '' ? $codexSessionId : Uuid::v7();
                $websocketHeaders = self::buildWebSocketHeaders($model->headers, $options?->headers, $accountId, $apiKey, $websocketRequestId);
                $retriedWebSocketConnectionLimit = false;
                $retriedMissingWebSocketContinuation = false;

                while (true) {
                    $websocketStarted = false;

                    try {
                        $this->processWebSocketStream(
                            self::resolveCodexWebSocketUrl($model->baseUrl),
                            $body,
                            $websocketHeaders,
                            $builder,
                            $stream,
                            $model,
                            static function () use (&$websocketStarted, &$startEmitted, $stream, $builder): void {
                                $websocketStarted = true;

                                if (!$startEmitted) {
                                    $startEmitted = true;
                                    $stream->push(new StartEvent($builder->snapshot()));
                                }
                            },
                            $httpTimeoutMs,
                            $websocketConnectTimeoutMs,
                            $cacheSessionId,
                            $accountId,
                            $grammar,
                            $transport,
                            $options,
                        );

                        if ($signal?->aborted() ?? false) {
                            throw new ProviderError(self::ABORTED);
                        }

                        self::assertSuccessfulOutput($builder);
                        $message = $builder->snapshot();
                        $stream->push(new DoneEvent($message->stopReason, $message));
                        $stream->end();

                        return;
                    } catch (Throwable $error) {
                        $aborted = $signal?->aborted() ?? false;
                        $connectionLimitBeforeStart = !$websocketStarted && self::isCodexErrorCode($error, self::WEBSOCKET_CONNECTION_LIMIT_REACHED_CODE);

                        if (!$aborted && self::isCodexErrorCode($error, self::PREVIOUS_RESPONSE_NOT_FOUND_CODE) && !$retriedMissingWebSocketContinuation) {
                            $retriedMissingWebSocketContinuation = true;

                            continue;
                        }

                        if (!$aborted && $connectionLimitBeforeStart && !$retriedWebSocketConnectionLimit) {
                            $retriedWebSocketConnectionLimit = true;

                            continue;
                        }

                        if ($aborted || (self::isCodexNonTransportError($error) && !$connectionLimitBeforeStart)) {
                            throw $error;
                        }

                        $builder->addDiagnostic(new AssistantMessageDiagnostic(
                            'provider_transport_failure',
                            Timestamp::nowMs(),
                            new DiagnosticErrorInfo($error->getMessage(), (new \ReflectionClass($error))->getShortName(), $error->getTraceAsString(), $error instanceof CodexApiError ? $error->codexCode : null),
                            [
                                'configuredTransport' => $transport,
                                ...($websocketStarted ? [] : ['fallbackTransport' => 'sse']),
                                'eventsEmitted' => $websocketStarted,
                                'phase' => $websocketStarted ? 'after_message_stream_start' : 'before_message_stream_start',
                                'requestBytes' => strlen($bodyJson),
                            ],
                        ));
                        self::recordWebSocketFailure($cacheSessionId, $error);

                        if ($websocketStarted) {
                            throw $error;
                        }

                        self::recordWebSocketSseFallback($cacheSessionId);

                        break;
                    }
                }
            }

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

            if (!$startEmitted) {
                $startEmitted = true;
                $stream->push(new StartEvent($builder->snapshot()));
            }

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

            self::assertSuccessfulOutput($builder);
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

    /** Upstream's `assertSuccessfulOutput()`. */
    private static function assertSuccessfulOutput(AssistantMessageBuilder $builder): void
    {
        if ($builder->stopReason() === StopReason::Pending) {
            throw new ProviderError('Codex stream ended without a stop reason');
        }

        if ($builder->stopReason() === StopReason::Error || $builder->stopReason() === StopReason::Aborted) {
            $message = $builder->errorMessage();

            throw new ProviderError($message !== null && $message !== '' ? $message : 'An unknown error occurred');
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

    // ---- the WebSocket transport -----------------------------------------------------------

    /**
     * Upstream's `processWebSocketStream()`: a connection — the session's own when it has one free
     * — the request sent as one `response.create` message, and its events through the same reader
     * as the SSE's. With `auto` or `websocket-cached`, a request that only adds to the last one on
     * the connection sends just what it adds, after `previous_response_id`; the backend refuses
     * `store: true`, and the continuation lives with the connection instead.
     *
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     * @param array<string, string> $grammar
     */
    private function processWebSocketStream(
        string $url,
        array $body,
        array $headers,
        AssistantMessageBuilder $builder,
        AssistantMessageEventStream $stream,
        Model $model,
        \Closure $onStart,
        ?int $idleTimeoutMs,
        ?int $websocketConnectTimeoutMs,
        ?string $cacheSessionId,
        string $accountId,
        array $grammar,
        string $transport,
        ?OpenAiCodexResponsesOptions $options,
    ): void {
        $signal = $options?->signal;
        [$socket, $entry, $reused, $release] = self::acquireWebSocket($url, $headers, $cacheSessionId, $accountId, $signal, $websocketConnectTimeoutMs);
        $keepConnection = true;
        $useCachedContext = $transport === 'websocket-cached' || $transport === 'auto';
        $requestBody = $useCachedContext && $entry !== null ? self::buildCachedWebSocketRequestBody($entry, $body) : $body;

        if ($cacheSessionId !== null) {
            self::ensureDebugStats($cacheSessionId);
            self::$websocketDebugStats[$cacheSessionId]['requests']++;
            self::$websocketDebugStats[$cacheSessionId][$reused ? 'connectionsReused' : 'connectionsCreated']++;

            if ($useCachedContext) {
                self::$websocketDebugStats[$cacheSessionId]['cachedContextRequests']++;
            }

            if (($requestBody['store'] ?? null) === true) {
                self::$websocketDebugStats[$cacheSessionId]['storeTrueRequests']++;
            }

            $inputItems = is_array($requestBody['input'] ?? null) ? count($requestBody['input']) : 0;
            self::$websocketDebugStats[$cacheSessionId]['lastInputItems'] = $inputItems;

            if (isset($requestBody['previous_response_id']) && $requestBody['previous_response_id'] !== '') {
                self::$websocketDebugStats[$cacheSessionId]['deltaRequests']++;
                self::$websocketDebugStats[$cacheSessionId]['lastDeltaInputItems'] = $inputItems;
                self::$websocketDebugStats[$cacheSessionId]['lastPreviousResponseId'] = $requestBody['previous_response_id'];
            } else {
                self::$websocketDebugStats[$cacheSessionId]['fullContextRequests']++;
                unset(self::$websocketDebugStats[$cacheSessionId]['lastDeltaInputItems'], self::$websocketDebugStats[$cacheSessionId]['lastPreviousResponseId']);
            }
        }

        try {
            $socket->send(self::encode(['type' => 'response.create', ...$requestBody]), $signal);
            OpenAiResponsesShared::processResponsesStream(
                self::startOnFirstEvent(
                    self::mapCodexEvents(self::parseWebSocket($socket, $signal, $idleTimeoutMs), $builder, $model, $options?->onProviderStreamEvent),
                    $onStart,
                ),
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
                $keepConnection = false;
            } elseif ($useCachedContext && $entry !== null && $builder->responseId() !== null && $builder->responseId() !== '') {
                $items = OpenAiResponsesShared::convertResponsesMessages(
                    $model,
                    Transcript::normalizeContext(new Context([$builder->snapshot()])),
                    self::CODEX_TOOL_CALL_PROVIDERS,
                    ['includeSystemPrompt' => false, 'grammarToolInputProperties' => $grammar],
                );
                $entry->continuation = [
                    'lastRequestBody' => $body,
                    'lastResponseId' => $builder->responseId(),
                    'lastResponseItems' => array_values(array_filter(
                        $items,
                        static fn (mixed $item): bool => !in_array(is_array($item) ? ($item['type'] ?? null) : null, ['function_call_output', 'custom_tool_call_output'], true),
                    )),
                ];
            }
        } catch (Throwable $error) {
            if ($entry !== null) {
                $entry->continuation = null;
            }

            $keepConnection = false;

            throw $error;
        } finally {
            $release($keepConnection);
        }
    }

    /**
     * Upstream's `acquireWebSocket()`: with no session, a connection of its own, closed after; with
     * one, the session's cached connection when it is free, open and under 55 minutes old, else a
     * new one that becomes the session's — or, while the cached one is busy, one of its own.
     *
     * @param array<string, string> $headers
     * @return array{0: WebSocket, 1: CachedWebSocketConnection|null, 2: bool, 3: \Closure(bool): void}
     */
    private static function acquireWebSocket(string $url, array $headers, ?string $sessionId, string $accountId, ?AbortSignal $signal, ?int $connectTimeoutMs): array
    {
        if ($sessionId === null) {
            $socket = self::connectWebSocket($url, $headers, $signal, $connectTimeoutMs);

            return [$socket, null, false, static fn (bool $keep) => $socket->close()];
        }

        $cached = self::$websocketSessionCache[$sessionId][$accountId] ?? null;

        // Upstream's idle timer, had it fired.
        if ($cached !== null && !$cached->busy && $cached->idleSince !== null
            && Timestamp::nowMs() - $cached->idleSince >= self::SESSION_WEBSOCKET_CACHE_TTL_MS) {
            $cached->socket->close(1000, 'idle_timeout');
            self::forgetWebSocket($sessionId, $accountId, $cached);
            $cached = null;
        }

        if ($cached !== null) {
            $cached->idleSince = null;

            if (!$cached->busy && Timestamp::nowMs() - $cached->createdAt >= self::SESSION_WEBSOCKET_MAX_AGE_MS) {
                $cached->socket->close(1000, 'connection_age_limit');
                self::forgetWebSocket($sessionId, $accountId, $cached);
            } elseif (!$cached->busy && $cached->socket->isOpen()) {
                $cached->busy = true;

                return [$cached->socket, $cached, true, self::releaser($sessionId, $accountId, $cached)];
            } elseif ($cached->busy) {
                $socket = self::connectWebSocket($url, $headers, $signal, $connectTimeoutMs);

                return [$socket, null, false, static fn (bool $keep) => $socket->close()];
            } else {
                $cached->socket->close();
                self::forgetWebSocket($sessionId, $accountId, $cached);
            }
        }

        $socket = self::connectWebSocket($url, $headers, $signal, $connectTimeoutMs);
        $entry = new CachedWebSocketConnection($socket, true, Timestamp::nowMs());
        self::$websocketSessionCache[$sessionId][$accountId] = $entry;

        return [$socket, $entry, false, self::releaser($sessionId, $accountId, $entry)];
    }

    /** @return \Closure(bool): void */
    private static function releaser(string $sessionId, string $accountId, CachedWebSocketConnection $entry): \Closure
    {
        return static function (bool $keep) use ($sessionId, $accountId, $entry): void {
            if (!$keep || !$entry->socket->isOpen()) {
                $entry->socket->close();
                self::forgetWebSocket($sessionId, $accountId, $entry);

                return;
            }

            $entry->busy = false;
            $entry->idleSince = Timestamp::nowMs();
        };
    }

    private static function forgetWebSocket(string $sessionId, string $accountId, CachedWebSocketConnection $entry): void
    {
        if ((self::$websocketSessionCache[$sessionId][$accountId] ?? null) === $entry) {
            unset(self::$websocketSessionCache[$sessionId][$accountId]);
        }

        if ((self::$websocketSessionCache[$sessionId] ?? null) === []) {
            unset(self::$websocketSessionCache[$sessionId]);
        }
    }

    /**
     * Upstream's `connectWebSocket()`: the handshake within `connectTimeoutMs` (15 seconds unless
     * given; 0 for no limit) — `WebSocket connect timeout after <ms>ms` past it — and an abort as
     * `Request was aborted`.
     *
     * @param array<string, string> $headers
     */
    private static function connectWebSocket(string $url, array $headers, ?AbortSignal $signal, ?int $connectTimeoutMs): WebSocket
    {
        $timeoutMs = $connectTimeoutMs ?? self::DEFAULT_WEBSOCKET_CONNECT_TIMEOUT_MS;

        return self::within(
            $timeoutMs,
            $signal,
            static fn (AbortSignal $combined): WebSocket => WebSocket::connect($url, $headers, $combined),
            "WebSocket connect timeout after {$timeoutMs}ms",
        );
    }

    /**
     * Run $fn with a signal that aborts when $signal does or $timeoutMs passes (none when it is
     * null or 0): the timeout is a `ProviderError` with $timeoutMessage, an abort `Request was
     * aborted`.
     *
     * @template T
     * @param \Closure(AbortSignal): T $fn
     * @return T
     */
    private static function within(?int $timeoutMs, ?AbortSignal $signal, \Closure $fn, string $timeoutMessage, ?\Closure $onTimeout = null): mixed
    {
        if ($signal?->aborted() ?? false) {
            throw new ProviderError(self::ABORTED);
        }

        $combined = new AbortController();
        $timedOut = false;
        $timer = $timeoutMs !== null && $timeoutMs > 0
            ? Loop::get()->delay($timeoutMs / 1000, static function () use ($combined, &$timedOut): void {
                $timedOut = true;
                $combined->abort('timeout');
            })
            : null;
        $listener = $signal?->onAbort(static function (string $reason) use ($combined): void {
            $combined->abort($reason);
        });

        try {
            return $fn($combined->signal);
        } catch (AbortError $error) {
            if ($timedOut && !($signal?->aborted() ?? false)) {
                if ($onTimeout !== null) {
                    $onTimeout();
                }

                throw new ProviderError($timeoutMessage, previous: $error);
            }

            throw new ProviderError(self::ABORTED, previous: $error);
        } finally {
            if ($timer !== null) {
                Loop::get()->cancel($timer);
            }

            if ($listener !== null) {
                $signal?->removeListener($listener);
            }
        }
    }

    /**
     * Upstream's `parseWebSocket()`: each message `JSON.parse`d — `Invalid Codex WebSocket JSON:
     * <message>` when it is not — until `response.completed`, `.done` or `.incomplete`, after
     * which nothing more is read. Each wait is held to the idle timeout (`WebSocket idle timeout
     * after <ms>ms`, and the connection closed); a close before the end is `WebSocket closed
     * <code> <reason>`.
     *
     * @return Generator<int, mixed>
     */
    private static function parseWebSocket(WebSocket $socket, ?AbortSignal $signal, ?int $idleTimeoutMs): Generator
    {
        while (true) {
            if ($signal?->aborted() ?? false) {
                throw new ProviderError(self::ABORTED);
            }

            $text = self::within(
                $idleTimeoutMs,
                $signal,
                static fn (AbortSignal $combined): ?string => $socket->receive($combined),
                "WebSocket idle timeout after {$idleTimeoutMs}ms",
                static fn () => $socket->close(1000, 'idle_timeout'),
            );

            if ($text === null) {
                throw self::closeError($socket);
            }

            if ($text === '') {
                continue;
            }

            try {
                $parsed = JsJson::parse($text);
            } catch (Throwable $cause) {
                throw new CodexProtocolError('Invalid Codex WebSocket JSON: ' . self::formatThrownValue($cause), $text, $cause);
            }

            yield $parsed;

            $type = is_array($parsed) && is_string($parsed['type'] ?? null) ? $parsed['type'] : '';

            if ($type === 'response.completed' || $type === 'response.done' || $type === 'response.incomplete') {
                return;
            }
        }
    }

    /** Upstream's `extractWebSocketCloseError()`. */
    private static function closeError(WebSocket $socket): ProviderError
    {
        $code = $socket->closeCode();
        $reason = $socket->closeReason() ?? '';

        if ($reason === '' && $code === self::WEBSOCKET_MESSAGE_TOO_BIG_CLOSE_CODE) {
            $reason = 'message too big';
        }

        return new ProviderError(trim('WebSocket closed' . ($code !== null ? " {$code}" : '') . ($reason !== '' ? " {$reason}" : '')));
    }

    /**
     * Upstream's `startWebSocketOutputOnFirstEvent()`.
     *
     * @param iterable<mixed> $events
     * @return Generator<int, mixed>
     */
    private static function startOnFirstEvent(iterable $events, \Closure $onStart): Generator
    {
        $started = false;

        foreach ($events as $event) {
            if (!$started) {
                $started = true;
                $onStart();
            }

            yield $event;
        }
    }

    /**
     * Upstream's `buildCachedWebSocketRequestBody()` and `getCachedWebSocketInputDelta()`: when the
     * body is the last one's but for `input`, and its input begins with the last input followed by
     * what the last response said, only the rest is sent, after the last response's id. Anything
     * else forgets the continuation and sends the whole body.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private static function buildCachedWebSocketRequestBody(CachedWebSocketConnection $entry, array $body): array
    {
        $continuation = $entry->continuation;

        if ($continuation === null) {
            return $body;
        }

        $delta = null;
        $without = static function (array $body): string {
            unset($body['input'], $body['previous_response_id']);

            return self::encode($body);
        };

        if ($without($body) === $without($continuation['lastRequestBody'])) {
            $currentInput = is_array($body['input'] ?? null) ? array_values($body['input']) : [];
            $lastInput = is_array($continuation['lastRequestBody']['input'] ?? null) ? array_values($continuation['lastRequestBody']['input']) : [];
            $baseline = [...$lastInput, ...$continuation['lastResponseItems']];

            if (count($currentInput) >= count($baseline)
                && self::encode(array_slice($currentInput, 0, count($baseline))) === self::encode($baseline)) {
                $delta = array_slice($currentInput, count($baseline));
            }
        }

        if ($delta === null || $continuation['lastResponseId'] === '') {
            $entry->continuation = null;

            return $body;
        }

        $body['previous_response_id'] = $continuation['lastResponseId'];
        $body['input'] = $delta;

        return $body;
    }

    /** Upstream's `resolveCodexWebSocketUrl()`: the SSE URL with `wss:` for `https:`, `ws:` for `http:`. */
    public static function resolveCodexWebSocketUrl(?string $baseUrl = null): string
    {
        $url = self::resolveCodexUrl($baseUrl);

        return (string) preg_replace(['#^https:#i', '#^http:#i'], ['wss:', 'ws:'], $url, 1);
    }

    /** Upstream's `isWebSocketConnectionLimitReachedError()` and `isPreviousResponseNotFoundError()`. */
    private static function isCodexErrorCode(Throwable $error, string $code): bool
    {
        return $error instanceof CodexApiError && $error->codexCode === $code;
    }

    /** Upstream's `isCodexNonTransportError()`: what the backend said, not how it was reached. */
    private static function isCodexNonTransportError(Throwable $error): bool
    {
        return $error instanceof CodexApiError || $error instanceof CodexProtocolError || $error instanceof ProviderStreamEventCallbackError;
    }

    private static function isWebSocketSseFallbackActive(?string $sessionId): bool
    {
        return $sessionId !== null && isset(self::$websocketSseFallbackSessions[$sessionId]);
    }

    private static function recordWebSocketSseFallback(?string $sessionId): void
    {
        if ($sessionId === null) {
            return;
        }

        self::ensureDebugStats($sessionId);
        self::$websocketDebugStats[$sessionId]['sseFallbacks']++;
        self::$websocketDebugStats[$sessionId]['websocketFallbackActive'] = self::isWebSocketSseFallbackActive($sessionId);
    }

    /** Upstream's `recordWebSocketFailure()`: from here on the session goes by SSE. */
    private static function recordWebSocketFailure(?string $sessionId, Throwable $error): void
    {
        if ($sessionId === null) {
            return;
        }

        self::$websocketSseFallbackSessions[$sessionId] = true;
        self::ensureDebugStats($sessionId);
        self::$websocketDebugStats[$sessionId]['websocketFailures']++;
        self::$websocketDebugStats[$sessionId]['lastWebSocketError'] = self::formatThrownValue($error);
        self::$websocketDebugStats[$sessionId]['websocketFallbackActive'] = true;
    }

    /** Upstream's `getOrCreateWebSocketDebugStats()`: the session's stats, started when it has none. */
    private static function ensureDebugStats(string $sessionId): void
    {
        self::$websocketDebugStats[$sessionId] ??= [
            'requests' => 0,
            'connectionsCreated' => 0,
            'connectionsReused' => 0,
            'cachedContextRequests' => 0,
            'storeTrueRequests' => 0,
            'fullContextRequests' => 0,
            'deltaRequests' => 0,
            'lastInputItems' => 0,
            'websocketFailures' => 0,
            'sseFallbacks' => 0,
        ];
    }

    /**
     * Upstream's `getOpenAICodexWebSocketDebugStats()`: what the WebSocket transport has done for a
     * session — requests, connections made and reused, full and delta requests, failures and SSE
     * fallbacks.
     *
     * @return array<string, mixed>|null
     */
    public static function webSocketDebugStats(string $sessionId): ?array
    {
        return self::$websocketDebugStats[$sessionId] ?? null;
    }

    /** Upstream's `resetOpenAICodexWebSocketDebugStats()`: the stats, and the SSE fallback with them. */
    public static function resetWebSocketDebugStats(?string $sessionId = null): void
    {
        if ($sessionId !== null) {
            unset(self::$websocketDebugStats[$sessionId], self::$websocketSseFallbackSessions[$sessionId]);

            return;
        }

        self::$websocketDebugStats = [];
        self::$websocketSseFallbackSessions = [];
    }

    /**
     * Upstream's `closeOpenAICodexWebSocketSessions()`, which its `cleanupSessionResources()` calls
     * when a session is disposed: the session's cached connections, or every one.
     */
    public static function closeWebSocketSessions(?string $sessionId = null): void
    {
        $sessions = $sessionId !== null
            ? [$sessionId => self::$websocketSessionCache[$sessionId] ?? []]
            : self::$websocketSessionCache;

        foreach ($sessions as $entries) {
            foreach ($entries as $entry) {
                $entry->socket->close(1000, 'debug_close');
            }
        }

        if ($sessionId !== null) {
            unset(self::$websocketSessionCache[$sessionId]);
        } else {
            self::$websocketSessionCache = [];
        }
    }

    /**
     * Upstream's `buildWebSocketHeaders()`: the base Codex headers less `accept`, `content-type`
     * and the SSE's beta, then the WebSocket beta and the request id as `x-client-request-id` and
     * `session-id`. (Upstream then deletes `OpenAI-Beta` from the record it hands the socket, but
     * the record's names are lowercase by then, so the beta goes out.)
     *
     * @param array<string, string> $initHeaders
     * @param array<string, string|null>|null $additionalHeaders
     * @return array<string, string>
     */
    private static function buildWebSocketHeaders(array $initHeaders, ?array $additionalHeaders, string $accountId, string $token, string $requestId): array
    {
        $built = Headers::build(
            ...self::baseCodexHeaderSources($initHeaders, $additionalHeaders, $accountId, $token),
            ...[
                ['accept' => null, 'content-type' => null, 'OpenAI-Beta' => null],
                [
                    'OpenAI-Beta' => self::OPENAI_BETA_RESPONSES_WEBSOCKETS,
                    'x-client-request-id' => $requestId,
                    'session-id' => $requestId,
                ],
            ],
        );

        return $built['values'];
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
            ...self::baseCodexHeaderSources($initHeaders, $additionalHeaders, $accountId, $token),
            ...[[
                'OpenAI-Beta' => 'responses=experimental',
                'accept' => 'text/event-stream',
                'content-type' => 'application/json',
            ],
            $sessionId !== null && $sessionId !== '' ? ['session-id' => $sessionId, 'x-client-request-id' => $sessionId] : null],
        );

        return $built['values'];
    }

    /**
     * Upstream's `buildBaseCodexHeaders()`: the model's headers, the caller's over them (a null
     * deletes), then the token, its account, `originator: pi` and the `User-Agent` — the order
     * the recordings were made with. (Upstream's source has since put `originator` and the
     * `User-Agent` first, "so model and caller headers can override them".)
     *
     * @param array<string, string> $initHeaders
     * @param array<string, string|null>|null $additionalHeaders
     * @return list<array<string, string|null>|null>
     */
    private static function baseCodexHeaderSources(array $initHeaders, ?array $additionalHeaders, string $accountId, string $token): array
    {
        return [
            $initHeaders,
            $additionalHeaders,
            [
                'Authorization' => "Bearer {$token}",
                'chatgpt-account-id' => $accountId,
                'originator' => 'pi',
                'User-Agent' => PigUserAgent::get(),
            ],
        ];
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
