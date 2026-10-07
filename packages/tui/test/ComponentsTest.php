<?php

declare(strict_types=1);

namespace Pig\Tui\Test;

use PHPUnit\Framework\TestCase;
use Pig\Async\Loop;
use Pig\Tui\Components\Box;
use Pig\Tui\Components\CancellableLoader;
use Pig\Tui\Components\Loader;
use Pig\Tui\Components\SelectItem;
use Pig\Tui\Ansi;
use Pig\Tui\Components\SelectList;
use Pig\Tui\Components\Rule;
use Pig\Tui\Components\SelectListTheme;
use Pig\Tui\Components\Spacer;
use Pig\Tui\Components\Text;
use Pig\Tui\Components\TruncatedText;
use Pig\Tui\Keys;
use Pig\Tui\Style;
use Pig\Tui\Tui;
use Pig\Tui\Width;

final class ComponentsTest extends TestCase
{
    #[\Override]
    protected function setUp(): void
    {
        Loop::reset();
    }

    public function testARuleFillsTheWidthItIsGiven(): void
    {
        $lines = (new Rule(static fn (string $text): string => $text))->render(12);

        $this->assertSame([str_repeat('─', 12)], $lines);
    }

    public function testARuleIsStillOneRowAtZeroWidth(): void
    {
        // An empty array would make the component occupy no row while the frame above it counts
        // one, which is how a differential renderer starts drawing from the wrong line.
        $this->assertCount(1, (new Rule())->render(0));
    }

    public function testARuleIsMeasuredInColumnsNotBytes(): void
    {
        // `─` is three bytes and one column, so a `str_repeat` counted in bytes would make this
        // rule a third of the width it was asked for.
        $this->assertSame(20, Width::visible((new Rule())->render(20)[0]));
    }

    public function testTextPadsEveryLineToTheFullWidth(): void
    {
        $lines = (new Text('hello'))->render(20);

        foreach ($lines as $line) {
            // A line that is sometimes padded and sometimes not differs from itself, and
            // the renderer redraws it for nothing.
            $this->assertSame(20, Width::visible($line));
        }
    }

    public function testTextIsPaddedAboveAndBelow(): void
    {
        $lines = (new Text('hello', paddingX: 1, paddingY: 2))->render(20);

        $this->assertCount(5, $lines);
        $this->assertSame('', trim($lines[0]));
        $this->assertSame('hello', trim($lines[2]));
        $this->assertSame('', trim($lines[4]));
    }

    public function testEmptyTextDrawsNothingAtAll(): void
    {
        $this->assertSame([], (new Text(''))->render(20));
        $this->assertSame([], (new Text("  \n  "))->render(20));
    }

    public function testTextWrapsInsideItsPadding(): void
    {
        $lines = (new Text('aaaa bbbb cccc', paddingX: 3, paddingY: 0))->render(12);

        // Twelve columns less three each side leaves six for the text.
        $this->assertSame(['aaaa', 'bbbb', 'cccc'], array_map(trim(...), $lines));
    }

    public function testTextIsRenderedOnceForTheSameInput(): void
    {
        $text = new Text('hello');

        $this->assertSame($text->render(20), $text->render(20));
    }

    public function testTextRerendersAfterTheTextChanges(): void
    {
        $text = new Text('before', paddingY: 0);
        $text->render(20);
        $text->setText('after');

        $this->assertSame('after', trim($text->render(20)[0]));
    }

    public function testTextBackgroundCoversThePaddingToo(): void
    {
        $lines = (new Text('hi', paddingY: 0, background: static fn (string $s): string => "[{$s}]"))->render(10);

        $this->assertSame('[ hi       ]', $lines[0]);
    }

    public function testSpacerIsBlankLines(): void
    {
        $this->assertSame(['', '', ''], (new Spacer(3))->render(20));
        $this->assertSame([], (new Spacer(0))->render(20));
    }

    public function testTruncatedTextKeepsToOneLine(): void
    {
        $lines = (new TruncatedText("first\nsecond"))->render(20);

        // A component that promises one line and returns two moves everything below it.
        $this->assertCount(1, $lines);
        $this->assertSame('first', trim($lines[0]));
    }

    public function testTruncatedTextCutsToTheWidth(): void
    {
        $lines = (new TruncatedText(str_repeat('x', 50)))->render(20);

        $this->assertSame(20, Width::visible($lines[0]));
        $this->assertStringEndsWith('...', rtrim($lines[0]));
    }

    public function testBoxIndentsItsChildrenAndPaintsThePadding(): void
    {
        $box = new Box(paddingX: 2, paddingY: 1, background: static fn (string $s): string => "<{$s}>");
        $box->addChild(new Text('hi', paddingX: 0, paddingY: 0));

        $lines = $box->render(10);

        $this->assertCount(3, $lines);
        $this->assertSame('<          >', $lines[0]);
        $this->assertSame('<  hi      >', $lines[1]);
    }

    public function testAnEmptyBoxDrawsNothing(): void
    {
        $this->assertSame([], (new Box())->render(20));
        // A box whose only child draws nothing has no padding to draw either.
        $box = new Box();
        $box->addChild(new Text(''));
        $this->assertSame([], $box->render(20));
    }

    public function testBoxNoticesAChangedBackgroundWithoutBeingTold(): void
    {
        $colour = 'a';
        $box = new Box(paddingY: 0, background: static function (string $s) use (&$colour): string {
            return $colour . $s;
        });
        $box->addChild(new Text('hi', paddingX: 0, paddingY: 0));

        $before = $box->render(6);
        $colour = 'b';
        $after = $box->render(6);

        // The closure cannot be compared, so the cache samples what it does instead.
        $this->assertNotSame($before, $after);
    }

    public function testSelectListMarksTheSelectedRow(): void
    {
        $list = $this->list(['one', 'two', 'three']);

        $lines = $list->render(30);

        $this->assertStringContainsString('→ one', $lines[0]);
        $this->assertStringContainsString('  two', $lines[1]);
    }

    public function testArrowKeysMoveTheSelectionAndWrapAround(): void
    {
        $list = $this->list(['one', 'two', 'three']);

        $list->handleInput("\x1b[B");
        $this->assertSame('two', $list->selectedItem()?->value);

        $list->handleInput("\x1b[A");
        $list->handleInput("\x1b[A");
        // Up from the top is the fastest way to the last entry of a long list.
        $this->assertSame('three', $list->selectedItem()?->value);

        $list->handleInput("\x1b[B");
        $this->assertSame('one', $list->selectedItem()?->value);
    }

    public function testEnterReportsTheChoiceAndEscapeCancels(): void
    {
        $list = $this->list(['one', 'two']);
        $chosen = null;
        $cancelled = false;

        $list->setSelectHandler(static function (SelectItem $item) use (&$chosen): void {
            $chosen = $item->value;
        });
        $list->setCancelHandler(static function () use (&$cancelled): void {
            $cancelled = true;
        });

        $list->handleInput("\r");
        $list->handleInput("\x1b");

        $this->assertSame('one', $chosen);
        $this->assertTrue($cancelled);
    }

    public function testFilteringKeepsTheMatchesAndPutsTheSelectionOnTheBestOne(): void
    {
        $list = $this->list(['commit', 'compare', 'help']);
        $list->handleInput("\x1b[B");

        $list->setFilter('com');

        $this->assertSame('commit', $list->selectedItem()?->value);
        // The two matches, and the line saying what was typed.
        $this->assertCount(3, $list->render(30));
    }

    public function testAnEmptyFilterResultSaysSoInsteadOfDrawingNothing(): void
    {
        $list = $this->list(['one']);
        $list->setFilter('zzz');

        $this->assertSame(["\x1b[2m  /zzz\x1b[22m", "\x1b[2m  No matches\x1b[22m"], $list->render(30));
        $this->assertNull($list->selectedItem());
    }

    // ---- typing in a list ---------------------------------------------------------------

    public function testTypingFiltersTheListAndIsShownBackToYou(): void
    {
        $list = $this->list(['commit', 'compare', 'help']);

        foreach (['h', 'e', 'l'] as $key) {
            $list->handleInput($key);
        }

        $this->assertSame('hel', $list->filter());
        $this->assertSame('help', $list->selectedItem()?->value);
        $this->assertStringContainsString('/hel', $list->render(30)[0]);
    }

    public function testTheCharactersDoNotHaveToBeNextToEachOther(): void
    {
        // The whole difference from the prefix match this replaced: `cmt` finds `commit`, and
        // nothing about `commit` starts with `cmt`.
        $list = $this->list(['compare', 'commit', 'help']);
        $list->setFilter('cmt');

        $rows = $list->render(30);

        $this->assertCount(2, $rows);
        $this->assertStringContainsString('commit', $rows[1]);
    }

    public function testBackspaceTakesOffACharacterAndNotAByte(): void
    {
        $list = $this->list(['构建中', '构建完成', 'build']);
        $list->handleInput('构');
        $list->handleInput('建');
        $list->handleInput("\x7f");

        // One `substr()` here instead of `mb_substr()` and the query is two thirds of a
        // character — which matches nothing, and is not text the terminal can draw either.
        $this->assertSame('构', $list->filter());
        // The search line, then the two rows that still match.
        $this->assertCount(3, $list->render(30));
    }

    public function testBackspaceOnAnEmptyQueryIsNotAnError(): void
    {
        $list = $this->list(['one']);
        $list->handleInput("\x7f");

        $this->assertSame('', $list->filter());
        $this->assertSame('one', $list->selectedItem()?->value);
    }

    public function testEscapeStillCancelsRatherThanClearingWhatWasTyped(): void
    {
        // The way out of a picker must not depend on whether you typed in it.
        $cancelled = false;
        $list = $this->list(['one', 'two']);
        $list->setCancelHandler(static function () use (&$cancelled): void {
            $cancelled = true;
        });

        $list->handleInput('t');
        $list->handleInput("\x1b");

        $this->assertTrue($cancelled);
    }

    public function testKeysThatAreNotTextNeverLandInTheQuery(): void
    {
        $list = $this->list(['one', 'two']);

        // An arrow, a function key, a ctrl chord, and a bare tab: every one of these would be a
        // stray character in the query if "not handled above" were taken to mean "text".
        foreach (["\x1b[A", "\x1b[15~", "\x10", "\t", "\x01"] as $key) {
            $list->handleInput($key);
        }

        $this->assertSame('', $list->filter());
        // Two rows and no search line: nothing was typed, so nothing is shown as typed.
        $this->assertCount(2, $list->render(30));
    }

    public function testClearingTheQueryLeavesTheSelectionWhereItWas(): void
    {
        $list = $this->list(['one', 'two', 'three']);
        $list->handleInput("\x1b[B");
        $list->handleInput("\x1b[B");

        $this->assertSame('three', $list->selectedItem()?->value);

        $list->setFilter('');

        $this->assertSame('three', $list->selectedItem()?->value);
    }

    public function testARowCanSayWhatItIsSearchedByWhenItsLabelIsNotIt(): void
    {
        // The model picker's values are row numbers and its labels are ids; upstream ranks on a
        // line that puts the provider first, so the row has to carry its own haystack.
        // The two rows are deliberately the wrong way round: the one that *reads* like the query
        // is not the one that should win it. Fall back to label-and-description and the arrow
        // lands on the other row.
        $list = new SelectList(
            [
                new SelectItem('0', 'openai-gpt-5', 'a reseller', 'openrouter openrouter/openai-gpt-5'),
                new SelectItem('1', 'no words in common', 'nor here', 'openai openai/gpt-5 openai gpt-5'),
            ],
            theme: SelectListTheme::default(),
        );

        $list->setFilter('openai/gpt-5');

        $this->assertSame('1', $list->selectedItem()?->value);
    }

    public function testALongListScrollsAndSaysWhereItIs(): void
    {
        $list = new SelectList(
            array_map(static fn (int $n): SelectItem => new SelectItem("item{$n}"), range(1, 20)),
            maxVisible: 5,
            theme: SelectListTheme::default(),
        );

        $list->setSelectedIndex(10);
        $lines = $list->render(30);

        // Five rows plus the position line.
        $this->assertCount(6, $lines);
        $this->assertStringContainsString('(11/20)', $lines[5]);
    }

    public function testDescriptionsAreDroppedOnANarrowTerminal(): void
    {
        $list = new SelectList([new SelectItem('run', 'run', 'execute the thing')], theme: SelectListTheme::default());

        $this->assertStringNotContainsString('execute', $list->render(30)[0]);
        $this->assertStringContainsString('execute', $list->render(70)[0]);
    }

    public function testAWideLabelDoesNotPushTheDescriptionOffTheScreen(): void
    {
        $list = new SelectList(
            [new SelectItem('cjk', '中文标签中文标签', 'what it does')],
            theme: SelectListTheme::default(),
        );

        // Upstream measures the label with JavaScript's .length; here it is columns, so
        // eight CJK characters count as sixteen and the gap shrinks to match.
        $this->assertLessThanOrEqual(70, Width::visible($list->render(70)[0]));
    }

    public function testTheDescriptionColumnIsTheSameForACjkLabelAsForAnAsciiOne(): void
    {
        $list = new SelectList(
            [
                new SelectItem('ascii', 'review', 'what it does'),
                new SelectItem('cjk', '代码审查', 'what it does'),
            ],
            theme: SelectListTheme::default(),
        );

        $columns = [];

        foreach (array_slice($list->render(70), 0, 2) as $line) {
            $plain = Ansi::strip($line);
            $at = mb_strpos($plain, 'what it does', 0, 'UTF-8');

            $columns[] = Width::visible(mb_substr($plain, 0, (int) $at, 'UTF-8'));
        }

        // The case the width assertion above cannot see: measuring the label with `strlen()` makes
        // the gap *smaller*, so the row still fits and the description simply starts in the wrong
        // place. A test that only asks "did it overflow" holds a half-fix in place.
        $this->assertSame($columns[0], $columns[1]);
    }

    public function testTheLoaderAnimatesOnTheEventLoop(): void
    {
        $tui = new Tui(new FakeTerminal());
        $loader = new Loader($tui, Style::cyan(...), Style::dim(...), 'Thinking');

        $first = $loader->render(40);
        Loop::get()->tick();
        Loop::get()->tick();
        $second = $loader->render(40);

        $this->assertNotSame($first, $second);
        $this->assertStringContainsString('Thinking', implode('', $second));

        $loader->stop();
    }

    public function testAStoppedLoaderLetsTheLoopGoIdle(): void
    {
        $tui = new Tui(new FakeTerminal());
        $loader = new Loader($tui, Style::cyan(...), Style::dim(...));

        // A pending timer is work, so a loader nobody stopped never lets the program end.
        $this->assertFalse(Loop::get()->isIdle());

        $loader->stop();
        Loop::get()->tick();

        $this->assertTrue(Loop::get()->isIdle());
        $this->assertFalse($loader->isRunning());
    }

    public function testLoaderWithTimerFormatsElapsedDuration(): void
    {
        $tui = new Tui(new FakeTerminal());
        $loader = new Loader($tui, Style::cyan(...), Style::dim(...), 'Working... (esc to interrupt)');

        // Without timer
        $this->assertStringContainsString('Working... (esc to interrupt)', Ansi::strip($loader->inBorder()));
        $this->assertStringNotContainsString('·', Ansi::strip($loader->inBorder()));

        // Under 1s elapsed
        $now = microtime(true);
        $loader->withTimer(true, $now - 0.2);
        $this->assertStringContainsString('Working... (esc to interrupt)', Ansi::strip($loader->inBorder()));
        $this->assertStringNotContainsString('·', Ansi::strip($loader->inBorder()));

        // 16s elapsed
        $loader->withTimer(true, $now - 16.0);
        $this->assertStringContainsString('Working... · 16s (esc to interrupt)', Ansi::strip($loader->inBorder()));

        // 75s elapsed (1m15s)
        $loader->withTimer(true, $now - 75.0);
        $this->assertStringContainsString('Working... · 1m15s (esc to interrupt)', Ansi::strip($loader->inBorder()));

        // Message without parentheses
        $loader->setMessage('Thinking...');
        $loader->withTimer(true, $now - 5.0);
        $this->assertStringContainsString('Thinking... · 5s', Ansi::strip($loader->inBorder()));

        $loader->stop();
    }

    public function testEscapeAbortsACancellableLoader(): void
    {
        $tui = new Tui(new FakeTerminal());
        $loader = new CancellableLoader($tui, Style::cyan(...), Style::dim(...));
        $called = 0;
        $loader->setAbortHandler(static function () use (&$called): void {
            $called++;
        });

        $this->assertFalse($loader->signal()->aborted());

        $loader->handleInput("\x1b");
        // A second Escape must not fire the handler again.
        $loader->handleInput("\x1b");

        $this->assertTrue($loader->signal()->aborted());
        $this->assertSame(1, $called);

        $loader->dispose();
    }

    public function testOtherKeysDoNotAbortTheLoader(): void
    {
        $tui = new Tui(new FakeTerminal());
        $loader = new CancellableLoader($tui, Style::cyan(...), Style::dim(...));

        $loader->handleInput('x');
        $loader->handleInput(Keys::kitty(ord('c'), 4));

        $this->assertFalse($loader->aborted());

        $loader->dispose();
    }

    public function testEachStyleClosesOnlyWhatItOpened(): void
    {
        // Not `\e[0m`. A full reset closes whatever somebody else opened around this
        // text too, so a bold word inside a red line would leave the rest of that line
        // un-red — which is what the coding agent's nested palette colours do.
        $this->assertSame("\x1b[31mred\x1b[39m", Style::red('red'));
        $this->assertSame("\x1b[38;5;240mgrey\x1b[39m", Style::ansi256(240, 'grey'));
        $this->assertSame("\x1b[48;2;1;2;3mbg\x1b[49m", Style::onRgb(1, 2, 3, 'bg'));
        $this->assertSame("\x1b[1mbold\x1b[22m", Style::bold('bold'));
        $this->assertSame("\x1b[7minverse\x1b[27m", Style::inverse('inverse'));

        // The one exception: of() is handed arbitrary codes, so it cannot know which
        // off-code belongs to each of them.
        $this->assertSame("\x1b[1;31mboth\x1b[0m", Style::of(1, 31)('both'));

        // Styled text still measures as its visible content.
        $this->assertSame(3, Width::visible(Style::bold(Style::red('abc'))));
    }

    public function testAStyleNestedInAColourLeavesTheColourStanding(): void
    {
        $line = Style::red('a ' . Style::bold('b') . ' c');

        // The colour is opened once and closed once, at the end — nothing in between
        // cancels it.
        $this->assertSame("\x1b[31ma \x1b[1mb\x1b[22m c\x1b[39m", $line);
    }

    /** @param list<string> $values */
    private function list(array $values): SelectList
    {
        return new SelectList(
            array_map(static fn (string $value): SelectItem => new SelectItem($value), $values),
            theme: SelectListTheme::default(),
        );
    }
}
