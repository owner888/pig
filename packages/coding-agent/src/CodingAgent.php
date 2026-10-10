<?php

declare(strict_types=1);

namespace Pig\CodingAgent;

use Closure;
use Pig\Agent\Agent;
use Pig\Agent\AgentOptions;
use Pig\Agent\AgentTool;
use Pig\Agent\ThinkingLevel;
use Pig\Ai\AssistantMessage;
use Pig\Ai\Model;
use Pig\Ai\Models;
use Pig\Ai\SystemMessage;
use Pig\Ai\Tool;
use Pig\Ai\ToolResultMessage;
use Pig\Ai\UserMessage;
use Pig\Ai\Utils\Transcript;
use Pig\CodingAgent\CustomTools\CustomToolApi;
use Pig\CodingAgent\CustomTools\CustomToolLoader;
use Pig\CodingAgent\CustomTools\CustomToolSet;
use Pig\CodingAgent\CustomTools\LoadedCustomTool;
use Pig\CodingAgent\Extensions\ExtensionApi;
use Pig\CodingAgent\Extensions\ExtensionLoader;
use Pig\CodingAgent\Extensions\LoadedExtension;
use Pig\CodingAgent\Extensions\ExtensionError;
use Pig\CodingAgent\Hooks\HookLoader;
use Pig\CodingAgent\Hooks\HookRunner;
use Pig\CodingAgent\Hooks\LoadedHook;
use Pig\CodingAgent\Session\AgentSession;
use Pig\CodingAgent\Session\BashExecution;
use Pig\CodingAgent\Session\BranchSummary;
use Pig\CodingAgent\Session\CompactionSummary;
use Pig\CodingAgent\Session\HookMessage;
use Pig\CodingAgent\Session\SessionManager;
use Pig\CodingAgent\Prompt\ContextFile;
use Pig\CodingAgent\Prompt\ContextFiles;
use Pig\CodingAgent\Prompt\Skill;
use Pig\CodingAgent\Packages\ResolvedPaths;
use Pig\CodingAgent\Prompt\Skills;
use Pig\CodingAgent\Theme\Themes;
use Pig\CodingAgent\Prompt\SlashCommands;
use Pig\CodingAgent\Tools\Shell;
use Pig\CodingAgent\Tools\ToolSelection;
use Pig\CodingAgent\Tools\ToolLoadout;
use Pig\CodingAgent\Tools\ToolSet;
use Throwable;

/**
 * Putting the pieces together: a model, a set of tools, and a prompt built for them.
 *
 * The assembly is the only thing here. `Agent` already runs the conversation and
 * `AgentLoop` already runs the turns; what was missing was the part that knows a coding
 * agent needs the working directory in three places at once — in the tools, so they act
 * on the right files; in the prompt, so the model knows where it is; and in the context
 * file lookup, so a project's own instructions are found.
 */
final class CodingAgent
{
    /**
     * What a session opens on when nothing names a model: not `--model`, not `PIG_MODEL`, not the
     * settings. The one this document and both READMEs have said all along; for a while the code
     * said `gemini-3.8-flash` on `antigravity`, which was a provider that only exists once an
     * extension has loaded and is the extension's default to make, not the core's.
     */
    public const string DEFAULT_MODEL = 'claude-sonnet-4-5';

    /**
     * An agent ready to be prompted.
     *
     * @param list<string>           $tools        tool names, as ToolSet knows them
     * @param Closure(string): ?string|null $getApiKey asked per turn, so an expiring token can
     *                                             be renewed between them — `Auth::apiKey()`
     * @param list<ContextFile>|null $contextFiles discovered from $cwd when not given
     * @param list<Skill>            $skills       what the model may reach for
     * @param HookRunner|null        $hooks        wrapped around the tools, and given the
     *                                             context on its way to the model
     * @param CustomToolSet|null     $customTools  tools loaded from disk, offered to the
     *                                             model alongside the built-in ones
     */
    public static function create(
        Model $model,
        string $cwd,
        array $tools = ToolSet::CODING,
        ?string $apiKey = null,
        ?Closure $getApiKey = null,
        ?string $systemPrompt = null,
        ?string $appendSystemPrompt = null,
        ?array $contextFiles = null,
        ThinkingLevel $thinking = ThinkingLevel::Off,
        array $skills = [],
        ?HookRunner $hooks = null,
        ?CustomToolSet $customTools = null,
    ): Agent {
        $agent = new Agent(new AgentOptions(
            // Nothing passed means nothing decided here: a null key reaches `Stream`, which
            // reads the environment itself. There used to be a second reader of the
            // environment in this file, and having two was the whole problem — it named
            // `ANTHROPIC_API_KEY` where `Stream::envApiKey()` prefers `ANTHROPIC_OAUTH_TOKEN`,
            // so which of the two variables won depended on which door you came in by.
            apiKey: $apiKey,
            // Asked again on every turn, because a token expires mid-conversation and the
            // one that answers is not always the one this started with. `Auth::apiKey()` is
            // what `bin/pig` passes; nothing passed means the key is whatever it was.
            getApiKey: $getApiKey,
            convertToLlm: self::toLlm(...),
            // The `context` event. It runs here rather than in `toLlm` because a hook
            // edits the conversation the agent keeps, not the wire format it becomes:
            // a hook that wants to drop a message should not have to know what an
            // Anthropic content block looks like.
            transformContext: $hooks === null
                ? null
                : static fn (array $messages): array => $hooks->emitContext($messages),
        ));

        $agent->setModel($model);

        // Custom tools go in beside the built-in ones and are then wrapped with them, so
        // a `tool_call` hook guards a tool somebody wrote exactly as it guards `bash`.
        // The built-ins come first: they are what the system prompt lists in that order.
        $agent->setThinkingLevel($thinking);
        $loadout = new ToolLoadout(
            $agent,
            $cwd,
            $tools,
            $customTools ?? new CustomToolSet(),
            $hooks ?? new HookRunner(),
            $contextFiles,
            $skills,
            $systemPrompt,
            $appendSystemPrompt,
        );
        $loadout->apply();

        // The prompt and the tools as the transcript's leading system message — upstream's agent
        // seeds one from `initialState.systemPrompt` and `tools` (`createInitialSystemMessage()`),
        // here with the prompt as its sections so that a later change can replace one of them. An
        // agent driven through an `AgentSession` starts from an empty transcript instead (see
        // `session()`): the session writes the prompt in itself, as the first entry of its file.
        $agent->replaceMessages([new SystemMessage(
            '',
            $loadout->systemPromptSections(),
            array_map(static fn (AgentTool $tool): Tool => Transcript::toToolDeclaration($tool->definition()), $agent->tools()) ?: null,
            null,
            0,
        )]);

        return $agent;
    }

    /**
     * A whole session, assembled the way `bin/pig` assembles one.
     *
     * Upstream's `createAgentSession()`, and the reason it is here rather than left in `bin/pig` is
     * the reason `Cli\Arguments`, `Cli\ModelList` and `Cli\SignIn` are classes: **a script that
     * ends in `exit()` cannot be called twice, so none of this had a test.** Two hundred lines of
     * resolution order — which of `--model`, `PIG_MODEL`, the settings and the built-in default
     * wins, when a thinking level gets clamped, which session file `--continue` picks, what happens
     * to a broken hook — were only ever exercised by running pig and looking.
     *
     * `create()` above builds the `Agent`. This builds everything around it: the model, the store,
     * the hooks, the custom tools, the prompt's inputs, and the `AgentSession` that holds them.
     *
     * What stays with the caller, and why:
     *
     * - **`Auth` and the custom models** arrive built, because `--list-models` prints the registry
     *   and exits, and it has to see a model somebody just declared without a session file being
     *   created on the way past.
     * - **The session picker**, which draws a list on a terminal. `$resume` is a path by the time
     *   it gets here, or null.
     * - **The theme**, which nothing below the UI has an opinion about.
     * - **Printing.** Nothing here writes to a stream; warnings come back in the result and
     *   anything fatal throws, which is what makes the whole thing testable.
     *
     * @param array<string, string|false> $environment `getenv()`, or a fixture — the same shape
     *        `Http\Proxy::fromEnvironment()` takes, and for the same reason: an environment read
     *        inside the thing under test is an environment a test cannot set
     * @param string|null $model    what `--model` said, or null for the environment and settings
     * @param string|null $thinking what `--thinking` said, which beats a `:level` on the model
     * @param string|null $resume   a session file to open, already chosen
     * @param string|null $apiKey   a key for this run only, never written down
     * @param list<string>|null $tools which tools the model gets — `ToolSet` names, custom tools'
     *        names, or patterns with `*` in them (`ToolSelection`). Null means the default set —
     *        upstream's four built-ins plus every custom tool — and `--read-only` swaps the
     *        built-ins for the read-only four rather than narrowing this one. An MCP tool is kept
     *        unless an entry starts with `mcp__`, as upstream keeps it.
     * @param list<string>|null $excludeTools names or patterns to take away, after `$tools`, MCP
     *        tools included — upstream's `--exclude-tools`
     * @param 'all'|'builtin'|null $noTools upstream's `noTools`, for when `$tools` names no set:
     *        `all` starts with no tool at all (`--no-tools`), MCP's included; `builtin` without the
     *        built-ins but with every custom and extension tool (`--no-builtin-tools`). A `$tools`
     *        of only `+name`/`-name` entries then changes an empty default rather than the four
     * @param bool $withPromptTemplates false is upstream's `--no-prompt-templates`: no prompt
     *        template is looked for, the packages' included; an extension's still arrive
     * @param bool $withContextFiles false is upstream's `--no-context-files`: no `AGENTS.md` or
     *        `CLAUDE.md` is read into the prompt
     * @param list<string> $skillPaths upstream's `--skill`: skill files or directories, absolute
     * @param list<string> $promptTemplatePaths upstream's `--prompt-template`, the same for templates
     * @param list<string> $disabledExtensions bundled extensions not to load this run, by their
     *        directory name — `pig-mcp` for `--no-mcp`
     * @param array<string, string> $flags every option the command line carried, for the ones an
     *        extension declared with `registerFlag()` — `bin/pig` hands over the lot and
     *        `ExtensionApi::applyFlags()` keeps what was declared
     * @param array{0: list<LoadedExtension>, 1: list<ExtensionError>}|null $preloadedExtensions
     *        what `ExtensionLoader::load()` already answered, when the caller had to load them
     *        before this — `bin/pig` does, so `--list-models` can show a provider an extension
     *        brings. A factory run twice is an MCP server connected twice, so the one load is
     *        handed in rather than repeated.
     * @param list<string>|null $models what `--models` said, already split on commas: the patterns
     *        this session is narrowed to, whose first entry is what it opens on
     * @param bool $projectTrusted whether `<cwd>/.pig/` may be loaded — `ProjectTrust::resolve()`'s
     *        answer, decided by the caller because deciding it may need a screen. False keeps the
     *        project's hooks, tools, extensions, skills and commands out and says so in a warning.
     *
     * @throws CodingAgentError with a sentence worth showing as-is
     */
    public static function session(
        string $cwd,
        Settings $settings,
        Auth $auth,
        array $environment = [],
        ?string $model = null,
        ?string $thinking = null,
        ?string $apiKey = null,
        ?string $resume = null,
        bool $continue = false,
        bool $save = true,
        bool $readOnly = false,
        bool $withHooks = true,
        bool $withTools = true,
        bool $withSkills = true,
        bool $withExtensions = true,
        ?string $skillsDir = null,
        ?array $tools = null,
        ?array $models = null,
        ?array $extensionPaths = null,
        bool $projectTrusted = true,
        ?array $excludeTools = null,
        array $disabledExtensions = [],
        array $flags = [],
        ?array $preloadedExtensions = null,
        ?ResolvedPaths $packageResources = null,
        ?string $noTools = null,
        bool $withPromptTemplates = true,
        bool $withContextFiles = true,
        array $skillPaths = [],
        array $promptTemplatePaths = [],
    ): StartedSession {
        $warnings = [];

        // Said once, up front, where the loaders below each quietly leave the project's root out.
        // Upstream's sentence: a project whose hooks are not running must say so, or a guard
        // somebody wrote looks broken rather than switched off.
        if (!$projectTrusted && ProjectTrust::hasResources($cwd)) {
            $warnings[] = ProjectTrust::warning();
        }

        // Before the extensions load, so a factory that reads `getSettings()` at load gets them;
        // the flags come after, because which flags exist is only known once they have loaded.
        ExtensionApi::useSettings($settings);

        $cliExtensions = $extensionPaths ?? [];
        [$loadedExtensions, $extensionProblems] = match (true) {
            // A preloaded set first: under `--no-extensions` it is what `-e` named, which still loads.
            $preloadedExtensions !== null => $preloadedExtensions,
            !$withExtensions => [[], []],
            default => ExtensionLoader::load(
                $cwd,
                $settings->extensions(),
                $cliExtensions,
                auth: $auth,
                projectTrusted: $projectTrusted,
                disabled: array_values(array_unique([...$disabledExtensions, ...$settings->disabledExtensions()])),
                packageExtensions: static fn (): array => $packageResources?->enabled('extensions') ?? [],
            ),
        };

        ExtensionApi::applyFlags($flags);

        foreach ($extensionProblems as $problem) {
            $warnings[] = "extension {$problem->toText()}";
        }

        // `--models sonnet:high,'anthropic/*'` narrows the session, which is what upstream's
        // `--models` means. Resolved against the models a key reaches, because a scope holding
        // one that cannot be spoken to is a scope ctrl+p walks into and fails on.
        // Upstream's `parsed.models ?? settingsManager.getEnabledModels()`: the setting is the scope
        // for a session nobody typed one for.
        $models ??= $settings->enabledModels();
        [$scope, $scopeWarnings] = $models === null || $models === []
            ? [[], []]
            : ModelResolver::scope($models, $auth->availableModels());

        foreach ($scopeWarnings as $problem) {
            $warnings[] = $problem;
        }

        if ($model === null && $scope !== []) {
            // The first of the scope is what the session opens on, which is upstream's rule and
            // sits exactly here in the order: below `--model`, which is somebody naming one
            // model, and above the environment. A resumed conversation still wins over both —
            // `restoreSettings()` below puts the file's model back unless `--model` was typed.
            //
            // Unless the remembered default is in the scope: upstream opens on that one, so
            // `enabledModels` narrows ctrl+p without overruling the model chosen last time.
            $savedId = $settings->defaultModel();
            $savedProvider = $settings->defaultProvider();
            $choice = $scope[0];

            foreach ($scope as $scoped) {
                if ($savedId !== null && $scoped->model->id === $savedId && ($savedProvider === null || $scoped->model->provider === $savedProvider)) {
                    $choice = $scoped;

                    break;
                }
            }
        } else {
            // The order stated at the top of `bin/pig`: what was typed, then the environment, then
            // what was chosen last time, then the built-in default.
            $envModel = self::fromEnvironment($environment, 'PIG_MODEL') ?? self::fromEnvironment($environment, 'PI_MODEL');
            $envProvider = self::fromEnvironment($environment, 'PIG_PROVIDER') ?? self::fromEnvironment($environment, 'PI_PROVIDER');
            $typed = $model ?? $envModel;
            $wanted = $typed ?? $settings->defaultModel() ?? self::DEFAULT_MODEL;

            // Provider precedence:
            // 1) Explicit in the model name (e.g. `antigravity/gemini-3.8-flash`)
            // 2) From environment (PIG_PROVIDER / PI_PROVIDER)
            // 3) Stored defaultProvider in settings (when using stored defaultModel)
            $provider = $envProvider ?? ($typed === null ? $settings->defaultProvider() : null);

            if ($provider !== null && !str_contains($wanted, '/')) {
                $choice = ModelResolver::parse($provider . '/' . $wanted) ?? ModelResolver::parse($wanted);
            } else {
                $choice = ModelResolver::parse($wanted);
            }

            if ($choice === null) {
                throw new CodingAgentError("No model matches '{$wanted}'. Try --list-models for the list.");
            }
        }

        if ($choice->warning !== null) {
            $warnings[] = $choice->warning;
        }

        $chosen = $choice->model;

        // After the model, because the provider a runtime key belongs to is that model's. An empty
        // value is refused rather than stored: it would beat the environment and then fail as a
        // missing key, which is the least informative way this could go wrong.
        if ($apiKey !== null) {
            if ($apiKey === '') {
                throw new CodingAgentError('--api-key needs a key after it.');
            }

            $auth->setRuntimeApiKey($chosen->provider, $apiKey);
        }

        $envThinking = self::fromEnvironment($environment, 'PIG_THINKING') ?? self::fromEnvironment($environment, 'PI_REASONING_LEVEL');
        $thinkingArg = $thinking ?? $envThinking;

        // Upstream's order: what was typed, the pattern's own `:level`, the model's own
        // `modelThinkingLevels` entry, `defaultThinkingLevel`, off. (A resumed conversation's
        // recorded level beats all but the first, in `restoreSettings()`.)
        $level = $thinkingArg !== null
            ? ThinkingLevel::tryFrom($thinkingArg) ?? ThinkingLevel::Off
            : ($choice->explicitThinking
                ? $choice->thinking
                : ($settings->modelThinkingLevel($chosen->provider, $chosen->id) ?? $settings->defaultThinkingLevel() ?? ThinkingLevel::Off));

        // Clamped rather than refused: a model that cannot reason and a request that asks it to is
        // a request the provider rejects, and nobody typing `--thinking high` meant to be told no.
        $level = ThinkingLevel::clampedFor($chosen, $level);

        // Loaded here rather than left to the prompt builder, so a banner and the system prompt
        // cannot disagree about what was picked up.
        // Before anything can run a command. Static for `Models::register()`'s reason, which
        // `Shell::useShellPath()` states: `bash`, `!command` and a hook all reach one shell.
        Shell::useShellPath($settings->shellPath());

        [$contextFiles, $contextWarnings] = $withContextFiles ? ContextFiles::loadWithWarnings($cwd) : [[], []];

        foreach ($contextWarnings as $problem) {
            $warnings[] = $problem;
        }


        Timings::mark('contextFiles');

        // Upstream's `--no-skills`, and the reason it is here rather than only in the settings is
        // its two siblings: `--no-hooks` and `--no-tool-files` are flags, and a third loader with a
        // settings switch and no flag is the asymmetry that makes somebody think skills cannot be
        // turned off for one run. The settings still decide when nothing was typed.
        [$skills, $skillWarnings] = $withSkills && $settings->skillsEnabled()
            ? Skills::load(
                $cwd,
                extraDirs: array_filter([$skillsDir, ...$settings->skillList('customDirectories')]),
                ignored: $settings->skillList('ignoredSkills'),
                only: $settings->skillList('includeSkills'),
                // Upstream's five per-root switches. The join between its key names and the root
                // each one governs is written here, once, because this is where the settings are
                // read — `Skills` takes plain values and knows no key names, the same way it takes
                // `ignored` rather than reading `ignoredSkills` itself.
                roots: [
                    'codex-user' => $settings->skillRoot('enableCodexUser'),
                    'claude-user' => $settings->skillRoot('enableClaudeUser'),
                    'claude-project' => $settings->skillRoot('enableClaudeProject'),
                    'pi-user' => $settings->skillRoot('enablePiUser'),
                    'pi-project' => $projectTrusted && $settings->skillRoot('enablePiProject'),
                    // pig's own project root has no settings key (see `Skills`' docblock), so
                    // trust is the only thing that switches it off.
                    'project' => $projectTrusted,
                ],
            )
            : [[], []];
        Timings::mark('skills');

        foreach ($skillWarnings as $warning) {
            $warnings[] = "skill {$warning->path}: {$warning->message}";
        }

        $fileCommands = $withPromptTemplates ? SlashCommands::load($cwd, projectTrusted: $projectTrusted) : [];
        Timings::mark('slashCommands');

        // The packages' skills, prompts and themes — after the local ones, which is the rank
        // upstream gives a package's resource (`ResolvedPaths::rank()`): a skill the person has
        // under the same name is the one the model hears about.
        if ($packageResources !== null) {
            if ($withSkills && $settings->skillsEnabled()) {
                [$packageSkills, $packageSkillWarnings] = Skills::fromFiles($packageResources->enabled('skills'));
                $byName = [];

                foreach ([...$skills, ...$packageSkills] as $skill) {
                    $byName[$skill->name] ??= $skill;
                }

                $skills = array_values($byName);

                foreach ($packageSkillWarnings as $warning) {
                    $warnings[] = "skill {$warning->path}: {$warning->message}";
                }
            }

            if ($withPromptTemplates) {
                $fileCommands = [...$fileCommands, ...SlashCommands::fromFiles($packageResources->enabled('prompts'))];
            }

            Themes::setPackageThemeFiles($packageResources->enabled('themes'));
        }

        // Upstream's `--skill` and `--prompt-template`: files or directories for this run, after
        // everything else (its `additionalSkillPaths`), and loaded under `--no-skills` and
        // `--no-prompt-templates` too — naming one is asking for it.
        if ($skillPaths !== []) {
            $cliSkills = self::cliResources($skillPaths, 'Skill', $warnings);
            [$fromDirectories, $directoryWarnings] = Skills::fromDirectories($cliSkills['dirs']);
            [$fromFiles, $fileWarnings] = Skills::fromFiles($cliSkills['files']);
            $byName = [];

            foreach ([...$skills, ...$fromDirectories, ...$fromFiles] as $skill) {
                $byName[$skill->name] ??= $skill;
            }

            $skills = array_values($byName);

            foreach ([...$directoryWarnings, ...$fileWarnings] as $warning) {
                $warnings[] = "skill {$warning->path}: {$warning->message}";
            }
        }

        if ($promptTemplatePaths !== []) {
            $cliPrompts = self::cliResources($promptTemplatePaths, 'Prompt template', $warnings);
            $fileCommands = [
                ...$fileCommands,
                ...SlashCommands::fromDirectories($cliPrompts['dirs']),
                ...SlashCommands::fromFiles($cliPrompts['files']),
            ];
        }
        Timings::mark('packages');

        // Before the agent as well as before any UI, because the hooks are handed the session file
        // and the agent is handed the hooks.
        $store = $save ? self::store($cwd, $resume, $continue) : null;
        $resumed = $save && ($resume !== null || $continue);
        Timings::mark('session');

        [$loadedHooks, $hookProblems] = $withHooks
            ? HookLoader::load($cwd, $settings->hooks(), projectTrusted: $projectTrusted)
            : [[], []];

        foreach ($hookProblems as $problem) {
            $warnings[] = "hook {$problem->toText()}";
        }


        foreach ($loadedExtensions as $ext) {
            $loadedHooks[] = new LoadedHook($ext->path, $ext->resolved, $ext->api);
        }

        $hooks = new HookRunner($loadedHooks, $cwd, $store);
        Timings::mark('hooks');

        // The built-in names are handed in so a tool that would shadow `bash` is named rather than
        // quietly replacing it.
        //
        // **Four by default, which is upstream's set and not every tool pig has.** `grep`, `find`
        // and `ls` are ported and available by name through `--tools`; they are not on by default
        // because a model with seven tools spends more of every turn deciding between them, and
        // searching through `bash` with `rg` is what the prompt already tells it to do. pig is a
        // minimal agent: see the rule at the top of this file.
        //
        // `defaultTools` changes the four (upstream's `getDefaultTools() ?? DEFAULT_TOOL_NAMES`), and a
        // `--tools` of only `+name`/`-name` entries changes whatever the default was rather than
        // naming the whole set. pig registers every custom tool active, so for them only a `-name`
        // means anything: it takes that tool away.
        if ($tools !== null && ($toolListError = Settings::toolListError($tools)) !== null) {
            throw new CodingAgentError("Invalid tools option: {$toolListError}");
        }

        // Upstream's `noTools ? [] : (getDefaultTools() ?? DEFAULT_TOOL_NAMES)`.
        $defaultTools = $noTools !== null ? [] : $settings->defaultTools();
        $modifiers = $tools !== null && array_filter($tools, Settings::isToolModifier(...)) !== [] ? $tools : null;

        if ($modifiers !== null) {
            $defaultTools = Settings::applyToolModifiers($defaultTools ?? ToolSet::CODING, $modifiers);
            $tools = null;

            // Upstream's `allowedToolNames`: under `all`, what the modifiers selected is the whole set.
            if ($noTools === 'all') {
                $tools = $defaultTools;
            }
        } elseif ($tools === null && $noTools === 'all') {
            $tools = [];
        }

        $selection = $tools === null ? null : new ToolSelection($tools);
        $excluded = $excludeTools === null ? null : new ToolSelection($excludeTools);
        $builtIn = $readOnly ? ToolSet::READ_ONLY : ToolSet::CODING;

        if ($selection !== null) {
            // `--tools` names the whole set: the built-ins it matches, in `ToolSet::ALL`'s order.
            $builtIn = array_values(array_filter(ToolSet::ALL, $selection->allows(...)));
        } elseif ($defaultTools !== null && (!$readOnly || $noTools !== null)) {
            $builtIn = array_values(array_filter(ToolSet::ALL, static fn (string $name): bool => in_array($name, $defaultTools, true)));
        }

        // The custom tools a `defaultTools` or `--tools` modifier took out by name.
        $removedByDefault = [];

        if ($defaultTools !== null && $selection === null) {
            foreach ([...($settings->defaultToolEntries() ?? []), ...($modifiers ?? [])] as $entry) {
                $name = substr($entry, 1);

                if ($entry[0] === '-' && !in_array($name, $defaultTools, true) && !in_array($name, ToolSet::ALL, true)) {
                    $removedByDefault[] = $name;
                }
            }
        }

        if ($excluded !== null) {
            $builtIn = array_values(array_filter($builtIn, static fn (string $name): bool => !$excluded->matches($name)));
        }

        // Held onto rather than left to the loader: it is what a mode later hands the UI to, and it
        // is the object every tool factory closed over.
        $toolApi = new CustomToolApi($cwd);

        [$loadedTools, $toolProblems] = $withTools
            ? CustomToolLoader::load($cwd, $builtIn, $settings->customTools(), api: $toolApi, projectTrusted: $projectTrusted)
            : [[], []];

        foreach ($toolProblems as $problem) {
            $warnings[] = "tool {$problem->toText()}";
        }

        foreach ($loadedExtensions as $ext) {
            foreach ($ext->api->tools() as $tool) {
                $loadedTools[] = new LoadedCustomTool($ext->path, $ext->resolved, $tool);
            }
        }

        Timings::mark('customTools');
        $customTools = new CustomToolSet($loadedTools, $toolApi);

        foreach ($loadedExtensions as $ext) {
            $customTools->adopt($ext);
        }

        // The same two filters over the custom tools, now and every time an extension changes its
        // list — an MCP server's tools arrive after startup, and `--exclude-tools 'mcp__gh__*'`
        // has to reach them then. The filter is kept on the set so `onChange()` need not know.
        if ($selection !== null || $excluded !== null || $removedByDefault !== []) {
            // A `--tools` entry that names nothing anywhere is a typo, and saying so is what
            // stops `--tools raed` from quietly starting with no tools. Read before the filter is
            // applied, or the list would be what the typo left.
            $available = [...ToolSet::ALL, ...$customTools->names()];

            $customTools->keep(static fn (string $name): bool
                => ($selection === null || $selection->allows($name))
                    && ($excluded === null || !$excluded->matches($name))
                    && !in_array($name, $removedByDefault, true));

            foreach ($selection?->unmatched($available) ?? [] as $entry) {
                throw new CodingAgentError(
                    "No tool called '{$entry}'. There is " . implode(', ', $available) . '.',
                );
            }
        }

        $agent = self::create(
            $chosen,
            $cwd,
            $builtIn,
            // Asked per turn rather than resolved once, because an OAuth token expires
            // mid-conversation and `Auth` renews it on the way past.
            getApiKey: $auth->apiKey(...),
            contextFiles: $contextFiles,
            thinking: $level,
            skills: $skills,
            hooks: $hooks,
            customTools: $customTools,
        );
        Timings::mark('agent');

        // A tool that arrives after startup — an MCP server connecting — reaches the model the
        // same way the ones at startup did: beside the built-ins, wrapped with the hooks, and
        // activated on registration as upstream's `_refreshToolRegistry()` does (`refresh()`).
        $loadout = new ToolLoadout($agent, $cwd, $builtIn, $customTools, $hooks, $contextFiles, $skills);
        $customTools->onChange(static fn () => $loadout->refresh());
        $loadout->apply();

        // Upstream's `createAgentSession()` starts the agent with `systemPrompt: ""` and `tools: []`
        // and lets the session declare both: its first prompt carries the whole prompt as a system
        // message, which the session file records as its first entry. `create()`'s seeded leading
        // message would be in the transcript and never in the file, so it is taken out here.
        $agent->replaceMessages([]);

        $session = new AgentSession($agent, $cwd, $store, $settings, $hooks, $fileCommands, $scope, auth: $auth, loadout: $loadout, projectTrusted: $projectTrusted);

        // What the hooks and the custom tools are told about the session is wired by the mode, not
        // here: the interactive one is what has a screen to draw a dialog on, and upstream says the
        // same — each mode provides its own UI.
        if ($resumed && $store !== null) {
            $session->restore($store->messages());

            // And what it was being talked on. `--model` beats the file: that is somebody saying
            // what they want now, where the file says what was true last time.
            $session->restoreSettings(modelWasAskedFor: $model !== null);
        } elseif ($store !== null) {
            // Upstream's two lines, and they were missing here: *"Save initial model and thinking
            // level for new sessions so they can be restored on resume."*
            //
            // The model survived without them by luck — `SessionManager::settings()` falls back to
            // the provider and model on the last assistant message — but **the thinking level has
            // no such fallback**, so a conversation had on `--thinking high` came back on `off`
            // after `--continue`, quietly, and on a reasoning model that changes the answers. See
            // CLAUDE.md.
            //
            // It costs no file for a session nobody had: `append()` holds everything before the
            // first assistant message in memory and flushes it in front once the conversation is
            // worth keeping, so pig already had the mechanism that makes recording this up front
            // safe — it just never made the record.
            $store->appendModelChange($chosen->provider, $chosen->id);
            $store->appendThinkingLevelChange($level->value);
        }

        return new StartedSession(
            $session,
            $chosen,
            $level,
            $contextFiles,
            $skills,
            $fileCommands,
            $hooks,
            $customTools,
            $store,
            $warnings,
            $resumed,
            extensions: $loadedExtensions,
        );
    }

    /**
     * `--skill` or `--prompt-template` paths split into directories and files; one that is neither
     * is upstream's diagnostic, `<kind> path does not exist`.
     *
     * @param list<string> $paths
     * @param list<string> $warnings
     * @return array{dirs: list<string>, files: list<string>}
     */
    private static function cliResources(array $paths, string $kind, array &$warnings): array
    {
        $found = ['dirs' => [], 'files' => []];

        foreach ($paths as $path) {
            if (is_dir($path)) {
                $found['dirs'][] = $path;
            } elseif (is_file($path)) {
                $found['files'][] = $path;
            } else {
                $warnings[] = "{$kind} path does not exist: {$path}";
            }
        }

        return $found;
    }

    /**
     * The session file this run writes to: one that was asked for, the latest, or a new one.
     *
     * @throws CodingAgentError
     */
    private static function store(string $cwd, ?string $resume, bool $continue): SessionManager
    {
        if ($resume === null && $continue) {
            $latestPath = SessionManager::latestPathFor($cwd);

            if ($latestPath === null) {
                throw new CodingAgentError("No earlier session in {$cwd}.");
            }

            $resume = $latestPath;
        }

        if ($resume !== null) {
            $found = SessionManager::find($cwd, $resume);

            if ($found !== null) {
                $resume = $found;
            }
        }

        try {
            return $resume === null ? SessionManager::create($cwd) : SessionManager::open($resume);
        } catch (Throwable $error) {
            // Re-thrown rather than swallowed, and as the sentence it came with: a session file
            // that will not open says why, and the why is the useful half.
            throw new CodingAgentError($error->getMessage(), previous: $error);
        }
    }

    /**
     * One environment variable, or null when it is absent or empty.
     *
     * `getenv()` gives `false` for absent and `''` for set-but-empty, and an empty `PIG_MODEL` is
     * somebody turning it off for one command rather than asking for a model called nothing.
     *
     * @param array<string, string|false> $environment
     */
    private static function fromEnvironment(array $environment, string $name): ?string
    {
        $value = $environment[$name] ?? false;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * The conversation as the model should see it.
     *
     * The agent's own default keeps the three LLM message types and drops everything
     * else, which is right for an app message nobody meant to send. A `!` command is the
     * exception: the whole point of typing one is that its output reaches the model, so
     * it is turned into a user message here. A `!!` command never becomes one of these
     * in the first place, so it falls through the same filter and stays out.
     *
     * A compaction summary is the same shape for a different reason: it stands in for the
     * messages it replaced, so it has to reach the model as something the model reads. A
     * branch summary is the third: it stands in for a branch the model was never shown.
     *
     * Public because it is the whole of what a coding agent adds to the agent's own
     * converter, and the cost of getting it wrong — a summary silently dropped, leaving
     * the model with a conversation that starts nowhere — is not visible from outside.
     *
     * @param list<mixed> $messages
     * @return list<mixed>
     */
    public static function toLlm(array $messages): array
    {
        $converted = [];

        foreach ($messages as $message) {
            if ($message instanceof BashExecution
                || $message instanceof CompactionSummary
                || $message instanceof BranchSummary
            ) {
                $converted[] = new UserMessage($message->toText(), $message->timestamp);

                continue;
            }

            // A hook's message is the fourth, and the only one whose content may be images
            // as well as text, so it goes through whole rather than through `toText()`. An
            // empty one is dropped: a user message with no content is a request every
            // provider rejects, and a hook that meant to say nothing has said nothing.
            if ($message instanceof HookMessage) {
                if (!$message->isEmpty()) {
                    $converted[] = new UserMessage($message->content, $message->timestamp);
                }

                continue;
            }

            // System messages pass through: they carry the prompt and the tool declarations
            // (upstream's `convertToLlm()` keeps `system` with the other three LLM roles).
            if ($message instanceof SystemMessage
                || $message instanceof UserMessage
                || $message instanceof AssistantMessage
                || $message instanceof ToolResultMessage
            ) {
                $converted[] = $message;
            }
        }

        return $converted;
    }

    /**
     * A model by id, from the registry.
     *
     * This used to invent the figures around the id — 200k of context, 64k of output,
     * reasoning on — which was fine for one model and wrong for most. `claude-3-haiku`
     * caps output at 4096 and cannot reason at all, so the invented numbers produced a
     * request the provider rejects, from a flag that looked like it had worked.
     *
     * @throws \InvalidArgumentException when there is no such model
     */
    public static function model(string $id): Model
    {
        return Models::get($id) ?? throw new \InvalidArgumentException(
            "No model called '{$id}'. Only Anthropic's models are in the registry so far.",
        );
    }
}
