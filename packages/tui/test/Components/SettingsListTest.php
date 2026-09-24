<?php

declare(strict_types=1);

namespace Pig\Tui\Test\Components;

use Closure;
use PHPUnit\Framework\TestCase;
use Pig\Tui\Component;
use Pig\Tui\Components\SelectItem;
use Pig\Tui\Components\SelectList;
use Pig\Tui\Components\SelectListTheme;
use Pig\Tui\Components\SettingItem;
use Pig\Tui\Components\SettingsList;
use Pig\Tui\Components\SettingsListTheme;
use Pig\Tui\Width;

/**
 * A list of settings, changed in place.
 *
 * The part worth testing is not the drawing — it is that Enter changes the row under the
 * cursor rather than closing the list, that Escape means different things inside a submenu
 * and outside one, and that the list and the caller never disagree about what a row says.
 */
final class SettingsListTest extends TestCase
{
    /** A theme that adds nothing, so a test can assert on text rather than on escapes. */
    private static function plain(): SettingsListTheme
    {
        return new SettingsListTheme(
            label: static fn (string $text, bool $selected): string => $text,
            value: static fn (string $text, bool $selected): string => $text,
            description: static fn (string $text): string => $text,
            hint: static fn (string $text): string => $text,
        );
    }

    /** @param list<SettingItem> $items */
    private function list(array $items, int $maxVisible = 10): SettingsList
    {
        return new SettingsList($items, $maxVisible, self::plain());
    }

    /**
     * Which screen column something starts in.
     *
     * Not `strpos`, which counts bytes: the cursor is `→ `, three bytes for one column, so
     * a selected row and an unselected one would never agree about any offset. Columns are
     * what "line up" means on a terminal.
     */
    private static function column(string $line, string $needle): int
    {
        $at = mb_strpos($line, $needle, 0, 'UTF-8');

        self::assertNotSame(false, $at, "'{$needle}' is not in '{$line}'");

        return Width::visible(mb_substr($line, 0, (int) $at, 'UTF-8'));
    }

    /** @return list<SettingItem> */
    private static function two(): array
    {
        return [
            new SettingItem('theme', 'Theme', 'dark', 'Which colours', values: ['dark', 'light']),
            new SettingItem('retry', 'Auto-retry', 'on', values: ['on', 'off']),
        ];
    }

    // ---- what it draws --------------------------------------------------------------------

    public function testEachRowShowsItsNameAndWhatItIsSetTo(): void
    {
        $lines = $this->list(self::two())->render(60);

        $this->assertStringContainsString('Theme', $lines[0]);
        $this->assertStringContainsString('dark', $lines[0]);
        $this->assertStringContainsString('Auto-retry', $lines[1]);
        $this->assertStringContainsString('on', $lines[1]);
    }

    public function testTheValuesLineUpEvenWhenTheNamesDoNot(): void
    {
        $lines = $this->list(self::two())->render(60);

        // Both values start in the same column, which is the whole reason the label width
        // is computed across every item rather than per row.
        $this->assertSame(self::column($lines[0], 'dark'), self::column($lines[1], 'on'));
    }

    public function testACjkLabelIsMeasuredInColumnsNotCharacters(): void
    {
        $lines = $this->list([
            new SettingItem('a', '主题', 'dark', values: ['dark']),
            new SettingItem('b', 'Auto-retry', 'on', values: ['on']),
        ])->render(60);

        // Upstream measures with `.length`, where 主题 is 2 and `Auto-retry` is 10, so the
        // two rows agree. Here it is 4 columns wide, and measuring it as 2 would put the
        // second row's value two columns left of the first's.
        $this->assertSame(self::column($lines[0], 'dark'), self::column($lines[1], 'on'));
    }

    public function testOnlyTheSelectedRowsDescriptionIsShown(): void
    {
        $list = $this->list(self::two());

        $this->assertStringContainsString('Which colours', implode("\n", $list->render(60)));

        $list->handleInput("\x1b[B");

        // The second row has none, so nothing takes its place — no blank explanation line
        // that makes the screen jump by one row as the cursor moves.
        $this->assertStringNotContainsString('Which colours', implode("\n", $list->render(60)));
    }

    public function testTheCursorIsOnExactlyOneRow(): void
    {
        $lines = $this->list(self::two())->render(60);

        $this->assertStringStartsWith('→ ', $lines[0]);
        $this->assertStringStartsWith('  ', $lines[1]);
        // And the two rows still start in the same column: an unselected row is indented
        // by the cursor's width rather than not indented.
        $this->assertSame(self::column($lines[0], 'Theme'), self::column($lines[1], 'Auto-retry'));
    }

    public function testALongListScrollsAndSaysWhereItIs(): void
    {
        $items = [];

        for ($at = 0; $at < 12; $at++) {
            $items[] = new SettingItem("s{$at}", "Setting {$at}", 'on', values: ['on', 'off']);
        }

        $rendered = implode("\n", $this->list($items, 4)->render(60));

        $this->assertStringContainsString('(1/12)', $rendered);
    }

    public function testAnEmptyListSaysSoRatherThanDrawingNothing(): void
    {
        $this->assertStringContainsString('Nothing to set', implode("\n", $this->list([])->render(60)));
    }

    public function testEveryLineFitsTheWidth(): void
    {
        $list = $this->list([new SettingItem(
            'long',
            str_repeat('name ', 20),
            str_repeat('value ', 20),
            str_repeat('why ', 40),
            values: ['a'],
        )]);

        foreach ($list->render(40) as $line) {
            $this->assertLessThanOrEqual(40, Width::visible($line));
        }
    }

    // ---- changing one --------------------------------------------------------------------

    public function testEnterCyclesTheRowAndSaysWhatItBecame(): void
    {
        $seen = [];
        $list = $this->list(self::two());
        $list->setChangeHandler(static function (string $id, string $value) use (&$seen): void {
            $seen[] = "{$id}={$value}";
        });

        $list->handleInput("\r");
        $list->handleInput("\r");

        $this->assertSame(['theme=light', 'theme=dark'], $seen, 'and it wraps');
        $this->assertSame('dark', $list->valueOf('theme'));
    }

    public function testSpaceDoesTheSameThing(): void
    {
        $list = $this->list(self::two());
        $list->handleInput(' ');

        // A row with two values reads like a checkbox, and a checkbox is toggled with the
        // space bar. Upstream accepts both for the same reason.
        $this->assertSame('light', $list->valueOf('theme'));
    }

    public function testTheRowRedrawsWithItsNewValue(): void
    {
        $list = $this->list(self::two());
        $list->handleInput("\r");

        // The point of this component: the screen keeps saying what each setting is now.
        $this->assertStringContainsString('light', $list->render(60)[0]);
    }

    public function testEnterDoesNotCloseTheList(): void
    {
        $closed = false;
        $list = $this->list(self::two());
        $list->setCloseHandler(static function () use (&$closed): void {
            $closed = true;
        });

        $list->handleInput("\r");

        // The difference from a `SelectList`, where Enter is the answer. Several settings
        // are changed in one visit, so Enter cannot also mean "done".
        $this->assertFalse($closed);
    }

    public function testEscapeCloses(): void
    {
        $closed = 0;
        $list = $this->list(self::two());
        $list->setCloseHandler(static function () use (&$closed): void {
            $closed++;
        });

        $list->handleInput("\x1b");
        $list->handleInput("\x03");

        $this->assertSame(2, $closed);
    }

    public function testARowWithNothingToCycleThroughIsJustDrawn(): void
    {
        $changed = false;
        $list = $this->list([new SettingItem('note', 'Read-only', 'whatever')]);
        $list->setChangeHandler(static function () use (&$changed): void {
            $changed = true;
        });

        $list->handleInput("\r");

        $this->assertFalse($changed);
    }

    public function testAValueFromOutsideTheCycleStartsItOverRatherThanGettingStuck(): void
    {
        $list = $this->list([new SettingItem('theme', 'Theme', 'solarized', values: ['dark', 'light'])]);
        $list->handleInput("\r");

        // A settings file holding a theme this build does not have is still changeable.
        // `array_search` returning false read as index 0 would have gone to `light`.
        $this->assertSame('dark', $list->valueOf('theme'));
    }

    public function testSomethingElseCanCorrectARowWithoutAnnouncingIt(): void
    {
        $announced = false;
        $list = $this->list(self::two());
        $list->setChangeHandler(static function () use (&$announced): void {
            $announced = true;
        });

        $list->setValue('theme', 'light');

        // For a setting that moves on its own — the thinking level, when a model that
        // cannot reason is chosen. Telling the caller would be telling it what it just did.
        $this->assertSame('light', $list->valueOf('theme'));
        $this->assertFalse($announced);
        $this->assertNull($list->valueOf('nothing'), 'an id no row has');
    }

    // ---- the arrow keys -------------------------------------------------------------------

    public function testTheSelectionWrapsAtBothEnds(): void
    {
        $list = $this->list(self::two());

        $list->handleInput("\x1b[A");
        $list->handleInput("\r");

        $this->assertSame('off', $list->valueOf('retry'), 'Up from the first row is the last');

        $list->handleInput("\x1b[B");
        $list->handleInput("\r");

        $this->assertSame('light', $list->valueOf('theme'));
    }

    // ---- submenus ------------------------------------------------------------------------

    public function testASubmenuTakesTheScreenAndThenTheValue(): void
    {
        $list = $this->list([$this->levels()]);

        $list->handleInput("\r");

        // The list is gone while the submenu is up, rather than drawn behind it.
        $rendered = implode("\n", $list->render(60));
        $this->assertStringContainsString('medium', $rendered);
        $this->assertStringNotContainsString('Thinking', $rendered);

        $list->handleInput("\x1b[B");
        $list->handleInput("\r");

        $this->assertSame('high', $list->valueOf('thinking'));
        $this->assertStringContainsString('Thinking', implode("\n", $list->render(60)));
    }

    public function testEscapeInsideASubmenuLeavesTheSubmenuAndNotTheScreen(): void
    {
        $closed = false;
        $list = $this->list([$this->levels()]);
        $list->setCloseHandler(static function () use (&$closed): void {
            $closed = true;
        });

        $list->handleInput("\r");
        $list->handleInput("\x1b");

        // One press doing two things is the bug this guards: Escape handed to the list as
        // well as to the submenu would shut the whole screen from inside a submenu.
        $this->assertFalse($closed);
        $this->assertSame('medium', $list->valueOf('thinking'), 'unchanged');
        $this->assertStringContainsString('Thinking', implode("\n", $list->render(60)));

        $list->handleInput("\x1b");

        $this->assertTrue($closed, 'and the next one does close it');
    }

    public function testASubmenuComesBackToTheRowThatOpenedIt(): void
    {
        $list = $this->list([
            new SettingItem('first', 'First', 'on', values: ['on', 'off']),
            $this->levels(),
        ]);

        $list->handleInput("\x1b[B");
        $list->handleInput("\r");
        // The submenu's own cursor moves; the list's must not.
        $list->handleInput("\x1b[B");
        $list->handleInput("\r");
        $list->handleInput("\r");

        $this->assertStringContainsString('medium', implode("\n", $list->render(60)), 'the submenu again');
        $this->assertSame('on', $list->valueOf('first'), 'and not the row above it');
    }

    public function testASubmenuThatCannotTakeKeysIsDrawnRatherThanCrashedOn(): void
    {
        $list = $this->list([new SettingItem(
            'x',
            'X',
            'on',
            submenu: static fn (string $current, Closure $done): Component => new class () implements Component {
                #[\Override]
                public function invalidate(): void
                {
                }

                #[\Override]
                public function render(int $width): array
                {
                    return ['nothing to press'];
                }
            },
        )]);

        $list->handleInput("\r");
        $list->handleInput("\x1b[B");

        // A component with no `handleInput` is a screen with nothing to press, not a crash.
        // The mistake this pairs with is the other way round: a `Container` *is* a
        // `Component`, so a submenu built from one compiles, draws, and silently eats every
        // key. `SettingsSubmenu` is what the settings screen uses instead.
        $this->assertSame(['nothing to press'], $list->render(60));
    }

    /** A row whose options are a list, because there are more than two and each needs saying. */
    private function levels(): SettingItem
    {
        return new SettingItem(
            'thinking',
            'Thinking',
            'medium',
            submenu: function (string $current, Closure $done): Component {
                $items = [];

                foreach (['medium', 'high'] as $level) {
                    $items[] = new SelectItem($level, $level);
                }

                $inner = new SelectList($items, 2, SelectListTheme::default());
                $inner->setSelectedIndex((int) array_search($current, ['medium', 'high'], true));
                $inner->setSelectHandler(static function (SelectItem $item) use ($done): void {
                    $done($item->value);
                });
                $inner->setCancelHandler(static function () use ($done): void {
                    $done(null);
                });

                return $inner;
            },
        );
    }
}
