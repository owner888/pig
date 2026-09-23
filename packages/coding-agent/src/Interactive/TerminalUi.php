<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Interactive;

use Closure;
use Pig\Async\Deferred;
use Pig\CodingAgent\Hooks\HookUi;
use Pig\CodingAgent\Theme\Palette;
use Pig\Tui\Components\Input;
use Pig\Tui\Components\SelectItem;
use Pig\Tui\Components\SelectList;
use Pig\Tui\Components\Spacer;
use Pig\Tui\Components\Text;
use Pig\Tui\Container;
use Pig\Tui\Tui;

/**
 * `HookUi`, drawn in the terminal.
 *
 * Upstream builds this as an object literal closing over the interactive mode's
 * internals. Here it takes them: the screen to focus, the two containers it draws into,
 * the editor it can read and write, and a closure for the current palette — a closure
 * because `/theme` replaces it and a dialog opened afterwards should be the new colour.
 *
 * **How blocking works.** A hook's handler runs inside the agent's fiber. `select()`
 * draws a list, gives it focus, and awaits a `Deferred`; the fiber suspends there and the
 * loop carries on serving the terminal. The keystroke that chooses an item completes the
 * deferred, the fiber resumes, and the handler gets its answer as an ordinary return
 * value. Nothing about the call site has to know any of that happened.
 *
 * **What is guarded against.** Two things, both of which park a turn forever:
 *
 * - Every dialog can be escaped. `SelectList` always could; `Input` was given a cancel
 *   handler for this, because an un-cancellable prompt in front of a suspended fiber is a
 *   session that has to be killed.
 * - Only one dialog at a time. A second `confirm()` while the first is open would take
 *   focus from it, leaving the first fiber waiting on a component nobody can reach. The
 *   second is refused with its safe answer instead.
 */
final class TerminalUi implements HookUi
{
    /** Set while a dialog is open, so a second one is refused rather than stacked. */
    private bool $busy = false;

    /** @param Closure(): Palette $palette the current one, not the one at startup */
    public function __construct(
        private readonly Tui $tui,
        private readonly Container $chat,
        private readonly Container $status,
        private readonly CustomEditor $editor,
        private readonly FooterComponent $footer,
        private readonly Closure $palette,
    ) {
    }

    #[\Override]
    public function select(string $title, array $options): ?string
    {
        if ($this->busy || $options === []) {
            return null;
        }

        $items = array_map(static fn (string $option): SelectItem => new SelectItem($option), $options);

        return $this->ask($title, new SelectList($items, 8, $this->palette()->selectListTheme()));
    }

    /**
     * Yes or no, as a two-item list.
     *
     * A list rather than a "type y" prompt because the answer is a choice from two, and
     * because escape then means the same thing here as everywhere else.
     */
    #[\Override]
    public function confirm(string $title, string $message): bool
    {
        if ($this->busy) {
            return false;
        }

        $items = [new SelectItem('no', 'No'), new SelectItem('yes', 'Yes')];
        $list = new SelectList($items, 2, $this->palette()->selectListTheme());

        return $this->ask(
            trim($message) === '' ? $title : $title . ' — ' . $message,
            $list,
        ) === 'yes';
    }

    #[\Override]
    public function input(string $title, string $placeholder = ''): ?string
    {
        if ($this->busy) {
            return null;
        }

        $this->busy = true;
        $answer = new Deferred();
        $field = new Input();

        $field->setSubmitHandler(function (string $value) use ($answer): void {
            $this->close();
            $answer->complete($value);
        });

        $field->setCancelHandler(function () use ($answer): void {
            $this->close();
            $answer->complete(null);
        });

        $this->open($title . ($placeholder === '' ? '' : " ({$placeholder})"), $field);

        $value = $answer->future->await();

        return is_string($value) ? $value : null;
    }

    #[\Override]
    public function notify(string $message, string $level = 'info'): void
    {
        $palette = $this->palette();

        [$colour, $prefix] = match ($level) {
            'error' => ['error', 'Error: '],
            'warning' => ['warning', 'Warning: '],
            default => ['dim', ''],
        };

        $this->chat->addChild(new Spacer(1));
        $this->chat->addChild(new Text($palette->fg($colour, $prefix . $message), 1, 0));
        $this->tui->requestRender();
    }

    #[\Override]
    public function setStatus(string $key, ?string $text): void
    {
        $this->footer->setStatus($key, $text);
        $this->tui->requestRender();
    }

    #[\Override]
    public function setEditorText(string $text): void
    {
        $this->editor->setText($text);
        $this->tui->requestRender();
    }

    #[\Override]
    public function getEditorText(): string
    {
        return $this->editor->text();
    }

    #[\Override]
    public function palette(): Palette
    {
        return ($this->palette)();
    }

    /**
     * Show a list under a title and wait for the answer.
     *
     * @return string|null the chosen value, or null on escape
     */
    private function ask(string $title, SelectList $list): ?string
    {
        $this->busy = true;
        $answer = new Deferred();

        $list->setSelectHandler(function (SelectItem $item) use ($answer): void {
            $this->close();
            $answer->complete($item->value);
        });

        $list->setCancelHandler(function () use ($answer): void {
            $this->close();
            $answer->complete(null);
        });

        $this->open($title, $list);

        $value = $answer->future->await();

        return is_string($value) ? $value : null;
    }

    /** Put $component in the status area, with the title above it, and give it the keys. */
    private function open(string $title, object $component): void
    {
        $this->status->clear();
        $this->status->addChild(new Spacer(1));
        $this->status->addChild(new Text($this->palette()->fg('muted', $title), 1, 0));
        $this->status->addChild($component);

        $this->tui->setFocus($component);
        $this->tui->requestRender();
    }

    /** Take the dialog down and hand the keys back to the prompt. */
    private function close(): void
    {
        $this->busy = false;
        $this->status->clear();
        $this->tui->setFocus($this->editor);
        $this->tui->requestRender();
    }
}
