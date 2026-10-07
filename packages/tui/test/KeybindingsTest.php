<?php

declare(strict_types=1);

namespace Pig\Tui\Test;

use PHPUnit\Framework\TestCase;
use Pig\Tui\KeybindingConflict;
use Pig\Tui\Keybindings;
use Pig\Tui\KeybindingsManager;

/** Upstream's `keybindings.test.ts`. */
final class KeybindingsTest extends TestCase
{
    private static function manager(array $userBindings = []): KeybindingsManager
    {
        return new KeybindingsManager(Keybindings::definitions(), $userBindings);
    }

    public function testBindsCtrlJAsADefaultNewlineAlias(): void
    {
        $keybindings = self::manager();

        $this->assertSame(['shift+enter', 'ctrl+j'], $keybindings->getKeys('tui.input.newLine'));
        $this->assertTrue($keybindings->matches("\n", 'tui.input.newLine'));
        $this->assertTrue($keybindings->matches("\x1b[106;5u", 'tui.input.newLine'));
    }

    public function testBindsModifiedAndUnmodifiedEditorViewportNavigation(): void
    {
        $keybindings = self::manager();

        $this->assertSame(['home', 'ctrl+a'], $keybindings->getKeys('tui.editor.cursorLineStart'));
        $this->assertSame(['end', 'ctrl+e'], $keybindings->getKeys('tui.editor.cursorLineEnd'));
        $this->assertSame(['pageUp', 'ctrl+pageUp'], $keybindings->getKeys('tui.editor.pageUp'));
        $this->assertSame(['pageDown', 'ctrl+pageDown'], $keybindings->getKeys('tui.editor.pageDown'));
    }

    public function testLeavesDedicatedPromptHistoryNavigationUnboundByDefault(): void
    {
        $keybindings = self::manager();

        $this->assertSame([], $keybindings->getKeys('tui.editor.historyPrevious'));
        $this->assertSame([], $keybindings->getKeys('tui.editor.historyNext'));
    }

    public function testBindsUnmodifiedTerminalViewportShortcutsToAlternateScreenNavigation(): void
    {
        $keybindings = self::manager();

        $this->assertSame(['pageUp'], $keybindings->getKeys('tui.altScreen.pageUp'));
        $this->assertSame(['pageDown'], $keybindings->getKeys('tui.altScreen.pageDown'));
        $this->assertSame([], $keybindings->getKeys('tui.altScreen.halfPageUp'));
        $this->assertSame([], $keybindings->getKeys('tui.altScreen.halfPageDown'));
        $this->assertSame([], $keybindings->getKeys('tui.altScreen.lineUp'));
        $this->assertSame([], $keybindings->getKeys('tui.altScreen.lineDown'));
        $this->assertSame(['ctrl+shift+up', 'ctrl+up'], $keybindings->getKeys('tui.altScreen.previousPrompt'));
        $this->assertSame(['ctrl+shift+down', 'ctrl+down'], $keybindings->getKeys('tui.altScreen.nextPrompt'));
        $this->assertSame(['ctrl+shift+f'], $keybindings->getKeys('tui.altScreen.search'));
        $this->assertSame(['enter', 'ctrl+g'], $keybindings->getKeys('tui.altScreen.searchNext'));
        $this->assertSame(['shift+enter', 'ctrl+shift+g'], $keybindings->getKeys('tui.altScreen.searchPrevious'));
        $this->assertSame(['escape'], $keybindings->getKeys('tui.altScreen.searchClose'));
        $this->assertSame(['ctrl+home'], $keybindings->getKeys('tui.altScreen.top'));
        $this->assertSame(['ctrl+end'], $keybindings->getKeys('tui.altScreen.bottom'));
    }

    public function testDoesNotEvictSelectorConfirmWhenInputSubmitIsRebound(): void
    {
        $keybindings = self::manager(['tui.input.submit' => ['enter', 'ctrl+enter']]);

        $this->assertSame(['enter', 'ctrl+enter'], $keybindings->getKeys('tui.input.submit'));
        $this->assertSame(['enter'], $keybindings->getKeys('tui.select.confirm'));
    }

    public function testDoesNotEvictCursorBindingsWhenAnotherActionReusesTheSameKey(): void
    {
        $keybindings = self::manager(['tui.select.up' => ['up', 'ctrl+p']]);

        $this->assertSame(['up', 'ctrl+p'], $keybindings->getKeys('tui.select.up'));
        $this->assertSame(['up'], $keybindings->getKeys('tui.editor.cursorUp'));
    }

    public function testStillReportsDirectUserBindingConflictsWithoutEvictingDefaults(): void
    {
        $keybindings = self::manager([
            'tui.input.submit' => 'ctrl+x',
            'tui.select.confirm' => 'ctrl+x',
        ]);

        $this->assertEquals(
            [new KeybindingConflict('ctrl+x', ['tui.input.submit', 'tui.select.confirm'])],
            $keybindings->getConflicts(),
        );
        $this->assertSame(['left', 'ctrl+b'], $keybindings->getKeys('tui.editor.cursorLeft'));
    }
}
