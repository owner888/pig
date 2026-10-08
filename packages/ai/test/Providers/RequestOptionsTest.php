<?php

declare(strict_types=1);

namespace Pig\Ai\Test\Providers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pig\Ai\Api;
use Pig\Ai\AssistantMessage;
use Pig\Ai\Context;
use Pig\Ai\Model;
use Pig\Ai\Pricing;
use Pig\Ai\Providers\Anthropic;
use Pig\Ai\Providers\AnthropicFederation;
use Pig\Ai\Providers\AnthropicOptions;
use Pig\Ai\Providers\Google;
use Pig\Ai\Providers\GoogleOptions;
use Pig\Ai\Providers\Mistral;
use Pig\Ai\Providers\MistralOptions;
use Pig\Ai\Providers\OpenAiCompletions;
use Pig\Ai\Providers\OpenAiOptions;
use Pig\Ai\Providers\OpenAiResponses;
use Pig\Ai\StopReason;
use Pig\Ai\StreamOptions;
use Pig\Ai\UserMessage;
use Pig\Async\AbortController;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\Test\CannedServer;
use Pig\Ai\Utils\Transcript;

/**
 * The request-level options upstream's `ProviderRequestOptions` / `StreamOptions` carry, across
 * every built-in provider: `onPayload`, `onResponse`, `onProviderStreamEvent`, `headers` (a null
 * deleting one), `maxRetries` / `maxRetryDelayMs` (`retryProviderRequest()`), header-owned auth,
 * the abort messages, and the headers each pinned SDK adds on its own.
 *
 * pig's options had none of these: the providers built their request from the model alone, and a
 * failed request was retried only by the session, around the whole turn.
 */
final class RequestOptionsTest extends TestCase
{
    private CannedServer $server;

    #[\Override]
    protected function setUp(): void
    {
        Loop::reset();
        AnthropicFederation::reset();
        $this->server = new CannedServer();
    }

    /** @return iterable<string, array{0: string}> */
    public static function providers(): iterable
    {
        yield 'anthropic' => ['anthropic'];
        yield 'openai completions' => ['completions'];
        yield 'openai responses' => ['responses'];
        yield 'google' => ['google'];
        yield 'mistral' => ['mistral'];
    }

    #[DataProvider('providers')]
    public function testOnPayloadSeesThePayloadAndItsAnswerIsWhatIsSent(string $provider): void
    {
        $url = $this->server->start(self::ok($provider));
        $seen = null;
        $message = $this->send($provider, $url, [
            'onPayload' => static function (mixed $payload, Model $model) use (&$seen): array {
                $seen = [$payload, $model->id];

                return [...$payload, 'pig_probe' => 'yes'];
            },
        ]);

        $this->assertSame(StopReason::Stop, $message->stopReason, (string) $message->errorMessage);
        $this->assertIsArray($seen[0]);
        $this->assertSame('test-model', $seen[1]);
        $body = $this->server->receivedJson();

        // Gemini's payload is the SDK's `{model, contents, config}`, which the SDK turns into the
        // body and keeps only the keys it knows; the others are the body itself.
        if ($provider === 'google') {
            $this->assertSame(['model', 'contents', 'config'], array_keys($seen[0]));
            $this->assertArrayNotHasKey('pig_probe', $body);
        } else {
            $this->assertSame('yes', $body['pig_probe']);
        }
    }

    #[DataProvider('providers')]
    public function testRequestHeadersWinAndANullDeletesOne(string $provider): void
    {
        $url = $this->server->start(self::ok($provider));
        $message = $this->send($provider, $url, ['headers' => ['X-Trace' => 'abc', 'User-Agent' => null]]);

        $this->assertSame(StopReason::Stop, $message->stopReason, (string) $message->errorMessage);
        $head = strtolower($this->server->receivedHead());
        $this->assertStringContainsString("x-trace: abc\r\n", $head);

        // Gemini's SDK keeps its own `User-Agent` under the one upstream's headers remove.
        if ($provider === 'google') {
            $this->assertStringContainsString('user-agent: google-genai-sdk/2.21.0 gl-php/', $head);
        } else {
            $this->assertStringNotContainsString('user-agent:', $head);
        }
    }

    #[DataProvider('providers')]
    public function testEachParsedStreamEventIsReportedBeforeItIsRead(string $provider): void
    {
        $url = $this->server->start(self::ok($provider));
        $events = [];
        $message = $this->send($provider, $url, [
            'onProviderStreamEvent' => static function (mixed $data, Model $model) use (&$events): void {
                $events[] = $data;
            },
        ]);

        $this->assertSame(StopReason::Stop, $message->stopReason, (string) $message->errorMessage);
        $this->assertNotSame([], $events);
        $this->assertIsArray($events[0]);
    }

    /** @return iterable<string, array{0: string}> */
    public static function respondingProviders(): iterable
    {
        yield 'anthropic' => ['anthropic'];
        yield 'openai completions' => ['completions'];
        yield 'openai responses' => ['responses'];
        yield 'mistral' => ['mistral'];
    }

    #[DataProvider('respondingProviders')]
    public function testOnResponseHearsTheStatusAndHeadersBeforeTheBody(string $provider): void
    {
        $url = $this->server->start(self::ok($provider, "x-request-id: req_1\r\n"));
        $responses = [];
        $this->send($provider, $url, [
            'onResponse' => static function (array $response, Model $model) use (&$responses): void {
                $responses[] = $response;
            },
        ]);

        $this->assertCount(1, $responses);
        $this->assertSame(200, $responses[0]['status']);
        $this->assertSame('req_1', $responses[0]['headers']['x-request-id']);
    }

    /** @return iterable<string, array{0: string}> */
    public static function retryingProviders(): iterable
    {
        yield 'anthropic' => ['anthropic'];
        yield 'openai completions' => ['completions'];
        yield 'openai responses' => ['responses'];
        yield 'google' => ['google'];
    }

    #[DataProvider('retryingProviders')]
    public function testARetryableRefusalIsRetriedWhenMaxRetriesAllows(string $provider): void
    {
        $refusal = "HTTP/1.1 503 Service Unavailable\r\nretry-after-ms: 0\r\nContent-Type: application/json\r\nContent-Length: 2\r\n\r\n{}";

        $url = $this->server->startSequence([[$refusal], self::ok($provider)]);
        $message = $this->send($provider, $url, ['maxRetries' => 1]);

        $this->assertSame(StopReason::Stop, $message->stopReason, (string) $message->errorMessage);
        $this->assertSame(2, $this->server->connections());

        // Upstream's default is no provider-level retries at all.
        $this->server = new CannedServer();
        $url = $this->server->startSequence([[$refusal], self::ok($provider)]);
        $message = $this->send($provider, $url, []);

        $this->assertSame(StopReason::Error, $message->stopReason);
        $this->assertSame(1, $this->server->connections());
    }

    /** @return iterable<string, array{0: string}> */
    public static function headerReadingProviders(): iterable
    {
        yield 'anthropic' => ['anthropic'];
        yield 'openai completions' => ['completions'];
        yield 'openai responses' => ['responses'];
    }

    #[DataProvider('headerReadingProviders')]
    public function testAServerRequestedDelayPastTheCapFailsAtOnceSayingSo(string $provider): void
    {
        $url = $this->server->start(["HTTP/1.1 429 Too Many Requests\r\nretry-after: 120\r\nContent-Type: application/json\r\nContent-Length: 2\r\n\r\n{}"]);
        $message = $this->send($provider, $url, ['maxRetries' => 3]);

        $this->assertSame(StopReason::Error, $message->stopReason);
        $this->assertStringStartsWith('Server requested 120s retry delay (max: 60s). ', (string) $message->errorMessage);
        $this->assertSame(1, $this->server->connections());

        // `maxRetryDelayMs: 0` disables the cap; a 4xx that is not retryable is never retried.
        $this->server = new CannedServer();
        $url = $this->server->start(["HTTP/1.1 400 Bad Request\r\nretry-after: 120\r\nContent-Type: application/json\r\nContent-Length: 2\r\n\r\n{}"]);
        $message = $this->send($provider, $url, ['maxRetries' => 3, 'maxRetryDelayMs' => 0]);

        $this->assertSame(StopReason::Error, $message->stopReason);
        $this->assertSame(1, $this->server->connections());
    }

    public function testGeminisRefusalHasNoHeadersSoTheDoublingDecides(): void
    {
        // `retryGoogleRequest()` gives the SDK's `ApiError` an undefined `headers`: a
        // `retry-after` is never read, so neither is the cap, and the wait is the doubling.
        $refusal = "HTTP/1.1 429 Too Many Requests\r\nretry-after: 120\r\nContent-Type: application/json\r\nContent-Length: 2\r\n\r\n{}";
        $url = $this->server->startSequence([[$refusal], self::ok('google')]);
        $began = microtime(true);
        $message = $this->send('google', $url, ['maxRetries' => 1]);

        $this->assertSame(StopReason::Stop, $message->stopReason, (string) $message->errorMessage);
        $this->assertSame(2, $this->server->connections());
        $this->assertLessThan(2.0, microtime(true) - $began, 'half a second at most, not two minutes');
    }

    /** @return iterable<string, array{0: string, 1: string, 2: string}> */
    public static function sdkHeaders(): iterable
    {
        yield 'anthropic' => ['anthropic', 'anthropic/js 0.129.0', '0.129.0'];
        yield 'openai completions' => ['completions', 'openai/js 7.19.0', '7.19.0'];
        yield 'openai responses' => ['responses', 'openai/js 7.19.0', '7.19.0'];
    }

    #[DataProvider('sdkHeaders')]
    public function testTheStainlessSdksOwnHeadersAreSent(string $provider, string $sdk, string $version): void
    {
        $url = $this->server->start(self::ok($provider));
        $this->send($provider, $url, ['timeoutMs' => 300_000]);
        $head = strtolower($this->server->receivedHead());

        // The SDK's own `User-Agent` is under upstream's `defaultHeaders`, whose pi user agent
        // replaces it; a null for it there would remove both.
        $this->assertStringContainsString("user-agent: pig (", $head);
        $this->assertStringNotContainsString($sdk, $head);
        $this->assertStringContainsString("accept: application/json\r\n", $head);
        $this->assertStringContainsString("x-stainless-retry-count: 0\r\n", $head);
        $this->assertStringContainsString("x-stainless-timeout: 300\r\n", $head);
        $this->assertStringContainsString("x-stainless-lang: js\r\n", $head);
        $this->assertStringContainsString("x-stainless-package-version: {$version}\r\n", $head);
        $this->assertStringContainsString("x-stainless-runtime: php\r\n", $head);
        $this->assertStringContainsString('x-stainless-runtime-version: ' . strtolower(PHP_VERSION) . "\r\n", $head);

        // With no `timeoutMs`, the SDK's own ten minutes.
        $this->server = new CannedServer();
        $url = $this->server->start(self::ok($provider));
        $this->send($provider, $url, []);
        $this->assertStringContainsString("x-stainless-timeout: 600\r\n", strtolower($this->server->receivedHead()));
    }

    public function testGeminiGetsTheGoogleSdksHeadersAndNoAccept(): void
    {
        $url = $this->server->start(self::ok('google'));
        $this->send('google', $url, []);
        $head = strtolower($this->server->receivedHead());

        $this->assertStringContainsString('x-goog-api-client: google-genai-sdk/2.21.0 gl-php/' . PHP_VERSION . "\r\n", $head);
        $this->assertStringContainsString("x-goog-api-key: test-key\r\n", $head);
        $this->assertStringNotContainsString("\r\naccept:", $head);
        // The pi user agent replaces the SDK's, as upstream's `httpOptions.headers` does.
        $this->assertStringContainsString("user-agent: pig (", $head);
    }

    public function testAnAuthHeaderStandsInForTheKey(): void
    {
        // Upstream's header-owned auth: Anthropic accepts an `authorization`, `x-api-key` or
        // `cf-aig-authorization` header instead of a key, the OpenAI APIs an `authorization` or
        // `cf-aig-authorization` one. No key and no header is refused before anything is sent.
        $url = $this->server->start(self::ok('anthropic'));
        $message = $this->send('anthropic', $url, ['apiKey' => null, 'headers' => ['x-api-key' => 'from-header']]);

        $this->assertSame(StopReason::Stop, $message->stopReason, (string) $message->errorMessage);
        $this->assertStringContainsString("x-api-key: from-header\r\n", strtolower($this->server->receivedHead()));

        $this->server = new CannedServer();
        $url = $this->server->start(self::ok('completions'));
        $message = $this->send('completions', $url, ['apiKey' => null, 'headers' => ['Authorization' => 'Bearer gateway']]);

        $this->assertSame(StopReason::Stop, $message->stopReason, (string) $message->errorMessage);
        $this->assertStringContainsString("authorization: bearer gateway\r\n", strtolower($this->server->receivedHead()));

        $message = $this->send('anthropic', 'http://127.0.0.1:1/', ['apiKey' => null]);
        $this->assertSame('No API key for provider: anthropic', $message->errorMessage);
        $message = $this->send('responses', 'http://127.0.0.1:1/', ['apiKey' => null]);
        $this->assertSame('No API key for provider: test-provider', $message->errorMessage);
    }

    #[DataProvider('retryingProviders')]
    public function testAnAbortBeforeTheResponseIsRequestAborted(string $provider): void
    {
        // `retryProviderRequest()`: an error while the signal is aborted is `Error("Request
        // aborted")`, whatever the transport said.
        $url = $this->server->start([], closeAfter: false);
        $controller = new AbortController();
        Loop::get()->delay(0.05, static fn () => $controller->abort('esc'));
        $message = $this->send($provider, $url, ['signal' => $controller->signal]);

        $this->assertSame(StopReason::Aborted, $message->stopReason);
        $this->assertSame('Request aborted', $message->errorMessage);
    }

    public function testAnthropicFederationExchangesTheIdentityTokenAndReusesTheAccessToken(): void
    {
        // Upstream hands the `ANTHROPIC_*` federation variables to the SDK as an
        // `oidc_federation` config: with no key and no auth header, the identity token is exchanged
        // at `<baseURL>/v1/oauth/token` for an access token, which then authenticates as a bearer
        // with the `oauth-2025-04-20` beta — and is reused while it is fresh.
        $jwtFile = tempnam(sys_get_temp_dir(), 'jwt');
        file_put_contents($jwtFile, "  eyJ.identity.token \n");
        $env = [
            'ANTHROPIC_FEDERATION_RULE_ID' => 'fdrl_1',
            'ANTHROPIC_ORGANIZATION_ID' => 'org_1',
            'ANTHROPIC_IDENTITY_TOKEN_FILE' => $jwtFile,
            'ANTHROPIC_WORKSPACE_ID' => 'wrkspc_1',
        ];
        $token = (string) json_encode(['access_token' => 'sk-fed-1', 'token_type' => 'Bearer', 'expires_in' => 3600]);
        $tokenReply = "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: " . strlen($token) . "\r\n\r\n{$token}";

        try {
            $url = $this->server->startSequence([[$tokenReply], self::ok('anthropic')]);
            $message = $this->send('anthropic', $url, ['apiKey' => null, 'env' => $env]);

            $this->assertSame(StopReason::Stop, $message->stopReason, (string) $message->errorMessage);
            $this->assertSame(2, $this->server->connections());
            $received = $this->server->received();
            [$exchange, $request] = explode('POST /v1/messages?beta=true', $received, 2);
            $this->assertStringStartsWith('POST /v1/oauth/token HTTP/1.1', $exchange);
            $this->assertStringContainsString('anthropic-beta: oauth-2025-04-20,oidc-federation-2026-04-01', $exchange);
            $this->assertStringContainsString(
                '{"grant_type":"urn:ietf:params:oauth:grant-type:jwt-bearer","assertion":"eyJ.identity.token","federation_rule_id":"fdrl_1","organization_id":"org_1","workspace_id":"wrkspc_1"}',
                $exchange,
            );
            $this->assertStringContainsString("authorization: Bearer sk-fed-1\r\n", $request);
            $this->assertMatchesRegularExpression('/anthropic-beta: [^\r]*oauth-2025-04-20/', $request);

            // A second request through the same config reuses the token: one more connection —
            // the sequence's last reply, a stream — and no second exchange.
            $message = $this->send('anthropic', $url, ['apiKey' => null, 'env' => $env]);

            $this->assertSame(StopReason::Stop, $message->stopReason, (string) $message->errorMessage);
            $this->assertSame(3, $this->server->connections());
        } finally {
            unlink($jwtFile);
        }
    }

    public function testAnthropicFederationNeedsAllThreeVariablesAndOnlyTheAnthropicProvider(): void
    {
        $model = $this->model('anthropic', 'http://127.0.0.1:1');
        $env = ['ANTHROPIC_FEDERATION_RULE_ID' => 'r', 'ANTHROPIC_ORGANIZATION_ID' => 'o', 'ANTHROPIC_IDENTITY_TOKEN_FILE' => '/x'];

        $this->assertNotNull(AnthropicFederation::config($model, null, null, $env));
        $this->assertNull(AnthropicFederation::config($model, 'a-key', null, $env), 'a key wins');
        $this->assertNull(AnthropicFederation::config($model, null, ['Authorization' => 'Bearer x'], $env), 'so does an auth header');
        $this->assertNull(AnthropicFederation::config($model, null, null, [...$env, 'ANTHROPIC_IDENTITY_TOKEN_FILE' => '']));

        $openrouter = new Model('m', 'M', Api::AnthropicMessages, 'openrouter', 'http://127.0.0.1:1', 1000, 100);
        $this->assertNull(AnthropicFederation::config($openrouter, null, null, $env));
    }

    public function testAFailedTokenExchangeEndsTheTurnInTheSdksWords(): void
    {
        $jwtFile = tempnam(sys_get_temp_dir(), 'jwt');
        file_put_contents($jwtFile, 'eyJ.identity.token');
        $body = '{"error":"invalid_grant","error_description":"no match","assertion":"secret"}';
        $url = $this->server->start(["HTTP/1.1 401 Unauthorized\r\nrequest-id: req_9\r\nContent-Type: application/json\r\nContent-Length: " . strlen($body) . "\r\n\r\n{$body}"]);

        try {
            $message = $this->send('anthropic', $url, ['apiKey' => null, 'env' => [
                'ANTHROPIC_FEDERATION_RULE_ID' => 'fdrl_1',
                'ANTHROPIC_ORGANIZATION_ID' => 'org_1',
                'ANTHROPIC_IDENTITY_TOKEN_FILE' => $jwtFile,
            ]]);
        } finally {
            unlink($jwtFile);
        }

        $this->assertSame(StopReason::Error, $message->stopReason);
        $this->assertSame(
            'Token exchange failed with status 401 (request-id req_9): {"error":"invalid_grant","error_description":"no match"}'
            . " Ensure your federation rule matches your identity token. If your federation rule is scoped to multiple workspaces, set the ANTHROPIC_WORKSPACE_ID environment variable, the 'workspace_id' config key, or the `workspaceId` option. View your authentication events in the Workload identity page of Claude Console for more details.",
            $message->errorMessage,
        );
    }

    public function testMistralResolvesItsUrlAndTakesTheObjectToolChoice(): void
    {
        // `new URL("v1/chat/completions", baseUrl)` with the base path's trailing slashes made one:
        // the base URL's own path is kept, its query dropped.
        $url = $this->server->start(self::ok('mistral'));
        $message = $this->send('mistral', rtrim($url, '/') . '/proxy//?key=1', ['toolChoice' => ['type' => 'function', 'function' => ['name' => 'read']]]);

        $this->assertSame(StopReason::Stop, $message->stopReason, (string) $message->errorMessage);
        $this->assertStringStartsWith('POST /proxy/v1/chat/completions HTTP/1.1', $this->server->receivedHead());
        $this->assertSame(['type' => 'function', 'function' => ['name' => 'read']], $this->server->receivedJson()['tool_choice']);
    }

    public function testMistralsPayloadIsShownInItsSdkNames(): void
    {
        $url = $this->server->start(self::ok('mistral'));
        $seen = null;
        $this->send('mistral', $url, [
            'maxTokens' => 99,
            'onPayload' => static function (mixed $payload) use (&$seen): null {
                $seen = $payload;

                return null;
            },
        ]);

        $this->assertSame(99, $seen['maxTokens']);
        $this->assertSame(99, $this->server->receivedJson()['max_tokens']);
        $this->assertArrayNotHasKey('maxTokens', $this->server->receivedJson());
    }

    // ---- helpers --------------------------------------------------------------------------

    /**
     * One request through one provider, with these options on top of a key.
     *
     * @param array<string, mixed> $options
     */
    private function send(string $provider, string $url, array $options): AssistantMessage
    {
        $model = $this->model($provider, $url);
        $context = new Context([new UserMessage('hi')]);
        $base = ['apiKey' => 'test-key', ...$options];

        return Async::run(static function () use ($provider, $model, $context, $base): AssistantMessage {
            $stream = match ($provider) {
                'anthropic' => (new Anthropic())->stream($model, Transcript::normalizeContext($context), new AnthropicOptions(...$base)),
                'completions' => (new OpenAiCompletions())->stream($model, Transcript::normalizeContext($context), new OpenAiOptions(...$base)),
                'responses' => (new OpenAiResponses())->stream($model, Transcript::normalizeContext($context), new OpenAiOptions(...$base)),
                'google' => (new Google())->stream($model, Transcript::normalizeContext($context), new GoogleOptions(...$base)),
                'mistral' => (new Mistral())->stream($model, Transcript::normalizeContext($context), new MistralOptions(...$base)),
            };

            foreach ($stream as $ignored) {
            }

            return $stream->result()->await();
        });
    }

    private function model(string $provider, string $url): Model
    {
        [$api, $name] = match ($provider) {
            'anthropic' => [Api::AnthropicMessages, 'anthropic'],
            'completions' => [Api::OpenAiCompletions, 'test-provider'],
            'responses' => [Api::OpenAiResponses, 'test-provider'],
            'google' => [Api::GoogleGenerativeAi, 'google'],
            'mistral' => [Api::MistralConversations, 'mistral'],
        };

        // Mistral resolves its own URL (`chatCompletionsUrl()`), and keeps whatever path it is given.
        $base = $provider === 'mistral' ? $url : rtrim($url, '/');

        return new Model('test-model', 'Test Model', $api, $name, $base, 128_000, 4_096, false, ['text'], new Pricing());
    }

    /** @return list<string> a minimal successful streamed answer in the provider's own format */
    private static function ok(string $provider, string $extraHeaders = ''): array
    {
        $events = match ($provider) {
            'anthropic' => [
                "event: message_start\ndata: " . json_encode(['type' => 'message_start', 'message' => ['id' => 'msg_1', 'model' => 'test-model', 'usage' => ['input_tokens' => 1, 'output_tokens' => 0]]]),
                "event: content_block_start\ndata: " . json_encode(['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']]),
                "event: content_block_delta\ndata: " . json_encode(['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'ok']]),
                "event: content_block_stop\ndata: " . json_encode(['type' => 'content_block_stop', 'index' => 0]),
                "event: message_delta\ndata: " . json_encode(['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 1]]),
                "event: message_stop\ndata: " . json_encode(['type' => 'message_stop']),
            ],
            'completions' => [
                'data: ' . json_encode(['id' => 'c1', 'choices' => [['index' => 0, 'delta' => ['content' => 'ok'], 'finish_reason' => 'stop']]]),
                'data: [DONE]',
            ],
            'responses' => [
                "event: response.output_item.added\ndata: " . json_encode(['type' => 'response.output_item.added', 'output_index' => 0, 'item' => ['type' => 'message', 'id' => 'm1', 'content' => []]]),
                "event: response.output_text.delta\ndata: " . json_encode(['type' => 'response.output_text.delta', 'output_index' => 0, 'content_index' => 0, 'delta' => 'ok']),
                "event: response.output_item.done\ndata: " . json_encode(['type' => 'response.output_item.done', 'output_index' => 0, 'item' => ['type' => 'message', 'id' => 'm1', 'content' => [['type' => 'output_text', 'text' => 'ok']]]]),
                "event: response.completed\ndata: " . json_encode(['type' => 'response.completed', 'response' => ['status' => 'completed']]),
            ],
            'google' => [
                'data: ' . json_encode(['candidates' => [['content' => ['parts' => [['text' => 'ok']]], 'finishReason' => 'STOP']]]),
            ],
            'mistral' => [
                'data: ' . json_encode(['id' => 'x', 'choices' => [['index' => 0, 'delta' => ['content' => 'ok'], 'finish_reason' => 'stop']]]),
                'data: [DONE]',
            ],
        };

        $pieces = ["HTTP/1.1 200 OK\r\n{$extraHeaders}Content-Type: text/event-stream\r\nTransfer-Encoding: chunked\r\n\r\n"];

        foreach ($events as $event) {
            $body = $event . "\n\n";
            $pieces[] = sprintf("%x\r\n%s\r\n", strlen($body), $body);
        }

        $pieces[] = "0\r\n\r\n";

        return $pieces;
    }
}
