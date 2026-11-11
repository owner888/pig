<?php

declare(strict_types=1);

namespace Pig\Evals;

/** Upstream's `DiscoveredEvalCase` (`packages/evals/src/plan.ts`). */
final readonly class DiscoveredEvalCase
{
    public function __construct(
        public string $file,
        public string $fullName,
        public string $evalSet,
        public string $caseId,
    ) {
    }

    /** @return array{file: string, fullName: string, evalSet: string, caseId: string} */
    public function toArray(): array
    {
        return [
            'file' => $this->file,
            'fullName' => $this->fullName,
            'evalSet' => $this->evalSet,
            'caseId' => $this->caseId,
        ];
    }
}
