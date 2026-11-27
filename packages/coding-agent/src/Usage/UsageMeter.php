<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Usage;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * One limit of a plan: how much of it is left, and when it fills again — "Weekly: 99.2% left ·
 * resets 5d 21h". Always what is *left*, whatever the provider reports, so every bar on one screen
 * reads the same way (the developer's choice): a provider that reports what is used passes
 * `1 - used`.
 */
final readonly class UsageMeter
{
    /**
     * @param string $label the limit's name: `Weekly Limit Remaining`, `Rolling 5h`
     * @param float $remaining 0.0–1.0
     * @param DateTimeImmutable|null $resetsAt when it fills again; null when the provider does not say
     * @param string|null $short the status line's name for it: `5h`, `wk`; the label without one
     * @param string|null $detail a few more words after the reset: `~15d left at this pace`
     */
    public function __construct(
        public string $label,
        public float $remaining,
        public ?DateTimeImmutable $resetsAt = null,
        public ?string $short = null,
        public ?string $detail = null,
    ) {
        if (!($remaining >= 0.0 && $remaining <= 1.0)) {
            throw new InvalidArgumentException("Usage meter '{$label}': remaining is a fraction from 0 to 1, not {$remaining}.");
        }
    }

    /** `99.2`, `100` — one decimal, none when it is whole. */
    public function percentLeft(): string
    {
        $percent = round($this->remaining * 100, 1);

        return (string) (floor($percent) === $percent ? (int) $percent : $percent);
    }
}
