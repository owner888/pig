<?php

declare(strict_types=1);

namespace Pig\Evals\Evals;

use Pig\Async\Async;
use Pig\Evals\ConfiguredRuntime;
use Pig\Evals\Harness;
use Pig\Evals\Judge;

/**
 * Upstream's `packages/evals/evals/models.docs.eval.ts`: add a model to an existing provider in
 * `models.json` without replacing existing models.
 */
final class ModelsDocsEval
{
    public const string EVAL_SET = 'Add model to existing provider';

    public const string CASE_ID = 'adds the model without replacing existing models';

    public const string PROVIDER_ID = 'openai';

    public const string MODEL_ID = 'fixture-chat';

    public const string MODEL_NAME = 'Fixture Chat';

    /**
     * @return list<array{name: string, file: string}>
     */
    public static function cases(): array
    {
        return [
            [
                'name' => self::EVAL_SET . ' > ' . self::CASE_ID,
                'file' => 'evals/models.docs.eval.php',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public static function run(array $options = []): array
    {
        $expected = [
            'result' => [
                'model' => [
                    'id' => self::MODEL_ID,
                    'name' => self::MODEL_NAME,
                    'provider' => self::PROVIDER_ID,
                    'reasoning' => true,
                    'input' => ['text'],
                    'cost' => ['input' => 0, 'output' => 0, 'cacheRead' => 0, 'cacheWrite' => 0],
                    'contextWindow' => 32768,
                    'maxTokens' => 4096,
                ],
                'existingModelsPreserved' => true,
            ],
        ];

        $harness = Harness::createPigDocumentationEvalHarness([
            ...$options,
            'output' => static fn (array $ctx): array => ConfiguredRuntime::inspectAddedModel(
                ConfiguredRuntime::loadConfiguredModelRuntime((string) $ctx['agentDir']),
                self::PROVIDER_ID,
                self::MODEL_ID,
            ),
        ]);

        $providerId = self::PROVIDER_ID;
        $modelId = self::MODEL_ID;
        $modelName = self::MODEL_NAME;
        $result = Async::run(static fn (): array => $harness([
            [
                'type' => 'prompt',
                'content' => "Configure this running Pig installation with a new `{$providerId}/{$modelId}` model. Do not create project-local configuration. Show it as “{$modelName}”. It accepts text, supports reasoning, has a 32,768-token context window and a 4,096-token maximum output, and has no usage cost.",
            ],
            ['type' => 'reload'],
        ]));

        $judge = Judge::structuredOutput($expected, 'strict', false);
        $evaluated = ($judge['evaluate'])(['output' => $result['output'], 'result' => $result]);

        return [
            'evalSet' => self::EVAL_SET,
            'caseId' => self::CASE_ID,
            'score' => $evaluated['score'],
            'scores' => [['name' => $judge['name'], ...$evaluated]],
            'run' => $result,
        ];
    }
}
