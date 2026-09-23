<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Hooks;

use Closure;
use Pig\CodingAgent\Theme\Palette;

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
 * Ported whole from upstream's `HookUIContext`; `theme` is `palette()`, pig's name for the
 * same thing.
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

    /** Ask for a line of text. Null when cancelled, or when there is no UI. */
    public function input(string $title, string $placeholder = ''): ?string;

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
     * the palette, and a `done()` to call with the answer; it returns the component to show.
     *
     * ```php
     * $count = $ctx->ui->custom(function ($tui, $palette, $done) {
     *     $list = new SelectList([...], 8, $palette->selectListTheme());
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
     * @param Closure(\Pig\Tui\Tui, Palette, Closure(mixed): void): \Pig\Tui\Component $factory
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

    /** The colours this session is drawn in, for styling a status line. */
    public function palette(): Palette;
}
