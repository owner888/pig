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
    public function testPreservesASkippedOutcomeForCasesWithoutHarnessRuns(string $status): void
    {
        ['observation' => $observation] = $this->readObservation(['status' => $status]);
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

    public function testSelectsTheRequestedCaseWhenASiblingCaseIsSkipped(): void
    {
        ['directory' => $directory, 'reportPath' => $reportPath] = $this->writeTaskReport([
            ['caseId' => 'sibling case', 'status' => 'skipped'],
            ['caseId' => $this->task->caseId, 'meta' => $this->scoredMeta()],
        ]);
        $observation = Report::readTaskObservation($this->task, $reportPath, $directory);

        $this->assertSame('scored', $observation['outcome']);
        $this->assertSame(0.5, $observation['score']);
        $this->assertSame(15, $observation['totalTokens']);
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
     * @param list<array{caseId?: string, status?: string, meta?: array<string, mixed>}> $assertions
     * @return array{directory: string, reportPath: string}
     */
    private function writeTaskReport(array $assertions): array
    {
        $directory = sys_get_temp_dir() . '/pig-eval-report-test-' . bin2hex(random_bytes(4));
        mkdir($directory, 0o700, true);
        $this->temporaryDirectories[] = $directory;

        $results = array_map(fn (array $assertion): array => [
            'ancestorTitles' => [$this->task->evalSet],
            'fullName' => "{$this->task->evalSet} " . ($assertion['caseId'] ?? $this->task->caseId),
            'status' => $assertion['status'] ?? 'passed',
            'title' => $assertion['caseId'] ?? $this->task->caseId,
            'failureMessages' => [],
            'meta' => $assertion['meta'] ?? new stdClass(),
        ], $assertions);
        $count = static fn (string $status): int => count(array_filter($results, static fn (array $r): bool => $r['status'] === $status));

        $reportPath = $directory . '/vitest.json';
        file_put_contents($reportPath, json_encode([
            'numFailedTests' => $count('failed'),
            'numPassedTests' => $count('passed'),
            'numPendingTests' => $count('pending') + $count('skipped') + $count('disabled'),
            'numTodoTests' => $count('todo'),
            'numTotalTests' => count($results),
            'startTime' => 0,
            'success' => $count('failed') === 0,
            'testResults' => [
                [
                    'message' => '',
                    'name' => '/repo/packages/evals/evals/example.docs.eval.php',
                    'status' => $count('failed') === 0 ? 'passed' : 'failed',
                    'assertionResults' => $results,
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        return ['directory' => $directory, 'reportPath' => $reportPath];
    }

    /**
     * @param array{status?: string, meta?: array<string, mixed>} $assertion
     * @return array{directory: string, observation: array<string, mixed>}
     */
    private function readObservation(array $assertion = []): array
    {
        ['directory' => $directory, 'reportPath' => $reportPath] = $this->writeTaskReport([$assertion]);

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
