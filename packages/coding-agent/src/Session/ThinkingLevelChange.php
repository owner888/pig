<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Session;

use Pig\Agent\ThinkingLevel;
use Pig\Ai\Timestamp;

/**
 * Somebody changed how hard the model thinks, here.
 *
 * `ModelChange`'s twin, and in the file for the same reason: it is part of how this
 * conversation was being had, and resuming it should bring it back.
 *
 * The level is kept as it was written rather than as a `ThinkingLevel`, because a file may
 * name one pig does not have — pi's list could grow first, and a level nobody here recognises
 * is better carried and ignored than turned into a wrong one.
 *
 * Ported from upstream's `ThinkingLevelChangeEntry`.
 */
final readonly class ThinkingLevelChange
{
    public int $timestamp;

    public function __construct(
        public string $level,
        ?int $timestamp = null,
    ) {
        $this->timestamp = $timestamp ?? Timestamp::nowMs();
    }

    /** The level pig knows it as, or null when it is one pig has no name for. */
    public function known(): ?ThinkingLevel
    {
        return ThinkingLevel::tryFrom($this->level);
    }
}
