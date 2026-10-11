<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;
use Pig\CodingAgent\Keybindings;
use Pig\Tui\Keys;

final class KeybindingsTest extends TestCase
{
    private string $home;

    #[\Override]
    protected function setUp(): void
    {
        $this->home = sys_get_temp_dir() . '/pig-keys-' . bin2hex(random_bytes(4));
        mkdir($this->home, 0o700, true);
    }

    #[\Override]
    protected function tearDown(): void
    {
        if (is_file($this->home . '/keybindings.json')) {
            unlink($this->home . '/keybindings.json');
        }

        rmdir($this->home);
    }

    public function testWithNoFileTheDefaultsAreUpstreams(): void
    {
        $keys = Keybindings::load($this->home);

        $this->assertSame([], $keys->problems());
        $this->assertSame('app.model.select', $keys->actionFor("\x0c"), 'ctrl+l as the control character');
        $this->assertSame('app.model.select', $keys->actionFor(Keys::kitty(ord('l'), 4)), 'and as kitty reports it');
        $this->assertSame('app.model.cycleBackward', $keys->actionFor(Keys::kitty(ord('p'), 5)));
        $this->assertSame('app.thinking.cycle', $keys->actionFor("\e[Z"));
        $this->assertSame('app.interrupt', $keys->actionFor("\e"));
        $this->assertNull($keys->actionFor('a'), 'typing is typing');
        $this->assertSame('ctrl+l', $keys->label('app.model.select'));
        $this->assertSame('esc', $keys->label('app.interrupt'));
        // `\e\r` is not alt+enter: it is Apple Terminal's Shift+Enter, and it goes to the
        // editor as a new line rather than being claimed here.
        $this->assertNull($keys->actionFor("\e\r"), "Apple Terminal's Shift+Enter is nobody's action");
        $this->assertSame('app.message.followUp', $keys->actionFor(Keys::kitty(13, 2)), 'and as kitty reports it');
        $this->assertSame('app.message.dequeue', $keys->actionFor("\e[1;3A"));

        $custom = new Keybindings(['app.message.followUp' => ['command+enter']]);
        // Kitty protocol Super+Enter is \x1b[13;9u (modifier 8 + 1 = 9)
        $this->assertSame('app.message.followUp', $custom->actionFor("\x1b[13;9u"));
    }

    public function testAHintSaysTheKeyTheWayItIsPrintedOnIt(): void
    {
        // Upstream's `keyDisplayText()`: capitalised, and `alt` is `option` on a Mac — a hint
        // reading `Alt+Up` on a keyboard that has no key called that sends somebody looking.
        $keys = Keybindings::defaults();
        $alt = PHP_OS_FAMILY === 'Darwin' ? 'Option' : 'Alt';

        $this->assertSame("{$alt}+Up", $keys->display('app.message.dequeue'));
        $this->assertSame('Command+Enter', $keys->display('app.message.followUp'));
        $this->assertSame('Ctrl+O', $keys->display('app.tools.expand'));
        $this->assertSame('(unbound)', (new Keybindings(['app.tools.expand' => []]))->display('app.tools.expand'));
    }

    public function testABindingReplacesTheDefaultRatherThanAddingToIt(): void
    {
        file_put_contents($this->home . '/keybindings.json', json_encode([
            'app.model.select' => 'ctrl+m',
            'app.tools.expand' => ['ctrl+e', 'Ctrl+Shift+O'],
        ]));

        $keys = Keybindings::load($this->home);

        $this->assertSame([], $keys->problems());
        // Kitty's spelling, because ctrl+m as a control character is `\r` — Enter — which is why
        // nobody binds it on a terminal without the protocol and why this test does not try to.
        $this->assertSame('app.model.select', $keys->actionFor(Keys::kitty(ord('m'), 4)));
        $this->assertFalse($keys->matches("\x0c", 'app.model.select'), 'ctrl+l is free again — that is the point of moving it');
        $this->assertSame('app.tools.expand', $keys->actionFor("\x05"));
        $this->assertSame('app.tools.expand', $keys->actionFor(Keys::kitty(ord('o'), 5)), 'the second key too, whatever its spelling');
        $this->assertSame(['ctrl+e', 'shift+ctrl+o'], $keys->keysFor('app.tools.expand'), 'spelled one way here');
        $this->assertSame('ctrl+m', $keys->label('app.model.select'));
    }

    public function testAnEmptyListUnbindsAnAction(): void
    {
        file_put_contents($this->home . '/keybindings.json', '{"app.suspend": []}');

        $keys = Keybindings::load($this->home);

        $this->assertNull($keys->actionFor("\x1a"), 'ctrl+z reaches the terminal instead');
        $this->assertSame('(unbound)', $keys->label('app.suspend'));
    }

    public function testWhatCannotBeReadIsNamedAndTheRestStillBinds(): void
    {
        file_put_contents($this->home . '/keybindings.json', json_encode([
            'app.model.select' => 'ctrl+m',
            'app.nonsense' => 'ctrl+q',
            'app.tools.expand' => 'hyper+o',
            'app.thinking.toggle' => 42,
        ]));

        $keys = Keybindings::load($this->home);

        $problems = implode("\n", $keys->problems());
        $this->assertStringContainsString("'app.nonsense', which pig has no action for", $problems);
        $this->assertStringContainsString("'app.tools.expand' names a key this cannot read: \"hyper+o\"", $problems);
        $this->assertStringContainsString("'app.thinking.toggle' must be a key or a list of keys", $problems);

        $this->assertSame('app.model.select', $keys->actionFor(Keys::kitty(ord('m'), 4)), 'the good line still binds');
        $this->assertSame('app.tools.expand', $keys->actionFor("\x0f"), 'a bad key leaves the default standing');
        $this->assertSame('app.thinking.toggle', $keys->actionFor("\x14"));
    }

    public function testAFileThatIsNotJsonIsTheDefaultsWithAComplaint(): void
    {
        file_put_contents($this->home . '/keybindings.json', '{nope');

        $keys = Keybindings::load($this->home);

        $this->assertCount(1, $keys->problems());
        $this->assertStringContainsString('is not a JSON object', $keys->problems()[0]);
        $this->assertSame('app.model.select', $keys->actionFor("\x0c"));
    }

    public function testAltScreenKeysGoToTheTuiRegistryAndReplaceItsDefaults(): void
    {
        file_put_contents($this->home . '/keybindings.json', json_encode([
            'tui.altScreen.pageUp' => 'ctrl+u',
            'tui.altScreen.search' => [],
        ]));

        $keys = Keybindings::load($this->home);
        $tui = $keys->tuiKeybindings();

        $this->assertSame([], $keys->problems());
        $this->assertTrue($tui->matches("\x15", 'tui.altScreen.pageUp'));
        $this->assertFalse($tui->matches("\x1b[5~", 'tui.altScreen.pageUp'), 'a binding replaces the default');
        $this->assertSame([], $tui->getKeys('tui.altScreen.search'), 'an empty list unbinds');
        $this->assertSame(['ctrl+end'], $keys->keysFor('tui.altScreen.bottom'), 'the rest keep upstream\'s defaults');
        $this->assertSame($keys, $tui, "upstream's manager is one object: the TUI's registry is this manager");
    }

    public function testEveryUpstreamIdIsBindableAndAnUnknownOneIsNamed(): void
    {
        file_put_contents($this->home . '/keybindings.json', json_encode([
            '$schema' => 'https://example.invalid/keybindings.json',
            'tui.editor.cursorUp' => 'ctrl+k',
            'tui.select.cancel' => ['escape'],
            'app.session.tree' => 'ctrl+shift+t',
            'app.clipboard.pasteImage' => 'alt+v',
            'app.nonsense' => 'ctrl+q',
        ]));

        $keys = Keybindings::load($this->home);

        $this->assertCount(1, $keys->problems(), implode("\n", $keys->problems()));
        $this->assertStringContainsString("'app.nonsense'", $keys->problems()[0]);
        $this->assertSame(['ctrl+k'], $keys->keysFor('tui.editor.cursorUp'));
        $this->assertSame(['escape'], $keys->keysFor('tui.select.cancel'));
        $this->assertSame(['shift+ctrl+t'], $keys->keysFor('app.session.tree'));
        $this->assertTrue($keys->matches("\x1bv", 'app.clipboard.pasteImage'));
    }

    public function testTheIdSetIsUpstreams(): void
    {
        $ids = array_keys(Keybindings::keybindingDefinitions('linux', []));

        foreach ([
            'tui.editor.undo', 'tui.editor.yank', 'tui.editor.jumpForward', 'tui.input.submit', 'tui.select.pageDown',
            'tui.altScreen.search', 'app.clipboard.pasteImage', 'app.session.new', 'app.session.tree',
            'app.session.fork', 'app.session.resume', 'app.session.togglePath', 'app.session.deleteNoninvasive',
            'app.models.save', 'app.models.reorderDown', 'app.thinking.save', 'app.tree.foldOrUp',
            'app.tree.filter.cycleBackward', 'app.tree.toggleLabelTimestamp',
        ] as $id) {
            $this->assertContains($id, $ids);
        }

        $this->assertCount(count(\Pig\Tui\Keybindings::TUI_KEYBINDINGS) + count(Keybindings::APP_KEYBINDINGS), $ids);
    }

    public function testDefaultsFollowThePlatformAsUpstreamsDo(): void
    {
        $linux = new Keybindings(platform: 'linux', env: []);
        $this->assertSame(['ctrl+z'], $linux->keysFor('app.suspend'));
        $this->assertSame(['shift+ctrl+p'], $linux->keysFor('app.model.cycleBackward'));
        $this->assertSame(['command+enter', 'alt+enter'], $linux->keysFor('app.message.followUp'));
        $this->assertSame(['alt+up'], $linux->keysFor('app.message.dequeue'));
        $this->assertSame(['ctrl+v'], $linux->keysFor('app.clipboard.pasteImage'));
        $this->assertSame(['ctrl+-'], $linux->keysFor('tui.editor.undo'));
        $this->assertSame(['ctrl+shift+f'], $linux->keysFor('tui.altScreen.search'));
        $this->assertSame(['ctrl+shift+up', 'ctrl+up'], $linux->keysFor('tui.altScreen.previousPrompt'));
        $this->assertSame(['ctrl+left', 'alt+left'], $linux->keysFor('app.tree.foldOrUp'));

        $windows = new Keybindings(platform: 'win32', env: []);
        $this->assertSame([], $windows->keysFor('app.suspend'), 'no job control on native Windows');
        $this->assertSame(['alt+p'], $windows->keysFor('app.model.cycleBackward'));
        $this->assertSame(['ctrl+q'], $windows->keysFor('app.message.followUp'));
        $this->assertSame(['alt+q'], $windows->keysFor('app.message.dequeue'));
        $this->assertSame(['alt+v'], $windows->keysFor('app.clipboard.pasteImage'));
        $this->assertSame(['ctrl+z'], $windows->keysFor('tui.editor.undo'));
        $this->assertSame(['ctrl+f'], $windows->keysFor('tui.altScreen.search'));
        $this->assertSame(['ctrl+up'], $windows->keysFor('tui.altScreen.previousPrompt'));
        $this->assertSame(['ctrl+down'], $windows->keysFor('tui.altScreen.nextPrompt'));

        $wsl = new Keybindings(platform: 'linux', env: ['WSL_DISTRO_NAME' => 'Ubuntu']);
        $this->assertTrue(Keybindings::useWindowsKeybindings('linux', ['WSL_INTEROP' => '/run/WSL/1_interop']));
        $this->assertSame(['ctrl+z'], $wsl->keysFor('app.suspend'), 'WSL keeps ctrl+z and fg');
        $this->assertSame(['alt+z'], $wsl->keysFor('tui.editor.undo'));
        $this->assertSame(['ctrl+q'], $wsl->keysFor('app.message.followUp'));
        $this->assertSame(['ctrl+f'], $wsl->keysFor('tui.altScreen.search'));

        $mac = new Keybindings(platform: 'darwin', env: []);
        $this->assertSame(['alt+left', 'ctrl+left'], $mac->keysFor('app.tree.foldOrUp'));
        $this->assertSame(['alt+right', 'ctrl+right'], $mac->keysFor('app.tree.unfoldOrDown'));
        $this->assertFalse(Keybindings::useWindowsKeybindings('darwin', ['WSL_DISTRO_NAME' => 'x']));
    }

    public function testTheOldFlatNamesAreReadAsTheNewOnes(): void
    {
        file_put_contents($this->home . '/keybindings.json', json_encode([
            'selectModel' => 'ctrl+m',
            'undo' => 'ctrl+z',
            'expandTools' => 'ctrl+e',
            'app.tools.expand' => 'ctrl+y',
        ]));

        $keys = Keybindings::load($this->home);

        $this->assertSame([], $keys->problems());
        $this->assertSame(['ctrl+m'], $keys->keysFor('app.model.select'));
        $this->assertSame(['ctrl+z'], $keys->keysFor('tui.editor.undo'));
        $this->assertSame(['ctrl+y'], $keys->keysFor('app.tools.expand'), 'the namespaced spelling wins');
        $this->assertTrue(Keybindings::migrateKeybindingsConfig(['undo' => 'ctrl+z'])['migrated']);
    }

    public function testReloadReadsTheFileAgainIntoTheSameManager(): void
    {
        $keys = Keybindings::load($this->home);
        $this->assertSame(['ctrl+l'], $keys->keysFor('app.model.select'));

        file_put_contents($this->home . '/keybindings.json', json_encode(['app.model.select' => 'ctrl+m', 'app.bogus' => 'x']));
        $problems = $keys->reload();

        $this->assertSame(['ctrl+m'], $keys->keysFor('app.model.select'));
        $this->assertCount(1, $problems);
        $this->assertSame($problems, $keys->problems());

        unlink($this->home . '/keybindings.json');
        $this->assertSame([], $keys->reload());
        $this->assertSame(['ctrl+l'], $keys->keysFor('app.model.select'), 'a removed file is the defaults again');
    }
}
