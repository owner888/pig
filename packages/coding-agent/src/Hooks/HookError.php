<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Hooks;

/**
 * A hook that failed, and enough to find it.
 *
 * Not an exception: a handler throwing is a fact about one hook, not a reason to end the
 * run, so the throw is caught at the boundary and becomes one of these. The one place
 * that is not true is `tool_call`, where a hook that cannot answer must not be read as
 * consent — see `HookedTool`.
 */
final readonly class HookError
{
    public function __construct(
        public string $hookPath,
        public string $event,
        public string $error,
    ) {
    }

    public function toText(): string
    {
        return "{$this->hookPath} ({$this->event}): {$this->error}";
    }
}
