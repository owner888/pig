<?php

declare(strict_types=1);

namespace Pig\Ai\Test;

use PHPUnit\Framework\TestCase;
use Pig\Ai\ClassifierModel;
use Pig\Ai\Http\HttpClient;
use Pig\Ai\Model;
use Pig\Ai\ModelJson;
use Pig\Ai\Models;
use Pig\Ai\ModelType;
use Pig\Ai\RemoteCatalog;
use Pig\Async\Async;
use Pig\Test\CannedServer;
use RuntimeException;

/** Upstream's `remote-catalog-provider.test.ts`, the protocol half, and the layer it fills in `Models`. */
final class RemoteCatalogTest extends TestCase
{
    #[\Override]
    protected function tearDown(): void
    {
        Models::forgetRegistered();
        putenv('PIG_MODEL_CATALOG_URL=off');
    }

    public function testTheServerIsPigagentDevUnlessTheEnvironmentNamesAnotherOrTurnsItOff(): void
    {
        putenv('PIG_MODEL_CATALOG_URL');
        $this->assertSame('https://pigagent.dev', RemoteCatalog::baseUrl());
        putenv('PIG_MODEL_CATALOG_URL=http://localhost:8787');
        $this->assertSame('http://localhost:8787', RemoteCatalog::baseUrl());
        putenv('PIG_MODEL_CATALOG_URL=off');
        $this->assertNull(RemoteCatalog::baseUrl());
        putenv('PIG_MODEL_CATALOG_URL=');
        $this->assertNull(RemoteCatalog::baseUrl());
    }

    public function testACatalogIsAListAModelsObjectOrAnObjectOfModelsStampedWithTheProvider(): void
    {
        $model = ['id' => 'm', 'provider' => 'elsewhere'];

        foreach ([[$model], ['models' => [$model]], ['m' => $model]] as $shape) {
            $this->assertSame([['id' => 'm', 'provider' => 'groq']], RemoteCatalog::parse('groq', $shape));
        }

        // No id, or a type this does not read: left out.
        $this->assertSame([], RemoteCatalog::parse('groq', [['name' => 'x'], ['id' => 'v', 'type' => 'video']]));
        $this->expectException(RuntimeException::class);
        RemoteCatalog::parse('groq', 'not a catalog');
    }

    public function testOnlyACatalogGeneratedAfterTheShippedDataCounts(): void
    {
        $this->assertTrue(RemoteCatalog::isNewer(2, 1));
        $this->assertFalse(RemoteCatalog::isNewer(1, 1));
        $this->assertFalse(RemoteCatalog::isNewer(null, 1));
        $this->assertTrue(RemoteCatalog::isNewer(null, null));
    }

    public function testAnEntryPigCannotReadCostsOnlyItself(): void
    {
        $first = Models::all()[0];
        $models = RemoteCatalog::models('groq', [ModelJson::encode($first), ['id' => 'x', 'api' => 'no-such-api', 'baseUrl' => 'https://x']]);

        $this->assertCount(1, $models);
        $this->assertSame('groq', $models[0]->provider);
    }

    public function testTheRequestNamesTheTypesAndRevalidatesWithTheEtag(): void
    {
        $server = new CannedServer();
        $body = '[{"id":"m"}]';
        $base = $server->start(["HTTP/1.1 200 OK\r\nLast-Modified: Sat, 01 Jan 2050 00:00:00 GMT\r\nETag: \"e1\"\r\nContent-Length: " . strlen($body) . "\r\n\r\n{$body}"]);

        [$response, $text] = Async::run(static fn (): array => RemoteCatalog::request('cloudflare-ai-gateway', $base, '"e0"', new HttpClient(5.0)));

        $head = $server->receivedHead();
        $this->assertStringContainsString('GET /api/models/providers/cloudflare-ai-gateway?types=chat,image,classifier HTTP/1.1', $head);
        $this->assertStringContainsStringIgnoringCase('if-none-match: "e0"', $head);
        $this->assertStringContainsStringIgnoringCase('accept: application/json', $head);
        $this->assertSame(
            ['models' => [['id' => 'm', 'provider' => 'cloudflare-ai-gateway']], 'lastModified' => 2524608000000, 'etag' => '"e1"'],
            RemoteCatalog::read('cloudflare-ai-gateway', $response, $text),
        );
    }

    public function testAMergedCatalogReplacesAModelWhereItStandsAndAddsANewOneAfterTheProvidersLast(): void
    {
        $groq = array_values(array_filter(Models::all(), static fn (Model $m): bool => $m->provider === 'groq'));
        $keys = array_map(static fn (Model $m): string => "{$m->provider}/{$m->id}", Models::all());
        $renamed = new Model(...[...get_object_vars($groq[1]), 'name' => 'Renamed']);
        $new = new Model(...[...get_object_vars($groq[0]), 'id' => 'zz-new']);

        Models::useProviderCatalog('groq', [$new, $renamed]);

        $all = Models::all();
        $after = array_map(static fn (Model $m): string => "{$m->provider}/{$m->id}", $all);
        $last = array_search('groq/' . $groq[count($groq) - 1]->id, $keys, true);
        $this->assertSame([...array_slice($keys, 0, $last + 1), 'groq/zz-new', ...array_slice($keys, $last + 1)], $after);
        $this->assertSame('Renamed', Models::find('groq', $groq[1]->id)?->name);
    }

    public function testAReplacingCatalogIsTheProvidersEveryTypeAndNullTakesItAway(): void
    {
        $chat = array_values(array_filter(Models::all(), static fn (Model $m): bool => $m->provider === 'openrouter'));
        $classifiers = array_values(array_filter(Models::allOfType(ModelType::Classifier), static fn (ClassifierModel $m): bool => $m->provider === 'openrouter'));
        $this->assertNotEmpty($classifiers);

        Models::useProviderCatalog('openrouter', [$chat[2], $classifiers[0]], replace: true);

        $this->assertSame([$chat[2]->id], array_map(static fn (Model $m): string => $m->id, array_values(array_filter(Models::all(), static fn (Model $m): bool => $m->provider === 'openrouter'))));
        $this->assertSame([$classifiers[0]->id], array_map(static fn (ClassifierModel $m): string => $m->id, array_values(array_filter(Models::allOfType(ModelType::Classifier), static fn (ClassifierModel $m): bool => $m->provider === 'openrouter'))));
        $this->assertSame([], array_values(array_filter(Models::allOfType(ModelType::Image), static fn ($m): bool => $m->provider === 'openrouter')));

        Models::useProviderCatalog('openrouter', null);
        $this->assertCount(count($chat), array_filter(Models::all(), static fn (Model $m): bool => $m->provider === 'openrouter'));
    }

    public function testTheShippedDataSaysWhenItWasGenerated(): void
    {
        $this->assertIsInt(Models::builtinGeneratedAt());
        $this->assertContains('anthropic', Models::builtinProviders());
        $this->assertContains('typesafe', Models::builtinProviders());
        $this->assertSame(['anthropic'], array_values(array_unique(array_map(static fn ($m): string => $m->provider, Models::builtinModelsOf('anthropic')))));
    }
}
