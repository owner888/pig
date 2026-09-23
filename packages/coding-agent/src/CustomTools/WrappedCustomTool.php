<?php

declare(strict_types=1);

namespace Pig\CodingAgent\CustomTools;

use Closure;
use Pig\Agent\AgentTool;
use Pig\Agent\AgentToolResult;
use Pig\Ai\Tool;
use Pig\Async\AbortSignal;
use Pig\CodingAgent\Hooks\HookContext;

/**
 * A custom tool, as the agent sees it.
 *
 * Upstream's `wrapper.ts`: the one thing it adds is the session context, which the agent
 * loop knows nothing about and cannot pass. Built fresh per call from a closure rather
 * than captured once, because the model changes mid-session and a tool asking which one
 * is answering should not be told about the one that was replaced.
 *
 * Nothing else is added. The definition, the label and the progress callback pass through
 * unchanged, so a custom tool and a built-in one are the same thing to the loop — and to
 * the hooks, which wrap this in turn.
 */
final readonly class WrappedCustomTool implements AgentTool
{
    /** @param Closure(): HookContext $context */
    public function __construct(
        private CustomTool $tool,
        private Closure $context,
    ) {
    }

    /**
     * @param list<LoadedCustomTool>  $loaded
     * @param Closure(): HookContext  $context
     * @return list<AgentTool>
     */
    public static function wrap(array $loaded, Closure $context): array
    {
        return array_map(
            static fn (LoadedCustomTool $one): AgentTool => new self($one->tool, $context),
            $loaded,
        );
    }

    /** The declaration underneath, for a caller that needs the real thing. */
    public function inner(): CustomTool
    {
        return $this->tool;
    }

    public function definition(): Tool
    {
        return new Tool($this->tool->name, $this->tool->description, $this->tool->parameters);
    }

    public function label(): string
    {
        return $this->tool->label;
    }

    public function execute(
        string $toolCallId,
        array $arguments,
        ?AbortSignal $signal = null,
        ?Closure $onUpdate = null,
    ): AgentToolResult {
        // Argument order is upstream's, not `AgentTool`'s: onUpdate and the context come
        // before the signal there, and a custom tool is written against the documented
        // shape rather than against pig's interface.
        return ($this->tool->execute)($toolCallId, $arguments, $onUpdate, ($this->context)(), $signal);
    }
}
