<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Tools;

use Closure;
use Pig\Agent\AgentTool;
use Pig\Agent\AgentToolResult;
use Pig\Ai\Tool;
use Pig\Async\AbortSignal;

/**
 * A tool whose pictures are fitted inside the provider's limits on their way out — the image half
 * of upstream's `_afterToolCall()`, which runs `normalizeToolResultImages()` after the
 * `tool_result` hooks so an image a hook put in is fitted too. Tools that make pictures
 * themselves (extensions, MCP servers, screenshot tools) hand back whatever they have, and one
 * oversized image makes the provider refuse the whole conversation.
 *
 * Outside `HookedTool`, for that order. A decorator for `HookedTool`'s reason.
 */
final readonly class ResultImagesTool implements AgentTool
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

        return $content === $result->content ? $result : new AgentToolResult($content, $result->details, $result->usage);
    }
}
