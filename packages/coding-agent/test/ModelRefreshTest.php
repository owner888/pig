<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;
use Pig\Ai\Extension\ApiKeyCredential;
use Pig\Ai\Extension\Provider;
use Pig\Ai\Extension\ProviderRegistry;
use Pig\Ai\Extension\RefreshModelsContext;
use Pig\Async\AbortController;
use Pig\CodingAgent\Auth;
use Pig\CodingAgent\ModelRefresh;
use Pig\Ai\ModelJson;
use Pig\Ai\Models;
use Pig\Ai\ModelType;
use Pig\Async\Async;
use Pig\Test\CannedServer;
use RuntimeException;

/** Upstream's `Models.refresh()`: two phases, the store, a failure per provider, a cancelled caller. */
final class ModelRefreshTest extends TestCase
{
    private string $home;

    /** A developer's own key would send the OpenRouter refresh to the network. */
    private string|false $openRouterKey = false;

    #[\Override]
    protected function setUp(): void
    {
        $this->home = sys_get_temp_dir() . '/pig-model-refresh-' . bin2hex(random_bytes(4));
        mkdir($this->home, 0o700, true);
        putenv("PIG_CODING_AGENT_DIR={$this->home}");
        $this->openRouterKey = getenv('OPENROUTER_API_KEY');
        putenv('OPENROUTER_API_KEY');
    }

    #[\Override]
    protected function tearDown(): void
    {
        ProviderRegistry::forget();
        Models::forgetRegistered();
        putenv('PIG_CODING_AGENT_DIR');

        if ($this->openRouterKey !== false) {
            putenv("OPENROUTER_API_KEY={$this->openRouterKey}");
        }

        exec('rm -rf ' . escapeshellarg($this->home));
    }

    public function testTheStoredCatalogFirstThenTheNetworkWithTheKeyAndWhatIsPublishedIsStored(): void
    {
        $seen = [];
        ProviderRegistry::register(new Provider('zzr-a', 'A', [], refreshModels: static function (RefreshModelsContext $context) use (&$seen): void {
            $seen[] = [$context->allowNetwork, $context->credential?->key, $context->stored['models'][0]['id'] ?? null];

            if ($context->allowNetwork) {
                ($context->publish)(['models' => [['id' => 'fresh']], 'checkedAt' => 7], null);
            }
        }));
        file_put_contents("{$this->home}/models-store.json", json_encode(['zzr-a' => ['models' => [['id' => 'cached']]], 'other' => ['models' => []]]));
        $auth = Auth::discover("{$this->home}/auth.json");
        $auth->setApiKey('zzr-a', 'sk-a');

        $result = ModelRefresh::refresh($auth);

        $this->assertFalse($result->aborted);
        $this->assertSame([], $result->errors);
        $this->assertSame([[false, 'sk-a', 'cached'], [true, 'sk-a', 'cached']], $seen);
        $store = json_decode((string) file_get_contents("{$this->home}/models-store.json"), true);
        $this->assertSame(['models' => [['id' => 'fresh']], 'checkedAt' => 7], $store['zzr-a']);
        $this->assertArrayHasKey('other', $store, "another provider's entry is kept");
    }

    public function testWithoutACredentialOnlyTheStoredPhaseRunsAndAFailureIsThatProvidersAlone(): void
    {
        $phases = [];
        ProviderRegistry::register(new Provider('zzr-none', 'None', [], refreshModels: static function (RefreshModelsContext $context) use (&$phases): void {
            $phases[] = $context->allowNetwork;
        }));
        ProviderRegistry::register(new Provider('zzr-bad', 'Bad', [], refreshModels: static function (RefreshModelsContext $context): void {
            if ($context->allowNetwork) {
                throw new RuntimeException('down');
            }
        }));
        $auth = Auth::inMemory();
        $auth->setApiKey('zzr-bad', 'k');

        $result = ModelRefresh::refresh($auth);

        $this->assertSame([false], $phases);
        $this->assertSame(['zzr-bad'], array_keys($result->errors));
        $this->assertSame('down', $result->errors['zzr-bad']->getMessage());
        $this->assertSame([false, false], [ModelRefresh::refresh($auth, ['zzr-none'], allowNetwork: false)->aborted, $phases[1] ?? true]);
    }

    public function testACancelledCallerPublishesNothing(): void
    {
        $published = null;
        $controller = new AbortController();
        ProviderRegistry::register(new Provider('zzr-c', 'C', [], refreshModels: static function (RefreshModelsContext $context) use ($controller, &$published): void {
            $controller->abort();
            $published = ($context->publish)(['models' => [], 'checkedAt' => 1], static fn () => null);
        }));

        $result = ModelRefresh::refresh(Auth::inMemory(), signal: $controller->signal);

        $this->assertTrue($result->aborted);
        $this->assertFalse($published);
        $this->assertFileDoesNotExist("{$this->home}/models-store.json");
        $this->assertNull((new ApiKeyCredential())->key);
    }

    public function testTheSavedOpenRouterListReplacesTheCatalogWithoutTheNetwork(): void
    {
        // Upstream #10672: "The combined list is saved and replaces the OpenRouter catalog, including offline."
        $kept = array_values(array_filter(Models::all(), static fn ($m): bool => $m->provider === 'openrouter'))[0];
        $saved = [...ModelJson::encode($kept), 'contextWindow' => 4321];
        file_put_contents("{$this->home}/models-store.json", json_encode(['openrouter' => ['models' => [$saved], 'checkedAt' => 1]]));

        $result = ModelRefresh::refresh(Auth::discover("{$this->home}/auth.json"), ['openrouter'], allowNetwork: false);

        $this->assertSame([], $result->errors);
        $openrouter = array_values(array_filter(Models::all(), static fn ($m): bool => $m->provider === 'openrouter'));
        $this->assertSame([[$kept->id, 4321]], array_map(static fn ($m): array => [$m->id, $m->contextWindow], $openrouter));
        // Its image models are the saved list's too, which has none.
        $this->assertSame([], array_values(array_filter(Models::allOfType(ModelType::Image), static fn ($m): bool => $m->provider === 'openrouter')));
    }

    public function testTheSavedRadiusCatalogReplacesTheShippedOneWithoutTheNetwork(): void
    {
        // Upstream 43d376399: "Radius org owners can disable models, so once known it replaces the
        // shipped baseline instead of overlaying it."
        $shipped = array_values(array_filter(Models::all(), static fn ($m): bool => $m->provider === Models::RADIUS));
        $this->assertGreaterThan(1, count($shipped));
        $saved = [...ModelJson::encode($shipped[0]), 'contextWindow' => 1234];
        file_put_contents("{$this->home}/models-store.json", json_encode([Models::RADIUS => ['models' => [$saved], 'checkedAt' => 1]]));

        $result = ModelRefresh::refresh(Auth::discover("{$this->home}/auth.json"), [Models::RADIUS], allowNetwork: false);

        $this->assertSame([], $result->errors);
        $radius = array_values(array_filter(Models::all(), static fn ($m): bool => $m->provider === Models::RADIUS));
        $this->assertSame([[$shipped[0]->id, 1234]], array_map(static fn ($m): array => [$m->id, $m->contextWindow], $radius));
    }

    public function testAnOpenRouterListSavedBeforeItHeldModelsLeavesTheCatalogAsBuilt(): void
    {
        $before = count(array_filter(Models::all(), static fn ($m): bool => $m->provider === 'openrouter'));
        file_put_contents("{$this->home}/models-store.json", json_encode(['openrouter' => ['models' => [['id' => 'x/y', 'context_length' => 1]], 'checkedAt' => 1]]));

        ModelRefresh::refresh(Auth::discover("{$this->home}/auth.json"), ['openrouter'], allowNetwork: false);

        $this->assertSame($before, count(array_filter(Models::all(), static fn ($m): bool => $m->provider === 'openrouter')));
    }

    public function testWithAKeyTheNarrowedListIsSavedWithTheOtherModelTypesKept(): void
    {
        $kept = array_values(array_filter(Models::all(), static fn ($m): bool => $m->provider === 'openrouter'))[0];
        $images = count(array_filter(Models::allOfType(ModelType::Image), static fn ($m): bool => $m->provider === 'openrouter'));
        $classifiers = count(array_filter(Models::allOfType(ModelType::Classifier), static fn ($m): bool => $m->provider === 'openrouter'));
        $server = new CannedServer();
        $body = (string) json_encode(['data' => [['id' => $kept->id, 'context_length' => 7777]]]);
        $base = rtrim($server->start(["HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: " . strlen($body) . "\r\n\r\n{$body}"]), '/');
        putenv("OPENROUTER_API_KEY=sk-test");
        Models::useOverlay(static fn (array $models): array => array_map(
            static fn ($m) => $m->provider === 'openrouter' ? new \Pig\Ai\Model(...[...get_object_vars($m), 'baseUrl' => $base]) : $m,
            $models,
        ));

        try {
            $result = Async::run(fn () => ModelRefresh::refresh(Auth::discover("{$this->home}/auth.json"), ['openrouter']));
        } finally {
            putenv('OPENROUTER_API_KEY');
        }

        $this->assertSame([], $result->errors);
        $this->assertStringContainsString('GET /models/user', $server->receivedHead());
        $openrouter = array_values(array_filter(Models::all(), static fn ($m): bool => $m->provider === 'openrouter'));
        $this->assertSame([[$kept->id, 7777]], array_map(static fn ($m): array => [$m->id, $m->contextWindow], $openrouter));
        $this->assertSame($images, count(array_filter(Models::allOfType(ModelType::Image), static fn ($m): bool => $m->provider === 'openrouter')));
        $stored = json_decode((string) file_get_contents("{$this->home}/models-store.json"), true)['openrouter']['models'];
        $this->assertSame(1 + $images + $classifiers, count($stored));
        $this->assertSame(['chat', $kept->id, 7777], [$stored[0]['type'], $stored[0]['id'], $stored[0]['contextWindow']]);
    }

    public function testANewerSavedCatalogIsLaidOverTheBuiltInModelsAndAnOlderOneIsNot(): void
    {
        $groq = array_values(array_filter(Models::all(), static fn ($m): bool => $m->provider === 'groq'));
        $first = $groq[0];
        $newer = Models::builtinGeneratedAt() + 1000;
        $entries = [
            [...ModelJson::encode($first), 'name' => 'Renamed'],
            [...ModelJson::encode($first), 'id' => 'zz-new-model', 'name' => 'New'],
            ['type' => 'video', 'id' => 'skipped'],
        ];
        $this->store('groq', ['models' => $entries, 'checkedAt' => 1, 'lastModified' => $newer]);

        try {
            ModelRefresh::refresh(Auth::discover("{$this->home}/auth.json"), ['groq'], allowNetwork: false);
            $now = array_values(array_filter(Models::all(), static fn ($m): bool => $m->provider === 'groq'));

            $this->assertSame('Renamed', $now[0]->name);
            $this->assertSame(count($groq) + 1, count($now));
            $this->assertSame('zz-new-model', $now[count($now) - 1]->id);

            $this->store('groq', ['models' => $entries, 'checkedAt' => 1, 'lastModified' => Models::builtinGeneratedAt()]);
            ModelRefresh::refresh(Auth::discover("{$this->home}/auth.json"), ['groq'], allowNetwork: false);
            $this->assertSame($first->name, array_values(array_filter(Models::all(), static fn ($m): bool => $m->provider === 'groq'))[0]->name);
        } finally {
            Models::useProviderCatalog('groq', null);
        }
    }

    public function testTheCatalogServerIsAskedAndItsAnswerSavedWithItsDateAndEtag(): void
    {
        $first = array_values(array_filter(Models::all(), static fn ($m): bool => $m->provider === 'groq'))[0];
        $body = (string) json_encode([[...ModelJson::encode($first), 'name' => 'From The Server']]);
        $server = new CannedServer();
        $base = $server->start(["HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nLast-Modified: Sat, 01 Jan 2050 00:00:00 GMT\r\nETag: \"v1\"\r\nContent-Length: " . strlen($body) . "\r\n\r\n{$body}"]);
        putenv("PIG_MODEL_CATALOG_URL={$base}");

        try {
            $result = Async::run(fn () => ModelRefresh::refresh(Auth::discover("{$this->home}/auth.json"), ['groq']));

            $this->assertSame([], $result->errors);
            $this->assertStringContainsString('GET /api/models/providers/groq?types=chat,image,classifier', $server->receivedHead());
            $this->assertSame('From The Server', array_values(array_filter(Models::all(), static fn ($m): bool => $m->provider === 'groq'))[0]->name);
            $entry = json_decode((string) file_get_contents("{$this->home}/models-store.json"), true)['groq'];
            $this->assertSame([2524608000000, '"v1"'], [$entry['lastModified'], $entry['etag']]);
            $this->assertSame('groq', $entry['models'][0]['provider']);

            // Checked a moment ago: not asked again within the interval, unless forced.
            Async::run(fn () => ModelRefresh::refresh(Auth::discover("{$this->home}/auth.json"), ['groq']));
            $this->assertSame(1, $server->connections());
        } finally {
            putenv('PIG_MODEL_CATALOG_URL=off');
            Models::useProviderCatalog('groq', null);
        }
    }

    public function testAStaleCheckRevalidatesWithTheEtagAndA304OnlyMovesTheCheckTime(): void
    {
        $first = array_values(array_filter(Models::all(), static fn ($m): bool => $m->provider === 'groq'))[0];
        $this->store('groq', ['models' => [[...ModelJson::encode($first), 'name' => 'Cached']], 'checkedAt' => 1, 'lastModified' => 2524608000000, 'etag' => '"v1"']);
        $server = new CannedServer();
        $base = $server->start(["HTTP/1.1 304 Not Modified\r\nContent-Length: 0\r\n\r\n"]);
        putenv("PIG_MODEL_CATALOG_URL={$base}");

        try {
            $result = Async::run(fn () => ModelRefresh::refresh(Auth::discover("{$this->home}/auth.json"), ['groq']));

            $this->assertSame([], $result->errors);
            $this->assertStringContainsStringIgnoringCase('if-none-match: "v1"', $server->receivedHead());
            $entry = json_decode((string) file_get_contents("{$this->home}/models-store.json"), true)['groq'];
            $this->assertGreaterThan(1, $entry['checkedAt']);
            $this->assertSame('"v1"', $entry['etag']);
            $this->assertSame('Cached', array_values(array_filter(Models::all(), static fn ($m): bool => $m->provider === 'groq'))[0]->name);
        } finally {
            putenv('PIG_MODEL_CATALOG_URL=off');
            Models::useProviderCatalog('groq', null);
        }
    }

    public function testNoCatalogForAProviderIsRememberedAndAFailureIsItsError(): void
    {
        $server = new CannedServer();
        $base = $server->start(["HTTP/1.1 404 Not Found\r\nContent-Length: 0\r\n\r\n"]);
        putenv("PIG_MODEL_CATALOG_URL={$base}");

        try {
            $result = Async::run(fn () => ModelRefresh::refresh(Auth::discover("{$this->home}/auth.json"), ['groq']));
            $this->assertSame([], $result->errors);
            $entry = json_decode((string) file_get_contents("{$this->home}/models-store.json"), true)['groq'];
            $this->assertSame([[], 0], [$entry['models'], $entry['lastModified']]);

            $failing = new CannedServer();
            putenv('PIG_MODEL_CATALOG_URL=' . $failing->start(["HTTP/1.1 500 Internal Server Error\r\nContent-Length: 0\r\n\r\n"]));
            $result = Async::run(fn () => ModelRefresh::refresh(Auth::discover("{$this->home}/auth.json"), ['cerebras']));
            $this->assertSame(['cerebras'], array_keys($result->errors));
            $this->assertSame('Model catalog request failed for cerebras: 500', $result->errors['cerebras']->getMessage());
        } finally {
            putenv('PIG_MODEL_CATALOG_URL=off');
        }
    }

    /** @param array<string, mixed> $entry */
    private function store(string $provider, array $entry): void
    {
        // Written beside and renamed over, as pig writes it.
        file_put_contents("{$this->home}/models-store.json.tmp", json_encode([$provider => $entry]));
        rename("{$this->home}/models-store.json.tmp", "{$this->home}/models-store.json");
    }

    public function testWithoutAnOpenRouterKeyTheCatalogIsAsBuilt(): void
    {
        $before = count(array_filter(Models::all(), static fn ($m): bool => $m->provider === 'openrouter'));

        $result = ModelRefresh::refresh(Auth::inMemory(), ['openrouter']);

        $this->assertSame([], $result->errors);
        $this->assertSame($before, count(array_filter(Models::all(), static fn ($m): bool => $m->provider === 'openrouter')));
    }
}
