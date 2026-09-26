<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Hooks\Results;

/**
 * A replacement for what a tool produced.
 *
 * Every field is optional and null means "leave it alone", which is why `isError` is a nullable
 * bool: a plain `false` default would claim, of every result, that somebody had said it was fine.
 *
 * **`isError: true` turns a success into a failure; `false` does not turn a failure into a
 * success.** The asymmetry is the loop's, not this class's: what a failed tool means is decided by
 * `execute()` throwing, and `HookedTool` raises the hook's `true` so the verdict still comes from
 * the one place. In the failure path the tool has already thrown, the hooks are told so they can
 * count what goes wrong, and their answer is not read — rescuing a tool that failed is a second
 * route to "this succeeded", and pig keeps one.
 *
 * Upstream declares this field ("Override isError flag") and its wrapper reads neither direction,
 * so a hook setting it there changes nothing at all.
 */
final readonly class ToolResultEventResult
{
    /** @param list<\Pig\Ai\UserContent>|null $content */
    public function __construct(
        public ?array $content = null,
        public mixed $details = null,
        public ?bool $isError = null,
    ) {
    }
}
