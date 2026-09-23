<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Hooks;

use Closure;
use Pig\CodingAgent\Theme\Palette;

/**
 * The UI when there isn't one.
 *
 * `examples/agent.php`, a test, anything that runs without a terminal. Upstream's
 * `noOpUIContext`, and the answers are its answers: nothing to choose from, so no choice;
 * nothing to type into, so no text; and `confirm()` is **false**, which is the only safe
 * direction — a hook that asks "may I run this?" and cannot be answered has not been told
 * yes.
 *
 * Every call is silent. A hook written for a terminal is not broken by running without
 * one; it is answered, and `hasUI` on the context is how it can tell the difference
 * beforehand.
 */
final readonly class NoUi implements HookUi
{
    public function __construct(private ?Palette $palette = null)
    {
    }

    #[\Override]
    public function select(string $title, array $options): ?string
    {
        return null;
    }

    #[\Override]
    public function confirm(string $title, string $message): bool
    {
        return false;
    }

    #[\Override]
    public function input(string $title, string $placeholder = ''): ?string
    {
        return null;
    }

    #[\Override]
    public function editor(string $title, string $prefill = ''): ?string
    {
        return null;
    }

    /** The factory is never called: there is no screen to put what it would build on. */
    #[\Override]
    public function custom(Closure $factory): mixed
    {
        return null;
    }

    #[\Override]
    public function notify(string $message, string $level = 'info'): void
    {
    }

    #[\Override]
    public function setStatus(string $key, ?string $text): void
    {
    }

    #[\Override]
    public function setEditorText(string $text): void
    {
    }

    #[\Override]
    public function getEditorText(): string
    {
        return '';
    }

    /**
     * The dark palette, because something has to be returned and a hook styling a status
     * line it will never show should not have to check for null first.
     */
    #[\Override]
    public function palette(): Palette
    {
        return $this->palette ?? Palette::dark();
    }
}
