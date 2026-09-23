<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Hooks;

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
 * Ported from upstream's `HookUIContext`, minus two members:
 *
 * - `custom()`, which hands a hook the `TUI` and a `done()` callback and lets it draw
 *   whatever it likes. Portable in principle — pig has the components — but it is a
 *   surface that exposes the renderer's internals to code loaded off disk, and nothing
 *   here needs it yet.
 * - `editor()`, a multi-line overlay with `$VISUAL` support. pig's `Editor` is built into
 *   the prompt rather than openable as a dialog, and `$VISUAL` has no counterpart here
 *   at all.
 *
 * `theme` is `palette()` — pig's name for the same thing.
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
