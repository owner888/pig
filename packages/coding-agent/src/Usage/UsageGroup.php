<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Usage;

/**
 * Limits that are spent together: Antigravity's "Gemini Models" pool, say. A plan with one pool
 * passes one group with no name, and its meters are drawn without a heading.
 */
final readonly class UsageGroup
{
    /**
     * @param list<UsageMeter> $meters
     * @param string $name the heading; '' for none
     * @param string|null $note a line under the heading: which models share the pool
     * @param string|null $short the status line's name for the group: `Gemini`; the name without one
     */
    public function __construct(
        public array $meters,
        public string $name = '',
        public ?string $note = null,
        public ?string $short = null,
    ) {
    }
}
