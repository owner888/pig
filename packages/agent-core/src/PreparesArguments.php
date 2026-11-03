<?php

declare(strict_types=1);

namespace Pig\Agent;

/**
 * A tool that reshapes a call's arguments before they are checked against its schema.
 *
 * Upstream's `AgentTool.prepareArguments`, run by `prepareToolCallArguments()` ahead of
 * `validateToolArguments()`: a compatibility shim for arguments that a model, or a session saved
 * before the schema changed, sends in an older or looser shape — `edit`'s top-level
 * `oldText`/`newText`, or its `edits` sent as a JSON string. The result is validated as if the
 * model had sent it.
 *
 * Optional: a tool that does not implement it has its arguments validated as they came. A wrapper
 * implements it and answers what it wraps, or the shim is lost one layer down
 * (`ToolArguments::prepare()`).
 */
interface PreparesArguments
{
    /**
     * @param array<string, mixed> $arguments as the model sent them
     * @return array<string, mixed>
     */
    public function prepareArguments(array $arguments): array;
}
