# Known traps: Providers

Each provider API, the model registry, thinking levels, usage, overflow. Moved out of `AGENTS.md` (then `CLAUDE.md`) as written — "this file" in the entries below means that file, where they were first written. The index is generated from the headings below.

- [DeepSeek accepted the field and ignored it, so every request to it was unbounded](#deepseek-accepted-the-field-and-ignored-it-so-every-request-to-it-was-unbounded)
- [1,633 mutations over the five providers, and an option none of them sent](#1633-mutations-over-the-five-providers-and-an-option-none-of-them-sent)
- [The registry was a transcription of a generated file](#the-registry-was-a-transcription-of-a-generated-file)
- [The first real regeneration, and what it cost](#the-first-real-regeneration-and-what-it-cost)
- [Eighteen of Gemini's twenty finish reasons were an error with no words in it](#eighteen-of-geminis-twenty-finish-reasons-were-an-error-with-no-words-in-it)
- [Google's overflow row, verified against a model released after the anchor](#googles-overflow-row-verified-against-a-model-released-after-the-anchor)
- [The overflow pattern for Cerebras could never match pig's own words](#the-overflow-pattern-for-cerebras-could-never-match-pigs-own-words)
- [A truncated answer came back as a finished one, with no usage at all](#a-truncated-answer-came-back-as-a-finished-one-with-no-usage-at-all)
- [A 404 for a model pig offers, and a cause that did not survive the second data point](#a-404-for-a-model-pig-offers-and-a-cause-that-did-not-survive-the-second-data-point)
- [An empty arguments list went out as `[]`, which is not an object](#an-empty-arguments-list-went-out-as--which-is-not-an-object)
- [Anthropic was the one provider of four that never cleaned the conversation up](#anthropic-was-the-one-provider-of-four-that-never-cleaned-the-conversation-up)
- [The provider that talks to Copilot sent none of Copilot's headers](#the-provider-that-talks-to-copilot-sent-none-of-copilots-headers)
- [Every Anthropic turn's input count was wiped out by its own last event](#every-anthropic-turns-input-count-was-wiped-out-by-its-own-last-event)
- [OpenAI's final tool-call arguments were read from the deltas only](#openais-final-tool-call-arguments-were-read-from-the-deltas-only)
- [A Gemini tool call's thought signature was collected and then dropped](#a-gemini-tool-calls-thought-signature-was-collected-and-then-dropped)
- [OpenRouter's encrypted reasoning was neither read nor sent](#openrouters-encrypted-reasoning-was-neither-read-nor-sent)
- [Twelve models were never told how hard to think](#twelve-models-were-never-told-how-hard-to-think)
- [The token total was recomputed for providers that report one](#the-token-total-was-recomputed-for-providers-that-report-one)
- [A `models.json` key written as a bare variable name is the key itself](#a-modelsjson-key-written-as-a-bare-variable-name-is-the-key-itself)
- [`thinkingLevelMap`: the half of Antigravity's routing that belongs in pig](#thinkinglevelmap-the-half-of-antigravitys-routing-that-belongs-in-pig)
- [Antigravity: three names for one model choice](#antigravity-three-names-for-one-model-choice)
- [`defaultProvider` was written from the start and read by nothing](#defaultprovider-was-written-from-the-start-and-read-by-nothing)
- [Removing Gemini CLI, and the four rules that nearly went with it](#removing-gemini-cli-and-the-four-rules-that-nearly-went-with-it)
- [Gemini 3.x on the public endpoint](#gemini-3x-on-the-public-endpoint)
- [The Antigravity provider is `GoogleGeminiCli` plus an envelope](#the-antigravity-provider-is-googlegeminicli-plus-an-envelope)
- [The quota wall's call to action is the provider's wording; the session does not rewrite it](#the-quota-walls-call-to-action-is-the-providers-wording-the-session-does-not-rewrite-it)
- [Anthropic's `redacted_thinking` block was dropped, so the next turn replayed something the model did not write](#anthropics-redacted_thinking-block-was-dropped-so-the-next-turn-replayed-something-the-model-did-not-write)
- [Token usage on OpenAI-compatible / Gemini: thinking counted twice, cache missed or counted twice](#token-usage-on-openai-compatible--gemini-thinking-counted-twice-cache-missed-or-counted-twice)
- [Tool call ids of the wrong shape across models made the whole request a 400](#tool-call-ids-of-the-wrong-shape-across-models-made-the-whole-request-a-400)
- [Gemini thinking config chosen by `str_contains($id, 'gemini-3')`: a no-thinking request was a 400, and levels did nothing on Gemma 4 / `-latest`](#gemini-thinking-config-chosen-by-str_containsid-gemini-3-a-no-thinking-request-was-a-400-and-levels-did-nothing-on-gemma-4---latest)
- [Reasoning replay on chat completions: thinking duplicated, blocks run together, `reasoning_details` lost](#reasoning-replay-on-chat-completions-thinking-duplicated-blocks-run-together-reasoning_details-lost)
- [Refusal text lost on the Responses API](#refusal-text-lost-on-the-responses-api)
- [A model without image support was told nothing about the images](#a-model-without-image-support-was-told-nothing-about-the-images)
- [DeepSeek replaying an assistant turn without thinking: 400 for the missing `reasoning_content`](#deepseek-replaying-an-assistant-turn-without-thinking-400-for-the-missing-reasoning_content)
- [Responses API thinking text trusted the streamed deltas alone](#responses-api-thinking-text-trusted-the-streamed-deltas-alone)
- [`OpenAiCompat::detect()` and upstream's `detectCompat()` were not the same table](#openaicompatdetect-and-upstreams-detectcompat-were-not-the-same-table)
- [A `compat` block replaced the whole detection result](#a-compat-block-replaced-the-whole-detection-result)
- [Optional parameters arrive as null under strict sampling](#optional-parameters-arrive-as-null-under-strict-sampling)
- [z.ai / DeepSeek were never told whether to think](#zai--deepseek-were-never-told-whether-to-think)
- [Anthropic adaptive thinking guessed from the model id, and read a property that did not exist](#anthropic-adaptive-thinking-guessed-from-the-model-id-and-read-a-property-that-did-not-exist)
- [Anthropic sent nothing when thinking was off, and omitted the thinking summary](#anthropic-sent-nothing-when-thinking-was-off-and-omitted-the-thinking-summary)
- [OpenRouter / Vercel routing preferences in models.json were dropped](#openrouter--vercel-routing-preferences-in-modelsjson-were-dropped)
- [Copilot's Claude went over chat completions](#copilots-claude-went-over-chat-completions)
- [Responses API tool-result images became a user message](#responses-api-tool-result-images-became-a-user-message)
- [The model wrote numbers as strings and the tool call was refused](#the-model-wrote-numbers-as-strings-and-the-tool-call-was-refused)
- [Copilot's gpt-6 / grok / mai models were sent to chat completions](#copilots-gpt-6--grok--mai-models-were-sent-to-chat-completions)
- [Anthropic: Opus 4.7+ refused a temperature, and thinking turns carried one too](#anthropic-opus-47-refused-a-temperature-and-thinking-turns-carried-one-too)
- [Anthropic: tool streaming uses eager_input_streaming, and the beta header is computed as upstream does](#anthropic-tool-streaming-uses-eager_input_streaming-and-the-beta-header-is-computed-as-upstream-does)
- [Claude's xhigh could not be selected](#claudes-xhigh-could-not-be-selected)
- [The request shape for managed-effort models (Opus 5 / Fable 5.1, …) was wrong](#the-request-shape-for-managed-effort-models-opus-5--fable-51--was-wrong)
- [Empty objects inside models.json compat object values were sent as `[]`](#empty-objects-inside-modelsjson-compat-object-values-were-sent-as-)
- [Anthropic's `max_tokens` was a third of the model's ceiling, and the thinking budget exceeded the ceiling](#anthropics-max_tokens-was-a-third-of-the-models-ceiling-and-the-thinking-budget-exceeded-the-ceiling)
- [Anthropic's refusal, `sensitive` and new stop reasons were treated as a normal end](#anthropics-refusal-sensitive-and-new-stop-reasons-were-treated-as-a-normal-end)
- [Anthropic's cache breakpoints skipped the tools, and `cacheRetention` did nothing](#anthropics-cache-breakpoints-skipped-the-tools-and-cacheretention-did-nothing)
- [Responses API switched thinking off with `# Juice: 0`, and every `incomplete` was `length`](#responses-api-switched-thinking-off-with--juice-0-and-every-incomplete-was-length)
- [Copilot's GPT-6 could not select xhigh](#copilots-gpt-6-could-not-select-xhigh)
- [The context and model StreamProxy sent to a gateway were missing fields](#the-context-and-model-streamproxy-sent-to-a-gateway-were-missing-fields)
- [OpenAI gpt-5.4/5.5/5.6/6 had a 1.05M window, and long context was billed at the base price](#openai-gpt-5455566-had-a-105m-window-and-long-context-was-billed-at-the-base-price)
- [A stream that broke midway was taken for a normal end](#a-stream-that-broke-midway-was-taken-for-a-normal-end)
- [Responses error text differed from upstream, and the ChatGPT quota error gave no link](#responses-error-text-differed-from-upstream-and-the-chatgpt-quota-error-gave-no-link)
- [Responses dropped the encrypted reasoning Azure gives only in `response.completed`, and arguments follow `.done`](#responses-dropped-the-encrypted-reasoning-azure-gives-only-in-responsecompleted-and-arguments-follow-done)
- [chat completions sent no prompt cache key, session affinity headers or Anthropic cache marks](#chat-completions-sent-no-prompt-cache-key-session-affinity-headers-or-anthropic-cache-marks)
- [StreamProxy dropped the gateway's `providerThinkingLevel` and its final tool call](#streamproxy-dropped-the-gateways-providerthinkinglevel-and-its-final-tool-call)
- [A mistyped price tier in models.json was silently billed at 0](#a-mistyped-price-tier-in-modelsjson-was-silently-billed-at-0)
- [Copilot billed at $0, and extended windows patched one id at a time](#copilot-billed-at-0-and-extended-windows-patched-one-id-at-a-time)
- [Mistral went over chat completions, with tool ids and thinking parameters pig guessed itself](#mistral-went-over-chat-completions-with-tool-ids-and-thinking-parameters-pig-guessed-itself)
- [The Google / Gemma thinking level maps were not upstream's](#the-google--gemma-thinking-level-maps-were-not-upstreams)
- [Z.ai read the wrong models.dev entry](#zai-read-the-wrong-modelsdev-entry)
- [Responses: concurrent items crossed streams, items arriving only at `done` were lost, empty deltas swallowed](#responses-concurrent-items-crossed-streams-items-arriving-only-at-done-were-lost-empty-deltas-swallowed)
- [chat completions took a stream cut off without finish_reason for success](#chat-completions-took-a-stream-cut-off-without-finish_reason-for-success)
- [A Google stream without a finish reason was a success, and an error finish reason threw at once](#a-google-stream-without-a-finish-reason-was-a-success-and-an-error-finish-reason-threw-at-once)
- [A malformed Anthropic SSE event was skipped silently](#a-malformed-anthropic-sse-event-was-skipped-silently)
- [StreamProxy's error text was not upstream's](#streamproxys-error-text-was-not-upstreams)
- [models.json cost without base prices, and the fallback model list unvalidated](#modelsjson-cost-without-base-prices-and-the-fallback-model-list-unvalidated)
- [The `deferred` stop reason did not exist](#the-deferred-stop-reason-did-not-exist)
- [JSON parse error text was PHP's, not V8's](#json-parse-error-text-was-phps-not-v8s)
- [Anthropic / Gemini HTTP error text was pig's own](#anthropic--gemini-http-error-text-was-pigs-own)
- [An error object in a chat completions stream was read past, and tool-result images landed between results](#an-error-object-in-a-chat-completions-stream-was-read-past-and-tool-result-images-landed-between-results)
- [The context-overflow table lagged upstream, and a Mistral overflow was retried](#the-context-overflow-table-lagged-upstream-and-a-mistral-overflow-was-retried)
- [Mistral had no response-header timeout, and no provider sent a User-Agent](#mistral-had-no-response-header-timeout-and-no-provider-sent-a-user-agent)
- [StreamProxy accepted `data:` lines without the space, and error text lacked the status text](#streamproxy-accepted-data-lines-without-the-space-and-error-text-lacked-the-status-text)
- [Antigravity threw at the error-finish-reason chunk, losing the usage after it](#antigravity-threw-at-the-error-finish-reason-chunk-losing-the-usage-after-it)
- [Provider-level retries, request options and provider events go through the request options, not a global `HttpClient` observer](#provider-level-retries-request-options-and-provider-events-go-through-the-request-options-not-a-global-httpclient-observer)
- [The SDKs' own request headers and abort wording are part of the port too](#the-sdks-own-request-headers-and-abort-wording-are-part-of-the-port-too)
- [The HTTP idle timeout is 300 seconds, not 60](#the-http-idle-timeout-is-300-seconds-not-60)
- [Antigravity: an empty response is asked again twice first, and a 404 runtime model falls back to the older name](#antigravity-an-empty-response-is-asked-again-twice-first-and-a-404-runtime-model-falls-back-to-the-older-name)
- [Gemini: no `thinkingConfig` when thinking was not mentioned; Windows `os.release()` carries the build number](#gemini-no-thinkingconfig-when-thinking-was-not-mentioned-windows-osrelease-carries-the-build-number)
- [Vertex and Bedrock: SDK behavior aligned item by item against oracle recordings](#vertex-and-bedrock-sdk-behavior-aligned-item-by-item-against-oracle-recordings)
- [Antigravity's model catalog is discovered dynamically](#antigravitys-model-catalog-is-discovered-dynamically)
- [Azure, Codex and Radius rows are derived rules of upstream's generator, and Codex credentials carry `accountId`](#azure-codex-and-radius-rows-are-derived-rules-of-upstreams-generator-and-codex-credentials-carry-accountid)
- [The other 25 providers on ported APIs: rows are the generator's output, compat written by the generator's own detection](#the-other-25-providers-on-ported-apis-rows-are-the-generators-output-compat-written-by-the-generators-own-detection)
- [Claude took models.dev's effort map](#claude-took-modelsdevs-effort-map)
- [xAI went over Chat Completions](#xai-went-over-chat-completions)
- [Cloudflare, classifiers and image models](#cloudflare-classifiers-and-image-models)
- [A tool with no parameters sent `properties: []`, and an integer enum, both refused on the Antigravity path](#a-tool-with-no-parameters-sent-properties--and-an-integer-enum-both-refused-on-the-antigravity-path)

## DeepSeek accepted the field and ignored it, so every request to it was unbounded

`OpenAiCompat` is the table of ways an "OpenAI-compatible" endpoint is not one, and its own note
says how a row gets added: *"None of that is documented anywhere as a difference; it is what a 400
looks like after you have sent it."* **This row is the one that never produced a 400.**

`api.deepseek.com` falls in `detect()`'s default arm, so pig sends `max_completion_tokens`. DeepSeek
takes it, ignores it, and answers. Measured through `test/live.php` against a `models.json` provider:

```
NO  max_tokens stops it   it came back as stop after 145 output tokens against a budget of 16
                          — the budget was ignored, so the field name is wrong for this endpoint
```

So **no pig request to DeepSeek had ever been bounded**: not `SimpleStreamOptions(maxTokens: …)`, not
the model's declared `maxTokens`, not the compaction summariser's. Nothing failed, nothing was
logged, and the only symptom is a bill. `deepseek.com` joins `chutes.ai` on the `max_tokens` row.

**An endpoint that quietly drops a field is worse than one that refuses it, and only a number tells
them apart.** The first run reported `it came back as stop rather than length`, which reads like a
stop-reason mapping that missed a word — and the two have opposite fixes. What separated them was
reporting the output count beside the budget: 145 against 16 is a limit that was never applied, and
about 16 would have been a limit applied and misreported. *A live scenario that reports a verdict
instead of a measurement can send you to the wrong file.*

Found through `models.json` — the harness offering declared providers is what found it, and the
developer having a key for a provider pig did not carry yet is the only reason anybody looked.

**And the same run found the silent overflow is not one provider's quirk.** `Overflow::happened()`
carries a usage-based branch for z.ai, which takes an oversized request, answers, and bills past the
window. DeepSeek does it too — a prompt sized past a declared 65,536-token window came back
**answered and billed at 98,315 input tokens**, and the branch caught it. Two examples is what makes
it a shape rather than a special case: a new endpoint is worth asking the question of rather than
assuming it refuses. That branch is also the reason the scenario had to be rewritten before it could
see any of this — it returned on "the provider answered" without ever consulting the usage, so a
provider of that kind was reported as a failure with no numbers in it.

**This entry used to say "upstream has it for that one endpoint", and that is false.** Checked
because the claim was about to be repeated out loud: upstream's `isContextOverflow` guards the branch
with `if (contextWindow && message.stopReason === "stop")` and its caller `_checkCompaction` passes
`this.model?.contextWindow ?? 0` — **provider-agnostic, exactly as pig's is**. Only its *comment*
names z.ai, as the one provider somebody had measured. So the two tools detect a silent overflow
equally well, and what decides whether either of them does is something else entirely: **whether the
declared `contextWindow` is right.** For a provider pig ships no entry for, that
number comes out of a hand-written `models.json`, where `CustomModels` checks it is a positive whole
number and cannot check it is *true*. Declared larger than the real window, the comparison never
fires in either tool; declared smaller, both compact early. That is the first thing to ask about when
a silent-overflow provider misbehaves, and the fourth shape from the index is why this paragraph
exists: a claim about a repository is worth a grep, upstream's included.

Regression tests: `OpenAiCompletionsTest::testDeepSeekGetsItUnderTheOlderNameToo` (taking DeepSeek
off the `max_tokens` row turns it red) and `testDeepSeekIsNonStandardTheWayUpstreamDetectsIt` — no
`store`, no `developer` role, as upstream's `detectCompat()` has it.

## 1,633 mutations over the five providers, and an option none of them sent

The worry the provider-SDK section states — 4,977 lines of hand-written protocol whose every test
answers a canned server pig wrote itself — has been answered by corpora for the pure functions and
by `test/live.php` for the wire. This is the third instrument: **change one decision at a time and
see whether any test notices.** Every file under `packages/ai/src/Providers`, one mutation at a
time, against the 423 tests that could possibly catch one.

Eleven operators (`===`↔`!==`, `&&`↔`||`, the four relational ones, `null`→`''`, `true`↔`false`)
plus the one that earns its place — **delete a whole statement and ask whether it was load-bearing**:

| | |
|---|---|
| mutations run | 1,633 |
| killed outright | 1,292 |
| hung the suite | 150 |
| **survived** | **191** |

A hang is a detection too — a mutant that stops the suite finishing is not a mutant that got past
it — so the score is 1,442 of 1,633, and the 191 are the list worth reading. **Two of those rows
were later found to be partly contamination**; see the second harness note at the bottom of this
entry, and the entry after it for what the re-run found. Most are equivalent
mutants: `?? null` → `?? ''` in front of an `is_string()`, a `$signal =` line whose absence is the
same as the null nothing passes, a `$stream->end()` whose consumers stop at the body's end anyway.
**Eleven were not**, and they divide into three findings.

**The big one: `temperature` reached no provider in any test.** Deleting the line that sets it
passed the whole suite in **all five** — Anthropic, both OpenAI shapes, Gemini and Code Assist —
and a grep confirms it: the word `temperature` appeared nowhere in `packages/ai/test`. So the one
option that changes what every model does could have stopped reaching every endpoint without a
single case going red. Nothing was broken; the point is that nothing would have said so.

**The second is the first shape from the index, on a request body.** `GoogleGeminiCli` had its
thinking budget, its output cap and `includeThoughts` pinned; `Google`, the sibling that shares
`GoogleShared` with it, had **none of the three**. Deleting `maxOutputTokens` from the public
endpoint's request passed — and an output cap nobody sends is an unbounded answer, which is
precisely the DeepSeek `max_completion_tokens` bug this file records finding live. `includeThoughts`
flipped to `false` passed too: the thoughts are then billed and never arrive.

**The third is a documented trap with its own provider unpinned.** The entry on an empty arguments
list says `input: []` is a list to Anthropic and that Anthropic **refuses the request**. Mutating
`$content->arguments === []` to `!== []` in `Anthropic::messages()` — which sends `{}` for a call
that has arguments and `[]` for one that does not, exactly inverting the fix — passed. The test for
it had to assert on the **raw body**, because `json_decode(assoc: true)` turns `{}` and `[]` into
the same PHP value: the third shape from the index, arriving in the test for a bug that was itself
the third shape.

Also fixed, smaller: `Copilot::headers()` reads the last message to decide who asked, and its own
comment calls "nothing said yet counts as the person" upstream's default. No test sent it an empty
conversation, so both mutations that break that answer survived.

Regression tests, and each of the eleven mutations was re-applied afterwards to confirm it now
dies: `AnthropicTest::testATemperatureSomebodySetReachesTheRequest`,
`testAToolCallWithNoArgumentsGoesOutAsAnObject`, `GoogleTest::testWhatTheTurnAsksForReachesTheRequest`
and `testNoneOfThemIsSentWhenNobodyAskedForOne`,
`GoogleGeminiCliTest::testTemperatureAndTheOutputCapReachTheRequest`, the same temperature case in
both OpenAI test classes, and `OpenAiResponsesTest::testNothingSaidYetCountsAsThePerson`.

**Two things about the harness, because a measuring instrument is a claim like any other.**

The first version bound the worker tree to the job index — `worker = i % 8` with eight threads — so
job 8 could start in the tree job 0 was still using and two mutations shared one file. It
manufactured **three false survivors out of fourteen** on the trial run, and one of them was a
header `OpenAiResponsesTest` demonstrably asserts. Reproducing that one by hand is what exposed it;
the worker now comes from a queue. *A survivor list is only as good as the isolation under it.*

**And the second flaw was worse, because it produced a whole row of numbers that meant nothing.**
`TransformMessages.php` came back `0 killed, 0 survived, 77 timeouts` — every mutation apparently
hanging the suite, including replacing the whole of `apply()` with `return $messages;`. Chasing it
found the cause was not in pig at all: the worker trees were **reused between runs**, an outer
`timeout` had killed one run mid-mutation, and the worker threads died without running the `finally`
that restores the file. The next two runs measured against trees with somebody else's mutation still
applied. The pass-through takes six seconds and fails four tests when actually run.

So `TransformMessages.php`'s 77 timeouts and `AssistantMessageBuilder.php`'s 47 were both suspect,
and re-running them with trees recreated from scratch separated them: the transform's were entirely
contamination, and **the builder's 47 are real** — mutating the accumulator genuinely stops the
stream terminating. The trees are rebuilt every run now. *Why* it stops terminating, and why that
makes the 47 detections of a state no provider can reach rather than a gap, took its own read and has
its own entry below.

The rule, for the third time in this session and the second in this entry: *a measuring instrument
is a claim like any other.* Both of this harness's bugs produced **confident wrong output** rather
than an error — three false survivors from the first, seventy-seven false timeouts from the second —
and both were found by picking one number off it and reproducing that number by hand.

## The registry was a transcription of a generated file

Two failures, months apart, with one cause. Groq answered `404 The model 'llama-3.3-70b-versatile'
does not exist or you do not have access to it` for a row `--list-models` still offered; and
`gemini-3.8-flash`, a real model, answered `no such model in the registry` and had to be
hand-declared in `models.json` before pig could reach it at all. Both were read as the price of
pinning the port to `d0a4c37`, which is a deliberate and defensible pin — and that reading was
wrong about what the pin covered.

**Upstream does not keep this table.** The file pig transcribed 178 rows out of opens with

```
// This file is auto-generated by scripts/generate-models.ts
// Do not edit manually - run 'npm run generate-models' to update
```

and at upstream HEAD the data is not in the repository at all: `models.generated.ts` is 130 lines
of imports, each provider's catalogue arriving from models.dev at build time (`git ls-tree` finds
no `providers/data/` — only the `.d.ts` that types the JSON import). So the thing pig pinned was a
generator's *output*, and `Models`' own docblock called the freeze fidelity: *"the figures are
upstream's at the anchor commit, which is the source a port should agree with rather than whatever
models.dev says today."*

**The decisive consequence: moving the anchor would not have fixed either failure.** There is no
newer table to port to. Porting the anchor forward is a separate, much larger question — 5,003
commits, the four packages 139 → 616 non-test files, 505 files at HEAD that do not exist at the
anchor — and it is *not* the answer to a stale registry. Conflating the two is what kept this
unfixed through two reports.

So `scripts/generate-models.php` is upstream's `generate-models.ts`, ported to the part pig needs:
read models.dev, keep what `tool_call` says can be handed a tool, map per provider to an api and a
base URL, and rewrite the rows. Five things about it are the decisions rather than the code:

- **It writes rows, not files.** Each table carries a `>>> generated` / `<<< generated` pair and
  only what lies between them is replaced, so every docblock, base URL, `RESOLD` entry and Copilot
  header stays hand-written and a regeneration's diff is the rows and nothing else. The alternative
  — emitting the whole file, as upstream does — would move all that prose into the generator.
- **The two subscription tables stay hand-written**, because they are hand-written in *upstream's
  generator too*: models.dev does not carry a Code Assist or Antigravity catalogue. That is a
  verified reason and not an omission.
- **Nothing is defaulted quietly.** Upstream writes `m.limit?.context || 4096`, which turns a
  missing window into a number small enough to make every conversation look nearly full, and a
  missing output cap into one that truncates every answer. The default is kept so the row is
  usable and **the complaint is what gets somebody to look** — the same arrangement as every other
  loader in this repository. Likewise a provider that has vanished from the catalogue is named and
  its table is **left alone**, because writing the empty result would take every one of that
  provider's models out of pig in silence.
- **`ANTHROPIC_MODELS` has no images column**, and the assumption behind that is now checked: a
  text-only Anthropic model is complained about rather than silently recorded as accepting images.
- **Upstream's corrections are ported with their reasons**, not their values alone — models.dev
  reports three times the real cache pricing for `claude-opus-4-5`, and two models it does not list
  are supplied by hand. A number with no reason beside it is a number nobody can ever retire, so
  each carries one, and an override whose row the catalogue has since started carrying **says so**
  instead of shadowing it for ever.
- **Upstream's `openai` temporary overrides are rules, applied every run** (`openAiTemporaryOverrides()`):
  `OPENAI_SHORT_CONTEXT_CAPPED_MODEL_IDS` (gpt-5.4, gpt-5.5, the GPT-5.6 trio, GPT-6, 6.1 Sol) get a
  272,000 window and 128,000 output — the window is where compaction fires, so a conversation is
  compacted before OpenAI's long-context price — `OPENAI_LONG_CONTEXT_PRICING_MODEL_IDS` get
  `withOpenAiLongContextPricing(OPENAI_STANDARD_COSTS[id] ?? cost)` (one tier above 272k: 2x input
  and cache, 1.5x output), and gpt-5-pro's output is 128,000. Upstream's `missingOpenAiModels` are
  `add` overrides. Upstream says users opt into the full window through `modelOverrides`; pig has no
  `modelOverrides`, so the way back to it is a `models.json` provider of one's own.
- **models.dev's `reasoning_options` are read** for the providers upstream records them for (not Google,
  not Mistral, whose rows take a built map instead — below): `getEffortThinkingLevelMap()` becomes the row's `effortLevelMap`, and `Models` merges it
  where upstream's `applyModelsDevReasoningOptionMetadata()` does — after the Anthropic compat arm,
  before the id rules — only when `supportsDirectReasoningEffort()` (Responses; adaptive Anthropic;
  completions with `thinkingFormat: "openai"` and `supportsReasoningEffort`). The rows carry none
  until the next regeneration on a machine that reaches models.dev.
- **Google, Mistral and z.ai rows carry a built `thinkingLevelMap`**, as upstream's generator builds
  it where it makes the row: Google's `getGoogleThinkingLevelMap()` (the verified efforts, else Gemma
  4's `{off: null, minimal: "MINIMAL", low: null, medium: null, high: "HIGH"}`; the `-latest` aliases
  read the model they name), Mistral's efforts (`reasoning_effort` at run time, `prompt_mode` for a
  reasoning model without), z.ai's efforts with GLM-5.2's `off: "none"`. A measured correction in
  `OVERRIDES` merges into the map rather than replacing it. z.ai's rows are read from models.dev's
  `zai-coding-plan` entry (the plan's endpoint is pig's `zai`), priced from `zai`'s.
- **Copilot rows carry models.dev's list prices and GitHub's extended windows**, as upstream's do:
  `cost: getModelsDevCost(m.cost)`, and `GITHUB_COPILOT_EXTENDED_CONTEXT_MODELS` as a rule over every
  row (`copilotTemporaryOverrides()`) — 1,000,000, whatever models.dev says. Upstream's
  `missingCopilotModels` (Opus 5.5, GPT-6 Sol and Luna) are `add` overrides.
- **Only Copilot's `deprecated` models are left out**, as upstream's generator leaves them out among
  the providers pig generates.
- **`inputLimits` and `promptCache` are upstream's generator metadata**, written in `Models::table()`
  (`inputLimits()`, `promptCache()`) like the maps: per-provider image limits plus the 2000px / 4.5 MiB
  resize profile on every image model, and `{short: 300, long: 3600}` on direct Anthropic.
  `promptCache` is read by `Session\CacheWarmer` for the cache lifetime; `inputLimits.images.resize`
  by `Utils\ImageProcess`, as upstream's image preprocessing reads it; `StreamProxy` sends both and
  `models.json` may set both.

`--from <file>` reads a saved `api.json` and `--dry-run` prints the rows instead of writing them.
Neither is a seam for a test: models.dev is unreachable from the dev container (`CONNECT tunnel
failed, 403`, like every host but Anthropic's), a regeneration from somebody else's snapshot is the
only way to reproduce a table, and a generator whose input cannot be pinned is one whose output
cannot be explained.

**Every run prints what moved** — added, gone, and each field that changed — against the table
loaded before the rewrite, and it reloads the written file in a fresh process to build that
comparison, because PHP cannot be told to forget a class it has already resolved. A regeneration
nobody read is a registry nobody checked, which is the failure this whole entry is about.

Regression tests: `GenerateModelsTest`, which **spawns the script** the way
`RpcClientTest` spawns `bin/pig` and for the same reason. Four rules were mutated one at a time and
each turns exactly one case red: the `tool_call` filter, the deprecated-Copilot skip, the api-by-id
rule, and the empty-table guard. **The fourth appeared silent and was not** — the replacement text
in the mutation script never matched the file, so nothing had been mutated at all; it went red once
the substitution was checked. *A mutation that changes no test is only evidence once you have
confirmed the mutation applied.*

## The first real regeneration, and what it cost

Run against models.dev on a machine that can reach it: **99 added, 104 gone, 28 changed**, 178
models down to 173. The generator did what it was written to do, and then **49 tests went red with
nothing wrong in pig**. That is the bill for the decision, and it is worth itemising because most of
it was a kind of test this repository had not named before.

**Two of the 49 were real bugs.** Both had been latent for as long as the table was frozen, and both
are the same shape: a hardcoded id that only stops working when the catalogue moves.

- **`AgentState::DEFAULT_MODEL` named a retired model.** It was
  `gemini-2.5-flash-lite-preview-06-17`, models.dev has dropped it, so `Models::find()` answered
  null and an `Agent` nobody configured was back to throwing `No model configured` — the exact thing
  those two constants were added to prevent. `bin/pig` never reaches them, so nothing in a coding
  session would have shown it; `AgentTest::testAnAgentNobodyConfiguredStillHasAModel` did, which is
  the test doing its job rather than a test to fix. It is `gemini-2.5-flash-lite` now, and the
  docblock says the rule that picks a replacement (the cheapest thing Google sells) so the next
  person is not guessing.
- **An override replaced a good row with the stale fallback beside it.** `xai/grok-code-fast-1`
  carried an anchor-era `add` override, on the grounds that models.dev did not list it. **It had
  never fired**, because at the anchor the catalogue *did* carry the model — with a 256,000 window, a
  10,000 output cap and reasoning on, which is what pig's transcribed row held. models.dev has since
  dropped it, the override fired for the first time, and the diff read `window 256000 → 32768; output
  10000 → 8192; reasoning yes → no`. *An override that has never been exercised is an override nobody
  has checked.*

Asking upstream what it thinks **now** is what settled the second one, and it is the cheap move worth
remembering: HEAD carries `XAI_BUILTIN_EXCLUDED_MODEL_IDS`, and `grok-code-fast-1` is on it, with
`grok-3`, `grok-3-fast` and two preview ids. So the catalogue dropping it and upstream excluding it
agree, and re-adding it under any numbers would be offering a model that cannot be talked to — the
Groq 404 again. The override is gone and that list is ported as `EXCLUDED`, which also drops three
models this regeneration would otherwise have added.

**And the override machinery gained the sentence it was missing.** A `fix` merged its values blind,
so it reported "corrected" whether or not the catalogue still had the figure wrong — meaning the day
models.dev fixes its own number, that row becomes a no-op nobody retires. The `add` arm already said
when the catalogue had caught up; `fix` says it now too.

**The other 47 were tests asserting what providers sell.** Sixteen in `ModelsTest` alone: 178 models,
a count per provider, eleven named rows checked to the token and the cent. Those were *right* for a
hand-transcribed table — the docblock on the count said exactly why, *"the way it goes wrong is a row
quietly missing or doubled"* — and that reason expired the moment the rows were generated. What they
assert now is what pig does: every row built whole, every provider pig claims present and nothing
present that pig does not claim, the resale rule, the figures varying per model rather than one set
for all. Where a case needs *a* model with some property it **finds one in the table**, and fails
loudly if the table cannot supply it, because that absence is itself a finding — no reseller-only id
means the fallback in `get()` is dead code.

The rest named live ids as fixtures for rules that have nothing to do with which models exist: that
an alias beats the dated build behind it, that a bare id means the direct provider, that a colon sets
the thinking level, that a non-reasoning model gets its level clamped. `Pig\Test\ProbeModels` is the
answer — a family registered under real providers with ids beginning `zzp-`, which no real id or
display name contains, so substring matching cannot cross between them and the real table. Eleven
cases in `ModelResolverTest`, eleven in `CodingAgentSessionTest`, two in `InteractiveModeTest` and one
in `RpcModeTest` moved onto it.

Four places could not use it and each says why in place:

- **`AgentSessionTest` builds its models locally**, which that file already did — and the case that
  needed a model that *cannot reason* had nothing left to look up, because every Anthropic model in
  the table now reasons.
- **`RpcClientTest` spawns `bin/pig`**, so a registration in the test process does not cross the
  process boundary. It declares the pair in the child's `models.json` instead, which is the same door
  a person uses for a model pig has no entry for — and `CustomModels` requiring an `apiKey` is what
  makes the with-key and without-key cases work off one declaration.
- **`ModelListTest`'s punctuation case** relied on Copilot selling `claude-sonnet-4.5` while Anthropic
  sold `claude-sonnet-4-5`; Copilot's is gone, so a rule about a `.` being a `.` failed for a reason
  that had nothing to do with punctuation. It registers a dotted and a hyphened id of its own.
- **`InteractiveModeTest::testPickingFromTheListSwitches` named the first row of the list** while its
  own comment said *"the first row of the list, whichever it is — what matters is that choosing one
  actually changes the model"*. The regeneration put a different model at the top. The assertion says
  what the comment said now. *A comment that describes a weaker assertion than the code makes is a
  comment that will be right before the test is.*

**`test/live.php`'s table had two dead rows** — `xai/grok-3-fast` and `github-copilot/gpt-4.1`, both
gone from the catalogue — and a dead row there is only noticed when that provider is actually run,
which is the argument for running the harness with no arguments after a regeneration.

The rule the whole batch adds, and it is the same one as the documentation rule two entries up, one
domain over: **a test that names a live model id has an expiry date.** Name one only when the
assertion is about that model; otherwise register what the rule needs. The tell is the same as ever —
if the test's own comment says the id does not matter, the assertion should not name it.

**The second regeneration was the quiet one, and it is the one that says the machinery works.** With
`EXCLUDED` in place: `0 added, 4 gone, 0 changed` — exactly the four xAI ids and nothing else, so the
first run's 99/104/28 really was the frozen table catching up rather than the generator being
unstable. 169 models. And it retired an override without anybody re-deriving anything:

```
! the override for anthropic/claude-opus-4-5 changes nothing any more: models.dev has 3x the real cache pricing
```

models.dev had fixed its own figure. That line is the whole reason a correction reports when it
changes nothing, and it arrived on the mechanism's first opportunity to use it. The override is gone.

**Which left the `fix` arm with no entry, and that was worth not shrugging at** — an untested arm in
the thing that rewrites the registry is what this entry is about. So upstream HEAD was read for a
*live* correction instead of deleting the two tests, and there is one:
`GITHUB_COPILOT_EXTENDED_CONTEXT_MODELS`. Measured against the regenerated rows, four of its ids were
short:

```
claude-opus-4.7    only 200,000
claude-opus-4.8    only 200,000
claude-sonnet-4.6  only 200,000
gpt-5.3-codex      only 400,000
```

GitHub's own table gives those the 1,000,000-token window. **A context window is where compaction
fires**, so a fifth of the real figure means pig summarising a conversation that had four times the
room — the failure the deleted `spotValues` docblock described in as many words ("too small
summarises a conversation that had room"). The check behind upstream's list is **inherited and not
repeated here**, the same standing as `EXCLUDED`. It is a rule now, as upstream's is — every listed
id gets 1,000,000 on every run (`copilotTemporaryOverrides()`) — so it also covers an id models.dev
lists later, and the five it listed at 1,050,000 rather than GitHub's figure.

## Eighteen of Gemini's twenty finish reasons were an error with no words in it

Reported from real use — *"新版 Gemini 和 DeepSeek 总是因为这个出错"* — and the half of it that could be
settled without waiting for the message was settled by a probe rather than by reading. A Gemini
candidate carries a `finishReason`, and `GoogleShared::stopReason()` answers `STOP => Stop`,
`MAX_TOKENS => Length`, `default => Error`. The default is the whole enum's worth of ways a turn can
produce nothing: `SAFETY`, `RECITATION`, `BLOCKLIST`, `PROHIBITED_CONTENT`, `SPII`, `LANGUAGE`,
`MALFORMED_FUNCTION_CALL`, `UNEXPECTED_TOOL_CALL`, `OTHER` and the image ones. Measured, one chunk per
row:

```
finishReason=SAFETY                   stopReason=error   errorMessage=NULL   overflow=no
finishReason=RECITATION               stopReason=error   errorMessage=NULL   overflow=no
finishReason=MALFORMED_FUNCTION_CALL  stopReason=error   errorMessage=NULL   overflow=no
finishReason=OTHER                    stopReason=error   errorMessage=NULL   overflow=no
finishReason=MAX_TOKENS               stopReason=length  errorMessage=NULL   overflow=no
finishReason=STOP                     stopReason=stop    errorMessage=NULL   overflow=no
```

**`errorMessage` is null, and three things read it.** `Overflow::happened()` and the retry
classification each test `stopReason === Error && errorMessage !== null` as their *first*
condition, so such a turn is **neither compacted nor retried**; and what reaches the screen is an
error with no text. A safety block, a recitation block and a malformed call are then the same event
to look at — and the last of those is ordinary on Gemini 3 with tools. The nearest thing to a
diagnosis anybody had was the absence of one.

Upstream now throws for these too, in `google-generative-ai.ts` / `google-vertex.ts`: `Provider
stopped with: <raw reason>` (pinned by `google-raw-stop-reason.test.ts`) — once the whole stream has
been read, from its check after the loop — so pig throws the same sentence from the same place
(`Google::run()`, and Antigravity's, which reads its stream the same way). Upstream also only turns
a **STOP** with a tool call in it into `toolUse`; `MAX_TOKENS` with a call stays `length` and an error
reason with a call part stays an error, so `GoogleShared::onChunk()` maps the reason first and only
then looks at the content. Upstream also keeps the raw reason as `AssistantMessage.rawStopReason`, and
so does pig now (a trailing constructor parameter — `responseId`, `responseModel`, `endTurn` and
`diagnostics` follow it the same way — written to JSON only when set): Anthropic's `stop_reason`,
completions' `finish_reason`, responses' `status` / `status.incompleteReason` / failed status, and
Gemini's `finishReason` (antigravity included, through `GoogleShared`), so the failed turn still
carries `MALFORMED_FUNCTION_CALL`; `fail()` leaves it alone.

A `MALFORMED_FUNCTION_CALL` itself is Gemini's doing, not pig's: Gemini 3 sometimes writes the call as
text (`call:default_api:bash{command:…}`) instead of a `functionCall` part and then reports the turn
malformed. pi shows the same error for it and does not retry it (`isRetryableAssistantError` has no
pattern for it), and neither does pig.

Three things had to be got right and each has its own test:

- **The rest of the stream is read before the turn fails.** The usage can ride on the chunk with the
  reason or after it; throwing on that chunk jumped over it — measured at `input=0 output=0` on a
  prompt of 40 tokens. A turn the provider refused was still billed for its input, which is
  `testAnAbortedTurnKeepsTheUsageThatHadAlreadyArrived`'s rule from the other end: *a turn that
  produced nothing did not therefore cost nothing.* `GoogleShared::onChunk()` only records the reason;
  both callers throw after the loop, with the open block already closed (`close()`), so the events
  still pair `TextStart` with `TextEnd`.
- **Nothing became retryable by accident.** None of the reason names matches upstream's retryable
  patterns (`Pig\Ai\Utils\Retry`), and none matches an `Overflow` pattern — so a safety block is
  explained and still not waited out, which is right, because asking again gets the same answer.
- **`MAX_TOKENS` and `STOP` still say nothing**, or the fix would be "every finish reason throws".

Regression tests: `GoogleTest::testAFinishReasonThatMeansNothingUsableSaysWhichOneItWas` (four cases,
including a reason this pig has never heard of), `testACallCutOffByTheTokenLimitIsALengthNotAToolUse`,
`testAMalformedCallIsAnErrorEvenWithACallPartInIt`, `testARefusedTurnKeepsWhatItSaidAndWhatItCost`,
`testTheTwoReasonsThatAreNotFailuresStillSayNothing`, the strengthened
`testASafetyBlockIsAnErrorHoweverPolitelyItIsPhrased`,
`AntigravityApiTest::testAnErrorFinishReasonIsReadToTheEndOfTheStreamBeforeTheTurnFails`, and the
`gemini safety` case of `Pig\Ai\Test\Utils\RetryTest::testWhatUpstreamDoesNotRetryIsNotRetried`.

**And the old test is why this lasted.** `testASafetyBlockIsAnErrorHoweverPolitelyItIsPhrased`
asserted `StopReason::Error` and nothing else, so it passed identically with a message and without
one — *an assertion that holds either way is not an assertion*, for the fourth time this file has
said so.

## Google's overflow row, verified against a model released after the anchor

The entry this replaces was an open question: the new Gemini and DeepSeek both fail often enough to
have been reported, and the `Ai\Utils\Overflow` rows meant to catch them had never been read back
from those endpoints. The Google half is now answered, by declaring `gemini-3.8-flash` in
`models.json` and running the harness at it — **eleven of eleven, and the sentence matches**:

```
ok  overflow is recognised   ok, refused: google returned 400:
    The input token count exceeds the maximum number of tokens allowed 1048576.
```

Worth keeping both wordings, because they are not the same sentence and only one of them was ever
the example:

| | |
|---|---|
| the anchor-era example | `The input token count (1196265) exceeds the maximum number of tokens allowed (1048575)` |
| what `gemini-3.8-flash` says | `The input token count exceeds the maximum number of tokens allowed 1048576.` |

Google dropped both parenthesised numbers. `/input token count.*exceeds the maximum/i` spans the gap
because the `.*` sits exactly where the count used to be — **luck rather than design**, and the
useful reading is that a pattern anchored on the *shape* of the numbers would have missed it. So the
row holds, and the reason to write this down is that it was one `curl`-free command away from being
known and was assumed for months instead.

**Which also says the reported failures are not this.** A Gemini overflow is recognised and
compacted; what was not recognised is the entry above — a finish reason with no message — and that
is the one that had no way to say what it was.

DeepSeek's half stays open in a different sense: the branch that catches it is the usage comparison,
and **whether it fires at all depends on `models.json`'s `contextWindow` being the true window**. That
number is hand-written, `CustomModels` can check it is a positive whole number and cannot check it is
right, and it is an *input* to this very scenario — the oversized prompt is sized from it. Declared
too large, the prompt may still fit and "the provider accepted an oversized request" is an artefact
of the file; declared too small, an ordinary answer looks like a silent overflow. So for any declared
provider, the window is the first thing to get right and the last thing to trust.

The general rule, unchanged and now cheaper to follow: a pattern with no example beside it is a guess
and a guess here compacts a conversation that was fine. `php test/live.php <provider>/<model-id>`
prints the provider's own words when they are not recognised, which is what that argument was added
for; for a model newer than the anchor, declare it in `models.json` first.

## The overflow pattern for Cerebras could never match pig's own words

Seventeenth, and the narrowest kind of porting bug: a regex ported with its subject left behind.
Upstream's bodiless-4xx pattern matches `"400 status code (no body)"` — the **OpenAI SDK's**
phrasing. pig's providers then wrote `"<who> returned <status>: <message or body>"`, so a 4xx with
an empty body ended at the colon and the pattern could not fire, and the session retry sent the
same too-long request three more times with backoff where compaction was the answer.

Every built-in provider now writes upstream's own error text (the SDK messages, `Utils\ErrorBody`), so
the pattern reads what it was written for, and `Overflow` is upstream's table as it stands: the
bodiless pattern counts **only for the `cerebras` provider** and only for 400/413
(`CEREBRAS_BODYLESS_OVERFLOW_PATTERN`); rate-limit and throttling text is never an overflow
(`NON_OVERFLOW_PATTERNS`); and a `length` stop with nothing written and the window ≥99% full is one
(Xiaomi MiMo). The test goes through a real provider and a canned 400, so the pattern and the message
it has to match cannot drift apart again.

Checked at the same time and found matching, so nobody has to check them twice: the other
`packages/ai` utilities. `Utf8::sanitize()` handles what upstream's `sanitizeSurrogates()` handles
and the CESU-8 surrogate case its docblock claims (verified, not assumed); `Credentials`, `Provider` and all four OAuth flows carry upstream's
endpoints, client ids and the same five-minute renewal margin; `SseParser` follows the spec on the
one optional space, multi-line `data:`, comments and CRLF; `PartialJson` is a hand-written stand-in
for the `partial-json` package, and it has now been run **against** it — `partial-json@0.1.7`, every
prefix of a corpus of realistic tool arguments, two differences and both accounted for in its
docblock. `Utils\Retry` is upstream's `utils/retry.ts`, its two pattern lists entry for entry.

## A truncated answer came back as a finished one, with no usage at all

Two terminal events end a Responses API stream and `OpenAiResponses` listed one. `response.failed`
and `response.completed` were there; **`response.incomplete` was not** — which is the one the API
sends when the answer was cut off, `max_output_tokens` reached being the ordinary way. So the stream
simply ended: `onCompleted()` never ran, the status was never read, and `stopReason()`'s own
`'incomplete' => Length` arm was **unreachable code**. Measured against the real API with a
sixteen-token budget:

```
NO  max_tokens stops it   it came back as stop after 0 output tokens against a budget of 16
```

Two symptoms, one missing arm, and the second is the worse one. **The usage rides on that event
too**, so a truncated turn reported none: the half-sentence read as the finished answer, and the
turn cost nothing in `/session` and the footer. Hitting the output cap is how a long answer
ordinarily ends, so this under-reported the commonest expensive turn there is.

*A `match` arm for a value that can never reach it is the same defect as a missing arm, and it reads
as coverage.* `stopReason()` looked complete — it names `incomplete` — and nothing above it could
deliver that word. The one place to check is the caller's event list, which is the third time this
file has arrived at *read the other end*.

The same run also produced a bare **`unknown error`** for an oversized prompt, which was
`errorText()` reading `$data['message']` on an `error` event that did not carry it there.
`Overflow`'s table matches against a provider's own words, so a message that goes missing there is a
conversation that could have been compacted and instead died. The event is now read the way the
`openai` SDK reads it for upstream — see `OpenAiResponses::sdkEvent()` and the Responses paragraph.

**Both fixes were then run against the real API and both hold**, and the second answered a question
this entry had left open. The overflow case came back
`ok, refused: Error context_length_exceeded: Your input exceeds the context window of this model.` —
a message *and* a code where there had been neither, so **the live event nests both under `error`**
and the documented flat shape is not what arrives. Two things follow: reading only the documented
path loses the whole message, and `/exceeds the context window/i` — OpenAI's own row in
`Ai\Utils\Overflow`, inherited from upstream and never checked against the API — **matches.** The
truncation case came back `ok` as well: `response.incomplete` now ends the turn as `Length` with its
usage intact — when its reason is `max_output_tokens`; any other reason is an error, as upstream's
`mapStopReason()` has it. *The fallback that prints the payload is what turned "which shape is it" from a guess
into a line of output.*

Regression tests: `OpenAiResponsesTest::testAnIncompleteResponseIsLengthAndKeepsItsUsage`,
`testAnErrorEventIsTheSdksMessageWithoutTheCode`, `testAnErrorWithNoMessageAnywhereCarriesWhatArrived`.

## A 404 for a model pig offers, and a cause that did not survive the second data point

Groq answered every scenario with `404 The model 'llama-3.3-70b-versatile' does not exist or you do
not have access to it` — and **that id is in pig's registry**. `Ai\Models` was then a transcription
of `models.generated.ts` at the anchor, 2026-01-02, so a provider that had retired one since
answered 404 for a row pig still offered in `--list-models`, and `--model` still resolved.

**That was the reading, and the next run refuted it.** Swapping in `llama-3.1-8b-instant` — also in
the registry, and a current Groq model as far as anything here can tell — produced the same 404. Two
independent live models failing the same way on one key points at **the key's access** rather than at
the catalogue, and Groq's sentence says so in its second half: *"or you do not have access to it"*.
So Groq is still untested, the cause is the credential, and the staleness above is a real hazard that
this observation is not evidence for. *A cause that explains one data point is a hypothesis; the
cheap way to find out is one more point, which cost a single run here.*

**The hazard was real anyway, and this entry used to call it the deliberate price of pinning the
registry** — *"a port should agree with the thing it was ported from"*. It is fixed rather than
priced now: the rows are regenerated from models.dev, which is where upstream's come from, and
[the entry above](#the-registry-was-a-transcription-of-a-generated-file) has the argument. What
stands from this one is the method rather than the diagnosis: two data points cost one run and
turned a confident cause into the right one.

What it cost here was a whole provider's live run, and the harness made it worse before it made it
better: nine scenarios answered the same 404, and **two of them printed `ok`** — the empty-message
and budget cases accept a refusal as their answer and could not tell one refusal from another. Both
ask `isAboutTheRequest()` now (a 400 is the provider judging what was sent; a 401, 403 or 404 is it
saying something else entirely), and `text` is the pre-flight: if the plainest scenario cannot say
hello, the rest are skipped with one line instead of nine identical ones.

## An empty arguments list went out as `[]`, which is not an object

Found by the differential run above rather than by a provider complaining, which is the point of
running one: `PartialJson::parse('')` answers `[]`, and in PHP that is both the empty list and the
empty map — so a tool call with no arguments carries an array that `json_encode` writes as `[]`.

`arguments` is an object everywhere it is sent. **Anthropic and Google guarded it** —
`$content->arguments === [] ? new stdClass() : …` — and the two OpenAI arms and `MessageJson` did
not, so the same fact was known in two places out of four and the shape of the miss is this file's
commonest one. What it costs: Anthropic refuses `input: []` outright, and the session file — which
is pi's, and which pi hands straight back to a provider — recorded `"arguments": []` for pi to be
refused with later.

When does a call have no arguments? A tool that takes none, and a call whose arguments never
arrived because the turn was interrupted. Both are ordinary.

Fixed at all four sites, in the shape the two that had it already used: `=== [] ? '{}'` for the two
that encode to a string, `new stdClass()` for the two that hand back a value. **Four copies of one
rule** is what that leaves, and it is deliberate for now — the two originals were already inline and
a shared helper is a decision about `Pig\Ai`'s public surface, not an audit's to make. If a fifth
site appears, that is the moment.

## Anthropic was the one provider of four that never cleaned the conversation up

Fifteenth, from the request-building side, and it is the same count as the others: upstream calls
`transformMessages()` in all four providers; pig called it in three. `Anthropic::messages()` walked
`$context->messages` raw.

Both things that transform does are **refusals** on this API, not degradations:

- A thought is signed by the model that had it, and the signature means nothing anywhere else.
  pig's own rule downgraded an *unsigned* thinking block to text, so a Gemini thought — which has a
  signature, just not one Anthropic can read — went out as a signed `thinking` block and Anthropic
  rejected the whole request. **`/model gemini` then `/model sonnet` broke the conversation** until
  it was compacted past.
- A tool call with no result is what an interrupted turn leaves behind, and Anthropic refuses a
  `tool_use` with no matching `tool_result`. So the next thing anybody typed failed.

One line, and both tests fail on the old code. One difference from upstream in `TransformMessages`
itself: pig treats any non-result message as closing the turn rather than listing the roles —
upstream holds a `system` message back instead, and pig's message union has none.

## The provider that talks to Copilot sent none of Copilot's headers

Sixteenth, and a sharp one: `github-copilot`'s models are **`openai-responses`** in the registry, and
the three headers Copilot needs — `X-Initiator`, `Openai-Intent`, `Copilot-Vision-Request` — existed
only in `OpenAiCompletions`. Upstream has the same block in both providers because Copilot serves
both APIs; pig had it in the one Copilot never uses. So every Copilot turn went out without them:
an agent follow-up after a tool result was billed and rate-limited as though a person had just typed
it, and **an image was refused outright**, which is the whole of `/paste`-a-screenshot on Copilot.

**Nothing covered those headers in either provider**, which is how the one that mattered ended up
empty. They live in `Providers\Copilot` now — one implementation, three call sites (`Anthropic`
too, since Copilot's Claude models speak it), a test on each — for the reason this audit keeps
arriving at. The call goes *after* `$model->headers` in all three,
which is upstream's order: a registry entry must not be able to turn off the headers that make the
request acceptable at all.

Testing them needs one trick worth knowing: with a non-empty key, `endpoint()` asks
`GithubCopilot::baseUrl()` where to go and the request leaves for the real Copilot API, so a canned
server never sees it. The tests pass an empty key, which is the branch that keeps the model's own
base URL.

The request bodies no longer deviate on thinking: Gemini's public endpoint sends no
`thinkingConfig` when the options say nothing about thinking, as upstream does (it used to send an
explicit "no thinking"). The simple path still always says on or off, so only a direct
`Google::stream()` caller sees the difference. (The Code Assist endpoint rejects a
`thinkingConfig` on a model that cannot think and reads its absence as none.)

## Every Anthropic turn's input count was wiped out by its own last event

Eleventh find, from reading the four providers' stream events. `message_delta` is the event that
carries the stop reason and the final usage, and both pig and upstream read its `usage` with a
`?? 0` / `|| 0`. Anthropic's `MessageDeltaUsage` types `input_tokens`, `cache_read_input_tokens` and
`cache_creation_input_tokens` as **`number | null`** — "may not be reported" — and the streaming
documentation's own example carries nothing but `output_tokens`. So the final event replaced the
counts `message_start` had already given with zeros.

What that costs: the input cost of every Anthropic turn disappears from `/stats` and the footer,
and `Compaction::calculateContextTokens()` — which believes `totalTokens` — sees a conversation the size of
its last answer. **Auto-compaction never fires on Anthropic**; the turn recovers only when the API
rejects the request as too long, which is late, and by accident.

`Anthropic::update()` now merges: a field the delta reports updates, a field it does not leaves the
earlier number standing. That is right under either API behaviour, and the nullable type is the
argument for it — `null` means "no update", not "zero". It is a deliberate divergence from upstream's
literal `|| 0`, on the same ground as every other "no silent fallback" rule in this file.

**The fixture repeated the input in `message_delta`**, which real Anthropic does not, so the suite
agreed with the code. It now carries the documented shape, and two cases state the rule from both
sides: a delta with `input_tokens: null` keeps 1000, and a delta that does report 1200 is believed.

## OpenAI's final tool-call arguments were read from the deltas only

Twelfth. On the responses API a function call's arguments arrive as `function_call_arguments.delta`
events **and** whole, as `arguments` on the `response.output_item.done` item. Upstream builds the
finished `ToolCall` from that item (`parseStreamingJson(item.arguments || partialJson || "{}")`, where
`partialJson` starts as the `output_item.added` item's own `arguments`, unparsed until a delta or the
`done` reads it); pig used only what the deltas had
accumulated and never looked at the field. Usually the same JSON, so usually the same call — but a
stream that sends no argument deltas, which this API allows and a compatible endpoint may do (Copilot
speaks this API and implements it itself), left the call with **no arguments at all**.

`AssistantMessageBuilder::setJson()` replaces rather than appends, for the same reason
`setSignature()` exists: the usual case is the same JSON arriving twice, and appending would give
`{"a":1}{"a":1}`.

## A Gemini tool call's thought signature was collected and then dropped

Thirteenth, and the clearest "wired at one end only" of the whole audit. Four places handle a tool
call's `thoughtSignature`: `GoogleShared` reads it off the part, `setSignature()` stores it,
`GoogleShared::messages()` writes it back into the request, and `TransformMessages` keeps it for
the model that made it. The fifth — `AssistantMessageBuilder::toContent()`, the one place that *builds* the
`ToolCall` — constructed it with three arguments out of four, so `ToolCall::thoughtSignature` was
always null for a call that came from a stream.

So Gemini never got its own thought context back with the call it came out of, which is what the
field is for and what Gemini 3 expects. The write half could not be tested before this, because
nothing could produce a call that had one; it has a test now, and so does the read half.

**Two smaller things in the same read.** A Gemini `Part` is a one-of by convention rather than by
schema, and upstream reads `text` and then `functionCall` from the same part; pig checked for the
call first and returned, dropping any text that shared the part. And a later part with no
`thoughtSignature` does not clear the one an earlier part of the same block set — upstream's
`retainThoughtSignature()`, on text blocks (as `textSignature`) as well as thinking blocks.

## OpenRouter's encrypted reasoning was neither read nor sent

Fourteenth, and both halves were missing, which is why nothing looked wrong. A reasoning model
reached through OpenRouter returns its chain of thought as `reasoning_details` — a list of
`reasoning.text`, `reasoning.summary` and `reasoning.encrypted` objects — beside or instead of the
text in one of the three `reasoning*` fields. pig read none of it, so **multi-step tool use through
OpenRouter lost the model's reasoning between every turn.**

What upstream does now, and pig with it: the whole list for the message is kept as the thinking
block's signature (consecutive text and summary deltas merged, encrypted entries kept whole), a
thinking block is made to hold them when no reasoning text made one, and on replay the list goes
back as `reasoning_details` — *instead of* the raw reasoning field, which is written only when there
are no details. An older session's encrypted details on each tool call's `thoughtSignature` are
still read back. pig differs in one structural way: it ends a block when something of another kind
arrives, so the details are written into the first thinking block when the stream ends, and a block
made for them is never the open one (it would end the call they arrived beside).

The raw field on replay is upstream's too: its name is the **first** thinking block's signature,
only when that is `reasoning`, `reasoning_content` or `reasoning_text`, and every thought goes in it
joined by `\n`. An endpoint with `requiresReasoningContentOnAssistantMessages` (detected for DeepSeek;
`OpenAiCompat::$reasoningContentOnAssistantMessages`) gets `reasoning_content: ""` on every replayed
assistant turn of a reasoning model that did not already write one. While streaming, only the first non-empty of the three fields in a delta is read —
chutes.ai sends the same text in two.

Two differences in the completions provider that are **kept** rather than aligned, now that they are
written down: a tool call whose id arrives *after* its first delta stays one call here and becomes
two upstream (`toolCall.id && currentBlock.id !== toolCall.id` splits on the id appearing, where pig
also requires the open call to have had one); and pig pushes no `toolcall_delta` for a chunk that
carries an id and no arguments, where upstream pushes one with an empty delta.

## Twelve models were never told how hard to think

Ninth find, from auditing the providers — the part of pig that is hand-written where upstream has
an SDK, so the expectation was mistranslation and what turned up was a missing `match` arm.

`Stream::translate()` (upstream's `mapOptionsForApi()`) had four arms for five APIs. There was no
`Api::GoogleGeminiCli`, and the `default` handed back plain `StreamOptions` — so every model on the
Code Assist API got no thinking configuration at all: the five `google-gemini-cli` models **and the
seven `google-antigravity` ones**, which speak the same API. `--thinking high` on any of them asked
for nothing and was answered by a model that did not think, without a word anywhere. The `off` case
came out right by accident, because Code Assist reads a missing `thinkingConfig` as none.

**The `default` arm is the actual finding.** `Stream::start()`, ten lines below, matches on the same
enum with no `default` at all — so a new API would fail there loudly and silently get provider
defaults here. Upstream's own default is an exhaustiveness check that throws. `translate()` now has
one arm per API and no `default`, which is the same shape as its sibling and the reason the gap can
not recur silently.

Two rules were stated as being about the protocol when upstream has them about the model, and both
are now the model's:

- **xhigh** was clamped for every `openai-completions` model on the grounds that "xhigh is OpenAI's
  alone". True of the registry, false of a `models.json` proxy reselling `gpt-5.2` over
  chat-completions — which is the common shape where the direct API is unreachable. Upstream runs
  `clampThinkingLevel(model, level)` over the model's map in both OpenAI arms; so does pig now.
- **Gemini 3** was `str_contains($id, 'gemini-3')` in the public arm and upstream's two checks
  (`3-pro`, `3-flash`) in the one being added. Two arms of one file disagreeing about which models
  are Gemini 3 is the shape of every other find here. The public arm now asks upstream's
  `usesGoogleThinkingLevel()`.

The Code Assist arm is deliberately *not* the public Google arm: upstream gives it one flat budget
table for all 2.x models where the public arm has per-model ceilings (`2.5-pro` starts at 128 there
and 1024 here). `Stream` no longer has a Code Assist arm: that request is built by the antigravity
extension, whose `Routing::thinkingBudget()` keeps its own per-model table and does not go through
`GoogleShared::usesGoogleThinkingLevel()`.

**`StreamTest` covered one API of the four.** The tests it had were good ones — every reasoning
level against Anthropic's budget table — and the arm that was missing entirely belonged to a
provider nothing in that file mentioned. A data provider over one arm reads like coverage of the
method.

## The token total was recomputed for providers that report one

Tenth find, and the numbers it moves are the ones compaction decides on.
`AssistantMessageBuilder::setUsage()` called `withTotalTokens()` for every provider — Anthropic's
rule (it reports the components and no total) applied to the three that do report one. For Anthropic
and OpenAI's completions the sum is the same figure either way, so it looked harmless.

**For Google it is not.** Gemini's `promptTokenCount` *includes* the cached tokens, so
prompt + output + cacheRead counts them twice, and `totalTokenCount` — the figure Google sends,
which does not — was thrown away. `Compaction::calculateContextTokens()` prefers `totalTokens`, so a
conversation with most of its prompt cached reported a context far fuller than it was and compacted
early: a summarisation paid for, and a shorter window than the model actually had. Upstream computes
the total in exactly the two providers whose API gives none and reads it in the two that do; that is
now the one line in `setUsage()`.

**The test fixture was written to agree with the bug.** `GoogleTest` asserted `totalTokens === 170`
against a `usageMetadata` whose `totalTokenCount` *was* 170 — 100 prompt + 10 candidates + 40
thoughts + 20 cached, which is not arithmetic Google does. Gemini would have sent 150. A fixture
invented to match the code under test is the same failure as a claim in this file that nobody
diffed.

The **input** is `promptTokenCount - cachedContentTokenCount`, as upstream's
`google-generative-ai.ts` computes it, so the cached part is priced once, at the cache rate.

## A `models.json` key written as a bare variable name is the key itself

**Symptom**: a `models.json` (or `auth.json`) that says `"apiKey": "DEEPSEEK_API_KEY"` sends
`Authorization: Bearer DEEPSEEK_API_KEY` and gets a 401 that explains nothing.

**Cause**: upstream's `resolve-config-value.ts` reads a value as a variable only when it says so —
`$NAME` or `${NAME}` — and as a command when it starts with `!`; anything else is literal. pig used to
treat an all-capitals value as a variable name (upstream's old `resolveApiKeyConfig`), plus a
shape heuristic of its own to tell an unset name from a key. That is gone with no fallback: a file
written for pi has to mean the same thing here, and a heuristic about what keys look like is a guess.

**Rule**: a reference is `$NAME` / `${NAME}`; a bare word is a literal. A reference whose variable is
not set is **no key** — `hasKeyFor()` answers false, the models are not offered, and `problems()` says
`"apiKey" references $MY_BOX_KEY, which is not set`. `authHeader: true` with no key adds no header
instead of `Bearer ` with nothing after it.

```json
{ "providers": { "deepseek": { "apiKey": "$DEEPSEEK_API_KEY" } } }
```

Regression tests: `CustomModelsTest::testABareNameIsTheKeyItselfAsUpstreamReadsIt`,
`testADollarReferenceInTheKeyIsReadFromTheEnvironment`,
`testAReferenceWithNothingBehindItIsNotAKeyAndSaysWhich`, `testACommandKeyRunsForEveryRequest`,
`testAuthHeaderWithNoKeyBehindItAddsNoHeader`.

## `thinkingLevelMap`: the half of Antigravity's routing that belongs in pig

The Antigravity catalogue carries two maps and they are not the same kind of thing. `routing` and
`modelEnums` turn a level into **another upstream model** — `gemini-3.8-flash` at medium is sent as
`gemini-3.8-flash-medium`, which is sent as `MODEL_PLACEHOLDER_M319`. `thinkingLevelMap` turns a
level into **what this model calls it**, or says the model does not have it.

The second is upstream's own model-schema field (`ai/src/types.ts`, and
`ThinkingLevelMapSchema` in `core/model-config.ts` validates it in a `models.json`). The first is a
gateway's business: when the transport goes through something that already resolves ids — as it
does here, through an OpenAI-compatible gateway — putting the routing in pig too means two things
deciding one thing, which is the argument `GoogleGeminiCli`'s docblock already makes about the
retry loop it declined to port. So the line is: **the map comes in, the routing stays out.** That
boundary was read off pi's source rather than guessed; the entry above says why it could not be
read off pig's.

**Three states, and PHP flattens two of them by default.** A key that is absent means "send the
level's own name"; a key whose value is *null* means the model does not have that level; a string
is what to send instead. `$map[$level] ?? …` reads null as absent, which is the one value that
means the opposite — so `hasThinkingLevel()` is `array_key_exists`-first, and the docblock saying
so was written *before* the first implementation got it wrong anyway. Upstream writes
`mapped === null` freely because JavaScript tells `undefined` from `null`; this is the line where
that stops being free. The regression test is `ModelTest::testALevelTheMapNullsIsOneTheModelDoesNotHave`.

Where each piece lives, and why it is not all on `Model`: `Model` is in the `ai` package, which
depends on `pig/async` and nothing else — it has never heard of `ThinkingLevel`, which lives in
`agent-core`. So `Model` carries the string-keyed half (`thinkingEffort()`, `hasThinkingLevel()`,
`supportsXhigh()`) and `ThinkingLevel` carries the enum half (`supportedBy()`, `clampedFor()`).
Writing it the other way round inverts the package layering, and the first draft did.

**`xhigh` is offered only when the map names it**, upstream's rule; `Models` writes the generator's
maps on the OpenAI and Copilot GPT rows, so `gpt-5.2` and later have it from their row.

**Two contracts were changed on purpose.**

`AgentSession::availableThinkingLevels()` still answers the **empty list** for a model that cannot
reason, while `ThinkingLevel::supportedBy()` answers `[off]`. Both are right: "one level, and it is
off" is a true statement about the model, and "no levels at all" is what `cycleThinkingLevel()` and
the settings list use to decide there is no thinking row to draw. Handing upstream's answer
straight to those callers turned "no row" into "a row with one choice" and broke seven tests, none
of them about thinking.

And asking for a level a model lacks now lands on **the nearest level it has** — up from what was
asked for, then down — where it used to fall to `off`. That reverses a decision this repository had
written down, on the grounds that sending `high` for `xhigh` answers a different question from the
one asked. That reads well for xhigh alone and fails everywhere else: once a map can remove *any*
level, the same rule turns "medium, please" on a model that goes low-or-high into thinking switched
off entirely, which is further from the question than `high` ever was. The Antigravity flash models
are exactly that shape — `off` is null, they always think.

**A hazard found the hard way.** `OpenAiCompletionsTest::send()` rebuilds the `Model` field by
field to swap in the canned server's URL. A new constructor field left off that list does not fail
to compile — it makes the feature look broken in whichever test happens to need it, which is how
half an hour went on a map that was being dropped in the test helper rather than ignored by the
provider. It is the only place in the repository that copies a `Model` that way; if a second one
appears, this is the note that says why it is worth avoiding.

## Antigravity: three names for one model choice

`extensions/pig-antigravity/src/Routing.php` now. The three names (logical → runtime → enum) and the
rule that an unknown model is refused rather than defaulted are unchanged; what changed is where
they live. See "A provider an extension brings" above.

## `defaultProvider` was written from the start and read by nothing

The footer said `(google) gemini-3.8-flash • thinking off` where pi said
`(antigravity) gemini-3.8-flash • medium`, and the provider half was a real bug.

`Settings::setDefaultModel($id, $provider)` stores both. `CodingAgent` read only
`$settings->defaultModel()` — the **bare** id — and a bare id means the direct provider by
`Models::RESOLD`'s rule. So a session last used on `antigravity/gemini-3.8-flash` reopened on
Google's public model of the same name: same id, different model, different bill. `defaultProvider`
had no reader outside the tests that asserted it was written.

**Invisible until a resold id was the default.** `claude-sonnet-4-5` remembered as `anthropic` and
resolved bare gives the same model either way, which is every case anybody had.

Two boundaries the fix needs, both tested:

- **Only the remembered model gets the remembered provider.** `--model gemini-3.8-flash` is left
  exactly as typed. Applying a stored provider to what somebody just typed
  would be the worse bug of the two.
- **A stored provider that has gone falls back rather than failing.** `google-antigravity` is
  precisely that case after the rename above, so any settings file written before it names a
  provider that no longer exists. A tool that will not start because of a line it wrote itself is
  not a trade worth making.

The other half of that footer — `• thinking off` against pi's `• medium` — was not a bug. Measured:

    (google) gemini-3.8-flash       off/minimal/low/medium/high    off stays off
    (antigravity) gemini-3.8-flash  low/medium/high                off clamps to low

Google's public model has no `thinkingLevelMap`, so every level is available and a session with
thinking off shows exactly that. Antigravity's says `off => null` — it cannot be turned off — so
the same session clamps up to `low`. Both halves of the difference came from being on the wrong
model.

## Removing Gemini CLI, and the four rules that nearly went with it

Upstream removed Gemini CLI and Antigravity together in `0.71.0`; pig kept Antigravity and
removed the other. Gone: `Providers\GoogleGeminiCli`, `Utils\Oauth\GeminiCli`, their tests, the
`Api` case, the `Stream` arms and `geminiCli()` options builder, `Oauth\Provider`'s case,
`Models::GEMINI_CLI` with its five rows, and `Auth::googleClient()` — dead once nothing called it.

**The interesting part was the tests.** Nineteen of them failed, and the lazy reading is "tests for
deleted code, delete them". Four covered behaviour that is *still live* in `Oauth\Antigravity` and
had only ever been tested through the flow that was going:

- the token exchange being **form-encoded** rather than JSON;
- the **CSRF state check** — `hash_equals` against the PKCE verifier;
- the email being read, and a provider that refuses to say it **not** being a failure;
- `access_type=offline` plus `prompt=consent`, and the state being the verifier.

Same story in `AuthTest` for the client-credentials reader: `antigravityClient()` implements
env-then-settings, **the environment winning over settings**, an exported-but-empty variable not
counting as set, and an empty setting not counting either — and the last three were only tested on
`googleClient()`. All seven were ported rather than dropped. **Deleting a provider is where
coverage goes quietly**: the tests that fail are obvious, and the ones that were carrying a second
provider's only coverage are not.

Two tests were rewritten rather than moved, because what they asserted no longer exists: the one
comparing Antigravity's client pair against Gemini CLI's now asserts that the reader looks at *its
own* names and refuses rather than falling back, and `GoogleTest`'s Gemini 3 Pro level test is
replaced by the drift note below.

## Gemini 3.x on the public endpoint

The entry this replaces was an open question: `Stream::gemini()` picked the level path with
`str_contains($id, '3-pro') || str_contains($id, '3-flash')`, the generated table had moved on to
`gemini-3.1-pro-preview`, `gemini-3.5-flash` and `gemini-3.8-flash`, and so every Gemini 3.x model but
`gemini-3-flash-preview` fell through to `thinkingBudget: -1` — "think as much as you like", with the
level ignored. It was pinned rather than fixed because fixing it needed a fact nobody here had:
what the endpoint takes for 3.5 and 3.8.

**Measured, one request per model and level, 2026-10-01**, with a `GEMINI_API_KEY` on a machine
that can reach `generativelanguage.googleapis.com`:

| | `LOW` | `MEDIUM` | `HIGH` | `MINIMAL` | `thinkingBudget: 0` |
|---|---|---|---|---|---|
| gemini-3-flash-preview | ok | ok | ok | ok | ok, no thoughts |
| gemini-3.1-flash-lite | ok | — | — | ok | ok, no thoughts |
| gemini-3.5-flash | ok | ok | ok | ok | ok, no thoughts |
| gemini-3.6-flash | ok | — | — | ok | ok, no thoughts |
| **gemini-3.5-flash-lite** | ok | ok | ok | ok | **400** `invalid argument` |
| **gemini-3.7-flash** | ok | — | — | **400** `not supported` | accepted, **thinks anyway** (79) |
| **gemini-3.8-flash** | ok | ok | ok | **400** `not supported` | accepted, **thinks anyway** (93) |
| **gemini-3.1-pro-preview** | ok | ok (111 thoughts) | ok | **400** `not supported` | **400** `only works in thinking mode` |
| **gemini-3.1-pro-preview-customtools** | ok | — | — | **400** | **400** |

Three things came out of it, and the first is worse than the question that was asked:

- **Thinking *off* was a 400 on three models, every turn.** `pig --model google/gemini-3.1-pro-preview`
  with the default level failed before the model said a word — `Budget 0 is invalid. This model only
  works in thinking mode` — and 3.5 Flash Lite the same with a less helpful sentence. 3.7 and 3.8
  Flash *accept* the zero and think anyway, so "off" there was a label on a thing that was on. None
  of this was the drift; it was the `thinkingEnabled: false` arm, which was right for 2.5 and is
  wrong for the models that cannot stop.
- **Every 3.x model takes a level.** The check is now upstream's `usesGoogleThinkingLevel()`
  (`/gemini-3(?:\.\d+)?-(?:pro|flash)/`, the two `-latest` aliases, `/gemma-?4/`), which matches all
  of them. `MEDIUM` is a real level on Pro now — 111 thinking tokens against LOW's 87
  and HIGH's 142 — so upstream's fold of Pro to two levels went with `gemini-3-pro-preview`, the
  model it was measured on.
- **Which levels a model refuses has no pattern in its name.** MINIMAL is refused on 3.7, 3.8 and
  both Pro ids and accepted on 3.5, 3.6, 3.1 Flash Lite and 3.5 Flash Lite; `off` is refused on Pro
  and 3.5 Flash Lite, ignored on 3.7/3.8, honoured on the rest. So it is **data, per row**: the
  Google rows carry a `thinkingLevelMap` — the same machinery the Antigravity rows already use — and
  `ThinkingLevel::clampedFor()` moves `off` and `minimal` up to `low` *before* a request is built.
  The measured refusals were `scripts/generate-models.php` overrides until models.dev listed the
  same efforts; the map is now built from them (upstream's `getGoogleThinkingLevelMap()`), and a
  regeneration keeps it. A row with
  nothing to say keeps its nine cells.

**And the generator's own report was lying about it**, which is worth more than the fix: the first
regeneration printed `antigravity/gemini-3.8-flash: levels {…} → []` for every Antigravity row, as
though the maps had been wiped. The file was untouched. The report reloads the written table in a
fresh process over a tab-separated line, and that line had no column for the map — so "after" read
as empty for every model that has one. It carries the map now, and the second run is `0 added, 0
gone, 0 changed`. *A diff printed by the tool that made the change is a claim like any other*; the
`git diff` is what said the file was fine.

Verified end to end through `bin/pig -p` against the real endpoint: 3.1 Pro with thinking off
answers (clamped to low), 3.8 Flash with `--thinking high` answers, 3.5 Flash with thinking off
answers with no thoughts. Regression tests:
`GoogleTest::testEveryGemini3ModelInTheTableTakesALevelAndNoneABudget`,
and `testAModelThatRefusesALevelSaysSoInItsRowRatherThanAtTheProvider`.

## The Antigravity provider is `GoogleGeminiCli` plus an envelope

`extensions/pig-antigravity/src/AntigravityApi.php` now, implementing `Pig\Ai\Extension\StreamApi`
and still built on `GoogleShared`, which is public for exactly this. The three constants that were
wrong (host, User-Agent, envelope `userAgent`) and the trajectory-id byte cut are as recorded on the
class. See "A provider an extension brings" above.

## The quota wall's call to action is the provider's wording; the session does not rewrite it

**Phenomenon**: long after a quota reset pi showed `Quota reached. Please wait 15h7m17s. Next: switch models or try again after reset.`. The TUI also showed the same failure twice: once in the assistant error component and once more via `sayError()` in `InteractiveMode::onMessageEnd()`.

**Cause**: that sentence is written by pi-antigravity's `friendlyAntigravityError()` inside the provider, not by pi's session; upstream's session does not read the wait time out of the error. pig once parsed `reset after …`/`Resets in …` in `Session\Retry` and rewrote the message in `AgentSession` — upstream has no such layer, and it is deleted.

**Rule**: the quota wall's wording belongs to the provider (the 429 branch of `AntigravityApi::explain()`, word for word from pi-antigravity), and the wording itself guarantees it misses upstream's retryable patterns, so the session does not retry and ends the turn as it is. One assistant error is shown by exactly one transcript component; `sayError()` is only the fallback for a malformed event stream where `$this->streaming === null`.

**Countermeasure**: `AntigravityApiTest::testAQuotaWallIsNotTriedOnTheOtherHostAndIsSaidInPiAntigravitysWords`, `AgentSessionTest::testAProvidersQuotaWallIsNotRetriedAndItsSentenceIsKept`, `InteractiveModeTest::testAProvidersQuotaWallIsShownOnceAsItWasWordedAndNotRetried`.

## Anthropic's `redacted_thinking` block was dropped, so the next turn replayed something the model did not write

**Symptom**: when Anthropic's safety policy redacts thinking it sends `content_block_start` with `{type: "redacted_thinking", data: "<encrypted payload>"}`; pig's message had no such block, nor did the session file, and the next request replayed the turn without it — not the turn the model wrote, breaking multi-turn reasoning continuity.

**Root cause**: the `match` in `Anthropic::onBlockStart()` knew `text` / `thinking` / `tool_use` only; `redacted_thinking` fell to `default => null` and the block vanished, and `ThinkingContent` had no `redacted` field to hold it. Upstream (`anthropic-messages.ts`) stores it as `thinking: "[Reasoning redacted]"`, `thinkingSignature: data`, `redacted: true`, and restores `{type: "redacted_thinking", data}` when replaying to Anthropic; `transform-messages.ts` keeps it only when provider, API and model all match, otherwise the block is dropped whole (not turned into `<thinking>` text).

**Trap rules**:
- An unknown block type in a provider's `match`: check first whether upstream stores it in another shape; never drop by default. Losing something that can be replayed is a protocol error, not a display issue.
- Redacted thinking: `thinking` holds only the placeholder (which is all the TUI / HTML / Markdown export show); the encrypted payload lives only in `thinkingSignature`; `Anthropic::assistantBlocks()` checks `redacted === true` before the empty-text check.
- In `TransformMessages` a redacted block is kept only when provider, API and model all match, and dropped otherwise.
- New optional fields (`ThinkingContent::$redacted`, `AssistantMessage`'s `responseId` / `responseModel` / `endTurn` / `diagnostics`, `Usage`'s `reasoning` / `cacheWrite1h`) all follow `rawStopReason`: constructor parameter last, key omitted from JSON when null, and every field-by-field `new AssistantMessage(` copy (`TransformMessages`, `SessionManager::withContent()`, `AgentSession::normaliseFinalMessage()`) carries them. Tests in `AnthropicTest` and `MessageTest`.

## Token usage on OpenAI-compatible / Gemini: thinking counted twice, cache missed or counted twice

**Symptom**: on an OpenAI-compatible provider a turn with reasoning reported `output` above the bill by the whole `reasoning_tokens`; DeepSeek / Kimi cache hits were all billed as fresh input with `cacheRead` forever 0; Gemini's `input` still included the cached part, so the same tokens were charged once at input price and once at cache price.

**Root cause**: `OpenAiCompletions::usage()` took `completion_tokens + reasoning_tokens` as output (a patch once added for Groq), but `completion_tokens` already includes reasoning, and upstream's `parseChunkUsage()` has no per-provider branch at all; the cache read only `prompt_tokens_details.cached_tokens`, missing DeepSeek's `prompt_cache_hit_tokens`, Kimi's top-level `cached_tokens` and the OpenRouter family's `cache_write_tokens`. `GoogleShared::usage()` did not subtract `cachedContentTokenCount` from `promptTokenCount`.

**Trap rules**:
- Usage fields line by line as upstream: completions is `output = completion_tokens`, `cacheRead = details.cached_tokens ?? prompt_cache_hit_tokens ?? cached_tokens` (the first one present wins, 0 included), `cacheWrite = details.cache_write_tokens`, `input = max(0, prompt - cacheRead - cacheWrite)`, total the sum of the four; Google is `input = prompt - cached`, `output = candidates + thoughts`, total read straight from `totalTokenCount`.
- `reasoning` is a subset of `output` and is never added to any count; a correction for some provider is ported only after its branch is found in upstream.
- Pinned by `OpenAiCompletionsTest` (the cache-field data provider, cache write, reasoning not double-counted) and `GoogleTest::testThinkingTokensAreCountedAsOutputBecauseTheyAreBilledAsOutput`.
- When the chunk has no top-level `usage`, read `choices[0].usage` (where Moonshot puts it); reading the top level alone makes every Moonshot turn 0. Test: `OpenAiCompletionsTest::testUsageOnTheChoiceIsReadWhenTheChunkHasNoneOfItsOwn`.

## Tool call ids of the wrong shape across models made the whole request a 400

**Symptom**: switching models mid-session with `/model` while the history held tool calls got the next request refused: gpt-5 → Claude, `call_…|fc_…` over 64 characters (Anthropic accepts only `^[a-zA-Z0-9_-]+$` up to 64); gpt-5 → Groq and other chat-completions endpoints, the long id with `|` sent as is; Copilot → `openai/gpt-5`, the item id Copilot minted (with `+` `/` `=`) sent back as `id`, which OpenAI requires to start with `fc_`; a different model of the same provider (gpt-5 → gpt-5-mini), the `fc_` id sent back without its paired `rs_` reasoning item, refused by OpenAI.

**Root cause**: upstream's `transformMessages()` takes each provider's own `normalizeToolCallId` callback and rewrites the id (and the matching result) for calls from a different model; pig hard-coded one rename between Copilot's two APIs, Anthropic only substituted characters without truncating when sending, and `OpenAiResponses` had none of upstream's `isDifferentModel` / `fc_` prefix checks.

**Trap rules**:
- Id rules live only in each provider, passed as a closure to `TransformMessages::apply()`; `TransformMessages` names no provider.
- Ids from the same model (provider, API and model all equal) are sent back unchanged, with no cleaning at send time.
- A Responses `id` is sent only when it starts with `fc_` and is not from another model of the same provider and API.
- Tests: `AnthropicTest::testAnotherModelsToolCallIdIsMadeSafeAndItsResultFollows`, `OpenAiCompletionsTest::testAResponsesIdIsRemadeForChatCompletionsWhoeverMintedIt`, `OpenAiResponsesTest::testAnotherProvidersPairKeepsItsCallIdAndGetsAnItemIdOfItsOwn`, `testAnotherModelOfThisProviderSendsNoItemIdAndNeitherDoesOneNotStartingFc`, `GoogleTest::testAnotherModelsIdIsMadeSafeOnlyForAModelThatIsSentIt`.

## Gemini thinking config chosen by `str_contains($id, 'gemini-3')`: a no-thinking request was a 400, and levels did nothing on Gemma 4 / `-latest`

**Symptom**: requests without a thinking level (the `HookContext` completion, an export summary) were a straight 400 on `google/gemini-3.1-pro-preview` — "Budget 0 is invalid. This model only works in thinking mode" — and likewise on 3.5 Flash Lite; on `gemma-4-*`, `gemini-flash-latest` and `gemini-flash-lite-latest`, `--thinking low` had no effect and the model thought as much as it liked.

**Root cause**: `Stream::gemini()` chose level versus budget with `str_contains($id, 'gemini-3')`, so Gemma 4 and the two `-latest` aliases fell to `thinkingBudget: -1`; `Google::thinking()` sent `thinkingBudget: 0` for every "no thinking", and the agent's `ThinkingLevel::clampedFor()` covers only the agent's own requests, so a request without a level never passed through it. Upstream picks the format with `usesGoogleThinkingLevel()` (a regex plus the two aliases plus `/gemma-?4/`) and routes "no thinking" through `getDisabledGoogleThinkingConfig()`: a level model without `off` is sent the lowest level `off` clamps to. Budgets are also looked up by the level `thinkingLevelMap` resolved to, and 2.5 Flash-Lite has a row of its own.

**Trap rules**:
- A Google model's thinking format is decided only by `GoogleShared::usesGoogleThinkingLevel()`, never by an id fragment elsewhere.
- "No thinking" is produced only by `GoogleShared::disabledGoogleThinkingConfig($model)`, never by writing `thinkingBudget: 0` directly.
- Tests: `GoogleTest::testThinkingOffOnALevelModelWithNoOffIsItsLowestLevelAndNotABudgetOfZero`, `testTheLatestAliasesAndGemmaFourTakeALevelToo`, `testABudgetIsReadFromTheLevelTheMapResolvedTo`.

## Reasoning replay on chat completions: thinking duplicated, blocks run together, `reasoning_details` lost

**Symptom**: on endpoints like chutes.ai that send both `reasoning_content` and `reasoning`, every thought appeared twice in the message; several thinking blocks replayed with no separator (`hmmand then`), and blocks with different signatures each wrote their own field; on reasoning models via OpenRouter, `reasoning.text` / `reasoning.summary` in `reasoning_details` were dropped and the next turn's reasoning started from scratch.

**Root cause**: `OpenAiCompletions` appended all three reasoning fields it read, where upstream reads the first non-empty one; replay joined by each block's own signature, where upstream picks the field name from the first block's signature, joins with `"\n"`, and writes only when the signature is one of the three known field names and there are no `reasoning_details`; `reasoning_details` recognized only `reasoning.encrypted`, hung on the tool call's `thoughtSignature`, where upstream merges the whole list into the thinking block's signature and replays `reasoning_details` instead of the raw fields.

**Trap rules**:
- Only the first non-empty reasoning field is read; the replay field name is the first non-empty thinking block's signature, and it must be in `REASONING_FIELDS`.
- `reasoning_details` is stored in the thinking block's signature (written into the first thinking block when the stream ends; one that is never opened is created if there is none); encrypted entries hung on tool calls in old sessions keep being read through upstream's legacy branch.
- Tests: `OpenAiCompletionsTest::testOnlyTheFirstReasoningFieldInADeltaIsRead`, `testReasoningDetailsAreKeptOnTheThinkingBlockAndMergedAsTheyStream`, `testTheFirstThinkingBlocksFieldCarriesEveryThoughtJoinedByANewline`.

## Refusal text lost on the Responses API

**Symptom**: when gpt-5 and other OpenAI Responses models refused, the refusal was missing or partial in the UI and the session; text that was in the finished message item but incomplete in the streamed deltas was cut short too.

**Root cause**: upstream rebuilds the text from the finished item on `response.output_item.done` — `item.content.map(c => c.type === "output_text" ? c.text : c.refusal).join("")` — discarding what the stream accumulated; pig kept only the delta-accumulated text and never read the finished item's `content`.

**Trap rules**:
- The text block is whatever `output_item.done`'s `content` says (`AssistantMessageBuilder::setText()` replaces, never appends), with both `output_text` and `refusal` parts joined in; an item without `content` sets `""` as upstream does, with no fallback to the deltas.
- A test fixture's `output_item.done` carries a real-shaped `content`, or the test exercises a stream pig invented.
- Tests: `OpenAiResponsesTest::testTheFinishedMessageItemIsTheTextAndARefusalIsPartOfIt`, `testAFinishedMessageItemWithNoContentLeavesNoText`.

## A model without image support was told nothing about the images

**Symptom**: sending a screenshot or `read`ing an image to a text-only model (`input` without `image`, such as DeepSeek), the model answered as if there were no attachment, or made things up about an empty tool result.

**Root cause**: upstream's `transformMessages()` first runs `downgradeUnsupportedImages()`, replacing images in user messages and tool results with `(image omitted: model does not support images)` / `(tool image omitted: model does not support images)`, collapsing a run of several into one line; pig only skipped images silently inside each provider by `acceptsImages()`.

**Trap rules**:
- Image downgrading happens only in `TransformMessages::apply()`; the `acceptsImages()` checks in providers stay but are not where downgrading happens.
- The placeholder text and the deduplication follow upstream literally: an image right after a placeholder adds no new line.
- Tests: `MessageTest::testAModelThatCannotSeeIsToldAnImageWasLeftOutAndARunIsOneLine`, `OpenAiCompletionsTest::testAnImageBecomesAPlaceholderForAModelThatCannotSeeOne`, `GoogleTest::testAnImageGoesInlineAndBecomesAPlaceholderForAModelThatCannotSeeOne`.

## DeepSeek replaying an assistant turn without thinking: 400 for the missing `reasoning_content`

**Symptom**: in a session on a DeepSeek thinking model (`reasoning: true` in `models.json`), once the history held an assistant turn without thinking (tool calls only, or a turn from another model), the next request was a 400 saying the assistant message lacks `reasoning_content`.

**Root cause**: upstream's compat has `requiresReasoningContentOnAssistantMessages`, switched on by `detectCompat()` for `provider === "deepseek"` or a URL containing `deepseek.com` (case-insensitive), and on replay it adds `""` to every assistant message without `reasoning_content` for a `model.reasoning` model; pig's `OpenAiCompat` had no such switch and wrote the field only when the thinking block's signature was `reasoning_content`.

**Trap rules**:
- The switch is `OpenAiCompat::$reasoningContentOnAssistantMessages`; `detect($baseUrl, $provider)` takes the provider name; the `compat` in `models.json` uses upstream's spelling `requiresReasoningContentOnAssistantMessages`, and `StreamProxy` carries it too.
- The condition is the model capability `model.reasoning`, not whether this request thinks; a turn that already has `reasoning_content` is not overwritten.
- Tests: `OpenAiCompletionsTest::testDeepSeekGetsAnEmptyReasoningContentOnAReplayedTurnThatHadNoThinking`, `testTheReasoningContentFillerNeedsTheFlagAndAReasoningModel`, `CustomModelsTest::testRequiresReasoningContentOnAssistantMessagesIsReadFromACompatBlock`.

## Responses API thinking text trusted the streamed deltas alone

**Symptom**: on a thinking model over the OpenAI Responses API, the thinking block ended with an extra empty paragraph (the `\n\n` added by `reasoning_summary_part.done`); on a model giving raw reasoning (`reasoning_text`) and no summary, the thinking block was empty.

**Root cause**: upstream rebuilds the thinking text from the finished reasoning item on `response.output_item.done`: the `summary` parts `join("\n\n")`, else the `content` parts, and only when both are empty does it keep what the deltas built; it also treats `response.reasoning_text.delta` as a thinking delta. pig only stored the item in the signature, kept the delta-built text, and did not recognize `reasoning_text.delta`.

**Trap rules**:
- The finished item is the authoritative text; the thinking block, like the text block, is overwritten with `setText()`, in the order `summary || content || streamed`, and an empty array does not count as content.
- The signature still stores the whole item, unchanged by the text rebuild.
- Tests: `OpenAiResponsesTest::testTheFinishedReasoningItemsSummaryIsTheThinkingText`, `testRawReasoningContentIsTheThinkingWhenThereIsNoSummary`, `testAReasoningItemWithNothingToReadKeepsTheStreamedThinking`.

## `OpenAiCompat::detect()` and upstream's `detectCompat()` were not the same table

**Symptom**: a DeepSeek endpoint written as `https://API.DeepSeek.com` in `models.json` ignored the output cap (it was sent `max_completion_tokens`, which it ignores); DeepSeek, z.ai, Moonshot, Together, NVIDIA and others were treated as standard endpoints and received `store`, the `developer` role, `reasoning_effort` or `max_completion_tokens`.

**Root cause**: pig's `detect()` recognized only cerebras/x.ai/mistral/chutes as non-standard, by URL; `deepseek.com` affected only `maxTokensField`, case-sensitively. Upstream's `isDeepSeek` (`baseUrl.toLowerCase()`) decides non-standard, `max_tokens` and the `reasoning_content` filler together, and z.ai, Moonshot, Together, OpenRouter, Cloudflare, NVIDIA, Ant Ling and opencode each have their own provider-name/URL checks.

**Trap rules**:
- `detect($baseUrl, $provider, $modelId)` copies upstream's `detectCompat()` item by item, with both the provider name and the URL passed in; only the DeepSeek URL check is case-insensitive.
- Mistral is not on this API: it is `Providers\Mistral` (upstream's `mistral-conversations`), and `detect()` has no Mistral rule any more.
- Tests: `OpenAiCompletionsTest::testEveryEndpointUpstreamNamesIsDetectedAsUpstreamDetectsIt`, `testDeepSeekIsNonStandardTheWayUpstreamDetectsIt`, `testDeepSeekGetsItUnderTheOlderNameToo`.

## A `compat` block replaced the whole detection result

**Symptom**: a `compat` with a single key (say `requiresThinkingAsText`) on a DeepSeek model in `models.json` made the request carry `store`, the `developer` role and `max_completion_tokens` again, and the `reasoning_content` filler was gone.

**Root cause**: upstream's `getCompat()` is key by key, `model.compat.x ?? detected.x`; pig replaced the detection result wholesale as soon as a `compat` existed, with the unspecified keys falling to the plain defaults. The provider-level `compat` was not read at all (upstream `mergeCompat(providerConfig.compat, definition.compat)`).

**Trap rules**:
- Every `OpenAiCompat` field is nullable, null meaning "unspecified"; providers use only `OpenAiCompat::resolve($model)`, never `$model->compat ?? detect(...)` again.
- `CustomModels::compat()` gives null for missing keys, not defaults; the provider-level `compat` is laid down first and the model's own keys override. `StreamProxy` sends only non-null keys.
- Tests: `OpenAiCompletionsTest::testAnExplicitCompatOverridesDetectionOnlyForTheKeysItSets`, `CustomModelsTest::testACompatBlockIsLaidOverDetectionKeyByKey`, `testAProviderCompatBlockAppliesToItsModelsAndAModelsOwnKeysWin`.

## Optional parameters arrive as null under strict sampling

**Symptom**: the built-in bash/edit/read/write declare `constrainedSampling` (strict: prefer); a strict-capable model (Anthropic, OpenAI, Gemini 3) calling `read` without wanting a `limit` sent `"limit": null`, and the tool refused: `limit: must be number`.

**Root cause**: the strict transform makes optional parameters required-and-nullable; upstream's `validateToolArguments()` first runs `normalizeOptionalNulls()`, which deletes keys that are not required, are null and do not themselves accept null, by the tool's original schema, before validating. pig's `ToolArguments` validated directly.

**Trap rules**:
- Before giving a tool `constrainedSampling`, confirm the null normalization in `ToolArguments::validate()` is still there; it goes by the tool's original schema, not the strict one, and a null for a required parameter still fails.
- Which models support strict follows upstream's generated metadata: models of the `anthropic` provider (`AnthropicCompat::$strictTools`), `openai`'s Responses models, the built-in Completions models except Cerebras (Copilot included); `models.json` models do not by default and can switch it on with `supportsStrictMode` (`supportsStrictTools` for Anthropic).
- Tests: `ToolArgumentsTest`, `AgentLoopTest::testANullForAnOptionalParameterIsTheParameterLeftOut`, `StrictToolSamplingTest`.

## z.ai / DeepSeek were never told whether to think

**Symptom**: z.ai (GLM) kept thinking, and billing for it, with thinking switched off; switching DeepSeek's thinking off did nothing; the thinking switch had no effect on OpenRouter, Qwen, Together and other `models.json` endpoints.

**Root cause**: upstream's compat has `thinkingFormat` (detected: deepseek → `deepseek`, z.ai → `zai`, Together → `together`, Ant Ling → `ant-ling`, OpenRouter → `openrouter`, otherwise `openai`), and `buildParams()` sends `thinking: {type}`, `reasoning: {effort}`, `enable_thinking`, `chat_template_kwargs` and so on by format; pig could only send `reasoning_effort`, and since upstream's detection says z.ai does not support `reasoning_effort`, nothing was sent at all.

**Trap rules**:
- Thinking parameters are written only in `OpenAiCompletions::thinking()`, branching on `thinkingFormat`, in upstream's order and with upstream's operators: `map[e] ?? e`, `Model::thinkingEffort()` + `is_string()`, and "`off` is absent or a string" are three different reads — do not mix them.
- Switching thinking off is also said explicitly per format (`{type: "disabled"}`, `effort: "none"`, …) unless `thinkingLevelMap` sets `off` to null.
- `models.json` keys: `thinkingFormat`, `chatTemplateKwargs`, `chatTemplateArgs` (the provider's and the model's template objects merge key by key).
- Tests: `OpenAiCompletionsTest::testZaiIsToldWhetherToThinkInItsOwnField`, `testDeepSeekIsToldWhetherToThinkAndHowHard`, `testEachThinkingFormatSaysItTheWayUpstreamDoes`, `testTheThinkingFormatIsDetectedAsUpstreamDetectsIt`, `CustomModelsTest::testAThinkingFormatAndItsTemplateValuesAreReadAndMergedKeyByKey`.

## Anthropic adaptive thinking guessed from the model id, and read a property that did not exist

**Symptom**: Opus 4.6, Sonnet 4.6 and Opus 5 were sent `budget_tokens` rather than adaptive when thinking; an `anthropic-messages` model with a `compat` in `models.json` raised a PHP warning on a thinking request (`Undefined property ...::$forceAdaptiveThinking`).

**Root cause**: at runtime upstream looks only at `model.compat?.forceAdaptiveThinking === true`; the id check (`isAnthropicAdaptiveThinkingModel()`) runs only when the model table is generated, writing the flag into the Anthropic API's built-in models. pig checked four id fragments at request time and read that property off an `OpenAiCompat`, which has no such thing. The interleaved-thinking beta should also be sent only on a reasoning model's thinking turn, and not when adaptive.

**Trap rules**:
- Anthropic's model metadata lives in `AnthropicCompat` (`forceAdaptiveThinking`, `strictTools`), written by `Models` at table-build time from upstream's id list; the provider reads the flag and never looks at the id.
- `Model::$compat` is typed per API; a provider does `instanceof` before reading it, and another API's compat counts as absent.
- A proxied new Claude in `models.json` that should be adaptive says `"forceAdaptiveThinking": true`, as upstream.
- Tests: `AnthropicTest::testAModelWhoseCompatSaysAdaptiveThinksAdaptively`, `testWithoutTheFlagEvenAnAdaptiveIdGetsABudget`, `testAnAnthropicModelCarryingACompatBlockDoesNotWarn`, `testATurnThatDoesNotThinkAsksForNoInterleavedThinking`, `ModelsTest::testTheAnthropicModelsThatThinkAdaptivelySaySo`.

## Anthropic sent nothing when thinking was off, and omitted the thinking summary

**Symptom**: with the thinking level off, adaptive Claudes (Opus 4.6+, Sonnet 4.6+, …) kept thinking and billing for it; on Opus 4.7 and later the thinking block was empty when thinking was on.

**Root cause**: upstream's `buildParams()` sends `thinking: {type: "disabled"}` when `thinkingEnabled === false` and `thinkingLevelMap.off !== null`, and both thinking branches (adaptive / budget) carry `display: options.thinkingDisplay ?? "summarized"`; pig sent no `thinking` when off and no `display` when on (and the API default on the new models is omitted).

**Trap rules**:
- `AnthropicOptions::$thinkingEnabled` is three-state: true on, false explicitly off, null nothing said; only false sends disabled.
- Sending disabled depends on `off: null` metadata: Fable 5, the managed-effort Claudes and the 5.5 series are marked in `Models::thinkingLevelMap()`; a new Claude is added the way upstream's generator does.
- Tests: `AnthropicTest::testThinkingSwitchedOffIsSaidRatherThanLeftToTheApi`, `testNothingIsSaidWhereOffIsNotALevelOrNothingWasAsked`, `testAThinkingTurnAsksForSummarizedThinkingUnlessTheCallerSaysOtherwise`, `ModelsTest::testTheClaudeModelsThatCannotStopThinkingSaySo`, `StreamTest::testReasoningBecomesAThinkingBudget`.

## OpenRouter / Vercel routing preferences in models.json were dropped

**Symptom**: `openRouterRouting` (`order`, `only`, `allow_fallbacks`, …) or `vercelGatewayRouting` written in pi's `models.json` was read by pig but absent from the request, leaving routing to the gateway.

**Root cause**: upstream's compat has both keys; `buildParams()` reads the model's own compat and sends `provider` and `providerOptions.gateway`, and `mergeCompat()` merges them key by key along with the template parameters; pig's `OpenAiCompat` had neither field.

**Trap rules**:
- Read `$model->compat` (what the model itself says), not the result of `OpenAiCompat::resolve()`; `{}` counts as said, and is sent as `{}`, not `[]`.
- A new object-valued compat key changes `CustomModels::OBJECT_KEYS` (shared by merging and object preservation) and `StreamProxy`'s encoding together.
- Tests: `OpenAiCompletionsTest::testOpenRouterRoutingIsSentAsTheProviderField`, `testVercelGatewayRoutingIsSentAsTheGatewayOptions`, `CustomModelsTest::testRoutingObjectsAreReadAndMergedFromProviderToModelKeyByKey`, `StreamProxyTest::testRoutingPreferencesTravelUnderUpstreamsKeyNames`.

## Copilot's Claude went over chat completions

**Symptom**: `github-copilot/claude-*` tools went out with OpenAI's `strict` field, and thinking and caching were not done Anthropic's way (no adaptive, no `cache_control`).

**Root cause**: upstream's generator routes Copilot's Claude to `anthropic-messages` by `/^claude-(haiku|sonnet|opus|fable)-[45]([.\-]|$)/` (token as bearer, Copilot's static and dynamic headers, `forceAdaptiveThinking` from the id list, no `supportsStrictTools`); pig's `copilotApi()` only distinguished Responses from completions.

**Trap rules**:
- Copilot's API is decided by `copilotApi()` in `scripts/generate-models.php`; a rule change updates the generator and the table in `Models.php` together.
- `Anthropic` for `github-copilot`: `authorization: Bearer <token>`, no `x-api-key`, not treated as a Claude Code subscription; the address follows the token's `proxy-ep` (`GithubCopilot::baseUrl()`); `Copilot::headers()` come after the model headers.
- Tests: `AnthropicTest::testACopilotClaudeAuthenticatesWithItsTokenAsABearerAndSendsCopilotsHeaders`, `testACopilotTokenDecidesWhereTheMessagesGo`, `ModelsTest::testCopilotSpeaksThreeApisAndTheIdDecidesWhich`, `testCopilotsClaudeModelsCarryAnthropicsCompatTheWayUpstreamsGeneratorWritesIt`, `GenerateModelsTest::testCopilotsApiIsDecidedByTheIdBecauseTheCatalogueDoesNotSay`.

## Responses API tool-result images became a user message

**Symptom**: when an OpenAI Responses model (gpt-5, …) looked at a screenshot-type tool result, the image arrived as a separate user message ("Attached image(s) from tool result:") and the model took it for something the user said.

**Root cause**: upstream's `convertToolResultOutput()` puts images into the `function_call_output.output` content list (`input_text` + `input_image`, `detail: "auto"`); pig wrote `(see attached image)` into the output and started a user message for the image.

**Trap rules**:
- Only chat completions needs a separate user message for images (its tool message cannot hold one); Responses puts them in the output.
- Tests: `OpenAiResponsesTest::testToolResultImagesGoInsideTheFunctionCallOutput`, `testAnImageOnlyResultHasNoTextPartAndATextOnlyModelStillGetsAString`.

## The model wrote numbers as strings and the tool call was refused

**Symptom**: the model sent `"limit": "10"`, `"recursive": "true"` and the like; the tool refused (`must be number` / `must be boolean`), costing a round trip.

**Root cause**: upstream's `validateToolArguments()` converts by schema after `normalizeOptionalNulls()` and before validating (`Value.Convert`, with plain JSON schemas going through `coerceWithJsonSchema()`); pig did only the null normalization.

**Trap rules**:
- Fixed order: null normalization, then conversion, then validation; the error message prints the model's original arguments.
- Plain JSON schemas (extensions, MCP) copy `coercePrimitiveByType()` line by line: a null for a required parameter becomes that type's zero value (`""`, 0, false). Built-in tools: see the `Value.Convert` entry below.
- Tests: the coercion section of `ToolArgumentsTest`.

## Copilot's gpt-6 / grok / mai models were sent to chat completions

**Symptom**: requests for `github-copilot/gpt-6-*`, `grok-*`, `mai-*` went to `/chat/completions`, where Copilot offers these models only on `/responses`.

**Root cause**: upstream's generator `needsResponsesApi` is the prefixes `gpt-`, `grok-`, `oswe`, `mai-` (no exceptions); pig's `copilotApi()` recognized only `gpt-5` and `oswe`.

**Trap rules**:
- A change to `copilotApi()` in `scripts/generate-models.php` changes the `COPILOT_MODELS` row in `Models.php` with it.
- Tests: `ModelsTest::testCopilotSpeaksThreeApisAndTheIdDecidesWhich`, `GenerateModelsTest::testCopilotsApiIsDecidedByTheIdBecauseTheCatalogueDoesNotSay`.

## Anthropic: Opus 4.7+ refused a temperature, and thinking turns carried one too

**Symptom**: with a temperature set, requests to Opus 4.7/4.8/5 and Sonnet/Haiku 5.5 were refused by the API; thinking turns also went out with a temperature.

**Root cause**: upstream sends temperature only when `!thinkingEnabled && !supportsMidConvoEffort && compat.supportsTemperature`, and the generator writes `supportsTemperature: false` for these models by `isAnthropicTemperatureUnsupportedModel()`; pig sent it whenever it had one.

**Trap rules**:
- A new Claude's metadata goes through `AnthropicCompat::forBuiltIn()` (id rules copied from upstream's generator); the provider only reads the flag.
- Test: `AnthropicTest::testTemperatureIsLeftOutWhileThinkingAndForAModelThatRefusesIt`.

## Anthropic: tool streaming uses eager_input_streaming, and the beta header is computed as upstream does

**Symptom**: every request carried the `fine-grained-tool-streaming` beta; a model's own `anthropic-beta` header and pig's went out side by side.

**Root cause**: upstream gives every tool `eager_input_streaming: true` by default and sends the fine-grained beta only when the model does not support it (`supportsEagerToolInputStreaming: false`, Copilot's three Claudes); the model header's `anthropic-beta` is the whole list. All three `createClient()` branches also send `anthropic-dangerous-direct-browser-access: true`.

**Trap rules**:
- The beta list is computed only in `Anthropic::betaFeatures()`, and any `anthropic-beta` in other casing is removed before sending.
- Tests: `AnthropicTest::testToolsAskForEagerInputStreamingInsteadOfTheFineGrainedBeta`, `testAModelWithoutEagerStreamingGetsTheFineGrainedBetaForItsTools`, `testAModelsOwnAnthropicBetaHeaderIsTheWholeList`.

## Claude's xhigh could not be selected

**Symptom**: Claudes that support xhigh (Opus 4.7+, Sonnet 5, Fable 5, …) had no xhigh among their thinking levels.

**Root cause**: upstream's generator writes a full `thinkingLevelMap` for every Claude (`max`, `xhigh`, the whole 5.5 table, Copilot's `minimal: "low"`), and `getSupportedThinkingLevels()` offers xhigh only when the map has it; pig had copied only `off: null`.

**Trap rules**:
- `Models::thinkingLevelMap()` is written in upstream's merge order (5.5 overrides → the managed-effort `off` → `applyThinkingLevelMetadata()` → Copilot overrides), and every built-in table goes through it. A `max` entry is offered as `ThinkingLevel::Max`.
- Test: `ModelsTest::testEveryClaudeCarriesUpstreamsWholeThinkingLevelMap`.

## The request shape for managed-effort models (Opus 5 / Fable 5.1, …) was wrong

**Symptom**: after changing the thinking effort mid-conversation on `anthropic/claude-opus-5`, `claude-opus-5-5`, `claude-sonnet-5-5`, `claude-fable-5-1`, the old turns' thinking blocks no longer matched the new effort and the request could 400 indefinitely.

**Root cause**: for `supportsMidConvoEffort` models upstream always sends adaptive + `block_binding: {prefix_mismatch_behavior: "drop_block"}`, top-level effort `high` and the two managed-effort betas, inserts a system message holding only `output_config` before every old turn of this provider that recorded a `providerThinkingLevel`, and the current effort at the end; pig had no such branch and did not record `providerThinkingLevel`.

**Trap rules**:
- `AssistantMessage::$providerThinkingLevel` is saved with the session (`MessageJson`); every place that copies an `AssistantMessage` carries it.
- Tests: `AnthropicTest::testAManagedEffortModelThinksAdaptivelyAndSaysItsEffortInSystemMessages`, `testAManagedEffortTurnRecordsTheEffortItWasAskedFor`, `MessageTest::testEveryNewOptionalFieldSurvivesTheTripToJsonAndBack`.

## Empty objects inside models.json compat object values were sent as `[]`

**Symptom**: `"openRouterRouting": {"max_price": {}}`, `"chatTemplateKwargs": {"options": {}}` and the like became `[]` in the request (including the model `StreamProxy` sends to a gateway).

**Root cause**: `json_decode(..., true)` cannot tell `{}` from `[]`; upstream forwards the file's objects as they are.

**Trap rules**:
- `CustomModels::load()` rebuilds the `OBJECT_KEYS` (the two routing keys and `chatTemplateKwargs` / `chatTemplateArgs`) with a second, object-preserving decode (nested empty objects stay `stdClass`); the top-level value is still an array.
- `OpenAiCompletions::chatTemplateValues()` treats a `stdClass` value as an object (no `$var` → take the effort), matching upstream's `typeof value === "object"`; it must not be sent as a plain value.
- Tests: `CustomModelsTest::testANestedEmptyObjectInARoutingValueStaysAnObjectOnTheWire`, `testANestedEmptyObjectInTheTemplateValuesStaysAnObjectToo`, and `chat-template with an empty object` in `OpenAiCompletionsTest`.

## Anthropic's `max_tokens` was a third of the model's ceiling, and the thinking budget exceeded the ceiling

**Symptom**: without `maxTokens`, an Anthropic request's `max_tokens` was `maxTokens / 3`, and `Stream::simple()` capped everything at 32,000 on top; with `maxTokens: 700` and medium thinking, the request went out as `max_tokens: 700` with a `budget_tokens` of 8,192, which Anthropic refused. The `minimal` thinking level was rewritten to `low` at the agent layer, so the 1,024 budget was never used.

**Root cause**: upstream's `buildBaseOptions()` uses `clampMaxTokensToContext(model, context, options.maxTokens ?? model.maxTokens)`, and Anthropic's `streamSimple()` then adds the budget to the ceiling with `adjustMaxTokensForThinking()` (up to the model's ceiling), leaving 1,024 for the answer; inside the provider it is `options.maxTokens ?? model.maxTokens`. Upstream's agent passes `minimal` through unchanged.

**Trap rules**:
- The ceiling in `Stream::translate()` is always `clampMaxTokensToContext()` (`Utils\Estimate::contextTokens()`, 3.5 characters per token, length in UTF-16); no cap of pig's own on top.
- The budget table is upstream's `DEFAULT_THINKING_BUDGETS` (minimal 1,024 / low 2,048 / medium 8,192 / high 16,384), and `ThinkingLevel::toReasoning()` passes each level by its own name.
- Tests: `StreamTest::testMaxTokensDefaultsToTheModelsOwnCeiling`, `testTheCeilingIsCutToTheRoomTheConversationLeaves`, `testABudgetThinkingTurnRaisesTheCeilingByItsBudget`, `AgentTest::testTheThinkingLevelReachesTheProviderAsReasoning`, `EstimateTest`.

## Anthropic's refusal, `sensitive` and new stop reasons were treated as a normal end

**Symptom**: a turn with `stop_reason: refusal` ended as `done` with no explanation; `sensitive` and newly added API stop reasons were treated as `stop`, and the agent carried on as if the blocked turn had completed.

**Root cause**: upstream's `mapStopReason()`: `refusal` → error, with `stop_details.explanation` or "The model refused to complete the request" as the message; `sensitive` → "Provider stopped with: sensitive"; an unknown value throws `Unhandled stop reason: <reason>`; and when the stream ends with stopReason error it throws, ending with an error event.

**Trap rules**:
- `Anthropic::stopReason()` has no `default` falling back to `stop`; the error message is recorded on the builder first (`setErrorMessage()`) and thrown after the stream ends.
- Tests: `AnthropicTest::testARefusalEndsAsAnErrorThatCarriesItsExplanation`, `testSensitiveIsAnErrorAndAnUnknownReasonIsNotASilentStop`.

## Anthropic's cache breakpoints skipped the tools, and `cacheRetention` did nothing

**Symptom**: tool definitions were outside the cached prefix; `cacheRetention: long` / `PIG_CACHE_RETENTION=long` gave no one-hour cache, and `none` still marked `cache_control`; endpoints that need session affinity headers (OpenRouter, …) did not get them.

**Root cause**: upstream's `getCacheControl()` builds `{type: "ephemeral", ttl?: "1h"}` by retention and marks the system prompt, the last tool and the last user block; `createClient()` sends `x-session-affinity` / `x-session-id` when caching is on and the compat asks for it. pig hard-coded the five-minute marker, had none on tools, and had no session id.

**Trap rules**:
- Breakpoints all go through `Anthropic::cacheControl()`; models with `supportsCacheControlOnTools: false` get none on tools; 1h needs no beta.
- The session id flows from `AgentSession` (the session file's id) → `Agent::$sessionId` → `SimpleStreamOptions::$sessionId`, and Responses' `prompt_cache_key` uses it too.
- Tests: `AnthropicTest::testTheLastToolCarriesTheCacheBreakpoint`, `testLongRetentionIsAnHourOnEveryBreakpointAndNoneMarksNothing`, `testTheSessionGoesOutAsAnAffinityHeaderWhereTheCompatAsksForIt`, `AgentSessionTest::testTheAgentCarriesTheSessionFilesIdAsItsSessionId`.

## Responses API switched thinking off with `# Juice: 0`, and every `incomplete` was `length`

**Symptom**: switching thinking off on gpt-5.x sent an extra `# Juice: 0 !important` developer message while the model kept reasoning and billing; an answer cut by content filtering was shown as "cut for being too long" and the agent carried on; `maxTokens` under 16 was an API 400; a half tool call with no `output_item.done` was handed to the agent to run.

**Root cause**: upstream switches thinking off with `reasoning: {effort: map.off ?? "none"}` (not sent for Copilot or `off: null` models), effort being `thinkingLevelMap[level] ?? level`; `mapStopReason()` maps only `max_output_tokens` to length, any other reason is a `Response incomplete: <reason>` error; `max_output_tokens` is at least 16; a stream ending in toolUse with an unfinished call throws.

**Trap rules**:
- The `thinkingLevelMap` of OpenAI / Copilot GPT models is written by `Models::thinkingLevelMap()` as upstream's generator does; switching off and the effort both read that table only, never the id.
- Tests: `OpenAiResponsesTest::testThinkingOffIsTheMapsOffEffortAndNoLongerAJuiceMessage`, `testAnAnswerCutOffForAnyOtherReasonIsAnErrorThatSaysWhy`, `testMaxOutputTokensIsNeverBelowSixteen`, `testAToolCallWhoseItemNeverFinishedIsRefused`, `ModelsTest::testOpenAiAndCopilotGptModelsCarryUpstreamsThinkingLevelMaps`.

## Copilot's GPT-6 could not select xhigh

**Symptom**: `github-copilot/gpt-6-sol` and the like had no xhigh among their thinking levels; `openai/gpt-5` offered an off it cannot switch to.

**Root cause**: pig's OpenAI / Copilot GPT rows had no `thinkingLevelMap`, and xhigh came from an old list of three ids; upstream's generator writes full tables for these rows (`off`, Copilot `minimal: "low"`, `xhigh` from gpt-5.2, `max` for gpt-5.6 / gpt-6, the whole GPT-6 table).

**Trap rules**:
- xhigh is decided by the table alone (`Model::supportsXhigh()`), with no id list added back; a `models.json` model writes `"xhigh": "xhigh"` itself.
- Tests: `ThinkingLevelTest::testCopilotsGptSixOffersXhighAndClampsByItsMap`, `ModelTest::testWithNoMapXhighIsNotOfferedWhateverTheId`.

## The context and model StreamProxy sent to a gateway were missing fields

**Symptom**: a gateway written to upstream's spec received no system prompt and no tools (pig sent flat `systemPrompt` / `tools` fields); the tools' `constrainedSampling`, the model's `thinkingLevelMap` and price tiers were lost, and `cacheRetention` / `sessionId` / `metadata` were not sent.

**Root cause**: upstream's `streamProxy()` sends a `TranscriptContext` (the first `system` message carrying the prompt and `toolsAdded`), the whole model object, and every field of `buildProxyRequestOptions()` (undefined ones omitted).

**Trap rules**:
- A change to a `Model` / `Tool` / `StreamOptions` field updates `StreamProxy::encodeModel()` / `encodeContext()` / `request()` with it.
- Tests: `StreamProxyTest::testTheContextGoesOverTheWireAsTheSessionFileWritesIt`, `testAToolsConstrainedSamplingTravelsWithIt`, `testTheThinkingLevelMapAndPriceTiersGoWithTheModel`, `testTheCacheAndSessionOptionsTravelAndUnsetOnesAreLeftOut`.

## OpenAI gpt-5.4/5.5/5.6/6 had a 1.05M window, and long context was billed at the base price

**Symptom**: conversations on `openai/gpt-5.5` and the like grew past 270k tokens without compacting, after which every turn was billed at twice the input price while `/session` and the footer reported the base price — under by half; `gpt-5-pro`'s output ceiling was 272,000.

**Root cause**: upstream's generator has temporary overrides: the window of `OPENAI_SHORT_CONTEXT_CAPPED_MODEL_IDS` is 272,000 (output 128,000), `OPENAI_LONG_CONTEXT_PRICING_MODEL_IDS` get a `withOpenAiLongContextPricing()` tier (input and cache 2× past 272k, output 1.5×, base from `OPENAI_STANDARD_COSTS`), and `gpt-5-pro`'s output becomes 128,000; pig's generator had none of it.

**Trap rules**:
- These are generator rules (`openAiTemporaryOverrides()`), applied on every regeneration; do not hand-edit the window and tiers of these rows in the table.
- `openai` provider only; the same models resold through Copilot are unaffected. pig has no `modelOverrides`, so the full window is only available by declaring it in `models.json`.
- Tests: `ModelsTest::testOpenAisLongContextModelsStopAt272kAndPriceWhatIsPastIt`, `GenerateModelsTest::testOpenAisLongContextModelsAreCappedAndPricedAsUpstreamsGeneratorWritesThem`, `CompactionTest::testOpenAisLongContextModelsCompactBeforeTheLongContextPrice`.

## A stream that broke midway was taken for a normal end

**Symptom**: when an Anthropic or Responses connection dropped mid-text, the turn ended `done`/`stop`, half a sentence passed as the whole answer with no usage, and the agent carried on; a mid-stream Anthropic `event: error` (overloaded, say) was ignored.

**Root cause**: upstream's output starts at `stopReason: "pending"`, and a stream still pending at the end throws `… stream ended without a stop reason`; Anthropic throws `Anthropic stream ended before message_stop` when it saw `message_start` without `message_stop`, and `event: error` throws with its data as the message; Responses throws `OpenAI Responses stream ended before a terminal response event` without `response.completed` / `.incomplete` / `.failed`. pig's builder defaulted to `stop`.

**Trap rules**:
- A new provider that starts from pending as upstream does calls `setStopReason(StopReason::Pending)` right after building the builder and checks at the end; `throwIfAborted()` comes before those checks (upstream's abort throws inside the loop).
- Test fixtures end with `message_stop` / a terminal event like a real stream, or they now fail.
- Tests: `AnthropicTest::testAStreamThatEndsWithoutAStopReasonIsAnErrorNotAFinishedAnswer`, `testAStreamThatStartedAndNeverStoppedIsAnErrorEvenWithAStopReason`, `testAnErrorEventMidStreamIsTheTurnsErrorWordForWord`, `OpenAiResponsesTest::testABodyThatEndsWithNoTerminalEventIsAnErrorNotAnAnswer`.

## Responses error text differed from upstream, and the ChatGPT quota error gave no link

**Symptom**: `response.failed` was shown as "The response failed: …" without the error code; an SSE `error` event as pig's own `Error <code>: …`; and a spent Sign in with ChatGPT quota came with no pointer to the usage page.

**Root cause**: upstream reads the stream through the `openai` SDK (7.19.0): an `event: error`, or an event whose data carries a truthy `error`, becomes an `APIError` whose message is `error.message` (or the JSON when there is none); only a flat error with no `event:` line goes through `Error Code ${code}: ${message}`; `response.failed` is `${code || "unknown"}: ${message || "no message"}` / `incomplete: <reason>` / `Unknown error (no error details in response)`; an error containing `subscription_sharing_usage_limit_exceeded` gets `\nCheck your ChatGPT usage: https://chatgpt.com/settings/usage` appended.

**Trap rules**:
- Events go through `OpenAiResponses::sdkEvent()` first; `dispatch()` does not parse error shapes on its own.
- The ChatGPT hint also checks a refused request's raw body: `explain()` keeps only `error.message`, while upstream's formatted message carries the code.
- Tests: `OpenAiResponsesTest::testAnErrorEventIsTheSdksMessageWithoutTheCode`, `testAFlatErrorWithNoEventNameIsErrorCodeAndMessage`, `testAFailedResponseSaysCodeAndMessageAsUpstreamWritesThem`, `testASignInWithChatGptUsageLimitPointsAtTheUsagePage`.

## Responses dropped the encrypted reasoning Azure gives only in `response.completed`, and arguments follow `.done`

**Symptom**: on endpoints like Azure that give `encrypted_content` only in the terminal event, the next turn replayed reasoning items without their encrypted content and the model reasoned from scratch; on endpoints sending `function_call_arguments.done` without deltas, tool arguments were completed only by `output_item.done`, so a UI listening to deltas saw partial arguments; endpoints whose compat says `supportsDeveloperRole: false` still received the `developer` role.

**Root cause**: upstream's `backfillReasoningSignatures()` fills reasoning items from `response.completed.response.output`; `response.function_call_arguments.done` replaces with the whole arguments and emits the missing tail delta; `instructionRole` checks `compat.supportsDeveloperRole !== false`.

**Trap rules**:
- Reasoning blocks are recorded by item id (`$reasoningById`); the backfill fills only those without `encrypted_content`.
- Tests: `OpenAiResponsesTest::testEncryptedReasoningOnlyTheTerminalResponseCarriesIsBackfilled`, `testTheFinishedArgumentsReplaceTheDeltasAndSendWhatTheyMissed`, `testTheSystemPromptIsASystemTurnWhereTheCompatSaysThereIsNoDeveloperRole`.

## chat completions sent no prompt cache key, session affinity headers or Anthropic cache marks

**Symptom**: through OpenAI's own chat completions, OpenRouter's Claude and the like, every turn of a long conversation was billed at full input price; `cacheRetention: long` did nothing; the thinking level was not clamped to the model's map (Groq Qwen 3.6 has only `high`, and asking for `low` sent `low`).

**Root cause**: upstream's `buildParams()` sends `prompt_cache_key` / `prompt_cache_retention: "24h"` for addresses containing `api.openai.com` (unless `none`), or for `long` where long retention is supported; `createClient()` sends the session headers under `sendSessionAffinityHeaders` (detected: OpenRouter); `cacheControlFormat: "anthropic"` (detected: OpenRouter's `anthropic/…`) marks `cache_control` on the system prompt, the last tool and the last conversation text; `streamSimple()` uses `clampThinkingLevel()`. pig had none of it, and `Stream`'s completions branch clamped xhigh only.

**Trap rules**:
- The four keys are detected in `OpenAiCompat::detect()` and overridden key by key in `resolve()`; Responses still reads the model's own compat plus runtime defaults.
- Tests: `OpenAiCompletionsTest::testOpenAisOwnEndpointGetsThePromptCacheKeyUnlessCachingIsOff`, `testTheSessionGoesOutAsHeadersOnlyWhereTheCompatSaysSo`, `testAnAnthropicModelThroughOpenRouterGetsCacheControlMarks`, `testTheCachingAndSessionKeysAreDetectedAsUpstreamDetectsThem`, `StreamTest::testTheCompletionsApiGetsTheLevelClampedToTheModelsMapAsTheResponsesOneDoes`.

## StreamProxy dropped the gateway's `providerThinkingLevel` and its final tool call

**Symptom**: Opus 5 and similar turns through a gateway did not record `providerThinkingLevel`, so the next turn's effort hint was wrong; the corrected call (id, arguments, namespace) the gateway gives in `toolcall_end` was ignored in favor of the one built from deltas; a gateway stream cut short reported pig's own message.

**Root cause**: upstream's `processProxyEvent()` copies `providerThinkingLevel` on `done` / `error`, does `Object.assign(content, proxyEvent.toolCall)` on `toolcall_end`, returns undefined (does not throw) when the block is not a tool call, and reports `Connection closed by proxy server before the response completed` when there is no terminal event.

**Trap rules**:
- Tests: `StreamProxyTest::testTheProvidersThinkingLevelComesBackOnDoneAndOnError`, `testTheServersFinishedToolCallReplacesWhatTheDeltasBuilt`, `testAToolCallEndForABlockThatIsNoToolCallIsIgnoredNotFatal`, `testAStreamThatEndsWithoutDoneIsAFailureAndNotASuccess`.

## A mistyped price tier in models.json was silently billed at 0

**Symptom**: a `cost.tiers` entry missing `output` or another field billed that part of a long prompt at 0; a tier with `inputTokensAbove` written as a string was quietly dropped; `inputLimits` / `promptCache` were ignored even when written.

**Root cause**: upstream validates `ModelCostTierSchema` with TypeBox (five required fields, all numbers), and an error fails the whole file with `Invalid models.json schema:` plus `<path>: <message>`; `inputLimits` / `promptCache` are validated by their own schemas and passed through as they are.

**Trap rules**:
- Validation goes through `CustomModels::schemaErrors()`, with messages matching TypeBox 1.3.27 (`must be number`, `must have required properties …`, `must be >= 1`); pig refuses only that model, not the whole file.
- Schema validation reads the object-preserving decode (`json_decode($raw, false)`), telling `{}` from `[]` as upstream does.
- Tests: `CustomModelsTest::testACostTierIsValidatedWithUpstreamsSchemaMessages`, `testInputLimitsAndPromptCacheAreReadAndCheckedAsUpstreamsSchemaChecksThem`.

## Copilot billed at $0, and extended windows patched one id at a time

**Symptom**: a session through GitHub Copilot always cost $0; newly added 1M-window Copilot models still compacted at models.dev's small window; the Copilot models upstream adds by hand (Claude Opus 5.5, GPT-6 Sol/Luna) did not exist in pig.

**Root cause**: upstream's `getModelsDevCost()` writes models.dev's list prices for Copilot too; every id in `GITHUB_COPILOT_EXTENDED_CONTEXT_MODELS` gets `contextWindow: 1_000_000`; `missingCopilotModels` adds rows models.dev does not have yet. pig's generator wrote Copilot prices as 0 and fixed windows one OVERRIDES row at a time.

**Trap rules**:
- Extended windows change only `GITHUB_COPILOT_EXTENDED_CONTEXT_MODELS`, with no more per-id fix rows; hand-added models go in OVERRIDES' add rows and are removed once models.dev has them.
- Tests: `GenerateModelsTest::testCopilotRowsCarryModelsDevsListPricesAsUpstreamsGeneratorWritesThem`, `testCopilotsExtendedWindowIsUpstreamsRuleOverEveryListedId`, `testUpstreamsMissingCopilotModelsAreAddedByHandUntilTheCatalogueHasThem`, `ModelsTest::testACopilotConversationIsPricedAtModelsDevsListPricesAsUpstreamPricesIt`, `testCopilotsExtendedWindowsAreUpstreamsOneMillionForEveryListedId`.

## Mistral went over chat completions, with tool ids and thinking parameters pig guessed itself

**Symptom**: Mistral models sent requests through `openai-completions`, tool ids were rewritten by pig to 9 characters, reasoning models received no `reasoning_effort` / `prompt_mode`; `detect()` switched on a pile of special cases at the sight of `mistral.ai`, catching custom Mistral-compatible endpoints too.

**Root cause**: upstream's generator writes the mistral rows as `api: "mistral-conversations"`, `baseUrl: "https://api.mistral.ai"`, and `api/mistral-conversations.ts` sends Mistral's own shape (the 9-character id only there; `reasoning_effort` when the effort has a map entry, otherwise `prompt_mode: "reasoning"`); completions' `detectCompat()` has no Mistral branch at all.

**Trap rules**:
- Mistral's special handling lives only in `Providers/Mistral.php`; `OpenAiCompat` does not recognize the host `mistral.ai`, and `requiresMistralToolIds` is gone.
- Tests: all of `MistralTest`, `ModelsTest::testMistralsModelsSpeakMistralsOwnApiAsUpstreamsGeneratorRoutesThem`, `OpenAiCompletionsTest::testMistralsHostIsNotOneOfThisApisQuirksAnyMore`, `testAToolIdGoesOutAsItIsWhateverTheHost`.

## The Google / Gemma thinking level maps were not upstream's

**Symptom**: Gemma 4 and other models with only two `thinking_level` values received the `low` / `medium` pig clamped to and were refused with 400; a Gemini `-latest` alias took its price and map from the wrong source model; `off` was sent to models that cannot stop thinking.

**Root cause**: upstream's `getGoogleThinkingLevelMap()` builds the map from models.dev's `reasoning_options` and writes it into the row, with a `-latest` alias taking its target model's; at runtime `streamSimpleGoogle` clamps with `clampThinkingLevel()` by the row's map. pig's generator built no map, and `Stream::gemini()` clamped to high only.

**Trap rules**:
- The row's `thinkingLevelMap` is written by the generator, with the levels the endpoint was measured to refuse merged in; `Stream::gemini()` uses only `$model->clampThinkingLevel()`.
- Tests: `GenerateModelsTest::testGoogleRowsTakeUpstreamsGoogleThinkingLevelMap`, `testALevelTheEndpointRefusesIsMergedIntoTheRowsThinkingLevelMap`.

## Z.ai read the wrong models.dev entry

**Symptom**: Z.ai coding-plan models had missing rows, wrong windows and thinking switches, and tool calls did not stream.

**Root cause**: upstream's `processZaiModels()` reads the `zai-coding-plan` entry with prices from the `zai` entry; GLM-5.2 cannot `off`; everything outside `ZAI_TOOL_STREAM_UNSUPPORTED_MODELS` gets `zaiToolStream: true`, `thinkingFormat: "zai"`. pig read `zai`.

**Trap rules**:
- Generator `SOURCE['zai'] = 'zai-coding-plan'`; the Z.ai rows in `Models.php` switch to the new source on the next regeneration (models.dev is unreachable at the moment).
- Tests: `GenerateModelsTest::testZaiIsReadFromTheCodingPlansEntryAndPricedFromZaisOwn`, `ModelsTest::testZaisModelsSayTheirThinkingFormatAndStreamTheirToolCalls`, `OpenAiCompletionsTest::testZaiIsAskedToStreamToolCallsWhereItsCompatSaysSo`.

## Responses: concurrent items crossed streams, items arriving only at `done` were lost, empty deltas swallowed

**Symptom**: two items streaming interleaved in one response (reasoning and a message, two tool calls) had their deltas land in the wrong block; an endpoint sending `output_item.done` without `added` lost that output entirely; an empty-string delta emitted no event; a refused request's message was not upstream's `OpenAI API error (N): {...}`.

**Root cause**: upstream's `processResponsesStream()` keeps slots by `output_index` and `getOrCreateSlot()`s on `done`; a delta is checked only for `typeof === "string"`; errors go through `formatProviderError(normalizeProviderError(err), "OpenAI API error")`. pig had a single "current block".

**Trap rules**:
- Every event finds its slot by `output_index` (default key `'undefined'`), never a "current block"; `done` does not re-read id / name.
- Error text goes through `Utils\ErrorBody`, never assembled by hand.
- Tests: `OpenAiResponsesTest::testTwoItemsStreamingAtOnceEachGetTheirOwnDeltas`, `testAnItemThatOnlyArrivesFinishedStillBecomesABlock`, `testAFinishedCallKeepsTheIdItWasOpenedWith`, `testAnEmptyDeltaIsStillADelta`, `testARefusedRequestReadsAsUpstreamsSdkErrorDoes`.

## chat completions took a stream cut off without finish_reason for success

**Symptom**: a stream broken midway (no `finish_reason`) ended the turn as `stop`, the truncated answer passing for a whole one; an unknown finish_reason was treated as `stop`; endpoints that do not support `stream_options` refused with 400; vLLM priority, the thinking budget field and Z.ai tool streaming could not be configured.

**Root cause**: upstream's message starts at `pending`; when the stream ends with `supportsFinishReason !== false` and no finish_reason received it reports `Stream ended without finish_reason`; `mapStopReason()`'s default branch reports `Provider finish_reason: X`; `stream_options` depends on `supportsUsageInStreaming`; `vllmPriority` / `thinkingTokenBudgetField` / `zaiToolStream` are compat keys.

**Trap rules**:
- An endpoint without finish_reason support writes `compat.supportsFinishReason: false`; the default is not changed back to success.
- Tests: `OpenAiCompletionsTest::testAStreamThatEndsWithoutAFinishReasonIsAnErrorNotAnAnswer`, `testAnEndpointThatSendsNoFinishReasonIsFinishedWhenItsStreamIs`, `testAFinishReasonNobodyMappedIsAnErrorThatSaysWhich`, `testUsageInTheStreamIsAskedForUnlessTheCompatSaysNot`, `testVllmsPriorityAndTheThinkingBudgetFieldGoOutAsTopLevelFields`, `testTheNewCompatKeysAreLaidOverDetectionKeyByKey`, `testARefusedRequestReadsAsUpstreamsSdkErrorDoes`.

## A Google stream without a finish reason was a success, and an error finish reason threw at once

**Symptom**: a Gemini stream broken midway ended the truncated answer as `stop`; when `finishReason` was an error value such as a safety block, the usage and content later in the same stream were lost.

**Root cause**: upstream's `google.ts` starts at `pending`, reports `Google stream ended without a finish reason` if still `pending` at the end, and reads an error finish reason to the end of the stream before reporting `Provider stopped with: X`.

**Trap rules**:
- `GoogleShared::onChunk()` only records the finish reason and does not throw; `Google::run()` and Antigravity throw `Provider stopped with: X` only after reading the stream to its end.
- Tests: `GoogleTest::testAStreamThatEndsWithoutAFinishReasonIsAnErrorNotAnAnswer`, `testAnErrorFinishReasonIsReadToTheEndOfTheStreamBeforeItEndsTheTurn`.

## A malformed Anthropic SSE event was skipped silently

**Symptom**: an unparsable event in an Anthropic stream was dropped and the turn ended normally with content missing; an event containing raw control characters was dropped too.

**Root cause**: upstream handles message events only, parses with `parseJsonWithRepair()` (repair first), and when that still fails throws `Could not parse Anthropic SSE event <type>: <msg>; data=<data>; raw=<raw>`.

**Trap rules**:
- Parsing goes through `Utils\JsonRepair::parse()`; `SseEvent::$raw` keeps the original lines for the message.
- Tests: `AnthropicTest::testAnEventThatCannotBeParsedEndsTheTurnAndSaysWhatItWas`, `testARawControlCharacterInAnEventIsRepairedRatherThanFatal`, `SseParserTest::testAnEventKeepsTheLinesItWasMadeOfCommentsIncluded`.

## StreamProxy's error text was not upstream's

**Symptom**: on a non-JSON line from the gateway pig skipped it and kept reading; a delta landing on a block of another kind reported pig's own words; a gateway `error` event without `errorMessage` had pig fill in default text.

**Root cause**: upstream throws as soon as `JSON.parse` fails, throws `Received text_delta for non-text content` and the like on a block type mismatch, and copies `errorMessage` from the `error` event as it is (possibly absent).

**Trap rules**:
- Tests: `StreamProxyTest::testALineThatIsNotJsonEndsTheTurnAsUpstreamsJsonParseDoes`, `testAnEventAgainstABlockOfAnotherKindEndsTheTurnInUpstreamsWords`, `testAnErrorEventWithNoMessageLeavesTheTurnWithoutOne`.

## models.json cost without base prices, and the fallback model list unvalidated

**Symptom**: a `cost` with only `input` billed the rest at 0; `compat.allowedFallbackModels` with more than 3 entries, an empty provider or a missing cost was accepted; `"cost": {}` and `"cost": []` were treated alike.

**Root cause**: upstream's `ModelCostSchema` requires the four base prices (not restricted to non-negative), `allowedFallbackModels` is validated by a `maxItems: 3`, `minLength: 1` schema, and TypeBox tells objects from arrays.

**Trap rules**:
- Validation reads the objects of `json_decode($raw, false)`, never the array decode, to tell types apart; an `allowedFallbackModels` error at provider level refuses the whole provider.
- Tests: `CustomModelsTest::testAPriceThatIsNotANumberIsRefusedRatherThanReadAsFree`, `testANegativePriceIsWhatUpstreamsSchemaAllows`, `testACostTierIsValidatedWithUpstreamsSchemaMessages`, `testTheCachingSessionAndFallbackKeysAreReadUnderUpstreamsNames`.

## The `deferred` stop reason did not exist

**Symptom**: a turn a provider ended with `deferred` (a handle for a run continuing in the background) read back from the session file as `stop` (the default after `StopReason::tryFrom()` fails), losing the handle; a provider had no way to report that ending either.

**Root cause**: upstream's `StopReason` includes `"deferred"`, `AssistantMessage.deferred` carries the handle, and the agent loop treats it as a normal end.

**Trap rules**:
- A new stop reason updates the copy points in `MessageJson`, `TransformMessages`, `SessionManager` and `AgentSession` with it.
- Tests: `MessageTest::testADeferredTurnAndItsHandleSurviveTheSessionFile`, `AgentLoopTest::testADeferredTurnEndsTheRunAsAFinishedOneDoes`.

## JSON parse error text was PHP's, not V8's

**Symptom**: when a gateway, an Anthropic SSE, a Mistral stream or a Gemini stream contained a piece that was not JSON, the error line said `Syntax error` (PHP's `json_last_error_msg()`), where pi says `Unexpected token 'x', "..." is not valid JSON` or similar for the same bytes.

**Root cause**: upstream calls `JSON.parse` directly in these places and hands V8's `SyntaxError` text to the user; PHP's parser has one generic sentence.

**Trap rules**:
- Wherever upstream passes a `JSON.parse` error through, use `Utils\JsJson::parse()` (throws a `JsonException` carrying V8's text), never `json_last_error_msg()`.
- The `openai` SDK's stream does not pass V8's text through (it always writes `Error reading response: malformed server-sent event JSON.`); that goes through `ErrorBody::openAiStreamEvent()`.
- After changing `JsJson`, compare against `node -e`; test: `JsJsonTest` (every expected value is node's real output).

## Anthropic / Gemini HTTP error text was pig's own

**Symptom**: a refused Anthropic request showed `Anthropic returned 401: invalid key`, Gemini `google returned 429: ...`; pi shows the SDK's text (`401 {"type":"error",...}`, `{"error":{"code":429,...}}`). Broken chunks in a Gemini stream were skipped silently, and an error object or a truncated tail in a 200 stream raised no error.

**Root cause**: upstream's Anthropic prints the `@anthropic-ai/sdk` `APIError` message (the whole response body as the error), Gemini prints `@google/genai`'s `throwErrorIfNotOK()` `JSON.stringify(errorBody)`; the Gemini stream is read by the SDK's `processStreamResponse()`, not a standard SSE parse.

**Trap rules**:
- Error text comes only from `Utils\ErrorBody` (`anthropicApiError()`, `genaiApiError()`, `openAiApiError()`); never assemble `<who> returned <status>` by hand.
- Session retries pattern-match these texts as upstream's `utils/retry.ts` does (`Pig\Ai\Utils\Retry`), never reading status codes; run `packages/ai/test/Utils/RetryTest.php` before changing an error's wording.
- The Gemini stream goes through `Google::sdkChunks()`; do not switch back to `SseParser`.
- Tests: `AnthropicTest::testARefusedRequestReadsAsTheSdksApiErrorMessage`, `GoogleTest::testARefusedRequestReadsAsTheGenaiSdkWritesIt`, `testTheStreamIsReadAsTheGenaiSdkReadsIt`.

## An error object in a chat completions stream was read past, and tool-result images landed between results

**Symptom**: when OpenRouter and others sent `{"error":{...}}` in a 200 stream, pig ignored it, kept reading, and finally reported `Stream ended without finish_reason`; with several consecutive tool results carrying images, the images' user message sat between two `tool` messages and some endpoints refused.

**Root cause**: upstream's chunks go through the `openai` SDK's `Stream`: `[DONE]` stops, non-JSON throws fixed text, `data.error` throws an `APIError` (the catch attaches `metadata.raw`). `convertMessages()` sends a run of consecutive tool results all as `tool` messages first, then all their images as one user message, and `lastRole` is updated only when a message is actually produced.

**Trap rules**:
- Stream events go through `ErrorBody::openAiStreamEvent()`, shared by the two OpenAI providers.
- Tests: `OpenAiCompletionsTest::testTheStreamFailsWhereTheSdksStreamDoes`, `testDoneEndsTheStreamWhateverFollows`, `testAToolResultRunsImagesGoOutTogetherAfterTheWholeRun`, `testASkippedEmptyMessageDoesNotResetTheLastRole`.

## The context-overflow table lagged upstream, and a Mistral overflow was retried

**Symptom**: overflows from Mistral (`... too large for model with N maximum context length`), Kimi, MiniMax, Together and others were not recognized, and pig resent the same oversized request three times with backoff instead of compacting; any provider's 429 with no body counted as an overflow.

**Root cause**: `Overflow` was a copy of an old upstream table, plus pig's own `EMPTY_BODY`. Upstream now has 25 patterns, `NON_OVERFLOW_PATTERNS`, a bodiless 400/413 rule for `cerebras` only, and the Xiaomi MiMo `length` stop check.

**Trap rules**:
- `Overflow` copies upstream's `overflow.ts` entry by entry, with no pattern of pig's own.
- Tests: `OverflowTest::testUpstreamsLaterExamplesAreRecognised`, `testTheBodilessFourHundredsOnlyCountForCerebras`, `testThrottlingThatMentionsTokensIsNotAnOverflow`, `testALengthStopThatFilledTheWindowWithNothingWrittenIsAnOverflow`.

## Mistral had no response-header timeout, and no provider sent a User-Agent

**Symptom**: when Mistral's server was slow to return the response headers, the turn hung indefinitely; no built-in provider's request carried a `User-Agent`.

**Root cause**: upstream's `requestMistralStream()` gives the response headers `timeoutMs ?? 60_000` (headers only, never cutting a long stream), reporting `Mistral response headers timed out after <ms>ms`; every provider sends `User-Agent: pi (<platform> <release>; <arch>)` (`getPiUserAgent()`) first, which the model's own headers may override. pig sends `pig (<platform> <release>; <arch>)` (`PigUserAgent::get()` — the product name is `pig` at the developer's request, the rest copied).

**Trap rules**:
- The header timeout wraps only `HttpClient::send()` and cancels its timer once the headers arrive; the user's signal keeps applying to the body.
- `User-Agent` goes first in the header array (`PigUserAgent::get()`); the Claude Code subscription token's and Copilot's UAs override it afterwards.
- Tests: `MistralTest::testTheResponseHeadersHaveTimeoutMsToArrive`, `testAStreamLongerThanTheTimeoutIsNotCutOff`, each provider's `testTheUserAgentIsPis*`.

## StreamProxy accepted `data:` lines without the space, and error text lacked the status text

**Symptom**: when a gateway wrote `data:{...}` (no space after the colon) pig read it where pi skips it; on a non-2xx pig wrote `Proxy error: 502` where pi writes `Proxy error: 502 Bad Gateway`; a non-string `error` field was not used by pig.

**Root cause**: upstream's `processLine()` recognizes only `startsWith("data: ")`, `slice(6).trim()`; the error is `Proxy error: ${status} ${statusText}`, and a truthy `errorData.error` is printed through a template string.

**Trap rules**:
- Recognize only `data: `, as upstream; do not loosen it on the grounds of the SSE spec.
- Tests: `StreamProxyTest::testOnlyALineStartingWithDataAndASpaceIsAnEvent`, `testANon2xxWithNoErrorFieldNamesTheStatus`, `testANon2xxErrorFieldIsPrintedAsATemplateLiteralWould`.

## Antigravity threw at the error-finish-reason chunk, losing the usage after it

**Symptom**: when Gemini on Antigravity ended with a safety block or similar, the `usageMetadata` arriving later in the same stream was not counted and the turn showed 0 input.

**Root cause**: direct Gemini had long read the stream to its end before throwing, as upstream does; Antigravity still threw at once in `onChunk`.

**Trap rules**:
- The two Gemini paths keep the same end-of-stream check; `promptFeedback.blockReason` is checked only in Antigravity (upstream's Gemini path has no such check).
- Tests: `AntigravityApiTest::testAnErrorFinishReasonIsReadToTheEndOfTheStreamBeforeTheTurnFails`, `testABlockedPromptIsStillNamedHere`.

## Provider-level retries, request options and provider events go through the request options, not a global `HttpClient` observer

**Symptom**: pig's providers had no `onPayload`/`onResponse`/`onProviderStreamEvent`/`headers`/`timeoutMs`/`maxRetries`/`maxRetryDelayMs`; `before_provider_request` hung on the process-wide `HttpClient::observe()`, received a `Request` object, and saw even the login token exchange; Anthropic without a key could not authenticate by header or by workload identity federation.

**Root cause**: upstream has all of these as `ProviderRequestOptions`/`StreamOptions` fields, providers wrap the first request in `retryProviderRequest()`, coding-agent's `buildRequestOptions()` fills `timeoutMs` (`retry.provider.timeoutMs` → `httpIdleTimeoutMs`), `maxRetries` and `maxRetryDelayMs` on every request, and the extension events attach through `onPayload`/`transformHeaders`/`onResponse`/`onProviderStreamEvent`.

**Trap rules**:
- New fields go on the `StreamOptions` base class, and `Stream` passes them whole with `baseArgs()`, never copying one by one.
- Provider-level retry recognizes only `ProviderHttpError` (with or without status+headers); Gemini's errors have no headers, so `retry-after` is not read and the cap does not apply.
- `onResponse` is called only where upstream calls it: after a successful Anthropic/OpenAI response, on every Mistral response (refusals included), never for Gemini.
- The federation client caches tokens by `[baseUrl, config]`; it cannot key by `HttpClient` instance (`Stream` news up a provider every time).
- Tests: `RequestOptionsTest` (five providers × each option, federation, Mistral URL/object toolChoice), `ProviderRetryTest`, `ExtensionApiTest::testTheProviderHooksSeeThePayloadTheHeadersTheResponseAndEachStreamEvent`, `AgentSessionTest::testEveryRequestCarriesTheProviderRequestOptions`.

## The SDKs' own request headers and abort wording are part of the port too

**Symptom**: Anthropic was sent to `/v1/messages` (the SDK uses `?beta=true`); Anthropic/OpenAI lacked `X-Stainless-*`, OpenAI sent `accept: text/event-stream` (the SDK sends `Accept: application/json`); Gemini lacked `x-goog-api-client` yet sent an extra `accept`; on abort the error text was pig's abort reason.

**Trap rules**:
- Header version numbers come from the SDK versions pinned in upstream's `packages/ai/package.json` (`Utils\SdkHeaders`); fields describing the runtime (`X-Stainless-Runtime*`, `gl-*`) say PHP truthfully and do not pretend to be Node.
- Abort wording per provider: `Request aborted` at the request stage (`retryProviderRequest`), `This operation was aborted` while reading the body (Anthropic/Gemini/Mistral) or `Request was aborted` after a silent end (both OpenAI), `Request aborted by user` while StreamProxy reads the stream.
- `SseParser`, Mistral, Gemini and StreamProxy decode as `TextDecoder` does: a leading BOM dropped, bad UTF-8 to U+FFFD; JS's `trim()` is `JsJson::trim()`.
- Tests: `RequestOptionsTest::testTheStainlessSdksOwnHeadersAreSent`, `testGeminiGetsTheGoogleSdksHeadersAndNoAccept`, `testAnAbortBeforeTheResponseIsRequestAborted`, `SseParserTest::testALeadingByteOrderMarkIsDroppedEvenSplitAcrossFeeds`, `AnthropicTest::testAbortingLeavesAnAbortedMessage`.

## The HTTP idle timeout is 300 seconds, not 60

**Symptom**: when a model thought for over a minute before the next token, the stream broke at `Socket timed out after 60.0s`; the `httpIdleTimeoutMs` setting affected only the provider retry layer's `timeoutMs`, not the stream read.

**Root cause**: upstream's `configureHttpDispatcher(httpIdleTimeoutMs)` sets undici's `headersTimeout`/`bodyTimeout` to 300000 (`DEFAULT_HTTP_IDLE_TIMEOUT_MS`); pig's `HttpClient` used its constructor's 60 seconds at every step.

**Trap rules**:
- An `HttpClient` given no timeout waits for the response headers and for every body read as long as the process-wide `HttpClient::useIdleTimeout()` says (default `DEFAULT_IDLE_TIMEOUT = 300.0`, 0 for unlimited); connect and write stay at 60 seconds. `bin/pig` calls it from the setting after configuring the proxy.
- Tests: `HttpClientTest::testTheIdleTimeoutIsFiveMinutesUnlessTheSettingSaysOtherwise`, `testAClientWithoutATimeoutOfItsOwnWaitsBetweenReadsAsLongAsTheSettingSays`.

## Antigravity: an empty response is asked again twice first, and a 404 runtime model falls back to the older name

**Symptom**: Antigravity occasionally returned a stream with no content at all and pig ended with an empty answer; when a new runtime model name was a 404 on some accounts the whole turn failed; the error text was not pi-antigravity's wording.

**Root cause**: pi-antigravity 0.9.0 re-requests an empty response up to twice (0.5s, 1s backoff), then reports `Antigravity API returned an empty response`; runtime models are tried in the order `[preferred, Routing::fallback()]`, moving on at a 404; the wording per status code comes from `friendlyAntigravityError()`.

**Trap rules**:
- Wording changes only in `AntigravityApi::friendlyAntigravityError()`, word for word against upstream; a token in an error is redacted first.

- Tests: `AntigravityApiTest::testAnEmptyResponseIsAskedForAgainBeforeTheTurnFails`, `testARuntimeModelThatIsNotThereFallsBackToTheOlderOne`, `testEveryStatusIsWordedAsPiAntigravityWordsIt`.

## Gemini: no `thinkingConfig` when thinking was not mentioned; Windows `os.release()` carries the build number

**Symptom**: calling `Google::stream()` directly with options that said nothing about thinking, pig sent `thinkingBudget: 0` (upstream sends nothing and lets Gemini decide); on Windows the OS version in the User-Agent was `10.0` where Node gives `10.0.22631`.

**Trap rules**:
- `GoogleOptions::$thinkingEnabled` is `?bool`, null meaning unsaid; "no thinking" still goes only through `GoogleShared::disabledGoogleThinkingConfig()`.
- The OS version goes through `PigUserAgent::releaseFrom($family, $release, $version)`, taking the build from `php_uname('v')` on Windows; tests inject the values rather than depending on the machine. The product name stays `pig`.
- Tests: `GoogleTest::testOptionsThatSayNothingAboutThinkingSendNoThinkingConfig`, `PigUserAgentTest`.

## Vertex and Bedrock: SDK behavior aligned item by item against oracle recordings

**Symptom**: requests written from reading `google-vertex.ts` / `bedrock-converse-stream.ts` differed from what upstream actually sends — `[profile work]` was taken for a section named `profile work` and the region went unread; an empty `inferenceConfig` serialized as `[]`; STS/SSO requests lacked the SDK's user agent and `amz-sdk-request` and were not retried; after assume-role Bedrock's UA lacked `T`; the bare Vertex id `gemini-2.5-flash` resolved to Vertex instead of the Gemini API; with non-key credentials `<authenticated>` was sent as the key.

**Root cause**: most of these two providers' behavior lives in the SDKs (`@aws-sdk/*`, smithy, `@google/genai`, `google-auth-library`) and is invisible from the provider files alone:
- smithy's ini section regex `(["'])?…\2` — in JS a backreference to a group that did not participate matches the empty string; in PCRE it fails;
- PHP's empty array `json_encode`s to `[]`, JS's empty object to `{}`;
- STS/SSO/SSO-OIDC are `@aws-sdk/nested-clients` (3.997.45), with the same UA, invocation id and standard retries;
- `RESOLVED_ACCOUNT_ID` (`T`) is added by the user-agent middleware for any identity with `$source` and an accountId, not only web identity;
- Vertex uses Google's own ids, colliding with the direct Gemini API's.

**Trap rules**:
- Before changing Bedrock/Vertex behavior, read upstream's recordings in `packages/ai/test/fixtures/{bedrock,vertex}/*.json`; a fixture is upstream's behavior, and a mismatch is pig's bug unless the test says why (only two places: the exact cut point of an abort, and undici's `accept: */*`).
- The optional group in the smithy regex is written `(["\']?)` so it always participates.
- Structures go through `shape()` in `BedrockRuntimeClient::serializeRequest()`, which returns `stdClass` for an empty one; no JSON assembled at call sites.
- STS/SSO/OIDC requests go only through `CredentialChain::call()` (SDK headers + standard retries); the UA's credential features only through `BedrockRuntimeClient::credentialFeatures()`.
- `Models::RESOLD` includes `google-vertex`: a bare id belongs to the direct Gemini API, Vertex is written `google-vertex/<id>`.
- `Stream::AMBIENT_AUTH_MARKER` means only "signed in"; `Stream::start()`/`translate()` both strip it, and nowhere is it sent as a key.
- The oracle's frozen clock triggers clock-skew retries: record error scenarios with the real clock; the Node side needs `AWS_BEDROCK_FORCE_HTTP1=1`.
- Tests: `BedrockTest` (47 recorded scenarios), `GoogleVertexTest` (12 end-to-end + 8 URL), `Utils/Aws/SignatureV4Test` (the AWS signature suite, 38 cases), `Utils/Aws/EventStreamTest`, `Utils/Aws/SharedConfigTest`, `Utils/GoogleAuthTest`, `ModelsTest::testBedrockRowsAreUpstreamsCatalogueRows`, `GenerateModelsTest::testBedrockRowsAreUpstreamsBedrockRows`.

## Antigravity's model catalog is discovered dynamically

**Symptom**: pig-antigravity had only the static table the generator script prints, plus whatever catalog pi had written to `models-store.json`; pig itself never asked `fetchAvailableModels`, so a new model waited for pi to run or the script to be rerun; `/antigravity.refresh` only reread the file. The catalog read in was laid over the static rows with `replace: true`, leaving the static row in place while its routing had been replaced.

**Root cause**: pi-antigravity 0.9.0's `refreshAntigravityModels()`: hydrate the stored catalog first, return at once when offline or without a key; unless forced, do not ask if the last check is within `getCatalogRefreshIntervalMs()` (default 4 hours, `ANTIGRAVITY_CATALOG_REFRESH_INTERVAL_MS`); ask the three endpoints (only `ANTIGRAVITY_BASE_URL` when set, which must be an https `*.googleapis.com`) and merge, group with `buildAntigravityCatalog()`, apply only when non-empty and publish `{models, "pi-antigravity": {catalog, checkedAt, modelEnums}}`; on failure keep the previous catalog, throwing only when forced. `applyAntigravityCatalog()` replaces the model list as a whole.

**Trap rules**:
- Grouping lives only in `Grouping`; network, TTL and storage only in `Discovery`; the shape is the extension's own (as stored), converted to pig's `Model`/`Routing` only through `Catalog::fromCatalog()`/`tables()`, and a routing target missing its enum refuses the whole catalog (`Discovery::apply()` throws).
- pig writes its own `~/.pig/agent/models-store.json` (pi's shape, keeping other providers' entries); the read order is pig's, then pi's, then pi's old `antigravity-model-catalog.json`.
- When to refresh: pi's model registry does it on opening `/model`, after login and on `pi update --models`; pig has that layer now (`ModelRefresh`, for providers with `Provider::$refreshModels`), but this extension does not register one, so it refreshes unforced in the background at session_start and after `/login antigravity`, and `/antigravity.refresh` forces. The offline switch is `PIG_OFFLINE`.
- An unforced refresh failure goes to pig's log (`Logger::warning`), not thrown.
- Tests: `AntigravityDiscoveryTest` (11), `AntigravityExtensionTest::testRefreshingTheCatalogWithoutAnAccountSaysToSignInFirst`, `AntigravityCatalogTest`.

## Azure, Codex and Radius rows are derived rules of upstream's generator, and Codex credentials carry `accountId`

**Symptom**: `azure/…`, `openai-codex/…`, `radius/…` had no models at all, so the ported protocols could not be selected; a bare id `gpt-5.4` could resolve to Azure or Radius; `openai/gpt-5.6` was listed from models.dev and refused by OpenAI; pig rewriting `auth.json` dropped the Codex `accountId` pi had written.

**Root cause**: upstream's three tables are not models.dev rows: Azure is a clone of the `openai` Responses rows taken **before** compat/thinking metadata is applied (the four prices only, no tiers; `AZURE_CONTEXT_WINDOW_OVERRIDES` sets 5.4/5.5/5.6 to 1,050,000), plus a hand-written DeepSeek V4 Pro (Chat Completions, Azure price, `AZURE_DEEPSEEK_V4_THINKING_LEVEL_MAP`); Codex is the hand-written `codexModels`; Radius is the gateway's `/v1/config`. The `applyThinkingLevelMetadata()` that follows has its own branches for the azure/codex APIs (`gpt-5`'s `off: null` includes Azure, the GPT-6 branch and `max` cover all three Responses APIs, Codex's xhigh models get `minimal: "low"`). `MODELS_DEV_OPENAI_UNSUPPORTED_MODEL_IDS` removes `gpt-5.6`. `credentialsFromToken()` stores `accountId` on the credentials.

**Trap rules**:
- The three tables are written only by the generator's `azureRows()` (derived from the `openai` rows just written), `codexRows()` (`CODEX_MODELS`) and `radiusRows()` (`RadiusConfig`, `--radius-from` offline); never hand-edited into another shape. The generator refuses to write when the gateway's `baseUrl` disagrees with `Models::RADIUS_BASE_URL`.
- The derived rows' compat comes only from `Models::azureCompat()` / `codexCompat()`, the thinking maps only from the branches of `thinkingLevelMap()`; a change is compared row by row against `azure.json`/`openai-codex.json`/`radius.json` in the `@earendil-works/pi-ai` release package (82 rows identical, key order included).
- `RESOLD` includes `azure`, `openai-codex`, `radius`: a bare id belongs to the direct provider, resold ones are written `azure/<id>`.
- `Credentials::$accountId` is read and written only through `Auth`; `CallbackServer`'s `state` parameter, when on, checks a wrong state and a missing code as upstream does — 400 and keep waiting.
- Codex sends no zstd, Radius is `RADIUS_API_KEY` plus the public catalog only (no login, no runtime refresh), TypeSafe has no chat models — known differences, see "Azure OpenAI, ChatGPT's Codex backend and `pi-messages`".
- Tests: `ModelsTest::testAzureRowsAreUpstreamsCatalogueRows`, `testCodexRowsAreUpstreamsCatalogueRows`, `testRadiusRowsAreUpstreamsCatalogueRows`, `GenerateModelsTest::testAzureRowsAreUpstreamsCloneOfTheOpenAiRows`, `testCodexRowsAreUpstreamsExplicitList`, `testRadiusRowsAreTheGatewaysCatalogueAsItSentThem`, `testOpenAisUnsupportedAliasIsNotOffered`, `AzureOpenAiCompletionsTest`, `OpenAiCodexOauthTest` (14 recorded scenarios), `RadiusConfigTest`, `AuthTest::testACodexCredentialKeepsItsAccountIdAsPiWritesIt`.

## The other 25 providers on ported APIs: rows are the generator's output, compat written by the generator's own detection

**Symptom**: DeepSeek, OpenRouter, Vercel AI Gateway, Together, Fireworks, Baseten, Hugging Face, NVIDIA, MiniMax(-cn), Moonshot(-cn), Kimi For Coding, Meta, OpenCode Zen/Go, Xiaomi and its three Token Plans, the three Qwen Token Plans, Zhipu's domestic coding plan and Ant Ling had no models at all although the protocols were ported; the `cerebras` and `zai` rows had two or three compat items, not upstream's catalog (runtime `resolve()` filled them in to the same behavior, but the metadata was not upstream's); OpenCode requests carried no `x-opencode-session`.

**Root cause**: these providers in upstream's `providers/all.ts` all go over `openai-completions` / `anthropic-messages` / `openai-responses` / `google-generative-ai`; the generator handles each on its own (`processBasetenModels()`, `processFireworksModels()`, the branches of `loadModelsDevData()`, `fetchOpenRouterModels()`/`fetchAiGatewayModels()`, hand-written DeepSeek/Ant Ling rows, temporary overrides) and then runs the same `apply*Metadata()` chain over every model. `applyOpenAICompletionsCompatMetadata()` uses the **generator's own** `detectOpenAICompletionsCompat()`, which differs from the runtime `detectCompat()` in three places: `supportsStrictMode` written as explicit metadata per provider, `TOGETHER_REASONING_ONLY_MODELS` not given the together format, and OpenRouter's `~anthropic/` also given Anthropic cache control; `applyModelsDevReasoningOptionMetadata()` decides **before** `applyThinkingLevelMetadata()` writes `forceAdaptiveThinking` by id, so a proxied Claude that is adaptive by id alone does not take models.dev's efforts (OpenCode's `claude-opus-4-6` in the release catalog is `{max: "max"}`); `opencode-headers.ts` adds a session header to every request of the two OpenCode providers.

**Trap rules**:
- Tables live only in `Models::CATALOGUE_PROVIDERS` (provider => table, base URL per API); a row says only what the catalog says (nine columns, plus an api column for the four multi-API providers; `thinkingLevelMap`, `effortLevelMap`, `tiers`, `supportsToggle`/`supportsEffort`, `cacheControlFormat`). Compat and id rules live only in `catalogueCompat()`, `completionsCompat()`, `responsesCompat()`, `AnthropicCompat::forBuiltIn($provider, $id, $own)`, `thinkingLevelMap()`; a single row is built by `catalogueModel()`.
- The compat of built-in Chat Completions models (`cerebras`/`groq`/`xai`/`zai` included) comes only from `completionsCompat()` = `detectedCompletionsCompat()` (the generator's detection, keeping only keys that differ from `OPENAI_COMPLETIONS_DEFAULT_COMPAT`) + its own + the transcript rules; the second table that `STRICT_MODE` was is deleted — do not add one back. Runtime detection is still `OpenAiCompat::detect()` + `resolve()`; the two are not merged.
- The effort gate of Anthropic API rows always uses the processing-stage compat (`$own`), the `anthropic` and `github-copilot` tables too — see "Claude took models.dev's effort map".
- Rows were written from the `@earendil-works/pi-ai` 1.1.0 release catalog (models.dev, OpenRouter, Vercel and the NVIDIA list are all unreachable): the catalog is the result after the generator's metadata, so only rows the id rules cannot reproduce carry the final map (as `thinkingLevelMap`), none has `effortLevelMap`, and Baseten/Fireworks toggle/effort are inferred from compat. The next regeneration writes from the sources. Compared row by row against the 25 `<provider>.json`: 1023 rows identical (maps in order, compat, inputLimits, headers, tiers).
- Generator: new branches in `rowsFor()`, `openRouterRows()`/`aiGatewayRows()`/`nvidiaIds()` (`--openrouter-from`/`--vercel-from`/`--nvidia-from` offline; on failure print and keep the existing table, as Radius does), `catalogueTemporaryOverrides()`, `DEEPSEEK_ROWS`/`ANT_LING_ROWS`; Azure's DeepSeek row is now derived from `DEEPSEEK_ROWS`.
- `RESOLD` gains the gateways, hosted providers, Token Plans and domestic endpoints; a bare id belongs to the maker's own provider (pig's rule — upstream simply does not recognize a bare id several providers have).
- The OpenCode session header is added only in `Stream::base()` through `Providers\OpenCodeHeaders::withSessionHeader()`: when there is a sessionId (an empty string is none) and the caller wrote no header of that name (case-insensitive; null counts as written).
- OpenRouter routers (`openrouter/auto`, …) are priced -1/token, passed through as -1,000,000 as upstream does; `ModelsTest` allows only that one negative price.
- Not ported: OpenRouter, Kimi and Meta OAuth login (key only). Cloudflare, classifiers, images and faux: see "Cloudflare, classifiers and image models". If OpenCode's Kimi K2.6 / Grok Build rules land on a Messages/Gemini API row, upstream writes OpenAI keys into that row's compat, which pig's `AnthropicCompat` cannot hold — no such row in the catalog today.
- Tests: `ModelsTest::testTheCatalogueProvidersRowsAreUpstreamsCatalogueRows`, `testFireworksCombinesModelsDevsEffortAndToggleWithNarrowCorrections` (upstream `fireworks-model-generation.test.ts`), `testAProxiedClaudeThatThinksAdaptivelyOnlyByItsIdTakesNoModelsDevEfforts`, `testAResoldIdOfTheCatalogueProvidersIsItsMakersOnlyBare`, `CatalogueProvidersTest` (upstream's baseten/together/fireworks/qwen-token-plan/xiaomi/zai-coding-plan/openrouter-cache-control/opencode-provider-headers tests), and in `GenerateModelsTest` the Baseten, Fireworks, Together, OpenCode, Kimi/Moonshot, Token Plan, NVIDIA, MiniMax, OpenRouter, Vercel, DeepSeek/Ant Ling and unreachable-list scenarios.

## Claude took models.dev's effort map

**Symptom**: `anthropic`'s Opus 4.6/4.7/4.8/5, Sonnet 4.6/5, Fable 5/5.1 and Copilot's Claudes of the same kind had models.dev's whole effort table as `thinkingLevelMap` (`off: null, minimal: null, low, …`); the 1.1.0 release catalog has only the few entries the id rules give (Opus 4.6 is just `{max: "max"}`). So Sonnet 4.6/5, Opus 4.6–4.8 and Copilot's Opus 4.7/5, Sonnet 4.6/5/5.5 were said unable to stop thinking. Three more row values differed from the catalog: `claude-sonnet-4-5` (both ids) window 200k (catalog 1M), `claude-sonnet-5-5` cacheRead 0.1 (catalog 0.2), and Copilot had a `claude-haiku-5.5` the catalog does not.

**Root cause**: upstream's metadata loop in `generateModels()` runs `applyModelsDevReasoningOptionMetadata()` before `applyThinkingLevelMetadata()`. The former's gate `supportsDirectReasoningEffort()` looks only at `compat.forceAdaptiveThinking` for `anthropic-messages`, while the `anthropic` branch of `loadModelsDevData()` writes no compat and the Copilot branch writes only `getAnthropicMessagesCompat()` — `forceAdaptiveThinking` is written by id by the latter. pig judged with the final compat from `AnthropicCompat::forBuiltIn()`, so the gate opened. The row values were generated from a models.dev newer than 1.1.0; upstream's hand-written Opus/Sonnet 5.5 rows were missing from pig's generator too.

**Trap rules**:
- The effort gate in `Models::table()` always gets the processing-stage compat: null for `anthropic` rows, null for Copilot's Anthropic rows, and for other APIs' rows their compat (the one `loadModelsDevData()` writes). Never pass `forBuiltIn()`'s result to `supportsDirectReasoningEffort()`.
- Rows still carry `effortLevelMap` (the generator writes it from models.dev); the gate decides whether it is used.
- The generator's `OVERRIDES` has upstream's hand-written `anthropic/claude-opus-5-5` and `claude-sonnet-5-5` rows ("Add Claude Opus 5.5 until models.dev includes it"); the whole map is merged by id in step 1 of `thinkingLevelMap()`.
- A change is compared row by row against the 1.1.0 release catalog's `anthropic.json` and `github-copilot.json`: 51 rows identical, maps in order.
- Tests: `ModelsTest::testEveryClaudeCarriesUpstreamsWholeThinkingLevelMap`, `testTheClaudeModelsThatCannotStopThinkingSaySo`, `GenerateModelsTest::testClaudeOpusAndSonnetFiveFiveAreAddedByHandUntilTheCatalogueHasThem`.

## xAI went over Chat Completions

**Symptom**: `xai/grok-4.x` was sent to `https://api.x.ai/v1/chat/completions` without `include: ["reasoning.encrypted_content"]`, and sent `prompt_cache_retention` for `cacheRetention: long`; models.dev's efforts did not apply and none of the four models had a level map.

**Root cause**: upstream's `xaiProvider()` is `openAIResponsesApi()`, and the generator writes `api: "openai-responses"` and `XAI_RESPONSES_COMPAT` (`{supportsLongCacheRetention: false}`) on every xAI row; `supportsDirectReasoningEffort()` is always true for Responses models, and rows without efforts get `{off: null, minimal: null}` from `applyThinkingLevelMetadata()`.

**Trap rules**:
- xAI rows are built only by `Models::addXaiModels()` (`XAI_BASE_URL`, `XAI_RESPONSES_COMPAT` through `responsesCompat()`); `OPENAI_COMPATIBLE` no longer has xai — do not add it back. The generator's `DIRECT` has xai as `Api::OpenAiResponses`.
- `OpenAiResponses`'s `include` rule for `provider === 'xai'` was already there; the xAI completions detection in the runtime `OpenAiCompat::detect()` is left for custom models written as completions in `models.json`.
- Not ported: xAI's SuperGrok/X Premium login (`XAI_API_KEY` only).
- Tests: `XaiResponsesTest` (upstream `xai-responses.test.ts`, minus the two UA cases and OAuth).

## Cloudflare, classifiers and image models

**Symptom**: `cloudflare-workers-ai`, `cloudflare-ai-gateway` had no models at all; there was no `classify()`/`generateImages()`, so the decision models of TypeSafe, OpenRouter, Vercel, OpenCode Zen and Workers AI and OpenRouter's image models had nowhere to go; there was no faux provider.

**Root cause**: upstream 1.x splits a provider's models into chat, image and classifier (`ModelType`, `KnownImageApi`, `KnownClassifierApi`), and `Models.classify()`/`generateImages()` "never rejects"; Cloudflare's `auth.resolve()` puts the account (and gateway) id into env, `cloudflareStreams()`/`cloudflareClassifier()` replace the placeholders in the base URL before dispatch, the gateway's key goes in `cf-aig-authorization`, and `Authorization`/`x-api-key` are forced to null.

**Trap rules**:
- Three tables for three kinds: chat is still `Model`, `all()`, `find()`; `ClassifierModel` and `ImageModel` live in `CLASSIFIER_MODELS` and `IMAGE_MODELS` (keyed `provider/id`), read only through `Models::findOfType()`/`allOfType()`. `classify()`/`generateImages()` dispatch by API (not by the provider's `classifiers` table — every built-in provider registers the same API to the same implementation), every failure is a result (`stopReason` error/aborted), never a throw; no key is `Provider is not configured: <provider>` (`llama-cpp-classify` needs none).
- Cloudflare auth lives only in `Stream::start()`'s `$requestAuth` and `Models::applyClassifierAuth()`: `Providers\Cloudflare::resolveCloudflareEnv()` missing the key, account or gateway id is `Provider is not configured`; placeholders are replaced only by `resolveCloudflareModel()` (ones missing from env are kept); gateway headers go through `gatewayAuthHeaders()` + `mergeHeaders()`, and the caller's header of the same name (case-insensitive) wins.
- pig's `Auth` does not store a credential's `env`: the `CLOUDFLARE_ACCOUNT_ID`/`CLOUDFLARE_GATEWAY_ID` pi writes into `auth.json` on Cloudflare login are not read by pig; put them in environment variables or `StreamOptions::$env`.
- System One and llama.cpp requests go only through `SystemOneShared::postJson()`: a timeout is a `ProviderHttpError` ("Request timed out after <ms>ms", retried, like upstream's `isProviderError()`), no response is `fetch failed` (not retried), a refusal is `<label> returned <status>` with the body, shown as `ErrorBody::format(ErrorBody::normalizeProviderError(), "<label> error")`; 2 retries by default.
- The public `bool` question goes on the wire as `noul`; an empty `state` array is sent as `{}`; responses are always parsed as objects (`stdClass`), the only way to tell `{}` from `[]`.
- llama.cpp's label token cache is a process-wide static table keyed `root\0model\0label`; each test case uses a different path prefix (`/sN/v1`).
- `openrouter-images` goes through `SdkRequest` (the `openai` SDK's headers, timeout, `APIError`); usage is computed from the four unit prices, ignoring tiers — upstream's own `parseUsage()` does the same.
- Rows were written from the `@earendil-works/pi-ai` 1.1.0 release catalog (models.dev, OpenRouter, Vercel unreachable): Cloudflare 74 rows, classifiers 25, images 61, compared row by row (maps in order); of the Cloudflare rows only the 25 the id rules cannot reproduce carry a final `thinkingLevelMap`, and the next regeneration writes `effortLevelMap` from the sources. `openai/gpt-6-luna`'s `openai-decisions` classifier is not in the reference commit (98d2e1947) and was not written. Generator: `DIRECT`'s `cloudflare-workers-ai`, `cloudflareAiGatewayRows()`, `classifierRows()`/`imageRows()` (`--decisions-from`, `--openrouter-decisions-from`, `--openrouter-images-from` offline), `HAND_KEPT_CLASSIFIERS`.
- faux: `Providers\Faux` (`fauxText()` and the rest, with `fauxProvider()`) + `FauxProvider` (`StreamApi`, `provider()` handed to `ProviderRegistry`). Through `Stream` a key is still required (every pig extension protocol needs one); calling `->stream()` directly does not.
- Not ported: `cloudflare-ai-binding.ts` beyond the sentinel and `createAiBindingFetch()`'s check (`StreamOptions` has no `fetch`, and there is no binding outside a Worker); faux's deferred; `pig-codemode`'s `models` namespace; `api/lazy.ts` and `*.lazy.ts` (PHP autoloads, with no observable difference: no asynchronous loading, so no load-failure error stream); `classifier-shared`, `openai-decisions` and `context.images`, which arrived in 1.1.0.
- Tests: `SystemOneTest` (upstream `typesafe-system-one.test.ts`, `cloudflare-workers-ai-system-one.test.ts`, `classifier-models.test.ts`), `LlamaCppClassifyTest`, `OpenRouterImagesTest`, `CloudflareTest`, `FauxProviderTest`, `ModelsTest::testTheCatalogueProvidersRowsAreUpstreamsCatalogueRows`, and in `GenerateModelsTest` the Workers AI, gateway, classifier, image and unreachable scenarios.

## A tool with no parameters sent `properties: []`, and an integer enum, both refused on the Antigravity path

**Symptom**: with `pig-computer` loaded, every Antigravity turn was a 400: `Invalid value at
'request.tools[0].function_declarations[16].parameters' (Map), Cannot bind a list to map for field
'properties'` and `…[19].parameters.properties[3].value.enum[0] (TYPE_STRING), 1`. The same tools
worked on Claude and on `google/` direct.

**Cause**: `GoogleShared::sanitizeForOpenApi()` passed `'properties' => []` through, and PHP encodes
an empty array as `[]` — the third shape from the index, already fixed for `args` at line 745 and
not here. And `computer_click`'s `click_count` was `type: integer, enum: [1, 2]`; Gemini's OpenAPI
subset allows `enum` on strings only. Only the Code Assist `parameters` path is affected; the direct
API's `parametersJsonSchema` is full JSON Schema.

**Rule**: an empty `properties` goes out as `new stdClass()` from `sanitizeForOpenApi()`; a tool
declaration carries no non-string `enum` (say it in the description instead).

**Test**: `GoogleTest::testAToolWithNoParametersDeclaresAnEmptyMapAndNotAList`.
