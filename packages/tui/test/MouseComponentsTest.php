<?php

declare(strict_types=1);

namespace Pig\Tui\Test;

use PHPUnit\Framework\TestCase;
use Pig\Async\Loop;
use Pig\Tui\Components\Editor;
use Pig\Tui\Components\EditorTheme;
use Pig\Tui\Components\Input;
use Pig\Tui\Components\SelectItem;
use Pig\Tui\Components\SelectList;
use Pig\Tui\Components\SelectListTheme;
use Pig\Tui\Components\SettingItem;
use Pig\Tui\Components\SettingsList;
use Pig\Tui\Components\SettingsListTheme;
use Pig\Tui\Component;
use Pig\Tui\Container;
use Pig\Tui\InputHandler;
use Pig\Tui\OverlayOptions;
use Pig\Tui\TuiAltScreen;
use Pig\Tui\TuiAltScreenOptions;
use Pig\Tui\TuiMouseEvent;

/**
 * Upstream's `mouse-components.test.ts`, every case.
 *
 * The settings-list scroll cases run without upstream's `{ enableSearch: true }`, because pig's
 * `SettingsList` has no search: the rows start at line 0 rather than below the search input and
 * its blank line, so the `+ 2` on each row and the `before[4]` are `+ 0` and `before[2]` here.
 *
 * "positions the cursor on a wrapped editor row" is pig's own, for the visual-line mapping
 * upstream's cases do not reach.
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

    /**
     * @param 'press'|'release'|'move'|'drag'|'click'|'wheel' $type
     * @param 'left'|'middle'|'right'|'none' $button
     */
    private static function mouse(string $type, int $x, int $y, int $width = 80, int $height = 10, string $button = 'left', ?int $wheelDelta = null): TuiMouseEvent
    {
        return new TuiMouseEvent($type, $button, $x, $y, $x, $y, $width, $height, wheelDelta: $wheelDelta, clickCount: $type === 'click' ? 1 : null);
    }

    private static function selectTheme(): SelectListTheme
    {
        $identity = static fn (string $text): string => $text;

        return new SelectListTheme($identity, $identity, $identity, $identity);
    }

    private static function settingsTheme(): SettingsListTheme
    {
        $identity = static fn (string $text): string => $text;

        return new SettingsListTheme(
            static fn (string $text, bool $selected): string => $text,
            static fn (string $text, bool $selected): string => $text,
            $identity,
            $identity,
            '> ',
        );
    }

    private static function editorTheme(): EditorTheme
    {
        return new EditorTheme(static fn (string $text): string => $text, self::selectTheme());
    }

    /** @param list<array{id: string, value: string}> $changes */
    private static function settingsList(array $items, int $maxVisible, array &$changes): SettingsList
    {
        $list = new SettingsList($items, $maxVisible, self::settingsTheme());
        $list->setChangeHandler(static function (string $id, string $value) use (&$changes): void {
            $changes[] = ['id' => $id, 'value' => $value];
        });

        return $list;
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

    public function testSelectsAndActivatesListRows(): void
    {
        $list = new SelectList(
            [new SelectItem('a', 'A'), new SelectItem('b', 'B'), new SelectItem('c', 'C'), new SelectItem('d', 'D'), new SelectItem('e', 'E')],
            3,
            self::selectTheme(),
        );
        $selected = null;
        $list->setSelectHandler(static function (SelectItem $item) use (&$selected): void {
            $selected = $item->value;
        });

        $this->assertTrue($list->handleMouse(self::mouse('press', 1, 2, 40, 3))?->handled);
        $this->assertSame('c', $list->selectedItem()?->value);
        $this->assertTrue($list->handleMouse(self::mouse('click', 1, 2, 40, 3))?->handled);
        $this->assertSame('c', $selected);
    }

    public function testActivatesSettingsRows(): void
    {
        $changes = [];
        $list = self::settingsList(
            [
                new SettingItem('mode', 'Mode', 'one', values: ['one', 'two']),
                new SettingItem('other', 'Other', 'off', values: ['off', 'on']),
                new SettingItem('third', 'Third', 'low', values: ['low', 'high']),
                new SettingItem('fourth', 'Fourth', 'x', values: ['x', 'y']),
            ],
            3,
            $changes,
        );

        $list->handleMouse(self::mouse('press', 1, 2, 40, 5));
        $list->handleMouse(self::mouse('click', 1, 2, 40, 5));
        $this->assertSame([['id' => 'third', 'value' => 'high']], $changes);
    }

    /** @return iterable<string, array{int}> */
    public static function scrolledRows(): iterable
    {
        yield 'row 0' => [0];
        yield 'row 4' => [4];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('scrolledRows')]
    public function testIgnoresHoverAndClicksVisibleSelectListRowAfterScrolling(int $row): void
    {
        $list = new SelectList(
            array_map(static fn (int $i): SelectItem => new SelectItem("item-{$i}", "Item {$i}"), range(0, 11)),
            5,
            self::selectTheme(),
        );
        $changes = [];
        $selected = null;
        $list->setSelectionChangeHandler(static function (SelectItem $item) use (&$changes): void {
            $changes[] = $item->value;
        });
        $list->setSelectHandler(static function (SelectItem $item) use (&$selected): void {
            $selected = $item->value;
        });
        $list->setSelectedIndex(5);
        $list->handleMouse(self::mouse('wheel', 1, $row, wheelDelta: 1));
        $this->assertSame('item-6', $list->selectedItem()?->value);
        $this->assertSame(['item-6'], $changes);
        $before = $list->render(80);
        $this->assertMatchesRegularExpression('/Item ' . (4 + $row) . '$/', $before[$row]);

        foreach ([0, 1, 2, 3, 4, $row] as $y) {
            $this->assertNull($list->handleMouse(self::mouse('move', 1, $y, button: 'none')));
            $this->assertSame($before, $list->render(80));
        }
        $this->assertSame('item-6', $list->selectedItem()?->value);
        $this->assertSame(['item-6'], $changes);
        $this->assertNull($selected);

        $list->handleMouse(self::mouse('press', 1, $row));
        $list->render(80);
        $list->handleMouse(self::mouse('click', 1, $row));
        $this->assertSame('item-' . (4 + $row), $selected);
        $this->assertSame(['item-6', 'item-' . (4 + $row)], $changes);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('scrolledRows')]
    public function testIgnoresHoverAndClicksVisibleSettingsRowAfterScrolling(int $row): void
    {
        $changes = [];
        $list = self::settingsList(
            array_map(
                static fn (int $i): SettingItem => new SettingItem("item-{$i}", "Item {$i}", 'off', "Description {$i}", ['off', 'on']),
                range(0, 11),
            ),
            5,
            $changes,
        );
        $list->selectItem('item-5');
        $list->handleMouse(self::mouse('wheel', 1, $row, wheelDelta: 1));
        $before = $list->render(80);
        $this->assertMatchesRegularExpression('/^> Item 6/', $before[2]);
        $this->assertMatchesRegularExpression('/Item ' . (4 + $row) . ' /', $before[$row]);

        foreach ([0, 1, 2, 3, 4, $row] as $y) {
            $this->assertNull($list->handleMouse(self::mouse('move', 1, $y, button: 'none')));
            $this->assertSame($before, $list->render(80));
        }
        $this->assertSame([], $changes);

        $list->handleMouse(self::mouse('press', 1, $row));
        $list->render(80);
        $list->handleMouse(self::mouse('click', 1, $row));
        $this->assertSame([['id' => 'item-' . (4 + $row), 'value' => 'on']], $changes);
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

    public function testKeepsASettingsListFocusedWhenAClickInItsSubmenuClosesTheSubmenu(): void
    {
        $terminal = new VirtualTerminal(30, 6);
        $tui = new TuiAltScreen($terminal);
        $changes = [];
        $selectTheme = self::selectTheme();
        $list = self::settingsList(
            [
                // A settings submenu that routes keys to its nested list, like the coding agent's theme submenu.
                new SettingItem('theme', 'Theme', 'first', submenu: static fn (string $value, \Closure $done): Component => new class ($done, $selectTheme) extends Container implements InputHandler {
                    public readonly SelectList $list;

                    public function __construct(\Closure $done, SelectListTheme $theme)
                    {
                        $this->list = new SelectList([new SelectItem('first', 'First'), new SelectItem('second', 'Second')], 5, $theme);
                        $this->list->setSelectHandler(static fn (SelectItem $item) => $done($item->value));
                        $this->list->setCancelHandler(static fn () => $done(null));
                        $this->addChild($this->list);
                    }

                    #[\Override]
                    public function handleInput(string $data): void
                    {
                        $this->list->handleInput($data);
                    }
                }),
                new SettingItem('other', 'Other', 'off', values: ['off', 'on']),
            ],
            5,
            $changes,
        );
        $tui->addChild($list);
        $tui->setFocus($list);
        $tui->start();
        $this->started[] = $tui;
        $terminal->waitForRender();

        $terminal->sendInput("\r");
        $terminal->waitForRender();
        // Press and release on the submenu's second row selects it and closes the submenu.
        $terminal->sendInput("\x1b[<0;3;2M");
        $terminal->sendInput("\x1b[<0;3;2m");
        $terminal->waitForRender();
        $this->assertSame([['id' => 'theme', 'value' => 'second']], $changes);
        $this->assertSame($list, $tui->getFocusedComponent());

        // Keys reach the visible list again instead of the closed submenu.
        $terminal->sendInput("\x1b[B");
        $terminal->sendInput("\r");
        $terminal->waitForRender();
        $this->assertSame([['id' => 'theme', 'value' => 'second'], ['id' => 'other', 'value' => 'on']], $changes);
    }

    public function testPositionsAndFocusesTheMultilineEditorThroughAlternateScreenDispatch(): void
    {
        $terminal = new VirtualTerminal(20, 6);
        $tui = new TuiAltScreen($terminal);
        $editor = new Editor(self::editorTheme());
        $editor->setText('hello');
        $tui->addChild($editor);
        $tui->start();
        $this->started[] = $tui;
        $terminal->waitForRender();

        $terminal->sendInput("\x1b[<0;3;2M");
        $terminal->sendInput("\x1b[<0;3;2m");
        $terminal->sendInput('X');
        $terminal->waitForRender();

        $this->assertSame('heXllo', $editor->text());
        $this->assertSame($editor, $tui->getFocusedComponent());
    }

    public function testPositionsTheCursorOnAWrappedEditorRow(): void
    {
        $editor = new Editor(self::editorTheme());
        $editor->setText('abcdefghij');
        // Rows of four: border, `abcd`, `efgh`, `ij`, border.
        $editor->render(4);

        // The second drawn row is bytes 4..8 of the one logical line.
        $this->assertTrue($editor->handleMouse(self::mouse('click', 3, 2, 4, 5))?->focus);
        $this->assertSame(['line' => 0, 'col' => 7], $editor->cursor());
        // Past the end of the last row lands after it.
        $editor->handleMouse(self::mouse('click', 3, 3, 4, 5));
        $this->assertSame(['line' => 0, 'col' => 10], $editor->cursor());
    }
}
