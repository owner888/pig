<?php

declare(strict_types=1);

/**
 * llama.cpp — local models through the llama.cpp router server.
 *
 * Upstream's built-in `extensions/llama/` (index.ts, client.ts, provider.ts, huggingface.ts, ui.ts),
 * file for file: `LlamaExtension` is index.ts, `LlamaClient` client.ts, `LlamaProvider` and
 * `LlamaApiKeyAuth` provider.ts, `HuggingFaceClient` huggingface.ts, and `LlamaUi`, `LlamaView` and
 * `HuggingFaceSearch` ui.ts. `RefreshModelsContext` is the one type of upstream's model registry the
 * provider needs and pig's core does not have.
 *
 * What it brings:
 *
 * - **the `llama.cpp` provider** — the router's loaded and sleeping models (and, with router autoload,
 *   its unloaded presets) as chat models on `openai-completions` at `<router>/v1`, and every one of them
 *   as a classifier with the same id;
 * - **`/login llama.cpp`** — the router URL (`http://127.0.0.1:8080` by default) and an optional API
 *   key, kept as an `api_key` credential with `env.LLAMA_BASE_URL`; `LLAMA_BASE_URL` and
 *   `LLAMA_API_KEY` configure the same without it;
 * - **`/llama`** — load or unload a model, download one from Hugging Face, Escape to stop either.
 *
 * The catalog is restored from pig's `models-store.json` at load, and asked of the router again in the
 * background at session start and after `/login`, as upstream's registry refreshes it.
 */

use Pig\Ai\Extension\ApiKeyCredential;
use Pig\CodingAgent\Auth;
use Pig\CodingAgent\Extensions\ExtensionApi;
use Pig\Async\AbortSignal;
use Pig\Extensions\Llama\LlamaExtension;
use Pig\Extensions\Llama\LlamaProvider;

// Class files beside the entry. Loading them twice, or beside another copy, is the loader's
// business — `DeclaredSymbols` — not this file's.
foreach (['LlamaClient', 'HuggingFaceClient', 'RefreshModelsContext', 'LlamaApiKeyAuth', 'LlamaProvider', 'LlamaUi', 'HuggingFaceSearch', 'LlamaView', 'LlamaExtension'] as $class) {
    require_once __DIR__ . "/{$class}.php";
}

return function (ExtensionApi $pi): void {
    $auth = $pi->auth() ?? Auth::discover();
    $extension = null;
    // Upstream's interactive mode refreshes the provider after `/login`; pig's has no registry
    // refresh to call, so the sign-in says so here — with the credential, which `auth.json` does not
    // hold yet when the flow returns.
    $provider = new LlamaProvider(static function (ApiKeyCredential $credential) use (&$extension): void {
        $extension?->refreshInBackground($credential);
    });
    $extension = new LlamaExtension($auth, $provider);
    $pi->registerProvider($provider->provider);

    // The stored catalog, before anything asks for a model by name.
    $extension->refresh(false, AbortSignal::never());

    $pi->on('session_start', static function () use ($extension): void {
        $extension->refreshInBackground();
    });

    $pi->registerCommand('llama', $extension->command(...), 'Manage llama.cpp router models');
};
