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

From upstream's `export class` lines. Some are ported under another name (noted), most are not
ported or are done another way; the developer marks which is which.

- **ai**: `InMemoryCredentialStore`, `InMemoryModelsStore`, `ModelsError`, `ToolCallArgumentsStream`
- **codemode**: `CodemodeSandboxBase`, `CodemodeSandboxDurableObject`, `CodemodeSourceError`, `InlineCodemodeSandbox`, `RemoteCodemodeSandbox` (pig has one `Sandbox`)
- **coding-agent**, storage and runtime: `AgentSessionRuntime`, `AuthStorage`, `FileAuthStorageBackend`, `InMemoryAuthStorageBackend`, `ReadOnlyAuthStorage`, `RuntimeCredentials`, `CredentialSynchronizationError`, `FileModelsStore`, `InMemoryCodingAgentModelsStore`, `ModelConfig`, `ModelRuntime` (see `VirtualModelRegistry` above), `SettingsManager`, `FileSettingsStorage`, `InMemorySettingsStorage`, `ProjectTrustStore`, `DefaultPackageManager`, `DefaultResourceLoader`, `FooterDataProvider`, `NestedCallRecorder`, `NestedToolCallRunner`, `OutputAccumulator`, `Bm25Ranker`, `HuggingFaceClient`, `LlamaClient`, `McpServerConnection`, `McpServerLog`, `McpOAuthCredentialStore`, `SessionImportFileNotFoundError`
- **coding-agent**, TUI: `SessionSelectorComponent` (pig: `SessionPicker`, folded with `SessionList`), `ModelSelectorComponent`, `ThemeSelectorComponent`, `ThinkingSelectorComponent`, `TreeSelectorComponent`, `TrustSelectorComponent`, `UserMessageSelectorComponent`, `SettingsSelectorComponent`, `ShowImagesSelectorComponent`, `ExtensionSelectorComponent`, `ExtensionEditorComponent`, `ExtensionInputComponent`, `LoginDialogComponent`, `AuthUrlComponent`, `BranchSummaryMessageComponent`, `CompactionSummaryMessageComponent`, `EarendilAnnouncementComponent`, `EasterEgg3dAnimation`, `McpManagerView`, `VisualLinePreview`, `CountdownTimer`, `StatusIndicator`, `IdleStatus`, `WorkingStatusIndicator`, `RetryStatusIndicator`, `CompactionStatusIndicator`, `BranchSummaryStatusIndicator`, `SelectSubmenu` (see `SettingsSubmenu` above), `SteppedSubmenu`, `DynamicBorder`
- **mcp**: `JsonRpcLineReader`, `McpRefreshLockBusyError`, `McpSignInCancelledError`, `PipeTransport`
- **tui**: `StdinBuffer` (see the program status note above)

How the audit was run, so it can be run again: collect every `class`/`interface`/`enum` under
`packages/*/src` with the upstream names its docblock quotes as ``Upstream's `X` ``; collect
upstream's `export (class|interface|enum|type) X` under its `packages/{ai,agent,coding-agent,tui,mcp,codemode}/src`;
a pig class whose docblock names an upstream type that exists, is not its own name and is not
another pig class is a row of the first table; an upstream `export class` with no pig class of
that name (after the acronym and `Component` conventions) is a line of the last.
