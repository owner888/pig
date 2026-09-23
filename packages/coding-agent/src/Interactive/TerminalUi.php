<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Interactive;

use Closure;
use Pig\Async\Deferred;
use Pig\CodingAgent\Hooks\HookUi;
use Pig\CodingAgent\Theme\Palette;
use Pig\Tui\Components\Editor;
use Pig\Tui\Components\Input;
use Pig\Tui\Components\SelectItem;
use Pig\Tui\Components\SelectList;
use Pig\Tui\Components\Spacer;
use Pig\Tui\Components\Text;
use Pig\Tui\Component;
use Pig\Tui\Container;
use Pig\Tui\Tui;
use Pig\Tui\TuiError;
use Throwable;

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
 *   handler for this, and the multi-line one gets escape through `CustomEditor` — because an
 *   un-escapable prompt in front of a suspended fiber is a session that has to be killed.
 * - Only one dialog at a time. A second `confirm()` while the first is open would take
 *   focus from it, leaving the first fiber waiting on a component nobody can reach. The
 *   second is refused with its safe answer instead.
 */
final class TerminalUi implements HookUi
{
    /** Set while a dialog is open, so a second one is refused rather than stacked. */
    private bool $busy = false;

    /**
     * @param Closure(): Palette          $palette        the current one, not the one at startup
     * @param Closure(string): ?string|null $externalEditor `$VISUAL` on some text, for Ctrl+G
     *        inside the dialog; null when the host cannot hand the terminal over
     */
    public function __construct(
        private readonly Tui $tui,
        private readonly Container $chat,
        private readonly Container $status,
        private readonly CustomEditor $editor,
        private readonly FooterComponent $footer,
        private readonly Closure $palette,
        private readonly ?Closure $externalEditor = null,
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

    /**
     * A multi-line editor as a dialog.
     *
     * A `CustomEditor` rather than a bare `Editor`, which is what gets escape and Ctrl+G:
     * the bare component treats both as text, and the wrapper is exactly the piece that
     * turns them into named keys — the same piece the prompt uses, so the keys mean the
     * same thing in both places.
     */
    #[\Override]
    public function editor(string $title, string $prefill = ''): ?string
    {
        if ($this->busy) {
            return null;
        }

        $this->busy = true;
        $answer = new Deferred();
        $field = new CustomEditor(new Editor($this->palette()->editorTheme()));
        $field->setText($prefill);

        $field->setSubmitHandler(function (string $value) use ($answer): void {
            $this->close();
            $answer->complete($value);
        });

        $field->on('escape', function () use ($answer): void {
            $this->close();
            $answer->complete(null);
        });

        // Ctrl+G hands the text to `$VISUAL` and puts back whatever comes out. The dialog
        // stays open and stays focused, because the person is still answering the question.
        $field->on('ctrl+g', function () use ($field): void {
            $edited = $this->externalEditor === null ? null : ($this->externalEditor)($field->text());

            if ($edited !== null) {
                $field->setText($edited);
            }

            // Forced: the editor drew over the whole screen, so there is no previous frame
            // to diff against.
            $this->tui->setFocus($field);
            $this->tui->requestRender(true);
        });

        $this->open($title . '  ' . $this->palette()->fg('dim', 'enter to finish · shift+enter for a line'), $field);

        $value = $answer->future->await();

        return is_string($value) ? $value : null;
    }

    /**
     * Show whatever a hook built, and wait for it to say it is done.
     *
     * `done()` is idempotent and always closes: a component that calls it twice — a select
     * handler and a cancel handler both firing on the same key, say — resolves once rather
     * than throwing into the middle of a turn.
     *
     * What cannot be guarded from here is a component that never calls it and does not
     * handle escape. It has the keys, and nothing else can take them back. See the note on
     * `HookUi::custom()`.
     */
    #[\Override]
    public function custom(Closure $factory): mixed
    {
        if ($this->busy) {
            return null;
        }

        $this->busy = true;
        $answer = new Deferred();

        $done = function (mixed $result = null) use ($answer): void {
            if ($answer->isComplete()) {
                return;
            }

            $this->close();
            $answer->complete($result);
        };

        try {
            $component = $factory($this->tui, $this->palette(), $done);
        } catch (Throwable $error) {
            // The dialog never opened, so the turn is not parked — but `busy` was set and
            // has to come back off, or nothing could ever ask anything again.
            $this->busy = false;

            throw $error;
        }

        if (!$component instanceof Component) {
            $this->busy = false;

            throw new TuiError('A custom dialog must return a component, got ' . get_debug_type($component));
        }

        $this->open('', $component);

        return $answer->future->await();
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

        // An empty title draws nothing rather than a blank line: `custom()` has none, and
        // a hook that wanted one drew it itself.
        if ($title !== '') {
            $this->status->addChild(new Text($this->palette()->fg('muted', $title), 1, 0));
        }

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
