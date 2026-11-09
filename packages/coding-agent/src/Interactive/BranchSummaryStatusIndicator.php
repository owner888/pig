<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Interactive;

use Pig\CodingAgent\Keybindings;
use Pig\Tui\TUI;

/** Upstream's `BranchSummaryStatusIndicator`: `Summarizing branch... (escape to cancel)`. */
final class BranchSummaryStatusIndicator extends StatusIndicator
{
    public function __construct(TUI $tui, Keybindings $keybindings)
    {
        parent::__construct('branchSummary', $tui, self::accent(), self::muted(), self::label($keybindings));
    }

    public static function label(Keybindings $keybindings): string
    {
        return 'Summarizing branch... (' . $keybindings->keyText('app.interrupt') . ' to cancel)';
    }
}
