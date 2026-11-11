<?php

declare(strict_types=1);

namespace Pig\Evals;

/** Upstream's `EvalTask` (`packages/evals/src/plan.ts`). */
final readonly class EvalTask
{
    /**
     * @param 'without_docs'|'with_docs' $variant
     */
    public function __construct(
        public string $file,
        public string $fullName,
        public string $evalSet,
        public string $caseId,
        public string $variant,
        public string $model,
        public int $runNumber,
    ) {
    }

    /** @return array{file: string, fullName: string, evalSet: string, caseId: string, variant: 'without_docs'|'with_docs', model: string, runNumber: int} */
    public function toArray(): array
    {
        return [
            'file' => $this->file,
            'fullName' => $this->fullName,
            'evalSet' => $this->evalSet,
            'caseId' => $this->caseId,
            'variant' => $this->variant,
            'model' => $this->model,
            'runNumber' => $this->runNumber,
        ];
    }
}
