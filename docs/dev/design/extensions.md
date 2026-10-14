# Design: Extensions

Extensions, hooks, MCP, codemode, skills, prompt templates, the bundled extensions. Moved out of `AGENTS.md` (then `CLAUDE.md`) as written — "this file" in the entries below means that file, where they were first written. The index is generated from the headings below.

- [Prompt templates](#prompt-templates)
- [Skills, prompts and themes are resource lists](#skills-prompts-and-themes-are-resource-lists)
- [A provider an extension brings: `Pig\Ai\Extension`, and why Antigravity is one](#a-provider-an-extension-brings-pigaiextension-and-why-antigravity-is-one)
- [A hook with something to say](#a-hook-with-something-to-say)
- [MCP servers, and the tool that arrives after startup](#mcp-servers-and-the-tool-that-arrives-after-startup)
- [codemode: the model writes PHP, and a child `php` runs it](#codemode-the-model-writes-php-and-a-child-php-runs-it)
- [A closure read `$pi` it never captured, and the warning was swallowed by its own `catch`](#a-closure-read-pi-it-never-captured-and-the-warning-was-swallowed-by-its-own-catch)
- [What pi 1.0.0 changed about codemode and MCP OAuth, and the one thing left out](#what-pi-100-changed-about-codemode-and-mcp-oauth-and-the-one-thing-left-out)
- [A stranger's `.pig/` is not loaded until somebody says so](#a-strangers-pig-is-not-loaded-until-somebody-says-so)
- [Built-in extensions](#built-in-extensions)

## Prompt templates

`Prompt\SlashCommands` is upstream's `core/prompt-templates.ts`: a markdown file in
`<agent-dir>/prompts/` or `.pig/prompts/` becomes `/name`, and its body is the prompt.
`/review src/Foo.php` sends `review.md` with `$1` filled in. **`commands/` is not read**, with no
fallback — upstream's directory name, and the developer asked for no compatibility layer.

Not the same thing as a skill, though both are markdown with frontmatter. A skill is **offered** to
the model, which reads it when a task matches; a template is **sent** by the person, now, as the
message.

- **Where is the package manager's**, as below for skills. A conventional `prompts/` directory loads
  its direct children only; a directory named in the `prompts` setting is walked. The first template
  of a name wins and a later one is a collision warning.
- **Substitution is upstream's set, in one pass**: `$1`…`$N`, `$@`, `$ARGUMENTS`, `${N:-default}`,
  `${@:-default}` (and `${ARGUMENTS:-default}`), `${@:N}`, `${@:N:L}`. One regex over the template, so
  an argument that happens to contain `$1` is never substituted into.
- `argument-hint` in the frontmatter is shown in the completion list; only a YAML string is read.
- A built-in command is tried **first**, so a `help.md` somebody forgot cannot shadow `/help`.
- `arguments()` walks bytes where upstream walks code units — the same answer, because the quotes,
  space and tab it looks for are all below 0x80.

## Skills, prompts and themes are resource lists

`Prompt\Skills` is upstream's `core/skills.ts`. A skill is a folder with a `SKILL.md` whose
frontmatter says what it is for; only the name and description reach the prompt, and the
instructions are a file the model reads when a task matches — a hundred skills cost a hundred lines.

**Where any of the three is looked for is `PackageManager::resolveLocalResources()`'s**, as it is
upstream's `resolve()`: the `skills` / `prompts` / `themes` settings (project first), then the
conventional directories — `<agent-dir>/skills|prompts|themes`, `.pig/skills|prompts|themes` once the
project is trusted, `~/.agents/skills`, and for a trusted project `.agents/skills` in the working
directory and each ancestor up to the repository root. `CodingAgent::resources()` puts it together for
both `session()` and `/reload`: an `-e` package's resources, then those, then the configured packages',
then `--skill` / `--prompt-template` / `--skills-dir`. **The first skill of a name wins**; a later one
is `name "x" collision: … is loaded and this one is skipped`, and one file reached through two paths
(a symlink) is one skill with no warning.

- **Each setting is a string list with pi's syntax**: a path, `!glob` to exclude, `+path` / `-path`
  for one exact file (`Settings::resourceEntries()`). The old `skills` *object* (`enabled`,
  `ignoredSkills`, the five `enable*` root switches) is reported by `Settings::problems()` and
  ignored; `enableSkillCommands` is a top-level key.
- **Other tools' folders are not read**: `~/.claude/skills`, `.claude/skills`, `~/.codex/skills`,
  `~/.pi/agent/skills`, `.pi/skills` and pi's theme directories are gone. Upstream reads none of them;
  somebody who wants one names it in `skills`.
- `disable-model-invocation: true` keeps a skill out of the prompt; `/skill:name` still reaches it.
- **A `SKILL.md` without a description is skipped with a warning** — it is the only thing the model
  sees. Any other `.md` without one is skipped silently: a stray README is not a broken skill. The name
  is not compared with the folder's, as upstream does not compare it.

Warnings go to stderr before the UI starts. The frontmatter is read a line at a time (no YAML
parser), so malformed frontmatter is not refused as upstream refuses it, and the `.gitignore` rules
upstream applies while walking a skills directory are not ported — both are known gaps.

**All five of upstream's protocols are here.** The fifth took a correction on the way: this
used to say it "was never a protocol" — that `google-gemini-cli` was Gemini behind a sign-in. It
is not. Its models carry `api: "google-gemini-cli"` and upstream has a 603-line provider for it,
because Code Assist has its own endpoint and wraps a Gemini request inside a Cloud-project
envelope. The claim was wrong and is recorded as wrong; checking it took one grep of
`models.generated.ts` for the `api` field. (Since then `mistral-conversations`, `google-vertex` and
`bedrock-converse-stream` have joined them — the last two in "Vertex AI and Amazon Bedrock: two more
SDKs, emulated rather than wrapped", below — and `azure-openai-responses`, `openai-codex-responses` and
`pi-messages`, in "Azure OpenAI, ChatGPT's Codex backend and `pi-messages`".)

`Providers\GoogleGeminiCli` is 250 lines against upstream's 603, because upstream re-implements
the chunk walk and this shares it — see `GoogleShared`. **GitHub Copilot is done** too, and needed
no new protocol at all: it serves everything through the two OpenAI shapes.

## A provider an extension brings: `Pig\Ai\Extension`, and why Antigravity is one

**Decided 2026-10-06.** pi removed Gemini CLI and Antigravity from its core in 0.71 and the
community rebuilt Antigravity as an extension (`Rahularya01/pi-antigravity`) on pi's
`registerProvider()`. pig had kept Antigravity built in, spread across `Models`, `Api`, `Stream`,
`Oauth\Provider`, `Auth`, `AgentSession`, `Doctor`, `HttpServer` and the web front end — 28 source
files, 95 references — with a 370-line extension that was a thin shell over all of it. Google's
private protocol (its User-Agent, its envelope, its `v1internal:` endpoints) was pig's largest
piece of core code with no upstream behind it, and every change Google made meant a core release.

The developer's call was to do pi's move in one step: **nothing about Antigravity is in
`packages/` any more.** `extensions/pig-antigravity/src/` holds the protocol (`AntigravityApi`),
the sign-in (`AntigravityOauth`), the routing, the model table, the accounts store, the catalogue,
quota and image generation; `index.php` registers the provider and wires the rest through hooks.
Install the extension and `--model antigravity/gemini-3.8-flash` works; remove it and the name
means nothing. The tracking target is `Rahularya01/pi-antigravity`, not pi.

**What the core grew to make that possible is the provider half of pi's `ExtensionAPI`**, which pig
had not ported because nothing needed it — and which is exactly what CLAUDE.md's rule about new
surface asks for: decided by its first caller.

- **`Pig\Ai\Extension\Provider`** is one object for what a built-in provider spreads across four
  classes: id, name, models, a `StreamApi` (or null for a protocol pig already has), an
  `OauthFlow` (or null for a key), the environment variables that carry its key, and `resold` —
  `Models::RESOLD`'s dynamic half, so `claude-sonnet-4-6` bare stays Anthropic's.
- **`ProviderRegistry`** is static for `Models::register()`'s reason, and each of the four core
  classes asks it when its own table comes up empty: `Models::isResold()` and
  `Models::forgetProvider()`, `Api::Extension` with `Stream::start()`'s one new arm and
  `Stream::translate()`'s, `Stream::envApiKey()`'s default, and `Auth`'s `signIns()`/`signIn()`,
  which `/login`, `pig-ai` and `Doctor` list from in place of `Provider::cases()`.
  **Still no `default` in `Stream::start()`**: `Api::Extension` is one named arm, and the registry
  refuses by name — `No extension provides the protocol for antigravity/gemini-3.8-flash. Is its
  extension loaded?` — where "No API key" would send somebody to the wrong file.
- **`Auth::useSecondStore(provider, read, renewed)`** is the seam `antigravity-accounts.json`
  needed: `credentials()` asks it only when `auth.json` has nothing, and `fresh()` tells it about
  a renewal. `Auth::login()` takes `Provider|OauthFlow` and stores under the flow's `id()`.
- **Upstream's four provider events**, wired as upstream wires them — through the request's
  options, which the session puts on the agent: `before_provider_request` is `onPayload` (the
  payload the provider built, chained, a `BeforeProviderRequestResult` replacing it),
  `before_provider_headers` is `transformHeaders` (handlers edit `$event->headers` in place, a null
  deleting), `after_provider_response` is `onResponse` (status and headers before the body) and
  `provider_stream_event` is `onProviderStreamEvent`. A sign-in or any other `HttpClient` call is
  not a provider request and is not seen. The 429 account failover is the protocol's own, inside
  the request, as pi-antigravity has it. `model_select` and `thinking_level_select` came in the
  same batch.
- **`ExtensionApi` grew the rest of the gap that this needed**: `registerProvider()`,
  `registerFlag()`/`getFlag()` (`bin/pig` hands every option over after the extensions load,
  because which flags exist is only known then), `getSettings()`, `setModel()`,
  `getThinkingLevel()`/`setThinkingLevel()`, `sendUserMessage()`, `setLabel()`, `getCommands()`, and
  `registerHttpRoute()` — pig's own, with no upstream counterpart because pi-web imports nothing
  from an extension; it is how the accounts panel's endpoints left `HttpServer` (the panel itself is
  now a `registerWebPanel()` — see "An extension's panel in the page"). `registerProvider()` also takes
  upstream's legacy `(name, config)` form (`Extensions\ProviderConfig`, `ConfiguredApiKeyAuth`): no
  `models` keeps the provider's models at the new `baseUrl` / `headers`, `models` replaces them (chat,
  `image` and `classifier` types) until `unregisterProvider()`, `apiKey` is a `ConfigValue`, and
  `streamSimple` speaks for the models of its `api`. Refused by name: custom `images` / `classifiers`
  implementation maps, and an `oauth` that is not an `OauthFlow`.

Three things about the move that are not obvious from the diff:

- **`bin/pig` loads the extensions before `--list-models`**, and hands the result to
  `CodingAgent::session()` as `preloadedExtensions` rather than loading twice — a factory run
  twice is an MCP server connected twice. `HttpServer::start()` loads them for the shell too.
- **The default model is `claude-sonnet-4-5` again** (`CodingAgent::DEFAULT_MODEL`), which both
  READMEs have said all along; for a while the code said `antigravity/gemini-3.8-flash`, a provider
  that only exists once an extension has loaded.
- **`ProbeModels` gained `zzp-thinker`** — a model whose map says `off => null` — because seven
  tests had used Antigravity's rows for "a model that cannot be turned off" and "a remembered
  provider that is not the direct one", which are facts about the rules and not about Google.

Regression tests: `ProviderRegistryTest` (8, from the core's side), `ExtensionApiTest` (7, the
hooks, flags and session drive), `AntigravityExtensionTest::testLoadingTheExtensionRegistersTheProviderAndUnloadingTakesItBack`
and `…WithoutTheClientPairAndRefusesOnlyAtSignIn`, the moved `AntigravityApiTest` (now registering
through the registry in `setUp`), `AntigravityOauthTest` (moved out of `OauthTest`),
`AgentSessionTest::testAHookCanChangeTheTermsOfARetryBeforeItWaits` and `…CanCallARetryOff`, and
`InteractiveModeTest::testAnExtensionsSignInIsARowInSlashLoginLikeAnyOther`. Verified live:
`/login` lists Antigravity as `subscription configured` with the extension loaded and not without,
`--list-models antigravity` shows its fourteen rows, `/antigravity.doctor` answers, and a `-p` turn
reaches the real deployment (and was told the quota resets in 16h, which is the deployment's
answer and not pig's).

## A hook with something to say

`sendMessage()`, `appendEntry()` and `registerMessageRenderer()` are the rest of upstream's
hook API, and the first two are opposites that are easy to confuse:

| | Where it goes | Who sees it | What it costs |
|---|---|---|---|
| `sendMessage()` | the conversation | the model, and a person unless `display: false` | context, like any message |
| `appendEntry()` | the session file only | the next run of the same hook | nothing |

`sendMessage()` makes a `Session\HookMessage` — an app message like `BashExecution`, turned
into a user message by `CodingAgent::toLlm()`. It is what a hook uses to tell the model
something the model had no way to find out: a build that just failed, a file that changed
underneath it, a rule about this repository. Its `content` goes to the model **whole** rather
than through `toText()`, because it is the only one of these whose content may be images —
which is how a hook gets a screenshot into a conversation.

`appendEntry()` makes a `Session\CustomEntry`, and the interesting thing about it is where it
is *not*: outside the tree. Every message has a parent, because going back to an earlier point
is what the tree is for, and a note about the session is not a point in the conversation anyone
could go back to. Upstream reads these with a flat scan; so does `customEntries()`.

Five things worth keeping straight:

- **A hook's message is not drawn as a user message.** It reaches the model as one, and
  showing it as one would have someone scroll back and find themselves saying something they
  never typed. `HookMessageComponent` labels it with the hook's own type instead, and the HTML
  export does the same.
- **`display: false` is honoured on screen and in the export**, and nowhere else. A reminder
  injected before every turn is talking to the model; a person who has to scroll past it every
  time stops reading the screen.
- **A note written before the first answer is held back, not dropped.** Nothing is written
  until the first assistant message, and upstream's own example is a `session_start` hook
  noting permissions — which happens before that. So the catch-up flushes held-back notes
  along with the held-back messages, interleaved by timestamp, or the file would read
  messages-then-notes and the common case would be lost entirely.
- **`triggerTurn` is a hook driving the agent.** Idle, it appends and runs; working, the
  message is queued as a follow-up and the flag is ignored, because a message between a tool
  call and its result is a request every provider rejects.
- **Calling either before a session exists throws.** A hook file is read at startup, before a
  mode has wired anything up, and a message that silently goes nowhere is a hook that appears
  to work.

`registerMessageRenderer()` is the same shape as a custom tool's `renderResult` and behaves the
same way: null means "draw it normally" and is not a complaint, a throw or a non-component
falls back **and says so**. One renderer per type, last registration wins, and a later hook
wins a clash — unlike a command clash, which is worth complaining about, two hooks claiming one
message type means one of them is drawing messages it did not send.

A compaction names it — `[Hook build]: …` — rather than folding it in as a user message, or a
summary would read "the user said the build is broken" and send the model looking for a
conversation that did not happen.

`BeforeAgentStartEventResult` still carries text rather than a `HookMessage`: upstream's is one,
and the part that survives into the conversation is the part that reaches the model.

**Each mode wires its own UI**, which is upstream's rule too. `InteractiveMode` builds the
`TerminalUi` and calls `HookRunner::initialize()` and `CustomToolSet::withUi()` itself;
`bin/pig` loads and constructs but wires none of it, because it has no screen to draw a
dialog on and a second mode will have a different one.

`CustomTools\` is upstream's `core/custom-tools/`, on the same loader. A tool lives in a
folder of its own — `~/.pig/agent/tools/<name>/index.php`, or the same under `<cwd>/.pig/tools`
— because a tool is likelier than a hook to want a second file beside it, and a folder is
where that goes. The file returns a factory; the factory returns a `CustomTool` or a list
of them:

```php
<?php // ~/.pig/agent/tools/wc/index.php

use Pig\Agent\AgentToolResult;
use Pig\Ai\TextContent;
use Pig\CodingAgent\CustomTools\CustomTool;
use Pig\CodingAgent\CustomTools\CustomToolApi;

return fn (CustomToolApi $pi) => new CustomTool(
    name: 'wc',
    label: 'Count lines',
    description: 'Count the lines in a file.',
    parameters: [
        'type' => 'object',
        'properties' => ['path' => ['type' => 'string', 'description' => 'the file']],
        'required' => ['path'],
    ],
    execute: fn ($id, $params, $onUpdate, $ctx) => new AgentToolResult(
        [new TextContent(trim($pi->exec(['wc', '-l', $params['path']])->stdout))],
    ),
);
```

Upstream's `CustomTool` is an object literal with five fields and three optional callbacks;
the PHP equivalent of an object literal is a value object built from closures, and the
developer chose that over an interface to implement. `WrappedCustomTool` turns one into an
`AgentTool` — upstream's `wrapper.ts` — and the one thing it adds is the session: `execute`
is given the same `HookContext` a hook gets, which is the reason to write a custom tool
rather than a built-in one. It can read the conversation, see which model is answering, and
stop the run.

Five things worth keeping straight:

- **The declaration validates itself.** A nameless tool, a tool with no description, a
  `parameters` that is not a JSON Schema object — all refused in the constructor, which the
  loader turns into a complaint naming the file. Left to the provider, a bad name fails the
  whole request rather than the tool, and a missing description is a tool the model can
  never choose.
- **A tool may not take a built-in's name.** `bash` is described in the system prompt; a
  custom tool answering to that name would be called in its place by a model that was told
  something else. Named, not dropped, because only the author can fix it.
- **Custom tools are wrapped with the built-ins, not after them.** `CodingAgent::create()`
  concatenates first and hands the lot to `HookedTool::wrap()`, so a `tool_call` hook guards
  a tool somebody wrote exactly as it guards `bash`. A guard that covered only the built-ins
  would not be a guard.
- **The session context is a closure, not a value.** `/model` replaces the model mid-session,
  and a tool that asked which one is answering must not be told about the one that was
  replaced.
- **The UI arrives after loading, not during it.** A factory runs at startup, before there
  is a screen; `CustomToolApi::ui()` answers as `NoUi` until the mode calls `withUi()` on the
  one shared API object every factory closed over. That is why `bin/pig` constructs the API
  itself and hands it to both the loader and the set.
- **`onSession` is fired from the interactive mode, not from the session.** All five moments
  a tool hears about — startup, `/new` and `/resume`, `/fork` and `/clone`, `/tree`, quitting — are
  things someone did in the UI, and the UI is the only place with somewhere to report a callback
  that failed. Routing them through the hook runner was the other option and was worse: `/hooks`
  would then list tool files as hooks. Upstream's `branch` reason is `fork` here, the word its
  session events use for the same thing now.

`--no-tool-files` loads none of them (it was `--no-tools` until that became upstream's "no tools at
all"). Settings name extra entry files under upstream's key,
`customTools`. The system prompt's "Available tools" list stays the built-ins, as upstream's
does: a custom tool reaches the model as a tool definition, which is the part that matters.

**`~/.pig/agent/tools/` holds two unrelated things**, and that is upstream's doing rather than a
choice made here: `ToolInstaller` downloads `fd` and `rg` into it, and custom tools live in
it too. They are told apart by shape — a downloaded binary is a file, a custom tool is a
folder with an entry file — and the discovery glob is `*/index.php`, so neither sees the
other. Worth knowing before wondering why `ls ~/.pig/agent/tools` shows a mixture.

`renderCall` and `renderResult` are ported too: a tool hands back a `Pig\Tui\Component` and
`ToolExecutionComponent` draws that instead of its own tool view — which is what stops a tool
whose result is a table from being shown through formatting built for files and commands. Four
things about them:

- **Two independent halves.** A tool may draw its heading and leave the result to the default
  text, or the other way round. `drawsItself()` is true if either is set, and each falls back
  on its own.
- **A renderer that throws falls back *and says so*, once.** Upstream catches and silently
  draws the default; a picture that quietly turns into plain text is a bug nobody reports. Once
  per call, because `draw()` runs again on every update and a broken renderer would otherwise
  fill the transcript with its own failure.
- **Returning null is not a failure.** "Nothing to draw here" is a legitimate answer and gets
  the default without a complaint; returning something that is not a component is a complaint.
- **`renderCall` gets the arguments as they stand**, which mid-stream may be half a JSON
  object. Every field is therefore optional to the renderer, exactly as it is to the built-in
  headings.

`CustomToolAPI.ui` is ported as `HookUi`, in both `$pi->ui()` and the context handed to
`execute`. Nothing of upstream's custom-tool module is left out.

`Extensions\` is upstream's `core/extensions/`. An extension is a PHP file (or a folder with
`index.php`) in `~/.pig/agent/extensions`, `<cwd>/.pig/extensions` or `<cwd>/extensions` that
returns a callable. It unifies what used to be split between hooks and custom tools: the callable
is handed an `ExtensionApi` (which inherits `HookApi`) and can register commands, tools, event
handlers and message renderers all from a single file or directory.

`extensions/pig-antigravity/index.php` is the first ported full extension, bringing Antigravity's
quota monitoring (`/antigravity.usage`, `/antigravity.models`), account management
(`/antigravity.accounts`), doctor diagnostics (`/antigravity.doctor`), model catalog refresh
(`/antigravity.refresh`), image command (`/antigravity.image`), and the `generate_image` agent tool.
All extensions in pig are pure PHP implementations without Node.js or npm dependencies.

`extensions/pig-web-search/index.php` provides real-time web search via DuckDuckGo HTML scraping
(`web_search`), readable page text extraction (`fetch_web_page`), and `/search <query>`. Future
headless browser or Puppeteer / CDP scraping extensions belong in this extension, preserving core
agent minimalism.

## MCP servers, and the tool that arrives after startup

`packages/mcp/` is upstream's `pi-mcp` package (`Pig\Mcp`) and `extensions/pig-mcp/` is its
built-in `mcp` extension, both ported file for file; `docs/mcp.md` upstream is the specification
for what the config file means. Two things are pig's own and both are consequences of the platform:

- **`codemode` is ported, in PHP.** This bullet used to say it could not be, because upstream's
  is a QuickJS VM in wasm and PHP has no wasm runtime. That was true of the *sandbox* and not of
  the *feature*, which is "the model writes code that calls tools and filters the results, and
  only what it keeps reaches the model". See the section below.
- **`deferred` has no loadout under it.** Upstream keeps an active set per tool and records a
  `tool_search` load in the transcript, so a loaded tool survives `/tree` and resume. pig has no
  such record: a deferred tool is simply **not registered** until `tool_search` names it, then
  registered like any other, through the same `adopt()`/`onChange()` door a connecting server uses.
  `tool_search` itself is **`extensions/pig-tool-search/`'s**, as upstream's is the built-in
  `tool-search` extension's, not the MCP extension's: pig-mcp lists each deferred tool in the
  static `Pig\Codemode\DeferredTools` (description and schema for the search, the server as its
  namespace, a `load` closure that registers it through pig-mcp's own API, and `loaded`), and
  pig-tool-search registers `tool_search` while that list is non-empty and loads the matches
  through it. It stands in for upstream's `getAllTools()` by exposure plus `setActiveTools()`,
  which pig's loadout cannot express. Both are built-ins, so they load together; one without the
  other leaves deferred tools unreachable (`--no-extensions -e builtin:mcp -e builtin:tool-search`).
  The cost is that a resumed session starts with the deferred tools unloaded and the model searches
  again; the gain is no second copy of what the agent's tools are. `ToolSearch` is upstream's
  `tool-search/tool.js` arithmetic for arithmetic — tokenizer, stemmer, Okapi BM25 — and the one
  thing worth knowing about it is that **`GitHub` tokenizes to `git hub`** there too (every
  lower-to-upper boundary splits), so a server named `github` is found by its name and not by the
  word inside a camelCased tool.
- **A tool loaded mid-run has to reach the *next request of that run*.** The first live try had the
  model call `tool_search`, be told `Loaded 8 tools. They are available from your next call`, call
  one, and get `Tool mcp__fs__read_text_file not found` — five times, then give up and read pig's
  source with `bash` to find out why. `AgentLoop` took a snapshot of the tools when the run started;
  `Agent::setTools()` changed the state and not the snapshot. `AgentLoopConfig::$getTools` is asked
  before every model call now, which is where upstream refreshes `context.tools` from
  `agent.state.tools` in `prepareRequest`. `AgentTest::testAToolRegisteredDuringARunReachesTheNextRequestOfThatRun`
  is the mutation's one kill.
- **A tool that arrives after startup needed a door the loader did not have.** `ExtensionLoader`
  copied `$api->tools()` once; an MCP server's tools exist only once it has connected, in a fiber,
  later. So `ExtensionApi::registerTool()` notifies after load and `removeTools()` is beside it,
  `CustomToolSet::adopt()` follows one extension's list, and `onChange()` is what re-sets the
  agent's tools — in `CodingAgent::session()` and in `InteractiveMode::reload()`, the two places
  that build the set, with the built-ins and `HookedTool::wrap()` exactly as at startup. That last
  clause is what makes the permission gate reach an MCP tool, and it was watched doing so.
- **`command`, `args`, and `cwd` expand `~` automatically** (`Paths::expand()` via `ServerConnection::transportFor()`).
  Configurations in `mcp.json` can use `~/` instead of hardcoding absolute home directories, keeping
  configs clean and portable across machines.

**And the first real server found a provider bug of its own.** The TypeScript MCP SDK writes
`"$schema": "http://json-schema.org/draft-07/schema#"` on every tool; Gemini's `parameters` is an
OpenAPI 3.0 schema and answered `Unknown name "$schema"` for every request — a 400 for the whole
session from the moment the server connected. Upstream has `sanitizeForOpenApi()` in
`google-shared.ts` and it had never been ported, because no built-in tool carries a
meta-declaration. The lesson is the one about fixtures that agree with the code: every tool schema
in pig's tests was written by pig. The direct Gemini API has since moved to upstream's
`parametersJsonSchema` (full JSON Schema, sent as written — `GoogleShared::convertTools()`); the
stripped `parameters` is what the Code Assist path (`GoogleShared::tools()`, `pig-antigravity`)
still sends. Regression tests: `GoogleTest::testTheCodeAssistToolsStillStripJsonSchemaMetaDeclarations`,
`testTheDirectApiSendsTheSchemaAsWrittenInParametersJsonSchema`.

**An extension registers a server with `$pi->registerMcpServer($name, $config)`**, upstream's
API with upstream's validation (`McpServers::validate()`, which `McpConfig` and `pig mcp` use
too — one answer to what a config is). The static `McpServerRegistry` holds them for
`Models::register()`'s reason; `unregisterMcpServer()` takes one away only from the extension that
registered it. Servers registered while the extensions load are read by `pig-mcp` on
`session_start` through `$pi->getMcpServers()`; one registered later reaches it as
`mcp_servers_change`, emitted in an `Async::spawn` because handlers await and a factory registers
at load, outside any fiber. A registered server **overrides a configured one of the same name for
the session** (`ServerEntry` scope `extension`; the manager says so and writes nothing to the
file for it), and a server no extension connects is reported once, as upstream reports it.
`/reload` resets the registry so the reloaded extensions register afresh.

**The manager is one component whose contents are swapped**, and its `menu()` parks the caller on
a `Deferred` the way every hook dialog does — which is what lets `$manage` read as upstream's loop
over menus (`for (;;) { name = await ui.menu(servers); for (;;) { action = await ui.menu(server) } }`)
with no state machine. A menu rebuilds in place when a server's state changes: `ServerConnection`'s
`onChange` fans out to whoever `subscribe()`d, and `menu()` keeps the selection where it was so a row
changing under the cursor does not move it. Two things found by driving it through a `FakeTerminal`:
the tool is back *before* the menu is redrawn when a server is re-enabled, so a key typed in that
window lands on a status screen that takes none (the test waits for the menu, not the tool); and
`registerTools()` diffed against the names it owned rather than the names it had declared, so
switching a connected server to `deferred` left its tools on the model — the Fixed entry above.

**OAuth is `Pig\Mcp\Oauth` plus `McpOauth`**, split as upstream splits `pi-mcp/oauth` from
`extensions/mcp/oauth.js`: the package knows the protocol (discovery, registration, the flow, the
callback server, the provider's state record) and the extension knows the file, the connection
and the dialogs. Four things decided on the way:

- **`OauthCallbackServer` is not `Pig\Ai\Utils\Oauth\CallbackServer`.** That one's port is a
  constant registered with Google; this one listens on whatever is free, because the redirect URI
  is registered with each MCP server's authorization server at sign-in, and matches a callback to
  the sign-in waiting for it by `state`. Two servers for two different facts.
- **The pasted-URL prompt races the callback**, which is why `HookUi::input()` grew a `$signal`:
  without one the dialog stays open after the browser already answered, and in the first live run
  it did. `NoticingUi` parks on the signal for the same reason — a test UI whose `input()` answers
  null at once would read as "the person cancelled" before the browser got a turn.
- **A 401 is `needs-auth` only for a server that *uses* OAuth** — an HTTP server with no
  `Authorization` header of its own. One *with* a header that answers 401 has a wrong header, and
  saying "sign in" would send somebody to a browser for a typo.
- **The cross-process refresh lock is `FileLock`**, a lock file per server beside `mcp-auth.json`
  as upstream keeps one — `flock()` where it takes `proper-lockfile` — and the tokens are read
  again once it is held, so a pig that arrives second finds the first's and sends nothing. See the
  `Auth` entry on renewing under a lock.

**The resource tools are one set with one exposure**, upstream's rule: the widest among the
servers that have resources. With pig's deferred-is-not-registered arrangement that means
they go into `DeferredTools` under a pseudo-source `mcp resources` when no resource server is
direct, and `tool_search` lists that source beside the servers. `tool_search` follows the list
on every change (`DeferredTools::onChange()`), so there is no sync step to order any more; what
is ordered is that a deferred tool already loaded (or pending from a resumed transcript) is
registered **before** it is listed, so `tool_search` is re-registered after it and stays last.

**Nothing of upstream's MCP extension is left out now**, codemode aside, and the live checks were
`server-filesystem` (stdio, tools), GitHub (streamable HTTP, 46 tools with a header; OAuth
discovery to the DCR refusal without one) and `server-everything` (resources, templates, the log).

**And a copying mistake cost an hour of looking in the wrong place.** The live run after
resources landed had the model say `list_mcp_resources` was not a tool it had, and every probe of
the code said it was registered. The extension loaded in that run was the copy under
`~/.pig/agent/extensions/pig-mcp/`, three files stale — `cp -r extensions/pig-mcp dest/` had been
the sync and it does not replace a directory that exists. `rsync -a --delete` is the sync now.
*When the code and the behaviour disagree, check which code ran before reading either again.*

**GitHub's MCP server cannot be signed into with dynamic registration**, verified live:
`github.com/login/oauth`'s metadata has no `registration_endpoint`, so `pig mcp login gh` says
`Authorization server does not support dynamic client registration` — correctly — and the way in
is a pre-registered client in the `oauth` block, or a token in the header. Upstream's docs say the
same; worth knowing before reading the refusal as a bug.

`pig mcp` is `McpCli`, beside the extension and required by `bin/pig` from there, because what it
reads and writes is the extension's file and nothing in a package knows the shape. Its tests found
the final-method trap again — `private function run()` in a test class, two entries below this
one — which is worth the sentence: *the sweep in the shim refuses it now, and I wrote it anyway.*
And then `status()` in the next test file. The names PHPUnit owns that read as natural test-helper
names: `run`, `status`, `output`, `any`, `count`, `name`, `result`, `toString`.

`McpExtensionTest` drives the whole thing through `ExtensionLoader::load()` with the stdio fixture
server (`packages/mcp/test/fixtures/stdio-server.php`): session start, the ten-second wait, a
server that fails, the startup report, a call through the connection, `/mcp`, a deferred tool
appearing only after `tool_search` finds it, and shutdown leaving the loop idle. Two of its first assertions were guesses that the code refuted — the middle cut's
byte count and a server with no exposure named reporting `(deferred)` — which is the usual cost of
asserting before measuring.

Audited difference by difference against `wrapper.ts`, `types.ts` and `loader.ts`. `wrapper.ts` is
`WrappedCustomTool` line for line, argument order included. What the rest found is one bug — the
`-p` context, below — and a row of places where pig is stricter with a reason already written
beside it: the declaration validates itself where upstream accepts a nameless tool with no
description, `hasUi` is derived from the UI rather than passed beside it as a second fact that can
disagree, and the dedupe is on `realpath()` where upstream's is on `path.resolve()`. Two things
were **checked rather than assumed** and turned out fine: `glob('*/index.php')` does follow a
symlinked tool folder, and `glob('*.php')` does match a symlinked hook file — upstream tests
`isDirectory() || isSymbolicLink()` explicitly, so the question was whether pig's globs quietly
skipped what that clause exists to catch. They do not. Left out on purpose: upstream's
`isBunBinary` branch, which is a second loader for a packaging mode pig has no equivalent of, and
`setUIContext`'s `hasUI` parameter for the reason above.

## codemode: the model writes PHP, and a child `php` runs it

`packages/codemode/` (`Pig\Codemode`) is upstream's `pi-codemode` and `extensions/pig-codemode/` is
its `extensions/codemode`, with three substitutions that follow from the one fact that PHP has no
wasm runtime and will not take one as a dependency:

| upstream | pig | why |
|---|---|---|
| the script is JavaScript in a QuickJS VM in a `worker_thread` | the script is PHP in a child `php -n` | the only sandbox PHP can make without a dependency is a process |
| `declare const tools: { read(input: {...}): Promise<string> }` (TypeScript) | `$tools->read(array{path: string} $input): string` (PHPStan shapes) | the notation PHP code already carries in its docblocks |
| `await Promise.all([...])` | `parallel([...])`, each arm a `Fiber` in the child | no `await`; the arms suspend on their first tool call and the driver sends every call before reading any answer |

**What the isolation is, in one paragraph, because it is the thing to be honest about.** `Sandbox`
starts the child with `-n` (no `php.ini`), `disable_functions` naming every process, file, stream,
socket, network and host-reading function in core and the usual extensions, `open_basedir` pointing
at the child script alone, a 256MB `memory_limit`, `allow_url_fopen=0`, and an empty `PATH`.
`eval` and `include` are constructs and cannot be disabled — the child uses `eval` to run the script,
and `open_basedir` is what stops `include`. That is a **blacklist**, and upstream's wasm is a
whitelist: a function the list forgot is a function the script can call. It is a sandbox for a
model's mistakes and not for an adversary's, and `DISABLED_FUNCTIONS` is where the next row goes.
Measured rather than argued: `file_get_contents`, `shell_exec`, `getenv`, `fsockopen`, a variable
function name, `SplFileObject`, `include` and `ini_set` are all refused, by `undefined function` or
`open_basedir restriction`; the ordinary language is all there.

**The wire is one JSON line per message**, both ways: `boot`, then `call`/`global`/`output` from the
child and `result` from the host, then `done`. The host answers each `call` in an `Async::spawn` of
its own, which is what makes `parallel()` parallel on the host side; measured, three 0.2s tools in
0.2s. The child's side of `parallel()` is `__CodemodeParallel`: an arm that calls a tool does
`Fiber::suspend($id)` with the request already written, the driver starts every arm, then reads
answers off the pipe and resumes whoever waits for each. An arm that calls two tools in sequence has
its second call made after the first answer, like `await` inside a `Promise.all` arm. **Keys are
kept** — `parallel(['a' => …, 'b' => …])` answers under `a` and `b` — which `Promise.all` has no
case for and the first live script written against this used.

**`exit_script()`, not `exit()`.** PHP's `exit` would end the sandbox with no `done` on the pipe,
which the host reads as a crash. The rest of the helpers keep upstream's names: `text()`, `image()`,
`store()`/`load()`, `ALL_TOOLS`, `search_tools()`, `describe_tool()`; `echo` is `console.log`.

**Nested calls go through the hooked tool**, so the permission gate reaches `bash` from a script
exactly as from the model — `testANestedCallGoesThroughTheToolCallHookLikeAnyOther` runs a `tool_call`
guard against `$tools->bash()` inside `parallel_settled()` and reads the refusal out of the arm. A
`codemode`-exposed MCP tool is the exception by design: it is **not on the agent at all** (the model
must not see it), so the MCP extension puts it in `Pig\Codemode\Registry` and the codemode tool
calls it from there. The registry is static for `Models::register()`'s reason — two extensions in
either load order have to see one list — and registering into it is what activates codemode
(upstream's `ensureDiscoveryActive()`: it only ever activates, never deactivates).

**codemode is registered inactive, as upstream's (`defaultActive: false`).** `CustomTool::$defaultActive`
is upstream's `ToolDefinition.defaultActive`; `ToolLoadout::isActivatedByDefault()` keeps such a tool
off unless the run named it (`useNamed()`): an entry of `--tools` matching it by name or pattern, or
the resolved `defaultTools` list with its `+name` modifiers. So `--tools read,codemode`, `--tools
+codemode` and `"defaultTools": ["+codemode"]` turn it on; there is no `codemode.enabled`.

**A tool with something to say in the system prompt can say it now.** Upstream's `promptSnippet`
and `promptGuidelines` were not ported — the paragraph on custom tools above says "the system
prompt's Available tools list stays the built-ins" — and codemode is the tool that needs them:
without "Use codemode to batch or chain several tool calls…" in the Guidelines, a model knows the
tool exists and issues one tool call at a time anyway. `CustomTool::$promptSnippet`/`$promptGuidelines`,
`CustomToolSet::promptContributions()`, two parameters on `SystemPrompt::build()`, and the prompt is
rebuilt in the same `onChange()` that rebuilds the tools.

**Three of upstream's later safeguards are here too** (pi-codemode 0.99.2, 1.0.1, and
`execute.ts`'s `saveImages()`), found by reading the two trees side by side after the port had
settled: an **output limit** — `__CodemodeBridge::output()` counts characters and items against
upstream's 16 MiB / 100,000 and fails the script, reporting `done` *before* the throw so a `catch`
cannot resume output and the host kills the child; the `ob_start` handler catches its own copy,
because an exception out of an output handler is not something PHP promises to carry. An
**`image()` that reads the type off the bytes** — base64 checked, line breaks dropped, the four
signatures matched as base64 prefixes, the declared mime ignored — because a provider refuses the
whole request over one bad image and an image block is resent on every later turn. And **every
shown image saved to `pig-codemode-*.<ext>` with a `[Image saved to …]` line in front of it**,
after the truncation so a path is never cut, once per distinct image; a write that fails becomes
the label rather than losing a result whose tool calls already ran, which is upstream's reason and
the one place in the extension a failure is worded rather than thrown. The signature check is what
the old `base64_encode("\x89PNG")` fixture tripped over: four bytes is not a PNG signature, and
the test now carries the eight-byte one. Regression tests: `CodemodeTest::testAnImageThatIsNotOneIsRefusedByName` (eight
rows), `testWrappedBase64IsStraightenedAndAJpegIsDetectedAsOne`,
`testOutputPastTheLimitFailsTheScriptAndKeepsWhatCameBefore`, and the path assertions in
`CodemodeExtensionTest::testAToolResultWithAPictureInItReachesTheScriptAsABlockImageCanShow`.

**The rest of upstream's codemode surface followed in one batch**, the developer's call after a
side-by-side read. Six items, and where each lives: the **`models` namespace** is `$models` in the
script (a second parameter of the eval'd function, `__CodemodeModels` bridging to
`models.<member>` globals on the host) — catalogue, `classify()` and `generateImages()` over
`Models::findOfType()`/`classify()`/`generateImages()`, upstream's context checks word for word
(pig's `ClassifierContext` has no images, so `images` is refused by name), a four-slot limiter on
`Deferred`s, one call row per model call with its cost, and the combined usage on the result's
`usage` (see (1) below). **`codemode.mode: on|only`** is `CustomTool::$prepareLoadout`
(upstream's `ToolDefinition.prepareLoadout`), asked by `ToolLoadout::apply()` after the hook wrap
with the whole active list: it answers descriptions (`DescribedTool` is the tool with its
description replaced, a decorator for `HookedTool`'s reason) and names to hide from the agent.
`on` appends `Codemode: $tools->x([...]) resolves to …` to every declared tool — upstream's
`describeOutput()` of the tool's `outputSchema`: "a string" without one, the field list for an object
(`bash`'s `{ output, truncated, full_output_path?, exit_code, wall_time_seconds }`). A call to a tool
with an output schema resolves to its `structuredContent`, an error result included (upstream's
`toScriptValue()`); `only`
hides them all and lists them in codemode's description instead — and the extension **keeps the
list it was handed** (`$callable`), which is the only way a hidden tool is still reachable from a
script. **`describe_namespace()`** and `search_tools()`'s namespace filter go through
`isNamespaceName()`, upstream's four spellings. **Output layout** is upstream's `formatOutput()` +
`joinAdjacentText()`: the sandbox marks what `echo` wrote as `console`, several text items get
`==> text N/M <==` heads, console lines close the output in one `<console_output>` block. **The
nested call id** is `<codemode call id>/<n>` and is what the hooked tool, and so the `tool_call`
hook, is handed. **Grammar sampling** was already in `pig/ai`; `Source::GRAMMAR` is upstream's Lark
byte for byte and the tool declares it. Two things to keep: `$mode` reads `$settings` **by
reference** — an arrow function would have captured the null it was built with, since
`session_start` fills it later — and `prepared()` runs for active custom tools only, so a loadout
that switched codemode off asks nothing. Regression tests: `CodemodeExtensionTest`'s six new
cases (`…OnModeTellsEachTool…`, `…TextItemsAreNumbered…`, `…DescribeNamespaceFinds…`,
`…ModelsNamespaceAnswers…`, `…AsksForGrammarSampling…`, and the id assertions in the hook case)
and `CodemodeTest::testWhatEchoWroteIsMarkedAsConsoleOutputAndTextIsNot`,
`testTheModelsNamespaceAndDescribeNamespaceReachTheHost`.

**Three of the four differences that read left went next**, the developer's call ("4 不管, 1 2 3
做一下"; the fourth, upstream's blacklist sandbox, stays out — pig's child process is the
sandbox). (1) **A tool's usage is billed.** `AgentToolResult` and `AgentError` carry `?Usage
$usage` (upstream's `AgentToolResult.usage`), `AgentLoop::toolResult()` puts it on the
`ToolResultMessage` (a new last constructor parameter, written and read by `MessageJson` as
`usage`, kept by `SessionManager`'s content rewrite), and `AgentSession::stats()` sums tool
results beside assistant messages — upstream books it under "Tools/summaries", and `/session` shows
one cost line per physical model plus that line (`UsageCostBreakdown`, upstream's
`getUsageCostBreakdown()`). Codemode returns
its `$models->*` usage, and what a nested tool reported, as that; `details.usage` stays for the
RPC port. The `AgentError` field is why a *failed* script still bills what it spent before
failing. (2) **Classifiers take images.** `ClassifierContext::$images` (a list of `ImageContent`)
is consumed by one API, upstream's `openai-decisions`, so that provider came with it:
`Providers\OpenAiDecisions` (POST `/decisions`, questions mapped to choices/levels/predicate,
the state as a JSON string or one user message with the images, 128 images at most, 504 not
retried and explained, a refusal failing the result but keeping the usage) with the hand-kept
`openai/gpt-6-luna` row — the first classifier row with a long-context **tier**, so
`classifierTable()` rows learned an optional `'tiers'` key and `scripts/generate-models.php`
writes it. `Models::classify()` refuses images for a model whose `input` lacks `image` before any
provider is reached (upstream's `assertClassifierInputSupported()`); System One and llama.cpp
refuse them by name. The codemode context check builds the blocks from `read`'s image shape, so a
read result passes straight through. Not ported: upstream hides `gpt-6-luna` when openai is
signed in through ChatGPT OAuth; pig lets the API's own 401 say so. (3) **Nested calls go through
the session.** `AgentSession::executeNestedTool()` (upstream's `_executeNestedToolCall()` +
`NestedToolCallRunner`) is handed the hooked tool and the id from `nestedToolCallId()` —
`<parent>/<n>`, counted per parent and cleared at `agent_end` — checks the arguments with
`ToolArguments::validate()` so a script that sends `amount: "lots"` reads the message the model
would, turns a throw into an error result, and sends `tool_execution_start/update/end` through
`onAgentEvent()` with the new `?string $parentToolCallId` on the agent-core events, the hook
events and the RPC encoding (spread in only when set, as upstream does). The TUI's `onToolStart()`
returns on a parent id, upstream's "shown inside their parent's row"; update and end already
found no row. `codemode`'s `$modelUsage`/`$combineUsage` moved above the sandbox-tool closures
that now capture them. Not ported: upstream's bounded `nestedCalls` record on the tool result
message (codemode's `details.calls` lists them) and `parentToolCallId` on the `tool_call` and
`tool_result` hook events (the id's `/` says as much). Regression tests:
`OpenAiDecisionsTest` (twelve cases, upstream's `openai-decisions.test.ts`),
`CodemodeExtensionTest::testANestedCallIsCheckedAgainstTheSchemaReportedUnderItsParentAndBilledToTheScript`,
the image rows in `…ModelsNamespaceAnswers…`, `SessionCodecTest::testAToolResultKeepsWhatTheToolSpentOnModels`,
the tool-result half of `AgentSessionTest::testStatsCountMessagesToolCallsAndTokens`, and
`RpcModeTest::testANestedCallsEventsNameTheirParentAndAResultCarriesWhatTheToolSpent`.

**The first full run on the Mac then failed eleven tests this batch had not touched, and one it
had.** The one was `GenerateModelsTest`'s `classifiers 8 models`, now 9 with the Decisions row.
The eleven were ten `AntigravityCatalogTest` cases and
`AntigravityExtensionTest::testRefreshingTheCatalogWithoutAnAccountSaysToSignInFirst` — all of
them green in the container and red on the one machine that has run the extension for real.
`AntigravityCatalogTest` set `PI_HOME` alone, and `Catalog::read()` looks at **pig's own**
`~/.pig/agent/models-store.json` first, so the Mac's real sixteen-model catalog answered where the
fixture's one model was expected; the refresh case set `PIG_CODING_AGENT_DIR` alone, so `Auth` read the real
`~/.pi/agent/auth.json`, found a key, and the "no credentials" command refreshed for real — the
`WARNING … no endpoint available` line in the run's output is that request. *The same shape as
the `fd` tests that passed only where pig had never fetched one*: a test that isolates one of two
homes is isolated on the machines that have nothing in the other. Both set both now, and **not to
one directory** — `read()` iterates pig's store and then pi's, so a shared home reads the fixture
twice and every "named once" assertion counts two. The twelfth, `ThemeWatcherTest`, passed alone
and passed in the same filter once those ten were green; a loop left mid-poll by ten failures
before it is the likely cause, and it is noted rather than chased.

**Two things the first live runs taught.** The model reads `$res['content'][0]['text']` *and*
`$res['structuredContent']['content']` and does not know which the server fills, so a script
receives the whole `CallToolResult` (upstream's rule for a tool with an output schema) and the
declaration says so. And the second script it wrote keyed its `parallel()` arms by path, which is
the keys bullet above.

**`AgentError` carries `$details`** so a failed script still shows the UI its nested calls — upstream
returns `{ isError: true, details }` where pig throws, and the renderer needs the calls either way.

**And `HookRunner::setSession()` had no caller.** `$ctx->session` was null in every handler in
production, which is how long a documented `HookContext` field can stay wired at one end: until the
first extension that needs it. `AgentSession` attaches itself now.

Regression tests: `CodemodeTest` (24 — source, identifiers, declarations, the sandbox's isolation,
`parallel()` timing and ordering, error kinds, store, images, globals) and `CodemodeExtensionTest`
(7 — through `CodingAgent::session()` with both extensions: activation by server and by setting, the
system prompt, a script against an MCP tool, the hook guard, a failed script's error, the store in
the session file, the output budget). Verified live with `server-filesystem` at the default
exposure: the model sees `read, bash, edit, write, codemode`, writes a `parallel()` over four
`read_text_file` calls, and answers from the script's return value.

## A closure read `$pi` it never captured, and the warning was swallowed by its own `catch`

Reported as `PHP Warning: Undefined variable $pi in …/smart-session.php on line 486`. Every
extension handler is `static function (…) use (…)` — the shape `/reload` requires — and `$pi`
exists only in the file's outermost closure, so a handler that reads it without `use ($pi)` is
reading nothing. Two sites: `session_start` read `$pi->getSessionName()` (null, so the default
branch ran and nobody noticed) and the auto-namer called `$pi->setSessionName($title)` inside a
`catch (Throwable) { /* Silent fallback */ }` — **so the warning printed and the title was never
set**, which is a feature failing on the exact line that made it worth having. Both are `$ctx->…`
now; `HookContext` had the two methods for precisely this.

**`uncapturedClosureReads()` in `test/lint.php` is the sweep**, one token over again from the
property check: for every closure, a variable read in its body that is not a parameter, not in its
`use` list, not `$this` (unless the closure is `static`), not a superglobal, and not assigned
anywhere in that body is reported with its line. "Assigned" is `=` and the compound operators,
`foreach … as`, destructuring, `catch (T $e)`, `static`/`global`, a `use (&$x)` on a nested closure
(by-ref capture springs into existence as null), and **a variable whose first appearance is as a
call argument** — `preg_match($re, $s, $m)` and `exec($cmd, $out, $code)` assign through a
reference and nothing outside the callee says so. That last rule is the deliberate blind spot: a
plain `f($undefined)` is not flagged. Arrow functions are skipped (they capture the enclosing
scope); nested closures are each checked in their own scope.

Over 618 files it found the one site and nothing else — after three rounds of false positives
that were each a syntax shape the first version did not know: `catch (\Fully\Qualified $e)`
tokenizes as `T_NAME_FULLY_QUALIFIED` and not `T_STRING`, `foreach ($rows as [$s, $e])` puts the
targets inside brackets, and a nested `use (&$acc)` is an assignment and not a read. Putting the
`$pi` back turns it red on line 486. **`lint.php` now also takes directories on the command line**
— `php test/lint.php ~/.pig/agent/extensions` — because the extensions a person wrote load into the
same process and fail the same ways, and `extensions/` in the repository is swept by default; it
was not before, which is how three `@` suppressions had survived in it.



Reported as *"任务还没完成，但是总是偶发触发系统通知"* — the desktop notification extension firing
mid-task, intermittently. Intermittent because it fired **once per failed attempt**: on a turn that
hit a 429 and retried, every attempt ended a run, and `agent_settled` went out beside `agent_end`.

`tellHooks()` emitted both on `AgentEndEvent`, with upstream's two names and pig's one moment.
Upstream's are two moments: `agent_end` is the end of a *run* and comes from the agent loop;
`agent_settled` comes from `_emitAgentSettled()`, after `_runAgentPrompt()`'s `while` loop over
retries and continuations has nothing left to do, and the docs call it "final and notification-only;
use it when an integration needs to know Pi will not continue automatically". pig's equivalent of
that loop is `runAgentPrompt()`'s post-run loop, and its `finally` is the one place every ending of
a prompt reaches — a clean run, the last retry, a retry escape stopped, a compaction that worked or
did not. So that is where `emitAgentSettled()` is called, exactly once per prompt, and it tells the
hooks before it resolves the idle wait, so a `-p` exiting on `prompt()`'s return has let the hook run.

Two things worth keeping:

- **Two event names emitted from one line are one event.** The port had both names, which is what
  the audit's name-by-name sweep checks, and the difference between them was the *when*, which it
  does not. A hook listening on either got the same thing, so nothing could tell them apart until
  something that cared about the difference — a notification — listened.
- **The user's own extension was on `agent_end`**, which is the right event for "a run ended" and
  the wrong one for "the task is done", and pi's own `system-notify.ts` is on `agent_settled`.
  Both `system-notify.php` and `smart-session.php` are on `agent_settled` only now; the latter had
  been on both and ran its title generation twice per prompt.

Regression tests: `AgentSessionTest::testAgentSettledFiresOnceAPromptAndNotOncePerAttempt` — three
runs, one settle, red on the old emit — and `testAgentSettledStillFiresWhenNothingWasRetried`, so the
fix cannot become "only after a retry".

## What pi 1.0.0 changed about codemode and MCP OAuth, and the one thing left out

The anchor is `d0a4c37`, and codemode and MCP were ported from upstream's HEAD at the time, so
upstream's 1.0.0 release notes were read against the port for the three items that touch it. Taken,
with the arguments:

- **The lean description.** pig's `codemode` description spelled every helper out — ~830 tokens
  before a single nested tool was listed — where upstream 1.0's is the intro, one line per global,
  and a pointer to `docs/codemode.md` "which the model reads when it needs it". pig has no `docs/`
  in an install, so the reference is `extensions/pig-codemode/CODEMODE.md` and the description
  names its absolute path. ~300 tokens now; with two MCP tools listed 929 → 395. The guideline and
  the snippet are upstream's shorter ones, and the model still wrote a keyed `parallel()` script on
  the first live try. **A description is read on every request and a reference is read once**, which
  is the whole of the argument.
- **Recovery errors.** `__CodemodeTools::__call()` names the close matches the way upstream's
  `guard()` proxy does — exact after case and punctuation are stripped, then containment, then the
  whole list when it is short. The store's size errors say what the store is for. And writing the
  test for the store found that **`RangeError` is not a PHP class**: both limits threw
  `Class "RangeError" not found` on the day they fired, which no test had reached. `LengthException`.
- **MCP OAuth.** Credentials keyed `mcp__<name>|<url>` with the URL-only legacy entry taken over by
  the first server that loads it (`stateOf()` moves it under the new key, `tokens()` only peeks,
  `remove()` takes either); the RFC 9207 `iss` check in `Flow::run()` before the code exchange, with
  a metadata flag `authorization_response_iss_parameter_supported` making a *missing* `iss` a
  refusal too; `Flow::stepUpScope()` so an `insufficient_scope` sign-in asks for granted plus
  missing, and `withScope()` recording the requested scope on a token response that names none
  (RFC 6749 §5.1), without which there is nothing to keep; `optionalString()` reading `''` as
  absent; and `oauth.authServerMetadataUrl`, read every time and never cached.

**Left out: the sandbox's `models` namespace** (`models.getModelOfType()`, `models.classify()`,
`models.generateImages()`), and the reason is scope rather than difficulty. Upstream's runs the
session's credentials through the catalogue's image and classifier models, with the usage added to
the tool result. The layer under it is in `pig/ai` now — `Models::findOfType()`/`allOfType()`,
`Models::classify()` and `Models::generateImages()` over the `openrouter-images` and System One APIs
(see the trap on classifiers and image models) — so what is missing is the namespace and its
argument checks, docs and image-result handling in `pig-codemode`. Meanwhile a script reaches image
generation as `$tools->generate_image([...])` from `pig-antigravity`. The developer's call was to
take the three above and decide this one on its own.

Regression tests: `OauthTest` (seven new — the `iss` triple, scope recorded and empty, `stepUpScope`,
the metadata URL), `McpExtensionTest::testAStepUpSignInAsksForTheGrantedScopeAsWellAsTheMissingOne`
(red on the old merge), `CodemodeTest::testAToolThatDoesNotExistNamesTheCloseMatches` and
`testAnOversizedStoreValueSaysWhatTheStoreIsFor`, and the description-length assertion in
`CodemodeExtensionTest`.

## A stranger's `.pig/` is not loaded until somebody says so

`ProjectTrust` is upstream's `trust-manager.ts` + `project-trust.ts`, and the reason it exists is a
sentence this file already had: *"a hook runs in this process with this process's permissions …
one that calls `exit()` takes the session with it."* True of `~/.pig/agent/hooks`, which the person
wrote — and equally true of `<cwd>/.pig/hooks`, which whoever pushed to the repository wrote. Until
this, `git clone` and `cd` were the whole distance between a stranger's PHP and `require`.

The shape, all of it upstream's: `~/.pig/agent/trust.json` keyed by absolute path, **nearest
ancestor wins**; a project with no `.pig/` resources is trusted without a question; a saved answer
wins; otherwise ask; and with nobody to ask — `-p`, `--mode json|rpc` — **the answer is no.** The
last is the only safe direction and the one worth stating: a script on a stranger's repository must
not run that repository's hooks because there was no terminal to refuse on.

Four things pig's arrangement decided:

- **It is asked before `Settings::load()`**, because `.pig/settings.json` is one of the things
  being asked about (`ProjectTrust::RESOURCES`: `settings.json`, `mcp.json`, `hooks`, `tools`,
  `extensions`, `skills`, `prompts`, `themes`, `SYSTEM.md`, `APPEND_SYSTEM.md`, plus a top-level
  `extensions/` and `.agents/skills` here or in any ancestor — upstream's list, with pig's `hooks` and
  `tools` kept and its old `commands` gone; `shellPath` names what every command runs in). So `bin/pig` resolves it first
  and the prompt's palette comes from the person's own settings alone — `Settings::load($cwd,
  projectTrusted: false)` — since the project's are exactly what is not yet allowed in.
- **The prompt is `Cli\TrustPrompt`, a `SessionPicker`-shaped screen** started and stopped inside
  the call, because the question gates what `CodingAgent::session()` may `require` and so cannot be
  asked from inside the session it gates. Upstream asks through its extension UI context during a
  two-pass bootstrap; pig has no screen at that point and this is the one.
- **One flag, every loader**: `projectTrusted` on `Settings`, `HookLoader`, `CustomToolLoader`,
  `ExtensionLoader` (both of its project roots), `PackageManager::resolveLocalResources()` (skills,
  prompts, themes, the ancestors' `.agents/skills`) and `SystemPromptFiles`. Each one *does not open*
  the directory, rather than reading and discarding — a broken `.pig/settings.json` in an untrusted
  project is not even a complaint. `ProjectTrustGatesTest` has one case per loader, because a gate on
  four of five is the first shape from the index.
- **Not gated**: `AGENTS.md`/`CLAUDE.md` (text the model reads, not code pig runs — upstream does
  not gate them either), the person's own `~/.agents/skills` even when the working directory is the
  home directory, and `--list-models`/`--export`, which print and exit without
  loading anything of the project's and so are not asked.

`/trust` saves a decision for *next* time and says to restart: what was `require`d cannot be
un-required, and what was left out cannot be loaded half way through a session. The "this session
only" choices exist at startup and are filtered out there, because this session is already decided.
No file lock where upstream takes one through `proper-lockfile`: the write is a rename, so a reader
never sees half a file, and two pigs saving in the same instant is a race over one JSON object that
the later write wins.

Regression tests: `ProjectTrustTest` (16), `ProjectTrustGatesTest` (6), `TrustPromptTest` (3),
`CodingAgentSessionTest::testAnUntrustedProjectWithResourcesSaysSoAndLoadsNoneOfThem`, and the two
`/trust` cases in `InteractiveModeTest`. Verified live: `pig -p` in an undecided project with a
hook prints the warning on stderr, the answer on stdout, and loads no hook.

## Built-in extensions

Upstream's four (`extensions/index.ts`) are pig's folders: `builtin:llama.cpp` is `pig-llama`,
`codemode` `pig-codemode`, `tool-search` `pig-tool-search`, `mcp` `pig-mcp`
(`Extensions\BuiltinExtensions::FOLDERS`). They load **from the package itself, after every other
extension** (`ExtensionLoader::load(builtins:)`), unless the `extensions` setting turns one off:
`!pattern`, `+exact` and `-exact` entries, upstream's `isEnabledByOverrides()`, with a project
entry deciding over the person's and the last match winning (`enabled()`). Those entries are
switches, not paths, so `Settings::extensions()` leaves them out and `extensionEntries()` has
them; `pig config` writes them from its Built-in rows. `-e builtin:<name>` loads one by name (an
unknown name is upstream's `Unknown built-in extension: builtin:x`), `--no-extensions` loads none
but those, `--no-mcp` leaves out `mcp`. `codemode`, `tool-search` and `mcp` are replaceable:
another extension registering one of their tools or commands keeps it and the built-in is dropped
with upstream's warning (`omitReplaced()`; upstream compares flags too, pig's flags are
process-wide).

**A folder with a built-in's name anywhere else is not loaded** (`isStaleCopy()`), with a warning
to remove it. `pig update` used to copy these four into `~/.pig/agent/extensions`, and such a copy
is whatever version it was the day it was made — the stale `pig-mcp` copy under
[MCP servers](#mcp-servers-and-the-tool-that-arrives-after-startup) cost an hour. The developer
chose ignoring over loading it; `pig update` copies the other bundled extensions and skips these.

**`pig-llama`** is upstream's `extensions/llama`: the provider registered with an api-key sign-in
(`Pig\Ai\Extension\ApiKeyAuth`, `Provider::$apiKeyAuth` and `$classifiers`), which
`CodingAgent\Auth` asks before its own table (`hasKeyFor()`, `apiKey()`, `providerAuth()`) and
`/login` lists beside OAuth — `/login <provider>` starts one directly, as upstream's
`findLoginProviderOptions()`. Its catalog is fetched (`Provider::$refreshModels`, upstream's
`refreshModels()`), which `CodingAgent\ModelRefresh` runs as upstream's `Models.refresh()` does:
the stored catalog without network when the provider is registered, every provider in the
background once the TUI or RPC mode is up (not under `PIG_OFFLINE`), and the one signed into after
`/login`, with upstream's warning when that times out or fails; `/model` and `/scoped-models` refresh
too (15 seconds, `ModelRefresh::TIMEOUT_MS`, with upstream's status line), and `/model <pattern>`
refreshes when nothing cached matches. The store is
`~/.pig/agent/models-store.json` and its rows are the provider's own — pig has no codec for every
model a provider could file. `pig-ai` stays OAuth-only, as upstream's `ai/cli.ts` is.

**`SelectList` takes upstream's layout** (`SelectListLayout`): the label column is the widest
label plus two, between a minimum and a maximum (32 and 32 when none is given, the old fixed
column), with an optional `truncatePrimary` hook. The pickers use upstream's bounds: 12–32 for the
theme, thinking, settings submenus and slash commands, 12–46 for the per-model thinking picker's
models, 36–56 for `/llama`.
