<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Interactive;

use Closure;
use Pig\CodingAgent\Keybindings;
use Pig\Tui\Ansi;
use Pig\Tui\Autocomplete\AutocompleteProvider;
use Pig\Tui\Clipboard\Clipboard;
use Pig\Tui\Caret;
use Pig\Tui\Component;
use Pig\Tui\Components\Editor;
use Pig\Tui\Components\EditorTheme;
use Pig\Tui\Components\Loader;
use Pig\Tui\InputHandler;
use Pig\Tui\Keys;

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
 * Ported from upstream's `components/custom-editor.ts`.
 */
final class CustomEditor implements Caret, Component, InputHandler
{
    /** @var array<string, Closure(): void> */
    private array $handlers = [];

    public function __construct(
        private readonly Editor $editor,
        // Which key means which action. The defaults are upstream's; `keybindings.json` moves
        // one, and this is the one place that reads the file's answer, so `on()` binds actions
        // and never keys.
        private readonly Keybindings $keybindings = new Keybindings(),
    ) {
    }

    /**
     * @param string $key one of escape, ctrl+c, ctrl+d, ctrl+g, ctrl+l, ctrl+o, ctrl+t,
     *        ctrl+z, shift+tab, ctrl+p, shift+ctrl+p
     * @param Closure(): void $handler
     */
    public function on(string $key, Closure $handler): void
    {
        $this->handlers[$key] = $handler;
    }

    #[\Override]
    public function handleInput(string $data): void
    {
        $key = $this->claimed($data);

        if ($key !== null && isset($this->handlers[$key])) {
            ($this->handlers[$key])();

            return;
        }

        // Ctrl+D is swallowed either way: it means end-of-input, and there is nothing
        // sensible for a text field to do with it.
        if (Keys::isCtrlD($data)) {
            return;
        }

        $this->editor->handleInput($data);
    }

    /**
     * Which of the application's keys this is, if any.
     *
     * Shift+Ctrl+P is tested before Ctrl+P because the first also matches the second.
     */
    private function claimed(string $data): ?string
    {
        $action = $this->keybindings->actionFor($data);

        return match ($action) {
            // While the completion list is open, Escape belongs to it: it closes the
            // list, which is what someone who pressed it there meant.
            'app.interrupt' => $this->editor->isShowingSuggestions() ? null : $action,
            // Exit only from an empty prompt; with text in it the key is end-of-input, swallowed.
            'app.exit' => $this->editor->text() === '' ? $action : null,
            default => $action,
        };
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

    /** Forwarded, or the terminal's cursor would never find the editor inside here. */
    #[\Override]
    public function caret(int $width): ?array
    {
        return $this->editor->caret($width);
    }

    #[\Override]
    public function invalidate(): void
    {
        $this->editor->invalidate();
    }

    #[\Override]
    public function render(int $width): array
    {
        return $this->editor->render($width);
    }
}
