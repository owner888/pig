<?php

declare(strict_types=1);

namespace Pig\Ai\Test\Providers;

use PHPUnit\Framework\TestCase;
use Pig\Ai\ClassifierApi;
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
use Pig\Ai\Model;
use Pig\Ai\Models;
use Pig\Ai\ModelType;
use Pig\Ai\Pricing;
use Pig\Ai\Providers\TypesafeSystemOne;
use Pig\Ai\StopReason;
use Pig\Async\AbortController;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\Test\ScriptedServer;

/**
 * The System One classifier APIs — upstream's `typesafe-system-one.test.ts`,
 * `cloudflare-workers-ai-system-one.test.ts` and `classifier-models.test.ts`. Upstream hands the API a
 * `fetch`; here a `ScriptedServer` stands where the service would, the model's base URL pointed at
 * it (with Workers AI's `{CLOUDFLARE_ACCOUNT_ID}` placeholder kept in its path, so the substitution
 * is what puts the account there).
 */
final class SystemOneTest extends TestCase
{
    private ?ScriptedServer $server = null;

    #[\Override]
    protected function setUp(): void
    {
        Loop::reset();
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->server?->stop();
    }

    public function testBoolQuestionsAndAnswersAreTypesafesNoulOnTheWire(): void
    {
        $base = $this->serve(static fn (): array => [200, [], json_encode(['answers' => self::wireAnswers()])]);

        // "System One has no temperature field; the option is ignored."
        $result = self::classify(self::typesafe($base . '/v1/'), self::context(), new ClassifierOptions(apiKey: 'secret', temperature: 1.5));

        $this->assertCount(1, $this->server()->requests);
        $request = $this->server()->requests[0];
        $this->assertSame('/v1/systemone', $request['path']);
        $this->assertSame('Bearer secret', $request['headers']['authorization'] ?? null);
        $payload = json_decode($request['body'], true);
        $this->assertSame('jev-latest', $payload['model']);
        $this->assertSame(['choice', 'score', 'noul'], [$payload['questions']['category']['type'], $payload['questions']['satisfaction']['type'], $payload['questions']['approved']['type']]);
        $this->assertArrayNotHasKey('temperature', $payload);

        $this->assertSame(StopReason::Stop, $result->stopReason, (string) $result->errorMessage);
        $this->assertEquals(new ClassifierBoolAnswer(0.95), $result->answers['approved']);
        $this->assertInstanceOf(ClassifierChoiceAnswer::class, $result->answers['category']);
        $this->assertSame('success', $result->answers['category']->choice);
        $this->assertEquals(new ClassifierScoreAnswer(2.0, 0.7), $result->answers['satisfaction']);
        $this->assertNull($result->usage);
    }

    public function testReportedUsageIsPricedFromTheCatalogue(): void
    {
        $base = $this->serve(static fn (): array => [200, [], json_encode(['answers' => self::wireAnswers(), 'usage' => ['input_tokens' => 308, 'output_tokens' => 23]])]);

        $result = self::classify(self::typesafe($base . '/v1/', new Pricing(0.042)), self::context(), new ClassifierOptions(apiKey: 'secret'));

        $this->assertNotNull($result->usage);
        $this->assertSame([308, 23, 331], [$result->usage->input, $result->usage->output, $result->usage->totalTokens]);
        $this->assertEqualsWithDelta(0.000012936, $result->usage->cost->total, 1e-12);
    }

    public function testOpenRoutersSystemOneEndpointIsTypesafesProtocol(): void
    {
        // Response shape observed from the live OpenRouter endpoint.
        $base = $this->serve(static fn (): array => [200, [], json_encode([
            'id' => 'gen-dec-1',
            'provider' => 'TypeSafe',
            'answers' => self::wireAnswers(),
            'usage' => ['input_tokens' => 308, 'output_tokens' => 23, 'cost' => 0.000012936],
        ])]);
        $model = new ClassifierModel('typesafe/jev-1.13', 'Jev', ClassifierApi::TypesafeSystemOne, 'openrouter', $base . '/api/v1', 32_000, pricing: new Pricing(0.042));

        $result = self::classify($model, self::context(), new ClassifierOptions(apiKey: 'secret'));

        $this->assertSame('/api/v1/systemone', $this->server()->requests[0]['path']);
        $this->assertSame(['typesafe/jev-1.13', ['text' => 'The deployment succeeded, thank you.']], [$this->server()->bodies()[0]['model'], $this->server()->bodies()[0]['state']]);
        $this->assertSame(StopReason::Stop, $result->stopReason);
        $this->assertEquals(new ClassifierBoolAnswer(0.95), $result->answers['approved']);
        // "Priced from the catalog like chat usage; matches OpenRouter's reported cost."
        $this->assertEqualsWithDelta(0.000012936, $result->usage?->cost->total, 1e-12);
    }

    public function testAModelForAnotherClassifierApiIsRefusedBeforeAnyRequest(): void
    {
        $base = $this->serve(static fn (): array => [200, [], json_encode(['answers' => self::wireAnswers()])]);
        $model = new ClassifierModel('jev-latest', 'Jev', ClassifierApi::CloudflareWorkersAiSystemOne, 'typesafe', $base, 64_000);

        $result = Async::run(static fn (): ClassifierResult => (new TypesafeSystemOne())->classify($model, self::context(), new ClassifierOptions(apiKey: 'secret')));

        $this->assertSame([], $this->server()->requests);
        $this->assertSame(StopReason::Error, $result->stopReason);
        $this->assertStringContainsString('Unsupported classifier API: cloudflare-workers-ai-system-one', (string) $result->errorMessage);
    }

    public function testHeadersMergeWhateverTheirCaseAndANullSuppressesOne(): void
    {
        $base = $this->serve(static fn (): array => [200, [], json_encode(['answers' => self::wireAnswers()])]);
        $model = new ClassifierModel('jev-latest', 'Jev', ClassifierApi::TypesafeSystemOne, 'typesafe', $base . '/v1/', 64_000, headers: ['authorization' => 'Bearer model', 'X-Source' => 'model']);

        self::classify($model, self::context(), new ClassifierOptions(apiKey: 'secret', headers: ['Authorization' => 'Bearer request', 'x-source' => 'request']));
        self::classify($model, self::context(), new ClassifierOptions(apiKey: 'secret', headers: ['Authorization' => null]));

        $this->assertSame('Bearer request', $this->server()->requests[0]['headers']['authorization'] ?? null);
        $this->assertSame('request', $this->server()->requests[0]['headers']['x-source'] ?? null);
        $this->assertArrayNotHasKey('authorization', $this->server()->requests[1]['headers']);
    }

    public function testPrototypeSensitiveQuestionIdsKeepTheirAnswers(): void
    {
        $base = $this->serve(static fn (): array => [200, [], '{"answers":{"__proto__":{"type":"noul","noul":0.75}}}']);
        $context = new ClassifierContext([], ['__proto__' => new ClassifierBoolQuestion('Is this true?', ['true' => 'Yes', 'false' => 'No'])]);

        $result = self::classify(self::typesafe($base . '/v1/'), $context, new ClassifierOptions(apiKey: 'secret'));

        $this->assertSame(StopReason::Stop, $result->stopReason, (string) $result->errorMessage);
        $this->assertSame(['__proto__'], array_keys($result->answers));
        $this->assertEquals(new ClassifierBoolAnswer(0.75), $result->answers['__proto__']);
        // `state: {}` goes out as an object.
        $this->assertStringContainsString('"state":{}', $this->server()->requests[0]['body']);
    }

    public function testATimeoutIsWordedApartFromTheCallersCancellation(): void
    {
        $base = $this->serve(static fn (): ?array => null);

        $result = self::classify(self::typesafe($base . '/v1/'), self::context(), new ClassifierOptions(apiKey: 'secret', timeoutMs: 5, maxRetries: 0));

        $this->assertSame(StopReason::Error, $result->stopReason);
        $this->assertSame('Request timed out after 5ms', $result->errorMessage);
    }

    public function testEveryRetryGetsAFreshTimeout(): void
    {
        $attempt = 0;
        $base = $this->serve(static function () use (&$attempt): array {
            $attempt++;

            return $attempt === 1 ? [500, ['retry-after-ms' => '0', 'content-type' => 'text/plain'], 'retry'] : [200, [], json_encode(['answers' => self::wireAnswers()])];
        });

        $result = self::classify(self::typesafe($base . '/v1/'), self::context(), new ClassifierOptions(apiKey: 'secret', timeoutMs: 1000, maxRetries: 1));

        $this->assertSame(StopReason::Stop, $result->stopReason, (string) $result->errorMessage);
        $this->assertCount(2, $this->server()->requests);
    }

    public function testARefusalNamesTheServiceItsStatusAndItsBody(): void
    {
        $base = $this->serve(static fn (): array => [401, [], '{"error":"bad key"}']);

        $result = self::classify(self::typesafe($base . '/v1/'), self::context(), new ClassifierOptions(apiKey: 'secret', maxRetries: 0));

        $this->assertSame('System One API error (401): {"error":"bad key"}', $result->errorMessage);
    }

    public function testMalformedAnswersAreAnErrorThatKeepsTheBilledUsage(): void
    {
        $base = $this->serve(static fn (): array => [200, [], json_encode(['answers' => (object) [], 'usage' => ['input_tokens' => 10, 'output_tokens' => 2]])]);

        $result = self::classify(self::typesafe($base . '/v1/'), self::context(), new ClassifierOptions(apiKey: 'secret'));

        $this->assertSame(StopReason::Error, $result->stopReason);
        $this->assertSame([], $result->answers);
        $this->assertStringContainsString('did not return an answer for category', (string) $result->errorMessage);
        // "The request was billed, so its usage is kept."
        $this->assertSame([10, 2], [$result->usage?->input, $result->usage?->output]);
    }

    public function testMalformedUsageIsIgnored(): void
    {
        $bodies = [
            json_encode(['answers' => self::wireAnswers(), 'usage' => ['input_tokens' => 'many', 'output_tokens' => 3]]),
            json_encode(['answers' => self::wireAnswers(), 'usage' => ['cost' => 0.1]]),
        ];
        $base = $this->serve(static function () use (&$bodies): array {
            return [200, [], (string) array_shift($bodies)];
        });

        $result = self::classify(self::typesafe($base . '/v1/'), self::context(), new ClassifierOptions(apiKey: 'secret'));
        $withoutTokens = self::classify(self::typesafe($base . '/v1/'), self::context(), new ClassifierOptions(apiKey: 'secret'));

        $this->assertSame(StopReason::Stop, $result->stopReason);
        $this->assertSame([0, 3, 3], [$result->usage?->input, $result->usage?->output, $result->usage?->totalTokens]);
        $this->assertSame(StopReason::Stop, $withoutTokens->stopReason);
        $this->assertNull($withoutTokens->usage);
    }

    public function testACancelledRequestIsAborted(): void
    {
        $base = $this->serve(static fn (): array => [200, [], json_encode(['answers' => self::wireAnswers()])]);
        $controller = new AbortController();
        $controller->abort();

        $result = self::classify(self::typesafe($base . '/v1/'), self::context(), new ClassifierOptions(apiKey: 'secret', signal: $controller->signal));

        $this->assertSame(StopReason::Aborted, $result->stopReason);
        $this->assertSame([], $this->server()->requests);
    }

    // ---- Workers AI ---------------------------------------------------------------------------

    public function testJevIsReachedOnlyThroughTheClassifierCatalogue(): void
    {
        $jev = Models::findOfType(ModelType::Classifier, 'cloudflare-workers-ai', 'typesafe/jev');

        $this->assertInstanceOf(ClassifierModel::class, $jev);
        $this->assertSame([ClassifierApi::CloudflareWorkersAiSystemOne, 32_000], [$jev->api, $jev->contextWindow]);
        $this->assertNull(Models::find('cloudflare-workers-ai', 'typesafe/jev'));
    }

    public function testWorkersAiRunsJevThroughTheAccountScopedRunEndpoint(): void
    {
        $base = $this->serve(static fn (): array => [200, [], json_encode(self::restResponse('Completed', self::jevOutput()))]);

        $result = Async::run(static fn (): ClassifierResult => Models::classify(...self::workersAiCall($base, 'typesafe/jev')));

        $request = $this->server()->requests[0];
        $this->assertSame('/client/v4/accounts/account-id/ai/run', $request['path']);
        $this->assertSame('Bearer cf-key', $request['headers']['authorization'] ?? null);
        $payload = json_decode($request['body'], true);
        $this->assertSame('typesafe/jev', $payload['model']);
        $this->assertSame(['message' => 'Help! My payouts have been failing for 3 days.'], $payload['input']['state']);
        $this->assertSame(['noul', 'choice'], [$payload['input']['questions']['is_urgent']['type'], $payload['input']['questions']['department']['type']]);

        $this->assertSame(StopReason::Stop, $result->stopReason, (string) $result->errorMessage);
        $this->assertEquals(new ClassifierBoolAnswer(0.95), $result->answers['is_urgent']);
        $this->assertEquals(new ClassifierChoiceAnswer('billing', ['billing' => 0.87, 'technical' => 0.13], 0.8), $result->answers['department']);
        $this->assertSame([426, 73, 499], [$result->usage?->input, $result->usage?->output, $result->usage?->totalTokens]);
    }

    public function testWorkersAisClefModelsReturnTheirOutputDirectly(): void
    {
        foreach (['@cf/cloudflare/clef' => 0.24, '@cf/cloudflare/clef-flash' => 0.09] as $id => $inputPrice) {
            $clef = [
                'model' => 'clef',
                'answers' => [
                    'is_urgent' => ['type' => 'noul', 'noul' => 0.9912],
                    'department' => ['type' => 'choice', 'choice' => 'technical', 'probabilities' => ['billing' => 0.1632, 'technical' => 0.8368], 'confidence' => 0.4538],
                ],
                'usage' => ['input_tokens' => 222, 'output_tokens' => 0],
            ];
            $base = $this->serve(static fn (): array => [200, [], json_encode(['result' => $clef, 'success' => true, 'errors' => [], 'messages' => []])]);

            $result = Async::run(static fn (): ClassifierResult => Models::classify(...self::workersAiCall($base, $id)));

            $this->assertSame($id, json_decode($this->server()->requests[0]['body'], true)['model'], $id);
            $this->assertSame(StopReason::Stop, $result->stopReason, $id);
            $this->assertEquals(new ClassifierBoolAnswer(0.9912), $result->answers['is_urgent']);
            $this->assertSame([222, 0, 222], [$result->usage?->input, $result->usage?->output, $result->usage?->totalTokens]);
            $this->assertEqualsWithDelta(222 * $inputPrice / 1_000_000, $result->usage?->cost->input, 1e-12);
            $this->server()->stop();
        }
    }

    public function testWorkersAiRunsThatDidNotCompleteAndEnvelopeErrorsAreErrors(): void
    {
        $bodies = [
            json_encode(self::restResponse('Queued', null)),
            json_encode(['success' => false, 'errors' => [['code' => 5007, 'message' => 'No such model']], 'result' => null]),
        ];
        $base = $this->serve(static function () use (&$bodies): array {
            return [200, [], (string) array_shift($bodies)];
        });

        $queued = Async::run(static fn (): ClassifierResult => Models::classify(...self::workersAiCall($base, 'typesafe/jev')));
        $refused = Async::run(static fn (): ClassifierResult => Models::classify(...self::workersAiCall($base, 'typesafe/jev')));

        $this->assertSame(StopReason::Error, $queued->stopReason);
        $this->assertStringContainsString('run did not complete (state: Queued)', (string) $queued->errorMessage);
        $this->assertStringContainsString('Cloudflare Workers AI error: No such model', (string) $refused->errorMessage);
    }

    public function testWorkersAiWithoutAnAccountIsNotConfigured(): void
    {
        $model = Models::findOfType(ModelType::Classifier, 'cloudflare-workers-ai', 'typesafe/jev');
        $this->assertInstanceOf(ClassifierModel::class, $model);

        $result = Models::classify($model, self::context(), new ClassifierOptions(apiKey: 'cf-key'));

        $this->assertSame(StopReason::Error, $result->stopReason);
        $this->assertSame('Provider is not configured: cloudflare-workers-ai', $result->errorMessage);
    }

    // ---- the catalogue ------------------------------------------------------------------------

    public function testJevIsTypesafesOnlyModelAndNotAChatModel(): void
    {
        $jev = Models::findOfType(ModelType::Classifier, 'typesafe', 'jev-latest');

        $this->assertInstanceOf(ClassifierModel::class, $jev);
        $this->assertSame([ClassifierApi::TypesafeSystemOne, 'typesafe', 64_000, 'https://api.typesafe.ai/v1/'], [$jev->api, $jev->provider, $jev->contextWindow, $jev->baseUrl]);
        $this->assertNull(Models::find('typesafe', 'jev-latest'));
        $this->assertSame(['jev-latest'], array_values(array_map(
            static fn (ClassifierModel $model): string => $model->id,
            array_filter(Models::allOfType(ModelType::Classifier), static fn (ClassifierModel $model): bool => $model->provider === 'typesafe'),
        )));
        $this->assertInstanceOf(Model::class, Models::findOfType(ModelType::Chat, 'anthropic', 'claude-opus-4-6'));
    }

    public function testTheGatewaysAndOpenCodesJevGoToTheirTypesafeCompatibleEndpoints(): void
    {
        foreach ([
            ['vercel-ai-gateway', 'typesafe-ai/jev', 'https://ai-gateway.vercel.sh/typesafe/v1'],
            ['opencode', 'jev-1.13', 'https://opencode.ai/zen/v1'],
            ['opencode', 'jev-1.13-free', 'https://opencode.ai/zen/v1'],
        ] as [$provider, $id, $baseUrl]) {
            $jev = Models::findOfType(ModelType::Classifier, $provider, $id);
            $this->assertInstanceOf(ClassifierModel::class, $jev, "{$provider}/{$id}");
            $this->assertSame([ClassifierApi::TypesafeSystemOne, 32_000, $baseUrl], [$jev->api, $jev->contextWindow, $jev->baseUrl]);
            $this->assertNull(Models::find($provider, $id));
        }

        foreach (Models::allOfType(ModelType::Classifier) as $model) {
            if ($model->provider === 'openrouter') {
                $this->assertSame([ClassifierApi::TypesafeSystemOne, 'https://openrouter.ai/api/v1'], [$model->api, $model->baseUrl], $model->id);
            }
        }
    }

    public function testAChatAndAClassifierWithTheSameIdStayApart(): void
    {
        // The classifiers on the APIs of the reference commit, as the catalogues list them at
        // regeneration; the count is theirs, so only the split is pinned.
        $this->assertNotEmpty(Models::allOfType(ModelType::Classifier));

        $this->assertInstanceOf(ClassifierModel::class, Models::findOfType(ModelType::Classifier, 'openrouter', 'cloudflare/clef'));
        $this->assertNull(Models::findOfType(ModelType::Chat, 'openrouter', 'cloudflare/clef'));
    }

    // ---- helpers ------------------------------------------------------------------------------

    /** @param \Closure(array<string, mixed>): ?array $answer */
    private function serve(\Closure $answer): string
    {
        $this->server = new ScriptedServer();

        return $this->server->start($answer);
    }

    private function server(): ScriptedServer
    {
        $this->assertNotNull($this->server);

        return $this->server;
    }

    private static function classify(ClassifierModel $model, ClassifierContext $context, ClassifierOptions $options): ClassifierResult
    {
        return Async::run(static fn (): ClassifierResult => Models::classify($model, $context, $options));
    }

    private static function typesafe(string $baseUrl, Pricing $pricing = new Pricing()): ClassifierModel
    {
        return new ClassifierModel('jev-latest', 'Jev', ClassifierApi::TypesafeSystemOne, 'typesafe', $baseUrl, 64_000, pricing: $pricing);
    }

    /** @return array{0: ClassifierModel, 1: ClassifierContext, 2: ClassifierOptions} the built-in row at the server, account-scoped */
    private static function workersAiCall(string $base, string $id): array
    {
        $row = Models::findOfType(ModelType::Classifier, 'cloudflare-workers-ai', $id);
        self::assertInstanceOf(ClassifierModel::class, $row);
        $model = $row->withBaseUrl(str_replace('https://api.cloudflare.com', $base, $row->baseUrl));

        return [$model, new ClassifierContext(
            ['message' => 'Help! My payouts have been failing for 3 days.'],
            [
                'is_urgent' => new ClassifierBoolQuestion('Does this convey urgency?', ['true' => 'Explicitly time-sensitive', 'false' => 'No urgency expressed']),
                'department' => new ClassifierChoiceQuestion('Which team should handle this?', ['billing' => 'Payments', 'technical' => 'Bugs']),
            ],
        ), new ClassifierOptions(apiKey: 'cf-key', env: ['CLOUDFLARE_ACCOUNT_ID' => 'account-id'])];
    }

    private static function context(): ClassifierContext
    {
        return new ClassifierContext(['text' => 'The deployment succeeded, thank you.'], [
            'category' => new ClassifierChoiceQuestion('Classify the message', ['success' => 'Successful', 'failure' => 'Failed']),
            'satisfaction' => new ClassifierScoreQuestion('Score satisfaction', ['low', 'neutral', 'high']),
            'approved' => new ClassifierBoolQuestion('Does the user approve?', ['true' => 'Approval', 'false' => 'No approval']),
        ]);
    }

    /** @return array<string, mixed> */
    private static function wireAnswers(): array
    {
        return [
            'category' => ['type' => 'choice', 'choice' => 'success', 'probabilities' => ['success' => 0.9, 'failure' => 0.1], 'confidence' => 0.8],
            'satisfaction' => ['type' => 'score', 'score' => 2, 'confidence' => 0.7],
            'approved' => ['type' => 'noul', 'noul' => 0.95],
        ];
    }

    /** "Model output from https://developers.cloudflare.com/ai/models/typesafe/jev/" @return array<string, mixed> */
    private static function jevOutput(): array
    {
        return [
            'model' => 'jev-1.13.0',
            'answers' => [
                'is_urgent' => ['type' => 'noul', 'noul' => 0.95],
                'department' => ['type' => 'choice', 'choice' => 'billing', 'confidence' => 0.8, 'probabilities' => ['billing' => 0.87, 'technical' => 0.13]],
            ],
            'usage' => ['input_tokens' => 426, 'output_tokens' => 73],
        ];
    }

    /** "REST envelope observed from the live /ai/run endpoint." @return array<string, mixed> */
    private static function restResponse(string $state, mixed $result): array
    {
        return ['result' => ['state' => $state, 'result' => $result, 'gatewayMetadata' => ['keySource' => 'Unified']], 'success' => true, 'errors' => [], 'messages' => []];
    }
}
