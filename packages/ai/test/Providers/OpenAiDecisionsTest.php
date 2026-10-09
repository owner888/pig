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
use Pig\Ai\ImageContent;
use Pig\Ai\Models;
use Pig\Ai\ModelType;
use Pig\Ai\Pricing;
use Pig\Ai\PricingTier;
use Pig\Ai\StopReason;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\Test\ScriptedServer;

/**
 * OpenAI's Decisions API — upstream's `openai-decisions.test.ts`, case for case, with a
 * `ScriptedServer` where upstream hands the API a `fetch`. The one case left out is the
 * `__proto__` question id: a PHP array key cannot be prototype-sensitive.
 */
final class OpenAiDecisionsTest extends TestCase
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

    public function testQuestionsMapToDecisionsTypesAndAnswersComeBackByName(): void
    {
        // Answers out of question order: they are matched by name.
        $base = $this->serve(static fn (): array => [200, [], json_encode(['model' => 'gpt-6-luna', 'answers' => array_reverse(self::wireAnswers()), 'usage' => self::wireUsage()])]);

        $result = self::classify(self::luna($base), self::context(), new ClassifierOptions(apiKey: 'secret', temperature: 1.5));

        $this->assertCount(1, $this->server()->requests);
        $request = $this->server()->requests[0];
        $this->assertSame('/v1/decisions', $request['path']);
        $this->assertSame('Bearer secret', $request['headers']['authorization'] ?? null);
        $this->assertSame([
            'model' => 'gpt-6-luna',
            'input' => '{"text":"The deployment succeeded, thank you."}',
            'questions' => [
                // Empty descriptions are omitted.
                ['type' => 'choice', 'name' => 'category', 'instructions' => 'Classify the message', 'choices' => [['value' => 'success', 'description' => 'Successful'], ['value' => 'failure']]],
                ['type' => 'score', 'name' => 'satisfaction', 'instructions' => 'Score satisfaction', 'levels' => [['label' => 'low'], ['label' => 'neutral'], ['label' => 'high']]],
                ['type' => 'predicate', 'name' => 'approved', 'instructions' => "Does the user approve?\n\nTrue means: Approval\nFalse means: No approval"],
            ],
        ], json_decode($request['body'], true));
        $this->assertSame(StopReason::Stop, $result->stopReason, (string) $result->errorMessage);
        $this->assertEquals([
            'category' => new ClassifierChoiceAnswer('success', ['success' => 0.9, 'failure' => 0.1], 0.8),
            'satisfaction' => new ClassifierScoreAnswer(1.8, 0.7),
            'approved' => new ClassifierBoolAnswer(0.95),
        ], $result->answers);
        $this->assertSame([164, 0, 0, 164], [$result->usage?->input, $result->usage?->output, $result->usage?->cacheRead, $result->usage?->totalTokens]);
        $this->assertEqualsWithDelta(0.0000164, $result->usage?->cost->total, 1e-12);
    }

    public function testLongContextRequestsArePricedAtTheLongContextInputRate(): void
    {
        $base = $this->serve(static fn (): array => [200, [], json_encode(['answers' => self::wireAnswers(), 'usage' => ['input_tokens' => 300_000, 'output_tokens' => 0]])]);

        $result = self::classify(self::luna($base), self::context(), new ClassifierOptions(apiKey: 'secret'));

        $this->assertEqualsWithDelta(0.06, $result->usage?->cost->total, 1e-12);
    }

    public function testImagesGoAfterTheStateInOneUserMessage(): void
    {
        $base = $this->serve(static fn (): array => [200, [], json_encode(['answers' => self::wireAnswers()])]);
        $context = new ClassifierContext(self::context()->state, self::context()->questions, [self::image(), new ImageContent('aW1hZ2U=', 'image/jpeg')]);

        $result = self::classify(self::luna($base), $context, new ClassifierOptions(apiKey: 'secret'));

        $this->assertSame(StopReason::Stop, $result->stopReason, (string) $result->errorMessage);
        $this->assertSame([[
            'role' => 'user',
            'content' => [
                ['type' => 'input_text', 'text' => '{"text":"The deployment succeeded, thank you."}'],
                ['type' => 'input_image', 'image_url' => 'data:image/png;base64,aW1hZ2U='],
                ['type' => 'input_image', 'image_url' => 'data:image/jpeg;base64,aW1hZ2U='],
            ],
        ]], $this->server()->bodies()[0]['input']);
    }

    public function testMoreThan128ImagesAreRefusedBeforeSending(): void
    {
        $base = $this->serve(static fn (): array => [200, [], json_encode(['answers' => self::wireAnswers()])]);
        $context = new ClassifierContext(self::context()->state, self::context()->questions, array_fill(0, 129, self::image()));

        $result = self::classify(self::luna($base), $context, new ClassifierOptions(apiKey: 'secret'));

        $this->assertSame([], $this->server()->requests);
        $this->assertSame(StopReason::Error, $result->stopReason);
        $this->assertStringContainsString('at most 128 images, got 129', (string) $result->errorMessage);
    }

    public function testARefusedQuestionFailsTheResultAndKeepsTheBilledUsage(): void
    {
        $answers = self::wireAnswers();
        $base = $this->serve(static fn (): array => [200, [], json_encode(['answers' => [$answers[0], $answers[1], ['type' => 'refusal', 'name' => 'approved']], 'usage' => self::wireUsage()])]);

        $result = self::classify(self::luna($base), self::context(), new ClassifierOptions(apiKey: 'secret'));

        $this->assertSame(StopReason::Error, $result->stopReason);
        $this->assertSame([], $result->answers);
        $this->assertSame('OpenAI Decisions refused to answer approved', $result->errorMessage);
        $this->assertSame(164, $result->usage?->input);
    }

    public function testMissingAndMistypedAnswersAreClassifierErrors(): void
    {
        $answers = self::wireAnswers();
        $base = $this->serve(static fn (): array => [200, [], json_encode(['answers' => array_slice($answers, 0, 2)])]);
        $missing = self::classify(self::luna($base), self::context(), new ClassifierOptions(apiKey: 'secret'));
        $this->server()->stop();

        $base = $this->serve(static fn (): array => [200, [], json_encode(['answers' => [$answers[0], $answers[1], ['type' => 'score', 'name' => 'approved']]])]);
        $mistyped = self::classify(self::luna($base), self::context(), new ClassifierOptions(apiKey: 'secret'));

        $this->assertSame(StopReason::Error, $missing->stopReason);
        $this->assertStringContainsString('did not return an answer for approved', (string) $missing->errorMessage);
        $this->assertSame(StopReason::Error, $mistyped->stopReason);
        $this->assertStringContainsString('did not return a predicate answer for approved', (string) $mistyped->errorMessage);
    }

    public function testAGatewayTimeoutIsNotRetriedAndIsExplainedRatherThanShownAsHtml(): void
    {
        $base = $this->serve(static fn (): array => [504, ['retry-after-ms' => '0', 'content-type' => 'text/html'], '<!DOCTYPE html><html>Gateway time-out</html>']);

        // Default retries: the same input would time out again.
        $result = self::classify(self::luna($base), self::context(), new ClassifierOptions(apiKey: 'secret'));

        $this->assertCount(1, $this->server()->requests);
        $this->assertSame(StopReason::Error, $result->stopReason);
        $this->assertStringContainsString('OpenAI Decisions error (504): the request timed out at the gateway', (string) $result->errorMessage);
        $this->assertStringNotContainsString('<html>', (string) $result->errorMessage);
    }

    public function testOtherServerErrorsAreStillRetried(): void
    {
        $attempt = 0;
        $base = $this->serve(static function () use (&$attempt): array {
            return ++$attempt === 1 ? [503, ['retry-after-ms' => '0', 'content-type' => 'text/plain'], 'busy'] : [200, [], json_encode(['answers' => self::wireAnswers()])];
        });

        $result = self::classify(self::luna($base), self::context(), new ClassifierOptions(apiKey: 'secret'));

        $this->assertSame(2, $attempt);
        $this->assertSame(StopReason::Stop, $result->stopReason, (string) $result->errorMessage);
    }

    public function testOtherHttpFailuresCarryTheApiErrorBody(): void
    {
        $base = $this->serve(static fn (): array => [400, ['content-type' => 'application/json'], json_encode(['error' => ['message' => 'Decision input exceeds the token limit.', 'type' => 'invalid_request_error']])]);

        $result = self::classify(self::luna($base), self::context(), new ClassifierOptions(apiKey: 'secret', maxRetries: 0));

        $this->assertSame(StopReason::Error, $result->stopReason);
        $this->assertStringContainsString('OpenAI Decisions error (400)', (string) $result->errorMessage);
        $this->assertStringContainsString('Decision input exceeds the token limit.', (string) $result->errorMessage);
    }

    public function testAModelForAnotherApiAndAMissingKeyAreRefusedBeforeAnyRequest(): void
    {
        $base = $this->serve(static fn (): array => [200, [], json_encode(['answers' => self::wireAnswers()])]);
        $provider = new \Pig\Ai\Providers\OpenAiDecisions();
        $other = new ClassifierModel('gpt-6-luna', 'GPT-6 Luna', ClassifierApi::TypesafeSystemOne, 'openai', $base . '/v1', 922_000);

        $otherApi = Async::run(static fn (): ClassifierResult => $provider->classify($other, self::context(), new ClassifierOptions(apiKey: 'secret')));
        $noKey = Async::run(static fn (): ClassifierResult => $provider->classify(self::luna($base), self::context(), new ClassifierOptions()));

        $this->assertSame([], $this->server()->requests);
        $this->assertStringContainsString('Unsupported classifier API: typesafe-system-one', (string) $otherApi->errorMessage);
        $this->assertStringContainsString('No API key for provider: openai', (string) $noKey->errorMessage);
    }

    public function testTheBuiltInRowIsUpstreamsAndTakesImages(): void
    {
        $row = Models::findOfType(ModelType::Classifier, 'openai', 'gpt-6-luna');

        $this->assertInstanceOf(ClassifierModel::class, $row);
        $this->assertSame(ClassifierApi::OpenAiDecisions, $row->api);
        $this->assertSame(['text', 'image'], $row->input);
        $this->assertSame(922_000, $row->contextWindow);
        $this->assertSame(0.1, $row->pricing->input);
        $this->assertEquals([new PricingTier(272_000, 0.2)], $row->pricing->tiers, 'the chat requests\' long-context multiplier, on input only');
    }

    public function testImagesForAModelThatCannotSeeAreRefusedBeforeItsProviderIs(): void
    {
        // Upstream's `assertClassifierInputSupported()`, in `Models::classify()`; and the two
        // APIs that cannot carry an image say so themselves when handed one directly.
        $base = $this->serve(static fn (): array => [200, [], json_encode(['answers' => []])]);
        $textOnly = new ClassifierModel('jev-latest', 'Jev', ClassifierApi::TypesafeSystemOne, 'typesafe', $base . '/v1', 64_000);
        $withImage = new ClassifierContext([], self::context()->questions, [self::image()]);

        $refused = self::classify($textOnly, $withImage, new ClassifierOptions(apiKey: 'secret'));
        $this->assertSame(StopReason::Error, $refused->stopReason);
        $this->assertSame('Model typesafe/jev-latest does not accept image input', $refused->errorMessage);

        $claims = new ClassifierModel('jev-latest', 'Jev', ClassifierApi::TypesafeSystemOne, 'typesafe', $base . '/v1', 64_000, ['text', 'image']);
        $cannot = self::classify($claims, $withImage, new ClassifierOptions(apiKey: 'secret'));
        $this->assertSame(StopReason::Error, $cannot->stopReason);
        $this->assertStringContainsString('System One API does not support image input', (string) $cannot->errorMessage);
        $this->assertSame([], $this->server()->requests);
    }

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

    /** Upstream's test model: the catalogue row, at the server. */
    private static function luna(string $base): ClassifierModel
    {
        return new ClassifierModel('gpt-6-luna', 'GPT-6 Luna', ClassifierApi::OpenAiDecisions, 'openai', $base . '/v1', 922_000, ['text', 'image'], new Pricing(0.1, 0.0, 0.0, 0.0, [new PricingTier(272_000, 0.2)]));
    }

    private static function image(): ImageContent
    {
        return new ImageContent('aW1hZ2U=', 'image/png');
    }

    private static function context(): ClassifierContext
    {
        return new ClassifierContext(['text' => 'The deployment succeeded, thank you.'], [
            'category' => new ClassifierChoiceQuestion('Classify the message', ['success' => 'Successful', 'failure' => '']),
            'satisfaction' => new ClassifierScoreQuestion('Score satisfaction', ['low', 'neutral', 'high']),
            'approved' => new ClassifierBoolQuestion('Does the user approve?', ['true' => 'Approval', 'false' => 'No approval']),
        ]);
    }

    /** "Response shape from the API reference and live `gpt-6-luna` requests." @return list<array<string, mixed>> */
    private static function wireAnswers(): array
    {
        return [
            ['type' => 'choice', 'name' => 'category', 'choice' => 'success', 'probabilities' => [['value' => 'success', 'probability' => 0.9], ['value' => 'failure', 'probability' => 0.1]], 'confidence' => 0.8],
            ['type' => 'score', 'name' => 'satisfaction', 'score' => 1.8, 'probabilities' => [['value' => 0, 'label' => 'low', 'probability' => 0.05], ['value' => 1, 'label' => 'neutral', 'probability' => 0.1], ['value' => 2, 'label' => 'high', 'probability' => 0.85]], 'confidence' => 0.7],
            ['type' => 'predicate', 'name' => 'approved', 'probability' => 0.95],
        ];
    }

    /** @return array<string, mixed> */
    private static function wireUsage(): array
    {
        return ['input_tokens' => 164, 'input_tokens_details' => ['cached_tokens' => 0, 'cache_write_tokens' => 0], 'output_tokens' => 0, 'output_tokens_details' => ['reasoning_tokens' => 0], 'total_tokens' => 164];
    }
}
