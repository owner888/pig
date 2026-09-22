<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Prompt;

/**
 * Something wrong with a skill, and which file it is wrong in.
 *
 * Collected rather than thrown: one malformed skill in a folder of twenty is not a
 * reason to start without the other nineteen, and a skill that is quietly skipped is a
 * skill whose author never finds out why it does nothing.
 */
final readonly class SkillWarning
{
    public function __construct(public string $path, public string $message)
    {
    }
}
