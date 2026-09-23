<?php

declare(strict_types=1);

namespace Pig\CodingAgent;

use Pig\Agent\Agent;
use Pig\Agent\AgentOptions;
use Pig\Agent\ThinkingLevel;
use Pig\Ai\AssistantMessage;
use Pig\Ai\Model;
use Pig\Ai\Models;
use Pig\Ai\ToolResultMessage;
use Pig\Ai\UserMessage;
use Pig\CodingAgent\CustomTools\CustomToolSet;
use Pig\CodingAgent\Hooks\HookedTool;
use Pig\CodingAgent\Hooks\HookRunner;
use Pig\CodingAgent\Session\BashExecution;
use Pig\CodingAgent\Session\CompactionSummary;
use Pig\CodingAgent\Prompt\ContextFile;
use Pig\CodingAgent\Prompt\Skill;
use Pig\CodingAgent\Prompt\SystemPrompt;
use Pig\CodingAgent\Tools\ToolSet;

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
        ?string $systemPrompt = null,
        ?string $appendSystemPrompt = null,
        ?array $contextFiles = null,
        ThinkingLevel $thinking = ThinkingLevel::Off,
        array $skills = [],
        ?HookRunner $hooks = null,
        ?CustomToolSet $customTools = null,
    ): Agent {
        $agent = new Agent(new AgentOptions(
            apiKey: $apiKey ?? self::apiKey($model),
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
     * The conversation as the model should see it.
     *
     * The agent's own default keeps the three LLM message types and drops everything
     * else, which is right for an app message nobody meant to send. A `!` command is the
     * exception: the whole point of typing one is that its output reaches the model, so
     * it is turned into a user message here. A `!!` command never becomes one of these
     * in the first place, so it falls through the same filter and stays out.
     *
     * A compaction summary is the same shape for a different reason: it stands in for the
     * messages it replaced, so it has to reach the model as something the model reads.
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
            if ($message instanceof BashExecution || $message instanceof CompactionSummary) {
                $converted[] = new UserMessage($message->toText(), $message->timestamp);

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

    /** The key for this model's provider, from the environment. */
    private static function apiKey(Model $model): ?string
    {
        $name = match ($model->provider) {
            'anthropic' => 'ANTHROPIC_API_KEY',
            default => strtoupper($model->provider) . '_API_KEY',
        };

        $key = getenv($name);

        return $key === false || $key === '' ? null : $key;
    }
}
