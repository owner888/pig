<?php

declare(strict_types=1);

namespace Pig\Evals\Evals;

use Pig\Async\Async;
use Pig\Evals\Harness;
use RuntimeException;

/**
 * Upstream's `packages/evals/evals/smoke.eval.ts`: basic prompt smoke eval with all tools disabled.
 */
final class SmokeEval
{
    public const string EVAL_SET = 'Answer a basic prompt';

    public const string CASE_ID = 'returns the expected answer';

    /**
     * @return list<array{name: string, file: string}>
     */
    public static function cases(): array
    {
        return [
            [
                'name' => self::EVAL_SET . ' > ' . self::CASE_ID,
                'file' => 'evals/smoke.eval.php',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public static function run(array $options = []): array
    {
        $harness = Harness::createPigCodingAgentHarness([...$options, 'noTools' => 'all']);
        $result = Async::run(static fn (): array => $harness("What's the capital of France? Respond with only the city name."));

        if (trim((string) ($result['output'] ?? '')) !== 'Paris') {
            throw new RuntimeException('Expected output "Paris", got: ' . var_export($result['output'] ?? null, true));
        }

        if (($result['errors'] ?? []) !== []) {
            throw new RuntimeException('Expected no errors.');
        }

        if ((int) ($result['usage']['totalTokens'] ?? 0) <= 0) {
            throw new RuntimeException('Expected totalTokens > 0.');
        }

        return [
            'evalSet' => self::EVAL_SET,
            'caseId' => self::CASE_ID,
            'score' => 1,
            'scores' => [['name' => 'SmokeAssertion', 'score' => 1]],
            'run' => $result,
        ];
    }
}
