<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Hooks\Results;

/**
 * Whether to go through with the jump.
 *
 * Upstream can also hand back a summary of the branch being left, which pig cannot use
 * yet: branch summarisation is not ported. See CLAUDE.md.
 */
final readonly class SessionBeforeTreeResult
{
    public function __construct(public bool $cancel = false)
    {
    }
}
