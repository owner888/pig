<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Hooks\Results;

use Pig\Ai\Http\Request;

/**
 * The request to send instead — headers added, a body rewritten. Chained: each handler is
 * given what the last one returned, as `context` handlers are, so two extensions can both add
 * a header without knowing about each other.
 */
final readonly class BeforeProviderRequestResult
{
    public function __construct(public Request $request)
    {
    }
}
