# Design: Tools and the CLI

Built-in tools, subprocesses, command-line flags, directories and configuration. Moved out of `CLAUDE.md` as written — "this file" in the entries below means `CLAUDE.md`, where they were first written. The index is generated from the headings below.

- [A tool result's `details` is pi's shape, under pi's names](#a-tool-results-details-is-pis-shape-under-pis-names)
- [`pig-ai`, the second entry point](#pig-ai-the-second-entry-point)
- [Tidying pi's directory, which is pig's problem](#tidying-pis-directory-which-is-pigs-problem)
- [Checking a tool call against its schema](#checking-a-tool-call-against-its-schema)
- [Starting up, and why it had to stop living in `bin/pig`](#starting-up-and-why-it-had-to-stop-living-in-binpig)
- [Where the startup time went](#where-the-startup-time-went)
- [Five upstream dependencies that are not needed here](#five-upstream-dependencies-that-are-not-needed-here)
- [Upstream's tool and loader flags](#upstreams-tool-and-loader-flags)

## A tool result's `details` is pi's shape, under pi's names

`details` is written into the session file, and the session file is pi's — so a name pig invented
here is a field pi cannot read. It cost nothing pig could see and everything pi could: a
conversation pig had written opened in pi with its truncation records unreadable, so pi drew no
truncation warning on any of them. The keys are now upstream's exactly, `notice` aside:

| Tool | `details` |
|---|---|
| `read` | `truncation` (only when something was cut) |
| `bash` | `truncation`, `fullOutputPath` |
| `ls` | `entryLimitReached`, `truncation` |
| `find` | `resultLimitReached`, `truncation` |
| `grep` | `matchLimitReached`, `truncation`, `linesTruncated` |

A key whose value would be null is left out rather than written as null, which is upstream's shape
too — its details start empty and it only assigns the ones that apply, and `read` has no `details`
at all when the file fitted.

**`notice` is the one key upstream does not have**, and it is set in one case upstream leaves
empty: a `limit` that stopped short of the end of the file, where nothing was *truncated* — the
model asked for five lines and got five — but there is more file and both readers should know.

Renames, kept because they are what a `grep` for the old name should find:

| pig had | pi's name, now pig's |
|---|---|
| `Truncation::$by` | `$truncatedBy` |
| `Truncation::$lastPartial` | `$lastLinePartial` |
| `Truncation::$firstTooBig` | `$firstLineExceedsLimit` |
| `details['fullOutput']` (bash) | `details['fullOutputPath']` |

`Truncation` also gained `maxLines` and `maxBytes`, which upstream records and pig had left to its
constants. They are per call and not constant: `ls`, `find` and `grep` pass `PHP_INT_MAX` for the
line limit, because the entry, result and match limits above them already cap how many there are.

One deviation from upstream, and it follows from showing the tool's own sentence rather than a
second one: the component prints the notice **only while the preview is cut**. Upstream prints its
warning either way, which it can afford because its two sentences are worded differently. Two
identical lines under each other read as a rendering fault, and an expanded view already has the
tool's own copy on screen.

`bash` carries `notice` too, though nothing reads it there. A shape that holds for four tools and
not the fifth is the next person's puzzle, and the reason it is unread — that one preview keeps the
tail — is a fact about that tool, not about what a result is allowed to say.

`Components\Rule` is upstream's `DynamicBorder`, renamed: "dynamic" there means "asks the width
at render time", which is what every component in `pig/tui` does — there is no static kind for it
to be the opposite of, and what it is is a rule. The colour is a closure taken at construction,
which upstream's own note argues for in the hardest way available: its `theme` is a module-level
global, and a hook loaded through jiti gets a second module cache where that global is
`undefined`, so a border drawn from inside a hook crashed on a theme present everywhere else. pig
has no module caches, but the shape is right for a second reason — `/theme` replaces the palette,
and a component holding a closure it was given cannot hold a stale colour table.

`Interactive\BorderedLoader` is upstream's, and it is for hooks: a hook that goes away for a while
hands one to `HookUi::custom()`, and the rules are what separate it from the transcript above and
the prompt below. `TerminalUi`'s own dialogs draw no rules, because a question reads as one thing;
this is a **wait**, and a wait with nothing around it reads as output that stopped. It is a
`Container` **and** an `InputHandler`, which is the trap on `SettingsSubmenu` again — and here the
key a container would eat is the only one that cancels.

Its `dispose()` is not a nicety: the spinner reschedules itself, so a loader nobody disposed of is
a loop that never runs out of work and a `bin/pig` that never exits.
`testARunningBorderedLoaderNeverLetsTheLoopSettleAndADisposedOneDoes` is the test, and its name is
long because the first version of it was wrong: it asserted `isIdle()` immediately after
`dispose()`, which is false for a moment because **a pending render counts as work**. That looked
like a bug in `dispose()` and was a bug in the test. The question worth asking is not whether the
loop is idle now but whether its work *ends*.

`Interactive\ArminComponent` is upstream's easter egg, reached by typing `/arminsayshi` — known
and not listed, like `quit`, because `COMMANDS` is also what `/help` prints and the autocomplete
offers, and something you have to already know about is the whole idea. Upstream leaves it out of
its own command list for the same reason.

It earns a place in a port for something other than the joke: it is the only thing in either tree
that draws a **bitmap with half-block characters**, two pixel rows to a cell — `█` for both, `▀`
and `▄` for one. The 144 bytes are upstream's, LSB first, and **a zero bit is foreground**, which
is an XBM convention and the one detail that turns the picture into a photographic negative if it
is guessed at.

**One of the seven effects never ends, in pi and in the first version here.** `rain` finishes a
column when `settled >= height`, and `settled` is `height - target` — so it only reaches `height`
for a column with ink in row 0. Four of these 31 columns have that and two have no ink at all, so
27 can never satisfy it: `allSettled` is never true, the effect never reports done, and because pi
creates this component and **never calls `dispose()`**, a one-in-seven roll leaves a 30fps redraw
running for the rest of the session.

It was found by driving each effect to its end in a test and watching one of them pass 100,000
frames, then by printing the topmost ink row of all 31 columns rather than reasoning about it. The
fix is the condition `settled >= height` was reaching for: a column is done when there is nothing
left above what has landed. Nothing about the finished picture changes — every effect was already
verified to end on the byte-identical grid.

Re-measured during the `armin.ts` audit, forty runs of each, because a number in this file is worth
a re-run rather than a quotation — and the sentence that used to be here said 8 to 186, which is one
out at both ends: **glitch 9, crt 19, scanline 19, dissolve 28, fade 38, rain 137–174, typewriter
187.** Only rain varies, which is the randomness in where each drop starts. All seven end on the
same grid and it is byte-identical to what upstream's `buildFinalGrid` produces from the same 144
bytes, which were compared byte for byte as well.

Three smaller notes:

- **`shuffle()`, not upstream's hand-written Fisher-Yates**, which is what `shuffle()` already is.
  The same rule as `version_compare()`: where upstream is working around a missing library call,
  the port is the call.
- **Three of the seven effects are one function.** typewriter, fade and dissolve are "reveal N
  cells per frame in a given order"; upstream has three copies because the order lives in three
  differently-named state bags. Written once here as `reveal()`.
- **The effect can be named in the constructor.** A seam that upstream has no equivalent of,
  because seven effects cannot be tested by starting this seven times and hoping — and it is what
  found the one that never ended.

`CustomEditor` wraps `Pig\Tui\Components\Editor` rather than extending it — the editor is
`final`, and wrapping keeps the list of keys an application may steal explicit. `Editor` grew
one method for this: `setTheme()`, so the border can change colour with the thinking level.

The terminal is injected, so `InteractiveModeTest` drives the whole front end by typing into a
`FakeTerminal`: `start()` draws the first frame, keystrokes go in by hand, and `run()` — which
blocks on the loop — is the only thing a test never calls.

`Theme\Colour` carries the one piece of real arithmetic in there: fitting a hex colour onto
a 256-colour terminal. The guard worth knowing about is that the grey ramp only wins when
the colour was nearly neutral to begin with — without it every muted tone in the theme
snaps to a pure grey and the theme loses its tint.

`Ai\Models` is the registry, and what is in it is the models whose *protocol* is ported — offering
a model and then failing to send the request is a worse answer than "no such model", so the rest
arrive with their protocols.

**The rows are generated now, and the paragraph this replaces is why.** It read *"the figures are
upstream's at the anchor commit, not whatever models.dev says today: a port should agree with the
thing it was ported from"*, and quoted a count per provider. That is fidelity to the bytes and not
to the mechanism: upstream has never kept this table by hand — the file pig transcribed opens with
*"Do not edit manually - run 'npm run generate-models' to update"*, and by upstream HEAD the data is
not in git at all, each provider's catalogue being fetched from models.dev at build time. pig froze
a generator's output and then treated the freeze as a decision, and the counts in this file were the
freeze quoted back. See [The registry was a transcription of a generated
file](../traps/providers.md#the-registry-was-a-transcription-of-a-generated-file).

So `scripts/generate-models.php` writes the rows and the counts are no longer quoted here: they are
whatever the last regeneration found, and the run prints them. The anchor still decides every line
of protocol; it never had anything useful to say about which models a provider sells this week.

The table is keyed `provider/id`, and `get()` used to take an id alone because no two
providers claimed the same one. **Copilot's table is the one that broke that**, exactly where
this said it would: it serves OpenAI's, Anthropic's and Google's models under their own ids, so
`gpt-5` now names two things.

The rule is `Models::RESOLD`, and it is one line of data rather than an ordering: **a bare id
means the direct provider**, and Copilot's is `github-copilot/gpt-5`. Writing it down mattered
more than it looks — building Copilot's table last already gave the right answer, and would
have stopped doing so the first time somebody moved a `foreach`. `Models::isResold()` is public
so `ModelResolver` asks the same question rather than keeping its own copy, in both the
exact-id pass and the sort over substring matches.

Two things about it that are easy to get backwards:

- **A resold id is still an answer**, just never the first one. `oswe-vscode-prime` is VS Code's
  own preview model and no direct provider carries it, so `get()` falls back to the reseller
  rather than answering null.
- **`grok-code-fast-1` looked Copilot-only and is not** — xAI's table carries it too, so a bare
  id means xAI's. That was a wrong guess in a test, not in the code, and the test now asserts
  both halves.

`ModelsTest::testEveryDuplicatedIdIsOneOfTheResoldOnes` replaced the uniqueness check: two
providers may share an id only when one of them is reselling, and any other collision is a typo
in a table that would make `get()` answer with whichever was written first.

It replaced a `CodingAgent::model()` that invented the figures around an id — 200k of context,
64k of output, reasoning on. Right for one model and wrong for most: `claude-3-haiku` caps
output at 4096 and cannot reason, so `--model claude-3-haiku-20240307` built a request the
provider rejects, from a flag that looked like it had worked.

`Providers\OpenAiCompletions` is upstream's `openai-completions.ts`, and it is worth more than
the one name on it: Groq, Cerebras, Zai, OpenRouter and GitHub Copilot's Gemini and
Kimi models all answer this shape (Copilot's Claude speaks `anthropic-messages` and its `gpt-`,
`grok-`, `oswe` and `mai-` models `openai-responses`, as upstream's generator routes them). Structurally it differs from `Anthropic` in one way that matters — **Anthropic
numbers its content blocks and says when each opens and closes; this does not.** A block runs
until something of a different kind arrives, so the boundaries are worked out in the provider,
and that is the only real complexity in the file. `AssistantMessageBuilder` grew `nextWire()`
and `setToolCall()` for it: OpenAI numbers nothing, and a tool call's id and name arrive in
whichever delta they arrive in.

`Ai\OpenAiCompat` is the table of ways an "OpenAI-compatible" endpoint is not one. Grok rejects
`reasoning_effort`, Cerebras rejects `store`, z.ai streams tool calls only with `tool_stream`. None of that is documented anywhere as a difference; it is what a 400 looks like
after you have sent it. A table per endpoint rather than one rule, for the same reason the tool
archive names are a table — see that note above. `detect()` is upstream's `detectCompat()` flag by
flag (provider name or URL; only DeepSeek's URL check ignores case), and a model's own `compat` is laid over it **key by key**
by `resolve()` — upstream's `getCompat()`. Every field is nullable and null means "not said".
`openRouterRouting` and `vercelGatewayRouting` are upstream's two routing objects: read off the
model's own compat (not the resolved one), sent as `provider` and `providerOptions.gateway`, and
merged provider → model one level deep in `models.json` like the template values.
Mistral is not on this API at all: `Providers\Mistral` is upstream's `mistral-conversations.ts`
(thinking as content chunks, `reasoning_effort` from the model's map or `prompt_mode` for one
without, nine-character tool ids it makes itself, `Mistral API error (<status>): <body>`), and the
four Mistral rules `detect()` used to keep from before upstream's move are gone.
Caching on completions is upstream's too: `prompt_cache_key` (the session id, 64 code points) for a
base URL containing `api.openai.com` unless `cacheRetention` is `none`, and anywhere for `long` where
`supportsLongCacheRetention` (detected false for Together, Cloudflare, NVIDIA, Ant Ling), with
`prompt_cache_retention: "24h"`; the session id as headers only where `sendSessionAffinityHeaders`
(detected: OpenRouter) — `x-session-id` for the `openrouter` format, else `x-client-request-id` and
`x-session-affinity` (plus `session_id` for `openai`); and `cacheControlFormat: "anthropic"` (detected:
an OpenRouter `anthropic/…` model) puts Anthropic's `cache_control` on the system prompt, the last tool
and the last conversation text, `ttl: "1h"` for `long`. `Stream::simple()` clamps the level to the
model's map (`Model::clampThinkingLevel()`) for completions as for Responses.
`thinkingFormat` decides how thinking is switched on and how hard — `reasoning_effort` (`openai`),
`thinking: {type}` (`zai`, `deepseek`), `reasoning: {effort}` (`openrouter`), `enable_thinking`
(`qwen`), `chat_template_kwargs` / `chat_template_args` with `$var` placeholders (`chat-template`,
`qwen-chat-template`, `baseten`) and the rest of upstream's list — in `OpenAiCompletions::thinking()`,
arm for arm. An `anthropic-messages` model carries `AnthropicCompat` instead (`forceAdaptiveThinking`,
`supportsStrictTools`, `supportsTemperature`, `supportsEagerToolInputStreaming`,
`supportsMidConvoEffort`), which is metadata `Models` writes on the built-ins as upstream's generator
does (`AnthropicCompat::forBuiltIn()`); nothing at request time looks at a Claude model's id. An
extension's models carry the compat the extension wrote and nothing derived from the id — upstream
spreads the definition. A thinking turn sends `display: "summarized"` unless
`AnthropicOptions::$thinkingDisplay` says otherwise, and a turn without thinking (`thinkingEnabled:
false`, which `Stream::simple()` always says) sends `thinking: {type: "disabled"}` unless the model's
`thinkingLevelMap` has `off: null`. `Models::thinkingLevelMap()` writes each built-in model's whole
map as upstream's generator (`applyThinkingLevelMetadata()`) does — for Claude `max` on the adaptive
4.6 models, `xhigh`/`max` from Opus 4.7 on, `off: null` on Fable 5 and the managed-effort models, the
full map on the 5.5 overrides and Copilot's `minimal: "low"` overrides; for OpenAI and Copilot GPT
`off: null` on a Responses `gpt-5…`, `off: "none"` on upstream's none-reasoning list, `xhigh` from
gpt-5.2 on, `max` on gpt-5.6/gpt-6, the whole GPT-6 maps, Copilot's `minimal: "low"` on `gpt-5…` —
so `xhigh` and `max` are offered exactly where upstream offers them (`ThinkingLevel::Max`,
`Model::supportedThinkingLevels()`; a token-budget provider spends `high`'s budget on `max`). The
session opens on `medium` when nothing says otherwise (`CodingAgent::DEFAULT_THINKING_LEVEL`,
upstream's), clamped to the model. The `max_tokens` default is upstream's: `options.maxTokens ?? model.maxTokens`
in the provider, and `Stream::simple()` always says — the model's ceiling cut to the room the
conversation leaves (`clampMaxTokensToContext()`, `Utils\Estimate`), and on a budget-thinking turn
raised by the budget (minimal 1,024, low 2,048, medium 8,192, high 16,384) up to the model's own, the
budget cut to leave 1,024 for the answer. Caching is upstream's `getCacheControl()`: breakpoints on the
system prompt, the **last tool** (unless `supportsCacheControlOnTools: false`) and the last user
block, `ttl: "1h"` for `cacheRetention: long` (or `PIG_CACHE_RETENTION=long`, upstream's `PI_CACHE_RETENTION`; the `PI_` name is not read), none at all for `none`;
the session id goes out as `x-session-affinity` (`x-session-id` for OpenRouter's format) where the
compat asks for it. `toolChoice` and `metadata.user_id` go out as upstream sends them. A model with
`allowedFallbackModels` (Fable 5 → Opus 4.8/5, as upstream's generator writes it) sends `fallbacks`
and the `server-side-fallback` beta, a turn the fallback answered is priced at the fallback's rate, and
a `fallback` block after output has started ends the turn. A refusal, a `sensitive` stop and an
unknown stop reason all end as an error event with upstream's message; thinking with no signature
goes back as text unless the compat says `allowEmptySignature`. `temperature` is left out of a thinking
turn, of a managed-effort model's turn and of a model whose compat says `supportsTemperature: false`
(Opus 4.7+). Each tool goes out with `eager_input_streaming: true`; the
`fine-grained-tool-streaming` beta is sent only for a request with tools to a model whose compat
says it does not take that field, and the `anthropic-beta` header is upstream's `getBetaFeatures()`
list — or, when the model's own headers carry one, that list alone. Every request carries
`anthropic-dangerous-direct-browser-access: true`, as the SDK's does upstream. A managed-effort model
(`supportsMidConvoEffort`: Opus 5/5.5, Sonnet/Haiku 5.5, Fable 5.1 on `anthropic`) always thinks
adaptively with `block_binding: {prefix_mismatch_behavior: "drop_block"}` and top-level effort
`high`, sends the two managed-effort betas, records the effort it was asked for on the answer
(`AssistantMessage::$providerThinkingLevel`), and gets an effort-only system message in front of
each earlier turn of its provider that recorded one, plus one with the current effort at the end.
A turn starts at `StopReason::Pending` (upstream's `"pending"`) and only `message_delta`'s
`stop_reason` replaces it: a body that ends without one fails with `Anthropic stream ended without a
stop reason`, one that sent `message_start` and never `message_stop` with `Anthropic stream ended
before message_stop`, and an `event: error` ends the turn with the event's data, whole, as the message.

`Providers\TransformMessages` (upstream's `api/transform-messages.ts`) is what makes `/model`
safe across models. Three things get cleaned up before any provider sees the history. First,
**signatures only go back to the model that made them** — upstream's `isSameModel`, provider, API
*and* model id all equal. For any other model, even another one of the same provider, a thinking
block becomes plain text (no tags, so the model does not learn to mimic them), empty thinking and
redacted thinking are dropped, a text block loses its `textSignature` and a tool call its
`thoughtSignature`. Second, **an assistant turn that errored or was aborted is not replayed at
all**, its calls included: it is incomplete (reasoning with nothing after it, a call cut off
mid-arguments) and the model retries from the last turn that finished. Third, a **tool call with
no result gets one invented** (`No result provided`, `isError: true`) — before the next assistant
or user message, and at the end of the conversation — because an interrupted turn leaves a
dangling call and every provider rejects the whole conversation rather than ignoring it. A stated
"No result provided" is worse than the truth and far better than a request that cannot be sent at
all. A `SystemMessage` that lands between a call and its results is held back until the results
are in (or the synthetic one is written), as upstream's is — a provider rejects anything between a
`tool_use` and its `tool_result`.

**Tool call ids are each provider's rule, passed in.** `TransformMessages::apply()` takes upstream's
optional `normalizeToolCallId` closure and runs it on every call of a message that is not from this
very model, renaming the result to match. Anthropic: `[^a-zA-Z0-9_-]` → `_`, cut to 64. Chat
completions: a `call_id|item_id` becomes `call_item` (sanitised), or the call id's head and a hash
when that passes 40; no `|`, unchanged — except `openai`, cut to 40. Responses: each part sanitised,
cut to 64, trailing `_` stripped; the pair survives only for openai, openai-codex and opencode, with
another provider's item id replaced by `fc_` + hash — everywhere else, Copilot included, the whole id
is one sanitised call id. Gemini: Anthropic's rule, only for a `requiresToolCallId()` model. There is
no Copilot special case: Copilot's ids crossing between its two APIs are covered by the general rules.
Upstream's `shortHash()` (`utils/hash.ts`) is ported to the digit as `Pig\Ai\Utils\ShortHash` (UTF-16
code units, `Math.imul` arithmetic), so a rebuilt id is the one pi would build; `ShortHashTest` holds
values taken from Node.

`Providers\OpenAiResponses` is upstream's `openai-responses.ts` — what gpt-5 and codex speak,
and the third shape in three providers. A response is a list of *items* and the stream says
when each opens and closes, which after `openai-completions` is a relief. Two things in it have
no counterpart anywhere else:

- **A reasoning item goes back whole.** What arrives as text is a *summary*; the reasoning
  itself is encrypted and opaque, and the model wants its own item back verbatim or it reasons
  from nothing again. So the entire item is kept as the thinking block's signature and replayed
  as it came — which is what `TextContent::$textSignature` and the builder's `setSignature()`
  are for. Asking for it at all needs `include: ["reasoning.encrypted_content"]`. The thinking
  *text* is rebuilt from the finished item too, as a message's text is: the `summary` parts joined
  by a blank line, else the `content` parts (raw reasoning, which also streams as
  `response.reasoning_text.delta`), else what the deltas built.
- **A message item's id goes back with its phase.** A text block's `textSignature` is upstream's
  `TextSignatureV1` JSON, `{"v":1,"id":…,"phase":…}`; a plain id string from an older session is
  read as the id. No id gives `msg_pi_<msgIndex>` (`_<textBlockIndex>` after the first text block),
  where `msgIndex` counts the messages that went out; an id over 64 is `msg_` + `shortHash(id)`.
- **A tool call has two ids.** `call_id` addresses the result, `id` is the item's own, and both
  have to go back, so they travel joined as `call_id|id` and are split on the way out. The `id` is
  left out — as upstream's `undefined` is — for a call with no `|`, one whose item id does not start
  `fc_`, and one from another model of the same provider and API (upstream's `isDifferentModel`:
  its reasoning item is not sent back, and OpenAI refuses an `fc_` paired with a missing `rs_`).
- **A tool result's images go inside its `function_call_output`** — upstream's
  `convertToolResultOutput()`: `output` is then a list, an `input_text` when there is text and an
  `input_image` (`detail: "auto"`, data URL) per image; without images, or for a model that takes
  none, it is the string (text, else `(see attached image)`, else `(no tool output)`).

The request is upstream's `buildParams()`: `store: false` always; `prompt_cache_key` (the session
id, cut to 64 code points) unless `cacheRetention` is `none`, with `prompt_cache_retention: "24h"` for
`long` — or, on the GPT-5.6-and-later models that take it (`supportsExplicitPromptCacheMode`),
`prompt_cache_options` instead; `max_output_tokens` never below 16; `service_tier` and `tool_choice`
when asked. The system prompt is a `developer` turn for a reasoning model unless its compat says
`supportsDeveloperRole: false`. The effort is what the model's `thinkingLevelMap` calls the level,
with `summary: OpenAiOptions::$reasoningSummary ?? "auto"` (a summary asked for with no level asks for
`medium`), and **thinking off is `reasoning: {effort: map.off ?? "none"}`** — nothing for a map whose
`off` is null, nothing for Copilot. Upstream also merges `samplingParams` last; pig has none to merge.
Only an `incomplete` whose reason is `max_output_tokens` is `length`; any other reason ends the turn
as an error (`Response incomplete: <reason>`). A tool call whose `output_item.done` never arrived is
refused rather than handed to the agent, and a call's `namespace` is kept and replayed to the same
model; `function_call_arguments.done` replaces a call's arguments and sends the tail the deltas missed.
Encrypted reasoning that only `response.completed`'s `output` carries (Azure) is written back into
the stored item. The turn starts at `StopReason::Pending`: a body with no `response.completed`,
`.incomplete` or `.failed` fails with `OpenAI Responses stream ended before a terminal response event`.

**Errors read as upstream's do through the `openai` SDK** (`sdkEvent()`): an `event: error`, or any
event whose data has a truthy `error`, is the SDK's `APIError` message — the error's `message`, or its
JSON — so the live API's nested `{type: "error", error: {code, message}}` reads as the message alone;
upstream's own `Error Code <code>: <message>` arm is reached only by a flat event with no `event:`
line; `response.failed` is `<code || "unknown">: <message || "no message">`, `incomplete: <reason>`, or
`Unknown error (no error details in response)`; non-JSON data is `Error reading response: malformed
server-sent event JSON.` Any error carrying `subscription_sharing_usage_limit_exceeded` — in the
message or in a refused request's body — gets `\nCheck your ChatGPT usage:
https://chatgpt.com/settings/usage`. Tools added mid-conversation (`toolsAdded` on a later system
message) go where the message stands, as upstream's: `additional_tools` on a `developer` item when
the compat says `supportsMidConvoToolAdditions`, otherwise a `tool_search_call`/`tool_search_output`
pair (`call_id` `pi_tool_load_<hash>`) with the tools `defer_loading`; a removal or a redeclaration
anywhere sends the current tool list at the top instead (`Transcript::resolveTranscriptTools()`).

`Providers\Google` is upstream's `google.ts` — Gemini, and the shape furthest from the other
three. A chunk carries a list of *parts*, and a part is text, or thinking (text with
`thought: true` on it), or a whole function call. Which means:

- **A tool call arrives complete**, arguments and all, in one part — so it is opened,
  delivered and closed in the same breath. There is nothing to stream.
- **Thinking and text are the same field**, told apart by a flag, so a block ends where the
  flag changes: the boundary problem from `openai-completions`, one field over.
- **Gemini often sends no id for a call**, and a result has to be addressed to something, so
  one is invented — and a repeat within a message is replaced for the same reason.
- **Saying nothing about thinking sends no `thinkingConfig`; asking for none sends a disabled one.**
  `GoogleOptions::$thinkingEnabled` is `?bool` and null is upstream's `options.thinking` left unset:
  no `thinkingConfig` at all, and Gemini decides. `false` is upstream's
  `getDisabledGoogleThinkingConfig()`: `thinkingBudget: 0`, except on a level model that has no
  `off` (3.1 Pro, 3.5 Flash Lite, 3.7/3.8 Flash), which gets the level `off` clamps to.
  `Stream::gemini()` (the simple path, which every agent turn takes) always says one or the other.
- **Thinking is said two ways.** A model upstream's `usesGoogleThinkingLevel()` matches — Gemini 3
  Pro/Flash with or without a minor version, `gemini-flash-latest`, `gemini-flash-lite-latest`,
  Gemma 4 — takes a named level and ignores a budget; the rest take a budget in tokens, from
  `getGoogleBudget()`'s table (2.5 Pro, 2.5 Flash-Lite, 2.5 Flash, else -1) at the level the
  model's `thinkingLevelMap` resolved to. `Stream::gemini()` picks; the provider sends whichever
  arrived. The helpers are in `GoogleShared`, as in upstream's `google-shared.ts`.
- **Eighteen of its twenty finish reasons mean "no"** — safety, recitation, a malformed call, a
  language it will not answer in. Only `STOP` and `MAX_TOKENS` are not errors.

Upstream hands this to `@google/genai`; the REST endpoint is called directly here —
`:streamGenerateContent?alt=sse`, which is what that package does underneath. Without `alt=sse`
the response is one enormous JSON array rather than a stream.

`Providers\GoogleShared` is upstream's `google-shared.ts`, and it exists now for the reason it
exists there: **two providers speak this shape.** Google Cloud Code Assist wraps the same request
in a project envelope and answers with the same chunk one key deeper, so what a Gemini request
and a Gemini chunk look like is not `Google`'s private business any more. All of it used to sit
in `Google` as private methods — 609 lines — because there was one caller; the extraction moved
about 400 of them out and `Google` is 207. The developer's call, with the alternative being a
second copy of the conversion and CLAUDE.md's own rule against two implementations that can
disagree about what a request looks like.

Three things about the split:

- **The caller unwraps.** Code Assist's chunks arrive under a `response` key, and
  `GoogleShared::onChunk()` is handed the candidate rather than being told which provider it is
  speaking for.
- **pig shares more than upstream does.** Upstream keeps the chunk walk in each provider and
  shares only the request conversion; the chunk is the same shape one key deeper, and two walks
  over it are two things that can come to disagree about what a Gemini answer is.
- **Each provider keeps its own business**: where it sends, how it authenticates, how it words a
  failure, and the body it assembles around the shared pieces. `explain()` stayed behind in both
  for that last reason — the message names the provider.

An assistant turn goes back as upstream's `convertMessages()` sends it: **a signature only goes
back to the same provider and model** (upstream's `isSameProviderAndModel`, which leaves the API
out) **and only when it is base64** — Gemini declares the field `TYPE_BYTES`, so anything else is a
400 for the whole request. A text block's `textSignature` goes back as that part's
`thoughtSignature`, and an empty text part is kept when it carries one. A thought from the same
model is `thought: true` with or without a signature; from another model it is plain text, no
tags. A call's `id`, on `functionCall` and `functionResponse` alike, goes only to a
`requiresToolCallId()` model — `claude-`, `gpt-oss-`, or a Gemini major version of 3 or more — and a
tool result's images go inside its `functionResponse` unless the model is a Gemini older than 3
(upstream's `supportsMultimodalFunctionResponse()`: not Gemini at all counts as yes).

Tools on the direct API are upstream's `buildParams()`: `convertTools(tools, false, strict)` sends
`parametersJsonSchema`, and `resolveGoogleFunctionCallingMode()` picks the mode — `none`/`any` as
asked, else `VALIDATED` when any tool goes strict (`Tool::$constrainedSampling` of type
`json_schema`, on a model where `supportsGoogleStrictToolSampling()` — Gemini 3+), else the mapped
choice or nothing. The built-in bash, edit, read and write ask for `strict: "prefer"`, as upstream's
do, so a Gemini 3 coding session sends `VALIDATED`. The Code Assist path (`pig-antigravity`) keeps
`tools()` and `toolConfig()`: upstream has no Code Assist provider left to compare against.

It was an extraction and nothing else, which is what `GoogleTest`'s thirty-one cases passing
unchanged is the evidence for. A move that needed a test changed would have been a rewrite.

`ModelResolver` is upstream's `model-resolver.ts`: `sonnet` finds the model, `sonnet:high`
finds it and sets the thinking level. When several match, the alias beats the dated build
behind it — someone typing `sonnet` wants the current one, not the June 2024 build that sorts
first. The pattern is tried whole before it is split on a colon, because an id can contain one
(OpenRouter's `:exacto`). `scope()` is upstream's `resolveModelScope()` — what `--models
sonnet:high,'anthropic/*'` narrows a session to, `fnmatch()` in place of `minimatch` — and the
trap entry on the two flags swapping names says what porting it decided.

`Export\HtmlExport` and `Export\MarkdownHtml` are upstream's `core/export-html/`, with the
rendering moved from the browser into PHP. Upstream's export is 211 lines of logic, 65KB of
templates, and **160KB of vendored `marked.min.js` and `highlight.min.js`** — and `marked` is
the dependency `Pig\Tui\Markdown` was written to replace, so shipping it in the export would
be the same dependency through a side door. The lexer and the highlighter are already here;
`MarkdownHtml` is the second thing that walks their output, and the file it writes has no
script in it at all. The same conversation comes out at 5KB rather than 230KB, and it reads
with JavaScript off, prints, and greps.

`Highlight` is reused through the seam that already existed for theming: a `HighlightTheme`
whose closures write `<span class="hl-keyword">` instead of an escape sequence. The one trap
in that is below.

**The system prompt and the tool list are not in it**, where upstream's payload carries both for
its viewer to show. An export is what gets pasted into a ticket or sent to a colleague, and what
belongs there is the conversation: the prompt is pig's own text plus whatever `AGENTS.md` and the
skill list put in it, and the tools are the same seven every time. Two more sections to leave out of
every export by hand is worse than not writing them.

`<details>` does the folding, which is the only interaction upstream's JavaScript provided
that was worth keeping. Open or closed is decided on the *text* length, not the number of
newlines — the renderer turns a paragraph of prose into one long line, so counting newlines
folds every code block and never folds any thinking, which is exactly backwards.

`Settings` is upstream's `core/settings-manager.ts`. Two JSON files, both optional:
`~/.pig/agent/settings.json` is the person's and is written back to; `<cwd>/.pig/settings.json` is
the project's and is only ever read. The project wins, which is the point of it being
separate — a repository can say "compaction keeps more here" without touching anyone's
preferences, and a `/theme` typed in that repository still saves to the person's file and
still loses to the project's answer while they are in it.

Upstream is 374 lines, most of it forty getter/setter pairs. The pairs here are only for the
settings something actually reads — theme, model, thinking level, hidden thinking, pictures, the
three compaction numbers, the skill filters — and everything else is reachable through `get()` under
upstream's own key names, so a settings file written by either project is read by both. A key
gains a typed accessor when something needs one, not before.

Three decisions in it:

- **Merged one level deep, not recursively.** Every nested thing in this format is a flat
  group of scalars; a deeper merge would be answering a question the format never asks.
- **A list replaces rather than appends.** A list here is an answer, not a contribution:
  merging two would leave a project no way to *stop* ignoring a skill the person ignores.
- **A file that is not JSON is named, not ignored.** A typo in a settings file is otherwise a
  setting that quietly does nothing for the rest of its life. `bin/pig` prints the complaints
  before the UI starts, the same as it does for a malformed skill.

Order for anything with more than one source: what was typed, then the environment, then what
was chosen last time, then the built-in default. `--no-session` is about the conversation only,
as upstream's: settings, `models.json` and `auth.json` are read (and written) exactly as in a saved
run.

## `pig-ai`, the second entry point

`Cli\SignIn` is upstream's `ai/cli.ts` and `bin/pig-ai` is nine lines over it: `list`, `login`,
`login <provider>`, `help`. `/login` does the same thing inside pig, so this is for the times there
is no terminal UI to be inside — a machine being set up over ssh, a container being built, or a
flow that has to be watched because it is not working.

Two things about it, and the first is the reason the logic is in `Cli\` rather than in the script:

- **The first version was inline in `bin/pig-ai`, and its `$onPrompt` was a `static function`
  with no `use ($ask)`** — so the moment Anthropic's flow asked for the pasted code, it called
  null. Nothing catches that except *reaching the prompt*, and a script that ends in `exit()`
  cannot be reached twice by a test. `Arguments` already says this about itself and the lesson did
  not travel; it has now. `SignInTest` reaches all four flows by escaping each at its first
  question.
- **It writes where pig reads.** Upstream's `AUTH_FILE` is the string `"auth.json"` — a relative
  path, so a login run inside a checkout leaves a file full of refresh tokens next to the code,
  and nothing reads it there afterwards either. Here it goes through `Auth`, into the one file
  `pig` and `pi` both read, with the mode that file gets. The usage text says which file, because
  that is the one thing somebody on a fresh machine needs to know.

**`composer.json` declares `bin` now.** This paragraph used to say the opposite — "declaring
executables is a packaging decision rather than part of this port" — and the decision has since been
taken, because the command the update check prints (`composer global update pigagent/pig`) only works for
an install that puts `pig` on the PATH. There is deliberately **no** `version` field beside it; the
two entries below have that and the rest of the packaging.

## Tidying pi's directory, which is pig's problem

`Migrations` is upstream's `migrations.ts`, two one-time tidies run before `Auth::discover()`.
Neither is about pig's own history, and that is why the old reason for skipping them — "pig writes
pi's format and has never shipped another" — was answering the wrong question. **They migrate
pi's** old shapes, and pig lives in pi's house: it reads pi's sessions and shares pi's `auth.json`.
So a pi install left mid-upgrade has credentials and conversations pi can see and pig cannot.

- **`oauth.json` and `settings.json`'s `apiKeys` into `auth.json`.** Nothing happens at all if
  `auth.json` is already there, which is upstream's first line and the whole safety of it: a file
  that exists is the current shape. An OAuth credential beats an api key for the same provider,
  because signing in is what replaced pasting a key.
- **Session files pi 0.30.0 left at the root of its own directory** instead of under
  `sessions/<the project's path, flattened>/`. Where each belongs is read from the `cwd` on its own
  header line, and filed with `SessionManager::slug()` — the same slug, or this would move a file
  out of pi's sight without bringing it into pig's. Upstream's issue #320.

**pig writing in pi's directory is a real thing to be uneasy about**, so the bound is worth stating
rather than assuming. What happens here is exactly what pi itself would do on its next start, so
running it converges rather than diverges. Nothing is deleted: `oauth.json` is renamed, a session
is moved, a file that already exists at the destination wins. Every step is skipped on the first
sign of trouble, because a half-migration of somebody else's data is worse than none.

**Which is the argument for auditing every difference rather than only the ones that felt like
decisions** — "worse than none" is exactly what a near-miss produces. The audit found two real
gaps and one thing that had been written down as a deviation and is not one:

| Difference | Verdict |
|---|---|
| `glob('*.jsonl')` | **A gap.** A glob does not match a leading dot and upstream's `readdirSync` does, so `.something.jsonl` was a stray session this could not see. Now a scan. The test helper in `MigrationsTest` had the same bug a few lines later, which is how thoroughly the habit travels |
| the message | **A gap.** Upstream shows one warning with the providers joined; this printed one stderr line per provider in its own words. Now upstream's line, once. stderr because that is where pig puts warnings from before the UI exists, beside the settings and credential ones |
| pi's `settings.json` keeps its own permissions | **Not a deviation at all** — upstream's plain `writeFileSync` does the same, and this entry used to claim otherwise. It was a deviation from the *first draft here*, which passed `0600` and would have tightened a file that was never pig's to tighten |
| `auth.json` written with 4-space JSON and a trailing newline | Deliberate: upstream's is `JSON.stringify(x, null, 2)` with no newline, but **`auth.json` is a file pig itself writes**, and `Auth::save()` writes it this way. Matching upstream would make the migration produce a shape pig never produces, so the next ordinary save reformats the whole file |
| the session directory created `0700` | Deliberate: `SessionManager` and `Auth` create directories with `0700` and pig creates this exact one in ordinary use. Upstream's default umask would put one project's sessions under two modes |
| only the first line of a session file is read | Deliberate: upstream reads the whole file to split off line one, and a session file is a conversation long. The header is line one either way |

## Checking a tool call against its schema

`Ai\Utils\JsonSchema` is upstream's `utils/validation.ts` — the AJV part, written out. `ToolArguments`
is still what `AgentLoop` calls and still owns the **message**, which is upstream's format down to
the blank line before the arguments; what moved out is the deciding.

**It replaced a two-rule checker, and the reason is the reason it was written that way.**
`ToolArguments` used to look for a missing required property and a wrong primitive type and pass
everything else through, because nothing was bundled that could read a schema. That was defensible
until there *was* something, and then it was two notions of what a tool's schema means — the shape
that goes wrong quietly, and the narrower one is the one nobody would have thought to look at.

The subset is `type` (one or several), `enum`, `const`, `required`, `properties`,
`additionalProperties`, `items` (single and tuple), the number bounds, `multipleOf`, the string
lengths, `pattern`, the item and property counts, `uniqueItems`, and `anyOf`/`oneOf`/`allOf`/`not`.
Left out with reasons in the docblock: `format` (an annotation in draft-07; `ajv-formats` makes it
an assertion and a tool has a better place to check an email), `$ref` (needs a resolver and a cycle
guard, and a tool's parameters are self-contained everywhere in either tree), and
`if`/`then`/`else`, `dependencies`, `patternProperties`, `propertyNames`, `contains` (nothing uses
them).

**Before the schema, a null for an optional parameter is dropped** — upstream's
`normalizeOptionalNulls()`: present, null, not required, not a `$ref`, and its own schema rejects
null. Strict sampling sends every optional parameter as null when the model leaves it out, and the
tool's own schema still says `number`. **Then the arguments are coerced** — upstream's
`coerceWithJsonSchema()`, ported line for line in `ToolArguments`: `"10"` for a number is 10, `"true"`
for a boolean is true, `5` for a string is `"5"`, a null for a required primitive is that type's zero
(`""`, 0, false), through `allOf`/`anyOf`/`oneOf`, properties, `additionalProperties` and items. The
error still prints the arguments the model sent. **A built-in tool is converted first** the way
TypeBox's `Value.Convert` converts upstream's built-in (`Type.Object`) schemas — `Tool::$typeBox`
marks them, `ToolArguments::valueConvert()` is the part of typebox 1.3.27's conversion their schemas
reach: a null for a required string is `"null"`, `""` for a number is 0, `"TRUE"`/`"1"` are true, a
lone value where an array belongs is wrapped. Upstream skips `coerceWithJsonSchema()` only for a
schema carrying `Symbol.for("TypeBox.Kind")`, which typebox 1.x no longer sets (it marks schemas with
a hidden `~kind`), so every schema, the built-ins' included, is coerced after that — pig does the
same. A plain JSON schema (extension, MCP) gets no `Value.Convert` work, upstream or here.

**An unknown keyword is ignored, never a failure.** That is the most important line in the file: a
schema using something unimplemented has to keep working, or adding a keyword to a tool breaks the
tool instead of tightening it.

**Then it was run against AJV**, `ajv@8.20.0` with `ajv-formats` and upstream's own options, over
~190 schema/value pairs covering every keyword it implements and every keyword it does not, values
transported as JSON text so both sides decode the same document. Three things came out of it, and
the first two are the same mistake in two places:

- **`const` and `enum` compared with `!==`**, so `const: 5` refused `5.0`. JSON has one number type
  — a provider sending `5.0` for a count sent 5 and had no way to send anything else, AJV compares
  in a language that cannot hold the difference, and **`isType()` two methods away already said so
  in a comment** for `integer`. A model cannot correct a value that was already right. `same()` is
  numeric when both sides are numbers, recursive through lists and objects, and never PHP's `==` —
  which would make `1` equal `true` and `0` equal `null`.
- **`uniqueItems` sorted object keys at the top level only**, so an object nested inside a list
  inside an object read as two different documents depending on the order its keys arrived in.
  `identity()` recurses now.
- **The duplicate is named by position**, as AJV names it: `(items ## 1 and 3 are identical)`. The
  model has to fix that list, and "one of these is a duplicate" makes it read the whole list again.

A branch that normalised whole floats in `identity()` was written at the same time and **removed for
doing nothing**: PHP's `json_encode(1.0)` is already `1`. It was the mutation check that found it —
mutating it changed no test, which is the only reason anybody looked. The docblock now says the
verified thing rather than the assumed one.

What the run left, all documented in the docblock: four verdicts that are the empty-array ambiguity
(`type: object` against `[]` and `type: array` against `{}` are the same PHP value), three messages
where AJV also lists why each `anyOf` branch failed and this reports only that none matched, and the
missing-property path convention below.

Five things that took a real answer rather than a guess:

- **The messages are AJV's wording**, not invented ones — `must be integer`, `must have required
  property 'path'`. The reader is the **model**: it is about to correct itself from this sentence
  and it has seen AJV's phrasing everywhere else. One existing test changed wording because of it.
- **A missing property is reported at its own path.** Upstream prints `err.params.missingProperty`,
  the bare name, so a nested one cannot say which object it was missing from; here it reads
  `edits/0/path`.
- **`[]` is both an empty array and an empty object.** `json_decode(assoc: true)` gives a PHP array
  for both, so the two are told apart by `array_is_list()` — and the empty one is
  indistinguishable. It satisfies either, because there is no third answer and rejecting one would
  reject a valid document for being ambiguous with another valid one.
- **`multipleOf` took three tries.** `fmod(0.3, 0.1)` is `0.0999999999999999778` — close to *the
  divisor*, not to zero — so a one-sided tolerance on the remainder calls 0.3 not a multiple of
  0.1. It asks whether the quotient is whole, to a tolerance. AJV asks
  `Number.isInteger(value / multipleOf)` and therefore *does* reject 0.3, which is the standard's
  literal reading and is deliberately not copied: "0.3 must be multiple of 0.1" is a correction a
  model cannot act on.
- **`pattern` is delimited with `#`, and lengths are counted in characters.** A schema's pattern is
  an undelimited ECMA-262 regex, and `/` as the delimiter would silently change every pattern
  containing a path. `strlen` would make a four-character Chinese string twelve and reject what the
  schema allows.

A pattern that is not a valid PCRE is the schema's mistake and is ignored rather than reported
against the value, which would send the model looking where it did nothing wrong. Not ported:
upstream's `validateToolCall(tools, call)`, which is `validateToolArguments` plus a find-by-name —
it has no caller upstream either, because `AgentLoop` has the tool in hand by the time it
validates and needs it afterwards to run.

## Starting up, and why it had to stop living in `bin/pig`

`CodingAgent::session()` is upstream's `createAgentSession()`. The rest of `sdk.ts`'s 673 lines has
no counterpart and needs none: about 35 are re-exports, which an autoloader does, and about 155 are
`discoverHooks()`, `discoverSkills()`, `loadSettings()` and four more like them — thin wrappers so
an SDK user need not import from `./internal/…`. pig's loaders were public from the start, so
`ContextFiles::load()`, `CodingAgent::resources()` (skills and prompt templates), `HookLoader::load()`,
`CustomToolLoader::load()`, `Auth::discover()`, `CustomModels::discover()`, `Settings::load()` and
`SystemPrompt::build()` are already the things to call.

**The remaining ~235 lines were the ones that mattered, and until this they were `bin/pig`'s
middle.** `create()` above builds the `Agent`; nothing built everything around it. So the resolution
order — which of `--model`, the settings and the built-in default wins, when a thinking
level is clamped, which file `--continue` picks, what a broken hook does — existed only as top-level
script statements, and *a script that ends in `exit()` cannot be called twice*. That is the same
sentence that made `Cli\Arguments`, `Cli\ModelList` and `Cli\SignIn` classes; this is the fourth
time, and the largest.

Three rules make it testable, and they are worth keeping when it grows:

- **Nothing writes to a stream.** Warnings come back as `StartedSession::$warnings`, already worded,
  in the order they were found; whoever asked prints them. There is a test whose whole assertion is
  that a startup with a broken hook *and* a malformed skill prints nothing at all.
- **Anything fatal throws `CodingAgentError`** with a sentence worth showing as it is. `bin/pig`
  catches it, prints it red and exits 1 — the same refusal reads the same to a terminal, a
  JSON-lines host and a test.
- **What needs a screen stays with the caller.** `--resume` draws a list, so
  `bin/pig` resolves that to a path first; `session()` takes a path or nothing. The theme is the
  caller's too, and so are `Auth` and the custom models — because `--list-models` prints the registry and
  exits, and it has to see a model somebody just declared without a session file being created on
  the way past.

`bin/pig` went from 539 lines to 411.

`Hooks\` is upstream's `core/hooks/`. A hook is a PHP file in `~/.pig/agent/hooks` or
`.pig/hooks` that returns a callable; the callable is handed a `HookApi` and registers what
it wants to hear about:

```php
<?php // ~/.pig/agent/hooks/no-force-push.php

use Pig\CodingAgent\Hooks\HookApi;
use Pig\CodingAgent\Hooks\Results\ToolCallEventResult;

return function (HookApi $pi): void {
    $pi->on('tool_call', function ($event) {
        if ($event->toolName === 'bash' && str_contains($event->input['command'] ?? '', '--force')) {
            return new ToolCallEventResult(block: true, reason: 'No force pushes from here.');
        }

        return null;
    });
};
```

**`require`, not a subprocess.** That was a decision with a cost, taken deliberately. A hook
runs in this process with this process's permissions: it can hand back an object, which a
command-line hook could not, and it can be written against pig's own classes because it is
running inside pig. What it cannot be given is a timeout or any isolation. A hook that loops
does not time out; one that calls `exit()` takes the session with it. What *is* caught is
everything PHP raises as a `Throwable` — including a syntax error, which an included file
raises as a catchable `ParseError` — so a broken hook is a complaint on the shell at startup
rather than a crash. `--no-hooks` starts without loading any.

The event names are upstream HEAD's (`HookApi::EVENTS`), with the shapes they have there:
`session_start` carries `reason` (`startup`/`reload`/`new`/`resume`) and `previousSessionFile`,
`session_shutdown` carries `reason` (`quit`/`reload`/`new`/`resume`) and `targetSessionFile`, and
`/new`, `/resume` and `/reload` fire the pair in that order — upstream tears its runtime down and
builds a new one, pig keeps the same hooks and tells them the same two things. `session_switch`,
which upstream no longer has, went with no alias. `ui_prompt_start`/`ui_prompt_end` are said
around every blocking dialog by `Hooks\PromptingUi`, upstream's `wrapUIPromptContext()`.
`session_before_fork` is asked by `AgentSession::fork()` with the entry and the position (`before`
for `/fork`, `at` for `/clone`), and the pair it fires afterwards carries the reason `fork`. A typo
is refused by name, since the names are a list here rather than TypeScript overloads.

**Four of upstream's events and its virtual models arrive in pig's own arrangement, and the
arrangement is the thing to know before reading any of them:**

- **`project_trust` is asked of a runner that exists only for the question.** `bin/pig` loads the
  person's extensions first (`ExtensionLoader::load()` in upstream's order: home, the settings',
  the command line's — then, after trust, the project's two roots and the project settings'
  `extensions`), and `projectTrusted:` may be a closure that is asked with what loaded so far. The
  closure builds a `HookRunner` over them and hands it to `ProjectTrust::resolve()`, which asks
  `emitProjectTrust()` before the saved answer and the prompt — the first `yes`/`no` wins,
  `undecided` falls through, `remember` writes `trust.json`. Two phases of one load rather than
  upstream's two loads, because a factory run twice is an MCP server connected twice. The settings
  are the person's alone until trust is decided (`$settleTrust` reads them again with the project's,
  re-applies the proxy and the idle timeout, and sets the theme directory); `--list-models` is
  asked too, as upstream's `print` mode is, with nobody to prompt. **The handler's `ctx->ui` is
  `NoUi` and `hasUi` is false** even at a terminal: pig has no screen before the session, and the
  trust prompt is a one-shot `TrustPrompt`. `defaultProjectTrust` (`ask`/`always`/`never`, the
  person's settings only) answers after the saved decision and before the prompt, as upstream's;
  `-a`/`--approve` and `-na`/`--no-approve` answer for this run ahead of everything, the saved
  decision included, and save nothing (`ProjectTrust::resolve(override:)`).
- **`resources_discover` is `AgentSession::discoverResources($reason)`**, called by each mode
  straight after it emits `session_start`, and by `/reload` after its `session_start('reload')`.
  Skills from the answered directories go through `Skills::fromDirectories()` (source
  `extension`) onto `ToolLoadout::addSkills()`, so the
  prompt names them; prompt templates through `SlashCommands::fromDirectories()` (source
  `(extension)`) into the session's file commands; theme directories into
  `Themes::setExtensionThemeDirs()`. A relative path is resolved against the extension's file. The
  mode gets an `ExtensionResources` back for its own `/skills` list and the autocomplete; a skill
  that could not be read is reported against the extension that named its directory.
- **`ui.setEditorComponent()` swaps the dock slot, and rebinds from scratch.** The prompt sits in
  `InteractiveMode::$editorSlot` (upstream's `editorContainer`); `setCustomEditorComponent()` puts
  the factory's `CustomEditor` there, carries the text over, and runs `bindEditor()` + `bindKeys()`
  again — the same two methods startup runs, which is the one way the new prompt cannot be
  missing a key the old one had. `TerminalUi` reads the prompt through a closure for the same
  reason. `CustomEditor` is **not final any more**, which upstream names as the point: an
  extension's editor extends it and calls `parent::handleInput()` for what it does not take.
  `ui.addAutocompleteProvider()` stacks wrappers over `baseAutocompleteProvider()`
  (`setupAutocompleteProvider()`); `/reload` clears both the wrappers and the editor, as upstream's
  does, and the extensions put them back on `session_start`. pig's editor has no
  `triggerCharacters`: `/` and `@` open a list and Tab opens the wrapper's, nothing else does.
- **`agent_before_settle` is the last step of `runAgentPrompt()`'s loop**, where upstream has it:
  after `handlePostAgentRun()` found no retry, no overflow and nothing queued, `runBeforeSettleBoundary()`
  runs `HookRunner::emitBoundary()` — each handler handed the drafts and `continue` the ones before
  it settled on, and a `BoundaryContextPreview` built from `SessionManager::preview()` (a clone that
  appends and writes nowhere; `previewOf()` for `--no-session`) with those drafts applied. An entry
  that cannot be applied throws there, is reported as `Invalid boundary entries`, and the whole list
  appends nothing. Committed drafts go to the store and the agent's state is replaced with what the
  store projects; a `continue` the context cannot carry (ends on the assistant's turn, nothing
  queued) is refused with upstream's sentence. `outcome` is set from each `turn_end`'s stop reason.
  pig has no `_pendingCustomMessages`: a hook's message during a run is already a follow-up on the
  agent's queue, which is what `pendingMessages` shows. `turn_end` is **not** a boundary here.
- **Virtual models are `VirtualModelRegistry` + `VirtualModelDefinition`, `ModelRouteRequest`,
  `ModelRoute`, `RoutedModel`**, flat in `Pig\CodingAgent` beside `McpServerRegistry` and
  `RegisteredMcpServer`: the virtual-model half of upstream's `ModelRuntime` on a static registry
  (reached through `current()`, `reset()` on `/reload`, like `McpServerRegistry`). `register()` puts an `Api::Virtual` row into `Models`
  (`registerVirtual()`, laid over the table last so it hides a physical model of the same id) and
  keeps the router; `Stream` refuses to stream such a row, and `Stream::envApiKey()` answers the
  ambient marker for a provider of nothing but virtual models, which is how `Auth::hasKeyFor()`,
  `apiKey()` and `AgentSession::keyFor()` all say "signed in". The routing is
  `Agent::$prepareRequest` (upstream's `AgentLoopConfig.prepareRequest`, new in agent-core: asked
  right before every request, the first included, after the pending messages are in; its answer
  replaces context, model and reasoning for the rest of the run) — `AgentSession` installs one that
  routes `agent->state->model` when it is virtual, so each request routes again and the selection
  never leaves the state. `VirtualModelRegistry::resolve()` is upstream's `resolveModel()`: physical
  target with credentials, level clamped with `ThinkingLevel::clampedFor()`. A router is called as
  `(request, ctx)` with a per-request `HookContext` — upstream's `ctx.sessionManager.getBranch()` is
  `$ctx->store->branch()`, `ctx.modelRegistry` is `$ctx->modelRegistry`. Reasons: `retry` when
  `$failedResponse` was set by a successful `prepareRetry()`, `user` when a `UserMessage` follows the
  last assistant message, else `continuation`; summaries (`summariseAndSwapIn()`, `branchSummary()`)
  route with `direct` through `directModel()`. Router state is the last `pi.virtual-model-state`
  custom entry on the branch for that provider/id. `SessionManager::settings()` carries upstream's
  `getBranchSelection()` rule: a virtual `ModelChange` holds over the physical responses after it,
  one no longer registered does not, and a response naming a virtual model (a failed routing) is
  never a selection; `recordSelection()` at the start of each prompt writes the selection down when
  the branch implies another. `routedModel()`, `limitsModel()` and `modelForMessage()` are
  upstream's: the footer, `contextUsage()`, `shouldCompact()` and the overflow checks use the physical
  model that answered last. `RoutedModel::$thinkingLevel` is the response's own
  `AssistantMessage::$thinkingLevel`, which `AgentLoop::streamAssistantResponse()` stamps on every
  response (upstream's `Object.assign(result, {thinkingLevel})`) and `MessageJson` writes as
  `thinkingLevel`; the copies in `Retry`, `TransformMessages`, `FauxProvider` and
  `SessionManager` carry it, and `withThinkingLevel()` is the one way to set it on a readonly message.

**Not yet ported from upstream's `ExtensionAPI`:** the context's `modelRegistry` (pig has `Auth` + the static `Models`), and `ExtensionCommandContext`
(see below).

**What is not here is upstream's `HookCommandContext`**, and this is the one place to look for it.
Upstream gives a slash command's handler four methods an event handler does not get —
`waitForIdle()`, `newSession()`, `branch()` and `navigateTree()` — walled off that way because
calling them from inside the agent loop deadlocks. pig has one `HookContext` for both, with
everything that can be read at any moment plus `abort()`. The four are not ported, and not as an
oversight: `branch()` is `AgentSession::fork()`, which a mode calls and a hook does not get,
`navigateTree()` is `goTo()`, whose
answer is a summary, an abort or text for the prompt rather than a yes; and the other two would need
a second context class to be safe from the deadlock upstream avoids by having one. Two context types
so a hook command can restart the session is more machinery than the thing is worth — see the rule
at the top of this file. A hook that wants a handoff can say so with `sendMessage()` and let the
person press `/new`.

**`isError` on a `tool_result` result is honoured in one direction**, which is one more than
upstream: `true` raises, so a hook can call a successful tool's output a failure, and the verdict
still comes from the one place the loop reads it — `execute()` throwing. `false` on a tool that
*did* throw is not read, because rescuing it would be a second route to "this succeeded". Upstream
declares the field and reads neither direction, so setting it there does nothing.

Four things worth keeping straight:

- **A `tool_call` hook that throws blocks the call.** Upstream's rule and the right one: a
  hook asked whether a tool may run and answering with an exception has not said yes. The
  alternative is a `rm -rf` guard that stops working the day someone leaves a typo in it,
  which is the day it matters. Every other event catches and reports instead — a hook that
  watches is not allowed to stop the thing it watches.
- **A handler that returns the wrong type is reported, not ignored.** Treating it as "no
  opinion" turns a typo into a hook that appears to work.
- **`context` is chained and nothing else is.** Each `context` handler is given what the last
  one returned, so two hooks can both edit the conversation without knowing about each other.
  A `tool_result` handler is given the tool's own output every time, so a hook can always tell
  the tool from another hook's edit of it.
- **A hook's note goes in through `prompt()`, not onto the state.** `before_agent_start`
  returns text that becomes its own user message in front of the prompt — appended to the
  agent's messages directly it would never reach the session file, and a resumed conversation
  would be missing the thing that explained it.

`HookedTool` is `tool-wrapper.ts` as a decorator, because PHP has interfaces where TypeScript
has `{...tool, execute}`. `Process::run()` grew an optional `$cwd` so `$pi->exec()` can run a
command where the project is.

**`$pi->exec()` is on the loop**, as upstream's `execCommand` is asynchronous. It was not: it went
through `Process::run()`, which polls with `usleep()`, so while a hook's command ran there were no
keystrokes, no spinner and no redraw — a thirty-second linter was a thirty-second freeze. It and
`CustomToolApi::exec()` both go through `Process::runAsync()` now; see the trap below.

**And escape stops it**, which is pig doing something upstream cannot. Upstream's `ExecOptions`
has a `signal` field and its `HookContext` has no signal to put in it, so a hook's command can
only be waited out there. Here `Agent::signal()` answers the run in progress's — null between
runs, because the controller is cleared in `run()`'s `finally` and handing back the last one would
mean an already-aborted signal for everything asking after an interrupted turn — and the modes
pass it to `HookRunner::initialize()` the way they already pass `getModel`. It reaches `exec()`
through the **context provider** rather than as a parameter: the runner builds a `HookContext`
fresh per emit, so what `exec()` reads is the turn running now, and a signal the hook had to
remember to pass is one whose author did not. `$ctx->signal` is there too, for a handler whose own
waiting is not a subprocess. `CustomToolApi` is wired the same way, through the `withContext()`
the modes already call.

Two differences from upstream's version remain, both deliberate. **There is a timeout by default**
where upstream's is opt-in, so a hook that forgets one cannot park the turn for as long as its
command wants. And **`killed` is `ExecResult::stopped()`**, wider by two cases and saying so on
itself: a program that is not on this machine, and one escape stopped, answer the same way as one
that ran too long — where upstream reports the first as `code: 1, killed: false`.

`HookUi` is upstream's `HookUIContext`, and it is what makes `tool_call` more than a
yes-or-no rule: a handler can ask the person something and wait for the answer.

```php
$pi->on('tool_call', function ($event, $ctx) {
    if ($event->toolName !== 'bash' || !str_contains($event->input['command'] ?? '', 'rm -rf')) {
        return null;
    }

    return $ctx->ui->confirm('Let bash run?', $event->input['command'])
        ? null
        : new ToolCallEventResult(block: true, reason: 'You said no.');
});
```

**It blocks the turn, and that is the whole trick.** A handler runs inside the agent's
fiber, so awaiting an answer suspends that fiber and nothing else: the loop keeps serving
the terminal, the keystroke arrives, the deferred completes, the handler carries on where it
stopped and returns an ordinary value. This is the one thing `pig/async` exists for, and it
is why upstream can have this feature in JavaScript and a synchronous PHP port could not.

Three things that keep it from parking a session forever:

- **Every dialog can be escaped.** `SelectList` always could. `Input` could not — nothing
  pig opened had needed it, because every input was one somebody had asked for. An
  un-escapable prompt in front of a suspended fiber is a session that has to be killed, so
  `Input::setCancelHandler()` was added for this.
- **One dialog at a time.** A second `confirm()` while the first is open would take focus
  from it, leaving the first fiber waiting on a component nobody can reach. The second is
  refused with its safe answer instead.
- **Escape is a no, and so is having no terminal.** `NoUi::confirm()` returns false, which
  for a guard means a tool nobody could approve does not run. Upstream's choice, and the
  only safe direction.

All nine members are ported. `select` and `confirm` are `SelectList`, `input` is `Input`,
`editor` is a `CustomEditor` — the wrapper, not the bare `Editor`, because that is the piece
that turns escape and Ctrl+G into named keys, so they mean the same thing in the dialog as at
the prompt. `notify` is the transcript, `setStatus` is a third footer line that appears only
when something has put a line on it, and `theme` is `palette()`.

`custom()` hands a hook the `TUI`, the palette and a `done()`, and shows whatever component
it builds. An earlier note here said it was left out because it exposes the renderer to code
loaded off disk; that reasoning was wrong and is recorded as wrong — a hook is `require`d
in-process and can already call `exit()` or reach anything by reflection, so withholding
`$tui` was a lock on a door in an open field. What it does need is care: `done()` is
idempotent and always closes, a factory that returns something that is not a `Component` is
refused *and* releases the one-at-a-time latch, and a component that never calls `done()` and
ignores escape parks the turn — which nothing here can prevent, because it holds the keys.

## Where the startup time went

`Timings` is upstream's `core/timings.ts`, behind `PIG_TIMING=1` as upstream's is behind
`PI_TIMING=1`. Twelve marks in `bin/pig` — arguments, settings, credentials, context files,
skills, slash commands, the session, hooks, custom tools, the agent, the `@file` reads — and a
table on **standard error** at the point a mode takes over.

Four things about it:

- **Gaps, not moments.** Each mark records the time since the last, so what comes out is a list
  of steps and what each cost. A list of timestamps needs subtracting by whoever reads it, and
  that is the part nobody does. The first mark is an anchor and reads 0.
- **The marks are unconditional**, and `mark()` is a comparison and a return when the variable
  is unset. An `if` at each of twelve steps would be the same thing written twelve times.
- **Standard error, and before the mode starts**, not at the end. Standard output belongs to
  `--mode json` and `-p`, and a report printed when pig quits is one somebody has to scroll back
  an hour to find.
- **`table()` is separate from `report()`** because `report()` writes to standard error, which
  `ob_start()` does not capture — a test against it would pass whatever the table said. That is
  the same failure as the `/label` test that passed while doing the wrong thing, caught this
  time by noticing the assertion could not fail.

Exactly `1`, as upstream tests it: `PIG_TIMING=0` meaning "on" would be a variable that cannot
be turned off the obvious way.

## Five upstream dependencies that are not needed here

`pi-tui` pulls in `get-east-asian-width` and leans on `Intl.Segmenter`, both for one question:
how many columns will the terminal give this string? Neither is needed here.

- **Grapheme clusters**: PCRE's `\X` implements UAX #29 extended grapheme clusters, correctly,
  including ZWJ emoji sequences and regional-indicator flags. `Graphemes::split()` is one
  `preg_match_all` call — no ext-intl, no table of Unicode ranges.
- **Character width**: `mb_strwidth()` carries the East Asian Width table, and PCRE answers
  `\p{Extended_Pictographic}` and `\p{Emoji_Presentation}` for the emoji cases it does not cover.

`chalk` is the third, and `Pig\Tui\Style` replaces it: a TUI needs "wrap this string in a
style and close it again", which is one file, not a package. Components take styles as
`Closure(string): string`, so `Style::dim(...)` is what gets passed around.

`marked` is the fourth, and the one that was a real decision rather than an obvious win.
`markdown.ts` uses it for a token stream and throws its HTML away, and a terminal renderer
needs a subset — headings, emphasis, code spans and fences, lists, quotes, links, rules,
tables. The developer chose to write that subset here rather than take `league/commonmark`,
which would have brought `league/config`, `dflydev/dot-access-data` and a row of Symfony
polyfills with it and ended the zero-dependency claim. It is not CommonMark and does not
try to be; anything it cannot parse is drawn as the plain text it came from.

`cli-highlight` is the fifth — it wraps highlight.js and its two hundred grammars, and
`theme.ts` uses it to colour fenced code blocks. `Theme\Highlight` replaces it with a
left-to-right scanner and a table of thirteen languages, which the developer chose over
the dependency. The trade is stated plainly in the class: fewer languages, and inside a
language a handful of things coloured slightly wrong that highlight.js would get right.

The one design point worth keeping: it is a **scanner**, not a pile of `preg_replace`
calls over a keyword list. A replacement-based highlighter paints the `if` inside
`"if you like"` blue and the `#` inside a URL grey, and a reader who has seen that once
stops trusting any of the colours. Asking "what starts here?" at each position means that
once the scanner is inside a string, nothing inside that string is anything else.

Two places it does guess — a Capitalised word is a type, a word before `(` is a call —
and both are documented as guesses. This is the one part of the codebase where a guess is
allowed, because the cost of being wrong is a wrong colour.

The one thing PCRE has no answer for is JavaScript's `\p{RGI_Emoji}`, which matches a whole emoji
*sequence*; PCRE properties test single codepoints. `Width` asks the question of the cluster's
first codepoint instead, which gives the same answer for everything a terminal actually draws.

## Upstream's tool and loader flags

`--no-tools` (`-nt`) is upstream's: no tool at all, MCP's included (`CodingAgent::session(noTools:
'all')`), and `--no-builtin-tools` (`-nbt`) keeps the custom and extension tools; a `--tools` of
`+name`/`-name` entries then changes the empty default, and a named set wins over both. **pig's
old `--no-tools` is `--no-tool-files`** — it skips `~/.pig/agent/tools` and `.pig/tools` — with no
reader for the old spelling. `--name` (`-n`) names the session before the first turn (upstream's
`appendSessionInfo()`), `--no-prompt-templates` (`-np`) and `--no-context-files` (`-nc`) switch off
those loaders; every one of upstream's short forms is in `Arguments::SHORT`.

**`--theme <path>` is upstream's too**: a theme file or directory for this run
(`Themes::setCliThemePaths()`), and the theme to start on is `--use-theme <name>` — pig's old
`--theme`, again with no reader for the old spelling. `--no-themes` (`Themes::useThemeDiscovery()`)
looks in none of the theme directories, the `themes` setting or the packages; the built-ins, an extension's and `--theme`'s
stay. `--skill` and `--prompt-template` load files or directories (`CodingAgent::session(skillPaths:,
promptTemplatePaths:)`), after everything else and **under `--no-skills` / `--no-prompt-templates`
too** — naming one is asking for it — and a path that is neither is `<Kind> path does not exist`.
These four and `-e` repeat (`Arguments::REPEATABLE`, `values()`), and `-e` loads under `-ne`
(`ExtensionLoader::load(discover: false)`), as upstream's "explicit -e paths still work". A relative
path is the shell's, not `--cwd`'s; `builtin:<name>` is one of the [built-ins](extensions.md#built-in-extensions).

The rest of upstream's flags arrived in one batch: `--provider` (requires `--model`),
`--system-prompt`, `--append-system-prompt` (repeatable), `--session-id`, `--fork`, `--tui-mode
regular|fullscreen`, `--verbose`, `--offline` (`PIG_OFFLINE=1` and `PIG_SKIP_VERSION_CHECK=1`),
`--no-session` (which replaced pig's `--no-save`, with no reader for the old spelling) and
`--update-check`. `pig auth check|print-api-key|print-bearer-token` (`Cli\AuthCommand`; `--json`,
`--credentials`, `--no-refresh`, `--min-expiry`) reads the credentials and `models.json` and nothing
else. There is no `pig doctor` subcommand — `/doctor` is the slash command.
