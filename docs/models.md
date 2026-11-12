# Choose a Model

For a built-in provider, start with `/login`, then choose a model with `/model`. Use custom model configuration only when pig does not already include the provider or endpoint you need.

## Choose a connection

| What you have | Recommended setup |
|---|---|
| A supported subscription | Sign in through `/login` |
| A provider API key | Store it through `/login` or set its environment variable |
| A local GGUF model | Connect pig to the llama.cpp router |
| An OpenAI-, Anthropic-, or Google-compatible endpoint | Add it to `models.json` |
| A provider with a custom protocol or authentication flow | Build or install a provider extension |

Browse the [model catalog](https://pigagent.dev/models) for current providers, model IDs, capabilities, context limits, and pricing. pig ships a bundled catalog generated from models.dev, and keeps each built-in provider's list current from pigagent.dev: checked at most every four hours, saved in `models-store.json`, and used only when it is newer than the bundled one (`PIG_MODEL_CATALOG_URL` names another server, `off` turns it off). `pig update --models` refreshes every provider's catalog now, into `models-store.json`; a provider whose login has gone stale is skipped with a warning. With an OpenRouter key, only the models that key may use are listed, with its limits and prices. Providers that can list their own models, such as the llama.cpp router, are refreshed when `/model` or `/scoped-models` opens, and their lists are cached in `models-store.json` in the agent directory so they stay available offline.

## Authenticate

Run `/login` and select a provider. pig stores credentials in [`auth.json`](/docs/latest/configuration#agent-directory). Run `/logout` to remove stored credentials for a provider.

You can instead provide an API key through the provider's environment variable. This is useful in CI and other environments where pig should not write credentials. [Provider Authentication](/docs/latest/providers) lists the variables and cloud-provider setup.

When several credential sources are configured, pig uses a runtime `--api-key` first, then a stored `auth.json` credential, an `apiKey` from `models.json`, and finally the provider's environment variables or ambient cloud credentials. Provider extensions can define their own authentication behavior.

Keep `auth.json` and any credential commands private. Project settings and extensions can execute inside the pig process after you trust a project. Review [Security](/docs/latest/security) before loading configuration from an untrusted directory.

## Select a model

Run `/model` to search available models. The picker shows models whose providers have usable authentication. Press `Ctrl+S` on a model to save it as the default for new sessions.

Run `/thinking` to select the thinking level for the current model. Press `Ctrl+S` there to save the startup level. pig limits the choices to levels supported by the selected model.

`Ctrl+P` cycles through available models. Use `/scoped-models` to control that cycle and save the selection, or configure model patterns through [Settings](/docs/latest/settings#model-cycling).

A session records model and thinking-level changes. Resuming the session restores them without changing defaults for new sessions.

## Connect local models

pig integrates directly with the llama.cpp router. The router discovers GGUF files and loads models on demand. pig's `/llama` command manages the router, while `/model` selects one of its loaded models.

Follow [Local Models with llama.cpp](/docs/latest/llama-cpp) for server startup, model layout, downloads, and connection troubleshooting.

For Ollama, LM Studio, vLLM, SGLang, and other compatible servers, [configure a compatible endpoint](#configure-a-compatible-endpoint) in `models.json`.

## Configure a compatible endpoint

Use [`models.json`](/docs/latest/configuration#agent-directory) when an endpoint speaks an API pig already supports. This includes most Ollama, LM Studio, vLLM, SGLang, and proxy deployments.

```json
{
  "providers": {
    "ollama": {
      "baseUrl": "http://localhost:11434/v1",
      "api": "openai-completions",
      "apiKey": "ollama",
      "models": [
        { "id": "qwen2.5-coder:7b" }
      ]
    }
  }
}
```

The dummy key makes the model available to pig; Ollama ignores it. A provider entry under `providers` supports `baseUrl`, `api` (`"openai-completions"`, `"openai-responses"`, `"anthropic-messages"`, `"google-generative-ai"`, `"mistral-conversations"`, `"google-vertex"`, `"bedrock-converse-stream"`, `"azure-openai-responses"`, `"openai-codex-responses"`), `apiKey`, `authHeader` (boolean), `headers`, `compat`, `models`, and `modelOverrides` (provider display names are not set in `models.json`; each model has its own `id` and `name`). For an authenticated endpoint, `apiKey` and header values can use `$NAME` or `${NAME}` environment interpolation, a literal value, or a leading `!command`. A bare name such as `MY_KEY` is a literal, not a variable. Commands in `models.json` are not cached: `apiKey` resolves on every request, and headers when the file is read.

Opening `/model` reloads the file. A provider entry may also name a built-in or extension provider; its `baseUrl`, `headers`, and `compat` then apply to all of that provider's models. A `models` entry adds or replaces a model with the same ID on that provider, including a shipped model, while preserving the provider's other existing models:

```json
{
  "providers": {
    "openai": {
      "models": [
        {
          "id": "custom-chat",
          "name": "Custom Chat",
          "reasoning": true,
          "input": ["text"],
          "contextWindow": 32768,
          "maxTokens": 4096,
          "cost": { "input": 0, "output": 0, "cacheRead": 0, "cacheWrite": 0 }
        }
      ]
    }
  }
}
```

New models default to `name` = `id`, `reasoning` `false`, `input` `["text"]`, `contextWindow` 128000, `maxTokens` 16384, and zero `cost` (`input`, `output`, `cacheRead`, `cacheWrite` in USD per million tokens); `apiKey` is optional. `models.json` is applied after extension providers register. Use `modelOverrides` to change metadata for an existing built-in or extension-provided model without replacing the provider's model list. Unknown override IDs are ignored.

### Describe model input and caching

Use `inputLimits.images.resize` to control how pig encodes new image attachments, `read` results, and tool-result images before storing them in conversation history:

```json
{
  "id": "vision-model",
  "input": ["text", "image"],
  "inputLimits": {
    "images": {
      "resize": {
        "maxWidth": 1568,
        "maxHeight": 1568,
        "maxBytes": 524288,
        "jpegQuality": 75
      }
    }
  }
}
```

`maxBytes` limits the base64-encoded payload. Omitted resize fields use conservative defaults of 2000 by 2000 pixels, 4.5 MiB encoded, and JPEG quality 80. Images are encoded once; changing models does not rewrite historical images. The catalog can also describe hard request limits with `inputLimits.maxRequestBytes`, `images.maxPerMessage`, and `images.maxPerRequest`, but pig does not yet rewrite or reject history based on them.

<a id="prompt-cache-lifetimes"></a>

Use `promptCache` to declare the provider's best-effort cache lifetime in seconds for the `short` or `long` retention tier:

```json
{ "id": "claude-sonnet-5", "promptCache": { "short": 300, "long": 3600 } }
```

Choose the conservative end of any published range. A model without a lifetime for the active tier is not eligible for cache warming. A `modelOverrides` entry can set `inputLimits` or `promptCache` for a built-in or extension model, including a model accessed through a validated proxy. See [`cacheWarming`](/docs/latest/settings#model-and-thinking).

Compatibility settings should describe verified differences in the endpoint's request or response behavior. Do not enable them based only on an endpoint advertising OpenAI or Anthropic compatibility.

## Use classifier models

Classifier models do not chat. They answer typed questions about JSON state: pick one of several choices, answer yes or no, or give a score, each with probabilities. pig includes TypeSafe's Jev model from these providers:

| Provider | Model IDs | Authentication |
|---|---|---|
| `typesafe` | `jev-latest` | `TYPESAFE_API_KEY` |
| `openrouter` | `typesafe/jev-1.13`, `~typesafe/jev-latest` | `OPENROUTER_API_KEY` or `/login` |
| `cloudflare-workers-ai` | `typesafe/jev` | `CLOUDFLARE_API_KEY` and `CLOUDFLARE_ACCOUNT_ID` |
| `vercel-ai-gateway` | `typesafe-ai/jev` | `AI_GATEWAY_API_KEY` |
| `opencode` | `jev-1.13`, `jev-1.13-free` | `OPENCODE_API_KEY` |

Chat models on a [llama.cpp router](/docs/latest/llama-cpp#classification) are also listed as classifier models.

Classifier models do not appear in `/model`. The model reaches them through the [`codemode`](/docs/latest/cli#enable-codemode) tool, which is off unless an MCP server turned it on. Turn it on with `"defaultTools": ["+codemode"]` in [settings](/docs/latest/settings#tools) or `--tools +codemode`. Scripts are PHP; they list classifier models with `$models->getAvailableOfType('classifier')` and call `$models->classify($model, ['state' => ..., 'questions' => ...])`:

```php
$jev = $models->getModelOfType('classifier', 'typesafe', 'jev-latest');
$result = $models->classify($jev, [
    'state' => ['message' => 'The change works, thanks.'],
    'questions' => [
        'approved' => [
            'type' => 'bool',
            'instructions' => 'Does the user approve of the result?',
            'criteria' => ['true' => 'Approval', 'false' => 'No approval'],
        ],
    ],
]);
return $result['stopReason'] === 'stop' ? $result['answers'] : $result['errorMessage'];
```

`classify()` does not throw on provider errors; check `$result['stopReason']` (`stop`, `error`, or `aborted`). A `choice` question answers `choice`, `probabilities`, and `confidence`; a `score` question `score` and `confidence`; a `bool` question `probability`.

When the service reports token counts, as all System One services do, `$result['usage']` carries them with their cost. pig adds the usage of a script's classifier calls to the `codemode` tool result, so it counts toward the session cost in the footer and `/session`. The cost uses the model's catalog price; models without one, such as TypeSafe's direct `jev-latest`, report tokens at no cost.

Extensions call classifiers through `$ctx->modelRegistry->classify()`, without codemode. [Virtual models](/docs/latest/virtual-models#route-requests) can use them to route requests.

## Add a custom provider

Use an extension when the provider needs custom streaming, model discovery, or authentication behavior. See [Custom Providers](/docs/latest/custom-provider) for the extension workflow.

## Troubleshooting

### A model does not appear

Confirm that its provider has usable authentication. Custom models can load from `models.json` but remain unavailable in `/model` until pig can resolve credentials. For llama.cpp, only models currently loaded by the router appear.

### Authentication works in one shell only

Check whether the key came from an environment variable rather than `auth.json`. Environment variables must be present in the process that starts pig.

### Sign-in opens a browser on a remote machine

Complete the provider's headless authentication flow when available. Some providers let you paste the final redirect URL or authorization code back into pig. See [Authenticate interactively](/docs/latest/providers#authenticate-interactively).

### A compatible endpoint rejects requests

Check its API type and compatibility settings in `models.json`. The upstream server must support the corresponding request fields and behavior.
