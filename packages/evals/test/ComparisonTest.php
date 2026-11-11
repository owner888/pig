<?php

declare(strict_types=1);

namespace Pig\Evals\Test;

use PHPUnit\Framework\TestCase;
use Pig\Evals\Report;
use Pig\Tui\Ansi;

/**
 * Ported from upstream's `packages/evals/test/comparison.test.ts`.
 */
final class ComparisonTest extends TestCase
{
    public function testComputesPairedLiftAndEfficiencyDeltas(): void
    {
        $observations = [
            self::scored('without_docs', 1, 0, ['totalTokens' => 100, 'toolCalls' => 3, 'totalMs' => 1000]),
            self::scored('with_docs', 1, 1, ['totalTokens' => 120, 'toolCalls' => 2, 'totalMs' => 800]),
            self::scored('without_docs', 2, 1, ['totalTokens' => 200]),
            self::scored('with_docs', 2, 1, ['totalTokens' => 180]),
        ];

        $report = Report::summarizeEvalObservations('digest', self::expectedFor([1, 2]), $observations);
        $comparison = $report['comparisons'][0];

        $this->assertSame('tool access', $comparison['evalSet']);
        $this->assertSame(2, $comparison['totalPairs']);
        $this->assertSame(2, $comparison['eligiblePairs']);
        $this->assertSame(0, $comparison['blockedPairs']);
        $this->assertSame(0.5, $comparison['controlPassRate']);
        $this->assertSame(1, $comparison['treatmentPassRate']);
        $this->assertSame(0.5, $comparison['lift']);
        $this->assertSame(['eligiblePairs' => 2, 'controlMean' => 150, 'treatmentMean' => 150, 'meanDelta' => 0], $comparison['totalTokens']);
        $this->assertSame(['eligiblePairs' => 2, 'controlMean' => 2.5, 'treatmentMean' => 2, 'meanDelta' => -0.5], $comparison['toolCalls']);
    }

    public function testFailsClosedForIncompleteAndErroredPairsWhileRetainingTotals(): void
    {
        $observations = [
            self::scored('without_docs', 1, 0),
            self::errored('with_docs', 1, 120),
            self::scored('without_docs', 2, 1, ['totalTokens' => 200]),
        ];

        $report = Report::summarizeEvalObservations('digest', self::expectedFor([1, 2]), $observations);
        $comparison = $report['comparisons'][0];

        $this->assertSame(2, $comparison['totalPairs']);
        $this->assertSame(0, $comparison['eligiblePairs']);
        $this->assertSame(2, $comparison['blockedPairs']);
        $this->assertNull($comparison['lift']);

        $this->assertSame(1, $report['blockedPairs'][0]['runNumber']);
        $this->assertSame(['with_docs: errored'], $report['blockedPairs'][0]['reasons']);
        $this->assertSame(2, $report['blockedPairs'][1]['runNumber']);
        $this->assertSame(['with_docs: expected 1 observation, found 0'], $report['blockedPairs'][1]['reasons']);
        $this->assertSame(['availableRuns' => 2, 'total' => 300], $report['operationalTotals'][0]['totalTokens']);
    }

    public function testBlocksDuplicateObservationsAndKeepsMissingMetricsDistinctFromZero(): void
    {
        $withoutTokens = self::scored('without_docs', 1, 1);
        unset($withoutTokens['totalTokens']);

        $report = Report::summarizeEvalObservations('digest', self::expectedFor([1]), [
            $withoutTokens,
            $withoutTokens,
            self::scored('with_docs', 1, 1, ['totalTokens' => 0]),
        ]);

        $this->assertSame(['without_docs: expected 1 observation, found 2'], $report['blockedPairs'][0]['reasons']);
        $this->assertSame(['availableRuns' => 0, 'total' => null], $report['operationalTotals'][0]['totalTokens']);
        $this->assertSame(['availableRuns' => 1, 'total' => 0], $report['operationalTotals'][1]['totalTokens']);
    }

    public function testFormatsBlockedComparisonsAndOperationalTotals(): void
    {
        $report = Report::summarizeEvalObservations('digest', self::expectedFor([1, 2]), [
            self::scored('without_docs', 1, 1),
            self::scored('with_docs', 1, 1),
        ]);

        $formatted = Ansi::strip(Report::formatEvalComparisonReport($report));
        $this->assertStringContainsString('Documentation Eval Comparisons', $formatted);
        $this->assertStringContainsString('Pass rate  withheld because pairs are blocked', $formatted);
        $this->assertStringContainsString('without_docs: 1 runs', $formatted);
    }

    /**
     * @param 'without_docs'|'with_docs' $variant
     * @param array<string, int|float> $metrics
     * @return array<string, mixed>
     */
    private static function scored(string $variant, int $runNumber, int|float $score, array $metrics = []): array
    {
        return [
            'evalSet' => 'tool access',
            'caseId' => 'create',
            'variant' => $variant,
            'model' => 'fixture/model',
            'runNumber' => $runNumber,
            'outcome' => 'scored',
            'score' => $score,
            'totalTokens' => 100,
            'toolCalls' => 2,
            'totalMs' => 1000,
            'estimatedCostUsd' => 0.01,
            ...$metrics,
        ];
    }

    /**
     * @param 'without_docs'|'with_docs' $variant
     * @return array<string, mixed>
     */
    private static function errored(string $variant, int $runNumber, ?int $totalTokens = null): array
    {
        return array_filter([
            'evalSet' => 'tool access',
            'caseId' => 'create',
            'variant' => $variant,
            'model' => 'fixture/model',
            'runNumber' => $runNumber,
            'outcome' => 'errored',
            'totalTokens' => $totalTokens,
        ], static fn (mixed $v): bool => $v !== null);
    }

    /**
     * @param list<int> $runNumbers
     * @return list<array{evalSet: string, caseId: string, variant: 'without_docs'|'with_docs', model: string, runNumber: int}>
     */
    private static function expectedFor(array $runNumbers): array
    {
        $expected = [];

        foreach ($runNumbers as $runNumber) {
            foreach (['without_docs', 'with_docs'] as $variant) {
                $expected[] = [
                    'evalSet' => 'tool access',
                    'caseId' => 'create',
                    'variant' => $variant,
                    'model' => 'fixture/model',
                    'runNumber' => $runNumber,
                ];
            }
        }

        return $expected;
    }
}
