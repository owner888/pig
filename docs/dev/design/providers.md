# Design: Providers

Each provider API, the model registry, thinking levels, usage, overflow. Moved out of `CLAUDE.md` as written — "this file" in the entries below means `CLAUDE.md`, where they were first written. The index is generated from the headings below.

- [A quota wall moves the turn to a `fallbackModels` entry](#a-quota-wall-moves-the-turn-to-a-fallbackmodels-entry)
- [Keeping the prompt cache warm](#keeping-the-prompt-cache-warm)
- [Somebody else's endpoint, declared in a file](#somebody-elses-endpoint-declared-in-a-file)
- [Talking to a gateway instead of a provider](#talking-to-a-gateway-instead-of-a-provider)
- [An `Agent` nobody configured has a model, and it is the free one](#an-agent-nobody-configured-has-a-model-and-it-is-the-free-one)
- [The upstream dependencies that no package can replace: the provider SDKs](#the-upstream-dependencies-that-no-package-can-replace-the-provider-sdks)
- [Vertex AI and Amazon Bedrock: two more SDKs, emulated rather than wrapped](#vertex-ai-and-amazon-bedrock-two-more-sdks-emulated-rather-than-wrapped)
- [Azure OpenAI, ChatGPT's Codex backend and `pi-messages`](#azure-openai-chatgpts-codex-backend-and-pi-messages)
- [Provider attribution (`ProviderAttribution`)](#provider-attribution-providerattribution)

## A quota wall moves the turn to a `fallbackModels` entry

pig's own, with no upstream counterpart: upstream's session stops at a limit error, pig's goes on.
The developer asked for a run that keeps going when the primary key's share is used up and a second
key for the same work is at hand — typically the same id on a different provider.

```json
"defaultModel": "gemini-3.8-flash",
"defaultProvider": "antigravity",
"fallbackModels": ["google/gemini-3.8-flash:medium"]
```

- **`defaultModel` / `defaultProvider` are untouched**; `fallbackModels` is what comes after them,
  in order, in `--models`' spelling (`ModelResolver::parse()`), one list that cannot drift from it.
- **Only a limit error switches** — `Retry::isProviderLimitError()`, the quota / billing half of
  `isRetryableAssistantError()`'s lists, plus pig's own `quota reached` for pi-antigravity's wall
  sentence, which upstream never classified because its session merely stops. A 503 is still a
  retry, and a retry that used its budget is still a failure: the two paths do not feed each other.
- **The switch is a `/model` typed by hand**: `setModel(…, persistAsDefault: false)`, so it is in the
  session file and the footer and *not* in `settings.json`; the next session opens on the primary.
  Nothing moves back inside the session — the primary is the one model known to be out.
- **Once per model per prompt** (`$fallbacksTried`), past entries with no key or that resolve to
  nothing; with none left the turn ends on the wall's sentence as it always did.
- **`:level` sets the thinking level; no suffix carries the current one over** — the one place this
  differs from `--models`, where no suffix is `off`. A fallback is the same work on another key.
- `ModelFallbackEvent` is announced between the switch and the resend; the terminal says
  `Quota reached on antigravity/gemini-3.8-flash — model: google/gemini-3.8-flash · thinking medium`,
  RPC gets `model_fallback` with `from`, `to` and `error`.

Lives in `AgentSession::switchToFallbackModel()`, called from `handlePostAgentRun()` after the retry
step and before the overflow check. Regression tests:
`AgentSessionTest::testAQuotaWallMovesTheTurnOnToTheNextFallbackModel` and
`…WithEveryFallbackTriedEndsTheTurnAsBefore`.

## Keeping the prompt cache warm

`Session\CacheWarmer` is upstream's `core/cache-warmer.ts`. A provider's prompt cache lives for the
model's `promptCache` TTL (Anthropic: 300 s short, 3600 s long); a tool that runs past it means the
next request writes the whole prompt to the cache again at full price, where a read costs a tenth.
So each session request starts a run: at `max(1, floor(min(ttl × 0.9, ttl − 10 s)))` the same
request goes out again with `maxTokens: 1` and `maxRetries: 0`, when
`continuation × missCost − warmCost ≥ $0.05` (`missCost` = cache write or input minus cache read,
`warmCost` = the cache read plus one output token, continuation 1.0 while the run streams and 0.15
idle), and the refresh is written down with `SessionManager::appendUsage()` as pi's `usage` entry
(`kind: cache_warm`). `stats()` counts it in the cost. Upstream's formulas, safety limits (60 min
streaming, 30 min idle, a refresh deadline half the remaining margin after the planned time) and
`/session` wording are copied as they are.

Six things decided where pig differs:

- **Opt-in by mode.** Upstream's SDK always builds the warmer and `unref()`s its timer, so Node can
  exit with one pending. pig's loop has no unref — a pending timer keeps `Loop::isIdle()` false — and
  a `-p` run has no next request to save, so `AgentSession::enableCacheWarming()` is called by
  `InteractiveMode::start()` and `RpcMode::start()` and nothing else. `cacheWarmingStatus()` is null
  without it, which is how `/session` can tell.
- **The refresh replays the inner provider call** (`$innerStream`, the agent's stream function as it
  was before `AgentSession` wrapped it), not the wrapper — that would start a run for its own
  refresh. Upstream calls `modelRuntime.streamSimple` directly for the same reason.
- **Only session requests start it**: the raw options' `sessionId` equal to the store's id, as
  upstream checks `sessionManager.getSessionId()`. Summaries use a fresh `Uuid::v7()` each and never match.
- **`isCurrent` is identity, not equality** — upstream's `cacheContextIsCurrent()`: same provider and
  id, and the message list captured at the request is a prefix of the agent's list, object by object.
- **`writeTo()` cancels**, because pig switches files inside one `AgentSession` where upstream
  replaces the session and disposes the old one. `dispose()` cancels too.
- **The "Cache warmed" line is live only.** Upstream announces each refresh as `Cache warmed: $x`
  behind `showCacheMissNotices`, and so does pig (`CacheWarmedEvent`). A transcript rebuilt from the
  file does not draw the old ones again: usage entries are not on `messages()`, which is what
  `replay()` walks. The cost is still on `/session`. The prompt size is read off the agent's last assistant message where upstream reads the branch's;
  the two differ only when the last turn failed and was taken off the state.

The `cache_warming_decision` hook (`HookRunner::emitCacheWarmingDecision()`) is upstream's: every
handler is asked, the last one returning an action wins, a throw is reported and leaves pig's answer
standing. A refresh that ran against pig's answer is noted `extension override` on its entry. The one
swallowed throw is the refresh itself — best effort, as upstream's comment says: a failed refresh must
not touch the run it serves.

Regression tests: `CacheWarmerTest` (16 — the delay table, TTL by retention, replayability, refresh
options and record, each mode, both hook directions, context changes, the status line) and
`CacheWarmingSessionTest` (6 — through `AgentSession`: an idle refresh on the bill and in the file,
streaming stopping at settle, not enabled meaning nothing, the hook, the mode setter, `writeTo()`).

## Somebody else's endpoint, declared in a file

`CustomModels` is upstream's `core/model-config.ts` and the `models.json` half of
`provider-composer.ts` (`applyModelsJson()`, `applyModelOverride()`, `modelFromJson()`).
The other half was already here under other names (`Ai\Models`, `Auth`, `ModelResolver`), which
is exactly how this one stayed invisible long enough to be the fourth thing the left-out table
got wrong.

pi's `~/.pi/agent/models.json` and then `~/.pig/agent/models.json`, pig's laid over pi's a provider at
a time: a provider named in both is pig's entry whole, its key included (`CustomModels::layeredWith()`).
It was "pig's, or pi's when pig has none", and the day the developer wrote a pig file for DeepSeek
every provider in pi's file vanished from pig without a word while pi went on using it — the
fallback only ever covered somebody with no pig file at all. Neither is ever written to. `--no-session` reads it
too, as upstream's model runtime does, and `/model` reads it again whenever it opens.

Every key is upstream's, so a file written for pi works here unchanged:

```json
{ "providers": { "my-box": {
  "baseUrl": "http://192.168.1.9:8080/v1", "apiKey": "$MY_BOX_KEY",
  "api": "openai-completions", "authHeader": true, "headers": { "X-Tenant": "acme" },
  "models": [{ "id": "qwen3-coder", "name": "Qwen3 Coder", "reasoning": false,
               "input": ["text"], "contextWindow": 262144, "maxTokens": 32768 }] },
  "openai": { "modelOverrides": { "gpt-5.5": { "contextWindow": 1050000 } } } } }
```

Things decided here:

- **`apiKey` and every header value is a `ConfigValue`** (upstream's `resolve-config-value.ts`):
  `$NAME` / `${NAME}` for an environment variable (the entry's `env` first, then the process's),
  `!command` for a command's stdout, `$$` / `$!` for a literal `$` / `!`, and **anything else
  literally** — a bare `MY_BOX_KEY` is a key that is those ten letters. A referenced variable that is
  not set is no value, not the text with a hole in it. The key is resolved per request (a command runs
  every time, upstream's `resolveConfigValueOrThrow()`); headers when the file is read, because pig's
  headers are part of the model. `auth.json` keys go through the cached `resolve()`. `apiKey` is
  optional.
- **A provider entry may name a provider that exists** — built in or an extension's. Its `baseUrl`,
  `headers` and `compat` are laid over every model it has; `models` adds a model **or replaces the one
  with the same id** (a shipped one included, as upstream's does); `modelOverrides` changes fields by
  id without replacing the list, and an unknown id is ignored. That is `Models::useOverlay()`, a layer
  over everything else and replaced on each load, so it applies after extension providers and an
  extension registered later is covered too. A `models` entry defaults `name` to its id,
  `contextWindow` to 128000 and `maxTokens` to 16384, and takes the provider's `api` / `baseUrl` from
  its existing models when it says neither.
- **`Models` is static.** Upstream threads a `ModelRegistry`
  instance through everything; here `--model`, `/model`, restoring a session and `RpcMode` all
  ask `Models` by id, and a model only some of them could see is a model that works until you
  save the conversation. The price is `forgetRegistered()`, which every test that registers has
  to call. What an extension sees is upstream's facade over it, `ModelRegistry` (`$ctx->modelRegistry`).
- **Nothing throws and nothing is all-or-nothing.** A bad line loses only itself: the good
  models in the same file still load, the built-ins are untouched, pig still starts, and every
  problem names the file, the provider and the model. That is `Settings`' rule — a file that is
  not JSON is named, not ignored — applied to a file with far more ways to be wrong. There is no
  AJV and no TypeBox; the checks are written out, which is `Agent\ToolArguments`' trade again.
- **A `cost` block is read and is optional**, under upstream's four names and upstream's unit:
  dollars per million tokens, so Sonnet's three dollars is `3.0` and not `0.000003`. Absent is free,
  which is what a local model is — and a price that is **not a number is refused by name** rather
  than read as free, because `/session`, the footer and `--list-models` all report money and a model
  that silently costs nothing misreports it every turn. `"input": "0.28"` with the quotes left on is
  the mistake to expect. Only the absent case had a test until a live run against a declared
  endpoint reported `the model is priced at zero` and that was mistaken — in this file's own summary
  of it — for the field not existing at all. *A claim about what this repository does is worth a
  grep, which is the fourth shape from the index pointed at pig rather than at a docblock.*
- **No `compat` block means null**, so `OpenAiCompat::detect()` still works it out from the
  provider name and URL. That is a better default than any set of flags: a local
  llama.cpp gets what it needs with nothing written. A block uses **upstream's key names** so files
  stay portable — the `supports…`/`maxTokensField` ones, the `requires…` ones
  (`requiresReasoningContentOnAssistantMessages`, `supportsStrictMode`), `thinkingFormat`,
  `chatTemplateKwargs` and `chatTemplateArgs`; for an `anthropic-messages` model,
  `forceAdaptiveThinking` and `supportsStrictTools`. A key the block leaves out
  is null and detection decides it (`OpenAiCompat::resolve()`, upstream's `getCompat()`), and a
  provider-level `compat` applies to each of its models with the model's own keys winning
  (upstream's `mergeCompat()`). This sentence used to say upstream had four of the eight and that
  the other four were pig's own; see the trap entry on it.

`--list-models` moved to **after** this loads, which it had to: a listing that cannot show the model
somebody just declared is a listing they will not trust about the rest either. It costs a
settings read on a command that prints and exits.

## Talking to a gateway instead of a provider

`Agent\StreamProxy` is upstream's `agent/proxy.ts`. **It is the second thing in this repository
called a proxy and it does the opposite of the first.** `Ai\Http\Proxy` carries pig's own encrypted
bytes to the provider and cannot read them; this one posts the conversation, the system prompt and
the tool schemas to a server that holds the provider keys and makes the call. For a team that does
not want keys on laptops that is exactly the point, and for anybody else it is a reason not to use
it — so nothing turns it on. It is a `streamFn`, passed in:

```php
$proxy = new StreamProxy('https://genai.example.com', $token);
$agent = new Agent(new AgentOptions(streamFn: $proxy->stream(...)));
```

The wire shape is upstream's, so a gateway written for pi serves pig unchanged: `POST
{proxyUrl}/api/stream`, `Authorization: Bearer …`, a body of `{model, context, options}` — the
model whole (its `thinkingLevelMap`, `cost.tiers` and every compat key included), the context as
upstream's transcript `{messages}` led by a `system` message carrying the prompt and the tools
(`constrainedSampling` and all), the options pig has (`temperature`, `maxTokens`, `reasoning`,
`cacheRetention`, `sessionId`, `metadata`, each only when set) — and
events back with the `partial` field stripped to save bandwidth — the client rebuilds it.

**That rebuilding is the whole file.** `AssistantMessage` is readonly here, so there is no object to
mutate as upstream does; every event pig hands on carries a real snapshot from
`AssistantMessageBuilder`, which is the same accumulator the five providers use. Reaching into it is
a reach across an `@internal` line, and it is the faithful one: upstream imports
`pi-ai/dist/utils/json-parse.js` here with a comment saying it is an internal import, for the same
reason — a second answer to "what does a half-finished tool call look like" is one too many.

Six things that took a decision:

- **`MessageJson` moved to `pig/ai` for this.** The request body is the session file's message
  shape — upstream sends the same objects to both — and the encoder was in `coding-agent`, where
  `agent-core` cannot reach it. Copying it would have been a second hand-written notion of a
  message, which is the mistake `ToolArguments` had already made once about tool schemas; twice is a
  pattern. `SessionCodec` now keeps the three roles only pig has and delegates the rest, and went
  from 302 lines to 146.
- **The stream is read line by line, not with `SseParser`** — and not merely because upstream does
  it that way. Splitting on `\n` and treating each `data:` line as a whole event is an assumption,
  and a sound one: `JSON.stringify` never emits a newline, so an event is always exactly one line.
  Because a blank line does not start with `data:`, this reader also handles a properly framed
  stream, which makes it the **superset**; a strict SSE parser is not, because it waits for the
  blank line and joins consecutive `data:` lines into one event, so against a gateway sending events
  back to back it stalls or glues two together. The gateway's source is in neither repository, so
  which wire it sends cannot be checked, and only one of the two readers is right either way. Four
  tests hold it down: no blank lines, blank lines, CRLF, and an event split across two TCP reads.
- **Only a line that starts with `data: `, space included, is an event** — upstream's
  `processLine()`: `if (!line.startsWith("data: ")) return; line.slice(6).trim()` (JavaScript's
  trim). A `data:{…}` line without the space is passed over, as it is in pi; against a body whose
  content lines lack the space and whose `done` line has one, the turn ends `stop` with no content
  in both. A line that is not JSON ends the turn with V8's `JSON.parse` message (`JsJson`), as
  upstream's does — a `: keep-alive` comment and a blank `data: ` never reach the parse.
- **These were the only two hand-rolled event-stream readers in pi at the time** (HEAD has since
  added Anthropic's and Mistral's). The OpenAI and Google SDKs parse the rest — and that is why `GoogleGeminiCli` here uses `SseParser` while this does not: Google's Code
  Assist frames properly and can be checked, a gateway cannot.
- **A stream that ends without `done` or `error` is a failure**, as upstream's: `Connection closed by
  proxy server before the response completed`. The partial starts at `StopReason::Pending`, as
  upstream's does. `done` and `error` carry `providerThinkingLevel` onto the message, and
  `toolcall_end` lays the server's finished `toolCall` over what the deltas built — and is ignored,
  not fatal, for a block that is not a tool call. Of upstream's request options, `headers`,
  `transport`, `thinkingBudgets` and `maxRetryDelayMs` are sent as upstream sends them;
  `samplingParams` is not, because no model in pig has any. The request carries
  upstream's two headers only — no `Accept`. An abort while the body is read is `Request aborted by
  user`, as upstream's reader says.
- **An unknown `done` reason is a plain stop, and an unknown event type is ignored.** The turn did
  finish; refusing it over a word this pig does not know loses the work. Same rule as `JsonSchema`'s
  unknown keywords, applied to a wire protocol.
- **`model` goes over whole — `headers` and `compat` included, and `cost` not `pricing`.** `compat`
  carries only the keys the model says, as upstream's `model.compat` does, so the server detects
  the rest. The
  server reads the provider and api off it to decide who to call, and pig renamed `cost` to
  `pricing` internally while the wire keeps the name the server was written against. A custom
  provider's extra auth header therefore reaches the gateway; that is upstream's behaviour and it is
  written down rather than quietly trimmed, because a model whose headers pig withheld would fail at
  the gateway for a reason nobody could see.

**The usage a gateway reports is trusted as sent, cost included** — and for a while it was not.
`AssistantMessageBuilder::setUsage()` recomputed the cost from the model's public price list, which
is right for the five providers (none of them sends a cost, so the list price is the only figure
there is) and wrong for the one caller that gets one: the gateway made the call and knows what it
paid. A gateway on its own deal was reported at list price and one running flat-rate was reported as
owing money. `setUsage(…, priced: true)` keeps what arrived, which is upstream assigning
`partial.usage = proxyEvent.usage` whole. The other side of trusting the wire, stated rather than
worked around: a gateway that sends no `cost` is reported as costing nothing — upstream's own type
requires the field, so an absent one is taken at its word instead of guessed at from a price list
pig has no reason to think applies. The token *total* is still filled in when the gateway omits it,
because that is arithmetic on numbers it did send.

Not ported: `validateToolCall(tools, call)`, which has no caller upstream either, and
`ProxyAssistantMessageEvent`, which is a TypeScript type whose counterpart is the event classes in
`pig/ai`.

## An `Agent` nobody configured has a model, and it is the free one

`AgentState::DEFAULT_PROVIDER` and `DEFAULT_MODEL` are upstream's default —
`google/gemini-2.5-flash-lite-preview-06-17`, the cheapest thing Google sells, which is the only
reason a default is defensible at all: five lines of library code answer without anybody choosing a
model, and choosing an expensive one for somebody is the thing a default must not do. pig had `null`
here and threw `No model configured`, which is safer in one way and unhelpful in the way that
matters, since upstream's own tests and examples assume an agent that works out of the box.

Two constants rather than a parsed string, so there is one place to change it. **`bin/pig` never
reaches this**: `CodingAgent::session()` resolves its own model — `--model` (with `--provider`), then
the settings, then `claude-sonnet-4-5`; no environment variable names a model, as upstream reads
none — and calls `setModel()` before anything is sent, so nobody's
coding session quietly goes to Google.

`$model` stays nullable and `Agent::prompt()` keeps its `No model configured`, because
`Models::find()` answers null if that id ever leaves the registry — and the honest message for that
is about configuration and not a null three layers down. Three tests that used to get "no model" for
free now clear it explicitly, which is what the state they are testing actually is: a model that was
lost, not one never chosen.

## The upstream dependencies that no package can replace: the provider SDKs

The five dependencies above were choices. **This one is not, and it is the largest single
difference between the two trees**, so it is written down with the facts it was decided on rather
than left to be rediscovered.

`pi-ai` does not implement most provider protocols itself. `@anthropic-ai/sdk` (0.129.0), `openai`
(7.19.0) and `@google/genai` (2.21.0) are in its `dependencies`, and each one builds the request,
reads the event stream and hands back typed events — except where upstream has written its own: at
HEAD `anthropic-messages.ts` sends through the SDK (`.asResponse()`) but parses the event stream by
hand, and `mistral-conversations.ts` calls `fetch` and parses by hand, as `agent/proxy.ts` does.

**What an SDK says is part of the port.** A refused request, a malformed event and a body that is not
JSON reach the person (and `Retry`/`Overflow`) in the SDK's words — the `APIError` message, the
`openai` stream's fixed `malformed server-sent event JSON` line, `@google/genai`'s error JSON and its
`got status: …` / `Incomplete JSON segment at the end`, and V8's own `JSON.parse` text wherever the
SDK lets a `SyntaxError` through. `Utils\ErrorBody` builds each SDK's error from the status and the
raw body as that SDK's pinned version does, and `Utils\JsJson` produces V8's parse messages (swept
against `node`); neither is pig's own wording.

pig hand-writes all of it: 4,734 lines across `Providers/` and `Http/`, with 144 tests over them.
That is not a preference. As of the date on this section:

- **Anthropic has an official PHP SDK** — `anthropics/anthropic-sdk-php`, PHP `^8.1`, on `psr/http-client`
  via `php-http/discovery`.
- **OpenAI has none.** `openai-php/client`, the one everybody uses, describes itself as
  community-maintained.
- **Google has none.** The official GenAI SDK ships for Python, JavaScript/TypeScript, Go, Java and
  C#; PHP is not on the list, and the PHP Gemini clients are community projects.

So the SDK route covers **one provider out of five**, and the other four are hand-written either
way — which would leave the tree less consistent than it is now, not more.

**And that one has a conflict that matters more than the count.** The official PHP SDK streams by
iterating a PSR-18 response body synchronously, and its own documentation says what happens with a
client that does not stream: *"With a buffering client, the `foreach` loop yields every event at once
when the response completes instead of incrementally"*. Even with Guzzle, the loop blocks the one PHP
thread while it waits for the next event. pig's whole shape is one `stream_select()` waiting on the
model's socket **and** the keyboard at the same time — the first paragraph of the README. A blocking
read inside `AgentLoop` costs interrupting a running turn with Esc, typing while the model streams,
and the spinner moving at all.

There is a route that would work, and it is worth knowing about rather than reinventing later: PSR-18
is only an interface, so a PSR-18 client and a PSR-7 stream backed by `Pig\Async\Socket` would make
the SDK's `foreach` suspend its fiber instead of the process, and the loop would keep running
underneath. It was not taken, and the reason is the arithmetic above: five new packages
(`anthropic-sdk-php`, `psr/http-client`, `psr/http-factory`, `php-http/discovery`,
`standard-webhooks`) plus an adapter, to replace one of five providers and leave the other four as
they are.

Two things follow for anybody reading this later. The first is that `Http\SseParser`,
`Http\ChunkedDecoder` and `Http\HttpClient` are **load-bearing in a way they are not upstream**: a
bug in them shows up as tokens going missing rather than as an error, and there is no vendor
implementation to fall back to. The second is that this is a dated judgement, not a principle — if an
official PHP SDK appears for OpenAI and for Gemini, and the streaming question is answerable, the
arithmetic changes and this section should be re-run rather than quoted.

## Vertex AI and Amazon Bedrock: two more SDKs, emulated rather than wrapped

`google-vertex.ts` and `bedrock-converse-stream.ts` are thin over `@google/genai` +
`google-auth-library` and `@aws-sdk/client-bedrock-runtime` + the AWS credential providers, so the
port is mostly of the SDKs — in plain PHP, with no new composer package:

- **Bedrock** — `Providers\Bedrock` (the provider, a literal port) over `Utils\Aws\`:
  `BedrockRuntimeClient` (config resolution — region, FIPS/dual-stack, `AWS_MAX_ATTEMPTS`, endpoint
  overrides — the schema-ordered request serializer, the SDK's headers and user agent with its
  `m/…` business metrics, the standard retry strategy, clock-skew correction, the first event read
  inside the call), `SignatureV4` (smithy's signer, checked against AWS's own suite),
  `EventStream` (the binary framing with both CRCs), `CredentialChain` (the default provider chain:
  env, `~/.aws` profiles with assume role / web identity / `credential_process` / SSO,
  `credential_process`, web identity, ECS/EKS, IMDS), `SharedConfig` (smithy's ini reader),
  `RestJson`/`ServiceError` (restJson1 errors), `Endpoints`.
- **Vertex** — `Providers\GoogleVertex` shares `Google`'s chunk walk and `GoogleShared`;
  `Utils\GoogleAuth` is ADC (a key file, `GOOGLE_APPLICATION_CREDENTIALS`, gcloud's well-known
  file, the metadata server) with RS256 JWTs through `openssl_sign()` and gaxios's retries.

**How it was checked: an oracle, not a reading.** Upstream's `src` with its lockfile's npm packages
was run under `node --experimental-strip-types` against the same canned server as pig, scenario by
scenario, and what it sent and emitted was recorded. `packages/ai/test/fixtures/bedrock/` and
`fixtures/vertex/` are those records, and `BedrockTest` / `GoogleVertexTest` replay every one through
pig and hold it to the record — request bodies byte for byte, headers, retry attempts, events, the
final message. A fixture is upstream's behaviour; a mismatch is pig's bug unless the test says why
not (the two exclusions are written in the tests: an abort's exact cut point, and undici's `accept`).

What is knowingly not there: HTTP/2 (pig speaks HTTP/1.1; upstream forces it too under
`AWS_BEDROCK_FORCE_HTTP1`), the adaptive retry mode and the retry quota, `login_session` profiles and
MFA, IMDS static stability, Vertex's project-id discovery and the external-account /
impersonated / GDCH credential types, a parallel race of the two metadata hosts.

## Azure OpenAI, ChatGPT's Codex backend and `pi-messages`

Three more APIs, ported the way Vertex and Bedrock were — literally, and checked by an oracle rather
than by reading:

- **`azure-openai-responses`** — `Providers\AzureOpenAiResponses` over `AzureOpenAiConfig`
  (`api/azure-openai-config.ts`: the endpoint from `azureBaseUrl` / `AZURE_OPENAI_BASE_URL` /
  `azureResourceName` / `AZURE_OPENAI_RESOURCE_NAME` / the model's `baseUrl`, normalized to
  `/openai/v1` on an Azure host; the deployment from `azureDeploymentName` /
  `AZURE_OPENAI_DEPLOYMENT_NAME_MAP`; the API version, `v1` by default). The `azure` provider's Chat
  Completions arm is `Providers\Azure` (`providers/azure.ts`' `azureStreams()`), which `Stream`
  takes for any `openai-completions` model whose provider is `azure`.
- **`openai-codex-responses`** — `Providers\OpenAiCodexResponses`, the ChatGPT backend, signed in
  through `Utils\Oauth\OpenAiCodex` (`auth/oauth/openai-codex.ts`: browser PKCE on port 1455 with the
  paste box beside it, or the device code; `accountId` stored on the credential, as pi stores it).
  `Utils\Oauth\DeviceCodeFlow` is `device-code.ts`' shared poller, which only this flow uses so far.
  **Its transport is upstream's `auto` by default**: a `wss://…/codex/responses` connection
  (`Http\WebSocket`, RFC 6455 over `Socket`, through `HttpClient`'s proxy) cached per session and
  account (5-minute idle TTL, 55-minute age limit), `previous_response_id` continuation sending only
  the input delta (`auto` / `websocket-cached`), one retry each for `previous_response_not_found` and
  `websocket_connection_limit_reached`, and SSE when the socket fails before the answer starts — a
  `provider_transport_failure` diagnostic, and that session stays on SSE. **The idle TTL is checked
  when the connection is next asked for, not by a timer**: pig's loop has no `unref()`, and a pending
  timer would hold a `-p` run open for five minutes. `AgentSession` closes the session's connection
  on `dispose()` and on `writeTo()` (upstream's `cleanupSessionResources()`).
  `OpenAiCodexWebSocketTest` runs it against `test/WebSocketServer.php`, which speaks both.
- **`pi-messages`** — `Providers\PiMessages` and `PiMessagesEventConverter`, pi's own wire protocol,
  which the Radius gateway speaks; `Providers\RadiusConfig` is `radius-config.ts`' catalogue half.

The rows: `Models::AZURE_MODELS` is upstream's clone of the `openai` Responses rows (before the
metadata, so no strict mode, tool search or models.dev efforts, no tiers, Azure's 1,050,000 windows)
plus DeepSeek V4 Pro on Chat Completions; `OPENAI_CODEX_MODELS` is upstream's hand-kept `codexModels`;
`RADIUS_MODELS` is the gateway's public `/v1/config`. All three were written from upstream's
published catalogue (`@earendil-works/pi-ai` 1.1.0) because neither models.dev nor the gateway was
reachable, and `scripts/generate-models.php` has the rules that write them (`azureRows()`,
`codexRows()`, `radiusRows()` / `--radius-from`). All three are in `RESOLD`.

The records: `packages/ai/test/fixtures/{azure,codex,pi-messages}/`, `codex-oauth/` and
`radius-config.json` are upstream run under Node against the same canned servers;
`AzureOpenAiResponsesTest`, `OpenAiCodexResponsesTest`, `PiMessagesTest`, `OpenAiCodexOauthTest` and
`RadiusConfigTest` replay them. `AzureOpenAiCompletionsTest` is upstream's
`azure-openai-completions.test.ts` against `Models`' own DeepSeek row.

What is knowingly not there:

- **zstd** on the Codex SSE body: PHP has no zstd without an extension; upstream sends it plain where
  `node:zlib` has none, and so does pig.
- **Radius's sign-in and dynamic catalogue**: `auth/oauth/radius.ts`, a `models.json` provider with
  `oauth: "radius"`, a custom gateway, the catalogue cached on the credential, and
  `radiusProvider().refreshModels()` (pig has no models store) — so Radius is `RADIUS_API_KEY` with
  the public catalogue. The bug-report upload to the gateway is not ported either.
- **TypeSafe** has no chat models — only the `typesafe-system-one` classifier API, which is
  `Models::classify()` (see the trap on classifiers and image models).

## Provider attribution (`ProviderAttribution`)

Upstream's `provider-attribution.ts`, under pig's name: `X-OpenRouter-Title: pig` with
`https://pigagent.dev`, `X-BILLING-INVOKE-ORIGIN: Pig`, `User-Agent: pig-coding-agent` for
Cloudflare, and `x-opencode-client: pig` beside OpenCode's session header. The first three ride on
`enableInstallTelemetry` (on by default; `PIG_TELEMETRY` overrides it either way); the request's own
headers win over all of them, and the `before_provider_headers` hooks see the result
(`AgentSession::buildRequestOptions()`). **There is no install ping**: upstream's reports to pi.dev,
which counts pi's installs. `enableAnalytics` is not ported — upstream reads it nowhere.
