# Custom Providers

A provider extension connects pig to a model service that needs custom authentication, model discovery, request handling, or streaming. If the service already speaks a supported API, configure it in `models.json` instead.

Provider extensions run inside pig and can inspect credentials, prompts, tool definitions, model responses, and usage. Treat them as trusted code and avoid logging secrets or provider payloads.

## Choose the smallest integration

| Requirement | Use |
|---|---|
| Add models behind a supported API | [`models.json`](/docs/latest/models#configure-a-compatible-endpoint) |
| Change an existing provider endpoint or headers | `models.json` or a small provider extension |
| Discover models dynamically | A provider with `refreshModels` |
| Add a `/login` flow | A provider with native or legacy OAuth configuration |
| Implement an unsupported wire protocol | A provider with `stream` or `streamSimple` |

A provider extension is an [extension](/docs/latest/extensions), so it follows the same loading, trust, reload, and error behavior.

## Register a provider

Call `$pig->registerProvider()` from the extension factory. Extensions load before startup model selection, so providers registered there are available to `--model` and `pig --list-models`.

There are two registration forms:

- Register a complete `Pig\Ai\Extension\Provider` for native authentication, filtering, discovery, refresh, and streaming behavior.
- Register a provider name with a configuration array, the legacy `ProviderConfig` form (`Pig\CodingAgent\Extensions\ProviderConfig` turns it into a `Provider`). Its keys are `name`, `baseUrl`, `apiKey`, `api`, `streamSimple`, `headers`, `authHeader`, `models`, `refreshModels`, and `oauth`.

Prefer a complete provider for new integrations that own more than static endpoint and model metadata. pig applies `models.json` after extension providers, so its overrides compose above a registered provider.

Registering only `baseUrl` or `headers` for an existing provider preserves its built-in models at the new endpoint. Supplying `models` in the legacy form replaces that provider's models across chat, image, and classifier operations until the provider is unregistered. An omitted `type` means `"chat"`; `image` and `classifier` name the other operations. A definition's `api` falls back to the configuration's (chat only) and then to an existing model's of the same type.

```php
use Pig\CodingAgent\Extensions\ExtensionApi;

return function (ExtensionApi $pig): void {
    $pig->registerProvider('my-gateway', [
        'name' => 'My Gateway',
        'baseUrl' => 'https://gateway.example.com/v1',
        'api' => 'openai-completions',
        'apiKey' => '$MY_GATEWAY_API_KEY',
        'models' => [
            ['id' => 'coder-large', 'name' => 'Coder Large', 'reasoning' => true, 'input' => ['text', 'image'],
             'contextWindow' => 200000, 'maxTokens' => 32000,
             'cost' => ['input' => 3, 'output' => 15, 'cacheRead' => 0.3, 'cacheWrite' => 3.75]],
            ['type' => 'classifier', 'id' => 'judge-v1', 'name' => 'Judge V1', 'api' => 'llama-cpp-classify',
             'input' => ['text'], 'contextWindow' => 64000,
             'cost' => ['input' => 0, 'output' => 0, 'cacheRead' => 0, 'cacheWrite' => 0]],
        ],
    ]);
};
```

Where pig differs from upstream pi's legacy form:

- `images` and `classifiers` map an `api` name to a PHP implementation: a Closure `(ImageModel, ImagesContext, ImagesOptions): AssistantImages` (classifiers: `(ClassifierModel, ClassifierContext, ClassifierOptions): ClassificationResult`), or an object implementing `Pig\Ai\Extension\ProviderImages` / `ProviderClassifier`. The implementation receives the model with `api` set to `extension`, not the custom name. A native provider passes the same objects as `new Provider(..., images: [...], imageApi: ...)` / `classifierApi:`.
- `oauth` must be a `Pig\Ai\Extension\OauthFlow` object, the shape native providers use, not an object of callbacks.
- `headers` (and the `Authorization` header from `authHeader`) are resolved when the provider is registered; a header that cannot be resolved fails the registration. `apiKey` is resolved on every request, and a key stored with `/login` wins over it.
- `streamSimple` is a PHP callable `(Model, TranscriptContext, ?SimpleStreamOptions): AssistantMessageEventStream`. It serves the models whose `api` is the configuration's `api`, which is then required.

Model-level `baseUrl` values take precedence over the provider endpoint. If no `models` list is supplied, built-in models of every operation remain registered. Equal model IDs in different operations remain distinct, including their model-specific headers.

Calls made after initial extension loading take effect immediately. Use `$pig->unregisterProvider()` to remove the dynamic provider and restore built-in behavior that it replaced; `/reload` unregisters and loads the extensions again.

pig's own `extensions/pig-llama/` (`LlamaProvider.php`) and the [pig-antigravity](https://github.com/owner888/pig-antigravity) package are complete provider registrations to study.

## Provide authentication

Static providers can resolve an API key from a literal, environment interpolation, or a command. These values use the same syntax as `models.json`:

- `$NAME` and `${NAME}` read environment variables.
- A leading `!command` uses command output.
- `$$` emits a literal `$`.
- `$!` emits a literal leading `!`.

Use native provider authentication when the integration needs stored credentials, custom resolution, provider-scoped environment, or multiple login methods.

An OAuth provider supplies an `OauthFlow` with a display name, login flow, token refresh, and access-token resolution. After registration it appears in `/login`, and pig stores returned credentials in `auth.json` (pi's `~/.pi/agent/auth.json` when it exists, otherwise `~/.pig/agent/auth.json`).

OAuth callbacks are UI-neutral. They can open an authorization URL, show a device code, report progress, request input, or ask the user to choose a login method. Honor cancellation and the supplied abort signal during network requests.

Never write access tokens, refresh tokens, authorization headers, or complete provider responses to ordinary logs.

## Supply and refresh models

Every model needs an ID, display name, input capabilities, and cost metadata. Chat and classifier models also need a context window; chat models need an output limit and reasoning support; image models declare their output modalities. Choose the API implementation at the provider level unless one model requires an override.

Set `promptCache.short` or `promptCache.long` to the provider's best-effort cache lifetime in seconds when pig should keep an idle prompt cache warm. Leave them unset to disable cache warming for that retention tier.

Compatibility flags describe verified differences in an otherwise supported API. Do not enable them based only on an endpoint claiming compatibility.

Confirm the request fields and response behavior against the actual server.

Use `refreshModels` when the available catalog comes from a live service. Pass `$context->signal` to blocking I/O so callers can cancel refreshes, and check `$context->allowNetwork`: pig first calls it without network access to restore the stored catalog, then again with the network.

The two registration forms have different refresh contracts:

- A complete `Provider` returns nothing. It calls `$context->publish` to install provider-owned model state, which pig keeps in `<agent-dir>/models-store.json`.
- The legacy `refreshModels` returns mixed-operation model definitions. pig checks them and replaces that registration's live models with the returned list.

Publish persisted catalog data only when it should survive across runs. A live service such as llama.cpp can update its in-memory list without persisting it; a remote catalog can retain a snapshot for offline startup.

## Reuse a supported streaming API

Use one of pig AI’s API implementations whenever the provider protocol matches it.

Supported implementations (`Pig\Ai\Api`) cover Anthropic Messages (`anthropic-messages`), OpenAI Chat Completions and Responses (`openai-completions`, `openai-responses`, `openai-codex-responses`), Google Generative AI and Vertex (`google-generative-ai`, `google-vertex`), Azure OpenAI Responses (`azure-openai-responses`), Mistral Conversations (`mistral-conversations`), and Bedrock Converse (`bedrock-converse-stream`).

The provider can still customize authentication, base URLs, headers, model filtering, and discovery while delegating request conversion and streaming to an existing API implementation.

This is safer than copying a stream implementation because it preserves pig’s message conversion, tool handling, usage accounting, cancellation, and compatibility behavior.

## Implement custom streaming

Implement `streamSimple` only when no existing API implementation can represent the service. Study the implementations under [`packages/ai/src/Providers`](https://github.com/owner888/pig/tree/main/packages/ai/src/Providers) first.

The stream receives a normalized `TranscriptContext`. System prompts and tool declarations live in transcript system messages, so read them with `Transcript::getCurrentSystemPrompt($context->messages)` and `Transcript::getCurrentTools($context->messages)` (`Pig\Ai\Utils\Transcript`) rather than expecting a separate system prompt or tool list. A model that supports mid-conversation system messages can receive them in place; otherwise call `Transcript::collapseSystemMessages($context)` to fold later system messages into the leading one.

A custom stream must:

1. Create an assistant message with provider, model, timestamp, pending stop reason, content, and zeroed usage.
2. After request setup succeeds, emit one `start` event before content events.
3. Update the message while emitting balanced text, thinking, and tool-call events.
4. Finalize usage, cost, content, and stop reason.
5. Emit exactly one terminal `done` or `error` event and close the stream.
6. Convert cancellation into an aborted result.

Request setup can fail before `start`; in that case the stream can terminate directly with `error`. Missing request authentication may also throw synchronously before a stream is returned.

Content indexes refer to blocks in the assistant message. Update each block before emitting the event whose `partial` field exposes that state. Tool-call arguments must contain valid parsed input by `toolcall_end`.

The stream must also honor request instrumentation supplied through `SimpleStreamOptions`:

- Call `$options->onPayload` before sending the provider request and use any replacement payload it returns.
- Call `$options->onResponse` after receiving the response but before consuming its body.
- Call `$options->onProviderStreamEvent` with each parsed provider event and the model before normalizing it.
- Pass through the abort signal and provider-scoped environment.

These hooks power extension request inspection, response-header events, and provider-stream observation. Omitting them makes the provider behave differently from pig’s built-in providers.

```php
use Pig\Ai\DoneEvent;
use Pig\Ai\ErrorEvent;
use Pig\Ai\Http\HttpClient;
use Pig\Ai\Http\Request;
use Pig\Ai\Model;
use Pig\Ai\Providers\AssistantMessageBuilder;
use Pig\Ai\SimpleStreamOptions;
use Pig\Ai\StartEvent;
use Pig\Ai\StopReason;
use Pig\Ai\TextDeltaEvent;
use Pig\Ai\TextEndEvent;
use Pig\Ai\TextStartEvent;
use Pig\Ai\TranscriptContext;
use Pig\Ai\Usage;
use Pig\Ai\UserMessage;
use Pig\Ai\Utils\AssistantMessageEventStream;
use Pig\Ai\Utils\Text;
use Pig\Async\Async;

'streamSimple' => static function (Model $model, TranscriptContext $context, ?SimpleStreamOptions $options = null): AssistantMessageEventStream {
    $stream = new AssistantMessageEventStream();
    Async::spawn(static function () use ($stream, $model, $context, $options): void {
        $builder = new AssistantMessageBuilder($model);
        try {
            $messages = [];
            foreach ($context->messages as $msg) {
                if ($msg instanceof UserMessage) {
                    $messages[] = ['role' => 'user', 'content' => Text::contentText($msg->content)];
                }
            }
            $payload = ['model' => $model->id, 'messages' => $messages, 'stream' => true];
            if ($options?->onPayload !== null && is_array($replaced = ($options->onPayload)($payload, $model))) {
                $payload = $replaced;
            }
            $headers = [...$model->headers, ...($options?->headers ?? []), 'content-type' => 'application/json', 'x-api-key' => (string) ($options?->apiKey ?? '')];
            $res = (new HttpClient())->send(new Request('POST', rtrim($model->baseUrl, '/') . '/generate', $headers, (string) json_encode($payload)), $options?->signal);
            if ($options?->onResponse !== null) {
                ($options->onResponse)(['status' => $res->status, 'headers' => $res->headers], $model);
            }
            if (!$res->isSuccessful()) {
                throw new \RuntimeException($res->body->all());
            }
            $stream->push(new StartEvent($builder->snapshot()));
            $textIndex = null;
            $buf = '';
            foreach ($res->body as $chunk) {
                $buf .= $chunk;
                while (($pos = strpos($buf, "\n")) !== false) {
                    $line = trim(substr($buf, 0, $pos));
                    $buf = substr($buf, $pos + 1);
                    if ($line === '' || !is_array($ev = json_decode($line, true))) {
                        continue;
                    }
                    if (($ev['type'] ?? null) === 'text_delta') {
                        if ($textIndex === null) {
                            $textIndex = $builder->startText($builder->nextWire());
                            $stream->push(new TextStartEvent($textIndex, $builder->snapshot()));
                        }
                        $delta = (string) ($ev['text'] ?? '');
                        $builder->append($textIndex, 'text', $delta);
                        $stream->push(new TextDeltaEvent($textIndex, $delta, $builder->snapshot()));
                    } elseif (($ev['type'] ?? null) === 'usage') {
                        $in = (int) ($ev['input_tokens'] ?? 0);
                        $out = (int) ($ev['output_tokens'] ?? 0);
                        $builder->setUsage(new Usage(input: $in, output: $out, totalTokens: $in + $out));
                    } elseif (($ev['type'] ?? null) === 'done') {
                        $builder->setStopReason(StopReason::tryFrom((string) ($ev['reason'] ?? 'stop')) ?? StopReason::Stop);
                    }
                }
            }
            if ($textIndex !== null) {
                $stream->push(new TextEndEvent($textIndex, $builder->textOf($textIndex), $builder->snapshot()));
            }
            $final = $builder->snapshot();
            $stream->push(new DoneEvent($final->stopReason, $final));
            $stream->end();
        } catch (\Throwable $e) {
            $builder->fail($e->getMessage(), $options?->signal?->aborted() ?? false);
            $failed = $builder->snapshot();
            $stream->push(new ErrorEvent($failed->stopReason, $failed));
            $stream->end();
        }
    });
    return $stream;
}
```

## Report failures and usage

Set a concrete terminal stop reason. Error and aborted messages need an `errorMessage`; successful messages need accurate input, output, cache, total-token, and cost values.

pig can compact and retry after recognized context-overflow errors. If the service uses an unknown message, normalize only that provider’s overflow response to `context_length_exceeded` in a guarded `message_end` handler.

Do not rewrite rate limits or transient provider failures as context overflow. Those failures use pig’s normal retry behavior instead.

## Test the integration

Test at least:

- ordinary and empty text responses
- tool calls and tool results
- image input and image tool results when supported
- usage and cost accounting
- abort behavior
- context overflow
- malformed or partial streams
- Unicode boundaries
- cross-provider session handoff
- authentication refresh and cancellation

The provider tests under [`packages/ai/test`](https://github.com/owner888/pig/tree/main/packages/ai/test) define the behavior expected from built-in providers. Adapt the relevant suites rather than relying only on manual prompts.

Run the extension directly while developing, then move it to a discovered extension location or distribute it through a [pig package](/docs/latest/packages). Use `/reload` after changing a discovered provider extension in an active session.
