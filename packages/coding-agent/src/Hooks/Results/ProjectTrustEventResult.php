<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Hooks\Results;

use InvalidArgumentException;

/**
 * A `project_trust` handler's answer: `yes`, `no`, or `undecided` to leave it to the next one.
 * `remember` saves a yes or a no in `trust.json`, as choosing "always" at the prompt would.
 */
final readonly class ProjectTrustEventResult
{
    public const array DECISIONS = ['yes', 'no', 'undecided'];

    public function __construct(
        public string $trusted,
        public bool $remember = false,
    ) {
        if (!in_array($trusted, self::DECISIONS, true)) {
            throw new InvalidArgumentException(
                "A project_trust answer is one of " . implode(', ', self::DECISIONS) . ", not \"{$trusted}\"",
            );
        }
    }

    public function decided(): bool
    {
        return $this->trusted !== 'undecided';
    }
}
