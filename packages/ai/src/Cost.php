<?php

declare(strict_types=1);

namespace Pig\Ai;

/**
 * What one request cost, in dollars, broken out the same way tokens are.
 *
 * The four components are catalog estimates. `$total` is "the best available total: provider-reported
 * billed cost when supported, otherwise the catalog estimate" — `$totalFromProvider` says which, as
 * upstream's `totalFromProvider` (OpenRouter's billed cost, #10286), so the components need not add
 * up to it.
 */
final readonly class Cost
{
    public function __construct(
        public float $input = 0.0,
        public float $output = 0.0,
        public float $cacheRead = 0.0,
        public float $cacheWrite = 0.0,
        public float $total = 0.0,
        public bool $totalFromProvider = false,
    ) {
    }

    /** The same components with the provider's billed total. */
    public function withProviderTotal(float $total): self
    {
        return new self($this->input, $this->output, $this->cacheRead, $this->cacheWrite, $total, true);
    }
}
