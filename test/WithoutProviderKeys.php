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
            'OPENAI_API_KEY', 'GEMINI_API_KEY', 'GROQ_API_KEY', 'CEREBRAS_API_KEY',
            'XAI_API_KEY', 'OPENROUTER_API_KEY', 'ZAI_API_KEY', 'MISTRAL_API_KEY',
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
