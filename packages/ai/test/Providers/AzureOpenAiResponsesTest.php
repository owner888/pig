<?php

declare(strict_types=1);

namespace Pig\Ai\Test\Providers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pig\Ai\Api;
use Pig\Ai\Model;
use Pig\Ai\ProviderError;
use Pig\Ai\Providers\AzureOpenAiConfig;
use Pig\Ai\Providers\AzureOpenAiResponsesOptions;
use Pig\Ai\ReasoningEffort;
use Pig\Ai\SimpleStreamOptions;
use Pig\Ai\Stream;
use Pig\Async\Loop;

/**
 * Azure OpenAI, replayed against what upstream did with the same input (see `UpstreamRecord`).
 *
 * `fixtures/azure/a*.json`: the Responses API through `streamSimple()` and `stream()` — the endpoint
 * from the environment and from the options, a deployment from the options and from
 * `AZURE_OPENAI_DEPLOYMENT_NAME_MAP`, the API version, `api-key`, a replayed conversation from the
 * same and from another provider, a refused request, no endpoint at all, a filtered answer, a model
 * that does not reason — and the provider's Chat Completions arm (`azureProvider()`), with and
 * without an endpoint. `fixtures/azure-config.json` is `azure-openai-config.ts` asked directly.
 *
 * Not compared: the user agent beyond its being pig's where upstream's is pi's, and the
 * `x-stainless-*` headers that describe the runtime (`os`, `arch`, `runtime`, `runtime-version`).
 */
final class AzureOpenAiResponsesTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $saved = [];

    #[\Override]
    protected function setUp(): void
    {
        Loop::reset();

        foreach (array_keys(getenv()) as $name) {
            if (str_starts_with($name, 'AZURE_') || str_starts_with($name, 'OPENAI') || str_starts_with($name, 'PI_') || preg_match('/proxy/i', $name) === 1) {
                $this->set($name, null);
            }
        }
    }

    #[\Override]
    protected function tearDown(): void
    {
        foreach ($this->saved as $name => $value) {
            putenv($value === false ? $name : "{$name}={$value}");
        }

        $this->saved = [];
    }

    /** @return iterable<string, array{string}> */
    public static function cases(): iterable
    {
        return UpstreamRecord::cases('azure');
    }

    #[DataProvider('cases')]
    public function testPigDoesWhatUpstreamDidWithTheSameInput(string $case): void
    {
        [$scenario, $upstream] = UpstreamRecord::fixture('azure', $case);

        [$requests, $events, $message] = UpstreamRecord::replay($scenario, function (string $server) use ($scenario) {
            foreach ($scenario['env'] ?? [] as $name => $value) {
                $this->set($name, str_replace('SERVER', $server, $value));
            }

            $model = UpstreamRecord::model($scenario['model'], $server);
            $context = UpstreamRecord::context($scenario);
            $o = json_decode(str_replace('SERVER', $server, (string) json_encode($scenario['options'])), true);

            if ($scenario['simple']) {
                return Stream::simple($model, $context, new SimpleStreamOptions(
                    maxTokens: $o['maxTokens'] ?? null,
                    apiKey: $o['apiKey'] ?? null,
                    reasoning: isset($o['reasoning']) ? ReasoningEffort::from($o['reasoning']) : null,
                    sessionId: $o['sessionId'] ?? null,
                ));
            }

            return Stream::start($model, $context, new AzureOpenAiResponsesOptions(
                temperature: $o['temperature'] ?? null,
                maxTokens: $o['maxTokens'] ?? null,
                apiKey: $o['apiKey'] ?? null,
                sessionId: $o['sessionId'] ?? null,
                headers: $o['headers'] ?? null,
                reasoningEffort: isset($o['reasoningEffort']) ? ReasoningEffort::from($o['reasoningEffort']) : null,
                toolChoice: $o['toolChoice'] ?? null,
                reasoningSummary: $o['reasoningSummary'] ?? null,
                azureApiVersion: $o['azureApiVersion'] ?? null,
                azureResourceName: $o['azureResourceName'] ?? null,
                azureBaseUrl: $o['azureBaseUrl'] ?? null,
                azureDeploymentName: $o['azureDeploymentName'] ?? null,
            ));
        });

        self::assertSame(array_column($upstream['requests'], 'path'), array_column($requests, 'path'));

        foreach ($upstream['requests'] as $i => $expected) {
            $actual = $requests[$i];
            self::assertSame($expected['method'], $actual['method']);
            self::assertSame($expected['body'], $actual['body'], "request {$i}'s body");
            $wanted = $expected['headers'];
            $sent = array_intersect_key($actual['headers'], array_flip(['api-key', 'authorization', 'content-type', 'accept', 'x-stainless-retry-count', 'x-stainless-lang', 'x-stainless-package-version', 'x-custom', 'session_id', 'x-client-request-id']));
            ksort($wanted);
            ksort($sent);
            self::assertSame($wanted, $sent, "request {$i}'s headers");

            if (str_starts_with((string) $expected['userAgent'], 'pi (')) {
                self::assertStringStartsWith('pig (', $actual['headers']['user-agent'] ?? '');
            } else {
                self::assertSame($expected['userAgent'], $actual['headers']['user-agent'] ?? null);
            }
        }

        self::assertSame($upstream['events'], $events);
        self::assertEquals(UpstreamRecord::normalize($upstream['message']), UpstreamRecord::normalize((array) $message));
    }

    public function testTheEndpointIsResolvedAsUpstreamResolvesIt(): void
    {
        $record = json_decode((string) file_get_contents(__DIR__ . '/../fixtures/azure-config.json'), true, flags: JSON_THROW_ON_ERROR);

        foreach ($record['baseUrls'] as [$model, $options, $expected]) {
            $actual = self::attempt(static fn (): string => AzureOpenAiConfig::resolveAzureBaseUrl(self::model($model['baseUrl']), self::options($options)));
            self::assertSame($expected, $actual, json_encode($options));
        }

        foreach ($record['deployments'] as [$id, $options, $expected]) {
            self::assertSame($expected, AzureOpenAiConfig::resolveDeploymentName(self::model('', $id), self::options($options)), json_encode($options));
        }

        foreach ($record['configs'] as [$options, $expected]) {
            self::assertSame($expected, AzureOpenAiConfig::resolveAzureConfig(self::model(''), self::options($options)));
        }
    }

    public function testTheStreamSimpleArmNeedsAKeyBeforeAnythingIsSent(): void
    {
        $model = UpstreamRecord::model(UpstreamRecord::fixture('azure', 'a1')[0]['model'], '');

        $this->expectException(ProviderError::class);
        $this->expectExceptionMessage('No API key for provider: azure');

        Stream::simple($model, UpstreamRecord::context(UpstreamRecord::fixture('azure', 'a1')[0]));
    }

    private static function attempt(\Closure $run): mixed
    {
        try {
            return $run();
        } catch (ProviderError $error) {
            return ['error' => $error->getMessage()];
        }
    }

    private static function model(string $baseUrl, string $id = 'gpt-5.4'): Model
    {
        return new Model($id, $id, Api::AzureOpenAiResponses, 'azure', $baseUrl, 400_000, 128_000);
    }

    /** @param array<string, mixed> $o */
    private static function options(array $o): AzureOpenAiResponsesOptions
    {
        return new AzureOpenAiResponsesOptions(
            env: $o['env'] ?? null,
            azureApiVersion: $o['azureApiVersion'] ?? null,
            azureResourceName: $o['azureResourceName'] ?? null,
            azureBaseUrl: $o['azureBaseUrl'] ?? null,
            azureDeploymentName: $o['azureDeploymentName'] ?? null,
        );
    }

    private function set(string $name, ?string $value): void
    {
        if (!array_key_exists($name, $this->saved)) {
            $this->saved[$name] = getenv($name);
        }

        putenv($value === null ? $name : "{$name}={$value}");
    }
}
