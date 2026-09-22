<?php

declare(strict_types=1);

namespace Pig\Agent;

use Closure;
use Pig\Ai\Tool;
use Pig\Async\AbortSignal;

/**
 * A tool the agent can run.
 *
 * Upstream's `AgentTool` extends the wire-level `Tool` and adds `label` and `execute`.
 * Here the wire part is returned by `definition()` rather than inherited, so a tool
 * writes one method instead of restating name, description and schema as three.
 */
interface AgentTool
{
    /** Name, description and argument schema, exactly as the model is shown them. */
    public function definition(): Tool;

    /** Human-readable, for the UI — "Read file" where the model sees "read". */
    public function label(): string;

    /**
     * Run it.
     *
     * Throwing is how a tool reports failure: the loop turns the exception into an error
     * result the model can read and carry on from, so a broken tool does not end the run.
     *
     * @param array<string, mixed>              $arguments decoded and validated against the schema
     * @param Closure(AgentToolResult): void|null $onUpdate progress, for tools that take a while
     */
    public function execute(
        string $toolCallId,
        array $arguments,
        ?AbortSignal $signal = null,
        ?Closure $onUpdate = null,
    ): AgentToolResult;
}
