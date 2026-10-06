<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Tools;

use Pig\Agent\Agent;
use Pig\Agent\AgentTool;
use Pig\CodingAgent\CustomTools\CustomToolSet;
use Pig\CodingAgent\Hooks\HookedTool;
use Pig\CodingAgent\Hooks\HookRunner;
use Pig\CodingAgent\Prompt\ContextFile;
use Pig\CodingAgent\Prompt\Skill;
use Pig\CodingAgent\Prompt\SystemPrompt;

/**
 * Which tools the model is offered, and what the system prompt says about them.
 *
 * There were three copies of "built-ins beside the custom tools, wrapped with the hooks, and a
 * system prompt to match": `CodingAgent::create()`, the `onChange()` listener for a tool that
 * arrives after startup, and `/reload`. They had already drifted — `/reload` built its custom
 * tool set without the `--tools` filter, so a reload put back every tool the command line had
 * left out. This is the one place now, and it is also what upstream's active-tool set needed:
 * `setActive()` narrows what the model is offered **without unregistering anything**, so an
 * extension can switch a tool off for a phase of work and back on later.
 *
 * Narrowing is by name, and a name nothing registered is ignored rather than refused —
 * upstream's rule, and the right one for a list an extension builds from what it hopes is there.
 */
final class ToolLoadout
{
    /** @var list<string>|null null is every registered tool */
    private ?array $active = null;

    /**
     * @param list<string>            $builtIn      built-in tool names, as `ToolSet` knows them
     * @param list<ContextFile>|null  $contextFiles
     * @param list<Skill>             $skills
     */
    public function __construct(
        private readonly Agent $agent,
        private readonly string $cwd,
        private readonly array $builtIn,
        private CustomToolSet $customTools,
        private readonly HookRunner $hooks,
        private ?array $contextFiles = null,
        private array $skills = [],
        private readonly ?string $systemPrompt = null,
        private readonly ?string $appendSystemPrompt = null,
    ) {
    }

    /**
     * What `/reload` read off disk this time. The active set survives a reload by name, so an
     * extension that narrowed it does not see its choice undone by somebody else's `/reload`.
     *
     * @param list<ContextFile>|null $contextFiles
     * @param list<Skill>            $skills
     */
    public function reloaded(CustomToolSet $customTools, ?array $contextFiles, array $skills): void
    {
        $this->customTools = $customTools;
        $this->contextFiles = $contextFiles;
        $this->skills = $skills;
        $this->apply();
    }

    /** Hand the agent the tools that are active now, and a system prompt that names them. */
    public function apply(): void
    {
        $builtIn = $this->active === null
            ? $this->builtIn
            : array_values(array_filter($this->builtIn, fn (string $name): bool => in_array($name, $this->active, true)));

        $custom = array_values(array_filter(
            $this->customTools->agentTools(),
            fn (AgentTool $tool): bool => $this->active === null || in_array($tool->definition()->name, $this->active, true),
        ));

        $this->agent->setTools(HookedTool::wrap([...ToolSet::create($this->cwd, $builtIn), ...$custom], $this->hooks));

        [$snippets, $guidelines] = $this->customTools->promptContributions($this->active);
        $this->agent->setSystemPrompt(SystemPrompt::build(
            $this->cwd,
            $builtIn,
            SystemPrompt::resolve($this->systemPrompt),
            SystemPrompt::resolve($this->appendSystemPrompt),
            $this->contextFiles,
            $this->skills,
            $snippets,
            $guidelines,
        ));
    }

    /** Every registered tool's name, active or not. Upstream's `getAllTools()`, by name. @return list<string> */
    public function names(): array
    {
        return [...$this->builtIn, ...$this->customTools->names()];
    }

    /** @return list<string> what the model is offered now */
    public function activeNames(): array
    {
        return $this->active === null
            ? $this->names()
            : array_values(array_filter($this->names(), fn (string $name): bool => in_array($name, $this->active, true)));
    }

    /**
     * Offer the model only these. Takes effect from the next request, because `AgentLoop` asks
     * for the tools before every model call.
     *
     * @param list<string> $names
     */
    public function setActive(array $names): void
    {
        $known = $this->names();
        $this->active = array_values(array_unique(array_filter($names, static fn (string $name): bool => in_array($name, $known, true))));
        $this->apply();
    }

    /**
     * Name, description and schema of every registered tool. Upstream's `getAllTools()`.
     *
     * @return list<array{name: string, description: string, parameters: array<string, mixed>, active: bool}>
     */
    public function describe(): array
    {
        $active = $this->activeNames();
        $tools = [...ToolSet::create($this->cwd, $this->builtIn), ...$this->customTools->agentTools()];

        return array_map(static fn (AgentTool $tool): array => [
            'name' => $tool->definition()->name,
            'description' => $tool->definition()->description,
            'parameters' => $tool->definition()->parameters,
            'active' => in_array($tool->definition()->name, $active, true),
        ], $tools);
    }
}
