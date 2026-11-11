<?php

declare(strict_types=1);

namespace Pig\Evals;

use InvalidArgumentException;

/**
 * Upstream's `packages/evals/src/plan.ts`: discovers documentation-eval cases and expands them into
 * alternating `(case, variant, model, runNumber)` tasks.
 */
final class Plan
{
    /** @var list<'without_docs'|'with_docs'> */
    public const array DOCUMENTATION_VARIANTS = ['without_docs', 'with_docs'];

    /**
     * @return list<DiscoveredEvalCase>
     */
    public static function parseDiscoveredCases(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new InvalidArgumentException('Discovered eval cases must be an array.');
        }

        $identities = [];
        $cases = [];

        foreach ($value as $item) {
            if (!is_array($item) || !isset($item['name'], $item['file']) || !is_string($item['name']) || !is_string($item['file'])) {
                throw new InvalidArgumentException('Discovered eval case is invalid.');
            }

            $parts = explode(' > ', $item['name']);
            $evalSet = $parts[0] ?? '';
            $caseId = $parts[1] ?? '';

            if (trim($evalSet) === '' || trim($caseId) === '' || count($parts) > 2) {
                throw new InvalidArgumentException("Documentation eval must use \"<eval set> > <case>\": {$item['name']}");
            }

            $identity = json_encode([$evalSet, $caseId], JSON_THROW_ON_ERROR);

            if (isset($identities[$identity])) {
                throw new InvalidArgumentException("Duplicate eval case identity: {$item['name']}");
            }

            $identities[$identity] = true;
            $cases[] = new DiscoveredEvalCase($item['file'], $item['name'], $evalSet, $caseId);
        }

        return $cases;
    }

    /**
     * @param list<DiscoveredEvalCase> $cases
     * @return list<EvalTask>
     */
    public static function createTaskPlan(array $cases, string $model, int $runsPerVariant): array
    {
        if (!str_contains($model, '/') || str_starts_with($model, '/') || str_ends_with($model, '/')) {
            throw new InvalidArgumentException('Model identity must contain a provider and model.');
        }

        if ($runsPerVariant < 1) {
            throw new InvalidArgumentException('Runs per variant must be a positive integer.');
        }

        $tasks = [];

        foreach ($cases as $evalCase) {
            for ($runNumber = 1; $runNumber <= $runsPerVariant; $runNumber++) {
                /** @var list<'without_docs'|'with_docs'> $variants */
                $variants = $runNumber % 2 === 1
                    ? ['without_docs', 'with_docs']
                    : ['with_docs', 'without_docs'];

                foreach ($variants as $variant) {
                    $tasks[] = new EvalTask(
                        $evalCase->file,
                        $evalCase->fullName,
                        $evalCase->evalSet,
                        $evalCase->caseId,
                        $variant,
                        $model,
                        $runNumber,
                    );
                }
            }
        }

        return $tasks;
    }
}
