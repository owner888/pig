<?php

declare(strict_types=1);

namespace Pig\Evals;

use Closure;
use Pig\Ai\Context;
use Pig\Ai\Model;
use Pig\Ai\Models;
use Pig\Ai\SimpleStreamOptions;
use Pig\Ai\TranscriptContext;
use Pig\Ai\Utils\Text;
use Throwable;

/**
 * Upstream's `packages/evals/evals/configured-runtime.ts`: inspects a configured provider or added
 * model after an eval run's reload step.
 */
final class ConfiguredRuntime
{
    public static function loadConfiguredModelRuntime(string $agentDir): ConfiguredModelRuntime
    {
        return new ConfiguredModelRuntime($agentDir, allowModelNetwork: false);
    }

    /**
     * @param array{
     *   providerId: string,
     *   modelId: string,
     *   createContext: Closure(): (Context|TranscriptContext),
     *   options?: SimpleStreamOptions|null,
     *   validRequestReceived: Closure(): bool,
     * } $scenario
     * @return array{result: array<string, mixed>}
     */
    public static function inspectProvider(ConfiguredModelRuntime $runtime, array $scenario): array
    {
        $runtime->refresh(allowNetwork: false);
        $configurationError = $runtime->getError();

        if ($configurationError !== null) {
            return ['result' => ['error' => $configurationError]];
        }

        $providerId = $scenario['providerId'];
        $modelId = $scenario['modelId'];
        $model = $runtime->getModel($providerId, $modelId);

        if ($model === null) {
            return ['result' => ['error' => "Model {$providerId}/{$modelId} is unavailable after reload."]];
        }

        try {
            $response = $runtime->completeSimple($model, ($scenario['createContext'])(), $scenario['options'] ?? null);

            return [
                'result' => [
                    'validRequestReceived' => ($scenario['validRequestReceived'])(),
                    'model' => self::modelFields($model),
                    'response' => [
                        'text' => Text::contentText($response->content),
                        'stopReason' => $response->stopReason->value,
                        'inputTokens' => $response->usage->input,
                        'outputTokens' => $response->usage->output,
                    ],
                ],
            ];
        } catch (Throwable $error) {
            return ['result' => ['error' => $error->getMessage()]];
        }
    }

    /**
     * @return array{result: array<string, mixed>}
     */
    public static function inspectAddedModel(
        ConfiguredModelRuntime $runtime,
        string $providerId,
        string $modelId,
    ): array {
        $pristineModels = Models::builtinModelsOf($providerId);

        if ($pristineModels === []) {
            return ['result' => ['error' => "Built-in provider {$providerId} is unavailable."]];
        }

        $existingModelIds = [];

        foreach ($pristineModels as $pristine) {
            if ($pristine instanceof Model) {
                $existingModelIds[] = $pristine->id;
            }
        }

        if ($existingModelIds === []) {
            return ['result' => ['error' => "Built-in provider {$providerId} has no models."]];
        }

        $runtime->refresh(allowNetwork: false);
        $configurationError = $runtime->getError();

        if ($configurationError !== null) {
            return ['result' => ['error' => $configurationError]];
        }

        $model = $runtime->getModel($providerId, $modelId);

        if ($model === null) {
            return ['result' => ['error' => "Model {$providerId}/{$modelId} is unavailable after reload."]];
        }

        $preserved = true;

        foreach ($existingModelIds as $id) {
            if ($runtime->getModel($providerId, $id) === null) {
                $preserved = false;

                break;
            }
        }

        return [
            'result' => [
                'model' => self::modelFields($model),
                'existingModelsPreserved' => $preserved,
            ],
        ];
    }

    /**
     * @return array{
     *   id: string,
     *   name: string,
     *   provider: string,
     *   reasoning: bool,
     *   input: list<'text'|'image'>,
     *   cost: array{input: int|float, output: int|float, cacheRead: int|float, cacheWrite: int|float},
     *   contextWindow: int,
     *   maxTokens: int,
     * }
     */
    public static function modelFields(Model $model): array
    {
        $rate = static fn (float $v): int|float => floor($v) === $v ? (int) $v : $v;

        return [
            'id' => $model->id,
            'name' => $model->name,
            'provider' => $model->provider,
            'reasoning' => $model->reasoning,
            'input' => array_values($model->input),
            'cost' => [
                'input' => $rate($model->pricing->input),
                'output' => $rate($model->pricing->output),
                'cacheRead' => $rate($model->pricing->cacheRead),
                'cacheWrite' => $rate($model->pricing->cacheWrite),
            ],
            'contextWindow' => $model->contextWindow,
            'maxTokens' => $model->maxTokens,
        ];
    }
}
