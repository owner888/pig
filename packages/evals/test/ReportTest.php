<?php

declare(strict_types=1);

namespace Pig\Evals\Test;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pig\Evals\EvalTask;
use Pig\Evals\Report;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use stdClass;

/**
 * Ported from upstream's `packages/evals/test/report.test.ts`.
 */
final class ReportTest extends TestCase
{
    private const string SESSION = "{\"type\":\"session\"}\n";

    /** @var list<string> */
    private array $temporaryDirectories = [];

    private EvalTask $task;

    #[\Override]
    protected function setUp(): void
    {
        $this->task = new EvalTask(
            'evals/example.docs.eval.php',
            'Example workflow > handles the case',
            'Example workflow',
            'handles the case',
            'without_docs',
            'fixture/model',
            1,
        );
    }

    #[\Override]
    protected function tearDown(): void
    {
        foreach ($this->temporaryDirectories as $dir) {
            self::removeTree($dir);
        }

        $this->temporaryDirectories = [];
    }

    /**
     * @return list<array{string}>
     */
    public static function skippedStatuses(): array
    {
        return [['skipped'], ['todo'], ['disabled']];
    }

    #[DataProvider('skippedStatuses')]
    public function testMapsSkippedStatusToSkipped(string $status): void
    {
        $this->assertSame('skipped', Report::classifyCaseStatus($status));
    }

    public function testMapsFailedInfrastructureToErrored(): void
    {
        $this->assertSame('errored', Report::classifyCaseStatus('failed'));
    }

    public function testPreservesASkippedOutcomeWhenNoHarnessRunExists(): void
    {
        ['observation' => $observation] = $this->readObservation(['status' => 'skipped']);
        $this->assertSame('skipped', $observation['outcome']);
    }

    public function testPreservesAPendingOutcomeWhenNoHarnessRunExists(): void
    {
        ['observation' => $observation] = $this->readObservation(['status' => 'pending']);
        $this->assertSame('pending', $observation['outcome']);
    }

    public function testPreservesMetricsFromAFailedEvalWithAPartialHarnessRun(): void
    {
        ['directory' => $directory, 'observation' => $observation] = $this->readObservation([
            'status' => 'failed',
            'meta' => $this->scoredMeta(['errors' => [['message' => 'Prompt verification failed']]]),
        ]);

        $this->assertSame([
            'evalSet' => $this->task->evalSet,
            'caseId' => $this->task->caseId,
            'variant' => $this->task->variant,
            'model' => $this->task->model,
            'runNumber' => $this->task->runNumber,
            'inputTokens' => 10,
            'outputTokens' => 5,
            'cacheReadTokens' => 2,
            'cacheWriteTokens' => 3,
            'totalTokens' => 15,
            'toolCalls' => 1,
            'totalMs' => 1234,
            'estimatedCostUsd' => 0.01,
            'outcome' => 'errored',
        ], $observation);

        $sessionsRoot = "{$directory}/{$this->task->variant}/sessions";
        $hashes = array_values(array_diff(scandir($sessionsRoot) ?: [], ['.', '..']));
        $this->assertCount(1, $hashes);
        $this->assertSame(self::SESSION, file_get_contents("{$sessionsRoot}/{$hashes[0]}/session.jsonl"));
    }

    public function testRecordsAnErroredOutcomeWhenAPassedEvalHasNoHarnessRun(): void
    {
        ['observation' => $observation] = $this->readObservation(['status' => 'passed']);
        $this->assertSame('errored', $observation['outcome']);
    }

    public function testReadsAScoredHarnessRunAndPersistsTheSessionArtifact(): void
    {
        ['directory' => $directory, 'observation' => $observation] = $this->readObservation([
            'meta' => $this->scoredMeta(),
        ]);

        $this->assertSame([
            'evalSet' => $this->task->evalSet,
            'caseId' => $this->task->caseId,
            'variant' => $this->task->variant,
            'model' => $this->task->model,
            'runNumber' => $this->task->runNumber,
            'inputTokens' => 10,
            'outputTokens' => 5,
            'cacheReadTokens' => 2,
            'cacheWriteTokens' => 3,
            'totalTokens' => 15,
            'toolCalls' => 1,
            'totalMs' => 1234,
            'estimatedCostUsd' => 0.01,
            'outcome' => 'scored',
            'score' => 0.5,
        ], $observation);

        $sessionsRoot = "{$directory}/{$this->task->variant}/sessions";
        $hashes = array_values(array_diff(scandir($sessionsRoot) ?: [], ['.', '..']));
        $this->assertCount(1, $hashes);
        $this->assertSame(self::SESSION, file_get_contents("{$sessionsRoot}/{$hashes[0]}/session.jsonl"));
    }

    public function testTreatsAZeroScoreAsScoredData(): void
    {
        ['observation' => $observation] = $this->readObservation([
            'meta' => $this->scoredMeta(['avgScore' => 0]),
        ]);

        $this->assertSame('scored', $observation['outcome']);
        $this->assertSame(0, $observation['score']);
    }

    public function testRecordsAnUnscoredOutcomeWhenACompletedEvalHasNoScore(): void
    {
        ['observation' => $observation] = $this->readObservation([
            'meta' => $this->scoredMeta(['avgScore' => null]),
        ]);

        $this->assertSame('unscored', $observation['outcome']);
    }

    public function testRecordsAnErroredOutcomeWhenTheReportedModelDoesNotMatchTheTask(): void
    {
        ['observation' => $observation] = $this->readObservation([
            'meta' => $this->scoredMeta(['model' => 'other']),
        ]);

        $this->assertSame('errored', $observation['outcome']);
    }

    public function testRecordsAnErroredOutcomeWhenACompletedHarnessRunContainsErrors(): void
    {
        ['observation' => $observation] = $this->readObservation([
            'meta' => $this->scoredMeta(['errors' => [['message' => 'boom']]]),
        ]);

        $this->assertSame('errored', $observation['outcome']);
    }

    public function testStillScoresACompletedEvalWhenTheSessionArtifactIsMissing(): void
    {
        ['observation' => $observation] = $this->readObservation([
            'meta' => $this->scoredMeta(['artifacts' => ['runId' => 'run-1']]),
        ]);

        $this->assertSame('scored', $observation['outcome']);
        $this->assertSame(0.5, $observation['score']);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function scoredMeta(array $overrides = []): array
    {
        $avgScore = array_key_exists('avgScore', $overrides) ? $overrides['avgScore'] : 0.5;

        return [
            'eval' => [
                'avgScore' => $avgScore,
                'scores' => [['name' => 'StructuredOutputJudge', 'score' => 0.5]],
                'thresholdFailed' => false,
            ],
            'harness' => [
                'name' => 'without_docs',
                'run' => [
                    'output' => ['ok' => true],
                    'session' => ['events' => [['type' => 'message', 'role' => 'user', 'content' => 'prompt']]],
                    'usage' => [
                        'provider' => 'fixture',
                        'model' => $overrides['model'] ?? 'model',
                        'inputTokens' => 10,
                        'outputTokens' => 5,
                        'totalTokens' => 15,
                        'toolCalls' => 1,
                        'metadata' => ['cacheReadTokens' => 2, 'cacheWriteTokens' => 3, 'estimatedCostUsd' => 0.01],
                    ],
                    'timings' => ['totalMs' => 1234],
                    'artifacts' => $overrides['artifacts'] ?? ['runId' => 'run-1', Report::PIG_SESSION_SNAPSHOT_ARTIFACT => self::SESSION],
                    'errors' => $overrides['errors'] ?? [],
                ],
            ],
        ];
    }

    /**
     * @param array{status?: string, meta?: array<string, mixed>} $assertion
     * @return array{directory: string, observation: array<string, mixed>}
     */
    private function readObservation(array $assertion = []): array
    {
        $directory = sys_get_temp_dir() . '/pig-eval-report-test-' . bin2hex(random_bytes(4));
        mkdir($directory, 0o700, true);
        $this->temporaryDirectories[] = $directory;

        $reportPath = $directory . '/vitest.json';
        $status = $assertion['status'] ?? 'passed';
        file_put_contents($reportPath, json_encode([
            'numFailedTests' => 0,
            'numPassedTests' => $status === 'passed' ? 1 : 0,
            'numPendingTests' => $status === 'pending' || $status === 'skipped' ? 1 : 0,
            'numTodoTests' => 0,
            'numTotalTests' => 1,
            'startTime' => 0,
            'success' => true,
            'testResults' => [
                [
                    'message' => '',
                    'name' => '/repo/packages/evals/evals/example.docs.eval.php',
                    'status' => 'passed',
                    'assertionResults' => [
                        [
                            'ancestorTitles' => [$this->task->evalSet],
                            'fullName' => "{$this->task->evalSet} {$this->task->caseId}",
                            'status' => $status,
                            'title' => $this->task->caseId,
                            'failureMessages' => [],
                            'meta' => $assertion['meta'] ?? new stdClass(),
                        ],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        return [
            'directory' => $directory,
            'observation' => Report::readTaskObservation($this->task, $reportPath, $directory),
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
