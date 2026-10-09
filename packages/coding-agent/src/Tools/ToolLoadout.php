<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Tools;

use Pig\Agent\Agent;
use Pig\Agent\AgentTool;
use Pig\Ai\SystemMessage;
use Pig\Ai\Utils\Text;
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
     * The prompt sections `apply()` last worked out — upstream's `_baseSystemPromptOptions`, built.
     * The loadout no longer writes the prompt into the agent: the session diffs these against the
     * sections the transcript has and sends what changed as a system message
     * (`AgentSession::preparePromptUpdate()`).
     *
     * @var array<string, string>
     */
    private array $sections = [];

    /**
     * Tools of the restored or reloaded loadout that are not registered yet — upstream's
     * `_pendingToolNames`: "such as tools of MCP servers that are still connecting. They are
     * activated when they are registered, and dropped when `setActiveToolsByName()` deactivates a
     * tool or the next agent run starts."
     *
     * @var array<string, true>
     */
    private array $pending = [];

    /** @var list<string>|null every tool registered as of the last `apply()`; null before the first */
    private ?array $registered = null;

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
        // Upstream's `reload()`: "Tools the new extensions register later, such as MCP tools, are
        // pending until then."
        foreach ($this->activeNames() as $name) {
            $this->pending[$name] = true;
        }

        $this->customTools = $customTools;
        $this->contextFiles = $contextFiles;
        $this->skills = $skills;
        $this->apply();
    }

    /**
     * The skills extensions added through `resources_discover`, on top of what was loaded off
     * disk; a same-named one replaces it, as a later root replaces an earlier one in `Skills`.
     *
     * @param list<Skill> $skills
     */
    public function addSkills(array $skills): void
    {
        $byName = [];

        foreach ([...$this->skills, ...$skills] as $skill) {
            $byName[$skill->name] = $skill;
        }

        $this->skills = array_values($byName);
        $this->apply();
    }

    /**
     * Hand the agent the tools that are active now, and work out the system prompt that names them.
     *
     * Upstream's `_applyToolLoadout()` and `_rebuildSystemPrompt()`: the tools go on the agent,
     * whose loop declares any difference from what the transcript says before the next request;
     * the prompt is kept here as sections (`systemPromptSections()`) for the session to send.
     */
    public function apply(): void
    {
        $builtIn = $this->active === null
            ? $this->builtIn
            : array_values(array_filter($this->builtIn, fn (string $name): bool => in_array($name, $this->active, true)));

        $custom = array_values(array_filter(
            $this->customTools->agentTools(),
            fn (AgentTool $tool): bool => $this->active === null || in_array($tool->definition()->name, $this->active, true),
        ));

        $tools = HookedTool::wrap([...ToolSet::create($this->cwd, $builtIn), ...$custom], $this->hooks);
        $this->agent->setTools($this->prepared($tools));

        // Upstream's `_setActiveTools()`: a pending tool that is active now is pending no longer.
        foreach ($this->activeNames() as $name) {
            unset($this->pending[$name]);
        }

        $this->registered = $this->names();

        [$snippets, $guidelines] = $this->customTools->promptContributions($this->active);
        $this->sections = SystemPrompt::sections(
            $this->cwd,
            $builtIn,
            SystemPrompt::resolve($this->systemPrompt),
            SystemPrompt::resolve($this->appendSystemPrompt),
            $this->contextFiles,
            $this->skills,
            $snippets,
            $guidelines,
        );
    }

    /**
     * Upstream's `prepareLoadout` step of `_applyToolLoadout()`: each active custom tool with a
     * `prepareLoadout` is asked, with the whole active list, what to change — descriptions to
     * show in place of the tools' own (`DescribedTool`), and tools to keep callable but not
     * declare (left off what the agent is handed; the asker keeps its own reference). A later
     * answer wins where two name one tool, which is upstream's `Object.assign` order.
     *
     * @param list<AgentTool> $tools
     * @return list<AgentTool>
     */
    private function prepared(array $tools): array
    {
        $descriptions = [];
        $hidden = [];

        foreach ($this->customTools->loaded() as $one) {
            if ($one->tool->prepareLoadout === null || ($this->active !== null && !in_array($one->tool->name, $this->active, true))) {
                continue;
            }

            $changes = ($one->tool->prepareLoadout)($tools);

            foreach ($changes['descriptions'] ?? [] as $name => $description) {
                $descriptions[$name] = $description;
            }

            foreach ($changes['hiddenDeclarations'] ?? [] as $name) {
                $hidden[$name] = true;
            }
        }

        if ($descriptions === [] && $hidden === []) {
            return $tools;
        }

        $out = [];

        foreach ($tools as $tool) {
            $name = $tool->definition()->name;

            if (isset($hidden[$name])) {
                continue;
            }

            $out[] = isset($descriptions[$name]) && $descriptions[$name] !== $tool->definition()->description
                ? new DescribedTool($tool, $descriptions[$name])
                : $tool;
        }

        return $out;
    }

    /**
     * The registry changed after startup — an extension registered or removed a tool, an MCP
     * server connected. Upstream's `_refreshToolRegistry()` without a new loadout: the tools that
     * were active stay active, a tool that was not registered before is activated
     * (`_isActivatedOnRegistration()` — pig has no exposure or `defaultActive` on a registered
     * tool, so that is every new one), and so is a pending tool that is registered now.
     *
     * With every tool active there is nothing to add to. The set is kept by name rather than
     * narrowed to what is registered, so a tool that goes away and comes back is still active.
     */
    public function refresh(): void
    {
        if ($this->active !== null) {
            $names = $this->names();
            $this->active = array_values(array_unique([
                ...$this->active,
                ...array_diff($names, $this->registered ?? []),
                ...array_intersect($names, array_keys($this->pending)),
            ]));
        }

        $this->apply();
    }

    /**
     * Restore the active tools a session transcript declared — the second half of upstream's
     * `_restoreToolsFromTranscript()`. The ones not registered yet are pending; `--tools` and
     * `--exclude-tools` keep out of the pending set what they would keep out of the registry.
     *
     * @param list<string> $names
     */
    public function restore(array $names): void
    {
        $filter = $this->customTools->filter();
        $this->pending = array_fill_keys(
            $filter === null ? $names : array_values(array_filter($names, static fn (string $name): bool => $filter($name))),
            true,
        );
        $this->setActive($names);
    }

    /** Forget the pending tools. Upstream's `_pendingToolNames.clear()`. */
    public function clearPending(): void
    {
        $this->pending = [];
    }

    /** Whether $name is a restored tool waiting to be registered. */
    public function isPending(string $name): bool
    {
        return isset($this->pending[$name]);
    }

    /**
     * The prompt sections for the tools active now, as of the last `apply()`.
     *
     * @return array<string, string>
     */
    public function systemPromptSections(): array
    {
        return $this->sections;
    }

    /** The prompt those sections render to — upstream's `buildSystemPrompt()` of the current options. */
    public function systemPrompt(): string
    {
        return Text::getSystemMessageText(new SystemMessage('', $this->sections));
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
