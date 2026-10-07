<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Interactive;

use Pig\CodingAgent\Theme\Themes;
use Pig\Tui\Component;
use Pig\Tui\Width;

/**
 * A cheat sheet overlay displayed when holding down the Command (⌘) key, similar to Blink Shell / iPadOS.
 *
 * Automatically pops up when Command is held for ~450ms and dismisses the moment Command is released.
 */
final class CommandHudComponent implements Component
{
    /** @var list<array{0: string, 1: string}> */
    public const array SHORTCUTS = [
        ['⌘ + Enter', 'Send message (idle) / Follow-up queue (working)'],
        ['Enter', 'Send message (idle) / Steer interrupt (working)'],
        ['Shift + Enter', 'Insert newline in prompt'],
        ['Esc', 'Interrupt the agent / Cancel open dialog'],
        ['Ctrl + C', 'Clear prompt / Exit when prompt is empty'],
        ['Ctrl + O', 'Expand / collapse tool execution output'],
        ['Ctrl + G', 'Edit prompt externally in $VISUAL or $EDITOR'],
        ['Ctrl + L', 'Select model from available models'],
        ['Ctrl + T', 'Toggle thinking blocks visibility'],
        ['Shift + Tab', 'Cycle reasoning thinking level'],
        ['Alt + Up', 'Restore queued messages back into editor'],
    ];

    public function __construct()
    {
    }

    #[\Override]
    public function invalidate(): void
    {
    }

    #[\Override]
    public function render(int $width): array
    {
        $safeWidth = max(20, $width);
        $innerWidth = max(16, min(76, $safeWidth - 4));

        $title = $innerWidth < 36 ? ' ⌘ Shortcuts ' : ' ⌘ Shortcuts (Release ⌘ to close) ';
        $borderCol = fn (string $text): string => Themes::theme()->fg('border', $text);
        $keyCol = fn (string $text): string => Themes::theme()->fg('accent', $text);
        $descCol = fn (string $text): string => Themes::theme()->fg('muted', $text);

        $lines = [];
        $ruleLen = max(0, $innerWidth - Width::visible($title) - 1);
        $rule = str_repeat('─', $ruleLen);
        $lines[] = $borderCol(' ┌─') . Themes::theme()->fg('accent', $title) . $borderCol($rule . '┐');

        $colWidth = 14;
        foreach (self::SHORTCUTS as [$key, $desc]) {
            $keyPadded = Width::pad($key, $colWidth);
            $availableDesc = max(1, $innerWidth - $colWidth - 4);
            $descCut = Width::truncate($desc, $availableDesc);
            $rowContent = '  ' . $keyCol($keyPadded) . ' ' . $descCol($descCut);
            $padRight = str_repeat(' ', max(0, $innerWidth - Width::visible($rowContent)));
            $lines[] = $borderCol(' │') . $rowContent . $padRight . $borderCol('│');
        }

        $lines[] = $borderCol(' └' . str_repeat('─', $innerWidth) . '┘');

        return $lines;
    }
}
