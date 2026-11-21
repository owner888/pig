<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Tools;

use Closure;
use Pig\Agent\AgentTool;

/**
 * The tools of a session as a `ToolDefinition::$prepareLoadout` sees them — upstream's
 * `ToolLoadout` interface (pig's `ToolLoadout` is the session's loadout itself).
 */
final readonly class LoadoutView
{
    /**
     * @param list<AgentTool> $declared   the tools declared to the model (the active ones), in order, with their own descriptions
     * @param list<AgentTool> $callable   the tools callable through `AgentSession::executeTool()` (`ToolLoadout::callable()`)
     * @param list<AgentTool> $registered every registered tool
     * @param Closure(string): string $exposure
     * @param Closure(string): ?array<string, mixed> $namespace
     */
    public function __construct(
        public array $declared,
        public array $callable,
        public array $registered,
        private Closure $exposure,
        private Closure $namespace,
    ) {
    }

    /** @return 'direct'|'model-only'|'codemode'|'deferred'|'hidden' upstream's `getExposure()` */
    public function exposure(string $name): string
    {
        return ($this->exposure)($name);
    }

    /** @return array<string, mixed>|null upstream's `getNamespace()` */
    public function namespace(string $name): ?array
    {
        return ($this->namespace)($name);
    }
}
