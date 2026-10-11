<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Interactive;

use Closure;
use Pig\CodingAgent\Keybindings;
use Pig\Tui\Ansi;
use Pig\Tui\Autocomplete\AutocompleteProvider;
use Pig\Tui\Clipboard\Clipboard;
use Pig\Tui\Focusable;
use Pig\Tui\Component;
use Pig\Tui\Components\Editor;
use Pig\Tui\Components\EditorTheme;
use Pig\Tui\Components\Loader;
use Pig\Tui\InputHandler;
use Pig\Tui\Keys;
use Pig\Tui\MouseHandler;
use Pig\Tui\TuiMouseDispatchResult;
use Pig\Tui\TuiMouseEvent;
use Pig\Tui\TuiMouseEventResult;

/**
 * The editor, plus the keys the application wants for itself.
 *
 * Escape means "stop what you are doing" to the agent, not to the editor; Ctrl+C at an
 * empty prompt means quit. Neither is something a text field can decide, so they are
 * taken here before the editor sees them.
 *
 * Upstream subclasses `Editor`. `Pig\Tui\Components\Editor` is final, and wrapping it
 * rather than opening it up keeps the list of keys an application may steal explicit —
 * anything not named below reaches the editor unchanged.
 *
 * Not final itself, for the one reason upstream names: an extension's own prompt
 * (`$ctx->ui->setEditorComponent()`) extends this and overrides `handleInput()`, calling
 * `parent::handleInput()` for the keys it does not take, so every app binding still works there.
 *
 * Ported from upstream's `components/custom-editor.ts`.
 */
class CustomEditor implements Component, Focusable, InputHandler, MouseHandler
{
    /** Set by the renderer and handed to the wrapped editor, which is what draws the cursor. */
    public bool $focused = false;

    /** @var array<string, Closure(): void> */
    private array $handlers = [];

    /** @var array<string, array{name: string, handler: Closure, description: string}> */
    private array $shortcuts = [];

    /** Insert text at the editor caret, as if pasted. */
    public function insertAtCursor(string $text): void
    {
        $this->editor->insertAtCursor($text);
    }

    /**
     * Register a keyboard shortcut from an extension.
     *
     * @param Closure(): void $handler
     */
    public function registerShortcut(string $name, Closure $handler, string $description = ''): void
    {
        $this->shortcuts[strtolower($name)] = [
            'name' => $name,
            'handler' => $handler,
            'description' => $description,
        ];
    }

    /** @return array<string, array{name: string, handler: Closure, description: string}> */
    public function shortcuts(): array
    {
        return $this->shortcuts;
    }

    public function __construct(
        private readonly Editor $editor,
        // Which key means which action. The defaults are upstream's; `keybindings.json` moves
        // one, so `on()` binds actions and never keys.
        private readonly Keybindings $keybindings = new Keybindings(),
    ) {
    }

    /**
     * Register a handler for an app action — upstream's `onAction()`. The key is whatever
     * `Keybindings` says the action is on.
     *
     * @param string $action an `app.*` keybinding id
     * @param Closure(): void $handler
     */
    public function on(string $action, Closure $handler): void
    {
        $this->handlers[$action] = $handler;
    }

    /**
     * Upstream's `CustomEditor.handleInput()`, in its order: the paste key, interrupt (unless the
     * completion list is open, where Escape closes it), exit (only from an empty prompt — with
     * text in it the key is the editor's delete-forward), the dedicated history keys (which win
     * over an app action on the same key, so `ctrl+p` can be bound to history), then every other
     * action a handler is registered for, in registration order.
     *
     * Extension shortcuts come after the app's actions rather than before, which is where
     * upstream's land too: its runner drops a shortcut on a reserved app key before it gets here.
     */
    #[\Override]
    public function handleInput(string $data): void
    {
        $kb = $this->keybindings;

        if ($kb->matches($data, 'app.clipboard.pasteImage')) {
            if (isset($this->handlers['app.clipboard.pasteImage'])) {
                ($this->handlers['app.clipboard.pasteImage'])();
            } else {
                $this->editor->pasteFromClipboard();
            }

            return;
        }

        if ($kb->matches($data, 'app.interrupt')) {
            if (!$this->editor->isShowingSuggestions() && isset($this->handlers['app.interrupt'])) {
                ($this->handlers['app.interrupt'])();

                return;
            }

            $this->editor->handleInput($data);

            return;
        }

        if ($kb->matches($data, 'app.exit') && $this->editor->text() === '') {
            if (isset($this->handlers['app.exit'])) {
                ($this->handlers['app.exit'])();
            }

            return;
        }

        if ($kb->matches($data, 'tui.editor.historyPrevious') || $kb->matches($data, 'tui.editor.historyNext')) {
            $this->editor->handleInput($data);

            return;
        }

        foreach ($this->handlers as $action => $handler) {
            if (in_array($action, ['app.interrupt', 'app.exit', 'app.clipboard.pasteImage'], true)) {
                continue;
            }

            if ($kb->matches($data, $action)) {
                $handler();

                return;
            }
        }

        foreach ($this->shortcuts as $spec) {
            if (Keys::matchesName($data, $spec['name'])) {
                ($spec['handler'])();

                return;
            }
        }

        $this->editor->handleInput($data);
    }

    /** Upstream's `pageScroll()` reads the terminal's rows; see `Editor::setTerminalRows()`. */
    public function setTerminalRows(?Closure $rows): void
    {
        $this->editor->setTerminalRows($rows);
    }

    /** The clipboard paste `app.clipboard.pasteImage` does when nobody registered another. */
    public function pasteFromClipboard(): void
    {
        $this->editor->pasteFromClipboard();
    }

    // ---- the editor underneath -------------------------------------------------------

    public function text(): string
    {
        return $this->editor->text();
    }

    public function setText(string $text): void
    {
        $this->editor->setText($text);
    }

    public function addToHistory(string $text): void
    {
        $this->editor->addToHistory($text);
    }

    public function setTheme(EditorTheme $theme): void
    {
        $this->editor->setTheme($theme);
    }

    /** @param Closure(string): void|null $handler */
    public function setSubmitHandler(?Closure $handler): void
    {
        $this->editor->setSubmitHandler($handler);
    }

    /** @param Closure(string): void|null $handler */
    public function setChangeHandler(?Closure $handler): void
    {
        $this->editor->setChangeHandler($handler);
    }

    public function setAutocompleteProvider(?AutocompleteProvider $provider): void
    {
        $this->editor->setAutocompleteProvider($provider);
    }

    /** Upstream's `setAutocompleteMaxVisible()`, which the `/settings` row calls. */
    public function setAutocompleteMaxVisible(int $maxVisible): void
    {
        $this->editor->setAutocompleteMaxVisible($maxVisible);
    }

    /** Upstream's `setPaddingX()`, which the `/settings` row for `editorPaddingX` calls. */
    public function setPaddingX(int $padding): void
    {
        $this->editor->setPaddingX($padding);
    }

    public function setClipboard(?Clipboard $clipboard): void
    {
        $this->editor->setClipboard($clipboard);
    }

    public function disableSubmit(bool $disabled): void
    {
        $this->editor->disableSubmit = $disabled;
    }

    /**
     * Show a loader in the rule above the prompt, or none.
     *
     * Upstream's `setWorkingStatusIndicator()`. The loader is asked on every frame, so its
     * spinner ticks in the border; it is drawn in the border's own colour, as upstream draws it,
     * because a rule with text in it reads as one rule and not as a line sitting on one.
     */
    public function setWorkingStatus(?Loader $loader, ?Closure $style = null): void
    {
        $this->editor->setBorderStatus(
            $loader === null || $style === null
                ? null
                : static fn (int $width): string => $style(Ansi::strip($loader->inBorder())),
        );
    }

    #[\Override]
    public function invalidate(): void
    {
        $this->editor->invalidate();
    }

    #[\Override]
    public function render(int $width): array
    {
        // Upstream's custom editor *is* the editor; this one wraps it, so focus is passed on.
        $this->editor->focused = $this->focused;

        return $this->editor->render($width);
    }

    /**
     * Upstream's custom editor inherits `Editor::handleMouse()`; this one wraps the editor and
     * draws exactly its rows, so the event goes to it unchanged. A focus result names this
     * wrapper (`Mouse::dispatchMouseEvent()` targets the component it was given), which is the
     * one the TUI routes keys to.
     */
    #[\Override]
    public function handleMouse(TuiMouseEvent $event): TuiMouseEventResult|TuiMouseDispatchResult|null
    {
        return $this->editor->handleMouse($event);
    }
}
