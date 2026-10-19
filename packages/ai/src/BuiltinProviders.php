<?php

declare(strict_types=1);

namespace Pig\Ai;

use Pig\Ai\Extension\ApiKeyAuth;
use Pig\Ai\Extension\EnvApiKeyAuth;

/**
 * The built-in providers' names and api-key sign-ins — the `name` and `auth.apiKey` of each
 * upstream `providers/<id>.ts` `createProvider()`, which `/login`'s "Sign in with an API key" lists.
 *
 * The variables a key is looked for in are pig's own (`Stream::envApiKeyNames()`), since those are
 * what a request reads; upstream's Anthropic `resolve()` looks at `ANTHROPIC_AUTH_TOKEN` before them,
 * and so does the status here.
 *
 * **Amazon Bedrock, Google Vertex AI and the two Cloudflare providers have no sign-in here.**
 * Upstream's prompt for an AWS profile, ADC, a project and location, or an account and gateway id, and
 * keeps them as the credential's `env`; pig's requests read those from the process environment only,
 * so a stored answer would be a sign-in that does nothing. `apiKeyAuth()` answers null for them and
 * `/login` says they are configured outside pig — upstream's `showAmbientAuthDialog()`.
 */
final class BuiltinProviders
{
    /**
     * Upstream's provider `name`, and its api-key auth's `name` — null for the four above, false for
     * a provider upstream signs in to with an account only.
     *
     * @var array<string, array{0: string, 1: string|false|null}>
     */
    private const array TABLE = [
        'amazon-bedrock' => ['Amazon Bedrock', null],
        'ant-ling' => ['Ant Ling', 'Ant Ling API key'],
        'anthropic' => ['Anthropic', 'Anthropic API key'],
        'azure' => ['Azure', 'Azure OpenAI API key'],
        'baseten' => ['Baseten', 'Baseten API key'],
        'cerebras' => ['Cerebras', 'Cerebras API key'],
        'cloudflare-ai-gateway' => ['Cloudflare AI Gateway', null],
        'cloudflare-workers-ai' => ['Cloudflare Workers AI', null],
        'deepseek' => ['DeepSeek', 'DeepSeek API key'],
        'fireworks' => ['Fireworks', 'Fireworks API key'],
        'github-copilot' => ['GitHub Copilot', 'GitHub Copilot token'],
        'google' => ['Google', 'Gemini API key'],
        'google-vertex' => ['Google Vertex AI', null],
        'groq' => ['Groq', 'Groq API key'],
        'huggingface' => ['Hugging Face', 'Hugging Face token'],
        'kimi-coding' => ['Kimi For Coding', 'Kimi API key'],
        'meta' => ['Meta', 'Meta Model API key'],
        'minimax' => ['MiniMax', 'MiniMax API key'],
        'minimax-cn' => ['MiniMax CN', 'MiniMax CN API key'],
        'mistral' => ['Mistral', 'Mistral API key'],
        'moonshotai' => ['Moonshot AI', 'Moonshot AI API key'],
        'moonshotai-cn' => ['Moonshot AI CN', 'Moonshot AI API key'],
        'nvidia' => ['NVIDIA', 'NVIDIA API key'],
        'openai' => ['OpenAI', 'OpenAI API key'],
        'openai-codex' => ['OpenAI Codex (legacy)', false],
        'opencode' => ['OpenCode Zen', 'OpenCode API key'],
        'opencode-go' => ['OpenCode Go', 'OpenCode API key'],
        'openrouter' => ['OpenRouter', 'OpenRouter API key'],
        'qwen-token-plan' => ['Qwen Token Plan', 'Qwen Token Plan API key'],
        'qwen-token-plan-cn' => ['Qwen Token Plan CN', 'Qwen Token Plan CN API key'],
        'qwen-token-plan-individual' => ['Qwen Token Plan Individual', 'Qwen Token Plan Individual API key'],
        'radius' => ['Radius', 'Radius API key'],
        'together' => ['Together', 'Together API key'],
        'typesafe' => ['TypeSafe', 'TypeSafe API key'],
        'vercel-ai-gateway' => ['Vercel AI Gateway', 'Vercel AI Gateway API key'],
        'xai' => ['xAI', 'xAI API key'],
        'xiaomi' => ['Xiaomi', 'Xiaomi API key'],
        'xiaomi-token-plan-ams' => ['Xiaomi Token Plan AMS', 'Xiaomi Token Plan AMS API key'],
        'xiaomi-token-plan-cn' => ['Xiaomi Token Plan CN', 'Xiaomi Token Plan CN API key'],
        'xiaomi-token-plan-sgp' => ['Xiaomi Token Plan SGP', 'Xiaomi Token Plan SGP API key'],
        'zai' => ['Z.AI', 'Z.AI API key'],
        'zai-coding-cn' => ['Z.AI Coding CN', 'Z.AI Coding CN API key'],
    ];

    /**
     * The built-in providers pig has models for that take an API key — upstream's providers with an
     * `auth.apiKey` — by id.
     *
     * @return list<string>
     */
    public static function apiKeyIds(): array
    {
        return array_values(array_filter(
            array_keys(self::TABLE),
            static fn (string $id): bool => self::TABLE[$id][1] !== false && in_array($id, Models::providers(), true),
        ));
    }

    /** Upstream's provider `name`, or the id for one this table does not know. */
    public static function name(string $id): string
    {
        return self::TABLE[$id][0] ?? $id;
    }

    /** Whether the provider is a built-in one this table knows. */
    public static function has(string $id): bool
    {
        return isset(self::TABLE[$id]);
    }

    /** Its api-key sign-in, or null for a provider configured only from the environment. */
    public static function apiKeyAuth(string $id): ?ApiKeyAuth
    {
        $name = self::TABLE[$id][1] ?? null;

        if (!is_string($name)) {
            return null;
        }

        $envVars = Stream::envApiKeyNames($id);

        return new EnvApiKeyAuth($name, $id === 'anthropic' ? ['ANTHROPIC_AUTH_TOKEN', ...$envVars] : $envVars);
    }
}
