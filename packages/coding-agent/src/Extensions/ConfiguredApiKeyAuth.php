<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Extensions;

use Closure;
use Pig\Ai\Extension\ApiKeyAuth;
use Pig\Ai\Extension\ApiKeyCredential;
use Pig\Ai\Extension\AuthResult;
use Pig\Ai\Extension\ModelAuth;
use Pig\Async\AbortSignal;
use Pig\CodingAgent\ConfigValue;

/**
 * The api-key sign-in of a provider registered in the legacy config form with an `apiKey` — upstream's
 * `composeApiKeyAuth()` for a provider with no inherited auth: a stored key wins, else the configured
 * value, a `ConfigValue` (`$NAME`, `${NAME}`, `!command`, or the key itself) resolved per request with
 * `resolveConfigValueOrThrow()`, so a command runs every time and a value that does not resolve is an
 * error naming why.
 */
final readonly class ConfiguredApiKeyAuth implements ApiKeyAuth
{
    public function __construct(
        private string $provider,
        private string $rawKey,
    ) {
    }

    public function name(): string
    {
        return 'API key';
    }

    /** Upstream's fabricated login: `interaction.prompt({type: "secret", message: "Enter API key"})`. */
    public function login(Closure $onPrompt, ?AbortSignal $signal = null): ?ApiKeyCredential
    {
        $key = $onPrompt('Enter API key', '', false, $signal);

        return $key === null ? null : new ApiKeyCredential($key);
    }

    /**
     * Upstream's `check()`: a stored key is configured; a command is configured without being run; a
     * value with `$NAME` references is configured when every one is set; a literal always is.
     */
    public function check(?ApiKeyCredential $credential): ?string
    {
        if ($credential?->key !== null && $credential->key !== '') {
            return 'stored credential';
        }

        return ConfigValue::isConfigured($this->rawKey) ? 'configured API key' : null;
    }

    public function resolve(?ApiKeyCredential $credential): ?AuthResult
    {
        if ($credential?->key !== null && $credential->key !== '') {
            return new AuthResult(new ModelAuth($credential->key), $credential->env, 'stored credential');
        }

        $key = ConfigValue::resolveOrThrow($this->rawKey, "API key for provider \"{$this->provider}\"");

        return new AuthResult(new ModelAuth($key), source: 'configured API key');
    }
}
