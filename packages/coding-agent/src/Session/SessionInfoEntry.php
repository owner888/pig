<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Session;

use Pig\Ai\Timestamp;

/**
 * Session metadata (e.g., user-defined display name or auto-summarized title).
 *
 * Set via `/name` or `pi.setSessionName()` in extensions.
 *
 * Ported from upstream's `SessionInfoEntry`.
 */
final readonly class SessionInfoEntry
{
    public int $timestamp;

    public function __construct(
        public string $name,
        ?int $timestamp = null,
    ) {
        $this->timestamp = $timestamp ?? Timestamp::nowMs();
    }
}
