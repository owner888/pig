<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Session;

/**
 * How full the context window is. Upstream's `ContextUsage`.
 *
 * `tokens` and `percent` are null when nobody knows — straight after a compaction, until a turn
 * has come back and measured the conversation the summary left.
 */
final readonly class ContextUsage
{
    public function __construct(
        public ?int $tokens,
        public int $contextWindow,
        public ?float $percent,
    ) {
    }
}
