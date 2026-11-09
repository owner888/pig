<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Interactive;

use Closure;
use Pig\CodingAgent\Keybindings;
use Pig\Tui\TUI;

/**
 * Upstream's `CompactionStatusIndicator`: `Compacting context...` for `/compact`,
 * `Auto-compacting...` for the threshold, with `Context overflow detected, ` in front for an
 * overflow — each followed by the interrupt key as `keyText()` names it.
 */
final class CompactionStatusIndicator extends StatusIndicator
{
    /**
     * @param 'manual'|'threshold'|'overflow' $reason
     * @param Closure(string): string|null $colorFn
     */
    public function __construct(TUI $tui, Keybindings $keybindings, string $reason, ?Closure $colorFn = null)
    {
        parent::__construct('compaction', $tui, $colorFn ?? self::accent(), $colorFn ?? self::muted(), self::label($keybindings, $reason));
    }

    public static function label(Keybindings $keybindings, string $reason): string
    {
        $cancelHint = '(' . $keybindings->keyText('app.interrupt') . ' to cancel)';

        return $reason === 'manual'
            ? "Compacting context... {$cancelHint}"
            : ($reason === 'overflow' ? 'Context overflow detected, ' : '') . "Auto-compacting... {$cancelHint}";
    }
}
