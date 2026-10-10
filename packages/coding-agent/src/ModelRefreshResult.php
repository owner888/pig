<?php

declare(strict_types=1);

namespace Pig\CodingAgent;

use Throwable;

/** Upstream's `ModelsRefreshResult`: whether the caller's signal ended it, and each provider's failure. */
final readonly class ModelRefreshResult
{
    /** @param array<string, Throwable> $errors by provider id */
    public function __construct(
        public bool $aborted,
        public array $errors,
    ) {
    }
}
