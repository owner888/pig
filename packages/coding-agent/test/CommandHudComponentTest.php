<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;
use Pig\CodingAgent\Interactive\CommandHudComponent;
use Pig\CodingAgent\Theme\Palette;
use Pig\Tui\Width;

final class CommandHudComponentTest extends TestCase
{
    public function testRenderFitsVariousWidthsWithoutOverflow(): void
    {
        $hud = new CommandHudComponent(Palette::named('dark'));

        foreach ([30, 50, 80, 120] as $width) {
            $lines = $hud->render($width);
            $this->assertNotEmpty($lines);

            foreach ($lines as $line) {
                $vis = Width::visible($line);
                $this->assertLessThanOrEqual($width, $vis, "Line exceeds width {$width}: {$line}");
            }
        }
    }

    public function testContainsCoreShortcuts(): void
    {
        $hud = new CommandHudComponent(Palette::named('dark'));
        $lines = implode("\n", $hud->render(80));

        $this->assertStringContainsString('⌘ + Enter', $lines);
        $this->assertStringContainsString('Shift + Enter', $lines);
        $this->assertStringContainsString('Ctrl + C', $lines);
        $this->assertStringContainsString('Ctrl + G', $lines);
        $this->assertStringContainsString('Shortcuts (Release ⌘ to close)', $lines);
    }

    public function testIsSoloCommandStrictModifierValidation(): void
    {
        $commandOnly = 0x00100000;
        $this->assertTrue(\Pig\CodingAgent\Interactive\InteractiveMode::isSoloCommand($commandOnly));

        // Shift + Command (e.g. Shift+Command+4 macOS screenshot) must be FALSE
        $shiftCommand = 0x00100000 | 0x00020000;
        $this->assertFalse(\Pig\CodingAgent\Interactive\InteractiveMode::isSoloCommand($shiftCommand));

        // Control + Command must be FALSE
        $ctrlCommand = 0x00100000 | 0x00040000;
        $this->assertFalse(\Pig\CodingAgent\Interactive\InteractiveMode::isSoloCommand($ctrlCommand));

        // Option + Command must be FALSE
        $optCommand = 0x00100000 | 0x00080000;
        $this->assertFalse(\Pig\CodingAgent\Interactive\InteractiveMode::isSoloCommand($optCommand));

        // Shift + Option + Command must be FALSE
        $allThree = 0x00100000 | 0x00020000 | 0x00080000;
        $this->assertFalse(\Pig\CodingAgent\Interactive\InteractiveMode::isSoloCommand($allThree));

        // No modifier at all must be FALSE
        $this->assertFalse(\Pig\CodingAgent\Interactive\InteractiveMode::isSoloCommand(0));
    }
}
