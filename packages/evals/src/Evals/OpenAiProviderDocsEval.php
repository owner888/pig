<?php

declare(strict_types=1);

namespace Pig\Evals\Evals;

use Pig\Ai\Context;
use Pig\Ai\SimpleStreamOptions;
use Pig\Ai\UserMessage;
use Pig\Async\Async;
use Pig\Evals\AcmeServer;
use Pig\Evals\ConfiguredRuntime;
use Pig\Evals\Harness;
use Pig\Evals\Judge;

/**
 * Upstream's `packages/evals/evals/openai-provider.docs.eval.ts`: configure an OpenAI-compatible
 * provider in `models.json` and verify it completes against `AcmeServer`.
 */
final class OpenAiProviderDocsEval
{
    public const string EVAL_SET = 'Add OpenAI-compatible provider';

    public const string CASE_ID = 'configures a provider that works through Pig';

    /**
     * @return list<array{name: string, file: string}>
     */
    public static function cases(): array
    {
        return [
            [
                'name' => self::EVAL_SET . ' > ' . self::CASE_ID,
                'file' => 'evals/openai-provider.docs.eval.php',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public static function run(array $options = []): array
    {
        $server = AcmeServer::create('openai');
        $server->start();

        try {
            $expected = [
                'result' => [
                    'validRequestReceived' => true,
                    'model' => [
                        'id' => AcmeServer::OPENAI_MODEL_ID,
                        'name' => 'Acme Chat',
                        'provider' => AcmeServer::OPENAI_PROVIDER_ID,
                        'reasoning' => false,
                        'input' => ['text'],
                        'cost' => ['input' => 0, 'output' => 0, 'cacheRead' => 0, 'cacheWrite' => 0],
                        'contextWindow' => 32768,
                        'maxTokens' => 4096,
                    ],
                    'response' => [
                        'text' => AcmeServer::OPENAI_PROBE_RESPONSE,
                        'stopReason' => 'stop',
                        'inputTokens' => 3,
                        'outputTokens' => 2,
                    ],
                ],
            ];

            $harness = Harness::createPigDocumentationEvalHarness([
                ...$options,
                'output' => static fn (array $ctx): array => ConfiguredRuntime::inspectProvider(
                    ConfiguredRuntime::loadConfiguredModelRuntime((string) $ctx['agentDir']),
                    [
                        'providerId' => AcmeServer::OPENAI_PROVIDER_ID,
                        'modelId' => AcmeServer::OPENAI_MODEL_ID,
                        'createContext' => static fn (): Context => new Context([new UserMessage(AcmeServer::OPENAI_PROBE_PROMPT, 0)]),
                        'options' => new SimpleStreamOptions(maxTokens: 32, env: ['ACME_API_KEY' => 'resolved-acme-key']),
                        'validRequestReceived' => $server->validRequestReceived(...),
                    ],
                ),
            ]);

            $providerId = AcmeServer::OPENAI_PROVIDER_ID;
            $baseUrl = $server->baseUrl();
            $modelId = AcmeServer::OPENAI_MODEL_ID;
            $prompt = <<<PROMPT
Configure this running Pig installation with Acme as a provider. Do not create project-local configuration. Its provider ID is {$providerId}, its API is at {$baseUrl}, and it uses OpenAI Chat Completions. Read its API key from the ACME_API_KEY environment variable.

The provider offers one model, {$modelId}, shown as “Acme Chat”. It accepts text, does not support reasoning, has a 32,768-token context window and a 4,096-token maximum output, and has no usage cost.
PROMPT;

            $result = Async::run(static fn (): array => $harness([
                ['type' => 'prompt', 'content' => $prompt],
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
        } finally {
            $server->stop();
        }
    }
}
