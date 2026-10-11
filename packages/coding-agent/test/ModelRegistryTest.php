<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;
use Pig\Ai\Api;
use Pig\Ai\AssistantMessage;
use Pig\Ai\ClassifierContext;
use Pig\Ai\Cost;
use Pig\Ai\Model;
use Pig\Ai\Models;
use Pig\Ai\ModelType;
use Pig\Ai\StopReason;
use Pig\Ai\TextContent;
use Pig\Ai\ToolResultMessage;
use Pig\Ai\Usage;
use Pig\CodingAgent\Auth;
use Pig\CodingAgent\Hooks\HookContext;
use Pig\CodingAgent\ModelRegistry;
use Pig\CodingAgent\Session\UsageEntry;
use Pig\CodingAgent\UsageCostBreakdown;

/**
 * `ctx.modelRegistry` — upstream's `ModelRegistry` facade over pig's static registry — and the
 * per-model cost breakdown `/session` shows (upstream's `getUsageCostBreakdown()`).
 */
final class ModelRegistryTest extends TestCase
{
    #[\Override]
    protected function setUp(): void
    {
        Models::forgetRegistered();
        Models::register([new Model('m', 'M', Api::OpenAiCompletions, 'zzreg', 'http://127.0.0.1:1', 1_000, 100)]);
        putenv('ZZREG_KEY');
    }

    #[\Override]
    protected function tearDown(): void
    {
        Models::forgetRegistered();
    }

    public function testAnExtensionContextCarriesAModelRegistry(): void
    {
        $ctx = new HookContext('.');

        $this->assertInstanceOf(ModelRegistry::class, $ctx->modelRegistry);
        $this->assertSame('m', $ctx->modelRegistry->find('zzreg', 'm')?->id);
        $this->assertNull($ctx->modelRegistry->find('zzreg', 'nope'));
    }

    public function testFindOfTypeAndTheTypedListsTakeUpstreamsTypeNames(): void
    {
        $registry = new ModelRegistry(Auth::inMemory());

        $this->assertSame('jev-latest', $registry->findOfType('classifier', 'typesafe', 'jev-latest')?->id);
        $this->assertSame('jev-latest', $registry->getModelOfType(ModelType::Classifier, 'typesafe', 'jev-latest')?->id);
        $this->assertSame(['m'], array_map(static fn ($m): string => $m->id, $registry->getModelsOfType('chat', 'zzreg')));
        $this->assertSame([], $registry->getAvailableOfType('chat', 'zzreg'), 'no key for it');
    }

    public function testAvailabilityAndProviderAuthAreTheRunsCredentials(): void
    {
        $auth = Auth::inMemory();
        $auth->setRuntimeApiKey('zzreg', 'sk-runtime');
        $registry = new ModelRegistry($auth);

        $this->assertTrue($registry->hasConfiguredAuth(Models::find('zzreg', 'm') ?? throw new \LogicException()));
        $this->assertContains('m', array_map(static fn (Model $m): string => $m->id, $registry->getAvailable()));
        $this->assertSame('sk-runtime', $registry->getProviderAuth('zzreg')?->auth->apiKey);
        $this->assertNull($registry->getProviderAuth('nobody'));
        $this->assertSame('zzreg', $registry->getProviderDisplayName('zzreg'));
    }

    /** "Classify structured state with request-time authentication. Never rejects." */
    public function testClassifyWithoutCredentialsIsAnErrorResultNotAThrow(): void
    {
        $model = Models::findOfType(ModelType::Classifier, 'typesafe', 'jev-latest');
        $this->assertNotNull($model);

        $result = (new ModelRegistry(Auth::inMemory()))->classify($model, new ClassifierContext(['message' => 'x'], []));

        $this->assertNotNull($result->errorMessage);
        $this->assertStringContainsString('not configured', (string) $result->errorMessage);
    }

    public function testTheCostBreakdownIsPerPhysicalModelWithToolsAndSummariesApart(): void
    {
        $usage = static fn (float $cost, int $input): Usage => new Usage(input: $input, cost: new Cost(total: $cost));
        $answer = static fn (string $model, float $cost, ?string $responseModel = null): AssistantMessage => new AssistantMessage(
            [new TextContent('x')], Api::AnthropicMessages, 'lab', $model, $usage($cost, 10), StopReason::Stop, responseModel: $responseModel,
        );

        $breakdown = UsageCostBreakdown::of([
            $answer('fast', 0.01),
            $answer('smart', 0.5),
            $answer('auto', 0.03, responseModel: 'routed-elsewhere'),
            $answer('fast', 0.01),
            new ToolResultMessage('call', 'note', [new TextContent('noted')], usage: $usage(0.001, 5)),
            new UsageEntry('cache-warm', 'lab', 'smart', $usage(0.1, 100)),
            $answer('free', 0.0),
        ]);

        $this->assertSame(
            [
                ['key' => 'lab/smart', 'cost' => 0.6, 'tokens' => 110],
                ['key' => 'lab/routed-elsewhere', 'cost' => 0.03, 'tokens' => 10],
                ['key' => 'lab/fast', 'cost' => 0.02, 'tokens' => 20],
                ['key' => 'Tools/summaries', 'cost' => 0.001, 'tokens' => 5],
                ['key' => 'lab/free', 'cost' => 0.0, 'tokens' => 10],
            ],
            array_map(static fn (array $e): array => [...$e, 'cost' => round($e['cost'], 6)], $breakdown),
        );
    }
}
