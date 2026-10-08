<?php

declare(strict_types=1);

namespace Pig\Ai\Providers;

use Pig\Ai\AssistantMessage;
use Pig\Ai\AssistantMessageDiagnostic;
use Pig\Ai\BedrockCompat;
use Pig\Ai\DoneEvent;
use Pig\Ai\ErrorEvent;
use Pig\Ai\Http\HttpClient;
use Pig\Ai\ImageContent;
use Pig\Ai\Model;
use Pig\Ai\StartEvent;
use Pig\Ai\StopReason;
use Pig\Ai\StreamOptions;
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
use Pig\Ai\TranscriptContext;
use Pig\Ai\Usage;
use Pig\Ai\UserMessage;
use Pig\Ai\Utils\AssistantMessageEventStream;
use Pig\Ai\Utils\Aws\BedrockRuntimeClient;
use Pig\Ai\Utils\Aws\Credentials;
use Pig\Ai\Utils\Aws\ServiceError;
use Pig\Ai\Utils\Aws\StreamError;
use Pig\Ai\Utils\ConstrainedSampling;
use Pig\Ai\Utils\Headers;
use Pig\Ai\Utils\PartialJson;
use Pig\Ai\Utils\Text;
use Pig\Ai\Utils\Transcript;
use Pig\Ai\Utils\Utf8;
use Pig\Async\Async;
use RuntimeException;
use Throwable;

/**
 * Amazon Bedrock's ConverseStream API, streamed.
 *
 * Ported from upstream's `api/bedrock-converse-stream.ts`, function for function. Upstream hands the
 * wire to `@aws-sdk/client-bedrock-runtime`; pig has no SDK, so `Utils\Aws\BedrockRuntimeClient` is
 * what that client does — endpoint and region resolution, the default credential chain, SigV4, the
 * standard retry strategy, the binary event stream — and this file is upstream's own business on top
 * of it: which config the client is built with, the request it is sent, how its events become a
 * message, and how a failure is worded.
 *
 * The shape is Anthropic's, nearly: blocks are numbered (`contentBlockIndex`), a text or thinking block
 * opens with its first delta rather than with a start event, a tool call's input streams as JSON, and
 * the stop reason and the usage arrive in two separate events at the end.
 */
final class Bedrock
{
    /** Upstream's `EMPTY_TEXT_PLACEHOLDER`. */
    private const string EMPTY_TEXT_PLACEHOLDER = '<empty>';

    /** Upstream's `THINKING_BINDING_CONTROLS_BETA`. */
    private const string THINKING_BINDING_CONTROLS_BETA = 'thinking-binding-controls-2026-08-01';

    /** "Matches the placeholder the Anthropic API path uses for redacted thinking." */
    private const string REDACTED_THINKING_PLACEHOLDER = '[Reasoning redacted]';

    /**
     * Upstream's `BEDROCK_ERROR_PREFIXES`: "The downstream retry logic in agent-session matches
     * patterns like `server.?error` and `service.?unavailable`, so we preserve the legacy prefix format
     * rather than using the raw SDK exception name."
     */
    private const array BEDROCK_ERROR_PREFIXES = [
        'InternalServerException' => 'Internal server error',
        'ModelStreamErrorException' => 'Model stream error',
        'ValidationException' => 'Validation error',
        'ThrottlingException' => 'Throttling error',
        'ServiceUnavailableException' => 'Service unavailable',
    ];

    /** Upstream's `BEDROCK_DATA_RETENTION_DOCS_URL`. */
    private const string BEDROCK_DATA_RETENTION_DOCS_URL = 'https://docs.aws.amazon.com/bedrock/latest/userguide/data-retention.html';

    /** "Over-long header values are dropped rather than truncated: a truncated request id is not a request id." */
    private const int MAX_BEDROCK_DIAGNOSTIC_VALUE_CHARS = 200;

    /** Upstream's default budgets for budget-based Claude thinking, xhigh and max clamped to high's. */
    private const array DEFAULT_BUDGETS = [
        'minimal' => 1024,
        'low' => 2048,
        'medium' => 8192,
        'high' => 16384,
        'xhigh' => 16384,
        'max' => 16384,
    ];

    /** "OpenAI GPT models (GPT-5.x, GPT-6) take a nested `reasoning.effort` and reject `minimal`." */
    private const array OPENAI_GPT_EFFORT = [
        'minimal' => 'low',
        'low' => 'low',
        'medium' => 'medium',
        'high' => 'high',
        'xhigh' => 'xhigh',
        'max' => 'max',
    ];

    /** "gpt-oss takes a flat `reasoning_effort` and only accepts low, medium and high." */
    private const array OPENAI_GPT_OSS_EFFORT = [
        'minimal' => 'low',
        'low' => 'low',
        'medium' => 'medium',
        'high' => 'high',
        'xhigh' => 'high',
        'max' => 'high',
    ];

    /**
     * @param (\Closure(): int)|null $now milliseconds, for a test that pins the signing clock
     * @param (\Closure(int): void)|null $sleep the retry back-off's wait, in milliseconds, for a test
     */
    public function __construct(
        private readonly HttpClient $http = new HttpClient(),
        private readonly ?\Closure $now = null,
        private readonly ?\Closure $sleep = null,
    ) {
    }

    /** Returns at once; the response fills in as it arrives. */
    public function stream(Model $model, TranscriptContext $context, ?BedrockOptions $options = null): AssistantMessageEventStream
    {
        $stream = new AssistantMessageEventStream();
        $options ??= new BedrockOptions();

        Async::spawn(function () use ($stream, $model, $context, $options): void {
            $this->run($stream, $model, $context, $options);
        });

        return $stream;
    }

    private function run(AssistantMessageEventStream $stream, Model $model, TranscriptContext $context, BedrockOptions $options): void
    {
        // "Bedrock has no mid-conversation system messages; fold them into the leading prompt."
        $normalizedContext = Transcript::collapseSystemMessages($context);
        $builder = new AssistantMessageBuilder($model);
        $builder->setStopReason(StopReason::Pending);
        $signal = $options->signal;
        $env = $options->env;

        // "A profile explicitly configured through pi's auth flow (the `profile` option or scoped
        // `AWS_PROFILE` on the stored credential's env) must win over ambient
        // AWS_ACCESS_KEY_ID/AWS_SECRET_ACCESS_KEY." See #6957.
        $optionsProfile = self::truthy($options->profile) ?? self::truthy($env['AWS_PROFILE'] ?? null);
        $config = ['profile' => $optionsProfile ?? StreamOptions::providerEnvValue('AWS_PROFILE', $env)];
        $configuredRegion = self::getConfiguredBedrockRegion($options);
        $hasAmbientConfiguredProfile = StreamOptions::providerEnvValue('AWS_PROFILE', null) !== null;
        $endpointRegion = self::getStandardBedrockEndpointRegion($model->baseUrl);
        $useExplicitEndpoint = self::shouldUseExplicitBedrockEndpoint($model->baseUrl, $configuredRegion, $hasAmbientConfiguredProfile);

        // "Only pin standard AWS Bedrock runtime endpoints when no region or ambient AWS_PROFILE is
        // configured. This preserves custom endpoints (VPC/proxy) from #3402 without forcing built-in
        // catalog defaults such as us-east-1 to override AWS_REGION/AWS_PROFILE."
        if ($useExplicitEndpoint) {
            $config['endpoint'] = $model->baseUrl;
        }

        $skipAuth = StreamOptions::providerEnvValue('AWS_BEDROCK_SKIP_AUTH', $env) === '1';
        $bearerToken = self::truthy($options->bearerToken)
            ?? self::truthy($options->apiKey)
            ?? StreamOptions::providerEnvValue('AWS_BEARER_TOKEN_BEDROCK', $env);
        $useBearerToken = $bearerToken !== null && !$skipAuth;

        // "Region resolution: ARN-embedded > explicit option > env vars > SDK default chain. When the
        // model ID is an inference profile ARN, extract the region from it. This avoids conflicts with
        // AWS_REGION set for other services." Upstream's Node/Bun arm; PHP is always that runtime.
        if (preg_match('/^arn:aws(?:-[a-z0-9-]+)?:bedrock:([a-z0-9-]+):/', $model->id, $arn) === 1) {
            $config['region'] = $arn[1];
        } elseif ($configuredRegion !== null) {
            $config['region'] = $configuredRegion;
        } elseif ($endpointRegion !== null && $useExplicitEndpoint) {
            $config['region'] = $endpointRegion;
        } elseif (!$hasAmbientConfiguredProfile) {
            $config['region'] = 'us-east-1';
        }

        // "Support proxies that don't need authentication."
        if ($skipAuth) {
            $config['credentials'] = new Credentials('dummy-access-key', 'dummy-secret-key');
        }

        $credentials = self::getConfiguredBedrockCredentials($env);

        if (!$skipAuth && $credentials !== null && $optionsProfile === null) {
            $config['credentials'] = $credentials;
        }

        // Upstream's proxy arm (`resolveHttpProxyUrlForTarget()` and an HTTP/1.1 handler) is pig's
        // process-wide `HttpClient::useProxy()`, and `AWS_BEDROCK_FORCE_HTTP1` is what pig always
        // speaks; neither needs a branch here.

        if ($useBearerToken) {
            $config['token'] = $bearerToken;
        }

        // "Kept outside the try so the catch can still correlate a mid-stream failure: exceptions
        // delivered as stream events carry no HTTP metadata of their own."
        $responseRequestId = null;
        /** @var array<int, int> $open contentBlockIndex => position, for the blocks not yet stopped */
        $open = [];
        /** @var array<int, string> $redactedChunks position => encrypted reasoning bytes so far */
        $redactedChunks = [];

        try {
            $supportsStrictMode = $model->compat instanceof BedrockCompat ? ($model->compat->supportsStrictMode ?? false) : false;

            if ($options->onResponse !== null) {
                $onResponse = $options->onResponse;
                $config['onResponse'] = static function (array $response) use ($onResponse, $model): void {
                    $onResponse($response, $model);
                };
            }

            $customHeaders = Headers::providerHeadersToRecord($options->headers);

            if ($customHeaders !== null) {
                $config['headers'] = self::withoutReservedHeaders($customHeaders);
            }

            $cacheRetention = $options->resolvedCacheRetention();
            $inferenceMaxTokens = $options->maxTokens ?? (self::isAnthropicClaudeModel($model) ? $model->maxTokens : null);
            $initialSystemMessage = Transcript::getInitialSystemMessage($normalizedContext->messages);
            $initialSystemPrompt = $initialSystemMessage !== null ? Text::getSystemMessageText($initialSystemMessage) : null;
            $inferenceConfig = [];

            if ($inferenceMaxTokens !== null) {
                $inferenceConfig['maxTokens'] = $inferenceMaxTokens;
            }

            if ($options->temperature !== null) {
                $inferenceConfig['temperature'] = $options->temperature;
            }

            $commandInput = [
                'modelId' => $model->id,
                'messages' => self::convertMessages($normalizedContext, $model, $cacheRetention, $env),
                'system' => self::buildSystemPrompt($initialSystemPrompt, $model, $cacheRetention, $env),
                'inferenceConfig' => $inferenceConfig,
                'toolConfig' => self::convertToolConfig(Transcript::getCurrentTools($normalizedContext->messages), $options->toolChoice, $supportsStrictMode),
                'additionalModelRequestFields' => self::buildAdditionalModelRequestFields($model, $options),
            ];

            if ($options->requestMetadata !== null) {
                $commandInput['requestMetadata'] = $options->requestMetadata;
            }

            $nextCommandInput = $options->onPayload !== null ? ($options->onPayload)($commandInput, $model) : null;

            if ($nextCommandInput !== null) {
                $commandInput = (array) $nextCommandInput;
            }

            $client = new BedrockRuntimeClient($config, $this->http, $this->now, $this->sleep);
            $response = $client->converseStream($commandInput, $signal);
            $responseRequestId = self::normalizeDiagnosticValue($response->requestId);

            foreach ($response->items() as $item) {
                if ($options->onProviderStreamEvent !== null) {
                    ($options->onProviderStreamEvent)($item, $model);
                }

                if (isset($item['messageStart'])) {
                    if (($item['messageStart']['role'] ?? null) !== 'assistant') {
                        throw new RuntimeException('Unexpected assistant message start but got user message start instead');
                    }

                    $stream->push(new StartEvent($builder->snapshot()));
                } elseif (isset($item['contentBlockStart'])) {
                    self::handleContentBlockStart($item['contentBlockStart'], $builder, $stream, $open);
                } elseif (isset($item['contentBlockDelta'])) {
                    self::handleContentBlockDelta($item['contentBlockDelta'], $builder, $stream, $open, $redactedChunks);
                } elseif (isset($item['contentBlockStop'])) {
                    self::handleContentBlockStop($item['contentBlockStop'], $builder, $stream, $open, $redactedChunks);
                } elseif (isset($item['messageStop'])) {
                    $raw = $item['messageStop']['stopReason'] ?? null;
                    $builder->setRawStopReason(is_string($raw) ? $raw : null);
                    [$stopReason, $errorMessage] = self::mapStopReason(is_string($raw) ? $raw : null);
                    $builder->setStopReason($stopReason);

                    if ($errorMessage !== null) {
                        $builder->setErrorMessage($errorMessage);
                    }
                } elseif (isset($item['metadata'])) {
                    self::handleMetadata($item['metadata'], $builder);
                }
            }

            if ($signal?->aborted() ?? false) {
                throw new RuntimeException('Request was aborted');
            }

            if ($builder->stopReason() === StopReason::Pending) {
                throw new RuntimeException('Bedrock stream ended without a stop reason');
            }

            if ($builder->stopReason() === StopReason::Error || $builder->stopReason() === StopReason::Aborted) {
                throw new RuntimeException($builder->errorMessage() ?? 'An unknown error occurred');
            }

            // "A stream can settle without stopping every block, so finalize here too."
            self::finalizeStreamingBlocks($builder, $redactedChunks);
            $message = $builder->snapshot();
            $stream->push(new DoneEvent($message->stopReason, $message));
            $stream->end();
        } catch (Throwable $error) {
            self::finalizeStreamingBlocks($builder, $redactedChunks);
            $aborted = $signal?->aborted() ?? false;
            $builder->fail(self::formatBedrockError($error), $aborted);

            if (!$aborted) {
                $diagnostic = self::bedrockFailureDiagnostic($error, $responseRequestId);

                if ($diagnostic !== null) {
                    $builder->addDiagnostic($diagnostic);
                }
            }

            $failed = $builder->snapshot();
            $stream->push(new ErrorEvent($failed->stopReason, $failed));
            $stream->end();
        }
    }

    /**
     * Upstream's `formatBedrockError()`: "We map the `.name` to a stable human-readable prefix so
     * downstream consumers (retry logic, context-overflow detection) can distinguish error categories
     * via simple string matching." A Bedrock service exception gets the prefix (its name when there is
     * none for it); anything else is its message. The data-retention hint follows either.
     *
     * The SDK's errors carry their message and an unread response stream, so `normalizeProviderError()`
     * finds no body to surface and the message is the core every time.
     */
    public static function formatBedrockError(Throwable $error): string
    {
        $core = $error->getMessage();
        $dataRetentionHint = preg_match('/data retention mode/i', $core) === 1
            ? ' See ' . self::BEDROCK_DATA_RETENTION_DOCS_URL . ' for supported data retention modes.'
            : '';

        if ($error instanceof ServiceError && $error->bedrock) {
            $prefix = self::BEDROCK_ERROR_PREFIXES[$error->name] ?? $error->name;

            return "{$prefix}: {$core}{$dataRetentionHint}";
        }

        return "{$core}{$dataRetentionHint}";
    }

    /** Upstream's `normalizeDiagnosticValue()`. */
    private static function normalizeDiagnosticValue(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        if ($trimmed === '' || mb_strlen($trimmed) > self::MAX_BEDROCK_DIAGNOSTIC_VALUE_CHARS) {
            return null;
        }

        return $trimmed;
    }

    /**
     * Upstream's `extractBedrockErrorCode()`: "The SDK puts the modeled code on `error.name` for service
     * exceptions and unmodeled stream errors alike … Modeled Bedrock errors all end in `Exception`,
     * unlike transport names such as `TimeoutError`."
     */
    private static function extractBedrockErrorCode(Throwable $error): ?string
    {
        $name = match (true) {
            $error instanceof ServiceError, $error instanceof StreamError => $error->name,
            default => 'Error',
        };

        return str_ends_with($name, 'Exception') ? self::normalizeDiagnosticValue($name) : null;
    }

    /**
     * Upstream's `appendBedrockFailureDiagnostic()`: "Structured metadata alongside `errorMessage`,
     * which stays byte-identical because `isRetryableAssistantError` matches against it. Unknown fields
     * are omitted, never guessed."
     */
    private static function bedrockFailureDiagnostic(Throwable $error, ?string $fallbackRequestId): ?AssistantMessageDiagnostic
    {
        $details = [];

        if ($error instanceof ServiceError && $error->status !== null) {
            $details['status'] = $error->status;
        }

        $errorCode = self::extractBedrockErrorCode($error);

        if ($errorCode !== null) {
            $details['errorCode'] = $errorCode;
        }

        $requestId = ($error instanceof ServiceError ? self::normalizeDiagnosticValue($error->requestId) : null) ?? $fallbackRequestId;

        if ($requestId !== null) {
            $details['requestId'] = $requestId;
        }

        if ($details === []) {
            return null;
        }

        return new AssistantMessageDiagnostic('bedrock_response_failure', Timestamp::nowMs(), details: $details);
    }

    /**
     * Upstream's `RESERVED_HEADER_EXACT` and `isReservedHeader()`: "`host` and `x-amz-*` participate in
     * the SigV4 canonical request; `authorization` is owned by SigV4 or the bearer-token path."
     * Compared without case; such a caller header is skipped, every other one is laid over the request's.
     *
     * @param array<string, string> $headers
     * @return array<string, string>
     */
    private static function withoutReservedHeaders(array $headers): array
    {
        $kept = [];

        foreach ($headers as $name => $value) {
            $lower = strtolower((string) $name);

            if (str_starts_with($lower, 'x-amz-') || $lower === 'authorization' || $lower === 'host') {
                continue;
            }

            $kept[(string) $name] = $value;
        }

        return $kept;
    }

    /**
     * Upstream's `handleContentBlockStart()`: only a tool call announces itself.
     *
     * @param array<string, mixed> $event
     * @param array<int, int> $open
     */
    private static function handleContentBlockStart(array $event, AssistantMessageBuilder $builder, AssistantMessageEventStream $stream, array &$open): void
    {
        $index = (int) ($event['contentBlockIndex'] ?? 0);
        $toolUse = $event['start']['toolUse'] ?? null;

        if (is_array($toolUse)) {
            $open[$index] = $builder->startToolCall(
                $builder->nextWire(),
                is_string($toolUse['toolUseId'] ?? null) ? $toolUse['toolUseId'] : '',
                is_string($toolUse['name'] ?? null) ? $toolUse['name'] : '',
            );
            $stream->push(new ToolCallStartEvent($open[$index], $builder->snapshot()));
        }
    }

    /**
     * Upstream's `handleContentBlockDelta()`.
     *
     * @param array<string, mixed> $event
     * @param array<int, int> $open
     * @param array<int, string> $redactedChunks
     */
    private static function handleContentBlockDelta(
        array $event,
        AssistantMessageBuilder $builder,
        AssistantMessageEventStream $stream,
        array &$open,
        array &$redactedChunks,
    ): void {
        $contentBlockIndex = (int) ($event['contentBlockIndex'] ?? 0);
        $delta = is_array($event['delta'] ?? null) ? $event['delta'] : [];
        $index = $open[$contentBlockIndex] ?? null;
        $type = $index !== null ? $builder->typeOf($index) : null;

        if (array_key_exists('text', $delta) && $delta['text'] !== null) {
            // "If no text block exists yet, create one, as `handleContentBlockStart` is not sent for
            // text blocks."
            if ($index === null) {
                $index = $open[$contentBlockIndex] = $builder->startText($builder->nextWire());
                $type = 'text';
                $stream->push(new TextStartEvent($index, $builder->snapshot()));
            }

            if ($type === 'text') {
                $text = (string) $delta['text'];
                $builder->append($index, 'text', $text);
                $stream->push(new TextDeltaEvent($index, $text, $builder->snapshot()));
            }
        } elseif (is_array($delta['toolUse'] ?? null) && $type === 'toolCall') {
            $input = is_string($delta['toolUse']['input'] ?? null) ? $delta['toolUse']['input'] : '';
            $builder->append($index, 'json', $input);
            $stream->push(new ToolCallDeltaEvent($index, $input, $builder->snapshot()));
        } elseif (is_array($delta['reasoningContent'] ?? null)) {
            $reasoning = $delta['reasoningContent'];

            if ($index === null) {
                $index = $open[$contentBlockIndex] = $builder->startThinking($builder->nextWire());
                $type = 'thinking';
                $stream->push(new ThinkingStartEvent($index, $builder->snapshot()));
            }

            if ($type !== 'thinking') {
                return;
            }

            $text = $reasoning['text'] ?? null;

            if (is_string($text) && $text !== '') {
                $builder->append($index, 'text', $text);
                $stream->push(new ThinkingDeltaEvent($index, $text, $builder->snapshot()));
            }

            // "`thinkingSignature` holds either an Anthropic signature or an opaque redacted payload,
            // never both: mixing them would corrupt whichever arrived first."
            $signature = $reasoning['signature'] ?? null;

            if (is_string($signature) && $signature !== '' && !$builder->isRedacted($index)) {
                $builder->append($index, 'signature', $signature);
            }

            $redacted = $reasoning['redactedContent'] ?? null;

            if (is_string($redacted) && $redacted !== '') {
                // "Encrypted reasoning from non-Anthropic models on Bedrock (e.g. OpenAI GPT-5.6). The
                // payload is opaque, so keep it verbatim in `thinkingSignature` the way the Anthropic
                // path stores redacted thinking, and replay it on the next turn."
                if (!$builder->isRedacted($index)) {
                    $builder->markRedacted($index);
                    $builder->setSignature($index, '');
                    $builder->append($index, 'text', self::REDACTED_THINKING_PLACEHOLDER);
                    $stream->push(new ThinkingDeltaEvent($index, self::REDACTED_THINKING_PLACEHOLDER, $builder->snapshot()));
                }

                $redactedChunks[$index] = ($redactedChunks[$index] ?? '') . $redacted;
            }
        }
    }

    /**
     * Upstream's `flushRedactedContent()` for every block, and `finalizeStreamingBlock()`'s point:
     * the scratch buffer is encoded into `thinkingSignature` and dropped.
     *
     * @param array<int, string> $redactedChunks
     */
    private static function finalizeStreamingBlocks(AssistantMessageBuilder $builder, array &$redactedChunks): void
    {
        foreach ($redactedChunks as $index => $bytes) {
            $builder->setSignature($index, base64_encode($bytes));
        }

        $redactedChunks = [];
    }

    /**
     * Upstream's `handleMetadata()`.
     *
     * @param array<string, mixed> $event
     */
    private static function handleMetadata(array $event, AssistantMessageBuilder $builder): void
    {
        $usage = $event['usage'] ?? null;

        if (!is_array($usage)) {
            return;
        }

        $input = (int) ($usage['inputTokens'] ?? 0);
        $output = (int) ($usage['outputTokens'] ?? 0);
        $cacheWrite1h = null;

        if (is_array($usage['cacheDetails'] ?? null)) {
            $cacheWrite1h = 0;

            foreach ($usage['cacheDetails'] as $detail) {
                if (is_array($detail) && ($detail['ttl'] ?? null) === '1h') {
                    $cacheWrite1h += (int) ($detail['inputTokens'] ?? 0);
                }
            }
        }

        $total = (int) ($usage['totalTokens'] ?? 0);

        $builder->setUsage(new Usage(
            $input,
            $output,
            (int) ($usage['cacheReadInputTokens'] ?? 0),
            (int) ($usage['cacheWriteInputTokens'] ?? 0),
            $total !== 0 ? $total : $input + $output,
            cacheWrite1h: $cacheWrite1h,
        ));
    }

    /**
     * Upstream's `handleContentBlockStop()`.
     *
     * @param array<string, mixed> $event
     * @param array<int, int> $open
     * @param array<int, string> $redactedChunks
     */
    private static function handleContentBlockStop(
        array $event,
        AssistantMessageBuilder $builder,
        AssistantMessageEventStream $stream,
        array &$open,
        array &$redactedChunks,
    ): void {
        $contentBlockIndex = (int) ($event['contentBlockIndex'] ?? 0);
        $index = $open[$contentBlockIndex] ?? null;

        if ($index === null) {
            return;
        }

        unset($open[$contentBlockIndex]);

        $type = $builder->typeOf($index);

        if ($type === 'text') {
            $stream->push(new TextEndEvent($index, $builder->textOf($index), $builder->snapshot()));
        } elseif ($type === 'thinking') {
            if (isset($redactedChunks[$index])) {
                $builder->setSignature($index, base64_encode($redactedChunks[$index]));
                unset($redactedChunks[$index]);
            }

            $stream->push(new ThinkingEndEvent($index, $builder->textOf($index), $builder->snapshot()));
        } elseif ($type === 'toolCall') {
            // "Finalize in-place and strip the scratch buffer so replay only carries parsed arguments."
            $builder->setJson($index, $builder->jsonOf($index));
            $stream->push(new ToolCallEndEvent($index, $builder->toolCallOf($index), $builder->snapshot()));
        }
    }

    /**
     * Upstream's `getModelMatchCandidates()`: "Checks both model ID and model name to support
     * application inference profiles whose ARNs don't contain the model name."
     *
     * @return list<string>
     */
    private static function getModelMatchCandidates(string $modelId, ?string $modelName): array
    {
        $candidates = [];

        foreach ($modelName !== null && $modelName !== '' ? [$modelId, $modelName] : [$modelId] as $value) {
            $lower = strtolower($value);
            $candidates[] = $lower;
            $candidates[] = (string) preg_replace('/[\s_.:]+/', '-', $lower);
        }

        return $candidates;
    }

    /** @param list<string> $needles */
    private static function anyCandidate(Model $model, array $needles): bool
    {
        foreach (self::getModelMatchCandidates($model->id, $model->name) as $candidate) {
            foreach ($needles as $needle) {
                if (str_contains($candidate, $needle)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Upstream's `supportsAdaptiveThinking()`: Opus 4.6+, Sonnet 4.6, the 5 family. */
    public static function supportsAdaptiveThinking(Model $model): bool
    {
        return self::anyCandidate($model, ['opus-4-6', 'opus-4-7', 'opus-4-8', 'opus-5', 'sonnet-4-6', 'sonnet-5', 'haiku-5', 'fable-5']);
    }

    /** Upstream's `supportsNativeXhighEffort()`. */
    private static function supportsNativeXhighEffort(Model $model): bool
    {
        return self::anyCandidate($model, ['opus-4-7', 'opus-4-8', 'opus-5', 'sonnet-5', 'haiku-5', 'fable-5']);
    }

    /**
     * Upstream's `supportsThinkingBlockBinding()`: "Opus 4.6 and Sonnet 4.6 reject it with
     * 'thinking.adaptive.block_binding: Extra inputs are not permitted'."
     */
    private static function supportsThinkingBlockBinding(Model $model): bool
    {
        return self::anyCandidate($model, ['opus-4-7', 'opus-4-8', 'opus-5', 'sonnet-5', 'haiku-5', 'fable-5']);
    }

    /** Upstream's `mapThinkingLevelToEffort()`. */
    private static function mapThinkingLevelToEffort(Model $model, ?string $level): string
    {
        if ($level === 'xhigh' && self::supportsNativeXhighEffort($model)) {
            return 'xhigh';
        }

        $mapped = $level !== null ? ($model->thinkingLevelMap[$level] ?? null) : null;

        if (is_string($mapped)) {
            return $mapped;
        }

        return match ($level) {
            'minimal', 'low' => 'low',
            'medium' => 'medium',
            default => 'high',
        };
    }

    /**
     * Upstream's `isAnthropicClaudeModel()`: "Checks both model ID and model name to support
     * application inference profiles whose ARNs don't contain the model name."
     */
    public static function isAnthropicClaudeModel(Model $model): bool
    {
        $id = strtolower($model->id);
        $name = strtolower($model->name);

        return str_contains($id, 'anthropic.claude')
            || str_contains($id, 'anthropic/claude')
            || str_contains($name, 'anthropic.claude')
            || str_contains($name, 'anthropic/claude')
            || str_contains($name, 'claude');
    }

    /**
     * Upstream's `supportsPromptCaching()`: "Supported: Claude 3.5 Haiku, Claude 3.7 Sonnet, Claude 4.x
     * models, Claude 5 models … As a last resort, set AWS_BEDROCK_FORCE_CACHE=1 to enable cache points.
     * Amazon Nova models have automatic caching and don't need explicit cache points."
     *
     * @param array<string, string>|null $env
     */
    private static function supportsPromptCaching(Model $model, ?array $env): bool
    {
        if (!self::anyCandidate($model, ['claude'])) {
            // "Application inference profiles don't contain the model name in the ARN. Allow users to
            // force cache points via environment variable."
            return StreamOptions::providerEnvValue('AWS_BEDROCK_FORCE_CACHE', $env) === '1';
        }

        return self::anyCandidate($model, ['fable-5', 'opus-5', 'sonnet-5', 'haiku-5'])
            || self::anyCandidate($model, ['-4-'])
            || self::anyCandidate($model, ['claude-3-7-sonnet'])
            || self::anyCandidate($model, ['claude-3-5-haiku']);
    }

    /**
     * Upstream's `supportsThinkingSignature()`: "Only Anthropic Claude models support the signature
     * field. Other models (OpenAI, Qwen, Minimax, Moonshot, etc.) reject it."
     */
    private static function supportsThinkingSignature(Model $model): bool
    {
        return self::isAnthropicClaudeModel($model);
    }

    /**
     * Upstream's `buildSystemPrompt()`.
     *
     * @param array<string, string>|null $env
     * @return list<array<string, mixed>>|null
     */
    private static function buildSystemPrompt(?string $systemPrompt, Model $model, string $cacheRetention, ?array $env): ?array
    {
        if ($systemPrompt === null || $systemPrompt === '') {
            return null;
        }

        $blocks = [['text' => Utf8::sanitize($systemPrompt)]];

        // "Add cache point for supported Claude models when caching is enabled"
        if ($cacheRetention !== 'none' && self::supportsPromptCaching($model, $env)) {
            $blocks[] = ['cachePoint' => self::cachePoint($cacheRetention)];
        }

        return $blocks;
    }

    /** @return array{type: string, ttl?: string} */
    private static function cachePoint(string $cacheRetention): array
    {
        return ['type' => 'default', ...($cacheRetention === 'long' ? ['ttl' => '1h'] : [])];
    }

    /** Upstream's `normalizeToolCallId()`: Bedrock's `[a-zA-Z0-9_-]`, at most 64. */
    private static function normalizeToolCallId(string $id): string
    {
        $sanitized = (string) preg_replace('/[^a-zA-Z0-9_-]/', '_', $id);

        return strlen($sanitized) > 64 ? substr($sanitized, 0, 64) : $sanitized;
    }

    /** @return array{text: string}|null upstream's `createNonBlankTextBlock()` */
    private static function createNonBlankTextBlock(string $text): ?array
    {
        $sanitized = Utf8::sanitize($text);

        return trim($sanitized) === '' ? null : ['text' => $sanitized];
    }

    /**
     * Upstream's `sanitizeBedrockDocument()`: object keys that are empty strings dropped, all the way
     * down — Bedrock refuses them in a replayed `toolUse.input`.
     */
    private static function sanitizeBedrockDocument(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(self::sanitizeBedrockDocument(...), $value);
        }

        $out = [];

        foreach ($value as $key => $nested) {
            if ((string) $key !== '') {
                $out[$key] = self::sanitizeBedrockDocument($nested);
            }
        }

        return $out;
    }

    /**
     * Upstream's `convertToolResultContent()`.
     *
     * @param list<TextContent|ImageContent> $content
     * @return list<array<string, mixed>>
     */
    private static function convertToolResultContent(array $content): array
    {
        $result = [];

        foreach ($content as $c) {
            if ($c instanceof ImageContent) {
                $result[] = ['image' => self::createImageBlock($c->mimeType, $c->data)];
            } elseif ($c instanceof TextContent) {
                $block = self::createNonBlankTextBlock($c->text);

                if ($block !== null) {
                    $result[] = $block;
                }
            }
        }

        if ($result === []) {
            $result[] = ['text' => self::EMPTY_TEXT_PLACEHOLDER];
        }

        return $result;
    }

    /**
     * Upstream's `convertMessages()`.
     *
     * @param array<string, string>|null $env
     * @return list<array{role: string, content: list<array<string, mixed>>}>
     */
    private static function convertMessages(TranscriptContext $context, Model $model, string $cacheRetention, ?array $env): array
    {
        $result = [];
        $transformedMessages = array_values(TransformMessages::apply(
            Transcript::withoutInitialSystemMessage($context->messages),
            $model,
            static fn (string $id): string => self::normalizeToolCallId($id),
        ));
        $count = count($transformedMessages);

        for ($i = 0; $i < $count; $i++) {
            $m = $transformedMessages[$i];

            if ($m instanceof UserMessage) {
                // A string user message is one text block here, so the string arm (a blank one is the
                // placeholder) and the list arm (blanks dropped, the placeholder when nothing is left)
                // give the same answer.
                $content = [];

                foreach ($m->content as $c) {
                    if ($c instanceof TextContent) {
                        $block = self::createNonBlankTextBlock($c->text);

                        if ($block !== null) {
                            $content[] = $block;
                        }
                    } elseif ($c instanceof ImageContent) {
                        $content[] = ['image' => self::createImageBlock($c->mimeType, $c->data)];
                    }
                }

                if ($content === []) {
                    $content[] = ['text' => self::EMPTY_TEXT_PLACEHOLDER];
                }

                $result[] = ['role' => 'user', 'content' => $content];
            } elseif ($m instanceof AssistantMessage) {
                // "Skip assistant messages with empty content (e.g., from aborted requests). Bedrock
                // rejects messages with empty content arrays."
                if ($m->content === []) {
                    continue;
                }

                $contentBlocks = [];

                foreach ($m->content as $c) {
                    if ($c instanceof TextContent) {
                        $block = self::createNonBlankTextBlock($c->text);

                        if ($block !== null) {
                            $contentBlocks[] = $block;
                        }
                    } elseif ($c instanceof ToolCall) {
                        $contentBlocks[] = ['toolUse' => [
                            'toolUseId' => $c->id,
                            'name' => $c->name,
                            'input' => self::sanitizeBedrockDocument($c->arguments),
                        ]];
                    } elseif ($c instanceof ThinkingContent) {
                        // "Encrypted reasoning is opaque: replay the stored payload as the
                        // `redactedContent` member instead of lowering it to reasoning text."
                        if ($c->redacted === true) {
                            $redactedContent = self::decodeRedactedContent($c->thinkingSignature);

                            if ($redactedContent !== null && $redactedContent !== '') {
                                $contentBlocks[] = ['reasoningContent' => ['redactedContent' => $redactedContent]];
                            }

                            continue;
                        }

                        $thinking = Utf8::sanitize($c->thinking);

                        if (trim($thinking) === '') {
                            continue;
                        }

                        if (self::supportsThinkingSignature($model)) {
                            // "Signatures arrive after thinking deltas. If a partial or externally
                            // persisted message lacks a signature, Bedrock rejects the replayed
                            // reasoning block. Fall back to plain text, matching Anthropic."
                            if ($c->thinkingSignature === null || trim($c->thinkingSignature) === '') {
                                $contentBlocks[] = ['text' => $thinking];
                            } else {
                                $contentBlocks[] = ['reasoningContent' => ['reasoningText' => ['text' => $thinking, 'signature' => $c->thinkingSignature]]];
                            }
                        } else {
                            $contentBlocks[] = ['reasoningContent' => ['reasoningText' => ['text' => $thinking]]];
                        }
                    }
                }

                // "Skip if all content blocks were filtered out"
                if ($contentBlocks === []) {
                    continue;
                }

                $result[] = ['role' => 'assistant', 'content' => $contentBlocks];
            } elseif ($m instanceof ToolResultMessage) {
                // "Collect all consecutive toolResult messages into a single user message. Bedrock
                // requires all tool results to be in one message."
                $toolResults = [self::toolResult($m)];
                $j = $i + 1;

                while ($j < $count && $transformedMessages[$j] instanceof ToolResultMessage) {
                    $toolResults[] = self::toolResult($transformedMessages[$j]);
                    $j++;
                }

                $i = $j - 1;
                $result[] = ['role' => 'user', 'content' => $toolResults];
            }
        }

        // "Add cache point to the last user message for supported Claude models when caching is enabled"
        if ($cacheRetention !== 'none' && self::supportsPromptCaching($model, $env) && $result !== []) {
            $last = count($result) - 1;

            if ($result[$last]['role'] === 'user') {
                $result[$last]['content'][] = ['cachePoint' => self::cachePoint($cacheRetention)];
            }
        }

        return $result;
    }

    /** @return array{toolResult: array<string, mixed>} */
    private static function toolResult(ToolResultMessage $message): array
    {
        return ['toolResult' => [
            'toolUseId' => $message->toolCallId,
            'content' => self::convertToolResultContent($message->content),
            'status' => $message->isError ? 'error' : 'success',
        ]];
    }

    /**
     * Upstream's `convertToolConfig()`.
     *
     * @param list<Tool> $tools
     * @param string|array{type: 'tool', name: string}|null $toolChoice
     * @return array<string, mixed>|null
     */
    private static function convertToolConfig(array $tools, string|array|null $toolChoice, bool $supportsStrictMode): ?array
    {
        if ($tools === [] || $toolChoice === 'none') {
            return null;
        }

        $bedrockTools = array_map(static function (Tool $tool) use ($supportsStrictMode): array {
            $strict = ConstrainedSampling::resolveJsonSchemaStrictSampling($tool, $supportsStrictMode);

            return ['toolSpec' => [
                'name' => $tool->name,
                'description' => $tool->description,
                'inputSchema' => ['json' => ConstrainedSampling::getJsonSchemaToolParameters($tool, $strict)],
                ...($strict === true ? ['strict' => true] : []),
            ]];
        }, $tools);

        $bedrockToolChoice = match (true) {
            $toolChoice === 'auto' => ['auto' => []],
            $toolChoice === 'any' => ['any' => []],
            is_array($toolChoice) && ($toolChoice['type'] ?? null) === 'tool' => ['tool' => ['name' => $toolChoice['name'] ?? '']],
            default => null,
        };

        return ['tools' => $bedrockTools, 'toolChoice' => $bedrockToolChoice];
    }

    /**
     * Upstream's `mapStopReason()`.
     *
     * @return array{0: StopReason, 1: string|null}
     */
    private static function mapStopReason(?string $reason): array
    {
        return match ($reason) {
            'end_turn', 'stop_sequence' => [StopReason::Stop, null],
            'max_tokens', 'model_context_window_exceeded' => [StopReason::Length, null],
            'tool_use' => [StopReason::ToolUse, null],
            default => $reason !== null && $reason !== ''
                ? [StopReason::Error, "Provider stopped with: {$reason}"]
                : [StopReason::Error, null],
        };
    }

    /** Upstream's `getConfiguredBedrockRegion()`: the option, `AWS_REGION`, `AWS_DEFAULT_REGION`. */
    public static function getConfiguredBedrockRegion(BedrockOptions $options): ?string
    {
        return self::truthy($options->region)
            ?? StreamOptions::providerEnvValue('AWS_REGION', $options->env)
            ?? StreamOptions::providerEnvValue('AWS_DEFAULT_REGION', $options->env);
    }

    /**
     * Upstream's `getConfiguredBedrockCredentials()`: the access key pair (and session token) from the
     * environment — the scoped `env` first — or nothing.
     *
     * @param array<string, string>|null $env
     */
    private static function getConfiguredBedrockCredentials(?array $env): ?Credentials
    {
        $accessKeyId = StreamOptions::providerEnvValue('AWS_ACCESS_KEY_ID', $env);
        $secretAccessKey = StreamOptions::providerEnvValue('AWS_SECRET_ACCESS_KEY', $env);

        if ($accessKeyId === null || $secretAccessKey === null) {
            return null;
        }

        return new Credentials($accessKeyId, $secretAccessKey, StreamOptions::providerEnvValue('AWS_SESSION_TOKEN', $env));
    }

    /** Upstream's `getStandardBedrockEndpointRegion()`. */
    public static function getStandardBedrockEndpointRegion(?string $baseUrl): ?string
    {
        if ($baseUrl === null || $baseUrl === '') {
            return null;
        }

        $host = parse_url($baseUrl, PHP_URL_HOST);

        if (!is_string($host)) {
            return null;
        }

        return preg_match('/^bedrock-runtime(?:-fips)?\.([a-z0-9-]+)\.amazonaws\.com(?:\.cn)?$/', strtolower($host), $match) === 1
            ? $match[1]
            : null;
    }

    /** Upstream's `shouldUseExplicitBedrockEndpoint()`. */
    public static function shouldUseExplicitBedrockEndpoint(string $baseUrl, ?string $configuredRegion, bool $hasAmbientConfiguredProfile): bool
    {
        if (self::getStandardBedrockEndpointRegion($baseUrl) === null) {
            return true;
        }

        return $configuredRegion === null && !$hasAmbientConfiguredProfile;
    }

    /** Upstream's `isGovCloudBedrockTarget()`. */
    private static function isGovCloudBedrockTarget(Model $model, BedrockOptions $options): bool
    {
        $region = self::getConfiguredBedrockRegion($options);

        if ($region !== null && str_starts_with(strtolower($region), 'us-gov-')) {
            return true;
        }

        $modelId = strtolower($model->id);

        return str_starts_with($modelId, 'us-gov.') || str_starts_with($modelId, 'arn:aws-us-gov:');
    }

    /**
     * Upstream's `buildAdditionalModelRequestFields()`: Claude's thinking (adaptive with an effort, or
     * a token budget), OpenAI's `reasoning.effort`, gpt-oss's flat `reasoning_effort`, nothing else.
     *
     * @return array<string, mixed>|null
     */
    private static function buildAdditionalModelRequestFields(Model $model, BedrockOptions $options): ?array
    {
        $reasoning = $options->reasoning;

        if ($reasoning === null || $reasoning === '' || !$model->reasoning) {
            return null;
        }

        if (self::isAnthropicClaudeModel($model)) {
            // "GovCloud Bedrock currently rejects the Claude thinking.display field. Omit it there
            // until the GovCloud Converse schema catches up."
            $isGovCloud = self::isGovCloudBedrockTarget($model, $options);
            $display = $isGovCloud ? null : ($options->thinkingDisplay ?? 'summarized');
            // "Replayed signed thinking blocks are bound to the system prompt and tools they were
            // created with. Bedrock 400s on replay after either changes unless stale blocks are
            // dropped, matching the Anthropic provider. Skipped on GovCloud like display."
            $useBlockBinding = !$isGovCloud && self::supportsThinkingBlockBinding($model);

            if (self::supportsAdaptiveThinking($model)) {
                $result = [
                    'thinking' => [
                        'type' => 'adaptive',
                        ...($display !== null ? ['display' => $display] : []),
                        ...($useBlockBinding ? ['block_binding' => ['prefix_mismatch_behavior' => 'drop_block']] : []),
                    ],
                    'output_config' => ['effort' => self::mapThinkingLevelToEffort($model, $reasoning)],
                    ...($useBlockBinding ? ['anthropic_beta' => [self::THINKING_BINDING_CONTROLS_BETA]] : []),
                ];
            } else {
                // "Custom budgets only cover token-based levels through high."
                $level = $reasoning === 'xhigh' || $reasoning === 'max' ? 'high' : $reasoning;
                $budget = $options->thinkingBudgets[$level] ?? self::DEFAULT_BUDGETS[$reasoning] ?? null;
                $result = [
                    'thinking' => [
                        'type' => 'enabled',
                        'budget_tokens' => $budget,
                        ...($display !== null ? ['display' => $display] : []),
                    ],
                ];
            }

            if (!self::supportsAdaptiveThinking($model) && ($options->interleavedThinking ?? true)) {
                $result['anthropic_beta'] = ['interleaved-thinking-2025-05-14'];
            }

            return $result;
        }

        if (self::anyCandidate($model, ['gpt-oss'])) {
            return ['reasoning_effort' => self::OPENAI_GPT_OSS_EFFORT[$reasoning] ?? null];
        }

        if (self::anyCandidate($model, ['gpt-'])) {
            $mapped = $model->thinkingLevelMap[$reasoning] ?? null;

            return ['reasoning' => ['effort' => is_string($mapped) ? $mapped : (self::OPENAI_GPT_EFFORT[$reasoning] ?? null)]];
        }

        return null;
    }

    /**
     * Upstream's `createImageBlock()`: the four formats Converse takes, anything else refused by name.
     *
     * @return array{source: array{bytes: string}, format: string}
     */
    private static function createImageBlock(string $mimeType, string $data): array
    {
        $format = match ($mimeType) {
            'image/jpeg', 'image/jpg' => 'jpeg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            default => throw new RuntimeException("Unknown image type: {$mimeType}"),
        };

        return ['source' => ['bytes' => self::base64ToBytes($data)], 'format' => $format];
    }

    /** Upstream's `base64ToBytes()`: `atob()`, whose failure is the browser's `InvalidCharacterError`. */
    private static function base64ToBytes(string $data): string
    {
        return self::atob($data)
            ?? throw new RuntimeException("Failed to execute 'atob' on 'Window': The string to be decoded is not correctly encoded.");
    }

    /** `atob()`: ASCII white space ignored, anything that is not base64 null. */
    private static function atob(string $data): ?string
    {
        $bytes = base64_decode((string) preg_replace('/[\t\n\f\r ]/', '', $data), true);

        return $bytes === false ? null : $bytes;
    }

    /**
     * Upstream's `decodeRedactedContent()`: "A hand-edited or externally produced session can hold a
     * signature that is not base64; drop that block instead of failing the whole request."
     */
    private static function decodeRedactedContent(?string $signature): ?string
    {
        if ($signature === null || $signature === '') {
            return null;
        }

        // Upstream's `try { … } catch { return undefined; }`, quoted above: the block is dropped by design.
        return self::atob($signature);
    }

    /** JavaScript's `value || undefined` for an optional string. */
    private static function truthy(?string $value): ?string
    {
        return $value !== null && $value !== '' ? $value : null;
    }
}
