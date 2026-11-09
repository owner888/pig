# Upstream

pig follows the latest pi (upstream `main`); see `AGENTS.md`. Until 2026-10 it was ported against a
pinned commit, `d0a4c37`, with pieces taken from upstream HEAD on request — comments and trap entries
written then still say "the anchor"; read them as history. This file is what is left to say: where
pig cannot do what upstream does, and what it does instead.

The program status port differs from upstream in two places, both platform. Upstream sends the
support query inside its kitty keyboard query and reads the answers out of `StdinBuffer`'s split
sequences; pig makes no keyboard query, so `ProcessTerminal::start()` sends the OSC 7501 query with
its own DA1 sentinel and sifts the two answers out of the raw read, holding back a tail that could be
a reply cut by the read — never a lone escape, which is a key. And upstream hears every compaction as
a `compaction_start`/`compaction_end` session event, while here only the overflow one is an event:
`/compact` and the threshold check run in the mode, which tells `ProgramStatusReporter` itself
through `compactionStart()`/`compactionEnd()`. A cancelled overflow compaction reads as `error` for a
moment and becomes `idle` at the `agent_settled` that follows it, since `abort()` set the flag.

Upstream reads the clipboard through a native Node addon on macOS and Windows and falls back
to `wl-paste` / `xclip` / PowerShell on Linux. PHP has no addon, so every platform goes
through a command here — including macOS, which uses `pngpaste` when it is installed and
AppleScript, which always is, when it is not. Under Termux it is Termux:API's
`termux-clipboard-get` / `termux-clipboard-set` and nothing else, text only, as upstream's.

A steered or follow-up message's images are fitted to the model's limits when the message is
queued, not when it is delivered as upstream does (#11782): upstream rewrites the queued message in
place at `message_start`, and a `UserMessage` here is immutable. `ImageProcess` is synchronous, so the
reason upstream waits (keeping the queue call synchronous) does not arise; the limits are the model in
use when it was queued.

Upstream changes that need nothing here, because pig never had the problem: #11813 (a bash timeout
after the shell exited — `Tools\Run` resolves once the shell exits rather than waiting for the pipes
its background children hold), #9274 (a diff dropping the removed line's indent — `DiffView` builds
each side from its own tokens), #11514 (reordering the same active tools — `ToolLoadout` keeps
registration order), #11853 (extension overlays left behind on `/reload` — `ExtensionUi::custom()`
has no overlay mode) and #11775 (CR-only SSE lines — `SseParser` already splits on them).

`pig auth login` (upstream 9b9cb30a) has no Radius step: upstream points the global `mcp.json`'s Radius
server at the login (`planRadiusMcpServer()`), and changes `pig mcp login`'s hint for a server using a
provider's credentials to name `pig auth login`. pig's MCP servers have no `auth: {provider}`, so
there is nothing to point and no such server to hint about.

Cache warming's policy is `Pig\Ai\CacheWarming` (upstream's `cache-warming.ts`), moved out of
`Session\CacheWarmer` as upstream moved it into `pi-ai` for its p2 app (#11857), with upstream's
function and constant names so the two read side by side; `CacheWarmer` keeps the scheduling.

Anthropic message threads (#11545) keep upstream's function names inside `Providers\Anthropic`
(`prepareThreadRequest()`, `sendThreadRequest()`, `recordThreadReply()`, `hashThreadPrefix()`), with
the thread state a static of that class. Upstream registers its cleanup with
`registerSessionResourceCleanup()`; pig has no such registry, so `AgentSession::closeSessionResources()`
calls `Anthropic::cleanupSessionResources()` beside `StrictToolLimits::cleanup()`. Upstream's
`shortHash()` is any stable digest and pig's is the first 16 hex digits of SHA-256: the hash is only
ever compared with another from the same process. `StreamProxy` sends `disableContinuation` only when
it is true, false being pig's unset.

`Session\SessionStore` has no upstream counterpart: pi's `SessionManager` writes its JSONL file
itself. pig's reads and writes the same lines through the store, so pig can keep sessions somewhere
other than files (a server, several users, MySQL — [storage.md](design/storage.md)). `JsonlSessionStore`
is the code that was in `SessionManager`, moved, and the file it writes is still pi's. The class above
the seam keeps upstream's names and logic, so it still reads against `session-manager.ts`.

The file tools' `*Operations` are upstream's interfaces with synchronous methods (a tool runs in a
fiber, so an implementation that waits suspends it rather than returning a promise). They throw
`Tools\FileAccessError` with a reason, so each tool keeps pig's own error wording, the path as the
model wrote it, where upstream passes on its file system's `ENOENT …`. A failure keeps its reason,
in the file system's words without their paths, after the tool's sentence (`Could not write a.txt: No
space left on device`), so nothing the errno said is lost.

`BashOperations` is upstream's with three differences:
- the timeout is in seconds;
- a stopped command is `CommandStopped` instead of the rejections `"aborted"` / `"timeout:N"`;
- `$onData($chunk, $stream)` carries the stream (1 stdout, 2 stderr), so the escape sequence or
  character a read cut short is held per stream (#10504) as pig always held it.

`Tools\LocalShellOperations` is `createLocalShellOperations()`.

Nothing to port: #11947 fixes the renumbering of `[paste #N]` markers after a
backspace, which pig's editor does not renumber; and the native Shift+Enter fallback for ESC+CR
(86dc686c) reads the Shift key through upstream's native addon, which PHP has no counterpart for.

#11751's second half, diffing a window around each edit instead of the whole file, is not ported:
upstream's diff is jsdiff's general one, ten to fifteen times the file in memory, where `EditDiff`
compares a common prefix and suffix and splits by the replacements it already knows — linear, with
no patch to build. Its first half (line endings) and the diff before the write are.

## Names that differ from upstream

An audit of 2026-11-09, after `CustomTools\` folded into `Extensions\` (1.8.0) and `bin/pig` was
found still naming the `LoadedHook` that 1.7.0 removed: every class under `packages/*/src` whose
docblock names an upstream type that is not the class's own name, checked against the names
upstream's `packages/*/src` export, plus upstream's exported classes with no class of the same
name here. The developer had the proposals applied as they stood: every row of the first table was renamed
in 1.9.0, the four with a "keep" option moved to the second table.

Two conventions are deliberate and not drift, so they are not listed below: acronyms are
PascalCased (`Api`, `Ui`, `Oauth`, `OpenAi`, `Mcp`, `Json` — `OpenAICompat` is `OpenAiCompat`,
`OAuthCallbackServer` is `OauthCallbackServer`), and a TUI component drops upstream's `Component`
suffix (`FirstTimeSetupComponent` is `FirstTimeSetup`, `ConfigSelectorComponent` is
`ConfigSelector`).

### Same thing, different name

| pig | upstream | package | Proposed | Decision |
|---|---|---|---|---|
| `PricingTier` | `ModelCostTier` | ai | rename; 11 files | renamed, 1.9.0 |
| `AnthropicCompat` | `AnthropicMessagesCompat` | ai | rename; 19 files | renamed, 1.9.0 |
| `Utils\Headers` | `ProviderHeaders` | ai | rename | renamed, 1.9.0 |
| `Utils\StreamingJson` | `StreamingJsonParser` | ai | rename; 6 files | renamed, 1.9.0 |
| `Utils\Oauth\Credentials` | `OAuthCredentials` | ai | rename → `OauthCredentials` | renamed, 1.9.0 |
| `Utils\Oauth\Provider` | `OAuthProvider` | ai | rename → `OauthProvider` (a bare `Provider` reads as `Pig\Ai\Provider`) | renamed, 1.9.0 |
| `Extension\ClassificationApi` | `ProviderClassifier` | ai | rename | renamed, 1.9.0 |
| `Extension\ImageGenerationApi` | `ProviderImages` | ai | rename | renamed, 1.9.0 |
| `Providers\AzureOpenAiConfig` | `AzureEndpointOptions` | ai | rename | renamed, 1.9.0 |
| `ModelRefreshResult` | `ModelsRefreshResult` | coding-agent | rename | renamed, 1.9.0 |
| `ModelReference` | `ModelReferenceResult` | coding-agent | rename; 3 files | renamed, 1.9.0 |
| `Session\BashExecution` | `BashExecutionMessage` | coding-agent | rename; 21 files | renamed, 1.9.0 |
| `Session\BranchSummary` | `BranchSummaryEntry` | coding-agent | rename; 25 files | renamed, 1.9.0 |
| `Session\CompactionSummary` | `CompactionEntry` | coding-agent | rename; 36 files | renamed, 1.9.0 |
| `Session\Label` | `LabelEntry` | coding-agent | rename; 14 files | renamed, 1.9.0 |
| `Session\ModelChange` | `ModelChangeEntry` | coding-agent | rename; 9 files | renamed, 1.9.0 |
| `Session\ThinkingLevelChange` | `ThinkingLevelChangeEntry` | coding-agent | rename; 5 files | renamed, 1.9.0 |
| `Prompt\FileCommand` | `PromptTemplate` | coding-agent | rename; 11 files | renamed, 1.9.0 |
| `Interactive\SettingsSubmenu` | `SelectSubmenu` | coding-agent | rename; 8 files | renamed, 1.9.0 |
| `Transports\Transport` | `McpTransport` | mcp | rename | renamed, 1.9.0 |
| `Sandbox` | `CodemodeSandbox` | codemode | rename | renamed, 1.9.0 |

The six `Session\` rows are one family — upstream suffixes every session-file entry type with
`Entry` (`Message` for the one that is a message) — and go together or not at all.

### Named after upstream on purpose, differently

Listed so the next audit does not raise them again.

| pig | upstream | why it stays |
|---|---|---|
| `ExtensionUi` | `ExtensionUIContext` | the `Context` is upstream's naming of a parameter bag; here it is the UI itself |
| `Session\CompactionEndEvent` | `CompactionResult` | an event here, a return value there; the docblock cites the result it carries |
| `Prompt\SystemPromptOptions` | `NormalizedBuildSystemPromptOptions` | upstream's name describes an internal step, not the object |
| `Rpc\RpcEvents` | `JsonAgentSessionEvent` | a translator here, a union type there |
| `Utils\Oauth\DeviceCodeFlow` | `OAuthDeviceCodePollResult` | the flow here, one of its results there |
| `Tools\BashTool` | `ShellToolConfig` | the tool here, its config there |
| `Theme\ThemeJson` | `ValidatedThemeJson` | the docblock cites the validated form it produces |
| `Components\Rule`, `SelectListLayout`, `SettingsList` | `DynamicBorder`, `*Options` | the component here, its options object there |
| `Cli\FirstTimeSetup` | `FirstTimeSetupComponent` | the `Component` convention above |
| `Pig\Ai\ReasoningEffort` | `ThinkingLevel` | `Pig\Agent\ThinkingLevel` already is upstream's `ThinkingLevel`, `off` included; this is the provider-side subset without `off`, and one name for two enums would be worse than two names |
| `Extension\OauthFlow` | `OAuthAuth` | upstream's name says nothing a reader can use |
| `StartedSession` | `CreateAgentSessionResult` | upstream's is a function's return type named after the function |
| `VirtualModelRegistry` | `ModelRuntime` | the virtual-model half of it only, as its docblock says |

### Upstream classes with no class of that name here

From upstream's `export class` lines, sorted by what became of each. The first list is the
one that matters; the other two are closed.

**Not ported, on purpose** — what is left after the audit's first list was worked through (five of
its eight rows turned out to be ported under another name and have moved to the second table, with
what was added to close the gap each had; the status indicators were then ported too):

| upstream | what it is | why not |
|---|---|---|
| `EasterEgg3dAnimation` | a software 3D renderer for **pi's logo** and **Armin** (upstream's author) as point clouds, 1,300 lines, on a header click and `/arminsayshi` | the logo and the person are upstream's identity, not pig's; carrying them over would sign pig with someone else's name. `/arminsayshi` here says hi in text. A pig of pig's own would be a design of its own, not a port |
| `EarendilAnnouncementComponent` | upstream's one-time banner: "pi has joined Earendil", a blog link, a picture | upstream's company news; pig has nothing to announce, and its changelog-on-start already says what is new |

**Ported under another name, or folded into something** — closed:

| upstream | here |
|---|---|
| `AuthStorage`, `FileAuthStorageBackend`, `InMemoryAuthStorageBackend`, `ReadOnlyAuthStorage`, `RuntimeCredentials` | `Auth` (one class; the backends are its constructor's choice, the run-time overlay its `$runtime` table — `setRuntimeApiKey()` / `removeRuntimeApiKey()` / `hasRuntimeApiKey()`, the last two added after the audit, which had taken the overlay for missing) |
| `InMemoryCredentialStore` (`ai/src/auth/credential-store.ts`) | `Auth` again — pig's `Pig\Ai` has no store of its own, the coding agent's is injected |
| `AgentSessionRuntime`, `SessionImportFileNotFoundError` | `CodingAgent::start()` and `StartedSession`; a missing import file is `MissingSessionCwdError` / an `AgentError` |
| `ModelConfig` (`core/model-config.ts`) | `CustomModels` |
| `FooterDataProvider` | `Interactive\FooterComponent`, which reads `.git/HEAD` itself (no `git` process per frame); its `findGitPaths()` — `.git` as a worktree's or submodule's `gitdir:` file — was added after the audit, which had the branch read down as a gap |
| `ModelRuntime`, `CredentialSynchronizationError` | `VirtualModelRegistry` (the virtual-model half) and `ModelResolver`; the synchronization error has no counterpart because `Auth` writes synchronously |
| `FileModelsStore`, `InMemoryCodingAgentModelsStore`, `InMemoryModelsStore` | `ModelRefresh` (its cache file) |
| `ModelsError` | `Models` throws `ProviderError` / `InvalidArgumentException` with upstream's messages |
| `SettingsManager`, `FileSettingsStorage`, `InMemorySettingsStorage` | `Settings` |
| `ProjectTrustStore` | `ProjectTrust` |
| `DefaultPackageManager` | `Packages\PackageManager` |
| `DefaultResourceLoader` | `CodingAgent` + `Prompt\SystemPromptFiles` + `Extensions\ExtensionDiscovery` |
| `NestedToolCallRunner`, `NestedCallRecorder` | `AgentSession::executeNestedTool()` and `Session\NestedCallRecorder` (ported after the audit) |
| `OutputAccumulator` | `Tools\Run` |
| `VisualLinePreview`, `truncateToVisualLines()` | `Interactive\BashOutputComponent` — the audit took it for bash-only; it is the shared visual-row preview, with `keep: 'start'` added after the audit so `pig-codemode`'s collapsed script and output previews cut by rows as upstream's do (they cut by logical lines before) |
| `ToolCallArgumentsStream` | `Providers\AssistantMessageBuilder` over `StreamingJsonParser` |
| `Bm25Ranker` | `Pig\Codemode\ToolSearch` (used by `pig-tool-search`) |
| `HuggingFaceClient`, `LlamaClient` | `pig-llama`'s `HuggingFaceSearch` and `LlamaClient` |
| `McpServerConnection`, `McpServerLog`, `McpSignInCancelledError`, `McpManagerView`, `McpOAuthCredentialStore`, `McpRefreshLockBusyError` | `pig-mcp`'s `ServerConnection`, `McpServerLog`, `McpSignInCancelledError`, `McpManagerView`, `McpOauth` (`mcp-auth.json`, its refresh lock is `FileLock`, which the audit had missed), and `Pig\Mcp\Oauth\McpRefreshLockBusyError` (added after the audit: a busy lock was a generic `RuntimeException`, and a sign-in did not take the lock at all) |
| `JsonRpcLineReader` | inside `Transports\StdioTransport` |
| `StdinBuffer` | inside `ProcessTerminal` (see the program status note above) |
| `SteppedSubmenu` | `Interactive\ModelThinkingSubmenu` |
| `DynamicBorder` | `Components\Rule` |
| `StatusIndicator`, `WorkingStatusIndicator`, `RetryStatusIndicator`, `CompactionStatusIndicator`, `BranchSummaryStatusIndicator` | `Interactive\StatusIndicator` and the four subclasses, ported after the audit (they were inline in `InteractiveMode`, with a string for the kind); the retry's `CountdownTimer` lives inside `RetryStatusIndicator`, as upstream's does. `IdleStatus` has no counterpart: pig's status is drawn in the prompt's border, which is there whether or not anything is in it |
| `LoginDialogComponent`, `AuthUrlComponent` | `InteractiveMode`'s sign-in flow, inline |
| `ExtensionEditorComponent`, `ExtensionInputComponent` | `ExtensionUi::editor()` / `input()` |
| the `*SelectorComponent`s (`Session`, `Model`, `Theme`, `Thinking`, `Tree`, `Trust`, `UserMessage`, `Settings`, `ShowImages`, `Extension`, `Config`, `ScopedModels`), `CustomEntryComponent`, `BranchSummaryMessageComponent`, `CompactionSummaryMessageComponent` | pig's selectors and message components without the `Component` suffix (`SessionPicker`, `ConfigSelector`, `ScopedModelsSelector`, `TrustPrompt`, `StartupSelector`, …) |

**Not applicable** — Node, Cloudflare or a platform pig does not run on; closed:

- `CodemodeSandboxBase`, `InlineCodemodeSandbox`, `RemoteCodemodeSandbox`, `CodemodeSandboxDurableObject` — upstream runs scripts in a V8 isolate, inline or in a Durable Object; pig's one `CodemodeSandbox` is a `php -n` child, there is no second runtime to choose
- `CodemodeSourceError` — upstream's compile error for a TypeScript script; pig's `Source` runs PHP and reports PHP's own parse errors
- `PipeTransport` — an MCP transport over `pi-env`'s `RemoteProcess`, which has no counterpart here

How the audit was run, so it can be run again: collect every `class`/`interface`/`enum` under
`packages/*/src` **and `extensions/`** (the first pass left the extensions out and listed four MCP
and llama classes as missing that `pig-mcp` and `pig-llama` have by name) with the upstream names
its docblock quotes as ``Upstream's `X` ``; collect
upstream's `export (class|interface|enum|type) X` under its `packages/{ai,agent,coding-agent,tui,mcp,codemode}/src`;
a pig class whose docblock names an upstream type that exists, is not its own name and is not
another pig class is a row of the first table; an upstream `export class` with no pig class of
that name (after the acronym and `Component` conventions) is a line of the last.
