<?php

declare(strict_types=1);

namespace Pig\Test;

/**
 * Take the machine's provider keys out of the environment for the length of a test.
 *
 * `Auth` reads the environment last, so which models are available — and whether a session can
 * come back to the model it was on — depends on what the shell running the suite happens to
 * export. The container this was written in has a `GITHUB_TOKEN`, which is a key for
 * github-copilot and therefore eight models: "no keys anywhere" quietly became "eight models"
 * and a case about filtering stopped being about anything.
 *
 * Put back in `tearDown()` rather than left cleared, because `putenv()` is process-wide and the
 * suite is one process — `RpcClientTest` spawns `bin/pig` with this environment on top of its own.
 *
 * The names are `Stream::envApiKey()`'s, copied rather than read from it: they are a `match` arm
 * there, and a copy that goes stale shows up as a failing test instead of as a case that no
 * longer covers what it says.
 */
trait WithoutProviderKeys
{
    /** @var array<string, string|false> */
    private array $realProviderKeys = [];

    /** @return list<string> */
    private static function providerKeyNames(): array
    {
        return [
            'ANTHROPIC_API_KEY', 'ANTHROPIC_OAUTH_TOKEN',
            'COPILOT_GITHUB_TOKEN', 'GH_TOKEN', 'GITHUB_TOKEN',
            'OPENAI_API_KEY', 'AZURE_OPENAI_API_KEY', 'GEMINI_API_KEY', 'GROQ_API_KEY', 'CEREBRAS_API_KEY',
            'XAI_API_KEY', 'TYPESAFE_API_KEY', 'RADIUS_API_KEY', 'OPENROUTER_API_KEY', 'ZAI_API_KEY', 'MISTRAL_API_KEY',
            'ANT_LING_API_KEY', 'QWEN_TOKEN_PLAN_API_KEY', 'QWEN_TOKEN_PLAN_CN_API_KEY', 'NVIDIA_API_KEY',
            'DEEPSEEK_API_KEY', 'AI_GATEWAY_API_KEY', 'ZAI_CODING_CN_API_KEY', 'MINIMAX_API_KEY', 'MINIMAX_CN_API_KEY',
            'MOONSHOT_API_KEY', 'HF_TOKEN', 'FIREWORKS_API_KEY', 'TOGETHER_API_KEY', 'BASETEN_API_KEY',
            'OPENCODE_API_KEY', 'KIMI_API_KEY', 'META_API_KEY', 'XIAOMI_API_KEY', 'XIAOMI_TOKEN_PLAN_CN_API_KEY',
            'XIAOMI_TOKEN_PLAN_AMS_API_KEY', 'XIAOMI_TOKEN_PLAN_SGP_API_KEY', 'CLOUDFLARE_API_KEY',
            // Vertex: its key, and the ADC file with the project and location that make ADC count.
            'GOOGLE_CLOUD_API_KEY', 'GOOGLE_APPLICATION_CREDENTIALS', 'GOOGLE_CLOUD_PROJECT', 'GCLOUD_PROJECT', 'GOOGLE_CLOUD_LOCATION',
            // Bedrock: every AWS source the ambient check accepts.
            'AWS_PROFILE', 'AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY', 'AWS_BEARER_TOKEN_BEDROCK',
            'AWS_CONTAINER_CREDENTIALS_RELATIVE_URI', 'AWS_CONTAINER_CREDENTIALS_FULL_URI', 'AWS_WEB_IDENTITY_TOKEN_FILE',
        ];
    }

    /** @param list<string> $also other variables to take out and put back with them */
    private function forgetProviderKeys(array $also = []): void
    {
        foreach ([...self::providerKeyNames(), ...$also] as $name) {
            $this->realProviderKeys[$name] = getenv($name);
            putenv($name);
        }
    }

    private function restoreProviderKeys(): void
    {
        foreach ($this->realProviderKeys as $name => $value) {
            putenv($value === false ? $name : $name . '=' . $value);
        }

        $this->realProviderKeys = [];
    }
}
