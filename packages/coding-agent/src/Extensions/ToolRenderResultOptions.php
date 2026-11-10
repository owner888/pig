<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Extensions;

use Pig\CodingAgent\Keybindings;
use Pig\CodingAgent\Theme\Themes;
use Pig\Tui\Keybindings as TuiKeybindings;

/**
 * How a result is being shown, for a tool drawing it itself.
 *
 * Upstream's `RenderResultOptions`. Both matter: `$partial` means the tool is still running
 * and this is progress rather than an answer, and `$expanded` is ctrl+o — the difference
 * between the five lines that fit and everything there is.
 *
 * `$isError` and `$timer` are upstream's `context.isError` and `toolTimerText()`: whether the
 * call failed, and `Elapsed 1.2s` / `Took 1.2s` for a tool that shows how long it ran — null
 * when nothing timed it. `$durationMs` is upstream's `context.durationMs`: the milliseconds
 * the tool's execution took, from the final result — null while it runs, when it did not run,
 * or for results saved before durations were recorded. `renderCall` is given one too, as its
 * third argument, for upstream's `context.expanded`.
 *
 * `$outputPad` is upstream's `context.outputPad` (#10557): "Horizontal padding configured by the
 * outputPad setting" — the blank column either side, for a renderer that lays out its own lines.
 *
 * `keyText()` and `keyHint()` are upstream's `KeybindingHints` (#11630), which every render context
 * carries — a tool's, a message renderer's and an entry renderer's: the keys bound to an action as
 * the person set them, so a renderer's `(ctrl+o to expand)` names the key that actually expands.
 */
final readonly class ToolRenderResultOptions
{
    public function __construct(
        public bool $expanded = false,
        public bool $partial = false,
        public bool $isError = false,
        public ?string $timer = null,
        public ?int $durationMs = null,
        public int $outputPad = 1,
    ) {
    }

    /** The keys bound to `$keybinding` (`app.tools.expand`, say), joined by `/`; empty when none is. */
    public function keyText(string $keybinding): string
    {
        $keybindings = TuiKeybindings::getKeybindings();

        return $keybindings instanceof Keybindings
            ? $keybindings->keyText($keybinding)
            : implode('/', $keybindings->getKeys($keybinding));
    }

    /** The keys dim and the description muted after them, as pig draws its own hints. */
    public function keyHint(string $keybinding, string $description): string
    {
        return Themes::theme()->fg('dim', $this->keyText($keybinding)) . Themes::theme()->fg('muted', " {$description}");
    }
}
