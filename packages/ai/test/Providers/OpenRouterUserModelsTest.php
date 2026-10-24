<?php

declare(strict_types=1);

namespace Pig\Ai\Test\Providers;

use PHPUnit\Framework\TestCase;
use Pig\Ai\Api;
use Pig\Ai\Http\HttpClient;
use Pig\Ai\Model;
use Pig\Ai\Models;
use Pig\Ai\Pricing;
use Pig\Ai\PricingTier;
use Pig\Ai\Providers\OpenRouterUserModels;
use Pig\Async\Async;
use Pig\Test\CannedServer;

/** Upstream's `openrouter-user-models.test.ts`: the OpenRouter models one key may use. */
final class OpenRouterUserModelsTest extends TestCase
{
    #[\Override]
    protected function tearDown(): void
    {
        Models::forgetRegistered();
    }

    public function testPricesArePerMillionTokensAndARouterModelsNegativePriceIsZero(): void
    {
        $this->assertEquals(new Pricing(3.0, 15.0, 0.3, 3.75), OpenRouterUserModels::cost([
            'prompt' => '0.000003', 'completion' => '0.000015', 'input_cache_read' => '0.0000003', 'input_cache_write' => '0.00000375',
        ]));
        // "OpenRouter reports -1 for router models such as `openrouter/auto`."
        $this->assertEquals(new Pricing(), OpenRouterUserModels::cost(['prompt' => '-1', 'completion' => '-1']));
    }

    public function testPromptLengthOverridesAreTiersAndTimeOfDayOnesAreSkipped(): void
    {
        $cost = OpenRouterUserModels::cost([
            'prompt' => '0.000001',
            'completion' => '0.000002',
            'overrides' => [
                ['min_prompt_tokens' => 200000, 'prompt' => '0.000002'],
                ['min_prompt_tokens' => 100000, 'utc_start' => 0, 'utc_end' => 8, 'prompt' => '0.0000005'],
            ],
        ]);

        $this->assertEquals([new PricingTier(200000, 2.0, 2.0, 0.0, 0.0)], $cost->tiers, 'a rate the tier does not list keeps the base price');
    }

    public function testOnlyTheKeysModelsAreKeptWithItsLimitsAndPrices(): void
    {
        $catalog = [self::model('a/one', 100_000, 8_000), self::model('b/two', 50_000, 4_000)];

        $personal = OpenRouterUserModels::personalize($catalog, [
            ['id' => 'a/one', 'context_length' => 64_000, 'top_provider' => ['context_length' => 32_000, 'max_completion_tokens' => 0], 'pricing' => ['prompt' => '0.000001', 'completion' => '0.000002']],
            ['id' => 'c/three'],
        ]);

        $this->assertCount(1, $personal);
        $this->assertSame(['a/one', 32_000, 8_000], [$personal[0]->id, $personal[0]->contextWindow, $personal[0]->maxTokens], 'a missing (0) limit keeps the catalog value');
        $this->assertEquals(new Pricing(1.0, 2.0), $personal[0]->pricing);
    }

    public function testTheListIsAskedOfTheConfiguredBaseUrlWithTheKey(): void
    {
        $server = new CannedServer();
        $body = (string) json_encode(['data' => [['id' => 'a/one', 'name' => 'One', 'description' => 'long text', 'context_length' => 1000, 'pricing' => ['prompt' => '0']]]]);
        $base = rtrim($server->start(["HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: " . strlen($body) . "\r\n\r\n{$body}"]), '/') . '/api/v1';

        $models = Async::run(static fn (): ?array => OpenRouterUserModels::fetch('sk-or', $base, new HttpClient(5.0)));

        $this->assertSame([['id' => 'a/one', 'context_length' => 1000, 'pricing' => ['prompt' => '0']]], $models, 'trimmed to what is read');
        $this->assertStringContainsString('GET /api/v1/models/user ', $server->receivedHead());
        $this->assertStringContainsStringIgnoringCase('authorization: Bearer sk-or', $server->receivedHead());
    }

    public function testARejectedKeyOrAMissingEndpointLeavesTheCatalogAndOtherFailuresSaySo(): void
    {
        foreach ([401 => 'Unauthorized', 403 => 'Forbidden', 404 => 'Not Found'] as $status => $reason) {
            $server = new CannedServer();
            $base = rtrim($server->start(["HTTP/1.1 {$status} {$reason}\r\nContent-Length: 0\r\n\r\n"]), '/');
            $this->assertNull(Async::run(static fn (): ?array => OpenRouterUserModels::fetch('k', $base, new HttpClient(5.0))), (string) $status);
        }

        $server = new CannedServer();
        $base = rtrim($server->start(["HTTP/1.1 500 Internal Server Error\r\nContent-Length: 0\r\n\r\n"]), '/');
        $this->expectExceptionMessage('OpenRouter model list request failed: 500');
        Async::run(static fn (): ?array => OpenRouterUserModels::fetch('k', $base, new HttpClient(5.0)));
    }

    public function testPersonalizingNarrowsTheBuiltInOpenRouterRowsOnly(): void
    {
        $before = count(array_filter(Models::all(), static fn (Model $m): bool => $m->provider === 'openrouter'));
        $this->assertGreaterThan(1, $before);
        $kept = array_values(array_filter(Models::all(), static fn (Model $m): bool => $m->provider === 'openrouter'))[0];
        $anthropic = count(array_filter(Models::all(), static fn (Model $m): bool => $m->provider === 'anthropic'));

        Models::personalize('openrouter', static fn (array $catalog): array => OpenRouterUserModels::personalize($catalog, [['id' => $kept->id, 'context_length' => 12_345]]));

        $now = array_values(array_filter(Models::all(), static fn (Model $m): bool => $m->provider === 'openrouter'));
        $this->assertSame([$kept->id], array_map(static fn (Model $m): string => $m->id, $now));
        $this->assertSame(12_345, $now[0]->contextWindow);
        $this->assertSame($anthropic, count(array_filter(Models::all(), static fn (Model $m): bool => $m->provider === 'anthropic')));

        Models::personalize('openrouter', null);
        $this->assertSame($before, count(array_filter(Models::all(), static fn (Model $m): bool => $m->provider === 'openrouter')));
    }

    private static function model(string $id, int $window, int $maxTokens): Model
    {
        return new Model($id, $id, Api::OpenAiCompletions, 'openrouter', OpenRouterUserModels::BASE_URL, $window, $maxTokens, pricing: new Pricing(9.0, 9.0));
    }
}
