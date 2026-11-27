<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Usage;

/**
 * What an extension's usage source answers (`ExtensionApi::registerUsage()`): the plan, its
 * groups of limits, and a note for what the numbers cannot say — "not connected", "this endpoint
 * needs a paid plan". A failure to fetch is thrown, not reported: pig says it as an error.
 */
final readonly class UsageReport
{
    /**
     * @param list<UsageGroup> $groups
     * @param string|null $plan the plan's name: `Google AI Ultra (g1-ultra-lite-tier)`
     * @param string|null $note said after the plan, or alone when there are no groups
     */
    public function __construct(
        public array $groups = [],
        public ?string $plan = null,
        public ?string $note = null,
    ) {
    }

    /** A report that is only a note: "Not connected. Run /login …". */
    public static function note(string $note): self
    {
        return new self(note: $note);
    }
}
