<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Tools;

use Closure;
use Pig\Agent\AgentTool;
use Pig\Agent\AgentToolResult;
use Pig\Ai\Tool;
use Pig\Async\AbortSignal;

/**
 * A tool with its description replaced — what a `CustomTool::$prepareLoadout` answer does to
 * the other tools, upstream's `ToolLoadoutChanges.descriptions`.
 *
 * Only the definition's description changes; the name, the schema, the sampling and the
 * execution are the tool's own, so to everything but the model it is the same tool. A
 * decorator for `HookedTool`'s reason: PHP has interfaces where upstream spreads the tool
 * into a literal with one field replaced.
 */
final readonly class DescribedTool implements AgentTool
{
    public function __construct(
        private AgentTool $tool,
        private string $description,
    ) {
    }

    /** What is underneath, for a caller that needs the real thing. */
    public function inner(): AgentTool
    {
        return $this->tool;
    }

    public function definition(): Tool
    {
        $definition = $this->tool->definition();

        return new Tool(
            $definition->name,
            $this->description,
            $definition->parameters,
            $definition->constrainedSampling,
            $definition->typeBox,
        );
    }

    public function label(): string
    {
        return $this->tool->label();
    }

    public function execute(
        string $toolCallId,
        array $arguments,
        ?AbortSignal $signal = null,
        ?Closure $onUpdate = null,
    ): AgentToolResult {
        return $this->tool->execute($toolCallId, $arguments, $signal, $onUpdate);
    }
}
