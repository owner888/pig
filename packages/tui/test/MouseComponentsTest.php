<?php

declare(strict_types=1);

namespace Pig\Tui\Test;

use PHPUnit\Framework\TestCase;
use Pig\Async\Loop;
use Pig\Tui\Components\Editor;
use Pig\Tui\Components\EditorTheme;
use Pig\Tui\Components\Input;
use Pig\Tui\Components\SelectListTheme;
use Pig\Tui\Container;
use Pig\Tui\InputHandler;
use Pig\Tui\OverlayOptions;
use Pig\Tui\TuiAltScreen;
use Pig\Tui\TuiAltScreenOptions;
use Pig\Tui\TuiMouseEvent;

/**
 * Upstream's `mouse-components.test.ts`, the cases for what pig has ported.
 *
 * Not ported, because pig's `SelectList`, `SettingsList` and `Editor` take no mouse events yet
 * (no `handleMouse()`; upstream's sources for those were not part of this port):
 *
 * - "selects and activates list rows"
 * - "activates settings rows"
 * - "ignores hover and clicks visible select-list row 0/4 after scrolling" (two cases)
 * - "ignores hover and clicks visible settings row 0/4 after scrolling" (two cases)
 * - "keeps a settings list focused when a click in its submenu closes the submenu"
 * - "positions and focuses the multiline editor through alternate-screen dispatch"
 *
 * "selects and copies editor text on drag instead of moving the cursor" is ported: it holds in
 * pig today because the editor does not take the press, so the drag is the renderer's selection.
 */
final class MouseComponentsTest extends TestCase
{
    /** @var list<TuiAltScreen> */
    private array $started = [];

    #[\Override]
    protected function setUp(): void
    {
        Loop::reset();
    }

    #[\Override]
    protected function tearDown(): void
    {
        foreach ($this->started as $tui) {
            $tui->stop();
        }
    }

    /** @param 'press'|'release'|'move'|'drag'|'click'|'wheel' $type */
    private static function mouse(string $type, int $x, int $y, int $width = 80, int $height = 10): TuiMouseEvent
    {
        return new TuiMouseEvent($type, 'left', $x, $y, $x, $y, $width, $height, clickCount: $type === 'click' ? 1 : null);
    }

    private static function editorTheme(): EditorTheme
    {
        $identity = static fn (string $text): string => $text;

        return new EditorTheme($identity, new SelectListTheme($identity, $identity, $identity, $identity));
    }

    public function testPositionsASingleLineInputCursorOnPress(): void
    {
        $input = new Input();
        $input->setValue('hello');
        $input->render(20);

        $this->assertTrue($input->handleMouse(self::mouse('press', 4, 0, 20, 1))?->handled);
        $input->handleInput('X');
        $this->assertSame('heXllo', $input->value());
    }

    public function testKeepsADelegatingOverlayFocusedWhenItsNestedInputIsClicked(): void
    {
        $terminal = new VirtualTerminal(20, 4);
        $tui = new TuiAltScreen($terminal);
        $overlay = new class () extends Container implements InputHandler {
            public readonly Input $input;

            public function __construct()
            {
                $this->input = new Input();
                $this->addChild($this->input);
            }

            #[\Override]
            public function handleInput(string $data): void
            {
                $this->input->handleInput($data);
            }
        };
        $overlay->input->setValue('hi');
        $tui->start();
        $this->started[] = $tui;
        $tui->showOverlay($overlay, new OverlayOptions(width: 20, anchor: 'top-left'));
        $terminal->waitForRender();

        $terminal->sendInput("\x1b[<0;5;1M");
        $terminal->sendInput("\x1b[<0;5;1m");
        $terminal->sendInput('!');
        $terminal->waitForRender();

        $this->assertSame('hi!', $overlay->input->value());
        $this->assertSame($overlay, $tui->getFocusedComponent());
    }

    public function testSelectsAndCopiesEditorTextOnDragInsteadOfMovingTheCursor(): void
    {
        $terminal = new VirtualTerminal(20, 6);
        $copied = [];
        $tui = new TuiAltScreen($terminal, options: new TuiAltScreenOptions(copySelection: static function (string $text) use (&$copied): bool {
            $copied[] = $text;

            return true;
        }));
        $editor = new Editor(self::editorTheme());
        $editor->setText('hello world');
        $tui->addChild($editor);
        $tui->start();
        $this->started[] = $tui;
        $terminal->waitForRender();
        $cursorBefore = $editor->cursor();

        $terminal->sendInput("\x1b[<0;1;2M");
        $terminal->sendInput("\x1b[<32;5;2M");
        $terminal->sendInput("\x1b[<0;5;2m");
        $terminal->waitForRender();

        $this->assertSame(['hello'], $copied);
        $this->assertSame($cursorBefore, $editor->cursor());
    }
}
