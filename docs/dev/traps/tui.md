# Known traps: The terminal UI

Rendering, widths, keys, the editor, themes, fullscreen. Moved out of `AGENTS.md` (then `CLAUDE.md`) as written — "this file" in the entries below means that file, where they were first written. The index is generated from the headings below.

- [A run of emoji measured as one: PCRE2 10.42's `\X` joins adjacent pictographs](#a-run-of-emoji-measured-as-one-pcre2-1042s-x-joins-adjacent-pictographs)
- [`str_pad()` counts bytes, so every column it lines up is ASCII-only](#str_pad-counts-bytes-so-every-column-it-lines-up-is-ascii-only)
- [A line wider than the terminal is fatal here, so a hint upstream lets overflow is a crash](#a-line-wider-than-the-terminal-is-fatal-here-so-a-hint-upstream-lets-overflow-is-a-crash)
- [An empty document has no lines, and `explode("\n", '')` has one](#an-empty-document-has-no-lines-and-exploden--has-one)
- [Upstream has two diff renderers and the second one numbers the wrong lines](#upstream-has-two-diff-renderers-and-the-second-one-numbers-the-wrong-lines)
- [Two pastes in one read, and only the first one arrived](#two-pastes-in-one-read-and-only-the-first-one-arrived)
- [A PNG that said it was nothing wide asked for 150,000 lines](#a-png-that-said-it-was-nothing-wide-asked-for-150000-lines)
- [A control character in tool output measures as nothing and moves the real cursor](#a-control-character-in-tool-output-measures-as-nothing-and-moves-the-real-cursor)
- [A budget handed out by flooring has to be corrected in both directions](#a-budget-handed-out-by-flooring-has-to-be-corrected-in-both-directions)
- [Apple Terminal's Shift+Enter is `\e\r`, which pig read as Alt+Enter and sent the prompt](#apple-terminals-shiftenter-is-er-which-pig-read-as-altenter-and-sent-the-prompt)
- [Erasing vanished rows with `\r\n` scrolled the screen, one row per vanished line](#erasing-vanished-rows-with-rn-scrolled-the-screen-one-row-per-vanished-line)
- [Forcing a render on resize skips the clear it needs](#forcing-a-render-on-resize-skips-the-clear-it-needs)
- [An image is drawn from the cursor downwards, so the cursor goes up first](#an-image-is-drawn-from-the-cursor-downwards-so-the-cursor-goes-up-first)
- [The terminal's reply to a query arrives as keystrokes](#the-terminals-reply-to-a-query-arrives-as-keystrokes)
- [An input method draws where the terminal's cursor is, not where the caret is drawn](#an-input-method-draws-where-the-terminals-cursor-is-not-where-the-caret-is-drawn)
- [A line starting with `/` is not a command, and neither is one that merely looks like a name](#a-line-starting-with--is-not-a-command-and-neither-is-one-that-merely-looks-like-a-name)
- [A slash command only worked in front of a screen](#a-slash-command-only-worked-in-front-of-a-screen)
- [Whatever holds the focus must be in a container only its own opener clears](#whatever-holds-the-focus-must-be-in-a-container-only-its-own-opener-clears)
- [`Container` is a `Component` and not an `InputHandler`, so a container given the focus eats every key](#container-is-a-component-and-not-an-inputhandler-so-a-container-given-the-focus-eats-every-key)
- [A window being dragged is a SIGWINCH per pixel, and three things each did too much per signal](#a-window-being-dragged-is-a-sigwinch-per-pixel-and-three-things-each-did-too-much-per-signal)
- [Widening the window left the right-hand side blank, and the terminal was innocent](#widening-the-window-left-the-right-hand-side-blank-and-the-terminal-was-innocent)
- [`fwrite()` to a terminal returns short, and STDOUT is non-blocking whether you asked or not](#fwrite-to-a-terminal-returns-short-and-stdout-is-non-blocking-whether-you-asked-or-not)
- [Closing a style with `\e[0m` closes whatever it was nested inside](#closing-a-style-with-e0m-closes-whatever-it-was-nested-inside)
- [A style must not be left open at the end of a line](#a-style-must-not-be-left-open-at-the-end-of-a-line)
- [Inverse and strikethrough paint a blank cell, and the rule said only underline does](#inverse-and-strikethrough-paint-a-blank-cell-and-the-rule-said-only-underline-does)
- [The last block of a message left a blank line under itself, and pi does not](#the-last-block-of-a-message-left-a-blank-line-under-itself-and-pi-does-not)
- [A per-line prefix has to go on after the wrap, not before](#a-per-line-prefix-has-to-go-on-after-the-wrap-not-before)
- [`Highlight` hands back the code unchanged when it has no grammar](#highlight-hands-back-the-code-unchanged-when-it-has-no-grammar)
- [Typing mid-turn had one door, and the second one upstream has is Alt+Enter](#typing-mid-turn-had-one-door-and-the-second-one-upstream-has-is-altenter)
- [Ctrl+L was the third key taken off the prompt and bound to nothing](#ctrll-was-the-third-key-taken-off-the-prompt-and-bound-to-nothing)
- [Ctrl+G was being reported to nobody](#ctrlg-was-being-reported-to-nobody)
- [A float took every argument off the screen for one byte](#a-float-took-every-argument-off-the-screen-for-one-byte)
- [A space that is not U+0020 emptied the search box](#a-space-that-is-not-u0020-emptied-the-search-box)
- [Ctrl+P was taken off the editor and bound to nothing](#ctrlp-was-taken-off-the-editor-and-bound-to-nothing)
- [Ctrl+X: `app.message.copy` existed upstream and pig had neither the key nor the selection half](#ctrlx-appmessagecopy-existed-upstream-and-pig-had-neither-the-key-nor-the-selection-half)
- [Four keys out of eight were not upstream's, and a docblock said that was fine](#four-keys-out-of-eight-were-not-upstreams-and-a-docblock-said-that-was-fine)
- [One settings key out of seventeen was not upstream's](#one-settings-key-out-of-seventeen-was-not-upstreams)
- [`/model son` offered file names, and the one method that knew better had no caller](#model-son-offered-file-names-and-the-one-method-that-knew-better-had-no-caller)
- [Every numbered list a model wrote with spaces in it came out numbered 1, 1, 1](#every-numbered-list-a-model-wrote-with-spaces-in-it-came-out-numbered-1-1-1)
- [Three components read against their upstream, and the one thing PHP cannot have](#three-components-read-against-their-upstream-and-the-one-thing-php-cannot-have)
- [The prompt answered to eleven keys that were named nowhere](#the-prompt-answered-to-eleven-keys-that-were-named-nowhere)
- [`/debug` was three-quarters ported and did nothing](#debug-was-three-quarters-ported-and-did-nothing)
- [Two loaders said escape worked and the key reached neither](#two-loaders-said-escape-worked-and-the-key-reached-neither)
- [A dialog opened in front of a suspended fiber has to be escapable](#a-dialog-opened-in-front-of-a-suspended-fiber-has-to-be-escapable)
- [One chunk is one key (tests)](#one-chunk-is-one-key-tests)
- [A spinner above the prompt repainted the line somebody was typing into](#a-spinner-above-the-prompt-repainted-the-line-somebody-was-typing-into)
- [One keystroke took a second and a half, and the component said it was cheap](#one-keystroke-took-a-second-and-a-half-and-the-component-said-it-was-cheap)
- [The footer cut its lines to a byte count and handed the terminal half a character](#the-footer-cut-its-lines-to-a-byte-count-and-handed-the-terminal-half-a-character)
- [The list widget could filter and nothing ever asked it to](#the-list-widget-could-filter-and-nothing-ever-asked-it-to)
- [The footer said less than upstream's about the same session](#the-footer-said-less-than-upstreams-about-the-same-session)
- [Footer session name display, `/name` command, and full `smart-session` telemetry parity](#footer-session-name-display-name-command-and-full-smart-session-telemetry-parity)
- [TUI performance bottlenecks, CJK word boundary bugs, and rendering optimizations](#tui-performance-bottlenecks-cjk-word-boundary-bugs-and-rendering-optimizations)
- [The fullscreen fixed bottom dock and the scrolling viewport (ChatViewport & ScrollView)](#the-fullscreen-fixed-bottom-dock-and-the-scrolling-viewport-chatviewport--scrollview)
- [Fullscreen wheel dead and the bottom dock pushed up: AltScreen must enable mouse reporting and disable autowrap](#fullscreen-wheel-dead-and-the-bottom-dock-pushed-up-altscreen-must-enable-mouse-reporting-and-disable-autowrap)
- [Crash after scrolling up: `Rendered line N is 172 columns wide, terminal is 171`](#crash-after-scrolling-up-rendered-line-n-is-172-columns-wide-terminal-is-171)
- [The TUI split into upstream's `TUI` / `TuiBase` / `TuiMainScreen` / `TuiAltScreen` (no aliases)](#the-tui-split-into-upstreams-tui--tuibase--tuimainscreen--tuialtscreen-no-aliases)
- [Keybindings: one registry, upstream's ids, the app's manager installed in the TUI's slot](#keybindings-one-registry-upstreams-ids-the-apps-manager-installed-in-the-tuis-slot)
- [The image module renamed after upstream's `terminal-image.ts` (no aliases)](#the-image-module-renamed-after-upstreams-terminal-imagets-no-aliases)
- [Terminal color queries: picking the replies out of a batched read](#terminal-color-queries-picking-the-replies-out-of-a-batched-read)
- [Switching TUI mode at runtime: components hold a forwarding reference, so `instanceof` can only be asked of `$renderer`](#switching-tui-mode-at-runtime-components-hold-a-forwarding-reference-so-instanceof-can-only-be-asked-of-renderer)
- [Fullscreen mouse: components first, and the wheel stays with a focused overlay](#fullscreen-mouse-components-first-and-the-wheel-stays-with-a-focused-overlay)
- [`Ansi::at()` did not know OSC/APC, so `\e]8;;\a` counted as 4 visible columns](#ansiat-did-not-know-oscapc-so-e8a-counted-as-4-visible-columns)
- [agent_settled fired early while Working (the "task done" notification over a screen still working)](#agent_settled-fired-early-while-working-the-task-done-notification-over-a-screen-still-working)
- [The retry notice reads `(escape to cancel)` and counts down by the second, as upstream](#the-retry-notice-reads-escape-to-cancel-and-counts-down-by-the-second-as-upstream)
- [A resize held the loop for four seconds, waiting on the terminal](#a-resize-held-the-loop-for-four-seconds-waiting-on-the-terminal)

## A run of emoji measured as one: PCRE2 10.42's `\X` joins adjacent pictographs

**Symptom**: `Width::visible("🙂🙂🙂")` was 2 where the terminal draws 6, so a line of emoji wrapped
late and the cursor landed short after it — the editor, wrapping and search all count through
`Graphemes::split()`.

**Cause**: PHP 8.3 bundles PCRE2 10.42, whose `\X` lets two `Extended_Pictographic` codepoints join
with no ZWJ between them (fixed in 10.43). UAX #29 joins them only across a ZWJ (GB11, `👨‍👩‍👧`).

**Rule**: `Graphemes::split()` cuts a cluster holding two or more pictographs back apart, before
each one that does not follow a ZWJ — a no-op on a PCRE that gets it right. Compare a cluster
question with `Intl.Segmenter` under Node, not with what PHP happens to answer on one machine.

```php
// 🙂🙂🙂 → ['🙂', '🙂', '🙂'];  👨‍👩‍👧👨‍👩‍👧 → two;  👍🏽👍🏽 → two (the skin tone is Extend)
if (strlen($cluster) > 4 && preg_match_all('/\p{Extended_Pictographic}/u', $cluster) > 1) {
    array_push($clusters, ...self::splitPictographs($cluster));
}
```

## `str_pad()` counts bytes, so every column it lines up is ASCII-only

Eight sites, two of them already right, and the two right ones are what make this the first shape
from the index rather than a new find. `SelectList` and `SettingsList` both measure their label
column with `Width::visible()` and pad with `str_repeat(' ', …)` — that correction is written down
twice in this file, against upstream's `.length`. The other six used `str_pad()`:

```
review        strlen= 6  columns= 6   padded to 24: 24 columns  aligned
代码审查      strlen=12  columns= 8   padded to 24: 20 columns  off by 4
データ整理    strlen=15  columns=10   padded to 24: 19 columns  off by 5
émoji-🎉      strlen=11  columns= 8   padded to 24: 21 columns  off by 3
```

`strlen` is never *smaller* than the column count, so the padding always falls **short** and the
column after it starts early — which means this cannot trip `TuiBase::checkWidth()` and nothing ever
failed. It just reads as a table pig cannot draw. Where it shows: `/skills` (a skill's name is a
folder name, and a folder can be called 代码审查), `/hooks` and `/help` (a hook registers its own
command name), and `--list-models`, whose provider and model names come out of a hand-written
`models.json`. A name with a combining mark or an emoji in it is off by its own amount.

`Width::pad()` is the one implementation now, beside `visible()` and `truncate()` where it belongs,
and `Width::background()` — which had the same three lines inline — delegates to it. Two things
worth keeping:

- **`--list-models` was self-consistent and still wrong.** It measured with `strlen()` *and* padded with
  `str_pad()`, so the arithmetic agreed with itself and disagreed with the terminal. A pair of calls
  that cancel out is harder to spot than a single wrong one, and `testTheColumnsLineUpDownTheWholeTable`
  passed throughout because every name in the built-in registry is ASCII.
- **`pad()` cuts nothing**, as `str_pad()` cuts nothing. The two lists truncate first and then pad,
  which is the order that fits a fixed column; a caller that pads without truncating gets a wide row,
  which is a different bug with a different fix.

The mutation check is the one that says the extraction held: putting `str_pad()` back inside
`Width::pad()` turns **nine** tests red across all three consumers plus `background()`'s own two.

Found by sweeping for it rather than by anybody looking at a crooked table — `str_pad`, `str_split`,
`ucfirst` and `strtolower` are the calls that have no business touching text from outside, and there
are only 41 of them. The other three came back clean: `str_split` is `mb_str_split` everywhere it
walks text (the two byte ones walk a key sequence and a base64 blob), `ucfirst` is applied to a
`ThinkingLevel` enum value, and every `strtolower` on something a person typed is `mb_strtolower`.

**Then the two that were already right were mutated, and only one of them had a test.**
`SettingsList` has `testACjkLabelIsMeasuredInColumnsNotCharacters`, which goes red the moment its
padding counts bytes. `SelectList` had nothing: swapping `Width::visible()` for `strlen()` in its gap
broke no test at all — and the case that looks like it should have caught it,
`testAWideLabelDoesNotPushTheDescriptionOffTheScreen`, cannot, because measuring in bytes makes the
gap **smaller**. The row still fits; the description just starts in the wrong column. *A test that
asks "did it overflow" cannot see a rule about where things line up*, which is the same shape as the
`grep` test that asserted `'ripgrep exited'` and thereby pinned the shortfall. Both consumers assert
the column now, and `SettingsList`'s inline three lines are `Width::pad()` like everybody else's.

`SelectList`'s gap is **not** `Width::pad()` and should not become it: `max(1, …)` there keeps at
least one space between a label and its description, which is a separator and not a fill to a fixed
width. Two lines that look alike and answer different questions — the thing this file says to check
before sharing one.

Regression tests: `WidthTest::testPadFillsToTheColumnAndNotToTheByte` (six shapes),
`InteractiveModeTest::testTheDescriptionColumnLinesUpForASkillNamedInAnotherAlphabet`,
`ModelListTest::testTheColumnsLineUpWhenAModelIsNamedInAnotherAlphabet`.

## A line wider than the terminal is fatal here, so a hint upstream lets overflow is a crash

`/settings` on a terminal 32 columns or narrower took the session down:

```
Pig\Tui\TuiError — Rendered line 8 is 33 columns wide, terminal is 32
```

Line 8 is the hint, `  Enter to change · Esc when done`, which `SettingsList` returned at its full
length however narrow the screen was. Upstream does the same and gets away with it, because there a
too-wide line merely wraps; **`TuiBase::checkWidth()` throws instead**, deliberately — a wrapped line
puts every cursor move below it one row low, and silent screen corruption with no visible cause is
worse than a loud stop. That choice is what turns a cosmetic overflow in a ported component into a
crash, and `render()` runs inside the loop's own callback, so there is nothing above it to catch.

Three of that component's lines could not fit, and the third is the one worth remembering: the rows
themselves. Upstream caps the label column at 30 columns and then **writes the label out in full
anyway**, so the cap does nothing and a row is as wide as its longest name; the column is clamped to
the terminal here, keeping one column of value, because the value is the point of that screen.
The count (`(3/30)`) is truncated as `SelectList` and upstream both truncate it, and the hints are
**wrapped**, which is how every other hint in pig is drawn — `TerminalUi` puts each one in a `Text`
— because `Esc when don` is worse than two rows. The indent goes on after the wrap, or the second
row hangs in the margin.

**Then the same question was asked of everything else that builds its own lines**, which is the half
that makes this worth an entry rather than a fix: `FooterComponent` and `TreeList` measure and
truncate, `DiffView` hands back a string that goes through `Text`, and `ToolExecutionComponent`,
`UserMessageComponent`, `HookMessageComponent`, `BorderedLoader` and the banner are
`Text`/`Markdown`/`Container` all the way down, which wrap and pad by themselves.

**That sweep named `BashOutputComponent` as one of the safe ones and it was not**, which is worth
more than the fix: it pads every line to the width and truncates none of them, and the line it adds
itself — `... (35 earlier lines)` — is 22 columns whatever the terminal is. So a `!` command whose
output was cut took the session down on any pane narrower than that. *Measuring is not truncating,
and a sweep that reads for `Width::` finds both.* Checking a claim like that means running the probe
per component, not grepping for the function name — `MarkdownTest` and
`ToolExecutionTest::testTheEarlierLinesNoteFitsANarrowTerminal` are what hold the two of them now.

So the rule for any new component: **the lines it returns are a contract with the renderer, not a
suggestion.** Anything assembled by concatenation rather than handed to `Text` has to truncate or
wrap it itself. A probe over widths 1–60 is the cheap way to ask — everything in the package
overflows at four columns or fewer, because `max(1, $width - $padding * 2)` is the floor everywhere
and upstream's is the same, and that is the only band where a difference is not a bug.

Regression tests: `SettingsListTest::testNoLineIsWiderThanTheTerminal` (widths 6 to 60),
`testTheHintFitsANarrowTerminal`, `testTheScrollCountFitsToo`. Found in the same read: the hint
named Enter and not Space, while `handleInput()` accepts both — upstream's own hint names both, and
the Ctrl+G rule the other way round.

## An empty document has no lines, and `explode("\n", '')` has one

An edit that emptied a file drew this:

```
-1 one
-2 two
-3 three
+1
```

That last line claims a blank line was added. Nothing added one: `explode("\n", '')` answers
`['']`, so `EditDiff::render()` saw the new file as one empty line rather than as no lines, and the
mirror case — an edit that fills an empty file — drew a `-1` for a line nobody deleted. Fixed by
treating `''` as zero lines, and the test beside it exists to stop that being over-applied: the last
element of `"a\nb\n"` **is** a real empty line, so losing a trailing newline is still a removed line
and is still shown as one.

Found by running `EditDiff` against upstream's `generateDiffString` over 515 pairs, which is also
the only reason the other differences are now written down rather than guessed at: 259 pairs differ
only in trailing context being numbered in the new file (deliberate — upstream's old numbers point
into a file that no longer exists), 2 in pig treating a trailing newline as an empty last line
consistently (deliberate, and upstream's alternative renders `"a\nb\n"` → `"a\nb"` as `-2 b` then
`+2 b`, the same text removed and re-added), and 152 are several separate edits in one file, which
is the documented single-block limitation and which `EditTool` cannot produce.

`Truncate` went through the same run — 777 documents with varied limits — and `head()`, `tail()` and
`size()` agree with upstream field for field, byte counts, `truncatedBy`, the partial-line edge case
and all. `line()` is the one that differs, and by unit rather than by intent: upstream counts UTF-16
code units, this counts characters.

**`EditTool` itself went through a third run — 307 cases, comparing the bytes left on disk — and
found nothing.** That is worth recording because it is the one file in the tree that changes somebody
else's work, so "probably fine" is not good enough: BOM, every mix of line endings, a lone CR,
overlapping matches, `$1` in the replacement, invalid UTF-8, and 260 random files with a random slice
of each as `oldText`. Four deliberate deviations, all now in the class docblock with this run beside
them, and the sharpest is one where upstream is the one doing damage: it reads the file as UTF-8, so
**an edit to line 90 of a latin-1 file rewrites line 3 as U+FFFD**. This reads bytes and splices
bytes. `testAFileThatIsNotUtf8KeepsItsBytes` is what stops that "fidelity" being restored later.

**`ReadTool` and `WriteTool` went through a fourth run — 193 cases — and found nothing either.**
Every field of `details.truncation` agrees in all 62 cases that have one, and the bytes `write` puts
on disk agree everywhere. Five deliberate deviations, in the two docblocks: the errors are pig's
words (upstream hands the model Node's `ENOENT` with an absolute path it never wrote), an empty
read's notice has no blank line in front of it, bytes that are not UTF-8 stay as they are, a bare
`GIF` magic is not a GIF here, and — the two worth knowing —

- **`write`'s byte count is bytes.** Upstream reports `content.length`, UTF-16 code units, in a
  sentence that says "bytes": `你好世界` is reported as 4 into a file of 12. The third shape from the
  index, in its sixth guise.
- **An offset or limit that is not a whole count is made one.** The schema says `number`, so a model
  can send `2.7` or `-1`; upstream carries the fraction into its own notice (`Use offset=2.5 to
  continue`) and turns `limit: -1` into `slice(0, -1)`, reading all but the last line. Here the cast
  is load-bearing rather than tidy — `array_slice()` takes `int`, so a float is a `TypeError` out of
  the tool, which the mutation check is what showed.

**`ls`, `find` and `bash` were read against their upstream files rather than run**, because the two
search tools are `fd` and `rg` with flags — where the differences that matter are the three traps
below about globs and exit codes — and `bash` is a process, not a function. What that reading found
is one bug (the signal number, below) and a row of places where **upstream mishandles a bad argument
and pig clamps it**: `ls` with `limit: 0` answers `(empty directory)` for a directory full of files,
`find`'s non-zero-with-partial-output branch is dead code for every exit code `fd` actually produces,
and `ls` skips an entry it cannot `stat` where pig lists it — a broken symlink is a real entry, and
hiding it is how the model ends up unable to explain what it is seeing. `ls`'s sort adds a `strcmp`
tiebreak upstream has no equivalent of, so two names differing only in case have an order.

**`Keys` went through a fifth run — 35 predicates over 1,568 inputs, 54,880 answers compared — and
found nothing either.** The corpus is every single byte, every `\e`+byte, the CSI finals and the
tilde family, SS3, CSI with one and two parameters over the finals that matter (including lock-bit
modifiers, 65/66/129/130), two dozen malformed shapes (`\e[`, `\e[;u`, `\e[1;2;3u`, `\e[999999999u`)
and some multi-byte text. The generated kitty table was compared too: pig rebuilds upstream's
eighteen `Keys` constants from `Keys::kitty()` and all eighteen match byte for byte.

What the run could not cover, and was read instead: `isCtrl()` is **wider** than upstream's
`isKittyCtrl()` — it takes the raw byte as well, by arithmetic, where upstream has a thirteen-entry
table each `isCtrlX()` consults separately — and it **throws** on a letter that is not lowercase
where upstream answers false. Both are now on the method; the second is the interesting one, since
`isKittyCtrl(data, 'C')` is silently false upstream for a caller who meant Ctrl+C.

**The lesson of the five runs is the same one: a hand-rolled replacement for a package is worth a
corpus, and the corpus is worth keeping the count of.** "It matched on the cases I thought of" is
what reading gives you — and a run that finds nothing is only worth having if the count is written
down, or the next reader has to take it on trust. The sixth and seventh are the two entries below,
and they add a second half to it: **a corpus is worth re-reading for the question it never asked** —
which is where "two pastes in one read" came from — and a file of pure functions is worth one even
when reading it finds nothing, which is where the 150,000-line PNG came from.

**The eighth found the package wrong rather than pig**, and it is the one to reach for the argument
from: `DiffView::words()` replaces `diffWords`, and reading it against `diff.ts` found nothing at all
— the two are line for line. Run against `diff@8` over 436 edited line pairs, **106 of upstream's
removed lines are drawn with text the file does not have.** `diffWords` ignores whitespace, so a
whitespace-only change comes back as one unchanged part holding the *new* string and upstream
appends it to both lines: re-indent a block and its `-` line shows the `+` line's indentation, with
nothing marked. pig's is 0, and the run also paid for itself in the other direction — pig's
tokenizer was splitting on whitespace alone, so `foo(bar, ⟨baz)⟩` marked a paren that did not
change, and matching `diffWords`' granularity took the disagreements from 279 pairs to 177. *A
corpus is worth running even when the reading found nothing, and the answer can be that the package
is the one that is wrong.*

**The ninth is upstream against itself, and it is the entry below.** `mom` has its own copy of
`generateDiffString`, so the question was not "does pig match upstream" but "do upstream's two copies
match each other" — and the answer is no, in the line numbers. Nothing here changed; what changed is
that pig's numbering is now known to be the right one of two rather than the only one on offer.

**The tenth is `Fuzzy`, and it found a live bug in the one place the corpus was overdue.** 3,078
comparisons against `fuzzy.ts`; the four notes on that file had all been read rather than run, and
one of them was a claim the run refuted. What it cost until then was the `/resume` search box going
empty on a full-width space — see [A space that is not U+0020 emptied the search
box](#a-space-that-is-not-u0020-emptied-the-search-box). *Reading that file found nothing, twice.*

## Upstream has two diff renderers and the second one numbers the wrong lines

Found by asking what the documented file-list sweep leaves out: it names four of upstream's seven
packages, and `mom` — the Slack bot — carries its own copy of `generateDiffString` inside
`tools/edit.ts`, beside second copies of `read`, `write`, `edit`, `bash` and `truncate`. Two copies of
the function pig ran a 515-pair corpus against, by the same author in the same repository, which is
the first shape from the index with upstream on both ends of it.

They differ in **where the skipped leading context is added to the line counter**. `coding-agent`
bumps `oldLineNum`/`newLineNum` by `skipStart` *before* printing the context lines; `mom` bumps them
*after*. So mom's leading context is numbered from 1 however far into the file the edit is:

```
=== mom ===            === coding-agent ===
    ...                    ...
  1 line 17             17 line 17
  2 line 18             18 line 18
  3 line 19             19 line 19
  4 line 20             20 line 20
-21 target             -21 target
+21 TARGET             +21 TARGET
```

Measured rather than reasoned about — both functions run side by side over 200 single-line edits at
every position in files of 5 to 44 lines: **151 of 200 disagree**, which is every edit far enough in
for an ellipsis. The 49 that agree are the ones near the top, where `skipStart` is 0 and the two
arrangements are the same arithmetic. What it costs mom is the one thing a diff has to get right for
the reader it is shown to — pointing at the line — and the `-`/`+` lines are correct throughout,
which is exactly what makes it hard to notice.

**pig ported the right one and it is pinned**, which was checked the only way that counts: mutating
`EditDiff::render()`'s leading-context loop to number from 1 turns
`EditToolTest::testDistantContextIsElidedWithAnEllipsis` (` 11 line11` → `  1 line11`) and
`testLineNumbersArePaddedToTheWidestOne` red. So this is a find with no fix and no new test, and the
reason it is written down is the method: **a sweep command is a claim like any other, and upstream's
own second implementation of something is the cheapest corpus there is** — it was written by somebody
who knew the problem, so where it disagrees, one of the two is wrong and the diff is short enough to
read.

## Two pastes in one read, and only the first one arrived

`Components\Input` was read against `input.ts` with the corpus that had already been built for it —
and **extended, which is where the find came from**. The first run asked about keys: every editing
key from every cursor position, 500 random walks, wide text, emoji, and a paste split across chunks.
It did not ask what happens when a chunk holds *two* pastes, and that is the shape a terminal
produces on its own: paste twice in quick succession and both bracketed blocks are written before
anything reads.

`buffer()` took the start marker off with `str_replace`, which replaces **every** occurrence. So the
two blocks ran together — `first ␛[201~second␛[201~` — the first end marker closed the pair, and the
rest went back through `handleInput()` as `second␛[201~`, which is not printable and was dropped. A
paste the person watched themselves make, gone, with nothing on screen to say so. Removing only the
first marker leaves the second block whole for the `$rest` recursion that already exists for input
typed after a paste, and pig then agrees with upstream on the case.

**And the two shapes where upstream is the one losing text are kept as pig's own**, because xterm
does not escape the markers in what it sends, so a paste really can carry one:

| in one chunk | upstream | pig |
|---|---|---|
| `␛[200~abc␛[200~def␛[201~` | pastes `abc␛[200~def` — an escape sequence into the prompt | `abcdef` |
| `␛[200~one` then `␛[200~two␛[201~` | `two`: its start check runs in front of its in-paste branch, so the marker **resets the buffer** and `one` is thrown away | `onetwo` |

An escape sequence in the prompt is the one thing `Chars::isPrintable()` keeps out of every other
door, and discarding half of what somebody pasted is worse than keeping both halves.

**The second find is a promise the class made and did not keep.** Its docblock says the cursor
"only ever lands on a grapheme boundary", and every method there leans on it — and `setValue()`
clamped with `min($cursor, strlen($value))`, which is upstream's line and cannot hold in bytes.
Cursor 4 in `abcdef`, then `setValue('你好')`: four bytes in is the middle of `好`,
`Graphemes::split()` of half a character answers false, and what comes out is
`Grapheme split failed` — from `render()`, inside the renderer, or from the next keystroke, inside
the loop's input callback, where nothing catches it. Measured rather than reasoned about: the probe
prints `valid utf-8: NO` and then three throws.

Upstream clamps UTF-16 code units, where the same slip lands between surrogate halves and
JavaScript carries a lone surrogate around without complaint. **`setValue()` has no production
caller in either tree**, so this is a latent hazard on public surface rather than a live bug — and
the reason to fix it rather than note it is that the invariant is what the other twelve methods are
written against. `boundaryAt()` snaps back to the start of the character the offset fell inside,
because the cursor sits *in front of* a character and Backspace there should take the whole of it.

The rest of the file matched, and the numbers are the point of saying so: 1,714 sequences, both
sides' value, cursor, submissions and rendered lines compared at nine widths — 15,426 renders. What
survives is five differences, all on the methods they belong to, and one of them is upstream's bug
rather than a difference of opinion: **upstream draws lines of the wrong width on wide text**,
because it slices its scroll window by character index — 79 of those renders at the three ordinary
widths and 211 more at narrow ones, against pig's 0 at every width above one column, where the
`> ` prompt is wider than the terminal in both and the whole package overflows anyway.

Regression tests: `InputTest::testTwoPastesInOneReadBothArrive`,
`testAStartMarkerInsideAPasteIsNotTextAndIsNotTheEndOfIt`,
`testSettingAShorterValueLeavesTheCursorOnACharacterAndNotInsideOne`.

## A PNG that said it was nothing wide asked for 150,000 lines

`terminal-image.ts` is six pure functions and four header readers, which is the shape a corpus
answers completely, so it got one: 136 payloads — every format at its edges, truncated, wrong-magic,
zero-sized, random bytes, and base64 that is not base64 — plus 462 row calculations over a grid of
image, cell and target sizes, and 1,384 encoder calls across both protocols. Two finds, and they
are the same question asked of two different zeroes.

**The first is a hang from a file somebody else wrote.** A PNG's IHDR is four bytes of width and
four of height, and nothing says they cannot be zero. Upstream hands back `{0, 0}`; pig did the
same, and `rows()` then divides by the width. Measured through the real component:

```
png     0x0     header=0x0      lines in the frame=1
png     0x100   header=0x100    lines in the frame=3000
png     0x5000  header=0x5000   lines in the frame=150000
```

150,000 lines, handed to a renderer that diffs every line against the last frame, **on every frame
for as long as that tool result is on screen**. And the bytes come from outside — a model's image, a
hook's screenshot, a custom tool's result — so this is a hang somebody else gets to choose. Upstream
divides by zero instead and gets `Infinity`, which its own component turns into an array of that
length.

The fix is at the source rather than in the arithmetic: **a header that says zero is read as no
header at all.** `Components\Image` already had the path for it — the size it assumes for a header it
could not read — so a malformed PNG now draws at 800×600 and takes 23 lines. One private `size()`
helper for all four readers, because a rule about what counts as a size belongs in one place; the
lossless WebP shape cannot produce a zero at all and goes through it anyway, since a reader that is
the exception is a reader somebody has to check before trusting.

`rows()`' two `max(1, …)` divisors stay and now say what they are for, which the conventions ask of
every clamp: the method takes an `ImageSize` rather than reading one, so a caller can still build a
zero, and dividing by it is a `DivisionByZeroError` thrown out of a render.

**The second find is JavaScript's falsiness, in three places at once** — the third shape from the
index, and the one this file already has an entry for under `getenv()` answering `''`. Upstream
guards its optional parameters with `if (options.x)`, which skips `0` and `''`; pig had `!== null`,
which does not:

| | upstream | pig had |
|---|---|---|
| `kitty(columns: 0)` | no `c` at all | `c=0` — and `columns: -5` gave `c=-5`, which is not a size |
| `iterm2(name: '')` | no `name` | `name=`, a base64 field holding nothing |
| `fallback(filename: '')` | `[Image: [image/png]]` | `[Image:  [image/png]]`, which reads as a name that went missing |

None of the three was reachable from pig's own callers — `Image::render()` clamps the cell count to
at least one, and nothing passes a name or a filename — so this is fidelity on public surface rather
than a live bug, and the corpus is what found it: **1,384 encoder calls, 320 of which differed, all
of them one rule.** With it fixed the encoders and the fallback agree with upstream on every one.

What is left differing is four rows, each pig's own with the reason on the method: the zero rule
above (nine payloads), the two divisor guards (147 row calculations, all of them a zero on one side
or the other), strict base64 where upstream's `Buffer.from` salvages what it can from junk (two
payloads), and the GIF signature compared byte for byte where Node's `toString("ascii")` **masks the
high bit** — so `\xc7IF87a` is a GIF to upstream and reports a size.

Read rather than run, and both are dead ends in upstream: `maxHeightCells` is declared in
`ImageRenderOptions` and in `image.ts` and read in neither, and the `offset + 3 >= buffer.length`
guard inside the JPEG segment walk cannot fire, because the loop it sits in already stops at
`length - 9`. Neither is ported, and the second is worth knowing before somebody adds it back as a
missing check.

Regression tests: `ImageTest::testAHeaderSayingZeroIsNotASize`,
`testRowsStillAnswersForASizeACallerMadeUpItself`,
`testASizeThatIsNotASizeIsLeftOutRatherThanSentAsOne`, `testAnEmptyNameIsNotAName`,
`testAnEmptyFilenameDoesNotLeaveASpaceWhereANameWouldBe`.

## A control character in tool output measures as nothing and moves the real cursor

`TuiBase::checkWidth()` throws on a line wider than the terminal, because a wrapped line puts every
later cursor move one row low and silent screen corruption is worse than a loud stop. **This is that
same failure arriving by the one route the check cannot see**: `\p{Cc}` is *zero columns wide*, so a
line carrying a form feed measures exactly right, passes, and is written to a terminal that then
drops a row anyway.

What reaches it: `\x0c` and `\x0b` drop a row, `\x08` leaves the padding measuring a column that is
no longer there, `\x00` is swallowed by some terminals and shown by others — and `\x07` is the one
that is worst to live with, because the transcript is redrawn as the conversation grows, so one bell
in a build log beeps again on every redraw. All of them are ordinary: `cat` a binary file, a
progress display that backspaces over itself, a `make` that rings.

Upstream has `sanitizeBinaryOutput` for exactly this and applies it in the same method. **pig had the
UTF-8 half of that function and not this one** — because the UTF-8 half arrived through
`Utf8::sanitize()`, which was written for the request body and pressed into service here, so the
control-character half was never missed.

Three transcript paths carry text that is not pig's own words, and **all three were different**: the
tool view sanitised UTF-8, `DiffView` gained it a batch later, and `HookMessageComponent` had
neither — so a hook that sent a build log with one stray byte in it took the session down, which is
the same killer a third time. `Tools\Shell::sanitize()` is the one rule now: escape sequences out,
malformed UTF-8 out, every control character but tab and newline out.

**There were three more, and counting them as three is how the fourth stayed hidden for four
batches** — the fifth and sixth are the entry above, and the lesson repeats: a count in this file is
a count of what somebody looked at.

**The fourth:**
`BashOutputComponent` — what a typed `!command` is drawn with — has no guard of its own, so
`!head -c 400 /dev/urandom`, or a `!cat` of anything that is not text, took the session down in
exactly the way above. Measured, with and without the fix: 200 bytes in, `valid utf-8 = false`,
`TuiError: Grapheme split failed`. It is not a missing guard, though, and that is the useful part —
**upstream's `bash-execution.ts` has no guard either, and is safe, because its command runner cleans
at the source.** Two files, one design: the entry on cleaning at the source is the other half of this
one, and until it landed pig had copied the component's half and not the runner's.

Regression tests: `BashOutputTest`, which is that component's first — three cases, and removing the
cleaning in `Run::read()` turns two of them red.

Two deliberate differences from upstream's version, and the second is the more useful one to
remember:

- **`\r` goes here and stays there.** A bare CR returns the cursor to column 0 and the rest of the
  line overwrites what was drawn. Upstream gets away with keeping it because its renderer is not
  differential.
- **U+FFF9–FFFB stay**, though upstream strips them. That is a crash in `string-width`; `Width`
  reads them as `\p{Cf}` and measures them at nothing, which is what they are. Same rule as
  `version_compare()` replacing upstream's arithmetic: **where upstream is working around a bug in
  its own dependency, the port is not the workaround.**

Regression tests: the five `ToolExecutionTest::testAControlCharacterInToolOutputNeverReachesTheTerminal`
rows, `DiffViewTest::testADiffOfAFileWithAFormFeedInItDoesNotMoveTheCursor` — a form feed is legal in
C and ordinary in Emacs-era source — and
`HookMessagesTest::testABuildLogAHookSentIsDrawnWhateverBytesAreInIt`.

## A budget handed out by flooring has to be corrected in both directions

A markdown table with three short columns and one prose column — an everyday shape in an answer —
came apart at **every width from 17 to 115**: the top border, the separator and every row each one
or two columns too wide, so the wrap in `Markdown::lines()` broke each of them onto a second row.

`columnWidths()` shares the budget out in proportion to what each column wants, floors each share,
and then hands back the columns that flooring lost. What it did not do is take back what
`max(1, …)` *added*: a column whose share rounded to nothing is given 1 anyway, and three of those
put the total three over the budget with nothing to remove it. Upstream has the same arithmetic and
the same half-correction.

```php
$widths = array_map(static fn (int $want): int => max(1, (int) floor($want / $total * $available)), …);
// hand back what flooring lost … and then take back what max(1, …) added, from the widest column
```

**The shape to remember: a proportional split with a floor *and* a minimum overshoots in both
directions, so both need correcting.** Flooring is the one everybody writes; the minimum is the one
that bites, because it only fires on the inputs where one column dwarfs the others — which is why a
three-prose-column table was fine and a table with a path and two tick marks was not.

It stayed invisible because the symptom is not an error: `TuiBase::checkWidth()` never fires, since
`lines()` wraps every rendered line before padding it. A line that is too wide is therefore *wrapped*
rather than refused, and a wrapped table border does not look like a width bug, it looks like the
renderer cannot draw tables. The test asserts the user-visible invariant instead — the top border is
one line and ends in `┐`, at every width the table claims to be drawable at:
`MarkdownTest::testATableIsOneTableAtEveryWidthItDrawsAt`, over four shapes and 109 widths each.

## Apple Terminal's Shift+Enter is `\e\r`, which pig read as Alt+Enter and sent the prompt

Shift+Enter sent the message instead of breaking the line. `php /tmp/keyprobe.php` in the
developer's terminal: Shift+Enter is `"\u001b\r"`, Enter is `"\r"`. Apple Terminal does not speak
the kitty protocol, so it never sends `\e[13;2u`; it sends ESC-prefixed CR, which is **also** the
ESC-prefix spelling of Alt+Enter that `Keys::isAltEnter()` accepted and `Keys::spec()` generated for
`alt+enter`. `CustomEditor::claimed()` asks `Keybindings` first, `app.message.followUp` matched, and
the key never reached `Editor::isNewLine()` — which has read `\e\r` as a new line all along.

One byte string, two keys with opposite meanings at a prompt; pig cannot tell them apart and has to
pick. Shift+Enter is the one pressed a hundred times a day and Alt+Enter has `command+enter` beside
it, so `\e\r` goes to the editor: `isAltEnter()` is the kitty form only, and `spec()` builds no
ESC-prefix legacy for `alt+enter` (it still does for `alt+<letter>`). The three `InteractiveModeTest`
cases that typed `\e\r` for Alt+Enter now type `\e[13;3u`.

Regression: `KeysTest` (`isAltEnter("\e\r")` false, `matchesName("\e\r", 'alt+enter')` false) and
`KeybindingsTest::testWithNoFileTheDefaultsAreUpstreams`, which asserts `\e\r` is nobody's action.
*When a key does the wrong thing, measure the bytes before reading the table* — the table was right
about every key it named and wrong about which key the bytes were.

## Erasing vanished rows with `\r\n` scrolled the screen, one row per vanished line

Blank rows under the footer from the moment a turn started: rows a shrinking frame leaves behind were
erased with `\r\n\x1b[2K`, and **a newline on the terminal's last row scrolls**. Upstream's
`TuiMainScreen` has a separate "all changes are in deleted lines" path that moves with `\x1b[1B` and
clears without scrolling; pig now runs that literally. `PIG_TUI_WRITE_LOG=<file>` (upstream's
`PI_TUI_WRITE_LOG`; a directory gets a `tui-<timestamp>-<pid>.log` per run) makes
`ProcessTerminal::write()` append every byte, for replaying a real session through a VT emulator —
a hand-rolled emulator that does not scroll on a newline at the last row will agree with the bug.

Regression tests: `TuiTest::testErasingVanishedLinesMovesDownRatherThanScrolling`,
`TuiMainScreenTest::testClearsAllRenderedLinesWhenContentShrinksToZero`.

## Forcing a render on resize skips the clear it needs

`TuiBase::requestRender(force: true)` empties `previousLines`, and `TuiMainScreen::doRender()` reads an empty
`previousLines` as "first frame ever" — which writes the new lines with no clear at all. A resize
needs the opposite: the previous frame must be remembered so the width change is *noticed*, and
then the screen and the scrollback are cleared before redrawing. So the resize handler calls
`requestRender()` plain, like upstream, and force stays for callers who know the screen was
overwritten by something else.

Caught by `TuiTest::testAResizeRedrawsEverythingAndClearsTheScrollback`, which asserts the
`\e[3J\e[2J\e[H` is there.

**And there was a second caller, which is the reason this entry is longer than the fix.** Having
written down that force is for "callers who know the screen was overwritten by something else",
nobody went and read the other one: `takeCellSizeReply()` forced the render after the terminal
answered `CSI 16 t`. So on **every terminal that draws pictures** — which is every terminal that
answers that query, and the only kind pig asks — the startup screen came out twice: frame one drew,
the reply arrived as input a moment later, force emptied `previousLines`, and `doRender()` wrote the
whole frame again with no clear from wherever the cursor already was, which is the last line of
frame one. Upstream asks for a plain render there, for the same reason the resize handler does.

Two things about the shape of it. It is **the trap this file already described, in the caller it did
not name** — a written-down rule is only as good as the audit of who obeys it, and "force stays for
callers who know" is a sentence about callers that lists none.

**It happened a third time, and then the rule itself turned out to be wrong.** `/theme` forced the
render too, and nothing had overwritten that screen: a theme switch changes what components draw, so
there is a previous frame to diff against and only the lines whose colours changed need rewriting. It
asks for a plain render now. Finding it took a byte-level assertion rather than a visible one, because
`requestRender()` **coalesces** — the plain render `say()` asked for and the forced one become a
single draw, so counting how many times the banner is written cannot tell the two paths apart. What
can: a differential update clears each line it rewrites (`\e[2K`) and the first-frame path emits
none.

And with `/theme` gone, every caller left says the same thing — coming back from `$VISUAL`, from a
suspend, from a hook's editor — so **`force` means "the screen is not ours any more", which is not
what a resize needs and is what the docblock claimed.** That meaning wants a clear, and emptying the
record does not give one: the renderer reads an empty `previousLines` as the first frame ever and
writes from wherever the cursor is with nothing cleared. A full-screen editor restores what it found
on the way out, so what is there is pig's own last frame and the new one lands underneath it — the
same doubling, arriving by the one door nobody had checked. Upstream's `resetRenderState()` sets the
remembered size to -1, so the next frame is a width change and clears — the second fact
that an empty record cannot carry: the very first frame has an empty record too, and clearing *there*
would wipe whatever the shell had printed before pig started. Both halves have a test
(`TuiTest::testAForcedRenderClearsTheScreenItNoLongerOwns` and
`testTheVeryFirstFrameDoesNotClearWhatTheShellPrinted`), and
`InteractiveModeTest::testSwitchingThemeDoesNotWriteTheWholeFrameUnderItself` holds the caller. And it was invisible to every test in
the suite, because `FakeTerminal` records what was written and nothing compares two frames drawn
over each other; what a test can see is that *nothing at all* should be written when the answer
changes nothing on screen, which is what
`TuiTest::testTheCellSizeReplyDoesNotRedrawTheWholeFrameOverItself` asserts, with
`testThePictureIsRedrawnWhenTheCellSizeArrives` holding the other half so the fix cannot become
"stop rendering".

## An image is drawn from the cursor downwards, so the cursor goes up first

Both image protocols put the picture where the cursor is and grow it *down*. The renderer
works by comparing lines, so it has to know how many rows the picture occupies before the
terminal draws it. `Image::render()` therefore returns that many lines: the first `rows - 1`
empty, and the last one `\e[<rows-1>A` followed by the image sequence. By the time the
terminal draws, the cursor is back at the top of the block and the picture fills exactly the
rows already accounted for.

`TuiBase::checkWidth()` skips any line holding `\e_G` or `\e]1337;File=`: an image line is tens
of kilobytes long and zero columns wide, and measuring it would fail the width check.

## The terminal's reply to a query arrives as keystrokes

`CSI 16 t` asks how many pixels a character cell is, which is the only way to know how tall a
picture will be. The answer comes back on **stdin**, so it has to be sifted out of the input
before a component reads it as typing. It can also arrive split across reads, so a partial
escape sequence is held back — but only until something that looks finished turns up, because
a terminal that never answers must not swallow the user's typing for the rest of the session.
Only asked on a terminal that draws images, since nothing else uses the answer.

Regression tests: `ImageTest::testTheReplyIsTakenOutOfTheInputAndTheRestStillArrives` and
`testATerminalThatNeverAnswersDoesNotSwallowTyping`.

## An input method draws where the terminal's cursor is, not where the caret is drawn

A component paints its cursor as an inverted cell; the terminal has a cursor of its own, and macOS
draws the composing text and the candidate list there. Upstream's mechanism, which pig now uses:
a `Focusable` component (`public bool $focused`, set by `TuiBase::setFocus()`) puts
`TUI::CURSOR_MARKER` (`\e_pi:c\a`, zero-width APC) just before its drawn cursor while focused; the
renderer finds it with `extractCursorPosition()`, strips it, and moves the hardware cursor there
(`positionHardwareCursor()` in `TuiMainScreen`, absolute in `TuiAltScreen`). The cursor stays hidden
unless `showHardwareCursor` is on.

**A wrapper must pass focus on.** `CustomEditor` and `SessionList` hold the real `Editor`/`Input` and
set `->focused` on it in `render()`; a focusable wrapper that does not stays silent and the candidate
list goes back to the bottom of the frame.

Regression tests: `TuiMainScreenTest::testTheHardwareCursorGoesWhereTheFocusedComponentPutItsMarker`,
`EditorTest::testTheCaretIsMeasuredInColumnsNotCharacters`, `InputTest::testTheCaret…`.

## A line starting with `/` is not a command, and neither is one that merely looks like a name

Reported from a real session, and the screen said the whole story:

```
Error: No command called /var/folders/mk/…/T/pig-clipboard-bf6615a630ccc5f2.png. Try /help.
```

Pasting a picture at the prompt writes it to a temp file and puts **the path** in the editor —
that is `Clipboard\ClipboardFile`, and it is deliberate, because a path works with every tool
that already takes one. So the line the person sends begins with `/`, and the submit handler
tested exactly that: `str_starts_with($text, '/')` → treat as a command. The path was reported
as an unknown command, and **the question typed after it was swallowed with it** — the model
never saw either half.

**The first fix was still a guess, and that was the real lesson.** It borrowed the
autocomplete's rule — a `/` at the start with no second `/` in the first word — which does tell
a path from a name, and kept pig's "No command called /setings" for the typo case. But "looks
like a name" is not "is a name": it is a second, looser notion of what a command is, living
beside the real one, which is the shape of the original bug rather than a fix for it.

So dispatch is **exact, against the names that exist** — `COMMANDS`, the hooks' registered
commands, and the ones kept as files, matched on the first word. `InteractiveMode::commandName()`
answers with a name or null, and null means the line is a message. Upstream's rule, which it
spells eighteen times (`text === "/settings"`); once here, because the hook and file names are
only known at runtime.

What follows, and is the price: **`/setings` goes to the model as text.** There is no error and
no "did you mean", because deciding a typo is a typo means guessing at what somebody meant, and
that is the thing being removed. `/nonsense` is a message too.

**The same rule was spelled by hand in three places**, and each hand-spelling was wrong in its
own way:

| Where | Question it is really asking | Was |
|---|---|---|
| the submit handler | is this line a command? | `str_starts_with($text, '/')` → read a path as one |
| `apply()` | should the completion insert `/name ` or a path? | right already — `couldBeACommandName()` |
| `shouldCompleteFiles()` | does Tab mean "complete a path"? | `starts with / and has no space` → **would not complete an absolute path typed at the start of a line**, while completing the same path fine one space to the right |

The third was found by asking the other two's question of it, and it is fixed the same way:
Tab belongs to the command list only while the cursor is still in the first word *and* that word
could still become a name. Past the first space Tab is a path again, which is what
`/export ~/out.html` needs.

**Two callers, two questions.** `couldBeACommandName()` is about *typing* and is private to the
autocomplete: a bare `/` counts, and so does a half-typed `/mod`, because that is what a
completion list is for. Dispatch is about *a finished line* and is exact. Sharing one predicate
between them is what made the first fix wrong, and there is a smaller version of the same trap
inside the autocomplete: requiring the name to be non-empty — correct for a submitted `/`, which
names nothing — silently broke command completion, because a bare `/` is the prefix every
command completion starts from, so picking `compact` off the list inserted `compact` with the
slash eaten. **A predicate two callers share is answering two questions, and the stricter caller
does not get to tighten it for the other.**

Regression tests: `InteractiveModeTest::testAPastedPathIsAMessageAndNotABadCommand`,
`testALineThatNamesNoCommandIsSentAsTheTextItIs`,
`testANearMissIsNotTreatedAsTheCommandItResembles`,
`testAFileCommandsNameIsMatchedExactlyLikeAnyOther`; and in `AutocompleteTest`, the eight
`testACommandNameIsToldFromAPathWhileTyping` rows,
`testTabCompletesAnAbsolutePathTypedAtTheStartOfTheLine`, and
`testCompletingFromABareSlashKeepsTheSlash` — the last being the one that fails if the
non-empty check goes back in.

## A slash command only worked in front of a screen

The whole of `commandName()` above lived in `InteractiveMode`, and so did what happened next: a
hook's command was *run* there, and a command kept as a file was *expanded* there. So both worked
in the terminal and nowhere else — `pig -p "/deploy"` and RPC's `prompt` handed the model the line
as text and left it to guess what `/deploy` meant. Reproduced from both doors before it was fixed:
`-p "/deploy staging"` never called the handler, and `-p "/review src/Foo.php"` sent eight
characters where a stored prompt should have gone.

Upstream does both inside `AgentSession.prompt()`, and the reason the fix waited a batch is that
the *question* looked split: pig's exact-match dispatch also knows the built-ins, so moving one
half in would put one decision in two places. It is not split. **The built-ins are the screen's and
the other two are the conversation's**, which is upstream's line exactly: `/help` draws on a
terminal and has nothing to do in `-p`, where a hook's command is code the session should run and a
file command is text the session should send. So `prompt()` gained both halves and
`InteractiveMode` kept only which door the text goes in by.

Four things decided on the way, and the first is the one to remember:

- **The hook half goes in front of the "already working" throw.** Upstream's comment says *"Hook
  commands always run immediately, even during streaming"* and its code throws there, so the two
  disagree — and pig takes the half with the reason attached, as it does for the image token count
  and the `data:` prefix. A hook's command is not a message; there is nothing for it to wait behind.
  `testAHookCommandStillRunsWhileTheAgentIsWorking` is the only test that fails when the dispatch
  moves one block down.
- **The expansion is on all three doors** — `prompt()`, `steer()` and `followUp()` — because a
  stored prompt typed during a turn goes in by one of the other two, and upstream queues the raw
  line at both, so `/review foo.php` reaches the model there as `/review foo.php`. Two of those
  three ends were silent to the mutation check until a test typed a file command mid-turn.
- **The queue keeps the line as it was typed and the agent gets the expansion.** `clearQueue()`
  hands that list back to the editor, and putting a forty-line stored prompt in front of somebody
  who typed `/review foo.php` is not putting their text back.
- **There is no `expandSlashCommands: false`.** Upstream declares the option and nothing in either
  tree passes it — a branch for a caller that does not exist, which is the standard the whole
  `rpc-types.ts` note is held to.

And the harness had the gap this file has now named twice: `InteractiveModeTest` handed the file
commands to the **mode** and not to the **session**, exactly as it once handed the settings to one
and not the other. The expansion would have been live in production and inert in every test.

Regression tests: `PrintModeTest::testAHookCommandRunsHereRatherThanReachingTheModelAsText`,
`testAFileCommandIsExpandedHereTooRatherThanSentAsItsOwnName`,
`testATextThatOnlyLooksLikeACommandIsSentAsTheTextItIs`,
`RpcModeTest::testAHostsSlashCommandsReachTheSameDispatchTheTerminalDoes`, and in
`InteractiveModeTest` the two above plus
`testAFileCommandQueuedMidTurnReachesTheModelAsItsPromptAndComesBackAsItsName`.

## Whatever holds the focus must be in a container only its own opener clears

Found by being asked the right question about a field I had just deleted. `InteractiveMode` had
a `private ?SelectList $picker` written by four callers and read by none, and "written and never
read" looked like a complete answer: the object is kept alive by the container that draws it, so
the field could go. It was the wrong answer. **The field was a missing reader, not a redundant
write** — and what was missing was reachable in three keystrokes.

`$this->status` had three kinds of writer. The pickers and `/settings` put a focused component
in it. The progress loaders — `onStart()`, `onEnd()`, `onRetryStart()`, `onOverflow()` — clear it
and put a `Loader` in, and those run from **agent events, which arrive without anybody pressing a
key**. `TerminalUi` puts a hook's dialog there as a third. So:

```
/settings while a turn is running   → the screen is drawn, focus is on it
the turn ends, onEnd() clears       → the screen is gone
                                    → THE FOCUS IS STILL ON IT
next keystroke                      → goes to an invisible list
```

Reproduced before it was fixed, and the second half is the part worth remembering: after the
screen vanished, Down and Enter still changed a setting. Somebody typing what they thought was a
message into the editor was walking down an invisible list changing things, with nothing on
screen to say so. `/settings`, `/login` and `/logout` reach it directly — the three pickers that
refuse while streaming refuse for a different reason (they would change the conversation
underneath it) and are covered by accident.

The fix is a second container, `$overlay`, drawn between `$status` and the editor: what is
*happening* and what is being *asked* have different writers, and the writers do not know about
each other. Upstream never had this bug because its arrangement already separates them — a
selector replaces the **editor** (`editorContainer`) while progress lives elsewhere.

**Not a flag.** Restoring `$picker` as something the loaders check was the cheaper option and the
wrong one twice over: it makes every *other* writer responsible for remembering, which is the
arrangement that just failed, and the only behaviour it can offer is hiding the retry countdown
while a picker is open — a countdown that exists so eight silent seconds do not look like a hang.
Two containers means both are drawn, which is the honest answer, because both are true.

The rule, and it generalises past this screen: **the two halves of closing something — the
container letting go and the focus moving — have to happen in the same place.** Every clear of a
container that can hold the focus sits next to a `setFocus()`: `closePicker()` and
`TerminalUi::close()` are the only two, and that is the invariant.

Splitting the containers left one writer still able to collide, from the other side: a hook's
dialog and a picker both belong in the overlay, so a tool call arriving while `/settings` was
open cleared it. Milder — the dialog *takes* the focus and hands it back to the editor, so
nothing is invisible-and-focused — but the person's screen still vanished without a word.

`TerminalUi` already had the answer for its own half of this and it generalised: **a second
dialog is refused with its safe answer rather than stacked**, because taking focus from the
first would park that fiber forever. So the guard now asks two questions rather than one —
`$busy` for a dialog of its own (which covers the moment a `custom()` factory is still
building, when nothing is drawn yet and the overlay looks free), and `$overlay->children()`
for anything else holding the focus. A refusal answers `false` from `confirm()` and `null`
from the rest, which is exactly what escape answers, so it travels as an answer somebody
could have given and no turn is parked.

And it is **said**. `notify()` puts a line in the transcript naming why the question was not
asked, because a tool quietly denied is a tool that looks broken — the same rule as no silent
fallback, applied to a UI.

The overlay's contents are the state here, not a flag: `canAsk()` asks the container what is
in it. A flag would be a second copy of the same fact, and a second copy that can disagree is
the shape of the bug this whole entry is about.

**And the dialog says which keys work**, which for a while it did not. Upstream's three components
each end with a hint line — `↑↓ navigate  enter select  esc cancel`, `enter submit  esc cancel`,
`ctrl+enter submit  esc cancel  ctrl+g external editor` — and pig folded all three into
`TerminalUi`, hint lines included, except that the hints did not come with them. `/tree`,
`/resume`, `/model` and `/settings` all carry one, so a hook's dialog was the one screen here that
did not say how to answer it — **in front of a parked turn**, where the line saying escape works is
the one that matters: the alternative to reading it is guessing, and guessing wrong on a screen
holding a turn is how a session gets killed. It is one argument to `open()` now, because one place
opens all four. `custom()` passes none: a hook that drew its own component knows its own keys and
pig does not. Ctrl+G is named only when there is an external editor to hand the text to, which is
upstream's rule for the same hint — a key in the list that does nothing is worse than a key missing
from it.

Regression tests: `InteractiveModeTest::testATurnEndingDoesNotEraseAnOpenScreenFromUnderTheCursor`
(fails if `showSettings()` is pointed back at `$status`),
`testAWorkingLoaderAndAnOpenScreenBothFitOnTheScreen`, and
`HookUiTest::testADialogIsRefusedWhileSomethingElseHoldsTheOverlay`,
`testARefusedDialogSaysWhyRatherThanFailingQuietly`,
`testTheOverlayBeingFreeAgainLetsTheNextOneThrough` (all three fail with the overlay half of
`canAsk()` removed).

## `Container` is a `Component` and not an `InputHandler`, so a container given the focus eats every key

The same family as the one above, and it bit in the opposite direction: not a component that
should have implemented an interface, but a component that looked complete because the
interface it was missing is optional.

`/settings`' thinking row opens a submenu, and a submenu wants a title and a hint above and
below the list — so it was a `Container` with a `Text`, the `SelectList` and another `Text`
in it. That compiles, `setFocus()` takes it (focus is typed `Component`), and it draws
correctly. Then every key went nowhere: `Container` forwards drawing to its children and
forwards nothing else, so the arrows, Enter and Escape all landed on an object with no
`handleInput()`. The screen opened and could not be navigated *or closed*.

`Interactive\SettingsSubmenu` is that container with the keys wired through — upstream's
`SelectSubmenu`, which exists for exactly this reason and says so in one line.

**Anything that can hold the focus has to be asked whether it handles input, and a container
never does.** `SettingsList::handleInput()` checks `instanceof InputHandler` before
forwarding to a submenu, which is what keeps this a screen with nothing to press rather than
a crash — and is why the bug was silent.

Regression tests: `InteractiveModeTest::testAThinkingModelGetsARowThatOpensTheLevels` and
`testChoosingALevelSetsItOnTheSessionAndRemembersIt` navigate *inside* the submenu, which is
what a test asserting only that it opened would have missed;
`SettingsListTest::testASubmenuThatCannotTakeKeysIsDrawnRatherThanCrashedOn` holds the guard.

## A window being dragged is a SIGWINCH per pixel, and three things each did too much per signal

Reported as "resizing is slow, pi is smooth", and the measurement that settled it was a pty with a
60Hz drag over 30 widths and a thread reading frames as they land. Both tools draw 30 frames for it
— one per distinct width — so the cadence was never the problem. Three things were:

- **`stty size` in the signal handler.** 4ms a fork, once per signal, serialised, with the frame
  queued behind. Node's `process.stdout.columns` is an `ioctl` for nothing; PHP has no `ioctl`, so
  the handler marks the size stale and `columns()`/`rows()` measure **once per frame** when first
  asked, however many signals arrived since.
- **A frame per signal, including the ones that changed nothing.** A resize-only render request
  whose width and height match the last frame draws nothing — no `\e[3J\e[2J`, no flash.
  `requestRender(resize: true)` is how the resize handler says so, and a content change arriving
  while that request is pending turns it back into a real one.
- **EINTR in `ProcessTerminal::write()`.** A SIGWINCH during the `select()` that waits for the tty
  buffer to drain is a PHP *warning*, and a warning goes to the terminal — in raw mode, mid-frame.
  `Loop::poll()` already had the handler for exactly this (the trap entry above); its sibling in
  `write()` did not. First shape from the index, on a `select()`.

`MIN_RENDER_INTERVAL` is upstream's 16ms: a request inside the interval waits for the rest of it,
and a burst in that window is one frame. The test for it measures *time*, because `frame()` ticks
once and a tick blocks in `select()` until the timer fires — the held-back frame is visible as a
delay, not as an empty tick.

**What did not change: the 89ms per frame on a wide pane**, which is `write()` waiting for the tty
to drain 10KB in 1KB pieces, each `select()` wait ~2ms. pi pays the same (its median gap was
106ms in the same harness); it is the terminal's read rate, not pig's.

## Widening the window left the right-hand side blank, and the terminal was innocent

Reported as "pi fills the new width at once, pig's right side stays blank for a while". The first
measurements said both tools emit the same 24 frames at the same cadence on a short transcript, and
the Terminal.app write cost is the same for both — so the drag entry above was true and this was a
different bug. What separated them was the session: on a resumed 1,144-message conversation, one
widen to 169 columns against pi on the **same file**:

```
pi:   first byte 125ms after the signal, frame complete at 146ms, 2170KB
pig:  first byte 823ms after the signal, frame complete at 915ms, 2529KB
```

Nothing a terminal does explains 700ms before the first byte; that is the frame being *built*.
Profiled component by component — 1,144 children, 560ms of render, 240ms of width checks, the rest
in `write()` — and it was four things, each of which looked cheap in the place it was written:

- **`BashOutputComponent` wrapped the whole output to show its tail.** Five rows kept out of a 50KB
  build log meant a 50KB wrap at every new width, and this session has 280 of those: 460ms of the
  560. The entry on `BashOutputComponent`'s missing cache three batches up fixed the *per keystroke*
  cost and left the *per width* cost exactly as it was, because a cache keyed on width is empty at
  every new width. It wraps from the end now and stops when it has the rows; `TextWrap::rows()`
  counts what the dropped head would have made, by arithmetic for a plain line — held to the real
  wrap over 20,000 random lines — and by wrapping for anything styled or wide.
- **`Width::visible()` segmented every styled line.** The fast path is "printable ASCII → `strlen`",
  and every line of a transcript carries a colour, so every one of them failed it and went through
  `\X`. 12,800 lines, measured once each by `checkWidth()` before writing: 236ms. Upstream's
  `asciiVisibleWidth` skips the escapes and counts the rest; pig does the same **after**
  `Ansi::strip()`, where the cache above it cannot help because a frame at a new width is 12,800
  strings it has never seen.
- **`TextWrap::breakWord()` cut a minified line grapheme by grapheme**, 10ms for 46KB and again at
  every width. A word of printable ASCII is one column a byte; it is `str_split` now, with the
  opening codes on the first piece and the closing codes on the last, which is where the tokenizer
  hangs them — and the two paths were diffed byte for byte over 3,000 random styled words before
  the first draft, which put the opening codes on *every* piece, was replaced.
- **`ProcessTerminal::write()` copied the rest of the frame on every short write.**
  `substr($data, $written)` with a tty taking a kilobyte at a time is 2,400 copies of 2.5MB — 72ms
  of `memcpy` for the one loop whose whole point is waiting on something slower than `memcpy`. A
  64KB slice.

```
pig now:  first byte 194ms after the signal, frame complete at 227ms
```

**What is left is the bytes.** pig's frame is 2.5MB to pi's 2.2MB and the terminal drains both at
the same rate, so the remaining gap is in what the components emit — and that is not something a
profile of the render finds. Two things worth knowing before anybody chases it: the per-frame
`write()` on a wide pane is tty drain and pi pays the same (measured, 89ms against 106 in the same
harness); and pi's transcript for that session is 10,246 lines to pig's 12,805, so a line count is
where to start.

The method is the entry, more than the fixes: **a profile of one frame, by class and then by
instance, against a real session** — not a synthetic transcript. The synthetic one three batches
ago was built from `Text` components and reproduced none of this.

Regression tests, one per fix, each red on its own mutation:
`BashOutputTest::testCollapsingALongOutputCostsTheTailAndNotTheWholeOfIt`,
`testTheDroppedCountIsCountedInRowsWhetherOrNotTheHeadWasWrapped`,
`testATailThatWrapsPastTheRowsIsCutAndCountedToo`; `TextWrapTest::testRowsCountsWhatWrapWouldHaveMade`,
`testAPlainWordIsCutToTheSameBytesWhicheverPathCutsIt`;
`WidthTest::testAStyledAsciiLineIsMeasuredWithoutSegmentingIt`. The slice has no test of its own:
`ProcessTerminalTest::testAWriteBiggerThanTheBufferStillArrivesWhole` already drives a megabyte
through a pipe and holds the bytes whole, and the slice changes only how often that loop goes round.

## `fwrite()` to a terminal returns short, and STDOUT is non-blocking whether you asked or not

`ProcessTerminal::write()` called `fwrite()` once and ignored what it returned. A frame is
tens of kilobytes; a tty buffer is a few. Measured: a 1,000,000-byte write put **65,536**
bytes on the wire and dropped the other 93%, and an 11,330-byte frame — the real first
frame at 178 columns — lost 3,138 bytes. What gets dropped is the middle of an escape
sequence, so from there every cursor move is against a screen holding something else. On
screen it looks like frames stacking up instead of replacing each other.

The second half is why it is easy to miss: **`stream_set_blocking($input, false)` makes the
output non-blocking too.** STDIN and STDOUT are dups of one open file description when both
are the terminal, and `O_NONBLOCK` belongs to the description, not the descriptor. So the
input watcher puts the output in a mode where short writes are normal, at a distance, in
another method.

`write()` now loops until everything is out, waiting on `stream_select()` for the writable
side when the buffer is full, and throws after five seconds rather than spinning. Anywhere
else this codebase writes to a stream it may not own, check the return value the same way —
a partial write is the quietest failure there is.

Regression test: `ProcessTerminalTest::testAWriteBiggerThanTheBufferStillArrivesWhole`. It
writes through a real pipe, because `FakeTerminal` accepts whatever it is given and so can
never reproduce this — which is exactly why it shipped.

## Closing a style with `\e[0m` closes whatever it was nested inside

`Style::bold()` and friends used to end with a full reset. A full reset turns off
*everything* — including a colour somebody else opened around that text. So a bold word
inside a red line left the rest of that line un-red, and the coding agent, which wraps
everything in a palette colour, hit it on the first component that used both.

Each attribute now closes with its own off-code: `22m` after bold or dim, `23m` italic,
`24m` underline, `27m` inverse, `29m` strikethrough, and `39m`/`49m` after a foreground
or background colour. The one exception is `Style::of()`, which is handed arbitrary
codes and cannot know which off-code goes with each — so a combination built that way is
a leaf style, and nesting is done with the named methods.

Regression tests: `ComponentsTest::testEachStyleClosesOnlyWhatItOpened` and
`testAStyleNestedInAColourLeavesTheColourStanding`.

## A style must not be left open at the end of a line

`TuiMainScreen` compares frames line by line, so a line is the unit that has to be self-contained:
a colour opened on one line and closed on the next means the second line carries a style
it never asked for, and a differential redraw of only the first line leaves the rest of
the screen tinted.

This bites anything whose tokens can span a newline — a block comment, a triple-quoted
string. `Highlight` therefore splits every token on `\n` and styles each piece on its own
line, rather than styling the token once and splitting afterwards.

Regression test: `HighlightTest::testEachLineClosesItsOwnStyles`.

## Inverse and strikethrough paint a blank cell, and the rule said only underline does

The third entry in this family and the one where the reason was written down and was wrong.
`AnsiTracker::lineEndReset()` turns off what must not run past the text on a wrapped line, and it
turned off **underline only** — upstream's rule, with upstream's reason on it: *"Only underline
causes visual bleeding into padding. Other attributes like colors don't visually bleed to padding."*

True of a colour. False of two others, and the test is simply **whether the attribute paints an empty
cell**:

| | on a space |
|---|---|
| underline (4) | a rule drawn through it |
| **strikethrough (9)** | **a rule drawn through it** |
| **inverse (7)** | **the foreground swapped in — a solid block of colour** |
| bold, dim, italic | nothing |
| blink, conceal | nothing visible |
| foreground | stops where the characters stop |
| background | *has* to survive — a padded row should reach the edge |

Both of the two are reachable: `DiffView::mark()` wraps a changed run in `Style::inverse()`, and
`Markdown` strikes through `~~text~~`. Reproduced through the real components rather than the
primitive — a diff of one rewritten line, drawn in a 44-column `Text`:

```
0 !!  \e[38;5;167m-12 return $this->\e[7moldCall($a, $b) +
1     \e[7;38;5;167m$this->somethingElseEntirely($c) ?? 0\e[27m; //
3 !!  \e[38;5;143m+12 return $this->\e[7mnewThing($x, $y) +
```

`!!` is a line handed to the terminal with inverse still on, so every padding space after it is
filled to the right edge — two rows per edited line, on the ordinary case of a line whose middle was
rewritten. The marked run only has to be long enough for the wrap to fall inside it.

`PAINTS_A_BLANK_CELL` is the table now, in SGR order so the sequence reads `24;27;29`, and only the
attributes actually on are emitted — so the underline-only case is still exactly `\e[24m`, which is
what upstream's own test asserts with `endsWith`.

**And the rest of `wrap-ansi.test.ts` passes as written**, which is worth the words because that file
is a specification of this whole family: no underline code on the line *before* the styled text, a
background on every continuation line, a background surviving an underline that closes per line, and
a colour re-opened at the start of each continuation — all four run against pig and all four agree,
byte for byte on the one upstream asserts exactly.

Regression tests: `TextWrapTest::testInverseIsClosedAtEachLineEndToo`,
`testStrikethroughIsClosedAtEachLineEndToo`, and
`testOnlyTheAttributesThatPaintABlankCellAreClosed` — which is the one that stops the fix becoming
"close everything", since closing bold or a foreground would cost a code per line for nothing, and
closing the background is the bug the whole mechanism exists to avoid. Dropping any one of the three
rows turns exactly its own test red.

## The last block of a message left a blank line under itself, and pi does not

Reported with a screenshot: a one-line user message sitting on **two** empty rows of its own
background, where pi draws one. `Markdown::block()` adds the blank line that separates one block
from the next, and it added it after the **last** block too 
dash `$next === null` fell through to
the `$lines[] = ''`. Upstream guards every one of its eight `lines.push("")` with
`nextTokenType && 
dash`, so its last block ends where its text ends and the gap after a message is
whoever placed it: a `Spacer`, or the component's own `paddingY`. One row per user message, per
assistant message, per tool result 
dash invisible on any single frame and a transcript a few
percent taller than pi's on every one.

Two tests were pinning it (`['```php', '  $x = 1;', '```', '']` and "three rows of text, then the
blank line a paragraph leaves after itself"), which is the shape this file keeps naming: *a test
written against what the code says*. Regression test:
`MarkdownTest::testTheLastBlockLeavesNoBlankLineUnderItself`, which also asserts the blank
**between** two blocks stays, so the fix cannot become "never add one".

## A per-line prefix has to go on after the wrap, not before

`Markdown` styles a block, then wraps it. That is right for *inline* styling — the wrapper
carries escape codes across its own breaks — and wrong for anything that must appear at the
start of every line. A block quote built as `"│ " . $text` and wrapped afterwards comes out
with the border on its first row and the remaining rows hanging in the margin, which reads as
the quote having ended.

So `quote()` wraps its children to `width - 2` itself and prefixes each resulting line. Found
by looking at rendered output, not by a test — every assertion about widths and codes passed.

Regression test: `MarkdownTest::testEveryLineOfAWrappedQuoteKeepsItsBorder`.

## `Highlight` hands back the code unchanged when it has no grammar

Which is right in a terminal — an unknown language is printed as it is — and is an injection
hole on a page. `MarkdownHtml::code()` therefore asks `Grammar::for()` first and escapes the
lines itself when there is none, rather than trusting the highlighter to have touched them.

A tool result is the obvious way in (a `<script>` in a file the model read), but so is any
fenced block in an answer whose language pig has no grammar for, which is most of them.

Regression test: `HtmlExportTest::testAToolCallSaysWhichToolAndWhatOn` asserts `&lt;?php` in
an un-languaged result. `testAJavascriptLinkIsNotALink` covers the other half: a
`javascript:` href in a transcript is a script the model wrote, running when someone opens
the file, so only `http(s)`, `mailto` and `ftp` become links at all.

## Typing mid-turn had one door, and the second one upstream has is Alt+Enter

Reported from a pi session the developer had open beside pig:

```
 Steering: 不错
 ↳ Option+Up to edit all queued messages
```

pig had the steer — Enter during a turn went to `AgentSession::steer()` and drew `Queued: …` — and
nothing else: no follow-up key, no way to take the queue back short of escape (which also stops
the turn), and a label that did not say which of the two queues the line was in. The two mean
different things to the person watching, and they are upstream's `app.message.followUp`
(`alt+enter`, `ctrl+q` on Windows) and `app.message.dequeue` (`alt+up`, `alt+q` on Windows):

| key | upstream | now |
|---|---|---|
| Enter mid-turn | `prompt(text, {streamingBehavior: "steer"})` | `steer()`, drawn `Steering:` |
| Alt+Enter mid-turn | `prompt(text, {streamingBehavior: "followUp"})` | `followUp()`, drawn `Follow-up:` |
| Alt+Enter idle | `editor.onSubmit(text)` | the Enter byte, through the same submit path |
| Alt+Up | `restoreQueuedMessagesToEditor()`, no abort | `clearQueue()` into the editor, turn carries on |

Four things decided on the way:

- **The editor read Alt+Enter as a new line**, in `Editor::isNewLine()` — `"\e\r"` is one of the
  spellings particular terminals send for Shift+Enter, and upstream's editor has the same line.
  Nothing in `Editor` changed: `CustomEditor` claims the key through `Keybindings` before the text
  field sees it, which is the arrangement every other application key already uses, so a
  `keybindings.json` that unbinds `app.message.followUp` gives the new line back. `EDITING_KEYS`
  stopped saying `shift+enter` means "a new line, and alt+enter".
- **Idle Alt+Enter is handed to the editor as `"\r"`** rather than through a second submit door.
  `Editor::submit()` is private and does two things Enter does — expands the paste markers and
  honours `disableSubmit` — and a second route to "send this" is the first shape from the index.
- **The hint says the key the way it is printed on it.** `Keybindings::display()` is upstream's
  `keyDisplayText()`: capitalised, `alt` said as `option` on a Mac. `label()` stays the help
  table's spelling; a sentence on screen wants the other.
- **A file command typed mid-turn was a follow-up and is a steer**, which is upstream's
  `handleSubmit` for every line of text: `/review foo.php` typed during a turn means "and look at
  this now", not "after you finish". It was the one place the two queues were still swapped, and
  `testAFileCommandQueuedMidTurn…` had been asserting `Queued:`, which could not tell them apart.

Five mutations, five kills: each binding, `followUp()` steering instead, the hint line, and the
idle-is-Enter branch. `AgentSession::queuedByKind()` is the one new accessor, because the screen
has to know which list a line is in and `queued()` flattens them.

## Ctrl+L was the third key taken off the prompt and bound to nothing

The last find of the audit, out of a 106-line component nobody expected anything from.
`custom-editor.ts` is eleven `if`s and pig's `CustomEditor::claimed()` is the same eleven as a
`match` — and one of the eleven, `ctrl+l`, was claimed and never bound. Upstream's `onCtrlL` opens its
model selector; pig had the key reserved for a handler it never registered, so pressing it took the
byte off the text field and did nothing at all.

**Third time, and the worst of the three**, because Ctrl+L is the one key in that set a terminal
already has a meaning for: pressing it out of habit to clear the screen did not even do that.
Ctrl+G was invisible (nothing had ever worked), ctrl+p was invisible, and this one is a key people
press expecting a specific thing. It is bound to `showModels('')` now — `/model` with nothing after
it, which is upstream's selector — and it has a row in `KEYS`, because the entry on the eleven
editing keys is about exactly this: a key that works and is named nowhere is half of a key.

The rule, stated for the third time because three is a pattern: **a component that claims a key is
making a promise the application has to keep, and nothing checks it.** The list to compare is
`CustomEditor::claimed()`'s arms against the `$this->editor->on(...)` calls in `bindEditor()` — one
grep, and it would have caught all three at once.

Regression test: `InteractiveModeTest::testCtrlLOpensTheModelPicker`.

## Ctrl+G was being reported to nobody

`CustomEditor` has recognised Ctrl+G since it was ported — `claimed()` turns it into the
named key `'ctrl+g'` — and `InteractiveMode` never bound it. Pressing it did nothing at all,
while the component behaved as though someone were listening. A forwarded key with no handler
is invisible: there is no error, no warning, and nothing to search for.

It is bound now, to upstream's `openExternalEditor()`: write the prompt to a temp `.md`, stop
the TUI, hand the terminal to `$VISUAL` or `$EDITOR` through `Process::interactive()`, read it
back, start the TUI again. Non-zero exit keeps the original, which is what `:cq` means.

**One difference from upstream, and it is pig doing more rather than less: the loop keeps
turning while the editor is open.** Upstream blocks on `spawnSync`, which stops libuv for as
long as the person takes; a model streaming into a socket nobody reads fills the receive
buffer, goes zero-window, and eventually has its connection reset. So a long edit during a
turn can cost the turn.

The first version here guarded against that by refusing Ctrl+G while the agent was working.
That was wrong, and it is worth saying why: the case Ctrl+G is *most* wanted for is "the model
is busy, my next message is three paragraphs" — which is the same thing as typing while it
streams, pig's own headline feature, only in vim. The guard forbade exactly the best use, and
upstream not guarding it is very likely because that is how it is used. Upstream does guard
other things on `isStreaming`, so it is not an oversight of the pattern.

pig can have both, because it has an event loop where upstream has a blocking call:
`Process::interactive()` takes a `$yield` closure, polls the child, and yields between looks,
so the socket is read and the turn is appended exactly as it would have been. The TUI is
stopped, so none of it is *drawn* until the forced redraw on the way back — nothing can be
done about that while vim owns the screen, but there is nothing to lose by not reading.

Two things that make it safe, both checked rather than assumed:

- `ProcessTerminal::stop()` cancels the stdin watcher and restores the saved `stty`, so pig
  and the editor are never both reading the keyboard.
- A pending timer keeps `Loop::isIdle()` false, so the polling delay is itself what stops
  `run()` from returning — and the program exiting — while the editor is open.

Both callers must spawn a fiber: the key handlers run inside the loop's input callback, and
suspending there suspends the loop that called them. Same reason `send()` and
`startCompaction()` spawn.

`Process::interactive()` has **no timeout**, alone among that class's methods. That is not an
upstream difference — `spawnSync` takes one and `openExternalEditor()` does not pass it either
— it is a difference from pig's own convention, where every other method there has one so a
hung command cannot hang pig. Here a timeout would kill the person's editor mid-sentence.

Barely covered by a test: `interactive()` opens `/dev/tty` for all three streams, and a test
runner has no tty to open. What is tested is the empty-command guard and that a run with no
controlling terminal answers STOPPED without polling — which is the branch a session started
from a script takes.

## A float took every argument off the screen for one byte

`packages/ai/src/Utils` swept with a filter per file, derived as the entry above says. 456 mutations,
354 killed, 13 detected by hanging the suite, 89 surviving — and the survivors are concentrated in
the two files this document describes as hand-written stands-in for npm packages: `PartialJson` (32
of 97) and `JsonSchema` (40 of 171).

**Both are files whose verification was a corpus that was never kept.** `PartialJson`'s docblock
records a run against `partial-json@0.1.7` "over every prefix of a corpus of realistic tool
arguments", and `JsonSchema`'s records ~190 pairs against `ajv@8.20.0`. Those runs found real bugs
and are why both files are trusted. What is in the suite is three test methods for the first and 38
for the second. *A corpus is evidence about the day it ran; a test is evidence every day.* The traps
above say a corpus is worth running and worth keeping the count of — this adds the third clause:
**worth keeping as a test**, or the code it verified goes back to being unpinned the moment the
corpus is deleted.

Rebuilding `PartialJson`'s as a test found a live bug on its first run, at one byte of every float a
model has ever sent:

```
{"path":"/tmp/a.php","temperature":0     => {"path":"/tmp/a.php","temperature":0}
{"path":"/tmp/a.php","temperature":0.    => []
{"path":"/tmp/a.php","temperature":0.7   => {"path":"/tmp/a.php","temperature":0.7}
```

**`is_numeric()` is not JSON's number grammar.** `isCompleteLiteral()` asked PHP whether a bare token
was a number, and PHP says yes to `0.` and `-12.` where JSON says no — so the scanner recorded the
safe end at the end of the input, built the repair `…"temperature":0.}`, `json_decode()` refused it,
and with no candidate left the whole object came back empty. Not the value: **the object**, including
the keys that had arrived thirty bytes earlier. On screen that is a blank frame in the middle of
every tool call carrying a float, and if the turn is interrupted on that byte it is worse than a
frame, because what a provider is then sent is `[]` — the empty arguments list that has an entry of
its own two screens up.

The grammar is written out (`/^-?(0|[1-9]\d*)(\.\d+)?([eE][-+]?\d+)?\z/`, `\z` for the reason that
trap gives). The other places PHP and JSON disagree — `+12`, `.5`, `012`, whitespace on either side —
are not prefixes of any valid JSON number, so no stream arrives at one, and refusing them leaves the
earlier keys standing instead of building a repair that cannot parse.

**Then the same test said the fix was half of one.** With the grammar corrected the object no longer
collapses, and the pair still drops out for that byte — `{"a":1,"temperature":0.` read as `{"a":1}`
where `{"a":1,"temperature":0}` is available and is what the byte before and the byte after both say.
The end-of-input branch keeps a bare token only if the whole of it reads as a value; it walks back to
the longest prefix that does now. Being briefly wrong about a value is this method's stated trade —
its own comment says so about `12` of `125` — and taking a key off the screen and putting it back is
not the same thing.

**The corpus had "numbers cut mid-digit" in it and missed this**, which is the entry on two pastes in
one read arriving in a second file: *a corpus is worth re-reading for the question it never asked.*
Cut mid-digit is `12` of `125`, and the decimal point is the one cut where the two languages'
notions of a number come apart.

The second finding is a boundary, in the branch this document spends the most words on:
**`Overflow::happened()` called a prompt that exactly filled the window an overflow.** `>` and `>=`
both passed, so the one thing that branch must not do — "a guess here compacts a conversation that
was fine" — was not written down. A conversation billed at exactly the window was answered; it fits.

What the property test asserts, and why it is a property rather than another row in `fragments()`:
for every prefix of a valid document, what comes back may hold less than the whole, and may hold a
value still being written, and may hold nothing — but it may never invent a key or contradict one,
which is exactly what a mis-placed safe end produces. The existing monotonic test could not see any
of this: it counts keys, and a wrong safe end counts the same keys while holding the wrong values.
*An assertion on how many is not an assertion on which.* The documents are chosen for the one shape
`fragments()` has no row for — a bracket that **closes** mid-document, which is where `open()` and
`close()` differ at all.

That took `PartialJson` from 32 survivors to 23 and `Overflow` from 4 to 3, and every one of the 26
left has a reason: four are `$x = false;` initialisers whose deletion **warns**, so `failOnWarning`
kills them where the shim does not (probed, not assumed — `Undefined variable $inString` on the line
that reads it); the rest are `?? null` against `''` in front of an `isset()`, and `>= 0` against
`> 0` on an offset that can only be 0 for a document not starting with `{`, which `parse()` answers
with `[]` either way.

Regression tests: `PartialJsonTest::testEveryPrefixSaysPartOfWhatTheWholeCallSaysAndNothingElse` over
six documents and every prefix of each, and
`OverflowTest::testAPromptThatExactlyFillsTheWindowIsNotAnOverflow`, which asserts both sides of the
boundary so the fix cannot become "never mind the silent case".

## A space that is not U+0020 emptied the search box

`Tui\Fuzzy` is what `/resume`'s searchable session list, `--list-models` and `SelectList` filter through, and
`filter()` split the query into tokens with

```php
preg_split('/\s+/', trim($query), -1, PREG_SPLIT_NO_EMPTY)
```

**No `/u`, after an ASCII-only `trim()`.** So a space that is not U+0020 was never a gap between
tokens — it was a character the query demanded to find. Measured against upstream over the same
inputs:

| typed | upstream keeps | pig kept |
|---|---|---|
| `tls␣handshake` with U+3000 | 1 | **0** |
| `tls␣handshake` with U+00A0 | 1 | **0** |
| `␣tls` with a leading U+FEFF | 2 | **0** |
| U+3000 on its own | 2 (nothing typed yet) | **0** |

**None of those is exotic.** U+3000 is what pressing space produces on a Chinese keyboard in
full-width mode, U+00A0 is what pasting from a web page brings, and a byte-order mark rides on the
front of anything copied out of a file. So the one keystroke somebody uses to narrow a list of
thirty conversations emptied it, and a lone full-width space — which upstream reads as *nothing
typed* and answers with the whole list — answered with nothing at all. It looked like the list had
lost its contents, which is the failure mode `Cli\SessionList` exists to prevent.

`PREG_SPLIT_NO_EMPTY` already drops the ends, so the `trim()` in front of it was doing nothing the
split does not; it is gone with the ASCII-only pattern.

**The whitespace class is now the union of PCRE's and JavaScript's, which took a codepoint-by-codepoint
check rather than a guess.** Over the whole BMP the two differ by exactly three: `﻿` is in
JavaScript's `\s` and not in PCRE's (it is a format character, not a separator), while U+0085 and
U+180E are in PCRE's and not in JavaScript's. Only the first is a mistake to inherit — a zero-width
no-break space is invisible, so a word after one is still at the start of a word — so `Fuzzy::SPACE`
adds it and keeps the other two. Same rule as `version_compare()` replacing upstream's arithmetic:
**where JavaScript is the one missing a case, the port does not copy the gap.**

Found by the ninth corpus run: **3,078 comparisons against `fuzzy.ts`** — every query against every
haystack, 600 random slices of a haystack used as their own query, and the ordering both produce.
43 differed before the fix and 36 after, and *the 36 are all one deliberate thing*.

**That thing is the second find, and it is a claim this file was making.** `Fuzzy`'s docblock said
the character walk was "the one deliberate difference" and that code units and characters are the
same "for everything either project searches". An **astral** character is two code units and one
character, so every position after one differs: `math 𝐀𝐁𝐂 astral letters` searched for `math 𝐀𝐁`
scores −241.4 upstream and −157.9 here. Emoji are astral and a conversation's text is full of them —
pig's own test corpus has ✅ and 🎉 in it. pig's answer is the **better** one, because the score is
built out of gaps and positions and a family emoji inflating a gap by eight where a reader sees one
character is upstream measuring the encoding rather than the text. So nothing changed except the
sentence that hid it, which is the fourth shape from the index found by running the thing the
sentence was about.

*The four notes about this file had all been read rather than run*, which is what the rule at the top
of the traps says to fix: **a hand-rolled replacement for a package is worth a corpus, and a file of
pure functions is worth one even when reading finds nothing.** Reading found nothing here twice.

Regression tests: `FuzzyTest::testASpaceThatIsNotU0020SeparatesTokens` (three separators plus a
leading mark), `testASpaceThatIsNotU0020StartsAWordToo` (four, the score half), and the two blank
cases added to `testABlankQueryGivesTheListBackInItsOwnOrder`. Each rule fails on its own mutation:
putting the ASCII split back turns two red, dropping `\x{FEFF}` from the class turns three red, and a
byte walk instead of `mb_str_split()` turns three red.

## Ctrl+P was taken off the editor and bound to nothing

Seventh find, and the cheapest kind to have: `CustomEditor::claimed()` has always answered
`'ctrl+p'` and `'shift+ctrl+p'` — taking both keys away from the text field — and
`InteractiveMode::bindKeys()` never registered a handler for either. Upstream binds them to
`cycleModel("forward")` and `cycleModel("backward")`, which pig never ported. So the keys did
nothing, and a key that is claimed and then does nothing is worse than either half.

Ported as `ModelResolver::next()` — a rotation over a list, which is all that was left once
`setModel()` gained the key check, the file write and the settings write. It lives beside `parse()`
rather than on `AgentSession` because two callers need the same arithmetic: ctrl+p, and RPC's
`cycle_model`. **That command is now here too**, and the docblock that said it was not needed
("`get_available_models` and `set_model` are what it is made of") had to go: it was true for a host
until pig needed the same rotation itself, and then leaving it out would have meant two
implementations of one list walk — this audit's subject exactly.

One quirk kept on purpose: a current model that is not in the list counts as being at position 0,
so ctrl+p from there lands on the *second* model. That is upstream's `indexOf` returning -1, and
`--model` pinned to a provider whose key has since gone is how somebody gets there.

## Ctrl+X: `app.message.copy` existed upstream and pig had neither the key nor the selection half

Upstream's `app.message.copy` (ctrl+x) is `handleCopyCommand({flashConfirmation, preferSelection})`:
in fullscreen with `fullscreenCopyOnSelect` off it copies the active mouse selection, otherwise the
last assistant message, and in fullscreen it says `Copied!` as a flash rather than a transcript line.
pig had `/copy` for the last-answer half, `TuiAltScreen::copyActiveSelectionToClipboard()` ported and
called by nobody, and no binding at all. Found while answering why ⌘C beeps after a fullscreen
selection — it does not reach pig; Terminal.app's Copy has no native selection to copy, and the
text was already on the clipboard from the mouse release — which is exactly the case the key is
for when copy-on-select is turned off.

`copyLastAnswer(flashConfirmation, preferSelection)` now carries both options; `/copy` calls it bare.

## Four keys out of eight were not upstream's, and a docblock said that was fine

The same find as the entry below, on a different shared file, and this time **the sentence that
should have prevented it is the one that caused it.** `CustomModels::compat()` read a
`models.json`'s compatibility block, and its docblock said:

> Upstream names four of these and this takes those four under upstream's own spellings … pig's
> `OpenAiCompat` has four more … a file that uses them is a pig file, which is the trade and it is
> stated.

Upstream's `OpenAICompat` in `types.ts` had **all eight** then, and the four it supposedly lacked
were right there with a `requires` prefix on each: `requiresToolResultName`,
`requiresAssistantAfterToolResult`, `requiresThinkingAsText`, `requiresMistralToolIds`. pig read
them without the prefix, so a `models.json` written for pi had four of its eight settings silently
ignored — and the last of the four is the one that matters most, because a Mistral-shaped endpoint
rejects any tool id that is not exactly nine alphanumeric characters. Somebody who had configured
their proxy correctly for pi got a 400 out of pig with nothing on screen to connect it to. (Upstream
has since dropped `requiresMistralToolIds` with Mistral's move to its own API, and pig with it.)

Three things worth taking from it:

- **A claim about what upstream has is worth a `grep`, not a sentence** — which this file says about
  itself in four other places, and which is exactly what a confident docblock buys you out of doing.
  The claim was wrong twice over: about upstream's count, and about whose the other four were.
- **The tell was there and it is the same tell as last time:** four spellings out of a group of
  eight not matching is a typo, not a design. `retry.maxRetries` was one out of three.
- **The short spellings are gone rather than kept beside them**, for the session format's reason:
  nobody has a pig `models.json` from a release, and a file holding both names for one flag is a
  file that can disagree with itself.

And the test that covered it was called `testACompatBlockUsesUpstreamsSpellings` and asserted three
of the four that were already right. *A test named after the rule is not a test of the rule.*

Regression test: `CustomModelsTest::testTheFourKeysWithARequiresPrefixAreUpstreamsToo`.

**The rest of `types.ts` matched, which is worth the words because it is 217 lines of nothing but
field names** — and a renamed or dropped field there is a wire difference with no error to announce
it. Checked one by one: the five `Api` values, twelve `KnownProvider` names, five reasoning levels,
the four option fields and `reasoning` on top of them, all four content types including
`textSignature`, `thinkingSignature` and `thoughtSignature`, `Usage`'s five counts and five costs,
the stop reasons (pig now has upstream's `pending`; its `deferred` is not ported), the three messages' fields, `Tool`, `Context`, **all twelve stream events**
down to `error` carrying its message under the field name `error` rather than `message`, and
`Model`'s twelve. Two differences with no defect behind them, now written where they live:
`ToolResultMessage`'s `isError` has a default where upstream requires it (every construction here
passes it), and `Context::$tools` is `[]` for "none" where upstream's is `undefined` — which is a
difference **on the wire**, because `[]` is truthy in JavaScript, so three of upstream's four
providers send `tools: []` for a conversation with no tools where pig sends no field. Only its
Google provider asks `.length > 0`, as pig does everywhere, and the one case where the empty field
is load-bearing already has a branch in `OpenAiCompletions`.

## One settings key out of seventeen was not upstream's

Third find from the audit, and the cheapest one to have prevented: **`retry.maxAttempts` where
upstream writes `retry.maxRetries`.** A `settings.json` written for pi had that number silently
ignored and got the built-in three.

What gave it away was not reading the key — it was reading its **siblings**. `retry.enabled` and
`retry.baseDelayMs` are both upstream's spellings, and `baseDelayMs` even carries a docblock saying
so ("as upstream writes it"). One key out of a group of three not matching is a typo, not a decision.
Sixteen of pig's seventeen settings keys were upstream's; this was the seventeenth.

It had a second layer: **CLAUDE.md asserted the thing that was false.** "Settings are upstream's keys:
`retry.enabled`, `retry.maxAttempts`, `retry.baseDelayMs`" — a sentence that would have stopped
anybody from checking. A claim about matching upstream is worth a diff, not a sentence.

And a third: **a test was passing because of the bug.** `AgentSessionTest` set `maxAttempts` in a
fixture and asserted two attempts; with the key corrected, the fixture stopped applying and the
assertion failed at three. The test had been documenting the wrong key rather than the behaviour.
That is worth remembering for the rest of the audit — a green suite proves the code and the tests
agree, and both were written by the same hand on the same day.

## `/model son` offered file names, and the one method that knew better had no caller

`SlashCommand::$argumentCompletions` is upstream's optional hook for a command that knows what its
own argument can be. pig reads it — `slashSuggestions()` has always called it — and **nothing ever
supplied one**, in either project: upstream declares the field, calls it, and no command implements
it. So its own docblock's example, *"`argumentCompletions` is what makes `/model <tab>` list models
rather than files"*, was a claim about the repository that the repository did not keep. Past the first
space a completion means a path, so `/model son` offered whatever files in the project happen to have
`son` in the name.

`/model` is the one built-in whose argument is a known list rather than free text, so it has the
closure now — the same `availableModels()` list `/model` with nothing after it draws, so the two
cannot come to disagree about what is on offer. Matched as a **substring** over `provider/id` and not
fuzzily, which is the one place `--list-models`' own trade-off does not apply: a subsequence match puts
`moonshotai/kimi-k2-instruct` under `haiku`, which is tolerable in a listing somebody is reading and
not in a list they are choosing from with one keystroke.

**And then the fix did not work from the keyboard, which is the more interesting half.**
`CombinedAutocompleteProvider::shouldCompleteFiles()` is the method that answers "does a completion
here mean a path", and it is **public with no production caller** — `Editor::completeOnTab()` spelled
the rule out itself, `starts with a slash and has no space`. That is the *fourth* hand-spelling of the
predicate the trap below about a line starting with `/` is entirely about: that entry gave the rule
one home and this reader never started asking it. The editor asks now, and the provider answers with
the one thing only it can know — which commands exist, and which of them answer for their own
argument.

Three ends, three mutations, and the third took three goes to pin. Removing the completer from
`/model` breaks it; making the editor spell the rule again breaks it; but the check inside
`shouldCompleteFiles()` is redundant for `/model ` with nothing typed (the trailing space is trimmed,
so the line still reads as a bare command name) and unreachable while a list is open, because Tab
there means "accept this one". The sequence that needs it is the ordinary one: type an argument,
**escape** to dismiss the list, then Tab — and that is what the test does.

## Every numbered list a model wrote with spaces in it came out numbered 1, 1, 1

`markdown.ts` is the one remaining file a surface map cannot read — upstream hands the parsing to
`marked` and walks its tokens, where `Markdown\Lexer` and `Inline` **are** the parser. So it got the
treatment `EditDiff`, `Truncate`, `Keys` and `PartialJson` got: **`marked` 15 installed, a corpus of
54 documents, both sides' block structure dumped and compared.** Two bugs, in the commonest thing a
model writes.

**A list's own numbers were thrown away.** `ListBlock` held its items and whether it was ordered, and
nothing else — so `3. three` / `4. four`, which is how anybody quotes steps three and four of a
procedure, was drawn as `1.` and `2.`. `marked` carries a `start` for this and **upstream ignores it
too**: its `renderList` is `${i + 1}. `, so this is a divergence in pig's favour, like `EditDiff`'s
numbering and the image token count.

**And a blank line between items ended the list.** `1. one` / blank / `2. two` / blank / `3. three` —
a numbered list whose items are paragraphs, which is most of them — was read as *three lists of one
item*, and each one started again at its own number. With all the items written `1.`, the idiom that
lets the renderer do the counting, it came out `1.` `1.` `1.` and no `start` could have saved it.
Every other markdown reader calls this one **loose** list.

**The mutation check is the story here.** Fixing `start` alone made the first test pass *and* the
second one, because three lists of one item each carry their own start — so mutating the lexer change
back broke nothing, and by this file's usual rule it was dead code to delete. Rendering both ways is
what showed it was not: without it the blank lines the author put between the items **disappear**,
because the list ends and the next one begins with no gap between. So the lexer half was doing
something, and the something was undoing the author's spacing — a regression hidden behind a passing
test. *A mutation that changes no test can still change the output; render it and look.*

What it wanted was the whole distinction, which the `Lexer` docblock used to disclaim: `ListBlock`
carries `loose`, the lexer sets it when it walks over a blank line between two items, and the
renderer puts one line between items — never before the first or after the last, because that
spacing belongs to the block and not to the list. Three tests, one per fact: the numbers, the
blank lines, and a tight list staying tight; each of the three ends fails on its own mutation.

**Ten differences survive the corpus and each now has a reason on the `Lexer`**, which is the other
half of the run: four are the harness comparing `marked`'s raw text against pig's parsed inline text,
three are the subset pig states (setext headings, reference links, HTML), and three are pig's own
calls — a change of bullet marker does not start a new list, a task item keeps its `[x]` (which
`marked` lifts into a flag **upstream never reads**, so the box vanishes there), and an unclosed fence
keeps its last newline.

## Three components read against their upstream, and the one thing PHP cannot have

`tool-execution.ts` (630) and `footer.ts` (324) were mapped member by member like the two big files
before them, and both came back matching — which is worth the words only with the list attached.
`ToolExecutionComponent` agrees with upstream on `read`'s `path:start-end` heading and its
arithmetic, `edit`'s `path:firstChangedLine` from the preview and then from the result, `write`
drawn from the arguments rather than the result, the `~` shortening, the tab width, and the per-tool
line limits; `FooterComponent` agrees on the five token bands, the 70/90 colour thresholds, the
aborted-turn skip when reading the context, `(auto)`, `(sub)`, and the path elision. pig's
`invalidate()` clears the cached branch exactly as upstream's does, with the same reason written on
it.

Three differences, each deliberate:

- **`file_path ||` is not ported.** Upstream's component reads `args.file_path || args.path` for
  `read`, `write` and `edit` while its own tool schemas say `path` — a fallback to a name it does
  not use. pig reads what its schemas declare.
- **`grep`'s pattern is drawn as `/pattern/` and carries its glob**, where upstream shows it bare.
  pig's own, and the delimiters are what tell a pattern from a path at a glance.
- **The git branch is not watched.** Upstream keeps an `fs.watch` on `.git/HEAD` and disposes of it,
  so an idle session notices a checkout made in another terminal; pig re-reads on `invalidate()`,
  which fires on every agent event, so it notices the next time anything happens. **This is the
  usual "PHP has what JavaScript reaches for" the other way round**: `fs.watch` is free in Node and
  PHP has no portable file watcher at all without an extension — `Loop::onReadable()` watches
  streams, not directories. The cost is a branch name that is one event stale on a screen nobody is
  looking at, and the alternative is an fd, a `dispose()` and an extension.

## The prompt answered to eleven keys that were named nowhere

`Editor` has handled ctrl+w, ctrl+u, ctrl+k, alt+backspace, ctrl+a/home, ctrl+e/end,
alt+left/right, ctrl+left/right, shift+enter, alt+enter, tab completion and the prompt history
since it was ported. **Not one of them appeared on any screen.** `KEYS` holds the keys the
*application* binds — esc, ctrl+c, ctrl+o and the rest — and upstream's `/hotkeys` is where the
editing ones are written down, and pig had ported the keys and not the table.

So `/help`, whose own row reads `Show the keys and commands`, showed some of the keys. That is the
claim-not-kept shape rather than a missing nicety, which is why it is a fix and not a note: the keys
worked, and the only way to find them was to already know them.

`EDITING_KEYS` is that table, **beside `KEYS` rather than as a second command**, because pig already
prints the keys in two places — `/help` and the banner under ctrl+o — and upstream's own two lists
disagree with each other. It is keyed by action id (`tui.editor.cursorLineStart` → "start of the
line") and the keys are read from the registry, so a rebinding is what `/help` shows; the list of
actions is kept by hand, and the test is what notices: when `Editor::editingKey()` grows an arm, `testEveryKeyTheEditorAnswersToIsNamedSomewhere` is where it
gets its name.

Two things fell out of adding it, both about the column:

- **The two printers were the same ten lines twice.** `banner()`'s expanded branch and
  `commandHelp()` each looped over `KEYS` and then `COMMANDS`. They could not have disagreed about
  the tables; they could and immediately would have disagreed about the column, since only one of
  them would have got the third table. `keysAndCommands()` is the one implementation.
- **The column was the constant `12`** and `shift+enter` is eleven columns, so it would have sat one
  space from its description while `esc` sat nine away. It is measured from the widest label now,
  with `Width::visible()`, which is `--list-models`' rule and for `--list-models`' reason.

## `/debug` was three-quarters ported and did nothing

The renderer has had the `onDebug` callback and a shift+ctrl+d intercept in
`handleTerminalInput()` since it was ported, `Keys::isShiftCtrlD()` answers, and **nothing in the repository
ever called the setter.** So the one key that works whatever holds the focus — which is the whole
point of a key that captures the screen — did nothing at all. The same shape as ctrl+p taken off the
editor and bound to nothing, one package over.

What it was missing is upstream's `handleDebugCommand()`, and the argument for porting it rather
than deleting the hook is that **pig already has the interesting end**: `TuiBase::checkWidth()` writes
`[n] (w=N) <line>` for every line of the frame when one is too wide to draw, and *every width bug in
this file was found by reading exactly that* — `str_pad` lining up an ASCII-only column,
`BashOutputComponent`'s 22-column note, the frame drawn twice over itself. Each time it had to be
got at with a probe written outside the repository, because from inside a real session there was no
way to ask. `TuiBase::frame()` is that dump on demand, shared with `checkWidth()` — the same question
before a fault and after one.

Four decisions in it:

- **Known and not listed**, beside `quit` and `arminsayshi`, because `COMMANDS` is what `/help`
  prints and what the autocomplete offers, and this is for reporting a fault rather than for using
  pig. The **key** is what `KEYS` names, since working regardless of focus is its advantage.
- **The escapes stay escapes.** The lines go through `json_encode`, so a log holding a cursor
  sequence cannot move the cursor of whatever opens it — and a column count beside a line whose
  codes are invisible is the pair that makes a padding bug obvious.
- **The conversation goes in as the session file's own shape**, through `SessionCodec`, rather than
  as a second notion of what a message looks like.
- **`0600`, and the mode is set before there is anything to read.** Not the file's own importance:
  a conversation holds whatever the model read, which on a bad day is somebody's `.env`. The session
  file it duplicates is inside a `0700` directory; this one sits at a predictable name in the home
  directory, so it says so itself. Set first rather than chmodded after, as `Auth::save()` does it.

Regression tests: `InteractiveModeTest::testTheDebugKeyWritesTheFrameAndTheConversation` and
`testTheDebugCommandWritesTheSameThingWithoutBeingListed` — one per route, and each stays green when
the other's end is removed.

## Two loaders said escape worked and the key reached neither

`onRetryStart()` draws `… — trying again in 30s (1/3, esc to stop)` and `onOverflow()` draws
`Context is full — summarising, then trying again. (esc to cancel)`. Both labels are upstream's and
both were true of nothing: `InteractiveMode::interrupt()` ended with

```php
$this->session->agent->abort();
```

— **the agent's abort, not the session's.** A sleeping retry and a running summariser both happen
*between* runs, where `$this->agent` has no controller to raise, so escape moved the queued text back
into the editor and then did nothing at all. Reproduced through the real screen: the countdown says
"esc to stop", escape arrives, and thirty seconds later the turn goes again.

`AgentSession::abort()` is what reaches all three, and it now reaches the third as well —
`abortCompaction()` beside `abortRetry()`, for the reason already written on it: *neither of these has
an agent to interrupt.* Upstream's escape handler calls `abortCompaction()` itself, beside `abort()`;
one call here is the same choice as `startNew()`'s, and it is what stops a caller reaching two of the
three and believing it has finished.

**The summariser had nothing behind the label either**, which is the second half of this and the
worse one. `compactAndCarryOn()` called `compact()` with **no signal at all**, so the *auto*
compaction — a whole conversation, at high reasoning, on a full context window — could not be stopped
by anything, and its own `Summarising was cancelled.` branch was unreachable on that path. That is
precisely the failure `Retry`'s abortable sleep exists to prevent, one method over: a wait escape
cannot reach is indistinguishable from a hang.

pig has one `compact()` where upstream has two paths and two controllers, so there is one
`$compacting` here, made inside `compact()` — which gives `isCompacting()` and `abortCompaction()`
one answer whoever started the summarisation. A signal the caller brought (the terminal's, for
`/compact`) is **forwarded into** it rather than carried alongside, so what goes downstream has a
single `aborted()` to ask: pig has no combinator for two signals, and the reason it has none is the
note on `Future` above. Both directions are tested, and each is a separate mutation — the forwarding
listener, and which signal goes down.

Two smaller things fell out of the same read, both on `get_state`: upstream's `RpcSessionState`
declares `isCompacting` and `queueMode` and pig's answer carried neither, so a host could not see a
summarisation at all and could set the queue mode without ever reading it back — the
wired-at-one-end shape, on a field rather than a key.

Regression tests: `InteractiveModeTest::testEscapeStopsTheRetryTheScreenSaysItCanStop` for the label
and the key, `AgentSessionTest::testEscapeReachesASummarisationNobodyAskedFor`,
`testStoppingTheRunStopsASummarisationWithIt`, `testATypedCompactionCanBeStoppedFromEitherEnd` (two
cases, one per end), and `RpcModeTest::testGetStateDescribesTheModelAndWhatItIsDoing` for the fields.

## A dialog opened in front of a suspended fiber has to be escapable

A hook's `tool_call` handler runs inside the agent's fiber. `$ctx->ui->confirm()` parks that
fiber on a `Deferred` and hands the keys to a component; the answer resumes it. If the
component has no way to say "no answer", nothing resumes it — the turn is stopped forever,
the tool never returns, and the only way out is killing pig. It does not look like a hang in
the tool: it looks like the agent went quiet.

Two things follow, and both are load-bearing rather than tidy:

- `Pig\Tui\Components\Input` got `setCancelHandler()` for this. `SelectList` already had
  one, and the multi-line dialog gets escape through `CustomEditor`. Any future dialog in
  `TerminalUi` needs the same before it is used — and `custom()` cannot be given it, which is
  why its docblock says so twice.
- `TerminalUi` refuses a second dialog while one is open, because opening one would take
  focus from the first and leave *its* fiber unreachable. The refusal answers with the safe
  value — null, or false for `confirm()`.

Tests: `HookUiTest::testEscapingAnInputAnswersWithNothing`,
`testASecondDialogIsRefusedRatherThanStacked`, and the three
`testAGuardCanAskBeforeLettingAToolRun` cases that drive the whole chain.

## One chunk is one key (tests)

`Keys::isEnter($data)` is `$data === "\r"`, and every other reader is the same shape: the
chunk that arrives *is* the key. So `type('hello' . ENTER)` in a `FakeTerminal` test delivers a
single six-character key that is not Enter, and it lands in the editor as text — the
characters appear, the submit handler never runs, and the test fails on something that looks
like the submit being broken. One `type()` per key, which is what every test in
`InteractiveModeTest` already did.

## A spinner above the prompt repainted the line somebody was typing into

Reported with a screenshot: typing Chinese while the agent worked put the pinyin and its candidate
list below the footer, moving on every spinner tick. pig's own renderer fixed it by rewriting only
changed lines, never touching the composing line, and moving the caret *inside* the
synchronized-output wrapper (Terminal.app does not support 2026, so the per-line diff was what
mattered). **The developer then chose a literal port of upstream's `TuiMainScreen` and
`CURSOR_MARKER`, accepting that this may come back.** If it does, the differences to look at first:
upstream rewrites every row from the first change to the last change (a change above *and* below
the editor rewrites the editor), and `positionHardwareCursor()` writes the cursor move *after* the
`\e[?2026l`.

The other half of that report stays fixed in the components: `AssistantMessageComponent::update()`
keeps its `Markdown` components and sets them again by position, and `setText()` returns early for
the text it already has — without that guard a 1,600-line answer costs ~19ms a delta instead of 0.1.
Regression tests: `MessageComponentsTest::testABlockThatHasStoppedChangingKeepsTheComponentThatDrewIt`,
`testABlockWhoseKindChangesDoesNotInheritTheWrongComponent`,
`testAMessageWithFewerBlocksThanLastTimeDrawsOnlyWhatItHasNow`.

## One keystroke took a second and a half, and the component said it was cheap

The other half of the same report — *"任务完成以后输入很卡"* — and the entry above is the record of
guessing at it twice and missing. What settled it was one fact from the developer that no
measurement of mine had asked for: **only one conversation was slow, and only when resumed with
`pig -r`.** Other sessions were fine. That is not a statement about frame size or about images; it
is a statement about *what is in that session*.

So the session was profiled rather than imagined — 1.7MB, 416 messages, **201 tool results**:

```
frame: 3295 lines, 1.23 MB          a keystroke: median 1536 ms
  render the whole tree    459 ms     the editor taking the key: 0.01 ms
  rowOf(editor)           1042 ms     the dearest component: 12 lines, 50 ms
```

`BashOutputComponent` is the tail of a command's output cut to a few rows, and it **had no cache**,
with a docblock explaining why: *"the wrap is cheap and the text changes on nearly every frame while
the command is running."* Both halves are false for a command that has finished. The wrap is over
**the whole output** — it wraps 600KB to keep twenty rows — and a resumed transcript is nothing but
finished commands, whose text will never change again. So every keystroke re-wrapped every command
output in the conversation, and `Container::rowOf()` renders the tree twice more to find the caret's
row, so it happened three times over. Cached on text, rows and width like every other component
here: **1536ms → 1.85ms.**

**The comment is the whole lesson, and it is the fourth shape from the index pointed at a
performance claim.** "The wrap is cheap" was a claim about the input, made where the input comes
from outside; "the text changes on nearly every frame" was true of the one caller the author had in
mind and false of the other, which is `replay()`. *A docblock that justifies the absence of a cache
is an assertion about every caller, and it goes stale the same way a docblock listing absences
does.* The sweep worth running after this one: every `invalidate()` in the tree whose body is a
comment saying nothing is cached.

**Two measurements from the same run are worth keeping, because neither is a bug and both will look
like one later.** `Container::rowOf()` is 1.1ms of the remaining 1.85 — it renders `$chat` twice
(once walking into it to search, once for `count()`), which with caches hitting is array
concatenation rather than layout, and there is no symptom to justify changing it. And the first
frame of that session is **1.24MB in a single `ProcessTerminal::write()`**, which blocks the one
thread until the terminal has drained it — 815ms there, and it is the shape to suspect first for a
session carrying screenshots, since a 600KB PNG becomes an 800KB image sequence and a full redraw
re-emits every one of them.

Regression tests: `BashOutputTest::testAFinishedCommandIsNotWrappedAgainOnEveryFrame` — a **ratio**
rather than a number, because what a loaded machine does in a millisecond is not a fact about this
code, and the gap it stands in for is 800× — plus `testTheSameOutputAtADifferentWidthIsWrappedAgain`
and `testNewOutputAndANewRowCountBothReachTheScreen`. The width key and the cache itself are killed
by those; the `setText`/`setRows` invalidations and the text and row keys are **mutually redundant**,
each covering the other's mutation, and both are kept because that is exactly the shape `Text` and
`Markdown` already have.

## The footer cut its lines to a byte count and handed the terminal half a character

Three places in `FooterComponent` cut a line that would not fit, and all three counted **bytes** —
`substr(Ansi::strip($text), 0, $width - 3) . '...'`. The method one of them sits in is called
`trim()` and its own docblock said *"measured by what is visible rather than by bytes"*, which was
true of the measuring and false of the cutting, three lines below it.

What that produces is not a crooked column, which is the rest of this family. It is **a fragment
that is no encoding's text**: three columns of a hook's status line in Chinese is nine bytes, and
the ninth is the middle of the fourth character. Every other display path in this repository
sanitises what it is handed against exactly that — and this one *made* it rather than passing it
on, downstream of all of them.

`justify()` had a second bug stacked on the first: `$gap = str_repeat(' ', $width - $leftWidth -
strlen($cut))`. The cut text is right-aligned, so the gap is what puts it against the edge — and
sized in bytes, a model id in a script where one character is not one byte leaves the line **short
of the width**. Short rather than over, which is why nothing caught it: every assertion in that
file asks whether a line overflows. *An assertion about overflowing cannot see a line that stops
early*, which is the `grep`-exit-code shape again.

All three go through `Width::truncate()`, which is ANSI-aware and cuts on grapheme boundaries and
already existed. Found by asking what could hand a terminal bytes it cannot turn into a string,
after Terminal.app trapped inside `__CFStringCreateImmutableFunnel3` while drawing — **that is not
evidence it was the cause**, and the reported session's footer is ASCII throughout, so this is a
bug found while looking rather than the bug looked for.

Regression tests: `FooterTest::testNothingIsEverCutInsideACharacter` over widths 8 to 80 and
`testAModelNamedInAWideScriptStillFitsBesideTheCounts` over 58 to 96 — a column at a time, because
the cut only lands inside a character at some of them, and only some widths reach the branch at
all. The second asserts the line is **exactly** the width whenever any of the right-hand text is
on it; without that, the byte-sized gap survives every mutation.

## The list widget could filter and nothing ever asked it to

`SelectList::setFilter()` existed, and the only caller in the whole repository was its own test.
Every picker — models, sessions, themes, sign-ins — took arrow keys and nothing else, and a
printable key fell off the end of a `match` into `default => null`.

It also filtered on the wrong field. `str_starts_with($item->value, …)`, and the model picker's
values are `0`, `1`, `2`: a row's position in the list. *There was nothing there anyone could
type.* A prefix match is the wrong shape besides — `cmt` should find `commit`.

So: upstream's `fuzzy.ts` ported to `Tui\Fuzzy` (scores pinned against numbers taken from running
the original — see below), `setFilter()` moved onto it, and `handleInput()` given a backspace arm
and a printable-text arm. Two details are upstream's and neither is obvious:

- **Escape cancels the picker rather than clearing the query.** The way out must not depend on
  whether you typed.
- **A query moves the selection to the top match; clearing one leaves it where it is.** Not
  symmetrical, and right: clearing a query should not throw you back to the top of a list you had
  scrolled into.

The search line is drawn *only when something has been typed*, which is what keeps this out of the
editor's completion popup — the one `SelectList` that must not grow a search box, since it is
already filtered by the line you are writing. It never gets one, because the editor forwards only
up and down arrows to it and so can never accumulate a query. No flag, no opt-in: the two
behaviours fall out of the same rule.

`isPrintable()` is the arm that needed the care. "Not handled above" is not the same as "text": an
arrow is `\e[A`, a function key `\e[15~`, ctrl chords are the C0 range, and any of them taken as
text is a stray character in the query.

## The footer said less than upstream's about the same session

Two things missing, both upstream's and both ported:

`• thinking off` when a model that *can* reason is not being asked to. It used to show nothing,
and nothing cannot distinguish "this model does not think" from "thinking is off" — a fact about
the session that the corner is exactly the place for.

`(provider) ` in front of the model id, under two conditions that are easy to drop and are the
whole design. It appears **only when more than one provider is on offer** — one provider makes the
prefix noise, since it distinguishes nothing — and **only if it fits**, dropped *whole* rather than
cut, because half a provider name in parentheses reads as a different provider, and the id is the
part you steer by. The fit is measured against the left side's width *after* it was truncated, not
the width it wanted.

The provider count is memoised and cleared in `invalidate()`, which is what a model switch or a
sign-in calls; upstream pushes the count in from outside for the same reason. The test for that
clearing is the one that needed `WithoutProviderKeys` — a second provider signed into mid-session
has to bring the prefix out, and without the clear it stays hidden until the next run.

Not ported: the `→ routed-model` segment, which is for upstream's virtual models. There is no
equivalent here yet.

## Footer session name display, `/name` command, and full `smart-session` telemetry parity

**Phenomenon**: Upstream pi's footer top line displays `pwd (branch) • <session-name>`, which extensions like `smart-session` use to render dynamic titles and token speed meters (`~/Development/owner/pig (main) • 仿写更新日志规则模板 • ⚡ 329 tok/s · avg 460 · TTFT 1ms`), while pig previously only showed `pwd (branch)`. Furthermore, pig had no `/name` command, lacked `session_info` entry encoding/decoding, omitted streaming message hook events (`message_start`, `message_update`, `message_end`, `session_info_changed`, `agent_settled`), and had an incomplete `smart-session.php` stub.

**Cause**:
1. `FooterComponent::where()` only formatted `$cwd` and git branch, omitting `$this->session->getSessionName()`.
2. `SessionEntries` did not encode or decode pi's standard `session_info` entry format (`{"type":"session_info","id":...,"name":...}`), and `SessionManager` had no `getSessionName()` / `setSessionName()` API.
3. `AgentSession::tellHooks()` only dispatched `AgentStartEvent`, `TurnStartEvent`, `TurnEndEvent`, and `AgentEndEvent`. Hook extensions observing streaming tokens or agent settling never received callbacks, so token speeds and auto-summaries could not compute.
4. In `smart-session.php`, checking `$message->role === 'assistant'` assumed JavaScript plain object conventions. In pig, messages are typed PHP classes (`AssistantMessage`) without a `role` property, causing stream handlers to bail out immediately.
5. Background LLM summarization lacked the active session's OAuth/API credentials, failing on OAuth-backed providers like Antigravity. Additionally, reasoning models like Gemini 3.8 Flash produce thinking tokens that exhaust small token budgets (e.g. 50 tokens), prematurely terminating on `StopReason::Length`.
6. `InteractiveMode` lacked an `onSessionNameChanged` listener, so extension updates to session name did not trigger immediate TUI re-renders.

**Countermeasure**:
1. Added `SessionInfoEntry` and mapped it in `SessionEntries` and `SessionManager` (`sessionName()` / `setSessionName()`), with persistence in `.jsonl` files and preview support in `/resume` (`describe()`).
2. Added `sessionName()` / `getSessionName()` and `setSessionName()` across `AgentSession`, `HookApi`, and `HookContext`, wired to trigger `onSessionNameChanged` and immediately request a TUI re-render.
3. Extended `HookApi::EVENTS` with `session_info_changed`, `message_start`, `message_update`, `message_end`, and `agent_settled`, forwarding them from `AgentSession::tellHooks()`.
4. Provided `$ctx->complete($prompt)` and `$ctx->apiKey($model)` in `HookContext`, enabling background tasks to seamlessly execute LLM completions using active session credentials.
5. In `smart-session.php`, supported both `instanceof AssistantMessage` and role checks, set title generation budget to 1000 tokens matching upstream, and bound speed telemetry and auto-summarization to `$pi->setSessionName(...)`.
6. Added `/name` built-in slash command to view or rename the current session on the fly.
7. In `FooterComponent::where()`, appended ` • {$sessionName}` beside git branch, rendering the identical top line.

## TUI performance bottlenecks, CJK word boundary bugs, and rendering optimizations

**Phenomenon**: 
1. In `Width::visible()`, LRU cache eviction used `array_shift()`, which in PHP triggers O(N) re-indexing and memory block movements on every cache insertion once full (512 items).
2. In `TextWrap::tokenize()`, lack of CJK character boundary awareness caused Chinese sentences lacking ASCII spaces to be treated as a single massive English token, awkwardly severing trailing English words (e.g. `请确认提交commit-id` was chopped into `请确认提交co` and `mmit-id`), while causing heavy CPU overhead in `breakWord()`.
3. In `Chars::isPunctuation()`, punctuation check was hardcoded to ASCII and required `strlen === 1`. Multibyte CJK punctuation (`，。！？；：“”‘’《》【】……——`) were misclassified as word characters, causing `Ctrl+W` / `Alt+Backspace` to delete entire sentences across Chinese commas and periods.
4. In `Markdown.php`, large base64 inline image lines (Kitty/iTerm2 protocol) were fed into `TextWrap::wrap()` and padded, incurring massive string search overhead.
5. In `Editor.php`, `layout($width)` was executed 2–3 times per keystroke across `render()`, `caret()`, and `Container::rowOf()`.

**Countermeasure**:
1. Replaced `array_shift(self::$cache)` with `unset(self::$cache[array_key_first(self::$cache)])` in `Width.php` for O(1) hash eviction, speeding up cache updates by 5.0x and expanding capacity to 2048.
2. Aligned `TextWrap::tokenize()` with upstream pi's `cjkBreakRegex`: each CJK glyph forms an atomic token, allowing natural wrapping and clean hyphenation/wrapping for mixed English words.
3. Updated `Chars::isPunctuation()` with Unicode punctuation matching (`^[\p{P}\p{S}]\z/u`), preserving `_` as word identifiers while correctly recognizing Chinese and full-width punctuation as word boundaries.
4. Added `isImageLine()` bypass in `Markdown::lines()` to skip wrapping and padding on terminal image protocol lines.
5. Added cached layout in `Editor.php` to memoize the visual line layout within the same render frame.

## The fullscreen fixed bottom dock and the scrolling viewport (ChatViewport & ScrollView)

**Phenomenon**: with single-screen streaming, a long conversation pushed the editor and the footer off the screen. In upstream pi's fullscreen mode the editor, the working indicator and the status bar stay at the bottom, and scrolling up floats `↓ Jump to latest message · Ctrl+End` centered on the viewport's last line.

**Structure (ported file by file from upstream)**:
1. `TuiAltScreen` (`tui-alt-screen.ts`) lays the layout root into the full window rectangle every frame with `Layout::renderLayoutFrame()` (`layout.ts`); without a layout root the children go into an implicit follow-end `ScrollView`.
2. `ChatViewport::create()` (`createChatViewport()` in `chat-viewport.ts`): `VStack[transcript ScrollView(basis 0, grow 1, min 1), dock VStack(basis auto)]`; the dock's items can shrink, the editor to no less than 3 rows. pig's overlay shares the status slot, the extension footer shares the footer slot, and the Spacer above the editor sits in the widgetsAbove slot.
3. `ScrollView` (`components/scroll-view.ts`) holds state only: it follows the end in `updateLayout()`, `scrollBy()` returns the rows it could not scroll (wheel overflow and drag-select auto-scroll both rely on it), and the scrollbar is `hidden|auto|always`.
4. Search highlights, the floating pill, the overlay, the scrollbar and the selection highlight are composited in `TuiAltScreen::doRender()` in upstream's order; the `tui.altScreen.*` keys (page, half page, line, previous/next prompt, search, top/bottom) go through `TuiAltScreen::handleViewportKey()`, which consults `Pig\Tui\Keybindings::getKeybindings()` — see the keybinding entry below.
5. Cursor: `TUI::CURSOR_MARKER` is searched for as upstream does; when a leaf box is taller than its allotted height, `Layout` keeps the marked row inside the box.

## Fullscreen wheel dead and the bottom dock pushed up: AltScreen must enable mouse reporting and disable autowrap

**Symptom**: in fullscreen the wheel could not scroll history (Terminal.app turns the wheel into ↑/↓, which the editor took for history); the bottom editor sometimes moved up one row and never came back, and misaligned after a window-height change; `Ctrl+Home` was a fatal error.

**Root cause**: `?1000h ?1002h ?1006h` had been removed to keep the terminal's native select-and-copy, which made the wheel parsing in `InteractiveMode` dead code (the tests fed SGR sequences directly, so they stayed green). The render path had no `?7l`, so a row one cell wider in the terminal than `Width::visible()` wrapped, and a wrap on the last row scrolled the screen without the line diff knowing; a height change did not repaint the whole screen either. `scrollToTop()` did not exist on `ScrollView`.

**Trap rule**: AltScreen follows upstream's `TuiAltScreen`: on entry `?1049h ?7l` plus mouse reporting (button-motion under tmux/screen, all-motion elsewhere, `?1004` focus in both), with select-and-copy done by pig itself (the selection in `TuiAltScreen` plus `AltScreenFlashContainer`'s `Copied!`); every frame addresses rows 0..height-1 absolutely, and a width or height change repaints the frame with `\x1b[2J`. Do not turn mouse reporting off again for native selection.

```php
$this->terminal->write(self::ENTER_ALT_SCREEN . self::DISABLE_AUTOWRAP . $mouse . "\x1b[2J\x1b[H");
// Mouse/focus reports may share one read with keystrokes; TuiAltScreen::handleViewportInput() picks them out first.
```

## Crash after scrolling up: `Rendered line N is 172 columns wide, terminal is 171`

**Symptom**: scrolling up in fullscreen, when the `↓ Jump to latest message` pill landed on a row containing Chinese, `checkWidth()` threw and pig crashed. (`TuiAltScreen` now truncates an over-wide row as upstream does instead of throwing, but the compositing itself still has to be right.)

**Root cause**: `Width::composite()` took the part left of the pill with a non-strict `sliceByColumn()`; a wide character straddling the start column was kept whole, and the row came out one column too wide. Upstream's `compositeTuiLine()` uses `extractSegments()` plus a strict slice — the straddling wide character is dropped and padded with a space — and truncates to `$totalWidth` at the end.

**Trap rule**: any "draw A over B at column X" compositing goes through `Width::composite()`; when assembling before/selected/after by hand, pass `strict: true` for all three.

```php
$before = Width::sliceByColumn($line, 0, $start, true);
```

## The TUI split into upstream's `TUI` / `TuiBase` / `TuiMainScreen` / `TuiAltScreen` (no aliases)

The developer asked for 100% alignment with upstream — when something breaks, the answer is found in pi — so the old class names keep no alias.

| Old | New |
|---|---|
| `Tui` (class) | `TUI` (interface) + `TuiBase` (shared) + `TuiMainScreen` (regular) + `TuiAltScreen` (fullscreen) |
| `new Tui()` + `setAltScreen()` | `TuiRenderer::createInteractiveTui()` (`tui-renderer.ts`) |
| `onInput()` | `addInputListener()` / `removeInputListener()` |
| `setDebugHandler()` | `$tui->onDebug = ...` |
| `handleInput()` / `draw()` | `handleTerminalInput()` / `doRender()` |
| `setViewportRenderer()` / `setPrimaryScrollView()` / `setCopySelection()` | `setLayoutRoot()` / the `ScrollView` with `primary: true` in the layout / `TuiAltScreenOptions` |
| `new ChatViewport(...)`, `renderViewport()`, `indicatorRect()` | `ChatViewport::create(...)`; the floating pill is composited by `TuiAltScreen` |
| the `Caret` interface, `caret()`, `Container::rowOf()` | `Focusable` + `public bool $focused` + `TUI::CURSOR_MARKER` |
| `ScrollView::scrollToBottom()`, `contentLines()` | `scrollToEnd()`, `LayoutBox::$scrollContentLines` |
| `TuiAltScreen::scrollPage()`, the input listener in `InteractiveMode` that looked up `tui.altScreen.*` | `TuiAltScreen::handleViewportKey()` + `Pig\Tui\Keybindings` (`keybindings.ts`) |
| `InteractiveMode::$tui` (the concrete renderer) | `$tui` is the forwarding reference from `TuiRenderer::createInteractiveTuiReference()` (upstream's `ui`); the concrete renderer is `$renderer` (upstream's `renderer`) |

**macOS case trap**: `Tui.php` → `TUI.php` differs only in case. APFS is case-insensitive by default and git's default is `core.ignorecase=true`, so `git add -A` does not record the rename and PSR-4 on Linux cannot find `TUI.php`. It has to be `git rm --cached packages/tui/src/Tui.php && git add packages/tui/src/TUI.php`.

## Keybindings: one registry, upstream's ids, the app's manager installed in the TUI's slot

**Structure**: upstream's `keybindings.ts` is `Pig\Tui\Keybindings` (`TUI_KEYBINDINGS`, `getKeybindings()`, `setKeybindings()`, `reset()`) plus `Pig\Tui\KeybindingsManager`. coding-agent's `Keybindings` **extends** that manager, as upstream's `core/keybindings.ts` does: the TUI's definitions with upstream's platform overrides (Windows/WSL `alt+p`, `ctrl+q`, `alt+q`, `alt+v`, `ctrl+f`, prompt jumps, `ctrl+z`/`alt+z` undo; no `app.suspend` on native Windows; `alt+left` first for the tree on macOS) plus `APP_KEYBINDINGS`, every upstream id and description, and `NAME_MIGRATIONS` (upstream's `KEYBINDING_NAME_MIGRATIONS`). `InteractiveMode`'s constructor calls `TuiKeybindings::setKeybindings($this->keybindings->tuiKeybindings())`, which is the manager itself, so `Editor`, `Input`, `SelectList`, `TreeList`, the session list and `TuiAltScreen` all read `tui.*` ids from one place, and `reload()` (also run by `/reload`, with the themes) changes every one at once.

**Rule**: every upstream id is bindable; an id nobody defines, or a value that is not a key, is a line in `problems()` (printed at startup, a warning on `/reload`) — stricter than upstream, which drops both silently. Never give a component its own key table. The registry is process-wide static: a test that installs keys calls `\Pig\Tui\Keybindings::setKeybindings(...)` in `setUp()` and `reset()` in `tearDown()`. `actionFor()` answers `app.*` only, or `searchNext`'s `enter` would be eaten as an editor action; `CustomEditor` asks about the actions it has handlers for, because several `app.*` ids share a key on purpose (`ctrl+s` saves in three selectors — `app.models.save`, `app.thinking.save`, `app.session.toggleSort`).

```php
\Pig\Tui\Keybindings::setKeybindings(new \Pig\CodingAgent\Keybindings(platform: 'linux', env: []));
```

What the registry made possible, all upstream's: `Editor` undo (`UndoStack`), a kill ring with yank / yank-pop (`KillRing`), delete word forward, jump to a character forward and back, page up/down and history keys; `Input` undo, kill ring and delete word forward; Ctrl+D with text deletes forward, Ctrl+C cancels a loader or closes a selector; the tree's fold/unfold (`⊟`/`⊞`), filter and copy keys, `shift+l` to edit a label (plain `l` types into the search) and `shift+t` for label timestamps; the session list's path toggle, delete (confirmed, the current session refused, trash before unlink) and page keys. `/help` lists the editing keys by action id (`EDITING_KEYS` is keyed by id), so a rebinding shows up there. pig keeps one extra default: `command+enter` on `app.message.followUp` (Apple Terminal sends `\e\r` for Shift+Enter, so ⌘+Return is the follow-up key there is).

## The image module renamed after upstream's `terminal-image.ts` (no aliases)

| Old | New |
|---|---|
| `Images\Capabilities` (`::detect()`, `->drawsImages()`) | `Images\TerminalCapabilities`; `TerminalImage::detectCapabilities()`; `->images !== null` |
| `Images\CellSize` / `Images\ImageSize` | `Images\CellDimensions` / `Images\ImageDimensions` (value objects) |
| `ImageDimensions::of/png/jpeg/gif/webp` (parsers) | `TerminalImage::getImageDimensions/getPngDimensions/getJpegDimensions/getGifDimensions/getWebpDimensions` |
| `TerminalImage::capabilities()` / `cellSize()` / `setCellSize()` / `reset()` | `getCapabilities()` / `getCellDimensions()` / `setCellDimensions()` / `setCapabilities()` + `resetCapabilitiesCache()` |
| `TerminalImage::kitty()` / `iterm2()` / `render()` / `fallback()` / `rows()` | `encodeKitty()` / `encodeITerm2()` / `renderImage()` / `imageFallback()` / `calculateImageRows()` |
| `TuiBase::isImageLine()`, the private `isImageLine()` in `Markdown` | `TerminalImage::isImageLine()` |
| `new Image($b64, $mime, ?theme, maxWidthCells, filename, size)`, `ImageTheme->fallback` | `new Image($b64, $mime, ImageTheme, ImageOptions, ?ImageDimensions)`, `ImageTheme->fallbackColor` |

**Trap rule**: in fullscreen a Kitty image is uploaded once, after which `getKittyImagePlacement()` swaps in the placement-only command (`a=p`); the off-screen cache evicts by three caps — image count, transferred bytes, decoded bytes. iTerm2 images are switched off while fullscreen (`setCapabilities` to null, restored on stop). Capability environment variables are read `PIG_*` first, then `PI_*`, through a helper, never with `?:` — `PIG_HYPERLINKS=0` is falsy in PHP and would fall through to `PI_`. A test that changes the global capabilities calls `resetCapabilitiesCache()` in `tearDown()`.

## Terminal color queries: picking the replies out of a batched read

**Structure**: `TuiBase::queryTerminalColors()` (returns `Future<TerminalColors>`), `onTerminalColorSchemeChange()` and `setTerminalColorSchemeNotifications()` (DEC 2031) follow upstream's `tui.ts`; parsing is in `TerminalColors::parseOscColorResponse()` / `parseTerminalColorSchemeReport()` (`terminal-colors.ts`).

**Trap rule**: upstream's input arrives already split into sequences; one of pig's reads may hold 18 color replies plus DA1 plus keystrokes, so `consumeTerminalColorReplies()` picks them out in order before the listeners and the rest is dispatched as usual. While a query is pending, a half `\e]…`/`\e[?…` at the end of a read is kept for the next one. With no query pending, OSC replies and DA1 are ordinary input (as upstream).

## Switching TUI mode at runtime: components hold a forwarding reference, so `instanceof` can only be asked of `$renderer`

**Structure**: as upstream's `switchTuiMode()`, changing the TUI mode in `/settings` unmounts the same component tree (document, pending, status slot, widgetsAbove slot, editor, widgetsBelow, footer slot) from the old renderer and mounts it on the new one; fullscreen additionally does `setLayoutRoot(ChatViewport)`. On exit, `fullscreenExitOutput=transcript` also switches to regular and `renderNow()`s the conversation; `resume-hint` only leaves the alternate screen.

**Trap rule**: `InteractiveMode::$tui` is the `TUI` forwarding reference — the Loader, `TerminalUi` and extensions all hold it, and it stays valid across a switch; `$this->tui instanceof TuiAltScreen` is always false, so checking the mode and calling `setLayoutRoot()`/`setCopyOnSelect()`/`frame()` always go through `$this->renderer`. Listeners an extension added via `TerminalUi::onTerminalInput()` hang on the old renderer and are moved by `rebindTerminalInputListeners()` after a switch, so any new way of adding an input listener has to go through that register too. A switch is refused while the overlay stack has entries (as upstream).

## Fullscreen mouse: components first, and the wheel stays with a focused overlay

**Structure**: as upstream, `TuiAltScreen::handleMouseEvent()` goes capture/press target → search box buttons → overlay → floating pill/scrollbar → components in the layout (`dispatchMouseToLayout()`, skipping stack nodes that use `Container`'s default `handleMouse()`) → text selection. A release without movement synthesizes `click`. An OSC 8 link press records the URL, and a release in place calls `TuiAltScreenOptions::$openUrl`.

**Trap rule**: pig's reads are batched, so a report for which `handleViewportReport()` returns false (overlay focused, a wheel no component takes) is left in the read for the focused component — that is upstream's `return undefined`. Upstream swallows `openUrl`/right-click-paste failures with a catch; pig does not, the callback reports its own errors (`InteractiveMode::openInBrowser()` never threw anyway). User messages and assistant messages without tool calls carry OSC 133 `A`/`B`/`C` at their ends, and `tui.altScreen.previousPrompt/nextPrompt` locate by `A` — do not strip them in rendering (`TuiAltScreen` removes the prefix only when compositing the screen).

## `Ansi::at()` did not know OSC/APC, so `\e]8;;\a` counted as 4 visible columns

**Symptom**: `Width::composite()` compositing onto a row that had already been composited put the second segment in the wrong column (`left  right` became `leright`).

**Root cause**: `compositeTuiLine()` inserts `\e[0m\e]8;;\a` between segments; `Ansi::at()` recognized CSI only, so `]` `8` `;` `;` were counted as 4 columns by `sliceByColumn()` and the other column-slicing functions. Upstream's `ansiCodeLength()` recognizes OSC (`\e]`) and APC (`\e_`), terminated by BEL or ST.

**Trap rule**: `Ansi::at()` is the base of every column slice; the sequences it recognizes must match upstream's `ansiCodeLength()`.

## agent_settled fired early while Working (the "task done" notification over a screen still working)

**Symptom**: the TUI still showed Working… while system-notify had already shown "task done (Xs)". Typical triggers: pressing Enter during "Retrying…"; `sendMessage(triggerTurn)`/`sendUserMessage` inside an agent_end handler; a steer/followUp in the instant a run was winding down (the message sat in the queue unanswered).

**Root cause**: pig decided retry/compaction/end inside the `AgentEndEvent` fan-out and settled at the end of every run that was not retried — settling "per run" rather than "per prompt". At the same time `isStreaming()` looked only at the agent's current run (false during a retry sleep, during overflow compaction and between runs, and `Agent::finish()` cleared isStreaming before the agent_end listeners ran), so input arriving in those gaps became a new prompt: settle the old one, then Working at once. The AgentLoop producer fiber also ran ahead of the listeners: messages queued in turn_end were missed, and tools kept running after a listener threw.

**Trap rules**:
- settle happens only in the `finally` of `runAgentPrompt()` (upstream's `_runAgentPrompt` → `_emitAgentSettled`), exactly once per prompt; retry, overflow compaction and the `agent.hasQueuedMessages()` continuation all live in its post-run loop, and "what happens next" is never decided inside an agent event callback.
- `AgentSession::isStreaming()` = upstream's `_isAgentRunActive` (the whole prompt), not `agent->state->isStreaming`; new code asking "can this be sent now" asks the session.
- The agent's listeners run synchronously to completion inside the loop's fiber (the `$emit` of `AgentLoop::start/continue`) before it continues; do not go back to "another fiber iterating the EventStream" to consume agent events.
- Run `AgentSettledTest` (which records the agent_start/agent_end/agent_settled order) before touching this.

## The retry notice reads `(escape to cancel)` and counts down by the second, as upstream

**Symptom**: the editor border read `Retrying (1/3) in 30s... (esc to stop)` during a retry, with the seconds frozen.

**Root cause**: upstream is `Retrying (${attempt}/${max}) in ${seconds}s... (${keyText("app.interrupt")} to cancel)`, refreshed every second; `keyText()` prints the key id as it is (`escape`), with `alt` written as `option` on macOS.

**Trap rules**:
- Key names come only from `Keybindings::keyText()`, never a hand-written `esc`; the countdown stops when the retry ends or is cancelled (`stopRetryCountdown()`).
- Tests: `InteractiveModeTest::testTheRetryCountdownCountsDownOnceASecond`, `testEscapeStopsTheRetryTheScreenSaysItCanStop`.

## A long resumed session stopped following the window, and a fresh one did not

Reported as "pig no longer follows the window at once; a new pig does". The window was 163 columns
and the frame stayed at 145 for seconds. `stty size` said 163 the whole time, so the signal and the
measurement were fine; a pty replay of the reporter's own session said the rest — a resize drew
nothing for ten seconds, and `PIG_STALL_LOG` put the render timer at `Event loop held for 12800ms`.

A sampled profile of that frame was 98% `Graphemes::split()` under `TextWrap::tokenize()`, from one
`BashOutputComponent`: a single 50KB line of scraped Chinese page text. `tokenize()` found each
non-ASCII character by splitting **everything after it** into graphemes and keeping the first, so a
line cost n² in its non-ASCII characters, and the cache keyed on width is empty at every new width.
A fresh session had no such line, which is why it looked like something that built up over time.

`clusterAt()` splits a 128-byte window that ends on a character boundary instead: the same frame is
0.3s, all of it the ordinary cost. A cluster longer than the window would come out in pieces; none
that a terminal draws is.

**Trap rules**:
- Never split the rest of a string to take its first grapheme inside a loop over that string; and
  `preg_match('/\G\X/u', $s, $m, 0, $pos)` is no cure — PCRE checks the whole subject's UTF-8 on
  every call, so it is n² as well (measured).
- When a resize is slow, profile a frame of the reporter's real session; the synthetic sessions in
  the harness had no long non-ASCII line and drew in 110ms.
- Tests: `TextWrapTest::testALongNonAsciiLineCostsItsLengthAndNotItsSquare` (20s on the old code),
  `testClustersLongerThanACodepointAreNeverCutByTheWindow`.

## After a retry, the rest of the run had no `Working`

Reported as "is it stuck halfway through a write? there is no Working". The run was fine and still
going — the model was reading the tool's result — but nothing above the prompt said so. Two places
differed from upstream, and a retry put them together:

- **`onRetryEnd()` took down whatever loader was up.** A successful retry is announced at the end of
  the answer it got (`AgentSession`, upstream's "reset retry counter immediately on successful
  assistant response"), which is mid-run, with `Working` back up since the attempt's `agent_start`.
  Upstream's `clearStatusIndicator("retry")` clears only the countdown.
- **`Working` was shown on `agent_start` only.** Upstream shows it on every `turn_start` when the
  indicator up is not the working one, so a turn after anything that replaced it gets it back.

`$loaderKind` is upstream's `activeStatusIndicator.kind` (`working`, `retry`, `other`), and
`$workingVisible` its `workingVisible`, which an extension's `setWorkingVisible(false)` sets.

**Trap rules**:
- Clear a loader by kind, as upstream does; never "whatever is up" from an event that can arrive
  mid-run.
- Test: `InteractiveModeTest::testWorkingIsBackForTheTurnAfterARetriedOneCalledATool` (red on the
  old code).

## A long fullscreen session died with `Allowed memory size of 536870912 bytes exhausted`

**Symptom**: mid-run, `PHP Fatal error: Allowed memory size … exhausted … in packages/tui/src/Container.php`;
afterwards the shell printed `35;31;25M…` on every mouse move.

**Root cause**: every alt-screen frame builds a `LayoutBox` tree whose `parent` ↔ `children` links
are cycles, and its boxes hold the whole transcript's lines. Cycles wait for the collector, which
is triggered by the count of possible roots — a frame adds a few dozen — so hundreds of frames piled
up (measured: memory grew with every frame and dropped back on `gc_collect_cycles()`). Then the
fatal error skipped `stop()`, and pig had no port of upstream's `exitRestoreHandler`, so mouse
reporting, raw mode and the alternate screen stayed on.

**Trap rules**:
- An object graph rebuilt per frame must not be left as a cycle; `TuiAltScreen::releaseLayout()`
  nulls `parent` when a frame is retired (nothing reads it, upstream included).
- The terminal is restored from a shutdown function (`TuiBase::exitRestoreHandler()` →
  `Terminal::restoreOnExit()`), which also restores `stty` and prints a fatal error again after
  leaving the alt screen.
- Tests: `TuiAltScreenTest::testARetiredFrameIsFreedWithoutWaitingForACycleCollection`,
  `ExitRestoreTest` (both red on the old code).

## A resize held the loop for four seconds, waiting on the terminal

**Symptom**: `PIG_STALL_LOG` logged `Event loop held for 4083ms` in `TuiBase.php:1196`, the render
timer, on a session of 1,747 messages. Nothing was drawn and no key was read for those seconds.

**Root cause**: a width change (or ctrl+o) in the regular screen redraws the whole transcript —
`scripts/bench-tui.php` measured 0.4–1.1s of PHP and 2–4MB of output for that session — and
`ProcessTerminal::write()` did not return until the terminal had taken every byte, waiting on
`select()` whenever its buffer was full. A terminal parses megabytes of escape sequences in seconds,
and all of that was spent inside the render callback. `FakeTerminal` takes everything at once, so
the bench never saw the second half.

**Trap rules**:
- `ProcessTerminal::write()` hands the terminal what it takes now and keeps the rest, written from
  the loop as the terminal reads (`BufferedTerminal`). It never waits; `flush()` does, and only
  `stop()`, `restoreOnExit()` and process shutdown call it — every hand-over of the terminal goes
  through `stop()` (suspend, the external editor, quitting).
- The renderer draws nothing while output is waiting (`TuiBase::waitForTerminal()`), and draws once
  when it has drained. Frames are diffs against the frame before, so none may be dropped from the
  queue; waiting draws every change made meanwhile in one frame.
- Anything else written to the terminal goes through the same `write()`, or it lands in the middle
  of a frame still draining — `SystemClipboard`'s OSC 52 copy takes the terminal's `write` for this.
- Tests: `ProcessTerminalTest::testAFrameTheTerminalCannotTakeAtOnceDoesNotHoldTheLoop`,
  `TuiTest::testAFrameWaitsForTheTerminalToTakeTheLastOneAndThenDrawsEverything`.
