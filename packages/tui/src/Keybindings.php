<?php

declare(strict_types=1);

namespace Pig\Tui;

/**
 * The global keybinding registry — upstream's `keybindings.ts` module: `TUI_KEYBINDINGS`,
 * `getKeybindings()` and `setKeybindings()`. Downstream packages put their own manager here
 * (built over these definitions plus theirs) so the TUI reads the user's keys.
 *
 * A class constant cannot hold objects, so `TUI_KEYBINDINGS` is the plain data and
 * `definitions()` turns it into `KeybindingDefinition`s.
 */
final class Keybindings
{
    /** @var array<string, array{defaultKeys: string|list<string>, description: string}> */
    public const array TUI_KEYBINDINGS = [
        'tui.editor.cursorUp' => ['defaultKeys' => 'up', 'description' => 'Move cursor up'],
        'tui.editor.cursorDown' => ['defaultKeys' => 'down', 'description' => 'Move cursor down'],
        'tui.editor.historyPrevious' => ['defaultKeys' => [], 'description' => 'Select previous prompt history entry'],
        'tui.editor.historyNext' => ['defaultKeys' => [], 'description' => 'Select next prompt history entry'],
        'tui.editor.cursorLeft' => ['defaultKeys' => ['left', 'ctrl+b'], 'description' => 'Move cursor left'],
        'tui.editor.cursorRight' => ['defaultKeys' => ['right', 'ctrl+f'], 'description' => 'Move cursor right'],
        'tui.editor.cursorWordLeft' => ['defaultKeys' => ['alt+left', 'ctrl+left', 'alt+b'], 'description' => 'Move cursor word left'],
        'tui.editor.cursorWordRight' => ['defaultKeys' => ['alt+right', 'ctrl+right', 'alt+f'], 'description' => 'Move cursor word right'],
        'tui.editor.cursorLineStart' => ['defaultKeys' => ['home', 'ctrl+a'], 'description' => 'Move to line start'],
        'tui.editor.cursorLineEnd' => ['defaultKeys' => ['end', 'ctrl+e'], 'description' => 'Move to line end'],
        'tui.editor.jumpForward' => ['defaultKeys' => 'ctrl+]', 'description' => 'Jump forward to character'],
        'tui.editor.jumpBackward' => ['defaultKeys' => 'ctrl+alt+]', 'description' => 'Jump backward to character'],
        'tui.editor.pageUp' => ['defaultKeys' => ['pageUp', 'ctrl+pageUp'], 'description' => 'Page up'],
        'tui.editor.pageDown' => ['defaultKeys' => ['pageDown', 'ctrl+pageDown'], 'description' => 'Page down'],
        'tui.editor.deleteCharBackward' => ['defaultKeys' => 'backspace', 'description' => 'Delete character backward'],
        'tui.editor.deleteCharForward' => ['defaultKeys' => ['delete', 'ctrl+d'], 'description' => 'Delete character forward'],
        'tui.editor.deleteWordBackward' => ['defaultKeys' => ['ctrl+w', 'alt+backspace'], 'description' => 'Delete word backward'],
        'tui.editor.deleteWordForward' => ['defaultKeys' => ['alt+d', 'alt+delete'], 'description' => 'Delete word forward'],
        'tui.editor.deleteToLineStart' => ['defaultKeys' => 'ctrl+u', 'description' => 'Delete to line start'],
        'tui.editor.deleteToLineEnd' => ['defaultKeys' => 'ctrl+k', 'description' => 'Delete to line end'],
        'tui.editor.yank' => ['defaultKeys' => 'ctrl+y', 'description' => 'Yank'],
        'tui.editor.yankPop' => ['defaultKeys' => 'alt+y', 'description' => 'Yank pop'],
        'tui.editor.undo' => ['defaultKeys' => 'ctrl+-', 'description' => 'Undo'],
        'tui.input.newLine' => ['defaultKeys' => ['shift+enter', 'ctrl+j'], 'description' => 'Insert newline'],
        'tui.input.submit' => ['defaultKeys' => 'enter', 'description' => 'Submit input'],
        'tui.input.tab' => ['defaultKeys' => 'tab', 'description' => 'Tab / autocomplete'],
        'tui.input.copy' => ['defaultKeys' => 'ctrl+c', 'description' => 'Copy selection'],
        'tui.select.up' => ['defaultKeys' => 'up', 'description' => 'Move selection up'],
        'tui.select.down' => ['defaultKeys' => 'down', 'description' => 'Move selection down'],
        'tui.select.pageUp' => ['defaultKeys' => 'pageUp', 'description' => 'Selection page up'],
        'tui.select.pageDown' => ['defaultKeys' => 'pageDown', 'description' => 'Selection page down'],
        'tui.select.confirm' => ['defaultKeys' => 'enter', 'description' => 'Confirm selection'],
        'tui.select.cancel' => ['defaultKeys' => ['escape', 'ctrl+c'], 'description' => 'Cancel selection'],
        // These intentionally shadow the unmodified editor bindings in fullscreen mode.
        'tui.altScreen.pageUp' => ['defaultKeys' => 'pageUp', 'description' => 'Scroll viewport up one page'],
        'tui.altScreen.pageDown' => ['defaultKeys' => 'pageDown', 'description' => 'Scroll viewport down one page'],
        'tui.altScreen.halfPageUp' => ['defaultKeys' => [], 'description' => 'Scroll viewport up half a page'],
        'tui.altScreen.halfPageDown' => ['defaultKeys' => [], 'description' => 'Scroll viewport down half a page'],
        'tui.altScreen.lineUp' => ['defaultKeys' => [], 'description' => 'Scroll viewport up one line'],
        'tui.altScreen.lineDown' => ['defaultKeys' => [], 'description' => 'Scroll viewport down one line'],
        'tui.altScreen.previousPrompt' => ['defaultKeys' => ['ctrl+shift+up', 'ctrl+up'], 'description' => 'Jump to previous semantic prompt'],
        'tui.altScreen.nextPrompt' => ['defaultKeys' => ['ctrl+shift+down', 'ctrl+down'], 'description' => 'Jump to next semantic prompt'],
        'tui.altScreen.search' => ['defaultKeys' => 'ctrl+shift+f', 'description' => 'Search the primary scroll view'],
        'tui.altScreen.searchNext' => ['defaultKeys' => ['enter', 'ctrl+g'], 'description' => 'Select the next search match'],
        'tui.altScreen.searchPrevious' => ['defaultKeys' => ['shift+enter', 'ctrl+shift+g'], 'description' => 'Select the previous search match'],
        'tui.altScreen.searchClose' => ['defaultKeys' => 'escape', 'description' => 'Close transcript search'],
        'tui.altScreen.top' => ['defaultKeys' => 'ctrl+home', 'description' => 'Scroll viewport to top'],
        'tui.altScreen.bottom' => ['defaultKeys' => 'ctrl+end', 'description' => 'Scroll viewport to bottom'],
    ];

    private static ?KeybindingsManager $globalKeybindings = null;

    /**
     * @param array<string, array{defaultKeys: string|list<string>, description?: string}> $data
     *
     * @return array<string, KeybindingDefinition>
     */
    public static function definitions(array $data = self::TUI_KEYBINDINGS): array
    {
        return array_map(
            static fn (array $definition): KeybindingDefinition => new KeybindingDefinition($definition['defaultKeys'], $definition['description'] ?? null),
            $data,
        );
    }

    public static function setKeybindings(KeybindingsManager $keybindings): void
    {
        self::$globalKeybindings = $keybindings;
    }

    public static function getKeybindings(): KeybindingsManager
    {
        return self::$globalKeybindings ??= new KeybindingsManager(self::definitions());
    }

    /** Back to the defaults — for tests, which share the process-wide registry. */
    public static function reset(): void
    {
        self::$globalKeybindings = null;
    }
}
