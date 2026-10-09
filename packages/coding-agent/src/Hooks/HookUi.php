<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Hooks;

use Closure;
use Pig\CodingAgent\Interactive\CustomEditor;
use Pig\CodingAgent\Keybindings;
use Pig\Tui\Autocomplete\AutocompleteProvider;
use Pig\Tui\Components\EditorTheme;
use Pig\Tui\TUI;
use Pig\CodingAgent\Theme\Theme;
use Pig\CodingAgent\Theme\ThemeInfo;

/**
 * Asking the person something, from inside a hook or a custom tool.
 *
 * This is what makes `tool_call` worth having: a guard that can only say yes or no has to
 * guess, and a guard that can ask does not. `confirm()` mid-tool-call is the case the
 * whole thing exists for.
 *
 * **It blocks the turn it was asked from, on purpose.** A hook's handler runs inside the
 * agent's fiber, so awaiting an answer suspends that fiber and nothing else: the loop
 * keeps turning, the keystroke arrives, the answer resolves, the handler carries on where
 * it stopped. That is the one thing `pig/async` exists for. The cost is that a person who
 * walks away leaves the turn parked — which is why every one of these can be cancelled,
 * and why `Input` grew an escape handler to make that true.
 *
 * Ported whole from upstream's `HookUIContext`; upstream's `theme` getter is `theme()`.
 */
interface HookUi
{
    /**
     * Offer a list and wait for a choice.
     *
     * @param list<string> $options
     * @return string|null null when it was cancelled, or when there is no UI
     */
    public function select(string $title, array $options): ?string;

    /**
     * Ask a yes-or-no question.
     *
     * False when cancelled, and false when there is no UI — which for a `tool_call` guard
     * means a tool nobody could approve is a tool that does not run. That is upstream's
     * choice and the safe direction.
     */
    public function confirm(string $title, string $message): bool;

    /**
     * Ask for a line of text. Null when cancelled, or when there is no UI.
     *
     * `$signal` closes the dialog from outside, answering null — upstream's `{ signal }`, for a
     * prompt raced against something else that may answer first (the MCP sign-in's pasted redirect
     * URL against the browser callback).
     */
    public function input(string $title, string $placeholder = '', ?\Pig\Async\AbortSignal $signal = null): ?string;

    /**
     * Ask for something longer, in a real editor.
     *
     * Enter finishes, shift+enter adds a line, escape cancels, and Ctrl+G hands the whole
     * thing to `$VISUAL` — the same key and the same code as Ctrl+G at the prompt.
     *
     * @return string|null null when cancelled, or when there is no UI
     */
    public function editor(string $title, string $prefill = ''): ?string;

    /**
     * Draw something pig has no dialog for, and wait for it to finish.
     *
     * The escape hatch, for a hook that wants a progress bar, a diff, a two-column picker
     * — anything the four above are the wrong shape for. `$factory` is given the screen,
     * the current theme, and a `done()` to call with the answer; it returns the component to show.
     *
     * ```php
     * $count = $ctx->ui->custom(function ($tui, $theme, $done) {
     *     $list = new SelectList([...], 8, Themes::getSelectListTheme());
     *     $list->setSelectHandler(fn ($item) => $done((int) $item->value));
     *     $list->setCancelHandler(fn () => $done(null));
     *
     *     return $list;
     * });
     * ```
     *
     * **A component that never calls `done()` parks the turn.** It has the keys while it is
     * open, so if it also ignores escape there is no way out and the session has to be
     * killed. Whatever is returned must have a path to `done()` for a person who has
     * changed their mind — usually escape, which is what every other dialog here uses.
     *
     * @param Closure(\Pig\Tui\TUI, Theme, Closure(mixed): void): \Pig\Tui\Component $factory
     * @return mixed whatever was passed to `done()`, or null when there is no UI
     */
    public function custom(Closure $factory): mixed;

    /** Put a line in the transcript. @param 'info'|'warning'|'error' $level */
    public function notify(string $message, string $level = 'info'): void;

    /**
     * Keep something on the footer under $key until it is replaced or cleared.
     *
     * For what a notification is wrong for: a count that changes, a watch that is running.
     * Null clears it.
     */
    public function setStatus(string $key, ?string $text): void;

    /** Put text in the prompt editor, for the person to look at before sending. */
    public function setEditorText(string $text): void;

    /** What is in the prompt editor now. */
    public function getEditorText(): string;

    /** The theme this session is drawn in, for styling a status line. Upstream's `theme` getter. */
    public function theme(): Theme;

    /** Put text at the editor caret, as if pasted. Upstream's `pasteToEditor()`. */
    public function pasteToEditor(string $text): void;

    /** Set the terminal window/tab title. Upstream's `setTitle()`. */
    public function setTitle(string $title): void;

    /** Set custom text on the working loader (null restores default). Upstream's `setWorkingMessage()`. */
    public function setWorkingMessage(?string $message = null): void;

    /** Show or hide the working loader explicitly. Upstream's `setWorkingVisible()`. */
    public function setWorkingVisible(bool $visible): void;

    /**
     * Configure the working indicator shown during streaming: no argument restores the default
     * spinner, `['frames' => ['●']]` is a still mark, `['frames' => []]` hides it. Custom frames
     * are drawn as given, so an extension adds its own colours.
     *
     * @param array{frames?: list<string>, intervalMs?: int}|null $options
     */
    public function setWorkingIndicator(?array $options = null): void;

    /** Set label for hidden thinking blocks. Upstream's `setHiddenThinkingLabel()`. */
    public function setHiddenThinkingLabel(?string $label = null): void;

    /** Whether tools are currently expanded in the transcript. Upstream's `getToolsExpanded()`. */
    public function getToolsExpanded(): bool;

    /** Expand or collapse tools in the transcript. Upstream's `setToolsExpanded()`. */
    public function setToolsExpanded(bool $expanded): void;

    /**
     * Add or update an extension widget above or below the prompt. Upstream's `setWidget()`.
     *
     * @param string $key unique widget identifier
     * @param list<string>|Closure(\Pig\Tui\TUI, Theme): \Pig\Tui\Component|null $content null removes it
     * @param array{placement?: 'above'|'below'} $options
     */
    public function setWidget(string $key, array|Closure|null $content, array $options = []): void;

    /** Set a custom header component above the chat. Null removes it. Upstream's `setHeader()`. */
    public function setHeader(?Closure $factory): void;

    /** Set a custom footer component. Null removes it. Upstream's `setFooter()`. */
    public function setFooter(?Closure $factory): void;

    /**
     * All available themes with their names and file paths. Upstream's `getAllThemes()`.
     *
     * @return list<ThemeInfo>
     */
    public function getAllThemes(): array;

    /** Load a theme by name without switching to it. Null if not found. Upstream's `getTheme()`. */
    public function getTheme(string $name): ?Theme;

    /**
     * Set the current theme by name or Theme object. Upstream's `setTheme()`.
     *
     * @return array{success: bool, error?: string}
     */
    public function setTheme(string|Theme $theme): array;

    /**
     * Intercept raw terminal input before components see it. Upstream's `onTerminalInput()`.
     *
     * @param callable(string): (bool|array{consume?: bool, data?: string}|null) $handler
     * @return Closure(): void unregister callback
     */
    public function onTerminalInput(callable $handler): Closure;

    /**
     * Stack autocomplete behaviour on top of the built-in provider. Upstream's
     * `addAutocompleteProvider()`: the factory is handed the provider as it stands and answers
     * the one to use — wrapping it, usually. Applied again on `/reload`, in the order added.
     *
     * @param Closure(AutocompleteProvider): AutocompleteProvider $factory
     */
    public function addAutocompleteProvider(Closure $factory): void;

    /**
     * Replace the prompt with an editor of the extension's own, or restore the default with null.
     * Upstream's `setEditorComponent()`: the factory is handed the TUI, the editor theme and the
     * keybindings and answers a `CustomEditor` — a subclass whose `handleInput()` calls
     * `parent::handleInput()` for the keys it does not take keeps every app binding.
     *
     * @param (Closure(TUI, EditorTheme, Keybindings): CustomEditor)|null $factory
     */
    public function setEditorComponent(?Closure $factory): void;

    /** The factory in use, or null for the default editor. */
    public function getEditorComponent(): ?Closure;
}
