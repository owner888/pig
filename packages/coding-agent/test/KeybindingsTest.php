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
        $this->assertSame("Command+Enter", $keys->display('app.message.followUp'));
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
        $this->assertNull($keys->actionFor("\x0c"), 'ctrl+l is free again — that is the point of moving it');
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
        $this->assertNull($keys->actionFor("\x15"), 'the app half does not claim a tui key');
    }

    public function testTheEditingKeysAreNotBindableYet(): void
    {
        file_put_contents($this->home . '/keybindings.json', json_encode(['tui.editor.cursorUp' => 'ctrl+k']));

        $keys = Keybindings::load($this->home);

        $this->assertCount(1, $keys->problems());
        $this->assertStringContainsString("'tui.editor.cursorUp'", $keys->problems()[0]);
    }
}
