<?php

declare(strict_types=1);

namespace Pig\Evals;

use InvalidArgumentException;

/**
 * Upstream's `packages/evals/src/report.ts`: reads task reports, pairs `without_docs` and
 * `with_docs` arms, computes pass-rate lift and efficiency deltas, and formats the comparison report.
 */
final class Report
{
    public const string PIG_SESSION_SNAPSHOT_ARTIFACT = 'pigSessionJsonl';

    public const string PI_SESSION_SNAPSHOT_ARTIFACT = 'piSessionJsonl';

    private const string CONTROL = 'without_docs';

    private const string TREATMENT = 'with_docs';

    /** @var list<'without_docs'|'with_docs'> */
    private const array VARIANTS = [self::CONTROL, self::TREATMENT];

    /**
     * @return 'errored'|'skipped'|'pending'|null
     */
    public static function classifyCaseStatus(string $status): ?string
    {
        return match ($status) {
            'failed' => 'errored',
            'skipped', 'todo', 'disabled' => 'skipped',
            'pending' => 'pending',
            default => null,
        };
    }

    /**
     * @return array<string, mixed>
     */
    public static function erroredObservation(EvalTask $task): array
    {
        return [...self::taskIdentity($task), 'outcome' => 'errored'];
    }

    /**
     * @return array<string, mixed>
     */
    public static function readTaskObservation(EvalTask $task, string $reportPath, string $artifactDirectory): array
    {
        $identity = self::taskIdentity($task);

        if (!is_file($reportPath) || !is_readable($reportPath)) {
            return [...$identity, 'outcome' => 'errored'];
        }

        $rawContent = file_get_contents($reportPath);

        if ($rawContent === false) {
            return [...$identity, 'outcome' => 'errored'];
        }

        $rawReport = json_decode($rawContent, true);

        if (!is_array($rawReport) || !isset($rawReport['testResults']) || !is_array($rawReport['testResults'])) {
            return [...$identity, 'outcome' => 'errored'];
        }

        $reportedFullName = "{$task->evalSet} {$task->caseId}";
        $assertions = [];

        foreach ($rawReport['testResults'] as $result) {
            if (!is_array($result) || !isset($result['assertionResults']) || !is_array($result['assertionResults'])) {
                return [...$identity, 'outcome' => 'errored'];
            }

            foreach ($result['assertionResults'] as $assertion) {
                $assertions[] = $assertion;
            }
        }

        if (count($assertions) !== 1 || !is_array($assertions[0])) {
            return [...$identity, 'outcome' => 'errored'];
        }

        $assertion = $assertions[0];

        if (($assertion['fullName'] ?? null) !== $reportedFullName || !is_string($assertion['status'] ?? null)) {
            return [...$identity, 'outcome' => 'errored'];
        }

        $statusOutcome = self::classifyCaseStatus($assertion['status']);

        if ($statusOutcome === 'skipped' || $statusOutcome === 'pending') {
            return [...$identity, 'outcome' => $statusOutcome];
        }

        $meta = $assertion['meta'] ?? null;

        if (!is_array($meta)) {
            return [...$identity, 'outcome' => 'errored'];
        }

        $harness = $meta['harness'] ?? null;
        $run = is_array($harness) ? ($harness['run'] ?? null) : null;

        if (!is_array($run)) {
            return [...$identity, 'outcome' => 'errored'];
        }

        self::persistSession($run, $task, $artifactDirectory);

        $usage = is_array($run['usage'] ?? null) ? $run['usage'] : [];
        $provider = is_string($usage['provider'] ?? null) ? $usage['provider'] : null;
        $model = is_string($usage['model'] ?? null) ? $usage['model'] : null;
        $actualModel = $provider !== null && $model !== null && $provider !== '' && $model !== ''
            ? "{$provider}/{$model}"
            : null;

        if ($actualModel !== $task->model) {
            return [...$identity, 'outcome' => 'errored'];
        }

        $usageMeta = is_array($usage['metadata'] ?? null) ? $usage['metadata'] : [];
        $timings = is_array($run['timings'] ?? null) ? $run['timings'] : [];

        try {
            $metrics = array_filter([
                'inputTokens' => self::optionalMetric($usage['inputTokens'] ?? null, 'inputTokens'),
                'outputTokens' => self::optionalMetric($usage['outputTokens'] ?? null, 'outputTokens'),
                'cacheReadTokens' => self::optionalMetric($usageMeta['cacheReadTokens'] ?? null, 'cacheReadTokens'),
                'cacheWriteTokens' => self::optionalMetric($usageMeta['cacheWriteTokens'] ?? null, 'cacheWriteTokens'),
                'totalTokens' => self::optionalMetric($usage['totalTokens'] ?? null, 'totalTokens'),
                'toolCalls' => self::optionalMetric($usage['toolCalls'] ?? null, 'toolCalls'),
                'totalMs' => self::optionalMetric($timings['totalMs'] ?? null, 'totalMs'),
                'estimatedCostUsd' => self::optionalMetric($usageMeta['estimatedCostUsd'] ?? null, 'estimatedCostUsd'),
            ], static fn (mixed $v): bool => $v !== null);
        } catch (InvalidArgumentException) {
            return [...$identity, 'outcome' => 'errored'];
        }

        $errors = is_array($run['errors'] ?? null) ? $run['errors'] : [];

        if ($statusOutcome === 'errored' || $errors !== []) {
            return [...$identity, ...$metrics, 'outcome' => 'errored'];
        }

        $evalMeta = is_array($meta['eval'] ?? null) ? $meta['eval'] : [];

        try {
            $score = self::validateScore($evalMeta['avgScore'] ?? null);
        } catch (InvalidArgumentException) {
            return [...$identity, ...$metrics, 'outcome' => 'errored'];
        }

        if ($score === null) {
            return [...$identity, ...$metrics, 'outcome' => 'unscored'];
        }

        return [...$identity, ...$metrics, 'outcome' => 'scored', 'score' => $score];
    }

    /**
     * @param list<EvalTask|array<string, mixed>> $expectedRuns
     * @param list<array<string, mixed>> $observations
     * @return array<string, mixed>
     */
    public static function summarizeEvalObservations(string $protocolDigest, array $expectedRuns, array $observations): array
    {
        $groups = self::groupPairs($expectedRuns, $observations);
        $blockedPairs = [];
        $pairsByEvalSet = [];
        $totalsByEvalSet = [];

        foreach ($groups as $group) {
            $evalSet = $group['evalSet'];
            $totalsByEvalSet[$evalSet] = ($totalsByEvalSet[$evalSet] ?? 0) + 1;
            $resolved = self::resolvePair($group);

            if (isset($resolved['blocked'])) {
                $blockedPairs[] = $resolved['blocked'];
            }

            if (isset($resolved['pair'])) {
                $pairsByEvalSet[$evalSet][] = $resolved['pair'];
            }
        }

        ksort($totalsByEvalSet, SORT_STRING);
        $comparisons = [];

        foreach ($totalsByEvalSet as $evalSet => $totalPairs) {
            $pairs = $pairsByEvalSet[$evalSet] ?? [];
            $blockedPairCount = $totalPairs - count($pairs);
            $publishHeadline = $blockedPairCount === 0 && $pairs !== [];
            $controlPassRate = $publishHeadline
                ? count(array_filter($pairs, static fn (array $p): bool => $p['control']['score'] >= 1)) / count($pairs)
                : null;
            $treatmentPassRate = $publishHeadline
                ? count(array_filter($pairs, static fn (array $p): bool => $p['treatment']['score'] >= 1)) / count($pairs)
                : null;

            $comparisons[] = [
                'evalSet' => $evalSet,
                'totalPairs' => $totalPairs,
                'eligiblePairs' => count($pairs),
                'blockedPairs' => $blockedPairCount,
                'controlPassRate' => $controlPassRate,
                'treatmentPassRate' => $treatmentPassRate,
                'lift' => $controlPassRate === null || $treatmentPassRate === null
                    ? null
                    : self::difference($treatmentPassRate, $controlPassRate),
                'flags' => self::comparisonFlags($pairs, $controlPassRate, $treatmentPassRate),
                'totalTokens' => self::summarizeMetric($pairs, 'totalTokens'),
                'toolCalls' => self::summarizeMetric($pairs, 'toolCalls'),
                'totalMs' => self::summarizeMetric($pairs, 'totalMs'),
                'estimatedCostUsd' => self::summarizeMetric($pairs, 'estimatedCostUsd'),
            ];
        }

        return [
            'schemaVersion' => 3,
            'protocolDigest' => $protocolDigest,
            'control' => self::CONTROL,
            'treatment' => self::TREATMENT,
            'comparisons' => $comparisons,
            'blockedPairs' => $blockedPairs,
            'operationalTotals' => [
                self::variantTotals($observations, self::CONTROL),
                self::variantTotals($observations, self::TREATMENT),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $report
     */
    public static function formatEvalComparisonReport(array $report): string
    {
        $comparisons = $report['comparisons'] ?? [];

        if (!is_array($comparisons) || $comparisons === []) {
            return '';
        }

        $lines = ["\x1b[1mDocumentation Eval Comparisons\x1b[22m"];

        foreach ($comparisons as $comparison) {
            $lines[] = "  {$comparison['evalSet']}";
            $lines[] = "         Pairs  {$comparison['eligiblePairs']}/{$comparison['totalPairs']} eligible";

            if ($comparison['lift'] === null) {
                $lines[] = $comparison['blockedPairs'] > 0
                    ? '     Pass rate  withheld because pairs are blocked'
                    : '     Pass rate  unavailable';
            } else {
                $lines[] = sprintf(
                    '     Pass rate  %s pp (with %s, without %s)',
                    self::signed($comparison['lift'] * 100, 1),
                    self::percentage($comparison['treatmentPassRate']),
                    self::percentage($comparison['controlPassRate']),
                );
            }

            if ($comparison['flags'] !== []) {
                $lines[] = '         Flags  ' . implode(', ', $comparison['flags']);
            }

            $lines[] = self::pairedMetric('Tokens', $comparison['totalTokens']);
            $lines[] = self::pairedMetric('Tools', $comparison['toolCalls']);
            $lines[] = self::pairedMetric('Latency', $comparison['totalMs'], 'ms');

            $cost = $comparison['estimatedCostUsd'];

            if ($cost['meanDelta'] === null || $cost['controlMean'] === null || $cost['treatmentMean'] === null) {
                $lines[] = '     Est. cost  unavailable';
            } else {
                $sign = $cost['meanDelta'] >= 0 ? '+' : '-';
                $lines[] = sprintf(
                    '     Est. cost  %s$%.4f (with $%.4f, without $%.4f, %d pairs)',
                    $sign,
                    abs((float) $cost['meanDelta']),
                    (float) $cost['treatmentMean'],
                    (float) $cost['controlMean'],
                    $cost['eligiblePairs'],
                );
            }
        }

        $lines[] = '  Operational totals';

        foreach ($report['operationalTotals'] ?? [] as $totals) {
            $runs = (int) $totals['runs'];
            $tokens = self::operationalMetric($totals['totalTokens'], $runs, static fn (float|int $total): string => "{$total} tokens");
            $tools = self::operationalMetric($totals['toolCalls'], $runs, static fn (float|int $total): string => "{$total} tools");
            $latency = self::operationalMetric($totals['totalMs'], $runs, static fn (float|int $total): string => sprintf('%.2fs', $total / 1000));
            $cost = self::operationalMetric($totals['estimatedCostUsd'], $runs, static fn (float|int $total): string => sprintf('$%.4f cost', $total));
            $lines[] = "    {$totals['variant']}: {$runs} runs, {$tokens}, {$tools}, {$latency}, {$cost}";
        }

        $blockedPairs = $report['blockedPairs'] ?? [];

        if (is_array($blockedPairs) && $blockedPairs !== []) {
            $lines[] = '  Blocked pairs';

            foreach ($blockedPairs as $blocked) {
                $lines[] = sprintf(
                    '    %s/%s/%s/run-%d: %s',
                    $blocked['evalSet'],
                    $blocked['caseId'],
                    $blocked['model'],
                    $blocked['runNumber'],
                    implode('; ', $blocked['reasons']),
                );
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @return array{evalSet: string, caseId: string, variant: 'without_docs'|'with_docs', model: string, runNumber: int}
     */
    private static function taskIdentity(EvalTask $task): array
    {
        return [
            'evalSet' => $task->evalSet,
            'caseId' => $task->caseId,
            'variant' => $task->variant,
            'model' => $task->model,
            'runNumber' => $task->runNumber,
        ];
    }

    /**
     * @param array<string, mixed> $run
     */
    private static function persistSession(array $run, EvalTask $task, string $artifactDirectory): void
    {
        $artifacts = is_array($run['artifacts'] ?? null) ? $run['artifacts'] : [];
        $session = $artifacts[self::PIG_SESSION_SNAPSHOT_ARTIFACT] ?? $artifacts[self::PI_SESSION_SNAPSHOT_ARTIFACT] ?? null;

        if (!is_string($session)) {
            return;
        }

        $identity = json_encode([$task->evalSet, $task->caseId, $task->variant, $task->model, $task->runNumber], JSON_THROW_ON_ERROR);
        $directory = $artifactDirectory . '/' . $task->variant . '/sessions/' . hash('sha256', $identity);

        if (!is_dir($directory)) {
            mkdir($directory, 0o700, true);
        }

        $file = $directory . '/session.jsonl';
        file_put_contents($file, $session);
        chmod($file, 0o600);
    }

    private static function optionalMetric(mixed $value, string $name): int|float|null
    {
        if ($value === null) {
            return null;
        }

        if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value) || $value < 0) {
            throw new InvalidArgumentException("{$name} must be a finite non-negative number.");
        }

        return $value;
    }

    private static function validateScore(mixed $value): int|float|null
    {
        if ($value === null) {
            return null;
        }

        if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value) || $value < 0 || $value > 1) {
            throw new InvalidArgumentException('Eval score must be between 0 and 1.');
        }

        return $value;
    }

    /**
     * @param list<EvalTask|array<string, mixed>> $expectedRuns
     * @param list<array<string, mixed>> $observations
     * @return list<array{evalSet: string, caseId: string, model: string, runNumber: int, expected: array<string, int>, observations: array<string, list<array<string, mixed>>>}>
     */
    private static function groupPairs(array $expectedRuns, array $observations): array
    {
        $groups = [];
        $ensure = static function (array $identity) use (&$groups): string {
            $key = json_encode([$identity['evalSet'], $identity['caseId'], $identity['model'], $identity['runNumber']], JSON_THROW_ON_ERROR);

            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'evalSet' => (string) $identity['evalSet'],
                    'caseId' => (string) $identity['caseId'],
                    'model' => (string) $identity['model'],
                    'runNumber' => (int) $identity['runNumber'],
                    'expected' => [],
                    'observations' => [],
                ];
            }

            return $key;
        };

        foreach ($expectedRuns as $expected) {
            $row = $expected instanceof EvalTask ? $expected->toArray() : $expected;
            $key = $ensure($row);
            $variant = (string) $row['variant'];
            $groups[$key]['expected'][$variant] = ($groups[$key]['expected'][$variant] ?? 0) + 1;
        }

        foreach ($observations as $observation) {
            $key = $ensure($observation);
            $variant = (string) $observation['variant'];
            $groups[$key]['observations'][$variant][] = $observation;
        }

        $list = array_values($groups);
        usort($list, static fn (array $left, array $right): int => strcmp($left['evalSet'], $right['evalSet'])
            ?: strcmp($left['caseId'], $right['caseId'])
            ?: strcmp($left['model'], $right['model'])
            ?: ($left['runNumber'] <=> $right['runNumber']));

        return $list;
    }

    /**
     * @param array{evalSet: string, caseId: string, model: string, runNumber: int, expected: array<string, int>, observations: array<string, list<array<string, mixed>>>} $group
     * @return array{pair?: array{control: array<string, mixed>, treatment: array<string, mixed>}, blocked?: array{evalSet: string, caseId: string, model: string, runNumber: int, reasons: list<string>}}
     */
    private static function resolvePair(array $group): array
    {
        $reasons = [];

        foreach (self::VARIANTS as $variant) {
            $expected = $group['expected'][$variant] ?? 0;
            $observed = $group['observations'][$variant] ?? [];

            if ($expected !== 1) {
                $reasons[] = "{$variant}: design expected 1 run, found {$expected}";
            }

            if (count($observed) !== $expected) {
                $plural = $expected === 1 ? '' : 's';
                $reasons[] = "{$variant}: expected {$expected} observation{$plural}, found " . count($observed);
            }

            if ($expected === 1 && count($observed) === 1 && ($observed[0]['outcome'] ?? null) !== 'scored') {
                $reasons[] = "{$variant}: {$observed[0]['outcome']}";
            }
        }

        if ($reasons !== []) {
            return [
                'blocked' => [
                    'evalSet' => $group['evalSet'],
                    'caseId' => $group['caseId'],
                    'model' => $group['model'],
                    'runNumber' => $group['runNumber'],
                    'reasons' => $reasons,
                ],
            ];
        }

        return [
            'pair' => [
                'control' => $group['observations'][self::CONTROL][0],
                'treatment' => $group['observations'][self::TREATMENT][0],
            ],
        ];
    }

    /**
     * @param list<array{control: array<string, mixed>, treatment: array<string, mixed>}> $pairs
     * @return array{eligiblePairs: int, controlMean: float|int|null, treatmentMean: float|int|null, meanDelta: float|int|null}
     */
    private static function summarizeMetric(array $pairs, string $field): array
    {
        $control = [];
        $treatment = [];

        foreach ($pairs as $pair) {
            $controlValue = $pair['control'][$field] ?? null;
            $treatmentValue = $pair['treatment'][$field] ?? null;

            if ($controlValue === null || $treatmentValue === null) {
                continue;
            }

            $control[] = $controlValue;
            $treatment[] = $treatmentValue;
        }

        $controlMean = self::mean($control);
        $treatmentMean = self::mean($treatment);

        return [
            'eligiblePairs' => count($control),
            'controlMean' => $controlMean,
            'treatmentMean' => $treatmentMean,
            'meanDelta' => $controlMean === null || $treatmentMean === null ? null : self::difference($treatmentMean, $controlMean),
        ];
    }

    /**
     * @param list<int|float> $values
     */
    private static function mean(array $values): float|int|null
    {
        if ($values === []) {
            return null;
        }

        $avg = array_sum($values) / count($values);

        return floor($avg) === $avg ? (int) $avg : $avg;
    }

    private static function difference(float|int $treatment, float|int $control): float|int
    {
        $diff = round((float) $treatment - (float) $control, 12);

        return floor($diff) === $diff ? (int) $diff : $diff;
    }

    /**
     * @param list<array{control: array<string, mixed>, treatment: array<string, mixed>}> $pairs
     * @return list<'no-lift'|'negative-delta'|'control-saturated'|'treatment-saturated'|'flaky'>
     */
    private static function comparisonFlags(array $pairs, float|int|null $controlPassRate, float|int|null $treatmentPassRate): array
    {
        $flags = [];

        if ($controlPassRate !== null && $treatmentPassRate !== null) {
            if ((float) $controlPassRate === (float) $treatmentPassRate) {
                $flags[] = 'no-lift';
            }

            if ((float) $treatmentPassRate < (float) $controlPassRate) {
                $flags[] = 'negative-delta';
            }

            if ((float) $controlPassRate === 1.0) {
                $flags[] = 'control-saturated';
            }

            if ((float) $treatmentPassRate === 1.0) {
                $flags[] = 'treatment-saturated';
            }
        }

        $outcomes = [];

        foreach ($pairs as $pair) {
            $controlKey = json_encode([$pair['control']['caseId'], $pair['control']['variant']], JSON_THROW_ON_ERROR);
            $outcomes[$controlKey][$pair['control']['score'] >= 1 ? 'true' : 'false'] = true;
            $treatmentKey = json_encode([$pair['treatment']['caseId'], $pair['treatment']['variant']], JSON_THROW_ON_ERROR);
            $outcomes[$treatmentKey][$pair['treatment']['score'] >= 1 ? 'true' : 'false'] = true;
        }

        foreach ($outcomes as $values) {
            if (count($values) > 1) {
                $flags[] = 'flaky';

                break;
            }
        }

        return $flags;
    }

    /**
     * @param list<array<string, mixed>> $observations
     * @return array<string, mixed>
     */
    private static function variantTotals(array $observations, string $variant): array
    {
        $runs = array_values(array_filter($observations, static fn (array $o): bool => ($o['variant'] ?? null) === $variant));

        return [
            'variant' => $variant,
            'runs' => count($runs),
            'inputTokens' => self::operationalTotal($runs, 'inputTokens'),
            'outputTokens' => self::operationalTotal($runs, 'outputTokens'),
            'cacheReadTokens' => self::operationalTotal($runs, 'cacheReadTokens'),
            'cacheWriteTokens' => self::operationalTotal($runs, 'cacheWriteTokens'),
            'totalTokens' => self::operationalTotal($runs, 'totalTokens'),
            'toolCalls' => self::operationalTotal($runs, 'toolCalls'),
            'totalMs' => self::operationalTotal($runs, 'totalMs'),
            'estimatedCostUsd' => self::operationalTotal($runs, 'estimatedCostUsd'),
        ];
    }

    /**
     * @param list<array<string, mixed>> $runs
     * @return array{availableRuns: int, total: int|float|null}
     */
    private static function operationalTotal(array $runs, string $field): array
    {
        $values = [];

        foreach ($runs as $run) {
            if (isset($run[$field])) {
                $values[] = $run[$field];
            }
        }

        return [
            'availableRuns' => count($values),
            'total' => $values === [] ? null : array_sum($values),
        ];
    }

    private static function percentage(float|int|null $value): string
    {
        return $value === null ? 'unavailable' : sprintf('%.1f%%', $value * 100);
    }

    private static function signed(float|int $value, int $digits): string
    {
        $prefix = $value >= 0 ? '+' : '';

        return $prefix . number_format((float) $value, $digits, '.', '');
    }

    /**
     * @param array{eligiblePairs: int, controlMean: float|int|null, treatmentMean: float|int|null, meanDelta: float|int|null} $metric
     */
    private static function pairedMetric(string $label, array $metric, string $unit = ''): string
    {
        $padded = str_pad($label, 10, ' ', STR_PAD_LEFT);

        if ($metric['meanDelta'] === null || $metric['controlMean'] === null || $metric['treatmentMean'] === null) {
            return "    {$padded}  unavailable";
        }

        return sprintf(
            '    %s  %s%s (with %.1f%s, without %.1f%s, %d pairs)',
            $padded,
            self::signed($metric['meanDelta'], 1),
            $unit,
            (float) $metric['treatmentMean'],
            $unit,
            (float) $metric['controlMean'],
            $unit,
            $metric['eligiblePairs'],
        );
    }

    /**
     * @param array{availableRuns: int, total: int|float|null} $metric
     * @param \Closure(int|float): string $format
     */
    private static function operationalMetric(array $metric, int $runs, \Closure $format): string
    {
        if ($metric['total'] === null) {
            return "unavailable (0/{$runs} measured)";
        }

        $coverage = $metric['availableRuns'] === $runs ? '' : " ({$metric['availableRuns']}/{$runs} measured)";

        return $format($metric['total']) . $coverage;
    }
}
