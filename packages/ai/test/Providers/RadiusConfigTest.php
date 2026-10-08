<?php

declare(strict_types=1);

namespace Pig\Ai\Test\Providers;

use PHPUnit\Framework\TestCase;
use Pig\Ai\Api;
use Pig\Ai\Model;
use Pig\Ai\ProviderError;
use Pig\Ai\Providers\RadiusConfig;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\Test\CannedServer;

/**
 * The Radius gateway's catalogue, replayed against what upstream's `providers/radius-config.ts` did
 * with the same answers (`fixtures/radius-config.json`, recorded under Node): a config whose models
 * are kept or dropped by `isRadiusGatewayModel()` — a `reasoning` that is not a boolean, a `cost` that
 * is a list, a `contextWindow` that is a string — a refusal with a long body cut to 512 units, a config
 * whose `baseUrl` is not a string, and a refusal with no body; and `normalizeRadiusGatewayUrl()`.
 */
final class RadiusConfigTest extends TestCase
{
    private const array REPLIES = [
        [200, '{"baseUrl":"https://g.example/v1","models":[{"id":"a","name":"A","reasoning":true,"input":["text","image"],"cost":{"input":1,"output":2,"cacheRead":0.1,"cacheWrite":0,"tiers":[{"inputTokensAbove":10,"input":2,"output":3,"cacheRead":0.2,"cacheWrite":0}]},"contextWindow":100,"maxTokens":10,"thinkingLevelMap":{"off":null,"high":"high"},"lab":"X"},{"id":"b","name":"B","reasoning":"yes","input":["text"],"cost":{},"contextWindow":1,"maxTokens":1},{"id":"c","name":"C","reasoning":false,"input":["text"],"cost":[],"contextWindow":1,"maxTokens":1},{"id":"d","name":"D","reasoning":false,"input":["text"],"cost":{},"contextWindow":"1","maxTokens":1}]}'],
        [503, null],
        [200, '{"baseUrl":1,"models":[]}'],
        [401, ''],
    ];

    #[\Override]
    protected function setUp(): void
    {
        Loop::reset();
    }

    public function testPigDoesWhatUpstreamDidWithTheSameAnswers(): void
    {
        $record = json_decode((string) file_get_contents(__DIR__ . '/../fixtures/radius-config.json'), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame($record['normalize'], array_map(RadiusConfig::normalizeRadiusGatewayUrl(...), ['radius.pi.dev', 'https://x.dev///', 'HTTP://y.dev/', 'ftp://z']));

        $server = new CannedServer();
        $replies = [];

        foreach (self::REPLIES as [$status, $body]) {
            $body ??= '  ' . str_repeat('é', 600) . '  ';
            $reason = match ($status) {
                200 => 'OK',
                401 => 'Unauthorized',
                503 => 'Service Unavailable',
            };
            $replies[] = ["HTTP/1.1 {$status} {$reason}\r\ncontent-type: application/json\r\ncontent-length: " . strlen($body) . "\r\n\r\n" . $body];
        }

        $gateway = $server->startSequence($replies) . 'some/path/';
        $results = Async::run(static function () use ($gateway): array {
            $results = [];

            foreach (['k1', null, null, 'k2'] as $key) {
                try {
                    $config = RadiusConfig::loadRadiusGatewayConfig($gateway, $key);
                    $results[] = ['config' => $config, 'models' => array_map(self::modelJson(...), RadiusConfig::getRadiusModelsFromConfig('radius-x', $config))];
                } catch (ProviderError $error) {
                    $results[] = ['error' => str_replace($gateway, 'GATEWAY', $error->getMessage())];
                }
            }

            return $results;
        });
        $received = $server->received();
        $server->stop();

        foreach ($record['results'] as $index => $want) {
            if (isset($want['error'])) {
                $this->assertSame($want['error'], $results[$index]['error'] ?? null, "answer {$index}");

                continue;
            }

            $this->assertEquals($want['config'], $results[$index]['config'], "answer {$index}");
            $this->assertEquals(array_map(self::upstreamModel(...), $want['models']), $results[$index]['models'], "answer {$index}");
        }

        // `GET new URL("/v1/config", gateway)` — the origin's, not under the gateway's path — with the
        // key as a bearer token only when there is one.
        $heads = preg_split('/(?=GET \/)/', $received, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $this->assertCount(4, $heads);

        foreach ($record['seen'] as $index => $seen) {
            $this->assertStringStartsWith("GET {$seen['url']} HTTP/1.1", $heads[$index]);
            $this->assertMatchesRegularExpression('/^accept: application\/json\r$/mi', $heads[$index]);

            if ($seen['authorization'] === null) {
                $this->assertDoesNotMatchRegularExpression('/^authorization:/mi', $heads[$index]);
            } else {
                $this->assertMatchesRegularExpression('/^authorization: ' . preg_quote($seen['authorization'], '/') . '\r$/mi', $heads[$index]);
            }
        }
    }

    /** @return array<string, mixed> */
    private static function modelJson(Model $model): array
    {
        return [
            'id' => $model->id,
            'name' => $model->name,
            'api' => $model->api->value,
            'provider' => $model->provider,
            'baseUrl' => $model->baseUrl,
            'reasoning' => $model->reasoning,
            'input' => $model->input,
            'contextWindow' => $model->contextWindow,
            'maxTokens' => $model->maxTokens,
            'cost' => [$model->pricing->input, $model->pricing->output, $model->pricing->cacheRead, $model->pricing->cacheWrite, array_map(
                static fn ($tier): array => [$tier->inputTokensAbove, $tier->input, $tier->output, $tier->cacheRead, $tier->cacheWrite],
                $model->pricing->tiers,
            )],
            'thinkingLevelMap' => $model->thinkingLevelMap,
        ];
    }

    /**
     * @param array<string, mixed> $model
     * @return array<string, mixed>
     */
    private static function upstreamModel(array $model): array
    {
        $cost = $model['cost'];

        return [
            'id' => $model['id'],
            'name' => $model['name'],
            'api' => $model['api'],
            'provider' => $model['provider'],
            'baseUrl' => $model['baseUrl'],
            'reasoning' => $model['reasoning'],
            'input' => $model['input'],
            'contextWindow' => $model['contextWindow'],
            'maxTokens' => $model['maxTokens'],
            'cost' => [(float) $cost['input'], (float) $cost['output'], (float) $cost['cacheRead'], (float) $cost['cacheWrite'], array_map(
                static fn (array $t): array => [$t['inputTokensAbove'], (float) $t['input'], (float) $t['output'], (float) $t['cacheRead'], (float) $t['cacheWrite']],
                $cost['tiers'] ?? [],
            )],
            'thinkingLevelMap' => $model['thinkingLevelMap'] ?? [],
        ];
    }

    public function testTheModelsArePiMessagesModelsAtTheConfigsBaseUrl(): void
    {
        $models = RadiusConfig::getRadiusModelsFromConfig('radius', ['baseUrl' => 'https://radius.pi.dev/v1', 'models' => [
            ['id' => 'x', 'name' => 'X', 'reasoning' => false, 'input' => ['text'], 'cost' => [], 'contextWindow' => 5, 'maxTokens' => 2],
        ]]);

        $this->assertCount(1, $models);
        $this->assertSame([Api::PiMessages, 'radius', 'https://radius.pi.dev/v1'], [$models[0]->api, $models[0]->provider, $models[0]->baseUrl]);
    }
}
