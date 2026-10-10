<?php

declare(strict_types=1);

/**
 * llama.cpp — local models through the llama.cpp router server.
 *
 * Upstream's built-in `extensions/llama/` (index.ts, client.ts, provider.ts, huggingface.ts, ui.ts),
 * file for file: `LlamaExtension` is index.ts, `LlamaClient` client.ts, `LlamaProvider` and
 * `LlamaApiKeyAuth` provider.ts, `HuggingFaceClient` huggingface.ts, and `LlamaUi`, `LlamaView` and
 * `HuggingFaceSearch` ui.ts.
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
 * The catalog is restored from pig's `models-store.json` at load, and asked of the router again at
 * startup and after `/login` — `CodingAgent\ModelRefresh`, as upstream's registry refreshes it.
 */

use Pig\CodingAgent\Auth;
use Pig\CodingAgent\Extensions\ExtensionApi;
use Pig\Extensions\Llama\LlamaExtension;
use Pig\Extensions\Llama\LlamaProvider;

// Class files beside the entry. Loading them twice, or beside another copy, is the loader's
// business — `DeclaredSymbols` — not this file's.
foreach (['LlamaClient', 'HuggingFaceClient', 'LlamaApiKeyAuth', 'LlamaProvider', 'LlamaUi', 'HuggingFaceSearch', 'LlamaView', 'LlamaExtension'] as $class) {
    require_once __DIR__ . "/{$class}.php";
}

return function (ExtensionApi $pi): void {
    $auth = $pi->auth() ?? Auth::discover();
    $extension = new LlamaExtension($auth, new LlamaProvider());
    // Registering restores the stored catalog (`ModelRefresh`, no network), before anything asks
    // for a model by name; the router is asked at startup and after `/login`, as for any provider
    // with a `refreshModels`.
    $pi->registerProvider($extension->provider->provider);
    $pi->registerCommand('llama', $extension->command(...), 'Manage llama.cpp router models');
};
