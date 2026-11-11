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
 * Upstream's `packages/evals/evals/custom-provider.docs.eval.ts`: implement a custom streaming
 * provider extension from an API specification fixture and verify it against `AcmeServer`.
 */
final class CustomProviderDocsEval
{
    public const string EVAL_SET = 'Add custom streaming provider';

    public const string CASE_ID = 'implements a provider from an API fixture';

    private const string DOCUMENTATION_PATH = 'fixtures/acme-stream-api.json';

    private const string STREAM_API_KEY = 'resolved-stream-key';

    /**
     * @return list<array{name: string, file: string}>
     */
    public static function cases(): array
    {
        return [
            [
                'name' => self::EVAL_SET . ' > ' . self::CASE_ID,
                'file' => 'evals/custom-provider.docs.eval.php',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public static function run(array $options = []): array
    {
        $server = AcmeServer::create('stream');
        $server->start();
        putenv('ACME_STREAM_API_KEY=' . self::STREAM_API_KEY);
        $_ENV['ACME_STREAM_API_KEY'] = self::STREAM_API_KEY;

        try {
            $expected = [
                'result' => [
                    'validRequestReceived' => true,
                    'model' => [
                        'id' => AcmeServer::STREAM_MODEL_ID,
                        'name' => 'Acme Stream Chat',
                        'provider' => AcmeServer::STREAM_PROVIDER_ID,
                        'reasoning' => false,
                        'input' => ['text'],
                        'cost' => ['input' => 0, 'output' => 0, 'cacheRead' => 0, 'cacheWrite' => 0],
                        'contextWindow' => 16384,
                        'maxTokens' => 2048,
                    ],
                    'response' => [
                        'text' => AcmeServer::STREAM_PROBE_RESPONSE,
                        'stopReason' => 'stop',
                        'inputTokens' => 4,
                        'outputTokens' => 3,
                    ],
                ],
            ];

            $harness = Harness::createPigDocumentationEvalHarness([
                ...$options,
                'workspaceFiles' => [self::DOCUMENTATION_PATH => AcmeServer::STREAM_API_DOCUMENTATION],
                'output' => static fn (array $ctx): array => ConfiguredRuntime::inspectProvider(
                    ConfiguredRuntime::loadConfiguredModelRuntime((string) $ctx['agentDir']),
                    [
                        'providerId' => AcmeServer::STREAM_PROVIDER_ID,
                        'modelId' => AcmeServer::STREAM_MODEL_ID,
                        'createContext' => static fn (): Context => new Context([new UserMessage(AcmeServer::STREAM_PROBE_PROMPT, 0)]),
                        'options' => new SimpleStreamOptions(maxTokens: 32, env: ['ACME_STREAM_API_KEY' => self::STREAM_API_KEY]),
                        'validRequestReceived' => $server->validRequestReceived(...),
                    ],
                ),
            ]);

            $providerId = AcmeServer::STREAM_PROVIDER_ID;
            $origin = $server->origin();
            $docPath = self::DOCUMENTATION_PATH;
            $modelId = AcmeServer::STREAM_MODEL_ID;
            $prompt = <<<PROMPT
Configure this running Pig installation with Acme Stream as a provider. Do not create a project package or modify project source. Its provider ID is {$providerId}, its API is at {$origin}, and its API documentation is in ./{$docPath}. Read its credential from the ACME_STREAM_API_KEY environment variable.

It offers one model, {$modelId}, shown as “Acme Stream Chat”. The model accepts text, does not support reasoning, has a 16,384-token context window and a 2,048-token maximum output, and has no usage cost.
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
            putenv('ACME_STREAM_API_KEY');
            unset($_ENV['ACME_STREAM_API_KEY'], $_SERVER['ACME_STREAM_API_KEY']);
            $server->stop();
        }
    }
}
