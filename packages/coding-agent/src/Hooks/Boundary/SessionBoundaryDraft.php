<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Hooks\Boundary;

/**
 * An entry a boundary handler (`agent_before_settle`) wants appended to the session before the
 * run settles — upstream's `SessionBoundaryDraft` union: a custom entry, a custom message, a
 * context edit or a compaction. A marker interface, as every open union here is.
 */
interface SessionBoundaryDraft
{
}
