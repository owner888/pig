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
use RuntimeException;

/** Upstream's `Models.refresh()`: two phases, the store, a failure per provider, a cancelled caller. */
final class ModelRefreshTest extends TestCase
{
    private string $home;

    #[\Override]
    protected function setUp(): void
    {
        $this->home = sys_get_temp_dir() . '/pig-model-refresh-' . bin2hex(random_bytes(4));
        mkdir($this->home, 0o700, true);
        putenv("PIG_HOME={$this->home}");
    }

    #[\Override]
    protected function tearDown(): void
    {
        ProviderRegistry::forget();
        putenv('PIG_HOME');
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
}
