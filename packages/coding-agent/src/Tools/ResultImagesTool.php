<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Tools;

use Closure;
use Pig\Agent\AgentTool;
use Pig\Agent\AgentToolResult;
use Pig\Agent\PreparesArguments;
use Pig\Agent\ReplayableTool;
use Pig\Agent\ToolReplay;
use Pig\Ai\Tool;
use Pig\Async\AbortSignal;

/**
 * A tool whose pictures are fitted inside the provider's limits on their way out — the image half
 * of upstream's `_afterToolCall()`, which runs `normalizeToolResultImages()` after the
 * `tool_result` hooks so an image a hook put in is fitted too. Tools that make pictures
 * themselves (extensions, MCP servers, screenshot tools) hand back whatever they have, and one
 * oversized image makes the provider refuse the whole conversation.
 *
 * Outside `ExtensionGuardedTool`, for that order. A decorator for `ExtensionGuardedTool`'s reason.
 */
final readonly class ResultImagesTool implements AgentTool, ReplayableTool, PreparesArguments
{
    /** @param Closure(list<mixed>): list<mixed> $normalize */
    public function __construct(
        private AgentTool $tool,
        private Closure $normalize,
    ) {
    }

    /** What is underneath, for a caller that needs the real thing. */
    public function inner(): AgentTool
    {
        return $this->tool;
    }

    public function definition(): Tool
    {
        return $this->tool->definition();
    }

    /** What the tool underneath declares. */
    public function replay(): ToolReplay
    {
        return ToolReplay::of($this->tool);
    }

    /** What the tool underneath makes of the arguments, or the arguments when it reshapes none. */
    #[\Override]
    public function prepareArguments(array $arguments): array
    {
        return $this->tool instanceof PreparesArguments ? $this->tool->prepareArguments($arguments) : $arguments;
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
        $result = $this->tool->execute($toolCallId, $arguments, $signal, $onUpdate);
        $content = ($this->normalize)($result->content);

        return $content === $result->content ? $result : new AgentToolResult($content, $result->details, $result->usage, $result->structuredContent);
    }
}
