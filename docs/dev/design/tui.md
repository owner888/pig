# Design: The terminal UI

Rendering, widths, keys, the editor, themes, fullscreen. Moved out of `AGENTS.md` (then `CLAUDE.md`) as written — "this file" in the entries below means that file, where they were first written. The index is generated from the headings below.

- [The settings upstream had and pig did not](#the-settings-upstream-had-and-pig-did-not)
- [`/settings`, and why its list is shorter than upstream's](#settings-and-why-its-list-is-shorter-than-upstreams)
- [Mermaid diagrams (`Mermaid\*`, `Interactive\MermaidTransformer`)](#mermaid-diagrams-mermaid-interactivemermaidtransformer)

## The settings upstream had and pig did not

The site's settings page listed upstream's keys and pig read about half of them. The developer's
call was to port the rest, in batches, under upstream's names and defaults (`settings-defaults.ts`),
rather than take the rows off the page. The first batch is the display and terminal ones:

| Key | Default | Where |
|---|---|---|
| `showCacheMissNotices` | `false` | `CacheStats` (upstream's `cache-stats.ts`): a line when a prompt that should have been read was billed again — 20,000 tokens or ten cents, a 1,024-token noise floor, reset at a compaction or branch summary, the idle gap named past the 5-minute TTL — plus `Cache warmed`, `Compaction: N tokens billed` and the dropped-thinking line. `/session` adds `Cache re-billed` |
| `terminal.images` / `trueColor` / `hyperlinks` | detected | `TerminalImage::setCapabilityOverrides()`, from `bin/pig` only |
| `terminal.imageWidthCells` | 60 | `ToolExecutionComponent`, `/settings` row only where pictures draw |
| `terminal.showTerminalProgress` | `false` | `Terminal::setProgress()`: OSC 9;4;3 while a turn or a compaction runs, 9;4;0 after, re-sent every second |
| `markdown.codeBlockIndent` | two spaces | `MarkdownTheme::$codeBlockIndent` |
| `quietStartup` | `false` | `true` drops the header, `header` keeps it and drops the loaded resources |
| `autocompleteMaxVisible` | 5 | `Editor::setAutocompleteMaxVisible()`, clamped 3–20 |
| `doubleEscapeAction` | `tree` | Escape twice within half a second on an empty prompt: `tree`, `fork` or `none` |
| `treeFilterMode` | `default` | `TreeList`'s opening filter |

Two things came with them. A compaction and a branch summary now carry what generating them cost
(`usage`, upstream's field on both entries), which `stats()` adds to the bill — before this a
summarisation was the one model call the session never charged for. And `FooterComponent::tokens()`
is public, because the notices count tokens the way the footer does.

`terminal.*` overrides are set in `bin/pig` and not in `InteractiveMode`'s constructor: the
capabilities are process-wide, and a mode setting them would undo whatever a test fixture had set.

Regression tests: `CacheStatsTest` (8), `SettingsTest`'s three display cases, the `quietStartup`,
double-escape, tree-filter, tab-progress, cache-miss and compaction-cost cases in
`InteractiveModeTest`, `EditorTest::testTheCommandListShowsAsManyRowsAsItIsToldAndNoMore`,
`MarkdownTest::testACodeBlockIsIndentedWithWhatTheThemeSays` and
`SessionEntriesTest::testWhatASummaryCostIsWrittenWithItAndOnlyWhenItIsKnown`.

The second batch is the model, tool and summary ones:

| Key | Default | Where |
|---|---|---|
| `modelThinkingLevels` | none | `provider/id` → level, written whole because ids hold dots. A switch takes the level typed on the pattern, then this, then `defaultThinkingLevel`, then the current one — upstream's `_getThinkingLevelForModelSwitch()` — and saving a default model no longer saves the level with it. `/settings` row *Default thinking level per model* (`ModelThinkingSubmenu`, upstream's two-step submenu) |
| `thinkingBudgets` | upstream's table | `SimpleStreamOptions::$thinkingBudgets` → `Stream::thinkingBudgetForLevel()`, laid over the table level by level for Anthropic, Bedrock, Gemini 2.x, Vertex and the completions budget field |
| `enabledModels` | none | the `--models` scope when none was typed; the remembered default is opened when it is in the scope, and saving a default outside it adds it |
| `defaultTools` | the four | names replace the four, `+name`/`-name` modify them, a project list of modifiers alone is appended to the global one (`mergeDefaultTools()`), and `--tools` of modifiers alone applies to it. Mixing the two kinds is refused with upstream's sentence |
| `enableSkillCommands` | `true` | `/skill:name args` sends the skill as upstream's `<skill name location>` block (`AgentSession::expandSkillCommand()`), offered in the autocomplete |
| `warnings.anthropicExtraUsage` | `true` | upstream's one-time warning on a Claude subscription sign-in, `/settings` → *Warnings* |
| `compaction.modelOverrides` | none | `provider/id` → `reserveTokens`/`keepRecentTokens`, before the ordinary values. Every compaction number must be a non-negative safe integer (0 allowed); anything else throws upstream's `Invalid compaction.<field> setting: … Expected a non-negative safe integer.` when it is read |
| `skills` / `prompts` / `themes` | none | lists of paths with `!pattern` / `+path` / `-path`, per scope — see "Skills, prompts and themes are resource lists" |
| `defaultThinkingLevel` | `medium` | upstream's default, clamped to the model |
| `branchSummary.reserveTokens` / `skipPrompt` | 16384 / `false` | the branch summary's own budget (it used compaction's before), and `/tree` without the question |

**`defaultTools` reaches the built-ins and a `-name`, not every custom tool.** Upstream's active set
starts from the list, so a custom tool it does not name is inactive; pig registers every custom tool
active, so a name in the list that is not built in changes nothing and only `-name` takes one away.
Making the list exclusive would switch off every extension's tools for anybody who set it, which is
a decision rather than a port.

`ModelChoice::$explicitThinking` exists because `parse('sonnet')` and `parse('sonnet:off')` came
back the same, and the switch order above turns on telling them apart.

Regression tests: `SettingsTest`'s seven batch-two cases, `StreamTest`'s three budget cases,
the scope, per-model, `defaultTools` and `--tools` cases in `CodingAgentSessionTest`,
`AgentSessionTest`'s per-model, scope and skill-block cases, and the skip-prompt, skill-command,
warnings, per-model submenu and subscription-warning cases in `InteractiveModeTest`.

The third batch is images, the shell and where sessions live:

| Key | Default | Where |
|---|---|---|
| `images.autoResize` | `true` | `Utils\ImageProcess` — upstream's `image-process.ts`, `image-resize-core.ts`, `tool-result-images.ts` and `exif-orientation.ts`. A picture from `read`, `@file`, a prompt and every tool's result is put in a format the providers take and fitted inside 2000×2000 and 4.5MB of base64, or the model's `inputLimits.images.resize`, with upstream's note on mapping coordinates back. `/settings` row |
| `images.blockImages` | `false` | `Agent::$convertToLlm` (now public, as upstream's is) wrapped by the session: a picture in a user message or a tool result reaches the provider as "Image reading is disabled.", a run of them as one line, and the conversation keeps them. `/settings` row |
| `shellCommandPrefix` | none | a line in front of every command, the `bash` tool's and a typed `!command`'s alike, joined by a newline; the command recorded is the one typed |
| `sessionDir` / `--session-dir` | per project | `SessionManager::useSessionDir()`, process-wide for `Shell::useShellPath()`'s reason: every session in one directory, the listings keeping the ones whose header names this project, and pi's directory not read. `--session-dir`, then `PIG_CODING_AGENT_SESSION_DIR`, then the setting — the project's read before trust (`Settings::startupSessionDir()`), as upstream reads it; a relative path is the working directory's |

**GD is the codec and it is optional.** Upstream bundles Photon (WASM); PHP's equivalent is
`ext-gd`, which pig does not require. Without it an image is measured from its header and the EXIF
orientation, which need no codec: one inside the limits goes through as it is, one outside them is
left out with upstream's sentence. Upstream with no Photon leaves every image out, in-limits ones
too — that is the one deviation, and `ImageProcess::$codec` is the seam the tests turn it off with.

**The tools get their options when the loadout builds them.** `ToolLoadout::useToolOptions()` is
upstream's `_buildRuntime()` options (`read`'s `autoResizeImages` and resize profile, `bash`'s
`commandPrefix`), asked at each `apply()`; `useResultImages()` wraps each tool in a
`ResultImagesTool` outside `HookedTool`, so a picture a `tool_result` hook put in is fitted too —
upstream's `_afterToolCall()` order. Both re-apply only a loadout that was already applied, or a
session built on a fresh loadout would gain a system message it never asked for.

Regression tests: `Utils\ImageProcessTest` (12), the image, block, prefix and loadout cases in
`AgentSessionTest`, `ReadToolTest`'s and `FileArgumentsTest`'s resize cases,
`BashToolTest::testTheCommandPrefixRunsInFrontOfTheCommand`, the two `sessionDir` cases in
`SessionManagerTest`, `SettingsTest`'s image-shell-session defaults, and the image rows in
`InteractiveModeTest`. Test pictures are `TinyImages` — real 4×3 PNG and JPEG bytes — because
eight bytes of PNG signature are a picture that cannot be read now, and left out.

## `/settings`, and why its list is shorter than upstream's

`Components\SettingsList` is upstream's `tui/components/settings-list.ts` and `/settings` —
`InteractiveMode::showSettings()` — is upstream's `settings-selector.ts`. It was the last
unported file in `pig/tui`, and it waited this long for a good reason: a component whose only
consumer is unported is dead code, so the two only make sense together.

It is not a `SelectList` with two columns, and the difference is worth naming. A select list is
*answered* — one question, Enter means "this one", and it closes. A settings list is **lived
in**: several things are changed in one visit, so Enter changes the row under the cursor and
leaves the list open, Escape means "done" rather than "cancelled", and the screen has to keep
saying what each row is set to now. Space does what Enter does, as upstream allows, because a
two-value row reads like a checkbox.

Two decisions of pig's own:

- **`SettingItem` is readonly and the values live in the list.** Upstream writes the new value
  back into the item it was given. Here `SettingsList` holds an `id => value` map, so a
  declaration built in one place stays a declaration, and `setValue()` exists for the setting
  that moves on its own — the thinking level changes when a model that cannot reason is chosen,
  and the screen should agree with the truth rather than with what was last pressed on it.
- **The label column is measured in columns, not characters.** Upstream's `.length` puts 主题 at
  2 where the terminal gives it 4, which pushes every value column after it out of line. Same
  deviation, same reason, as `SelectList`'s description column.

**The list only offers things that take effect**, which is what decided its rows:

| Row | Reaches |
|---|---|
| Theme | `useTheme()`, split out of `switchTheme()` so a row can name a theme rather than toggle |
| Thinking | a submenu of the levels *this model* offers, so a model that cannot reason gets no row at all rather than six ways to change nothing |
| Thinking blocks | `useHideThinking()`, split out of the ctrl+t handler |
| Pictures | `useShowImages()`, and its reader — see below |
| Steering mode, Follow-up mode | `AgentSession::setSteeringMode()` / `setFollowUpMode()`, which tell the agent and write the setting |
| Auto-compact | `compaction.enabled`, read again on every turn |
| Auto-retry | `retry.enabled`, read again on every failure |
| Cache warming | `cacheWarming`, `AgentSession::setCacheWarmingMode()` — saved, and a running warm reconciled at once |
| Editor padding | `editorPaddingX`, `useEditorPaddingX()` — the editor that is up is told |
| Output padding | `outputPad`, `useOutputPad()` — everything drawn is told, see above |

**`collapseChangelog` is a row now**, ported with the rest of upstream's settings: on, the note
after an upgrade is the line "Updated to vX. Use /changelog to view full changelog." in place of
the release notes (`sayChangelogCollapsed()`). An earlier reading kept it out as a second way to
draw one thing; the developer's call was to port every setting the docs list.

**The two queue modes are two rows, under upstream's names.** `d0a4c37` split the single queue
into `steer()` and `followUp()`, and upstream's HEAD has a mode per queue — `steeringMode` and
`followUpMode`, each a setting, a `/settings` row and an RPC command (`set_steering_mode`,
`set_follow_up_mode`). pig had one `queueMode` that set the follow-up queue alone; it was renamed to
upstream's pair before a release, with no reader for the old key.

Three smaller decisions fell out of it:

- **The modes are copied out of `AgentOptions` into fields on `Agent`.** `AgentOptions` is readonly
  and stays that way: it records what an agent was *set up* with, and a settings screen writing a
  field on it would turn that record into a record of the present.
- **`AgentSession`'s constructor applies the stored modes**, not whoever built the agent. It is the
  class holding both the agent and the settings, and `setSteeringMode()` / `setFollowUpMode()`
  write through the same pair — a caller passing it into `AgentOptions` instead would be a second route for one fact.
- **Upstream's `queue-mode-selector.ts` has no consumer.** Checked before deciding not to port the
  component: the only `QueueModeSelectorComponent` in upstream's tree is its own definition, and
  the live path is a row in its settings screen. So the row is the port and the standalone
  component is dead code there.

Writing the test for it found a gap in the test harness rather than in the code:
`InteractiveModeTest` was passing **null settings to `AgentSession`** while giving the same
settings to `InteractiveMode`, which `bin/pig` does not do. Three settings the session reads — the
queue mode, auto-compaction and auto-retry — were live in production and inert in every test.

**`terminal.showImages` was a setting pig stored and nothing read.** Building the screen is what
surfaced that, and the choice was between leaving the row off and giving the setting its reader.
Upstream has a real one — `tool-execution.ts` takes `showImages`, checks
`caps.images && this.showImages` before drawing, and has a `setShowImages()` for the transcript
already on screen — so pig now has the same: `ToolExecutionComponent` takes the flag and
`setShowImages()` redraws. Off draws the label `TerminalImage::fallback()` already produces for a
terminal that cannot draw pictures, from the same function, so "turned off" and "cannot" look
alike and neither pretends no picture came back. Queue mode is on the list now and has its own note above.

`useTheme()`, `useHideThinking()` and `useShowImages()` being split out is the part that matters
most and is the least visible: ctrl+t writes the setting *and* tells every
`AssistantMessageComponent` already on screen. A second place writing only the setting is a
toggle that half works — the file says hidden and the transcript still shows thinking — so both
paths go through the one method. The theme is the exception and says so: the list holds the old
palette's closures, so new colours arrive the next time it is opened, which is the same thing
`/theme` already says about the transcript.

`Changelog` is upstream's `utils/changelog.ts`, with two readers: `/changelog` shows the whole
file and startup shows only what is newer than the version this person last saw. The second is the
one that has to be right — a tool that greets you with its whole history every morning is a tool
whose greeting you stop reading.

Four things in it, three of which are upstream's and one of which is arithmetic:

- **`version_compare()` does the comparing.** Upstream splits the number into three and
  subtracts, because JavaScript has nothing else; the first version here copied that and was
  worse than the platform call in two ways that only showed up against real input — `0.2` read
  as `0.2.0` and `0.2.0-beta` read as `0.2.0`, so both compared *equal* to a release they are
  not, and somebody on a pre-release saw nothing after upgrading to the release it preceded.
  `version_compare` gets those right and `0.10.0 > 0.9.0` too, which is the one the hand-rolled
  version existed for. **Copying upstream's arithmetic is not the same as porting it** — where
  JavaScript is working around a missing library call, the port is the call.
- **A `##` with no version in it ends the entry and starts nothing**, discarding what it had
  collected. Right for a file people edit by hand: folding an `## Unreleased` section into the
  release above would file unreleased notes under a released number.
- **A string that is no kind of version means everything is new**, because it sorts below every
  release. Same end as upstream's `Number()` of a missing part: showing the whole file once beats
  silently showing nothing because a settings file had a typo in it.
- **The version is written down when the notes are shown**, not when pig starts, so a run that
  showed nothing does not mark them as seen. `-c` and `-r` skip it: somebody picking a
  conversation back up is in the middle of something.

**There is a `CHANGELOG.md` now**, and the machinery was ported before it existed on the grounds
that "it is the same answer somebody who deleted theirs gets" — which was right, and cost two tests
that were pinning the absence of the file rather than the behaviour. See
[A changelog and a version, and two tests that were pinning the absence of the file](../traps/testing.md#a-changelog-and-a-version-and-two-tests-that-were-pinning-the-absence-of-the-file).
The empty answer is still a real one and still has a case, against a path that is not there.

## Mermaid diagrams (`Mermaid\*`, `Interactive\MermaidTransformer`)

Upstream draws a ```mermaid block as Unicode box-drawing art with the npm package `grok-mermaid`;
`packages/coding-agent/src/Mermaid/` is that package (0.2.3, Apache-2.0 — its `LICENSE` sits beside
the code) ported file for file: `Parse` is `parse.ts`, `Layout` `layout.ts`, `SequenceLayout`
`layout-seq.ts`, `Canvas`, `Labels`, `SourceBox`, and `Mermaid::render()` is `render()`. **One
deliberate difference: widths are pig's** (`Measure` is `Graphemes::split()` + `Width::visible()`),
not the package's `unicode-width` table, because the rows go through pig's Markdown and onto pig's
screen, which measure that way. It shows only on tabs, soft hyphens and keycaps.

`MermaidTest` replays every block of upstream's hand-written cases as grok-mermaid drew them under
Node (`test/fixtures/mermaid/cases.json`). The fuzz corpus (4,486 sources) was checked the same
way while porting: all of it matches with upstream's width table swapped in.

`MermaidTransformer` is upstream's `components/mermaid.ts`: first among the Markdown transformers
(`InteractiveMode::markdownTransformers()`), each row a code span joined by hard breaks, the block
left as written when `markdown.mermaid` is `off`, while streaming under `final`, when it cannot be
drawn, or when it is wider than the terminal less the padding. **Top level is a fence at column 0**:
pig's Markdown lexer keeps no raw text, which upstream's `marked` tokens are re-joined from, and a
column-0 fence is never inside a list, a quote or indented code.
