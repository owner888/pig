<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test\Extensions;

use PHPUnit\Framework\TestCase;
use Pig\Ai\Api;
use Pig\Ai\Context;
use Pig\Ai\Model;
use Pig\Ai\Pricing;
use PigAntigravity\AntigravityApi as Antigravity;
use Pig\Ai\Providers\GoogleOptions;
use Pig\Ai\Tool;
use Pig\Ai\UserMessage;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\Test\CannedServer;

/**
 * What goes out on the wire, which is all this provider is.
 *
 * The Gemini request inside the envelope is `GoogleShared`'s and is asserted where that lives;
 * what is this provider's own is the envelope around it — the runtime model, the enum, the
 * labels, the ids — and the two hosts.
 */
final class AntigravityApiTest extends TestCase
{
    use LoadsAntigravity;

    private CannedServer $server;

    #[\Override]
    protected function setUp(): void
    {
        self::registerAntigravity();
        Loop::reset();
        $this->server = new CannedServer();
    }

    #[\Override]
    protected function tearDown(): void
    {
        \Pig\Ai\Extension\ProviderRegistry::forget();
    }

    /** What signing in stores: a token and a Cloud project in one string. */
    private static function key(string $token = 'ya29.a', string $project = 'proj-1'): string
    {
        return (string) json_encode(['token' => $token, 'projectId' => $project]);
    }

    // ---- the envelope ---------------------------------------------------------------------

    public function testTheModelOnTheWireIsTheRuntimeOneAndNotTheOneChosen(): void
    {
        // The whole reason `Antigravity\Routing` exists: `gemini-3.8-flash` at medium is a
        // different upstream model, named by an enum.
        $this->send('gemini-3.8-flash', 'medium');

        $body = $this->server->receivedJson();

        $this->assertSame('gemini-3.8-flash-medium', $body['model']);
        $this->assertSame('MODEL_PLACEHOLDER_M319', $body['request']['labels']['model_enum']);
    }

    public function testTheLevelChangesWhatIsSent(): void
    {
        $this->send('gemini-3.8-flash', 'high');

        $this->assertSame('gemini-3.8-flash-high', $this->server->receivedJson()['model']);
    }

    public function testThinkingOffStillPicksARuntimeModelAndAsksForNoBudget(): void
    {
        $this->send('gemini-3.8-flash', null);

        $body = $this->server->receivedJson();

        $this->assertSame('gemini-3.8-flash-low', $body['model']);
        $this->assertArrayNotHasKey('thinkingConfig', $body['request']['generationConfig'] ?? []);
    }

    public function testTheBudgetComesFromTheRoutingTablesRatherThanTheCaller(): void
    {
        $this->send('gemini-3.8-flash', 'medium');

        $thinking = $this->server->receivedJson()['request']['generationConfig']['thinkingConfig'];

        $this->assertSame(4000, $thinking['thinkingBudget']);
        $this->assertTrue($thinking['includeThoughts']);
    }

    public function testTheTopLevelAsksForAnUncappedBudget(): void
    {
        $this->send('gemini-3.8-flash', 'high');

        $this->assertSame(-1, $this->server->receivedJson()['request']['generationConfig']['thinkingConfig']['thinkingBudget']);
    }

    public function testThinkingTurnedOffBeatsALevelThatWasLeftSet(): void
    {
        // The two arriving contradictory is not hypothetical: `Stream` sets both, and every other
        // provider here reads `thinkingEnabled: false` as off whatever the level says. Reading
        // only the level would have this turn think at `high`.
        $url = $this->serve([['response' => ['candidates' => [['content' => ['parts' => [['text' => 'ok']]], 'finishReason' => 'STOP']]]]]);

        Async::run(function () use ($url): void {
            $stream = (new Antigravity())->stream(
                $this->model($url),
                new Context([new UserMessage('hello')]),
                new GoogleOptions(apiKey: self::key(), thinkingEnabled: false, thinkingLevel: 'high'),
            );

            foreach ($stream as $ignored) {
                // Drain it.
            }

            $stream->result()->await();
        });

        $body = $this->server->receivedJson();

        $this->assertSame('gemini-3-flash', $body['model']);
        $this->assertArrayNotHasKey('thinkingConfig', $body['request']['generationConfig'] ?? []);
    }

    public function testTheEnvelopeSaysItIsAnAgentAndCallsItselfAntigravity(): void
    {
        // Both are the reference implementation's, and the `userAgent` field in particular is
        // upstream's own fix for 429s — pig used to send `pig` here.
        $this->send('gemini-3-flash', 'low');

        $body = $this->server->receivedJson();

        $this->assertSame('agent', $body['requestType']);
        $this->assertSame('antigravity', $body['userAgent']);
        $this->assertSame('proj-1', $body['project']);
    }

    public function testTheUserAgentHeaderIsTheClientsOwn(): void
    {
        // This deployment answers 403 to the wrong one, so it is load bearing rather than
        // decorative.
        $this->send('gemini-3-flash', 'low');

        $this->assertStringContainsString('antigravity/cli/', $this->server->receivedHead());
        $this->assertStringContainsString('aidev_client', $this->server->receivedHead());
    }

    public function testAClaudeModelIsLabelledAsOneAndAGeminiIsNot(): void
    {
        $this->send('claude-sonnet-4-6', 'medium');
        $labels = $this->server->receivedJson()['request']['labels'];

        $this->assertSame('true', $labels['used_claude']);
        $this->assertSame('true', $labels['used_claude_conservative']);
        $this->assertSame('true', $labels['used_non_gemini_model']);

        $this->server = new CannedServer();
        $this->send('gemini-3-flash', 'medium');
        $labels = $this->server->receivedJson()['request']['labels'];

        $this->assertSame('false', $labels['used_claude']);
        $this->assertSame('false', $labels['used_non_gemini_model']);
    }

    public function testGptOssIsNotClaudeButIsNotGeminiEither(): void
    {
        $this->send('gpt-oss-120b', 'medium');
        $labels = $this->server->receivedJson()['request']['labels'];

        $this->assertSame('false', $labels['used_claude']);
        $this->assertSame('true', $labels['used_non_gemini_model']);
    }

    public function testTheSameConversationKeepsTheSameTrajectoryId(): void
    {
        // What the deployment caches against, so it has to be derived from the conversation and
        // not from the clock. Two turns of one conversation, two requests, one trajectory.
        $context = new Context([new UserMessage('the opening line')]);

        $this->send('gemini-3-flash', 'low', $context);
        $first = $this->server->receivedJson()['request']['labels']['trajectory_id'];

        $this->server = new CannedServer();
        $this->send('gemini-3-flash', 'low', $context);
        $second = $this->server->receivedJson()['request']['labels']['trajectory_id'];

        $this->assertSame($first, $second);
    }

    public function testADifferentConversationGetsADifferentTrajectoryId(): void
    {
        $this->send('gemini-3-flash', 'low', new Context([new UserMessage('one thing')]));
        $first = $this->server->receivedJson()['request']['labels']['trajectory_id'];

        $this->server = new CannedServer();
        $this->send('gemini-3-flash', 'low', new Context([new UserMessage('a different thing')]));

        $this->assertNotSame($first, $this->server->receivedJson()['request']['labels']['trajectory_id']);
    }

    public function testTheRequestIdCarriesTheTrajectoryAndTheStepCount(): void
    {
        $this->send('gemini-3-flash', 'low', new Context([new UserMessage('hello')]));

        $body = $this->server->receivedJson();
        $trajectory = $body['request']['labels']['trajectory_id'];

        $this->assertMatchesRegularExpression("~^agent/[0-9a-f]{16}/\d+/{$trajectory}/\d+$~", $body['requestId']);
        $this->assertSame('0', $body['request']['labels']['last_step_index']);
    }

    public function testAnOpeningTurnWithNoTextStillGetsAStableSeed(): void
    {
        // A first message that is only a picture has nothing to key on. It must not fall back to
        // something random, or every turn of that conversation is a different trajectory.
        $context = new Context([new UserMessage([])]);

        $this->send('gemini-3-flash', 'low', $context);
        $first = $this->server->receivedJson()['request']['labels']['trajectory_id'];

        $this->server = new CannedServer();
        $this->send('gemini-3-flash', 'low', $context);

        $this->assertSame($first, $this->server->receivedJson()['request']['labels']['trajectory_id']);
    }

    public function testTheGeminiRequestIsStillInsideTheEnvelope(): void
    {
        $this->send('gemini-3-flash', 'low', new Context(
            [new UserMessage('hello')],
            'be brief',
            [new Tool('read', 'Read a file', ['properties' => ['path' => ['type' => 'string']]])],
        ));

        $inner = $this->server->receivedJson()['request'];

        $this->assertSame('hello', $inner['contents'][0]['parts'][0]['text']);
        $this->assertSame('be brief', $inner['systemInstruction']['parts'][0]['text']);
        $this->assertSame('read', $inner['tools'][0]['functionDeclarations'][0]['name']);
    }

    // ---- the two hosts --------------------------------------------------------------------

    public function testA403OnTheFirstHostIsTriedAgainOnTheSecond(): void
    {
        $refusing = new CannedServer();
        $refused = $refusing->start([self::refusal(403, 'not for you')]);

        $answering = $this->serve([['response' => ['candidates' => [['content' => ['parts' => [['text' => 'ok']]], 'finishReason' => 'STOP']]]]]);

        $this->drain(new Antigravity(fallback: $answering), $this->model($refused), 'gemini-3-flash', 'low', new Context([new UserMessage('hi')]));

        // The second host is the one that saw the request through.
        $this->assertStringContainsString('gemini-3-flash', $this->server->receivedJson()['model']);
    }

    public function testA429IsNotRetriedOnTheOtherHost(): void
    {
        // The quota answering, not the host refusing us — asking the other one gets the same
        // answer, and waiting it out belongs a level up.
        $quota = new CannedServer();
        $url = $quota->start([self::refusal(429, 'RESOURCE_EXHAUSTED: out of quota for today')]);

        $second = new CannedServer();
        $secondUrl = $second->start([self::refusal(200, 'unused')]);

        $message = $this->drain(new Antigravity(fallback: $secondUrl), $this->model($url), 'gemini-3-flash', 'low', new Context([new UserMessage('hi')]));

        $this->assertStringContainsString('out of quota', (string) $message);
        $this->assertSame('', $second->receivedHead(), 'the second host was never asked');
    }

    public function testAQuotaRefusalIsNamedAsOneRatherThanLeftAsJson(): void
    {
        $server = new CannedServer();
        $url = $server->start([self::refusal(429, 'RESOURCE_EXHAUSTED: slow down')]);

        $message = $this->drain(new Antigravity(), $this->model($url), 'gemini-3-flash', 'low', new Context([new UserMessage('hi')]));

        $this->assertStringContainsString('out of quota', (string) $message);
        $this->assertStringContainsString('slow down', (string) $message);
    }

    public function testAnOrdinaryFailureIsNotCalledAQuotaOne(): void
    {
        $server = new CannedServer();
        $url = $server->start([self::refusal(400, 'contents is empty')]);

        $message = $this->drain(new Antigravity(), $this->model($url), 'gemini-3-flash', 'low', new Context([new UserMessage('hi')]));

        $this->assertStringContainsString('returned 400', (string) $message);
        $this->assertStringNotContainsString('quota', (string) $message);
    }

    public function testAKeyThatIsNotATokenAndAProjectIsNamedRatherThanSent(): void
    {
        $message = $this->drain(
            new Antigravity(),
            $this->model('http://127.0.0.1:1'),
            'gemini-3-flash',
            'low',
            new Context([new UserMessage('hi')]),
            // Short on purpose: long enough to read as "somebody pasted a Gemini key", too
            // short for a secret scanner to mistake for one.
            'AIza-fake',
        );

        $this->assertStringContainsString('token and a Cloud project', (string) $message);
    }

    // ---- what the registry offers ----------------------------------------------------------

    public function testTheRegistrysAntigravityModelsAreAllOnThisProvider(): void
    {
        $listed = array_values(array_filter(
            \Pig\Ai\Models::all(),
            static fn (Model $m): bool => $m->provider === 'antigravity',
        ));

        $this->assertCount(14, $listed);

        foreach ($listed as $model) {
            $this->assertSame(Api::Extension, $model->api, $model->id);
            $this->assertSame(Antigravity::ENDPOINT, $model->baseUrl, $model->id);
            // A subscription, so there is no per-token price to report.
            $this->assertSame(0.0, $model->pricing->input, $model->id);
            // Every one of them always thinks, which is the deployment's answer and not an
            // oversight — see the table's docblock.
            $this->assertFalse($model->hasThinkingLevel('off'), $model->id);
        }
    }

    public function testAntigravityDoesNotStealAnthropicsOwnId(): void
    {
        // `claude-sonnet-4-6` is Anthropic's id and Antigravity resells it. A bare one has to
        // keep meaning Anthropic's, or somebody with an Antigravity sign-in would find their
        // `--model sonnet` quietly going through Google. This test moved here with the provider;
        // the rule is `Models::RESOLD`'s and did not change.
        $this->assertNotSame('antigravity', \Pig\Ai\Models::get('claude-sonnet-4-6')?->provider);
        $this->assertSame('antigravity', \Pig\Ai\Models::find('antigravity', 'claude-sonnet-4-6')?->provider);
    }

    // ---- helpers ---------------------------------------------------------------------------

    private function send(string $model, ?string $level, ?Context $context = null): void
    {
        $url = $this->serve([['response' => ['candidates' => [['content' => ['parts' => [['text' => 'ok']]], 'finishReason' => 'STOP']]]]]);

        $this->drain(new Antigravity(), $this->model($url), $model, $level, $context ?? new Context([new UserMessage('hello')]));
    }

    /** @return string|null the error the stream ended with, when it ended with one */
    private function drain(
        Antigravity $provider,
        Model $model,
        string $id,
        ?string $level,
        Context $context,
        ?string $key = null,
    ): ?string {
        return Async::run(function () use ($provider, $model, $id, $level, $context, $key): ?string {
            $stream = $provider->stream(
                new Model(
                    $id,
                    $id,
                    Api::Extension,
                    'antigravity',
                    $model->baseUrl,
                    $model->contextWindow,
                    $model->maxTokens,
                    true,
                    ['text', 'image'],
                    new Pricing(),
                ),
                $context,
                // Both, the way `Stream` sets them: a level and the flag that says it counts.
                new GoogleOptions(
                    apiKey: $key ?? self::key(),
                    thinkingEnabled: $level !== null,
                    thinkingLevel: $level,
                ),
            );

            foreach ($stream as $ignored) {
                // Drain it; what is under test is what went out.
            }

            return $stream->result()->await()->errorMessage;
        });
    }

    private function model(string $baseUrl): Model
    {
        return new Model(
            'gemini-3-flash',
            'Gemini 3 Flash',
            Api::Extension,
            'antigravity',
            rtrim($baseUrl, '/'),
            1_048_576,
            65_536,
            true,
            ['text', 'image'],
            new Pricing(),
        );
    }

    /**
     * One canned refusal, with a `Content-Length` that matches the body.
     *
     * Counted rather than written: a hand-typed length that is short cuts the JSON off, and the
     * test then fails against half an error message for reasons that have nothing to do with the
     * code under test.
     */
    private static function refusal(int $status, string $message): string
    {
        $body = (string) json_encode(['error' => ['message' => $message]]);

        return "HTTP/1.1 {$status} Refused\r\nContent-Length: " . strlen($body) . "\r\n\r\n" . $body;
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
