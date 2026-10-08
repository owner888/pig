<?php

declare(strict_types=1);

namespace Pig\Ai;

/** Token counts for one request, and what they cost. */
final readonly class Usage
{
    /**
     * @param int|null $reasoning reasoning tokens, **a subset of `output`** rather than an addition
     *        to it. Set — possibly to 0 — by a provider that reports the split, null by one that
     *        does not, as upstream leaves the field out.
     * @param int|null $cacheWrite1h the part of `cacheWrite` written with a one-hour lifetime. Only
     *        Anthropic reports it; it is priced differently, see `withCost()`.
     *
     * Last, after `cost`, so the positional calls that predate them keep working.
     */
    public function __construct(
        public int $input = 0,
        public int $output = 0,
        public int $cacheRead = 0,
        public int $cacheWrite = 0,
        public int $totalTokens = 0,
        public Cost $cost = new Cost(),
        public ?int $reasoning = null,
        public ?int $cacheWrite1h = null,
    ) {
    }

    /**
     * The same counts, priced.
     *
     * Upstream's calculateCost() writes into the usage it is given; this returns a new one,
     * since Usage is immutable here.
     *
     * **A one-hour cache write is not priced at the cache-write rate.** That rate is the
     * five-minute one; Anthropic charges twice the base input price for the one-hour kind, and
     * upstream prices it that way from the input rate rather than from a separate price-list
     * column — so `Pricing` needs none either.
     */
    public function withCost(Model $model): self
    {
        $longWrite = $this->cacheWrite1h ?? 0;
        $shortWrite = $this->cacheWrite - $longWrite;

        $input = $model->pricing->input / 1_000_000 * $this->input;
        $output = $model->pricing->output / 1_000_000 * $this->output;
        $cacheRead = $model->pricing->cacheRead / 1_000_000 * $this->cacheRead;
        $cacheWrite = $model->pricing->cacheWrite / 1_000_000 * $shortWrite
            + $model->pricing->input * 2 / 1_000_000 * $longWrite;

        return new self(
            $this->input,
            $this->output,
            $this->cacheRead,
            $this->cacheWrite,
            $this->totalTokens,
            new Cost($input, $output, $cacheRead, $cacheWrite, $input + $output + $cacheRead + $cacheWrite),
            $this->reasoning,
            $this->cacheWrite1h,
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
            $this->reasoning,
            $this->cacheWrite1h,
        );
    }
}
