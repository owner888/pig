<?php

declare(strict_types=1);

namespace Pig\Ai\Extension;

use Closure;
use Pig\Async\AbortSignal;

/**
 * Upstream's `envApiKeyAuth(name, envVars)` (`auth/helpers.ts`): "Standard api-key auth: a stored
 * credential key wins, otherwise the first set env var resolves. Includes a `login` that prompts for
 * the key." — the api-key half of every built-in provider whose key is one environment variable.
 */
final readonly class EnvApiKeyAuth implements ApiKeyAuth
{
    /** @param list<string> $envVars */
    public function __construct(
        private string $name,
        private array $envVars,
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    /** `interaction.prompt({type: "secret", message: "Enter <name>"})`. */
    public function login(Closure $onPrompt, ?AbortSignal $signal = null): ?ApiKeyCredential
    {
        $key = $onPrompt("Enter {$this->name}", '', false, $signal);

        return $key === null ? null : new ApiKeyCredential($key);
    }

    public function check(?ApiKeyCredential $credential): ?string
    {
        return $this->resolve($credential)?->source;
    }

    public function resolve(?ApiKeyCredential $credential): ?AuthResult
    {
        if ($credential?->key !== null && $credential->key !== '') {
            return new AuthResult(new ModelAuth($credential->key), $credential->env, 'stored credential');
        }

        foreach ($this->envVars as $envVar) {
            $value = getenv($envVar);

            if (is_string($value) && $value !== '') {
                return new AuthResult(new ModelAuth($value), source: $envVar);
            }
        }

        return null;
    }
}
