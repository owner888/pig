<?php

declare(strict_types=1);

namespace Pig\Ai;

/** Token counts for one request, and what they cost. */
final readonly class Usage
{
    public function __construct(
        public int $input = 0,
        public int $output = 0,
        public int $cacheRead = 0,
        public int $cacheWrite = 0,
        public int $totalTokens = 0,
        public Cost $cost = new Cost(),
    ) {
    }

    /**
     * The same counts, priced.
     *
     * Upstream's calculateCost() writes into the usage it is given; this returns a new one,
     * since Usage is immutable here.
     */
    public function withCost(Model $model): self
    {
        $input = $model->pricing->input / 1_000_000 * $this->input;
        $output = $model->pricing->output / 1_000_000 * $this->output;
        $cacheRead = $model->pricing->cacheRead / 1_000_000 * $this->cacheRead;
        $cacheWrite = $model->pricing->cacheWrite / 1_000_000 * $this->cacheWrite;

        return new self(
            $this->input,
            $this->output,
            $this->cacheRead,
            $this->cacheWrite,
            $this->totalTokens,
            new Cost($input, $output, $cacheRead, $cacheWrite, $input + $output + $cacheRead + $cacheWrite),
        );
    }

    /** Anthropic reports the components and leaves the total to the caller. */
    public function withTotalTokens(): self
    {
        return new self(
            $this->input,
            $this->output,
            $this->cacheRead,
            $this->cacheWrite,
            $this->input + $this->output + $this->cacheRead + $this->cacheWrite,
            $this->cost,
        );
    }
}
