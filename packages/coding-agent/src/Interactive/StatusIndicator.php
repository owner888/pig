<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Interactive;

use Closure;
use Pig\CodingAgent\Theme\Themes;
use Pig\Tui\Components\Loader;
use Pig\Tui\TUI;

/**
 * A status line above the prompt while something is going on — upstream's `StatusIndicator`
 * (`status-indicator.ts`), a `Loader` that knows what kind of status it is, so the mode can ask
 * "is a retry up?" of the indicator rather than of a string beside it. The four kinds are the
 * four subclasses; `other` is a command's own loader (`InteractiveMode::showLoader()`).
 *
 * Drawn where pig draws every status: in the rule above the prompt (`CustomEditor::setWorkingStatus()`),
 * upstream's `embedWorkingStatus`. Upstream's `renderInBorder()` is that editor's business here,
 * and its `IdleStatus` — two blank rows where no indicator is — has no counterpart, because the
 * border is already there when nothing is in it.
 */
class StatusIndicator extends Loader
{
    /**
     * @param 'working'|'retry'|'compaction'|'branchSummary'|'other' $kind
     * @param Closure(string): string $spinnerStyle
     * @param Closure(string): string $messageStyle
     */
    public function __construct(
        public readonly string $kind,
        TUI $tui,
        Closure $spinnerStyle,
        Closure $messageStyle,
        string $message,
    ) {
        parent::__construct($tui, $spinnerStyle, $messageStyle, $message);
    }

    /** Upstream's `dispose()`: the spinner stopped, and whatever the kind keeps going let go of. */
    public function dispose(): void
    {
        $this->stop();
    }

    /** @return Closure(string): string the theme's accent, for a spinner */
    protected static function accent(): Closure
    {
        return static fn (string $text): string => Themes::theme()->fg('accent', $text);
    }

    /** @return Closure(string): string the theme's muted, for a message */
    protected static function muted(): Closure
    {
        return static fn (string $text): string => Themes::theme()->fg('muted', $text);
    }
}
