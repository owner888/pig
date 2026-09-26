<?php

declare(strict_types=1);

namespace Pig\CodingAgent;

use Closure;
use Pig\Agent\Agent;
use Pig\Agent\AgentOptions;
use Pig\Agent\ThinkingLevel;
use Pig\Ai\AssistantMessage;
use Pig\Ai\Model;
use Pig\Ai\Models;
use Pig\Ai\ToolResultMessage;
use Pig\Ai\UserMessage;
use Pig\CodingAgent\CustomTools\CustomToolApi;
use Pig\CodingAgent\CustomTools\CustomToolLoader;
use Pig\CodingAgent\CustomTools\CustomToolSet;
use Pig\CodingAgent\Hooks\HookedTool;
use Pig\CodingAgent\Hooks\HookLoader;
use Pig\CodingAgent\Hooks\HookRunner;
use Pig\CodingAgent\Session\AgentSession;
use Pig\CodingAgent\Session\BashExecution;
use Pig\CodingAgent\Session\BranchSummary;
use Pig\CodingAgent\Session\CompactionSummary;
use Pig\CodingAgent\Session\HookMessage;
use Pig\CodingAgent\Session\SessionManager;
use Pig\CodingAgent\Prompt\ContextFile;
use Pig\CodingAgent\Prompt\ContextFiles;
use Pig\CodingAgent\Prompt\Skill;
use Pig\CodingAgent\Prompt\Skills;
use Pig\CodingAgent\Prompt\SlashCommands;
use Pig\CodingAgent\Prompt\SystemPrompt;
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
        $agent->setTools(HookedTool::wrap(
            [...ToolSet::create($cwd, $tools), ...($customTools?->agentTools() ?? [])],
            $hooks ?? new HookRunner(),
        ));
        $agent->setThinkingLevel($thinking);
        $agent->setSystemPrompt(SystemPrompt::build(
            $cwd,
            $tools,
            SystemPrompt::resolve($systemPrompt),
            SystemPrompt::resolve($appendSystemPrompt),
            $contextFiles,
            $skills,
        ));

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
     * - **`Auth` and the custom models** arrive built, because `--models` prints the registry and
     *   exits, and it has to see a model somebody just declared without a session file being
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
     * @param list<string>|null $tools which built-in tools the model gets, as `ToolSet` names them.
     *        Null means the default set — upstream's four — and `--read-only` swaps that for the
     *        read-only four rather than narrowing this one.
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
        ?string $skillsDir = null,
        ?array $tools = null,
    ): StartedSession {
        $warnings = [];

        // The order stated at the top of `bin/pig`: what was typed, then the environment, then what
        // was chosen last time, then the built-in default.
        $wanted = $model
            ?? self::fromEnvironment($environment, 'PIG_MODEL')
            ?? $settings->defaultModel()
            ?? 'claude-sonnet-4-5';
        $choice = ModelResolver::parse($wanted);

        if ($choice === null) {
            throw new CodingAgentError("No model matches '{$wanted}'. Try --models for the list.");
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

        $level = $thinking !== null
            ? ThinkingLevel::tryFrom($thinking) ?? ThinkingLevel::Off
            : ($choice->thinking === ThinkingLevel::Off
                ? ($settings->defaultThinkingLevel() ?? ThinkingLevel::Off)
                : $choice->thinking);

        // Clamped rather than refused: a model that cannot reason and a request that asks it to is
        // a request the provider rejects, and nobody typing `--thinking high` meant to be told no.
        if (!$chosen->reasoning) {
            $level = ThinkingLevel::Off;
        }

        // Loaded here rather than left to the prompt builder, so a banner and the system prompt
        // cannot disagree about what was picked up.
        $contextFiles = ContextFiles::load($cwd);
        Timings::mark('contextFiles');

        [$skills, $skillWarnings] = $settings->skillsEnabled()
            ? Skills::load(
                $cwd,
                extraDirs: array_filter([$skillsDir, ...$settings->skillList('customDirectories')]),
                ignored: $settings->skillList('ignoredSkills'),
                only: $settings->skillList('includeSkills'),
            )
            : [[], []];
        Timings::mark('skills');

        foreach ($skillWarnings as $warning) {
            $warnings[] = "skill {$warning->path}: {$warning->message}";
        }

        $fileCommands = SlashCommands::load($cwd);
        Timings::mark('slashCommands');

        // Before the agent as well as before any UI, because the hooks are handed the session file
        // and the agent is handed the hooks.
        $store = $save ? self::store($cwd, $resume, $continue) : null;
        $resumed = $save && ($resume !== null || $continue);
        Timings::mark('session');

        [$loadedHooks, $hookProblems] = $withHooks ? HookLoader::load($cwd, $settings->hooks()) : [[], []];

        foreach ($hookProblems as $problem) {
            $warnings[] = "hook {$problem->toText()}";
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
        $builtIn = $tools ?? ($readOnly ? ToolSet::READ_ONLY : ToolSet::CODING);

        // Held onto rather than left to the loader: it is what a mode later hands the UI to, and it
        // is the object every tool factory closed over.
        $toolApi = new CustomToolApi($cwd);

        [$loadedTools, $toolProblems] = $withTools
            ? CustomToolLoader::load($cwd, $builtIn, $settings->customTools(), api: $toolApi)
            : [[], []];

        foreach ($toolProblems as $problem) {
            $warnings[] = "tool {$problem->toText()}";
        }

        Timings::mark('customTools');
        $customTools = new CustomToolSet($loadedTools, $toolApi);

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

        $session = new AgentSession($agent, $cwd, $store, $settings, $hooks);

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
        );
    }

    /**
     * The session file this run writes to: one that was asked for, the latest, or a new one.
     *
     * @throws CodingAgentError
     */
    private static function store(string $cwd, ?string $resume, bool $continue): SessionManager
    {
        if ($resume === null && $continue) {
            $latest = SessionManager::latestFor($cwd);

            if ($latest === null) {
                throw new CodingAgentError("No earlier session in {$cwd}.");
            }

            $resume = $latest->path;
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

            if ($message instanceof UserMessage
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
