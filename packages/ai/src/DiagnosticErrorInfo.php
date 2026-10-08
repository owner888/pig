<?php

declare(strict_types=1);

namespace Pig\Ai;

/** The error half of a diagnostic — upstream's `DiagnosticErrorInfo`. */
final readonly class DiagnosticErrorInfo
{
    public function __construct(
        public string $message,
        public ?string $name = null,
        public ?string $stack = null,
        public string|int|null $code = null,
    ) {
    }
}
