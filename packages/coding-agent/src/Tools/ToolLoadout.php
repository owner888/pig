<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Tools;

use Closure;
use Pig\Agent\Agent;
use Pig\Agent\AgentTool;
use Pig\Ai\SystemMessage;
use Pig\Ai\Utils\Text;
use Pig\CodingAgent\Extensions\CustomToolSet;
use Pig\CodingAgent\Extensions\WrappedCustomTool;
use Pig\CodingAgent\Extensions\ExtensionGuardedTool;
use Pig\CodingAgent\Extensions\ExtensionRunner;
use Pig\CodingAgent\Prompt\ContextFile;
use Pig\CodingAgent\Prompt\ContextFiles;
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
 *
 * A custom tool registered with `defaultActive: false` (upstream's `ToolDefinition.defaultActive`)
 * is registered but not active: it is left out of "every registered tool" unless the run named it
 * — `--tools` or `defaultTools`, which `$named` answers for — or `setActive()` lists it. That is
 * upstream's `_isActivatedOnRegistration()` beside the `allowedTools(toolName)` and
 * `initialActiveToolNames` halves of `_refreshToolRegistry()`.
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
     * tool of that loadout or the next agent run starts."
     *
     * @var array<string, true>
     */
    private array $pending = [];

    /**
     * "The restored or reloaded loadout that `_pendingToolNames` belongs to" — upstream's
     * `_restoredToolNames` (#11996).
     *
     * @var array<string, true>
     */
    private array $restored = [];

    /** @var list<string>|null every tool registered as of the last `apply()`; null before the first */
    private ?array $registered = null;

    /**
     * @var list<string> the tools that were activated on registration as of the last `apply()` —
     *      upstream's `previousActivatedOnRegistration`, so one whose exposure changes to `direct` or
     *      `model-only` is activated like a new tool
     */
    private array $activatedOnRegistration = [];

    /** @var (Closure(): array<string, array<string, mixed>>)|null upstream's tool options, asked at each `apply()` */
    private ?Closure $toolOptions = null;

    /** @var (Closure(list<mixed>): list<mixed>)|null what a tool's pictures go through after the hooks */
    private ?Closure $resultImages = null;

    /**
     * Whether the run named a tool — `--tools` (by name or pattern, upstream's `allowedTools`) or
     * the resolved `defaultTools` list (upstream's `initialActiveToolNames`). A named tool is
     * active even when it is registered with `defaultActive: false`. Null: nothing was named.
     *
     * @var (Closure(string): bool)|null
     */
    private ?Closure $named = null;

    /**
     * pig's `toolExposure` setting (`Settings::toolExposure()`): names or `*` patterns to
     * exposures, over the ones the tools' definitions give. See `exposure()`.
     *
     * @var array<string, string>
     */
    private array $exposureOverrides = [];

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
        private readonly ExtensionRunner $extensionRunner,
        private ?array $contextFiles = null,
        private array $skills = [],
        private readonly ?string $systemPrompt = null,
        private readonly ?string $appendSystemPrompt = null,
    ) {
    }

    /**
     * What `--tools` or `defaultTools` named — see `$named`. A loadout already applied is applied again.
     *
     * @param (Closure(string): bool)|null $named
     */
    public function useNamed(?Closure $named): void
    {
        $this->named = $named;
        $this->reapply();
    }

    /**
     * `--tools` as upstream's `_allowedTools`: whether an entry matched the tool by name or
     * pattern. Null: no `--tools`. Only `isActivatable()` reads it; `useNamed()` is what activates.
     */
    private ?Closure $allowlist = null;

    /** `--tools`, for `isActivatable()`. A loadout already applied is applied again. */
    public function useAllowlist(?Closure $matches): void
    {
        $this->allowlist = $matches;
        $this->reapply();
    }

    /**
     * Upstream's `_isActivatable()`: "MCP tools the allowlist keeps without matching them are only
     * for codemode and tool_search: they may be declared only when tool_search can load them
     * (non-`direct` exposure and tool_search registered). This also applies to tools restored
     * from the transcript or set by extensions."
     */
    private function isActivatable(string $name): bool
    {
        if ($this->allowlist === null || ($this->allowlist)($name) || !ToolSelection::isMcp($name)) {
            return true;
        }

        return $this->exposure($name) !== 'direct' && in_array('tool_search', $this->names(), true);
    }

    /**
     * The `toolExposure` setting — see `$exposureOverrides`. A loadout already applied is applied again.
     *
     * @param array<string, string> $overrides
     */
    public function useToolExposure(array $overrides): void
    {
        $this->exposureOverrides = $overrides;
        $this->reapply();
    }

    /**
     * Upstream's `_getToolExposure()` — the definition's `exposure`, `direct` for a built-in or a
     * tool that gives none — under pig's `toolExposure` setting: the key naming the tool, else the
     * first `*` pattern that matches it, as the MCP `toolExposure` reads.
     *
     * @return 'direct'|'model-only'|'codemode'|'deferred'|'hidden'
     */
    public function exposure(string $name): string
    {
        if (isset($this->exposureOverrides[$name])) {
            return $this->exposureOverrides[$name];
        }

        foreach ($this->exposureOverrides as $pattern => $exposure) {
            if (str_contains((string) $pattern, '*') && fnmatch((string) $pattern, $name, FNM_NOESCAPE)) {
                return $exposure;
            }
        }

        return $this->customTools->find($name)?->exposure ?? 'direct';
    }

    /** Upstream's `_isDeclarable()`: "whether activating the tool declares it to the model". */
    private function isDeclarable(string $name): bool
    {
        return in_array($this->exposure($name), ['direct', 'model-only'], true);
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

        $this->restored = array_fill_keys($this->activeNames(), true);

        $this->customTools = $customTools;
        $this->contextFiles = $contextFiles;
        $this->skills = $skills;
        $this->apply();
    }

    /** @return list<Skill> the skills the prompt names now, extensions' included */
    public function skills(): array
    {
        return $this->skills;
    }

    /** @return list<ContextFile> the context files the prompt carries — read off disk when none were handed in, as the prompt does */
    public function contextFiles(): array
    {
        return $this->contextFiles ?? ContextFiles::load($this->cwd);
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
     * Upstream's `_buildRuntime()` options for the built-in tools — `read`'s image settings,
     * `bash`'s command prefix — asked each time the tools are built, so a setting changed in the
     * session reaches the next build. A loadout already applied is applied again.
     *
     * @param Closure(): array<string, array<string, mixed>> $options
     */
    public function useToolOptions(Closure $options): void
    {
        $this->toolOptions = $options;
        $this->reapply();
    }

    /**
     * Upstream's image half of `_afterToolCall()`: every tool's result, after the `tool_result`
     * hooks, goes through $normalize. A loadout already applied is applied again.
     *
     * @param Closure(list<mixed>): list<mixed> $normalize
     */
    public function useResultImages(Closure $normalize): void
    {
        $this->resultImages = $normalize;
        $this->reapply();
    }

    private function reapply(): void
    {
        if ($this->registered !== null) {
            $this->apply();
        }
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
        $active = $this->activeNames();
        [$tools, $builtIn] = $this->built($active);
        $declared = $this->prepared($tools, $active);
        $this->agent->setTools($declared);
        $declaredNames = array_map(static fn (AgentTool $tool): string => $tool->definition()->name, $declared);
        // Upstream's `skillFileReadTool`: the tool the skills hint names — `read`, else `bash` — and
        // `indirect` (none named) when the reader is active but hidden behind another tool. With no
        // reader at all the skills are left out, as nothing could load them.
        $skillReader = array_values(array_intersect(['read', 'bash'], $declaredNames))[0]
            ?? (array_intersect(['read', 'bash'], $active) !== [] ? 'indirect' : null);

        // Upstream's `_setActiveTools()`: a pending tool that is active now is pending no longer.
        foreach ($active as $name) {
            unset($this->pending[$name]);
        }

        $this->registered = $this->names();
        $this->activatedOnRegistration = array_values(array_filter($this->registered, $this->isActivatedOnRefresh(...)));

        [$snippets, $guidelines] = $this->customTools->promptContributions($active);
        $this->sections = SystemPrompt::sections(
            $this->cwd,
            $builtIn,
            SystemPrompt::resolve($this->systemPrompt),
            SystemPrompt::resolve($this->appendSystemPrompt),
            $this->contextFiles,
            $this->skills,
            $snippets,
            $guidelines,
            $skillReader,
        );
    }

    /**
     * The tools of these names as the agent is handed them — the built-ins made, the custom ones
     * beside them, all wrapped with the hooks — and the built-ins' names among them.
     *
     * @param list<string> $names
     * @return array{0: list<AgentTool>, 1: list<string>}
     */
    private function built(array $names): array
    {
        $builtIn = array_values(array_filter($this->builtIn, static fn (string $name): bool => in_array($name, $names, true)));

        $custom = array_values(array_filter(
            $this->customTools->agentTools(),
            static fn (AgentTool $tool): bool => in_array($tool->definition()->name, $names, true),
        ));

        // A registered tool with a built-in's name replaces the built-in, as upstream's does: an
        // extension that registers `bash` is offering its own `bash`, and the model is told one.
        $customNames = array_map(static fn (AgentTool $tool): string => $tool->definition()->name, $custom);
        $builtIn = array_values(array_filter($builtIn, static fn (string $name): bool => !in_array($name, $customNames, true)));

        $tools = ExtensionGuardedTool::wrap([...ToolSet::create($this->cwd, $builtIn, $this->toolOptions !== null ? ($this->toolOptions)() : []), ...$custom], $this->extensionRunner);

        if ($this->resultImages !== null) {
            $tools = array_map(fn (AgentTool $tool): AgentTool => new ResultImagesTool($tool, $this->resultImages), $tools);
        }

        return [$tools, $builtIn];
    }

    /**
     * Upstream's `_getCallableTools()`: "tools callable through `ctx.executeTool()`: the active
     * `direct` tools and every registered `codemode` or `deferred` tool" — a `model-only` tool is
     * the model's alone, and a `hidden` one nobody's.
     *
     * @param list<string>|null $active the active tools' names, the current ones by default
     * @return list<AgentTool>
     */
    public function callable(?array $active = null): array
    {
        $active ??= $this->activeNames();

        return $this->built(array_values(array_filter($this->names(), function (string $name) use ($active): bool {
            $exposure = $this->exposure($name);

            return $exposure === 'codemode' || $exposure === 'deferred' || ($exposure === 'direct' && in_array($name, $active, true));
        })))[0];
    }

    /**
     * Upstream's `prepareLoadout` step of `_applyToolLoadout()`: each active custom tool with a
     * `prepareLoadout` is asked, with the loadout (`LoadoutView`), what to change — descriptions to
     * show in place of the tools' own (`DescribedTool`), and tools to keep callable but not
     * declare (left off what the agent is handed; the asker keeps its own reference). A later
     * answer wins where two name one tool, which is upstream's `Object.assign` order.
     *
     * @param list<AgentTool> $tools
     * @param list<string>    $active the active tools' names
     * @return list<AgentTool>
     */
    private function prepared(array $tools, array $active): array
    {
        $descriptions = [];
        $hidden = [];
        $view = null;

        foreach ($this->customTools->loaded() as $one) {
            if ($one->tool->prepareLoadout === null || !in_array($one->tool->name, $active, true)) {
                continue;
            }

            $view ??= new LoadoutView(
                $tools,
                $this->callable($active),
                $this->built($this->names())[0],
                $this->exposure(...),
                fn (string $name): ?array => $this->customTools->find($name)?->namespace,
            );
            $changes = ($one->tool->prepareLoadout)($view);

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
     * were active stay active; a tool that was not activated on registration before and is now —
     * a new one, or one whose exposure changed to `direct` or `model-only` — is activated when it
     * is `direct` or `model-only` and is not `defaultActive: false` or the run named it (upstream's
     * `allowedTools(toolName) && _isDeclarable()`); and so is a pending tool that is registered now.
     *
     * With no narrowed set, `activeNames()` already works that out from what is registered. The
     * set is kept by name rather than narrowed to what is registered, so a tool that goes away and
     * comes back is still active.
     */
    public function refresh(): void
    {
        if ($this->active !== null) {
            $names = $this->names();
            $this->active = array_values(array_unique([
                ...$this->active,
                ...array_filter(array_diff($names, $this->activatedOnRegistration), $this->isActivatedOnRefresh(...)),
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
     * @param list<string> $recorded the tools the transcript recorded, without the ones registered
     *        since — the loadout an extension has to narrow for the pending tools to be dropped
     */
    public function restore(array $names, array $recorded): void
    {
        $filter = $this->customTools->filter();
        $this->pending = array_fill_keys(
            $filter === null ? $names : array_values(array_filter($names, static fn (string $name): bool => $filter($name))),
            true,
        );
        $this->restored = array_fill_keys($recorded, true);
        $this->setActive($names);
    }

    /** Forget the restored loadout and its pending tools, as a new restore begins by doing. */
    public function forgetRestored(): void
    {
        $this->pending = [];
        $this->restored = [];
    }

    /** Whether $name is a tool of the restored or reloaded loadout. */
    public function isRestored(string $name): bool
    {
        return isset($this->restored[$name]);
    }

    /** The registered custom tools' names — every registered tool that is not a built-in. @return list<string> */
    public function customToolNames(): array
    {
        return $this->customTools->names();
    }

    /**
     * Upstream's `_isActivatedOnRegistration()`: a registered custom tool that is declarable
     * (`direct` or `model-only`) and was not registered with `defaultActive: false`.
     */
    public function isActivatedOnRegistration(string $name): bool
    {
        $tool = $this->customTools->find($name);

        return $tool !== null && $this->isDeclarable($name) && $tool->defaultActive !== false;
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

    /**
     * What the model is offered now. With no narrowed set, every registered tool that is active
     * by default (`isActivatedByDefault()`).
     *
     * @return list<string>
     */
    public function activeNames(): array
    {
        // Upstream's `_applyToolLoadout()`: a `hidden` tool is never active, whoever names it, and
        // nor is one that is not activatable (`isActivatable()`).
        $visible = array_values(array_filter($this->names(), fn (string $name): bool => $this->exposure($name) !== 'hidden' && $this->isActivatable($name)));

        return $this->active === null
            ? array_values(array_filter($visible, $this->isActivatedByDefault(...)))
            : array_values(array_filter($visible, fn (string $name): bool => in_array($name, $this->active, true)));
    }

    /**
     * Whether a registered tool is active without anybody calling `setActive()`: every built-in
     * here (`$builtIn` is already what the run selected), a custom tool unless it was registered
     * with `defaultActive: false`, and such a tool too when `--tools` or `defaultTools` named it.
     * Upstream's `_isActivatedOnRegistration()` or `allowedTools(toolName)` / `initialActiveToolNames`.
     */
    /** What `refresh()` activates: a declarable tool that registering activates, or that the run named. */
    private function isActivatedOnRefresh(string $name): bool
    {
        return $this->isDeclarable($name) && $this->isActivatedByDefault($name);
    }

    private function isActivatedByDefault(string $name): bool
    {
        if ($this->isDeclarable($name) && $this->customTools->find($name)?->defaultActive !== false) {
            return true;
        }

        return $this->named !== null && ($this->named)($name);
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
     * @return list<array{name: string, description: string, parameters: array<string, mixed>, active: bool, exposure: string, namespace?: array<string, mixed>}>
     */
    public function describe(): array
    {
        $active = $this->activeNames();
        $tools = [...ToolSet::create($this->cwd, $this->builtIn), ...$this->customTools->agentTools()];

        return array_map(function (AgentTool $tool) use ($active): array {
            $reaches = $tool instanceof WrappedCustomTool ? $tool->inner()->reachesTools : null;
            $namespace = $tool instanceof WrappedCustomTool ? $tool->inner()->namespace : null;

            return [
                'name' => $tool->definition()->name,
                'description' => $tool->definition()->description,
                'parameters' => $tool->definition()->parameters,
                'active' => in_array($tool->definition()->name, $active, true),
                'exposure' => $this->exposure($tool->definition()->name),
                // Upstream's `...(definition.namespace ? { namespace } : {})` and the same for `reachesTools`.
                ...($namespace === null ? [] : ['namespace' => $namespace]),
                ...($reaches === null ? [] : ['reachesTools' => $reaches]),
            ];
        }, $tools);
    }
}
