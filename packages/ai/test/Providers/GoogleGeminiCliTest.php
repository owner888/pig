<?php

declare(strict_types=1);

namespace Pig\Ai\Test\Providers;

use PHPUnit\Framework\TestCase;
use Pig\Ai\Api;
use Pig\Ai\AssistantMessage;
use Pig\Ai\Context;
use Pig\Ai\Model;
use Pig\Ai\Models;
use Pig\Ai\Pricing;
use Pig\Ai\Providers\GoogleGeminiCli;
use Pig\Ai\Providers\GoogleOptions;
use Pig\Ai\StopReason;
use Pig\Ai\TextContent;
use Pig\Ai\Tool;
use Pig\Ai\UserMessage;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\Test\CannedServer;

/**
 * Google Cloud Code Assist — the envelope, and nothing else.
 *
 * What a Gemini request and a Gemini chunk look like is `GoogleShared`'s and is covered by
 * `GoogleTest`; asserting it again here would be asserting the same code twice. What is this
 * provider's own is the wrapper around both, the two-things-in-one-string key, and the endpoint.
 */
final class GoogleGeminiCliTest extends TestCase
{
    private CannedServer $server;

    #[\Override]
    protected function setUp(): void
    {
        Loop::reset();
        $this->server = new CannedServer();
    }

    /** The key `/login` stores: a token and a Cloud project in one string. */
    private static function key(string $token = 'ya29.a', string $project = 'proj-1'): string
    {
        return (string) json_encode(['token' => $token, 'projectId' => $project]);
    }

    // ---- what goes out --------------------------------------------------------------------

    public function testTheRequestIsAGeminiRequestInsideAProjectEnvelope(): void
    {
        $url = $this->serve([['response' => ['candidates' => [['content' => ['parts' => [['text' => 'ok']]], 'finishReason' => 'STOP']]]]]);

        $this->send($url, new Context(
            [new UserMessage('hello')],
            'be brief',
            [new Tool('read', 'Read a file', ['properties' => ['path' => ['type' => 'string']]])],
        ));

        $head = $this->server->receivedHead();
        $body = $this->server->receivedJson();

        $this->assertStringContainsString('POST /v1internal:streamGenerateContent?alt=sse', $head);
        // The token goes in a bearer header; the project goes in the body. Neither is an api key
        // header, which is what the public endpoint uses.
        $this->assertStringContainsString('authorization: Bearer ya29.a', $head);
        $this->assertStringNotContainsString('x-goog-api-key', $head);

        $this->assertSame('proj-1', $body['project']);
        $this->assertSame('test-model', $body['model']);
        $this->assertSame('pig', $body['userAgent']);
        $this->assertStringStartsWith('pig-', $body['requestId']);

        // And inside it, an ordinary Gemini request.
        $this->assertSame('hello', $body['request']['contents'][0]['parts'][0]['text']);
        $this->assertSame('be brief', $body['request']['systemInstruction']['parts'][0]['text']);
        $this->assertSame('read', $body['request']['tools'][0]['functionDeclarations'][0]['name']);
    }

    public function testCodeAssistIsToldWhoItIsTalkingTo(): void
    {
        $url = $this->serve([['response' => ['candidates' => [['content' => ['parts' => [['text' => 'ok']]]]]]]]);

        $this->send($url, new Context([new UserMessage('hi')]));

        $head = $this->server->receivedHead();

        // Upstream's three, copied. `client-metadata` is JSON inside a header, which is Google's
        // doing rather than a mistake here.
        $this->assertStringContainsString('user-agent: google-cloud-sdk vscode_cloudshelleditor/0.1', $head);
        $this->assertStringContainsString('x-goog-api-client: gl-node/22.17.0', $head);
        $this->assertStringContainsString('client-metadata: {"ideType":"IDE_UNSPECIFIED"', $head);
    }

    public function testAntigravityGetsItsOwnUserAgentBecauseTheSandboxChecksIt(): void
    {
        $url = $this->serve([['response' => ['candidates' => [['content' => ['parts' => [['text' => 'ok']]]]]]]]);

        // The same provider class against the other deployment. The sandbox answers 403 to
        // Gemini CLI's `User-Agent`, so this is load-bearing rather than cosmetic — and the
        // version and platform in it are upstream's literal string, nothing to do with the
        // machine this runs on.
        $this->send($url, new Context([new UserMessage('hi')]), sandbox: true);

        $head = $this->server->receivedHead();

        $this->assertStringContainsString('user-agent: antigravity/1.11.5 darwin/arm64', $head);
        $this->assertStringContainsString('x-goog-api-client: google-cloud-sdk vscode_cloudshelleditor/0.1', $head);
        $this->assertStringNotContainsString('gl-node/22.17.0', $head);
    }

    public function testTheRegistrysAntigravityModelsAllPointAtTheSandbox(): void
    {
        $listed = array_values(array_filter(
            Models::all(),
            static fn (Model $m): bool => $m->provider === 'google-antigravity',
        ));

        $this->assertCount(7, $listed);

        foreach ($listed as $model) {
            $this->assertSame(Api::GoogleGeminiCli, $model->api, $model->id);
            $this->assertSame(GoogleGeminiCli::SANDBOX_ENDPOINT, $model->baseUrl, $model->id);
            $this->assertSame(0.0, $model->pricing->input, $model->id);
        }
    }

    public function testAntigravityDoesNotStealAnthropicsOwnId(): void
    {
        // `claude-sonnet-4-5` is Anthropic's id, and Antigravity resells it. A bare one has to
        // keep meaning Anthropic's, or somebody with an Antigravity sign-in would find their
        // `--model sonnet` quietly going through Google.
        $this->assertSame('anthropic', Models::get('claude-sonnet-4-5')?->provider);
        $this->assertSame(
            'google-antigravity',
            Models::find('google-antigravity', 'claude-sonnet-4-5')?->provider,
        );
    }

    public function testThinkingIsOnlyMentionedWhenItWasAskedFor(): void
    {
        $url = $this->serve([['response' => ['candidates' => [['content' => ['parts' => [['text' => 'ok']]]]]]]]);

        $this->send($url, new Context([new UserMessage('hi')]), new GoogleOptions(
            apiKey: self::key(),
            thinkingEnabled: false,
        ), reasoning: true);

        // The one place the body differs from the public endpoint's: there, a turn that wants no
        // thinking has to say `thinkingBudget: 0`. Code Assist rejects a `thinkingConfig` on a
        // model that cannot think, so nothing is said unless something was asked for.
        $this->assertArrayNotHasKey('generationConfig', $this->server->receivedJson()['request']);
    }

    public function testAThinkingLevelIsPassedThrough(): void
    {
        $url = $this->serve([['response' => ['candidates' => [['content' => ['parts' => [['text' => 'ok']]]]]]]]);

        $this->send($url, new Context([new UserMessage('hi')]), new GoogleOptions(
            apiKey: self::key(),
            thinkingEnabled: true,
            thinkingLevel: 'HIGH',
        ), reasoning: true);

        $config = $this->server->receivedJson()['request']['generationConfig']['thinkingConfig'];

        $this->assertTrue($config['includeThoughts']);
        $this->assertSame('HIGH', $config['thinkingLevel']);
    }

    // ---- what comes back ------------------------------------------------------------------

    public function testAChunkIsUnwrappedBeforeItIsRead(): void
    {
        $url = $this->serve([
            ['response' => ['candidates' => [['content' => ['parts' => [['text' => 'Hel']]]]]]],
            ['response' => ['candidates' => [['content' => ['parts' => [['text' => 'lo']]]]]]],
            ['response' => ['candidates' => [['finishReason' => 'STOP']]], 'usageMetadata' => []],
        ]);

        [$types, $message] = $this->collect($url, new Context([new UserMessage('hi')]));

        // The same events `Google` produces, because it is the same walk over the same shape —
        // one key deeper.
        $this->assertSame(
            ['StartEvent', 'TextStartEvent', 'TextDeltaEvent', 'TextDeltaEvent', 'TextEndEvent', 'DoneEvent'],
            $types,
        );
        $this->assertInstanceOf(TextContent::class, $message->content[0]);
        $this->assertSame('Hello', $message->content[0]->text);
    }

    public function testAChunkWithNoResponseInItIsSkipped(): void
    {
        $url = $this->serve([
            // Code Assist says this while it is thinking about whether to say anything. Reading
            // it as a candidate would be reading a candidate that is not there.
            ['somethingElse' => true],
            ['response' => ['candidates' => [['content' => ['parts' => [['text' => 'ok']]], 'finishReason' => 'STOP']]]],
        ]);

        [$types, $message] = $this->collect($url, new Context([new UserMessage('hi')]));

        $this->assertSame(StopReason::Stop, $message->stopReason);
        $this->assertContains('TextDeltaEvent', $types);
    }

    public function testAFailureNamesThisProviderAndNotGoogle(): void
    {
        $payload = (string) json_encode(['error' => ['message' => 'project not onboarded']]);
        $url = $this->server->start([
            "HTTP/1.1 403 Forbidden\r\ncontent-type: application/json\r\ncontent-length: " . strlen($payload) . "\r\n\r\n" . $payload,
        ]);

        [$types, $message] = $this->collect($url, new Context([new UserMessage('hi')]));

        $this->assertSame(['ErrorEvent'], $types);
        $this->assertStringContainsString('google-gemini-cli returned 403', (string) $message->errorMessage);
        $this->assertStringContainsString('project not onboarded', (string) $message->errorMessage);
    }

    // ---- the key --------------------------------------------------------------------------

    public function testAnOrdinaryGeminiKeyIsRefusedByName(): void
    {
        $url = $this->serve([['response' => ['candidates' => []]]]);

        [$types, $message] = $this->collect($url, new Context([new UserMessage('hi')]), new GoogleOptions(
            apiKey: 'AIzaSySomethingThatLooksLikeAGeminiKey',
        ));

        // The likely mistake, and the only useful thing to say about it. A request sent with this
        // would come back 401 and name nothing.
        $this->assertSame(['ErrorEvent'], $types);
        $this->assertStringContainsString('/login', (string) $message->errorMessage);
        $this->assertStringContainsString('ordinary Gemini API key', (string) $message->errorMessage);
    }

    public function testAKeyWithNoProjectInItIsRefusedToo(): void
    {
        $url = $this->serve([['response' => ['candidates' => []]]]);

        [$ignored, $message] = $this->collect($url, new Context([new UserMessage('hi')]), new GoogleOptions(
            apiKey: (string) json_encode(['token' => 'ya29.a']),
        ));

        // A token with nothing to spend it against. Code Assist would answer 400 about a missing
        // project, three layers away from where the credential was read.
        $this->assertStringContainsString('Cloud project', (string) $message->errorMessage);
    }

    // ---- the registry ---------------------------------------------------------------------

    public function testTheRegistrysFiveModelsAllSpeakThisProtocol(): void
    {
        $listed = array_values(array_filter(
            Models::all(),
            static fn (Model $m): bool => $m->provider === 'google-gemini-cli',
        ));

        $this->assertCount(5, $listed);

        foreach ($listed as $model) {
            $this->assertSame(Api::GoogleGeminiCli, $model->api, $model->id);
            $this->assertSame(GoogleGeminiCli::ENDPOINT, $model->baseUrl, $model->id);
            // A subscription is not metered per token, so upstream's table is zeroes and so is
            // this — `/session` says $0.00, which is the truth.
            $this->assertSame(0.0, $model->pricing->input, $model->id);
        }
    }

    public function testABareGeminiIdStillMeansThePublicEndpoint(): void
    {
        // Code Assist's ids are Gemini's own, so this provider is in `Models::RESOLD`.
        $this->assertSame('google', Models::get('gemini-2.5-pro')?->provider);
        $this->assertSame('google-gemini-cli', Models::find('google-gemini-cli', 'gemini-2.5-pro')?->provider);
    }

    // ---- helpers --------------------------------------------------------------------------

    /** @return array{0: list<string>, 1: AssistantMessage} */
    private function collect(string $url, Context $context, ?GoogleOptions $options = null): array
    {
        return Async::run(function () use ($url, $context, $options): array {
            $stream = (new GoogleGeminiCli())->stream(
                $this->model($url),
                $context,
                $options ?? new GoogleOptions(apiKey: self::key()),
            );
            $types = [];

            foreach ($stream as $event) {
                $types[] = (new \ReflectionClass($event))->getShortName();
            }

            return [$types, $stream->result()->await()];
        });
    }

    private function send(
        string $url,
        Context $context,
        ?GoogleOptions $options = null,
        bool $reasoning = false,
        bool $sandbox = false,
    ): void {
        Async::run(function () use ($url, $context, $options, $reasoning, $sandbox): void {
            $stream = (new GoogleGeminiCli())->stream(
                $this->model($url, $reasoning, $sandbox),
                $context,
                $options ?? new GoogleOptions(apiKey: self::key()),
            );

            foreach ($stream as $ignored) {
                // Drain it; what is under test is what went out.
            }

            $stream->result()->await();
        });
    }

    private function model(
        string $baseUrl = 'http://127.0.0.1:1',
        bool $reasoning = false,
        bool $sandbox = false,
    ): Model {
        return new Model(
            'test-model',
            'Test Model',
            Api::GoogleGeminiCli,
            // The provider name is what picks the header set, so this stays a local URL and the
            // request still arrives — which is the whole reason the rule is the name and not the
            // host. See `GoogleGeminiCli::headersFor()`.
            $sandbox ? Models::ANTIGRAVITY : 'google-gemini-cli',
            rtrim($baseUrl, '/'),
            1_000_000,
            8_192,
            $reasoning,
            ['text', 'image'],
            new Pricing(),
        );
    }

    /** @param list<array<string, mixed>> $chunks */
    private function serve(array $chunks): string
    {
        $pieces = ["HTTP/1.1 200 OK\r\nContent-Type: text/event-stream\r\nTransfer-Encoding: chunked\r\n\r\n"];

        foreach ($chunks as $chunk) {
            $body = 'data: ' . json_encode($chunk) . "\n\n";
            $pieces[] = sprintf("%x\r\n%s\r\n", strlen($body), $body);
        }

        $pieces[] = "0\r\n\r\n";

        return $this->server->start($pieces);
    }
}
