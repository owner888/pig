<?php

declare(strict_types=1);

namespace Pig\Extensions\Llama;

use Closure;
use Pig\Ai\Extension\ApiKeyAuth;
use Pig\Ai\Extension\ApiKeyCredential;
use Pig\Ai\Extension\AuthResult;
use Pig\Ai\Extension\ModelAuth;
use Pig\Async\AbortSignal;

/**
 * Upstream's `auth.apiKey` of the llama.cpp provider (`extensions/llama/provider.ts`): `/login
 * llama.cpp` asks for the router URL and an optional key, checks the router answers its catalog,
 * and keeps `{type: "api_key", key?, env: {LLAMA_BASE_URL}}`; `LLAMA_BASE_URL` and `LLAMA_API_KEY`
 * configure the same without it. A keyless router is sent the key `local`.
 */
final readonly class LlamaApiKeyAuth implements ApiKeyAuth
{
    #[\Override]
    public function name(): string
    {
        return 'llama.cpp server';
    }

    #[\Override]
    public function login(Closure $onPrompt, ?AbortSignal $signal = null): ?ApiKeyCredential
    {
        $fromEnvironment = getenv('LLAMA_BASE_URL');
        $enteredUrl = $onPrompt('llama.cpp server URL', $fromEnvironment !== false ? $fromEnvironment : LlamaProvider::DEFAULT_LLAMA_SERVER_URL, true, $signal);

        if ($enteredUrl === null) {
            return null;
        }

        $serverUrl = LlamaClient::normalizeLlamaServerUrl(
            trim($enteredUrl) !== '' ? trim($enteredUrl) : ($fromEnvironment !== false && $fromEnvironment !== '' ? $fromEnvironment : LlamaProvider::DEFAULT_LLAMA_SERVER_URL),
        );
        $entered = $onPrompt('API key (optional)', '', true, $signal);

        if ($entered === null) {
            return null;
        }

        $apiKey = trim($entered);
        (new LlamaClient($serverUrl, $apiKey !== '' ? $apiKey : null))->list(signal: $signal);
        return new ApiKeyCredential($apiKey !== '' ? $apiKey : null, ['LLAMA_BASE_URL' => $serverUrl]);
    }

    #[\Override]
    public function check(?ApiKeyCredential $credential): ?string
    {
        $serverUrl = LlamaProvider::resolveServerUrl($credential);

        return $serverUrl !== null ? ($credential !== null ? 'stored credential' : 'LLAMA_BASE_URL') : null;
    }

    #[\Override]
    public function resolve(?ApiKeyCredential $credential): ?AuthResult
    {
        $serverUrl = LlamaProvider::resolveServerUrl($credential);

        if ($serverUrl === null) {
            return null;
        }

        $fromEnvironment = getenv('LLAMA_API_KEY');
        $apiKey = $credential?->key ?? ($fromEnvironment !== false ? $fromEnvironment : null) ?? 'local';

        return new AuthResult(
            new ModelAuth(apiKey: $apiKey, baseUrl: LlamaClient::llamaInferenceUrl($serverUrl)),
            [...($credential?->env ?? []), 'LLAMA_BASE_URL' => $serverUrl],
            $credential !== null ? 'stored credential' : 'LLAMA_BASE_URL',
        );
    }
}
