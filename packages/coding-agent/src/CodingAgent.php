<?php

declare(strict_types=1);

namespace Pig\CodingAgent;

use Pig\Agent\Agent;
use Pig\Agent\AgentOptions;
use Pig\Agent\ThinkingLevel;
use Pig\Ai\Api;
use Pig\Ai\Model;
use Pig\CodingAgent\Prompt\ContextFile;
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
    ): Agent {
        $agent = new Agent(new AgentOptions(apiKey: $apiKey ?? self::apiKey($model)));

        $agent->setModel($model);
        $agent->setTools(ToolSet::create($cwd, $tools));
        $agent->setThinkingLevel($thinking);
        $agent->setSystemPrompt(SystemPrompt::build(
            $cwd,
            $tools,
            SystemPrompt::resolve($systemPrompt),
            SystemPrompt::resolve($appendSystemPrompt),
            $contextFiles,
        ));

        return $agent;
    }

    /**
     * A model described well enough to talk to.
     *
     * Upstream ships a generated registry of several hundred. There is none here yet, so
     * a caller names the model and gets sensible figures for the rest; a registry arrives
     * when something needs to choose between models rather than be handed one.
     */
    public static function model(string $id, string $provider = 'anthropic'): Model
    {
        return match ($provider) {
            'anthropic' => new Model(
                id: $id,
                name: $id,
                api: Api::AnthropicMessages,
                provider: 'anthropic',
                baseUrl: 'https://api.anthropic.com',
                contextWindow: 200_000,
                maxTokens: 64_000,
                reasoning: true,
                input: ['text', 'image'],
            ),
            default => throw new \InvalidArgumentException("No provider '{$provider}'. Only anthropic so far."),
        };
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
