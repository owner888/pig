<?php

declare(strict_types=1);

namespace Pig\Ai\Utils\Aws;

use Closure;
use Generator;
use Pig\Ai\Http\HttpClient;
use Pig\Ai\Http\HttpError;
use Pig\Ai\Http\Request;
use Pig\Ai\Http\Response;
use Pig\Ai\Utils\JsJson;
use Pig\Ai\Utils\PigUserAgent;
use Pig\Async\AbortError;
use Pig\Async\AbortSignal;
use Pig\Async\Deferred;
use Pig\Async\Loop;
use Pig\Async\SocketError;
use RuntimeException;
use Throwable;

/**
 * What `new BedrockRuntimeClient(config).send(new ConverseStreamCommand(input))` does in
 * `@aws-sdk/client-bedrock-runtime` 3.1127.0 — the part of the AWS SDK upstream's
 * `bedrock-converse-stream.ts` leans on, written out because pig has no SDK:
 *
 * - **configuration** the way the client resolves what it was not given: the region from
 *   `AWS_REGION`, then the profile's `region` (the credentials file winning), then the instance
 *   metadata service, else "Region is missing"; a `fips-`/`-fips` region turning FIPS on;
 *   `AWS_USE_FIPS_ENDPOINT` / `use_fips_endpoint` and `AWS_USE_DUALSTACK_ENDPOINT` /
 *   `use_dualstack_endpoint`; `AWS_MAX_ATTEMPTS` / `max_attempts` (3); `AWS_RETRY_MODE` /
 *   `retry_mode` (`standard`);
 * - **the endpoint**, `Endpoints::bedrockRuntime()`, and the request: `POST
 *   /model/<id>/converse-stream` with the input serialized in the service's member order — blobs as
 *   base64, documents as they are, members the schema does not have dropped;
 * - **the headers the SDK adds**: `x-amz-user-agent` and `user-agent` (whose runtime part says PHP, as
 *   `SdkHeaders` does for the other SDKs), `amz-sdk-invocation-id`, `amz-sdk-request: attempt=N;
 *   max=M`, then the caller's own (upstream's build-step middleware), then SigV4 or `Authorization:
 *   Bearer`;
 * - **the standard retry strategy**: throttling (429 or a throttling code) and transient failures
 *   (500/502/503/504, `TimeoutError`/`RequestTimeout…`, a connection that never answered, a clock-skew
 *   correction) are tried again up to the attempt limit, after `random × min(base × 2^n, 20 s)` with a
 *   500 ms base for throttling and 100 ms otherwise, a `retry-after` / `x-amz-retry-after` stretching it
 *   by up to five seconds; the clock offset is taken from the server's `Date` the way the SigV4 signer's
 *   error handler takes it;
 * - **errors**, `RestJson::error()` for an answer that is not 2xx;
 * - **the event stream**: the first message is read before `send()` returns, as the SDK's deserializer
 *   does, so a refusal in it is retried like any other; after that every message is decoded on demand —
 *   an `error` message is an `Error` named by its `:error-code`, an `exception` one a Bedrock service
 *   exception (or, for a type the client does not model, an `Error` of the payload), an `event` one the
 *   deserialized union member, unknown kinds skipped.
 *
 * Not here: HTTP/2 (pig speaks HTTP/1.1, which is what upstream switches to behind a proxy or with
 * `AWS_BEDROCK_FORCE_HTTP1=1`), the adaptive retry mode (refused by name), and the retry quota (500
 * tokens a client, more than one stream call can spend).
 *
 * @internal
 */
final class BedrockRuntimeClient
{
    /** The SDK version the user agent names, as `client-bedrock-runtime` 3.1127.0 reports it. */
    public const string SDK_VERSION = '3.1126.0';

    private const int MAXIMUM_RETRY_DELAY = 20_000;

    private const int DEFAULT_RETRY_DELAY_BASE = 100;

    private const int THROTTLING_RETRY_DELAY_BASE = 500;

    /** smithy's `THROTTLING_ERROR_CODES`. */
    private const array THROTTLING_ERROR_CODES = [
        'BandwidthLimitExceeded', 'EC2ThrottledException', 'LimitExceededException', 'PriorRequestNotComplete',
        'ProvisionedThroughputExceededException', 'RequestLimitExceeded', 'RequestThrottled', 'RequestThrottledException',
        'SlowDown', 'ThrottledException', 'Throttling', 'ThrottlingException', 'TooManyRequestsException',
        'TransactionInProgressException',
    ];

    /** smithy's `TRANSIENT_ERROR_CODES` and `TRANSIENT_ERROR_STATUS_CODES`. */
    private const array TRANSIENT_ERROR_CODES = ['TimeoutError', 'RequestTimeout', 'RequestTimeoutException'];

    private const array TRANSIENT_ERROR_STATUS_CODES = [500, 502, 503, 504];

    /** The stream's modeled exception members, by the `:exception-type` they arrive as. */
    private const array STREAM_EXCEPTIONS = [
        'internalServerException' => 'InternalServerException',
        'modelStreamErrorException' => 'ModelStreamErrorException',
        'validationException' => 'ValidationException',
        'throttlingException' => 'ThrottlingException',
        'serviceUnavailableException' => 'ServiceUnavailableException',
    ];

    /** The `ConverseStreamOutput` event members and the members each keeps. */
    private const array STREAM_EVENTS = [
        'messageStart' => ['role'],
        'contentBlockStart' => ['start', 'contentBlockIndex'],
        'contentBlockDelta' => ['delta', 'contentBlockIndex'],
        'contentBlockStop' => ['contentBlockIndex'],
        'messageStop' => ['stopReason', 'additionalModelResponseFields'],
        'metadata' => ['usage', 'metrics', 'trace', 'performanceConfig', 'serviceTier'],
    ];

    private int $systemClockOffset = 0;

    /**
     * @param array{
     *     profile?: string|null,
     *     region?: string|null,
     *     endpoint?: string|null,
     *     credentials?: Credentials|null,
     *     token?: string|null,
     *     headers?: array<string, string>|null,
     *     onResponse?: (Closure(array{status: int, headers: array<string, string>}): void)|null,
     * } $config upstream's `BedrockRuntimeClientConfig`, with the custom headers and `onResponse`
     *        its two middlewares carry
     * @param (Closure(): int)|null $now milliseconds, for tests
     * @param (Closure(int): void)|null $sleep milliseconds, for tests
     */
    public function __construct(
        private readonly array $config,
        private readonly HttpClient $http = new HttpClient(),
        private readonly ?Closure $now = null,
        private readonly ?Closure $sleep = null,
    ) {
    }

    /**
     * `client.send(new ConverseStreamCommand(input), {abortSignal})`.
     *
     * @param array<string, mixed> $input
     */
    public function converseStream(array $input, ?AbortSignal $signal): BedrockEventStream
    {
        if ($signal?->aborted() ?? false) {
            throw new RequestAbortedError();
        }

        $profile = $this->config['profile'] ?? null;
        $region = $this->region($profile);
        $useFips = $this->useFips($profile, $region);
        $useDualStack = $this->booleanSetting('AWS_USE_DUALSTACK_ENDPOINT', 'use_dualstack_endpoint', $profile);
        $realRegion = self::realRegion($region);
        $endpoint = Endpoints::bedrockRuntime(
            $this->config['endpoint'] ?? Endpoints::configured('Bedrock Runtime'),
            $realRegion,
            $useFips,
            $useDualStack,
        );
        $maxAttempts = $this->maxAttempts($profile);
        $this->assertStandardRetryMode($profile);

        $token = $this->config['token'] ?? null;
        $credentials = null;

        if ($token === null) {
            $credentials = isset($this->config['credentials'])
                ? $this->config['credentials']->withFeature('CREDENTIALS_CODE', 'e')
                : (new CredentialChain($profile, $realRegion, $this->http, $signal, $this->now, $maxAttempts, $this->sleep))->resolve();
        }

        $body = (string) json_encode(self::serializeRequest($input), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        $parts = parse_url($endpoint);

        if ($parts === false || !isset($parts['host'])) {
            throw new RuntimeException("Invalid endpoint: {$endpoint}");
        }

        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $path = rtrim($parts['path'] ?? '', '/') . '/model/' . rawurlencode((string) ($input['modelId'] ?? '')) . '/converse-stream';
        $features = [
            ...($this->config['endpoint'] ?? null) !== null ? ['ENDPOINT_OVERRIDE' => 'N'] : [],
            'RETRY_MODE_STANDARD' => 'E',
            ...self::credentialFeatures($credentials),
        ];
        $invocationId = self::uuid();
        $retryCount = 0;

        for ($attempt = 1; ; $attempt++) {
            $headers = [
                'content-type' => 'application/json',
                'content-length' => (string) strlen($body),
                'x-amz-user-agent' => 'aws-sdk-js/' . self::SDK_VERSION,
                'user-agent' => self::userAgent($features, 'bedrock-runtime', self::SDK_VERSION),
                'host' => $host,
                'amz-sdk-invocation-id' => $invocationId,
                'amz-sdk-request' => "attempt={$attempt}; max={$maxAttempts}",
            ];

            foreach ($this->config['headers'] ?? [] as $name => $value) {
                $headers[strtolower((string) $name)] = $value;
            }

            $sentAt = $this->nowMs();

            if ($credentials !== null) {
                $headers = SignatureV4::sign('POST', $path, [], $headers, $body, $credentials, (string) $realRegion, 'bedrock', $sentAt + $this->systemClockOffset);
            } else {
                $headers['authorization'] = 'Bearer ' . $token;
            }

            try {
                return $this->attempt(new Request('POST', "{$scheme}://{$host}{$path}", $headers, $body), $signal, $credentials !== null, $sentAt);
            } catch (Throwable $error) {
                if ($signal?->aborted() ?? false) {
                    throw new RequestAbortedError('Request aborted', previous: $error);
                }

                $type = self::retryErrorType($error);

                if (($type !== 'THROTTLING' && $type !== 'TRANSIENT') || $attempt >= $maxAttempts) {
                    throw $error;
                }

                $delay = $this->retryDelay($type, $retryCount, $error);
                $retryCount++;
                $this->sleepMs($delay, $signal);
            }
        }
    }

    /**
     * One attempt: the request, then either the error it answered with or the stream with its first
     * message already read.
     */
    private function attempt(Request $request, ?AbortSignal $signal, bool $signed, int $sentAt): BedrockEventStream
    {
        try {
            $response = $this->http->send($request, $signal);
        } catch (AbortError $error) {
            throw $error;
        } catch (Throwable $error) {
            // A connection that never answered: Node's network error codes are all transient.
            throw new TransportError(self::transportMessage($error, $request), $error);
        }

        if (!$response->isSuccessful()) {
            $error = RestJson::error($response->status, $response->headers, $response->body->all(), true);

            throw $signed ? $this->correctClockSkew($error, $response, $sentAt) : $error;
        }

        if ($signed) {
            $this->updateClockOffset($response, $sentAt);
        }

        $stream = new BedrockEventStream($response->status, RestJson::requestId($response->headers), $this->messages($response, $signal));

        try {
            $stream->prime();
        } catch (ServiceError | StreamError | AbortError $error) {
            throw $error;
        } catch (Throwable $error) {
            throw new ServiceError($error->getMessage() . RestJson::DESERIALIZATION_HINT, 'Error', $response->status, $stream->requestId, false, $response->headers, $error);
        }

        if (isset($this->config['onResponse'])) {
            ($this->config['onResponse'])(['status' => $response->status, 'headers' => $response->headers]);
        }

        return $stream;
    }

    /**
     * The body as the SDK's event-stream deserializer yields it: each message decoded and unmarshalled.
     *
     * @return Generator<int, array<string, mixed>>
     */
    private function messages(Response $response, ?AbortSignal $signal): Generator
    {
        $framing = new EventStream();

        try {
            foreach ($response->body as $bytes) {
                foreach ($framing->feed($bytes) as $message) {
                    $item = self::unmarshal(EventStream::decode($message));

                    if ($item !== null) {
                        yield $item;
                    }
                }
            }
        } catch (AbortError $error) {
            throw new RequestAbortedError('Request aborted', previous: $error);
        } catch (SocketError | HttpError $error) {
            // Node's `http` when the peer goes away mid-body: `Error: aborted`.
            if ($signal?->aborted() ?? false) {
                throw new RequestAbortedError('Request aborted', previous: $error);
            }

            throw new StreamError('aborted', 'Error', $error);
        }

        $framing->end();
    }

    /**
     * `getMessageUnmarshaller()`.
     *
     * @param array{headers: array<string, array{type: string, value: mixed}>, body: string} $message
     * @return array<string, mixed>|null the union member, or null for one the client does not model
     */
    private static function unmarshal(array $message): ?array
    {
        $headers = $message['headers'];
        $messageType = $headers[':message-type']['value'] ?? null;

        if ($messageType === 'error') {
            $text = (string) ($headers[':error-message']['value'] ?? '');

            throw new StreamError($text !== '' ? $text : 'UnknownError', (string) ($headers[':error-code']['value'] ?? ''));
        }

        if ($messageType === 'exception') {
            $code = (string) ($headers[':exception-type']['value'] ?? '');

            if (!isset(self::STREAM_EXCEPTIONS[$code])) {
                throw new StreamError(JsJson::decodeUtf8($message['body']), $code);
            }

            $data = json_decode($message['body'], true);
            $text = is_array($data) && is_string($data['message'] ?? null) ? $data['message'] : (is_array($data) && is_string($data['Message'] ?? null) ? $data['Message'] : 'UnknownError');

            throw new ServiceError($text, self::STREAM_EXCEPTIONS[$code], null, null, true);
        }

        if ($messageType === 'event') {
            $type = (string) ($headers[':event-type']['value'] ?? '');

            if (!isset(self::STREAM_EVENTS[$type])) {
                return null;
            }

            $data = JsJson::parse(JsJson::decodeUtf8($message['body']));
            $member = [];

            foreach (self::STREAM_EVENTS[$type] as $key) {
                if (is_array($data) && array_key_exists($key, $data) && $data[$key] !== null) {
                    $member[$key] = $data[$key];
                }
            }

            $redacted = $member['delta']['reasoningContent']['redactedContent'] ?? null;

            if (is_string($redacted)) {
                $decoded = base64_decode($redacted, true);
                $member['delta']['reasoningContent']['redactedContent'] = $decoded === false ? '' : $decoded;
            }

            return [$type => $member];
        }

        throw new RuntimeException('Unrecognizable event type: ' . (string) ($headers[':event-type']['value'] ?? 'undefined'));
    }

    /**
     * The request body: `ConverseStreamRequest`'s members in the service's order — `modelId` goes in
     * the path — each shape the same way down.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function serializeRequest(array $input): array
    {
        $out = [];

        if (isset($input['messages']) && is_array($input['messages'])) {
            $out['messages'] = array_map(static fn (mixed $message): array|\stdClass => self::shape($message, [
                'role' => null,
                'content' => static fn (mixed $blocks): array => array_map(self::contentBlock(...), is_array($blocks) ? array_values($blocks) : []),
            ]), array_values($input['messages']));
        }

        if (isset($input['system']) && is_array($input['system'])) {
            $out['system'] = array_map(static fn (mixed $block): array|\stdClass => self::shape($block, [
                'text' => null,
                'guardContent' => null,
                'cachePoint' => self::cachePoint(...),
            ]), array_values($input['system']));
        }

        if (isset($input['inferenceConfig'])) {
            $out['inferenceConfig'] = self::shape($input['inferenceConfig'], ['maxTokens' => null, 'temperature' => null, 'topP' => null, 'stopSequences' => null]);
        }

        if (isset($input['toolConfig'])) {
            $out['toolConfig'] = self::shape($input['toolConfig'], [
                'tools' => static fn (mixed $tools): array => array_map(static fn (mixed $tool): array|\stdClass => self::shape($tool, [
                    'toolSpec' => static fn (mixed $spec): array|\stdClass => self::shape($spec, [
                        'name' => null,
                        'inputSchema' => static fn (mixed $schema): array|\stdClass => self::shape($schema, ['json' => null]),
                        'description' => null,
                        'strict' => null,
                    ]),
                    'systemTool' => null,
                    'cachePoint' => self::cachePoint(...),
                ]), is_array($tools) ? array_values($tools) : []),
                'toolChoice' => static fn (mixed $choice): array|\stdClass => self::shape($choice, [
                    'auto' => static fn (mixed $value): object => (object) (is_array($value) ? $value : []),
                    'any' => static fn (mixed $value): object => (object) (is_array($value) ? $value : []),
                    'tool' => static fn (mixed $value): array|\stdClass => self::shape($value, ['name' => null]),
                ]),
            ]);
        }

        foreach (['guardrailConfig', 'additionalModelRequestFields', 'promptVariables', 'additionalModelResponseFieldPaths', 'requestMetadata', 'performanceConfig', 'serviceTier', 'outputConfig'] as $key) {
            if (isset($input[$key])) {
                $out[$key] = $input[$key];
            }
        }

        return $out;
    }

    /**
     * `ContentBlock`, whose `image`, `toolResult` and `reasoningContent` carry blobs.
     *
     * @return array<string, mixed>|\stdClass
     */
    private static function contentBlock(mixed $block): array|\stdClass
    {
        return self::shape($block, [
            'text' => null,
            'image' => self::imageBlock(...),
            'document' => null,
            'video' => null,
            'audio' => null,
            'toolUse' => static fn (mixed $use): array|\stdClass => self::shape($use, ['toolUseId' => null, 'name' => null, 'input' => self::document(...), 'type' => null]),
            'toolResult' => static fn (mixed $result): array|\stdClass => self::shape($result, [
                'toolUseId' => null,
                'content' => static fn (mixed $blocks): array => array_map(static fn (mixed $content): array|\stdClass => self::shape($content, [
                    'json' => self::document(...),
                    'text' => null,
                    'image' => self::imageBlock(...),
                    'document' => null,
                    'video' => null,
                    'searchResult' => null,
                ]), is_array($blocks) ? array_values($blocks) : []),
                'status' => null,
                'type' => null,
            ]),
            'guardContent' => null,
            'cachePoint' => self::cachePoint(...),
            'reasoningContent' => static fn (mixed $reasoning): array|\stdClass => self::shape($reasoning, [
                'reasoningText' => static fn (mixed $text): array|\stdClass => self::shape($text, ['text' => null, 'signature' => null]),
                'redactedContent' => static fn (mixed $bytes): string => base64_encode((string) $bytes),
            ]),
            'citationsContent' => null,
            'searchResult' => null,
        ]);
    }

    /** @return array<string, mixed> */
    private static function imageBlock(mixed $image): array|\stdClass
    {
        return self::shape($image, [
            'format' => null,
            'source' => static fn (mixed $source): array|\stdClass => self::shape($source, [
                'bytes' => static fn (mixed $bytes): string => base64_encode((string) $bytes),
                's3Location' => null,
            ]),
            'error' => null,
        ]);
    }

    /** @return array<string, mixed> */
    private static function cachePoint(mixed $point): array|\stdClass
    {
        return self::shape($point, ['type' => null, 'ttl' => null]);
    }

    /**
     * A document member: sent as it is, an empty top-level map as `{}` — a tool call's arguments are
     * an object, and PHP's empty array would otherwise go out as `[]`.
     */
    private static function document(mixed $value): mixed
    {
        return $value === [] ? new \stdClass() : $value;
    }

    /**
     * A structure or union in schema order: each member that is set, through its serializer.
     *
     * A structure the input left empty is `{}` on the wire, as the SDK writes it.
     *
     * @param array<string, (Closure(mixed): mixed)|null> $members
     * @return array<string, mixed>|\stdClass
     */
    private static function shape(mixed $value, array $members): array|\stdClass
    {
        if (!is_array($value)) {
            return new \stdClass();
        }

        $out = [];

        foreach ($members as $name => $serialize) {
            if (!array_key_exists($name, $value) || $value[$name] === null) {
                continue;
            }

            $out[$name] = $serialize !== null ? $serialize($value[$name]) : $value[$name];
        }

        return $out === [] ? new \stdClass() : $out;
    }

    /** The region, as the client resolves one it was not given. */
    private function region(?string $profile): ?string
    {
        if (($this->config['region'] ?? null) !== null) {
            return $this->config['region'];
        }

        return SharedConfig::loadConfig(
            static fn (): ?string => SharedConfig::env('AWS_REGION'),
            static fn (array $values): ?string => $values['region'] ?? null,
            fn (): string => $this->instanceMetadataRegion() ?? throw new RuntimeException('Region is missing'),
            $profile,
            'credentials',
        );
    }

    /** `getInstanceMetadataRegion()`: IMDSv2 for the region, one second each, nothing on any failure. */
    private function instanceMetadataRegion(): ?string
    {
        if (SharedConfig::env('AWS_EC2_METADATA_DISABLED') !== null) {
            return null;
        }

        $endpoint = SharedConfig::env('AWS_EC2_METADATA_SERVICE_ENDPOINT') !== null
            ? rtrim((string) SharedConfig::env('AWS_EC2_METADATA_SERVICE_ENDPOINT'), '/')
            : (SharedConfig::env('AWS_EC2_METADATA_SERVICE_ENDPOINT_MODE') === 'IPv6' ? 'http://[fd00:ec2::254]' : 'http://169.254.169.254');
        $http = new HttpClient(1.0);

        try {
            $tokenResponse = $http->send(new Request('PUT', $endpoint . '/latest/api/token', ['x-aws-ec2-metadata-token-ttl-seconds' => '21600']));
            $token = $tokenResponse->body->all();

            if (!$tokenResponse->isSuccessful()) {
                return null;
            }

            $regionResponse = $http->send(new Request('GET', $endpoint . '/latest/meta-data/placement/region', ['x-aws-ec2-metadata-token' => $token]));
            $region = trim($regionResponse->body->all());
        } catch (Throwable) {
            // The SDK's `catch { return cacheNegativeAndReturnUndefined(); }`: no instance, no region.
            return null;
        }

        return $regionResponse->isSuccessful() && $region !== '' ? $region : null;
    }

    private function useFips(?string $profile, ?string $region): bool
    {
        if ($region !== null && (str_starts_with($region, 'fips-') || str_ends_with($region, '-fips'))) {
            return true;
        }

        return $this->booleanSetting('AWS_USE_FIPS_ENDPOINT', 'use_fips_endpoint', $profile);
    }

    private function booleanSetting(string $envName, string $profileKey, ?string $profile): bool
    {
        return (bool) SharedConfig::loadConfig(
            static fn (): ?bool => getenv($envName) === false ? null : SharedConfig::booleanSelector([$envName => (string) getenv($envName)], $envName, 'environment variable'),
            static fn (array $values): ?bool => SharedConfig::booleanSelector($values, $profileKey, 'config'),
            static fn (): bool => false,
            $profile,
        );
    }

    /** `getRealRegion()` and `checkRegion()`. */
    private static function realRegion(?string $region): ?string
    {
        if ($region === null) {
            return null;
        }

        if (str_starts_with($region, 'fips-') || str_ends_with($region, '-fips')) {
            $region = in_array($region, ['fips-aws-global', 'aws-fips'], true)
                ? 'us-east-1'
                : (string) preg_replace('/fips-(dkr-|prod-)?|-fips/', '', $region, 1);
        }

        if (preg_match('/^(?!.*-$)(?!-)[a-zA-Z0-9-]{1,63}$/', $region) !== 1) {
            throw new RuntimeException("Region not accepted: region=\"{$region}\" is not a valid hostname component.");
        }

        return $region;
    }

    private function maxAttempts(?string $profile): int
    {
        return (int) SharedConfig::loadConfig(
            static function (): ?int {
                $value = SharedConfig::env('AWS_MAX_ATTEMPTS');

                if ($value === null) {
                    return null;
                }

                if (preg_match('/^\s*[+-]?\d/', $value) !== 1) {
                    throw new RuntimeException("Environment variable AWS_MAX_ATTEMPTS mast be a number, got \"{$value}\"");
                }

                return (int) $value;
            },
            static function (array $values): ?int {
                $value = $values['max_attempts'] ?? null;

                if ($value === null || $value === '') {
                    return null;
                }

                if (preg_match('/^\s*[+-]?\d/', $value) !== 1) {
                    throw new RuntimeException("Shared config file entry max_attempts mast be a number, got \"{$value}\"");
                }

                return (int) $value;
            },
            static fn (): int => 3,
            $profile,
        );
    }

    /** `AWS_RETRY_MODE` / `retry_mode`: the standard mode is ported, the adaptive one is not. */
    private function assertStandardRetryMode(?string $profile): void
    {
        $mode = SharedConfig::loadConfig(
            static fn (): ?string => SharedConfig::env('AWS_RETRY_MODE'),
            static fn (array $values): ?string => $values['retry_mode'] ?? null,
            static fn (): string => 'standard',
            $profile,
        );

        if ($mode === 'adaptive') {
            throw new RuntimeException('The adaptive AWS retry mode (AWS_RETRY_MODE / retry_mode = adaptive) is not supported by pig; use standard');
        }
    }

    /**
     * The `user-agent` the SDK sends: `aws-sdk-js/<version> ua/2.1 os/<platform>#<release> lang/js
     * md/<runtime>#<version> api/<service>#<version>`, `exec-env/` from `AWS_EXECUTION_ENV`, the
     * business metrics as `m/…`, and `app/` from `AWS_SDK_UA_APP_ID` — for Bedrock's client, and for
     * the STS and SSO clients the credential providers build (`CredentialChain`), which are another
     * package at another version.
     *
     * @param array<string, string> $features
     * @internal
     */
    public static function userAgent(array $features, string $api, string $version): string
    {
        $escape = static fn (string $value): string => (string) preg_replace('/[^!$%&\'*+\-.^_`|~\w#]/', '-', $value);
        $parts = [
            'aws-sdk-js/' . $version,
            'ua/2.1',
            'os/' . PigUserAgent::platform() . '#' . $escape(PigUserAgent::release()),
            'lang/js',
            'md/php#' . $escape(PHP_VERSION),
            "api/{$api}#{$version}",
        ];
        $executionEnv = SharedConfig::env('AWS_EXECUTION_ENV');

        if ($executionEnv !== null) {
            $parts[] = 'exec-env/' . $escape($executionEnv);
        }

        $parts[] = 'm/' . implode(',', array_values(array_unique($features)));
        $appId = SharedConfig::env('AWS_SDK_UA_APP_ID');

        if ($appId !== null) {
            $parts[] = 'app/' . $escape($appId);
        }

        return implode(' ', $parts);
    }

    /**
     * The business metrics a request's credentials add, as `checkFeatures()` reads the signing
     * identity: `T` (`RESOLVED_ACCOUNT_ID`) first when they carry an account id and say where they
     * came from, then where they came from.
     *
     * @return array<string, string>
     * @internal
     */
    public static function credentialFeatures(?Credentials $credentials): array
    {
        if ($credentials === null || $credentials->features === []) {
            return [];
        }

        return [
            ...$credentials->accountId !== null ? ['RESOLVED_ACCOUNT_ID' => 'T'] : [],
            ...$credentials->features,
        ];
    }

    /**
     * smithy's `getRetryErrorType()`.
     *
     * @internal
     */
    public static function retryErrorType(Throwable $error): string
    {
        $name = $error instanceof ServiceError || $error instanceof StreamError ? $error->name : '';
        $status = $error instanceof ServiceError ? $error->status : null;

        if ($status === 429 || in_array($name, self::THROTTLING_ERROR_CODES, true)) {
            return 'THROTTLING';
        }

        if ($error instanceof TransportError
            || ($error instanceof ServiceError && $error->clockSkewCorrected)
            || in_array($name, self::TRANSIENT_ERROR_CODES, true)
            || ($name === 'InvalidSignatureException' && str_contains($error->getMessage(), 'Signature expired'))
            || in_array($status ?? 0, self::TRANSIENT_ERROR_STATUS_CODES, true)) {
            return 'TRANSIENT';
        }

        if ($status !== null && $status >= 500 && $status <= 599) {
            return 'SERVER_ERROR';
        }

        return 'CLIENT_ERROR';
    }

    private function retryDelay(string $type, int $retryCount, Throwable $error): int
    {
        return self::standardRetryDelay($type, $retryCount, $error, $this->nowMs());
    }

    /**
     * `StandardRetryStrategy::refreshRetryTokenForRetry()`'s delay.
     *
     * @internal
     */
    public static function standardRetryDelay(string $type, int $retryCount, Throwable $error, int $now): int
    {
        $base = $type === 'THROTTLING' ? self::THROTTLING_RETRY_DELAY_BASE : self::DEFAULT_RETRY_DELAY_BASE;
        $delay = (int) floor((mt_rand() / mt_getrandmax()) * min($base * 2 ** $retryCount, self::MAXIMUM_RETRY_DELAY));
        $hint = $error instanceof ServiceError ? self::retryAfterHint($error->headers ?? [], $now) : null;

        if ($hint !== null) {
            $delay = max($delay, min($hint - $now, $delay + 5_000));
        }

        return $delay;
    }

    /**
     * smithy's `parseRetryAfterHeader()`: `retry-after` in seconds or as an HTTP date, or
     * `x-amz-retry-after` in milliseconds — as a moment, in milliseconds.
     *
     * @param array<string, string> $headers
     */
    private static function retryAfterHint(array $headers, int $now): ?int
    {
        foreach ($headers as $name => $value) {
            if ($name === 'retry-after') {
                if (preg_match('/^((\d+)|(\d+\.\d+))$/', $value) === 1) {
                    return $now + (int) round((float) $value * 1000);
                }

                if (preg_match('/ GMT, ([\d.]+)$/', $value, $matches) === 1) {
                    return $now + (int) round((float) $matches[1] * 1000);
                }

                $time = strtotime($value);

                return $time === false || ($time * 1000 < $now && !str_ends_with($value, 'GMT')) ? null : $time * 1000;
            }

            if ($name === 'x-amz-retry-after') {
                return is_numeric($value) ? $now + (int) $value : null;
            }
        }

        return null;
    }

    /** The SigV4 signer's error handler: the server's clock, and a correction it says was made. */
    private function correctClockSkew(ServiceError $error, Response $response, int $sentAt): ServiceError
    {
        $before = $this->systemClockOffset;
        $this->updateClockOffset($response, $sentAt);

        if (abs($this->systemClockOffset) >= 240_000 && $this->systemClockOffset !== $before) {
            return $error->withClockSkewCorrected();
        }

        return $error;
    }

    /** `getUpdatedSystemClockOffset()`, from a response's `date` (and not when it has an `age`). */
    private function updateClockOffset(Response $response, int $sentAt): void
    {
        $date = $response->header('date');

        if ($date === null || $response->header('age') !== null) {
            return;
        }

        $serverTime = strtotime($date);
        $received = $this->nowMs();

        if ($serverTime === false || $received - $sentAt > 900_000) {
            return;
        }

        $this->systemClockOffset = (int) round($serverTime * 1000 - ($sentAt + $received) / 2);
    }

    /**
     * What Node says about a connection that failed: `connect ECONNREFUSED host:port` where it can tell.
     *
     * @internal
     */
    public static function transportMessage(Throwable $error, Request $request): string
    {
        $parts = parse_url($request->url);
        $target = ($parts['host'] ?? '') . ':' . ($parts['port'] ?? (($parts['scheme'] ?? '') === 'http' ? 80 : 443));

        return str_contains(strtolower($error->getMessage()), 'refused')
            ? "connect ECONNREFUSED {$target}"
            : $error->getMessage();
    }

    private function sleepMs(int $ms, ?AbortSignal $signal): void
    {
        self::backOff($ms, $signal, $this->sleep);
    }

    /**
     * The retry strategy's wait between attempts, ended early — as `Request aborted` — by the signal.
     *
     * @param (Closure(int): void)|null $sleep the wait itself, for tests
     * @internal
     */
    public static function backOff(int $ms, ?AbortSignal $signal, ?Closure $sleep): void
    {
        if ($sleep !== null) {
            $sleep($ms);

            return;
        }

        $done = new Deferred();
        $timer = Loop::get()->delay(max(0, $ms) / 1000, static function () use ($done): void {
            if (!$done->isComplete()) {
                $done->complete(true);
            }
        });
        $listener = $signal?->onAbort(static function () use ($done, $timer): void {
            Loop::get()->cancel($timer);

            // The timer and the abort race; whichever comes second finds the other already done.
            if (!$done->isComplete()) {
                $done->complete(false);
            }
        });

        try {
            if ($done->future->await() !== true) {
                throw new RequestAbortedError('Request aborted');
            }
        } finally {
            if ($listener !== null) {
                $signal?->removeListener($listener);
            }
        }
    }

    private function nowMs(): int
    {
        return $this->now !== null ? ($this->now)() : (int) floor(microtime(true) * 1000);
    }

    /**
     * smithy's `v4()`.
     *
     * @internal
     */
    public static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }
}
