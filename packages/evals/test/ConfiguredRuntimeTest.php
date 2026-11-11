<?php

declare(strict_types=1);

namespace Pig\Evals\Test;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use Pig\Ai\Context;
use Pig\Ai\Models;
use Pig\Ai\SimpleStreamOptions;
use Pig\Ai\UserMessage;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\Evals\AcmeServer;
use Pig\Evals\ConfiguredModelRuntime;
use Pig\Evals\ConfiguredRuntime;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use stdClass;

/**
 * Ported from upstream's `packages/evals/test/configured-runtime.test.ts`.
 */
final class ConfiguredRuntimeTest extends TestCase
{
    private const string PROVIDER_ID = AcmeServer::OPENAI_PROVIDER_ID;

    /** @var array<string, mixed> */
    private const array MODEL = [
        'id' => AcmeServer::OPENAI_MODEL_ID,
        'name' => 'Acme Chat',
        'provider' => self::PROVIDER_ID,
        'reasoning' => false,
        'input' => ['text'],
        'cost' => ['input' => 0, 'output' => 0, 'cacheRead' => 0, 'cacheWrite' => 0],
        'contextWindow' => 32768,
        'maxTokens' => 4096,
    ];

    private AcmeServer $server;

    /** @var list<string> */
    private array $temporaryDirectories = [];

    #[\Override]
    protected function setUp(): void
    {
        Loop::reset();
        $this->server = AcmeServer::create('openai');
        $this->server->start();
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->server->stop();
        Models::useOverlay(null);
        Models::useClassifierOverlay(null);

        foreach ($this->temporaryDirectories as $dir) {
            self::removeTree($dir);
        }

        $this->temporaryDirectories = [];
    }

    public function testProbesAModelsJsonProviderThroughModelRuntime(): void
    {
        $runtime = $this->runtimeWith($this->acmeModelsJson());
        $output = Async::run(fn (): array => ConfiguredRuntime::inspectProvider(
            $runtime,
            $this->probe(AcmeServer::OPENAI_PROBE_PROMPT, 'resolved-acme-key'),
        ));

        $this->assertSame([
            'result' => [
                'validRequestReceived' => true,
                'model' => self::MODEL,
                'response' => [
                    'text' => AcmeServer::OPENAI_PROBE_RESPONSE,
                    'stopReason' => 'stop',
                    'inputTokens' => 3,
                    'outputTokens' => 2,
                ],
            ],
        ], $output);
    }

    public function testKeepsANonProbeCompletionDistinctFromAValidProbe(): void
    {
        $runtime = $this->runtimeWith($this->acmeModelsJson());
        $output = Async::run(fn (): array => ConfiguredRuntime::inspectProvider(
            $runtime,
            $this->probe('hello', 'resolved-acme-key'),
        ));

        $this->assertSame([
            'result' => [
                'validRequestReceived' => false,
                'model' => self::MODEL,
                'response' => [
                    'text' => AcmeServer::OPENAI_PROBE_RESPONSE,
                    'stopReason' => 'stop',
                    'inputTokens' => 3,
                    'outputTokens' => 2,
                ],
            ],
        ], $output);
    }

    public function testReturnsAStructuredErrorWhenTheConfiguredModelIsMissing(): void
    {
        $runtime = $this->runtimeWith(['providers' => new stdClass()]);
        $output = Async::run(fn (): array => ConfiguredRuntime::inspectProvider(
            $runtime,
            $this->probe(AcmeServer::OPENAI_PROBE_PROMPT, 'resolved-acme-key'),
        ));

        $this->assertSame([
            'result' => ['error' => 'Model ' . self::PROVIDER_ID . '/' . AcmeServer::OPENAI_MODEL_ID . ' is unavailable after reload.'],
        ], $output);
        $this->assertFalse($this->server->validRequestReceived());
    }

    public function testReturnsAStructuredErrorWhenModelsJsonCannotBeParsed(): void
    {
        $directory = $this->makeTempDir();
        file_put_contents($directory . '/models.json', '{');
        $runtime = ConfiguredRuntime::loadConfiguredModelRuntime($directory);
        $output = Async::run(fn (): array => ConfiguredRuntime::inspectProvider(
            $runtime,
            $this->probe(AcmeServer::OPENAI_PROBE_PROMPT, 'resolved-acme-key'),
        ));

        $this->assertStringContainsString('Failed to parse models.json', (string) ($output['result']['error'] ?? ''));
        $this->assertFalse($this->server->validRequestReceived());
    }

    public function testDoesNotTreatAnUnauthorizedCompletionAsAValidProbe(): void
    {
        $runtime = $this->runtimeWith($this->acmeModelsJson());
        $output = Async::run(fn (): array => ConfiguredRuntime::inspectProvider(
            $runtime,
            $this->probe(AcmeServer::OPENAI_PROBE_PROMPT, 'wrong-key'),
        ));

        $this->assertSame([
            'result' => [
                'validRequestReceived' => false,
                'model' => self::MODEL,
                'response' => [
                    'text' => '',
                    'stopReason' => 'error',
                    'inputTokens' => 0,
                    'outputTokens' => 0,
                ],
            ],
        ], $output);
    }

    public function testLoadsAnAddedModelWithoutDroppingBuiltInModels(): void
    {
        $provider = 'openai';
        $modelId = 'fixture-chat';
        $addedModel = [
            'id' => $modelId,
            'name' => 'Fixture Chat',
            'provider' => $provider,
            'reasoning' => true,
            'input' => ['text'],
            'cost' => ['input' => 0, 'output' => 0, 'cacheRead' => 0, 'cacheWrite' => 0],
            'contextWindow' => 32768,
            'maxTokens' => 4096,
        ];

        $directory = $this->agentDirWith([
            'providers' => [
                $provider => [
                    'models' => [[
                        'id' => $addedModel['id'],
                        'name' => $addedModel['name'],
                        'reasoning' => $addedModel['reasoning'],
                        'input' => $addedModel['input'],
                        'cost' => $addedModel['cost'],
                        'contextWindow' => $addedModel['contextWindow'],
                        'maxTokens' => $addedModel['maxTokens'],
                    ]],
                ],
            ],
        ]);

        $output = ConfiguredRuntime::inspectAddedModel(
            ConfiguredRuntime::loadConfiguredModelRuntime($directory),
            $provider,
            $modelId,
        );

        $this->assertSame([
            'result' => ['model' => $addedModel, 'existingModelsPreserved' => true],
        ], $output);
    }

    public function testReturnsAStructuredErrorWhenTheAddedModelIsMissing(): void
    {
        $directory = $this->agentDirWith(['providers' => new stdClass()]);
        $output = ConfiguredRuntime::inspectAddedModel(
            ConfiguredRuntime::loadConfiguredModelRuntime($directory),
            'openai',
            'fixture-chat',
        );

        $this->assertSame([
            'result' => ['error' => 'Model openai/fixture-chat is unavailable after reload.'],
        ], $output);
    }

    public function testReturnsAStructuredErrorWhenModelsJsonCannotBeParsedForAddedModel(): void
    {
        $directory = $this->makeTempDir();
        file_put_contents($directory . '/models.json', '{');
        $output = ConfiguredRuntime::inspectAddedModel(
            ConfiguredRuntime::loadConfiguredModelRuntime($directory),
            'openai',
            'fixture-chat',
        );

        $this->assertStringContainsString('Failed to parse models.json', (string) ($output['result']['error'] ?? ''));
    }

    private function makeTempDir(): string
    {
        $dir = sys_get_temp_dir() . '/pig-eval-provider-probe-' . bin2hex(random_bytes(4));
        mkdir($dir, 0o700, true);
        $this->temporaryDirectories[] = $dir;

        return $dir;
    }

    private function agentDirWith(mixed $modelsJson): string
    {
        $dir = $this->makeTempDir();
        file_put_contents($dir . '/models.json', json_encode($modelsJson, JSON_THROW_ON_ERROR) . "\n");

        return $dir;
    }

    private function runtimeWith(mixed $modelsJson): ConfiguredModelRuntime
    {
        return ConfiguredRuntime::loadConfiguredModelRuntime($this->agentDirWith($modelsJson));
    }

    /**
     * @return array<string, mixed>
     */
    private function acmeModelsJson(): array
    {
        return [
            'providers' => [
                self::PROVIDER_ID => [
                    'baseUrl' => $this->server->baseUrl(),
                    'api' => 'openai-completions',
                    'apiKey' => '$ACME_API_KEY',
                    'models' => [[
                        'id' => self::MODEL['id'],
                        'name' => self::MODEL['name'],
                        'reasoning' => self::MODEL['reasoning'],
                        'input' => self::MODEL['input'],
                        'cost' => self::MODEL['cost'],
                        'contextWindow' => self::MODEL['contextWindow'],
                        'maxTokens' => self::MODEL['maxTokens'],
                    ]],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function probe(string $content, string $apiKey): array
    {
        return [
            'providerId' => self::PROVIDER_ID,
            'modelId' => AcmeServer::OPENAI_MODEL_ID,
            'createContext' => static fn (): Context => new Context([new UserMessage($content, 0)]),
            'options' => new SimpleStreamOptions(maxTokens: 32, env: ['ACME_API_KEY' => $apiKey]),
            'validRequestReceived' => $this->server->validRequestReceived(...),
        ];
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        /** @var SplFileInfo $item */
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($dir);
    }
}
