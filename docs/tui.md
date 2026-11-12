# Terminal UI

`pig/tui` (namespace `Pig\Tui`, in `packages/tui/`) provides the terminal component system used by pig. Extensions use it when built-in dialogs, notifications, status text, and widgets are not enough for the interaction they need.

Start with the `$ctx->ui` methods (`Pig\CodingAgent\Extensions\ExtensionUi`) available to an [extension](/docs/latest/extensions) handler. Build a custom component only when the UI needs its own rendering, keyboard or mouse input, focus, layout, or lifecycle.

## Choose an integration point

| Need | Use |
|---|---|
| Select, confirm, input, or multi-line editor | `$ctx->ui->select()`, `confirm()`, `input()`, or `editor()` |
| Non-blocking feedback | `$ctx->ui->notify()` or `setStatus($key, $text)` |
| Persistent content near the editor | `$ctx->ui->setWidget($key, $lines or $factory, ['placement' => 'above' or 'below'])` |
| Replace the header, footer, or editor | `setHeader()`, `setFooter()`, or `setEditorComponent()` |
| Temporary interactive screen | `$ctx->ui->custom($factory)` |
| Custom rendering for a tool or session entry | `$pig->registerToolRenderer()`, `registerMessageRenderer()`, or `registerEntryRenderer()` |

These APIs receive pig's active theme and keybindings where needed (`setFooter($factory)` receives `($tui, $theme, $footerData)`, where `$footerData` is the built-in `FooterComponent` exposing `getGitBranch()`, `getExtensionStatuses()`, `getAvailableProviderCount()`, `onBranchChange()` and `render($width)`, and replaces the built-in footer until `setFooter(null)` or `/reload`). Do not create a second terminal renderer inside an extension.

```php
use Pig\CodingAgent\Extensions\ExtensionApi;
use Pig\CodingAgent\Extensions\ExtensionContext;
use Pig\CodingAgent\Interactive\FooterComponent;
use Pig\CodingAgent\Theme\Theme;
use Pig\Tui\Ansi;
use Pig\Tui\Component;
use Pig\Tui\TUI;

return function (ExtensionApi $pig): void {
    $pig->on('session_start', function (object $event, ExtensionContext $ctx): void {
        $ctx->ui->setFooter(function (TUI $tui, Theme $theme, FooterComponent $defaultFooter) use ($ctx): Component {
            return new class ($defaultFooter, $ctx) implements Component {
                public function __construct(
                    private readonly FooterComponent $defaultFooter,
                    private readonly ExtensionContext $ctx,
                ) {
                }

                public function invalidate(): void
                {
                    $this->defaultFooter->invalidate();
                }

                public function render(int $width): array
                {
                    // $this->ctx->getContextUsage() returns ?ContextUsage { ?int $tokens, int $contextWindow, ?float $percent }.
                    // $this->defaultFooter->render($width) returns the built-in 2-line footer (line 0: path/branch,
                    // line 1: token stats + context readout `42.2%/272k (auto)` + right-aligned model).
                    // Strip ANSI codes with Ansi::strip($line) before regex-replacing the context readout
                    // (e.g. `/\d+(?:\.\d+)?%\/[0-9.]+[kM]?(?: \(auto\))?/`) so high-usage warning colors do not prevent matching.
                    $lines = $this->defaultFooter->render($width);
                    $usage = $this->ctx->getContextUsage();
                    $pct = max(0.0, min(100.0, (float) ($usage->percent ?? 0.0)));
                    $filled = (int) round($pct / 10);
                    $bar = str_repeat('█', $filled) . str_repeat('░', 10 - $filled) . ' ' . (int) round($pct) . '%';

                    return array_map(
                        static fn (string $line): string => (string) preg_replace(
                            '/\d+(?:\.\d+)?%\/[0-9.]+[kM]?(?: \(auto\))?/',
                            $bar,
                            Ansi::strip($line),
                        ),
                        $lines,
                    );
                }
            };
        });
    });
};
```

## Understand the component model

A component implements `Pig\Tui\Component`: `render(int $width): array` returns the terminal lines for an available width, and `invalidate()` drops cached output. It can also implement `InputHandler` (`handleInput(string $data)`) for keyboard input and `MouseHandler` for mouse input, and it must invalidate cached output when its state or theme-dependent content changes.

Every rendered line must fit within the supplied width. Measure visible terminal columns rather than string length because ANSI escapes, wide characters, emoji, and combining characters change display width.

Use `Width::visible()`, `Width::truncate()`, `Width::sliceByColumn()`, `Width::pad()`, and `TextWrap::wrap()` instead of implementing terminal-width handling yourself. pig resets styling and hyperlinks after every line, so reapply styles on each rendered line.

After changing component state, invalidate the affected component and call the injected `$tui->requestRender()`. The TUI coalesces render requests and updates the terminal.

## Compose built-in components

The package includes components in `Pig\Tui\Components` for common layouts and controls:

- `Text`, `Markdown`, `Image`, `TruncatedText`, and `Rule` render content.
- `Pig\Tui\Container`, `VStack`, `HStack`, `Stack`, `Box`, and `Spacer` compose layouts.
- `Input` and `Editor` accept text.
- `SelectList` and `SettingsList` implement searchable selection and settings flows.
- `ScrollView` provides a bounded scrollable viewport.
- `Loader` and `CancellableLoader` report ongoing work.
- `MouseRegion` adds pointer behavior around another component.

Prefer these components over rebuilding selection, scrolling, text editing, or width handling.

## Handle keyboard input and focus

Use `Keys::matchesName($data, 'ctrl+x')` for terminal keyboard input; it reads the same key names as `keybindings.json`, accounts for the supported terminal protocols and key modifiers, and has shortcuts such as `Keys::isEscape()` and `Keys::isEnter()`. For configurable application actions, ask the keybindings: `Pig\CodingAgent\Keybindings` extends `Pig\Tui\KeybindingsManager`, whose `matches($data, 'tui.select.confirm')` checks an action ID.

A component that displays a text cursor should implement `Focusable` (a public `bool $focused` property the renderer sets) and place `TUI::CURSOR_MARKER` immediately before its visual cursor. The TUI uses that marker to position the hardware cursor for input method editors.

Containers that wrap an `Input` or `Editor` must propagate their `focused` state to that child. Without propagation, Chinese, Japanese, Korean, and other IME candidate windows can appear at the wrong screen position.

Extend pig's `Pig\CodingAgent\Interactive\CustomEditor` when replacing the main editor through `$ctx->ui->setEditorComponent()`. The factory receives the TUI, the editor theme, and the keybindings. It preserves application shortcuts and agent controls.

Forward keys your editor does not own to `parent::handleInput()`, and restore the default with `setEditorComponent(null)`.

## Handle mouse input

Fullscreen mode routes normalized mouse events to components. A handler can mark an event handled, capture a drag sequence, request focus, or request a render.

Unhandled wheel events scroll the nearest `ScrollView`. Unhandled primary-button drags remain available for transcript selection. OSC 8 links take precedence over enclosing click regions.

Regular mode leaves mouse input to the terminal because the terminal owns scrollback. Design every interaction with a keyboard path even when fullscreen mouse input is available.

## Use custom screens and overlays

`$ctx->ui->custom($factory)` temporarily gives one component control of the interactive area and returns once that component calls the supplied completion callback. The factory receives the TUI, the current theme, and `$done`:

```php
use Pig\CodingAgent\Theme\Themes;
use Pig\Tui\Components\SelectItem;
use Pig\Tui\Components\SelectList;

$count = $ctx->ui->custom(function ($tui, $theme, $done) {
    $list = new SelectList([new SelectItem('1', 'One'), new SelectItem('2', 'Two')], 8, Themes::getSelectListTheme());
    $list->setSelectHandler(fn ($item) => $done((int) $item->value));
    $list->setCancelHandler(fn () => $done(null));

    return $list;
});
```

A component that never calls `$done()` parks the turn: it has the keys while it is open, so always give the user a way out, usually Escape. Without a UI (print, JSON, or RPC mode), `custom()` returns `null`.

To draw above existing content, call `$tui->showOverlay($component, new OverlayOptions(...))`. The options control size, anchor, offsets, margins, and responsive visibility. The returned `OverlayHandle` can `focus()`, `unfocus()`, `hide()`, or temporarily hide and show the overlay with `setHidden()`.

Focused overlays retain input ownership across ordinary renders. If another component should receive input while an overlay remains visible, explicitly release or redirect focus through the handle.

Treat each custom component instance as belonging to one interaction. Create a new instance when starting that interaction again.

## Apply themes correctly

Use the theme passed to the extension or component callback (`$ctx->ui->theme()` in a handler). Theme helpers produce ANSI-styled strings for semantic colors such as accent, muted text, success, warnings, errors, tool output, and Markdown.

Use `$theme->style()` with a `ThemeStyle` to combine foreground and background colors with text attributes:

```php
use Pig\CodingAgent\Theme\ThemeStyle;
use Pig\Tui\Components\Text;

return new Text(
    $theme->style('Done!', new ThemeStyle(fg: 'success', bg: 'toolSuccessBg', bold: true)),
    0,
    0,
);
```

A style color can be a semantic theme token or a concrete `Pig\Tui\Color`. Foreground tokens are accepted as `fg` and background tokens as `bg`; to use a token's color in the other position, pass its concrete color, for example `new ThemeStyle(fg: $theme->colors()['userMessageBg'])`. Access concrete colors through `$theme->colors()` and use utilities such as `Colors::mixColors()` from `pig/tui` when color math is needed. Tokens that a theme sets to the terminal default render with the terminal's own color; `colors()` reports the color the terminal announced for them, or a guess when it did not. Use `$theme->appearance()` (`'dark'` or `'light'`) to decide, for example, whether to lighten or darken a color. pig converts the result to truecolor or 256-color output based on terminal capabilities. Theme tokens are converted once per theme; compute concrete colors outside the render path when possible.

The `$theme->fg($token, $text)` and `$theme->bg($token, $text)` helpers apply one semantic color.

Do not permanently store strings with theme colors unless `invalidate()` rebuilds them. A theme change clears render caches, but it cannot remove old ANSI colors embedded in application state.

Theme callbacks evaluated during rendering do not need special rebuilding. Stateless components can also calculate themed output on every render.

Use [Themes](/docs/latest/themes) to create terminal palettes. Use pig's `Themes::getMarkdownTheme()` when rendering Markdown that should match the active application theme.

## Keep rendering responsive

Rendering runs on the interactive path. Cache expensive layout and highlighting work by width and content, then clear that cache from `invalidate()`.

Keep the default view compact and reveal detail through expansion or a dedicated screen. For custom tool rendering, handle partial results and reuse the previous component when it can be updated safely.

Use `PIG_TUI_WRITE_LOG` to capture the raw ANSI stream when diagnosing rendering problems (a directory value writes `tui-<timestamp>-<pid>.log` inside it). Test narrow widths, wide characters, resize events, theme changes, focus transitions, and both regular and fullscreen modes.

## Examples and source

pig's own extensions show the main patterns: `extensions/pig-mcp/McpManagerView.php` (a full-screen manager built on `SelectList`) and `extensions/pig-llama/LlamaView.php`. The components live in [`packages/tui/src/`](https://github.com/owner888/pig/tree/main/packages/tui/src) and the extension UI contract in [`HookUi.php`](https://github.com/owner888/pig/blob/main/packages/coding-agent/src/Extensions/HookUi.php). See [Extensions](/docs/latest/extensions) for extension lifecycle, state, tools, and events.
