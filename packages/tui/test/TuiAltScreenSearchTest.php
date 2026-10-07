<?php

declare(strict_types=1);

namespace Pig\Tui\Test;

use PHPUnit\Framework\TestCase;
use Pig\Async\Loop;
use Pig\Tui\AltScreenSearch;
use Pig\Tui\AltScreenSearchIndex;
use Pig\Tui\AltScreenSearchMatch;
use Pig\Tui\AltScreenSearchSegment;
use Pig\Tui\Ansi;
use Pig\Tui\Components\AltScreenSearchComponent;
use Pig\Tui\Components\ScrollView;
use Pig\Tui\Components\StackEntry;
use Pig\Tui\Components\Text;
use Pig\Tui\Components\VStack;
use Pig\Tui\Keybindings;
use Pig\Tui\TuiAltScreen;
use Pig\Tui\TuiAltScreenOptions;
use Pig\Tui\Width;

/**
 * The transcript search cases of upstream's `tui-alt-screen.test.ts`: the search functions, the
 * search box, and Ctrl+Shift+F in a running `TuiAltScreen`. The rest of that file is
 * `TuiAltScreenTest`.
 */
final class TuiAltScreenSearchTest extends TestCase
{
    /** @var list<TuiAltScreen> */
    private array $started = [];

    #[\Override]
    protected function setUp(): void
    {
        Loop::reset();
        Keybindings::reset();
    }

    #[\Override]
    protected function tearDown(): void
    {
        foreach ($this->started as $tui) {
            $tui->stop();
        }
        Keybindings::reset();
    }

    private function start(TuiAltScreen $tui): void
    {
        $tui->start();
        $this->started[] = $tui;
        self::settle();
    }

    /** Upstream's `waitForRender()`; see `TuiAltScreenTest::settle()`. */
    private static function settle(float $seconds = 0.04): void
    {
        $deadline = microtime(true) + $seconds;
        do {
            Loop::get()->defer(static function (): void {
                Loop::get()->defer(static function (): void {
                });
            });
            Loop::get()->tick();
            usleep(1000);
        } while (microtime(true) < $deadline);
    }

    /** @return list<string> the last frame, styling stripped */
    private static function viewport(TuiAltScreen $tui): array
    {
        return array_map(static fn (string $line): string => Ansi::strip($line), $tui->getScreenLines());
    }

    private static function screenContains(TuiAltScreen $tui, string $needle): bool
    {
        foreach (self::viewport($tui) as $line) {
            if (str_contains($line, $needle)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<array{0: 'write', 1: string}|array{0: 'start'|'stop'}> $events */
    private static function wroteIn(array $events, string $needle): bool
    {
        foreach ($events as $event) {
            if ($event[0] === 'write' && str_contains($event[1], $needle)) {
                return true;
            }
        }

        return false;
    }

    /** JavaScript's `lastIndexOf()` on a terminal row, as a column; -1 when absent. */
    private static function lastColumn(string $line, string $needle): int
    {
        $index = strrpos($line, $needle);

        return $index === false ? -1 : Width::visible(substr($line, 0, $index));
    }

    /** JavaScript's `indexOf()` on a terminal row, as a column; -1 when absent. */
    private static function column(string $line, string $needle): int
    {
        $index = strpos($line, $needle);

        return $index === false ? -1 : Width::visible(substr($line, 0, $index));
    }

    /** @param list<string> $viewport */
    private static function arrowRow(array $viewport): int
    {
        foreach ($viewport as $row => $line) {
            if (str_contains($line, '↑') && str_contains($line, '↓')) {
                return $row;
            }
        }

        return -1;
    }

    public function testSearchesNormalizedRenderedTranscriptTextAcrossRows(): void
    {
        $this->assertEquals(
            [new AltScreenSearchMatch([new AltScreenSearchSegment(0, 6, 11), new AltScreenSearchSegment(1, 0, 5)])],
            AltScreenSearch::findAltScreenSearchMatches(['alpha QUICK', 'brown fox'], 'quick brown'),
        );
    }

    public function testMapsNormalizedAsciiAndUnicodeSearchMatchesBackToRenderedColumns(): void
    {
        $this->assertEquals(
            [new AltScreenSearchMatch([
                new AltScreenSearchSegment(0, 1, 3),
                new AltScreenSearchSegment(0, 5, 8),
                new AltScreenSearchSegment(1, 0, 6),
            ])],
            AltScreenSearch::findAltScreenSearchMatches(["\x1b[31mfoo  bar\x1b[0m", 'A界🙂éZ'], "oo   bar\nA界🙂é"),
        );
    }

    public function testReusesIndexedTranscriptMatchesUntilTheQueryOrRenderedLinesChange(): void
    {
        $index = new AltScreenSearchIndex();
        $initial = $index->search(['alpha needle', 'omega'], 'needle');
        $this->assertTrue($initial->changed);
        $this->assertCount(1, $initial->matches);

        $cached = $index->search(['alpha needle', 'omega'], 'needle');
        $this->assertFalse($cached->changed);
        $this->assertSame($initial->matches, $cached->matches);

        $changedQuery = $index->search(['alpha needle', 'omega'], 'omega');
        $this->assertTrue($changedQuery->changed);
        $this->assertNotSame($initial->matches, $changedQuery->matches);
        $this->assertEquals([new AltScreenSearchSegment(1, 0, 5)], $changedQuery->matches[0]->segments);

        $changedLines = $index->search(['alpha needle', 'no match'], 'omega');
        $this->assertTrue($changedLines->changed);
        $this->assertSame([], $changedLines->matches);
    }

    public function testRendersTranscriptSearchWithAMutedPlaceholderAndRightAlignedControls(): void
    {
        $component = new AltScreenSearchComponent(static function (): void {
        });
        $rendered = $component->render(48);
        $lines = array_map(static fn (string $line): string => Ansi::strip($line), $rendered);

        $this->assertCount(3, $lines);
        foreach ($lines as $line) {
            $this->assertSame(48, Width::visible($line));
        }
        $this->assertMatchesRegularExpression('/^┌─+┐$/u', $lines[0]);
        $this->assertMatchesRegularExpression('/^│ Find in transcript +│$/u', $lines[1]);
        $this->assertStringContainsString("\x1b[2m", $rendered[1]);
        $this->assertMatchesRegularExpression('/^└─+ ↑ Shift\+Enter · ↓ Enter ─┘$/u', $lines[2]);
        $controls = $lines[2];
        $this->assertSame(-1, $component->getNavigationDirectionAt(2, self::column($controls, '↑')));
        $this->assertSame(-1, $component->getNavigationDirectionAt(2, self::column($controls, 'Shift+Enter') + 5));
        $this->assertNull($component->getNavigationDirectionAt(2, self::column($controls, '·')));
        $this->assertSame(1, $component->getNavigationDirectionAt(2, self::column($controls, '↓')));
        $this->assertSame(1, $component->getNavigationDirectionAt(2, self::lastColumn($controls, 'Enter') + 2));

        $component->handleInput('n');
        $component->setResult(0, 2);
        $populatedRender = $component->render(48);
        $populated = array_map(static fn (string $line): string => Ansi::strip($line), $populatedRender);
        $this->assertStringContainsString('n', $populated[1]);
        $this->assertStringContainsString('1/2', $populated[1]);
        $this->assertStringContainsString("\x1b[2m 1/2 \x1b[22m", $populatedRender[1]);
        $this->assertStringNotContainsString('Find in transcript', implode("\n", $populated));
    }

    public function testNavigatesTranscriptSearchWithHoverableArrowButtonsAndTogglesItWithItsShortcut(): void
    {
        $terminal = new FakeTerminal(120, 6);
        $tui = new TuiAltScreen($terminal, options: new TuiAltScreenOptions(
            searchNavigationButtonStyle: static fn (string $text, bool $hovered): string => ($hovered ? "\x1b[45m" : "\x1b[44m") . $text . "\x1b[49m",
        ));
        $tui->addChild(new Text("needle one\nmiddle\nneedle two\nend", 0, 0));
        $this->start($tui);

        $terminal->type("\x1b[102;6u");
        $terminal->type('needle');
        self::settle();
        $this->assertTrue(self::screenContains($tui, '1/2'));
        $this->assertTrue(self::screenContains($tui, '↑ Shift+Enter · ↓ Enter'));

        $viewport = self::viewport($tui);
        $arrowRow = self::arrowRow($viewport);
        $arrowColumn = $arrowRow >= 0 ? self::lastColumn($viewport[$arrowRow], 'Enter') : -1;
        $this->assertTrue($arrowRow >= 0 && $arrowColumn >= 0);
        $hoverEventCount = count($terminal->events);
        $terminal->type("\x1b[<35;" . ($arrowColumn + 1) . ';' . ($arrowRow + 1) . 'M');
        self::settle();
        $this->assertTrue(self::wroteIn(array_slice($terminal->events, $hoverEventCount), "\x1b[45m↓ Enter\x1b[49m"));
        $terminal->type("\x1b[<0;" . ($arrowColumn + 1) . ';' . ($arrowRow + 1) . 'M');
        self::settle();
        $this->assertTrue(self::screenContains($tui, '2/2'));
        $this->assertTrue(self::screenContains($tui, '↑ Shift+Enter · ↓ Enter'));

        $viewport = self::viewport($tui);
        $arrowRow = self::arrowRow($viewport);
        $arrowColumn = ($arrowRow >= 0 ? self::column($viewport[$arrowRow], 'Shift+Enter') : -3) + 3;
        $this->assertTrue($arrowRow >= 0 && $arrowColumn >= 0);
        $terminal->type("\x1b[<0;" . ($arrowColumn + 1) . ';' . ($arrowRow + 1) . 'M');
        self::settle();
        $this->assertTrue(self::screenContains($tui, '1/2'));
        $this->assertTrue(self::screenContains($tui, '↑ Shift+Enter · ↓ Enter'));

        $terminal->type("\x1b[102;6u");
        self::settle();
        $this->assertFalse(self::screenContains($tui, '↑ Shift+Enter · ↓ Enter'));
    }

    public function testDoesNotTreatTranscriptBoxDrawingAsSearchNavigationButtons(): void
    {
        $terminal = new FakeTerminal(80, 10);
        $tui = new TuiAltScreen($terminal);
        $tui->addChild(new Text(implode("\n", [
            'needle one',
            'middle',
            'needle two',
            'filler',
            '┌────────────────────────────────────────┐',
            '│ box                                    │',
            '└────────────────────────────────────────┘',
            'end',
        ]), 0, 0));
        $this->start($tui);

        $terminal->type("\x1b[102;6u");
        $terminal->type('needle');
        self::settle();
        $this->assertTrue(self::screenContains($tui, '1/2'));
        $this->assertFalse(self::screenContains($tui, '2/2'));

        $boxBottomRow = -1;
        foreach (self::viewport($tui) as $row => $line) {
            if (str_starts_with($line, '└')) {
                $boxBottomRow = $row;
                break;
            }
        }
        $this->assertGreaterThanOrEqual(0, $boxBottomRow);
        $terminal->type("\x1b[<0;24;" . ($boxBottomRow + 1) . 'M');
        self::settle();

        $this->assertTrue(self::screenContains($tui, '1/2'));
        $this->assertFalse(self::screenContains($tui, '2/2'));
    }

    public function testUsesConfiguredStylesForCurrentAndNonCurrentSearchMatches(): void
    {
        $terminal = new FakeTerminal(60, 4);
        $tui = new TuiAltScreen($terminal, options: new TuiAltScreenOptions(
            searchMatchStyle: static fn (string $text): string => "\x1b[41m{$text}\x1b[49m",
            searchCurrentMatchStyle: static fn (string $text): string => "\x1b[42m{$text}\x1b[49m",
        ));
        $tui->addChild(new Text("needle first\nmiddle\nneedle second\nend", 0, 0));
        $this->start($tui);

        $terminal->type("\x1b[102;6u");
        $terminal->type('needle');
        self::settle();

        $this->assertTrue(self::wroteIn($terminal->events, "\x1b[42mneedle\x1b[49m"));
        $this->assertTrue(self::wroteIn($terminal->events, "\x1b[41mneedle\x1b[49m"));
    }

    public function testSearchesTheTranscriptWithCtrlShiftFAndRestoresEditorFocusOnClose(): void
    {
        $terminal = new FakeTerminal(60, 8);
        $tui = new TuiAltScreen($terminal);
        $lines = [];
        for ($index = 0; $index < 12; $index++) {
            $lines[] = match ($index) {
                4 => 'line 5 needle one',
                9 => 'line 10 needle two',
                default => 'line ' . ($index + 1),
            };
        }
        $transcript = new ScrollView(new Text(implode("\n", $lines), 0, 0), follow: 'end', primary: true);
        $editor = new FocusableOverlay(['editor']);
        $tui->setLayoutRoot(new VStack([
            new StackEntry($transcript, basis: 0, grow: 1, minSize: 1),
            new StackEntry($editor, basis: 1, shrink: 0),
        ]));
        $tui->setFocus($editor);
        $this->start($tui);

        $terminal->type("\x1b[102;6u");
        $terminal->type('needle');
        self::settle();
        $this->assertFalse($transcript->isFollowingEnd());
        $this->assertTrue(self::screenContains($tui, '2/2'));
        $this->assertTrue(self::screenContains($tui, '↑ Shift+Enter · ↓ Enter'));
        $this->assertTrue(self::screenContains($tui, 'line 10 needle two'));
        $this->assertSame([], $editor->inputs);
        $this->assertTrue(self::wroteIn($terminal->events, "\x1b[1;7mneedle\x1b[22;27m"));

        for ($index = 0; $index < 6; $index++) {
            $terminal->type("\x1b[<64;1;4M");
        }
        self::settle();
        $this->assertSame(0, $transcript->scrollTop());
        $found = false;
        foreach (self::viewport($tui) as $line) {
            $found = $found || (str_contains($line, 'needle') && str_contains($line, '2/2'));
        }
        $this->assertTrue($found);

        $terminal->type("\x07");
        self::settle();
        $this->assertTrue(self::screenContains($tui, '1/2'));
        $this->assertTrue(self::screenContains($tui, 'line 5 needle one'));

        $terminal->type("\x1b[103;6u");
        self::settle();
        $this->assertTrue(self::screenContains($tui, '2/2'));
        $this->assertTrue(self::screenContains($tui, 'line 10 needle two'));

        $terminal->type("\x1b");
        $terminal->type('x');
        self::settle();
        $this->assertFalse(self::screenContains($tui, '↑ Shift+Enter · ↓ Enter'));
        $this->assertSame(['x'], $editor->inputs);
    }

    public function testKeepsViewportScrollingWhileTranscriptSearchIsFocused(): void
    {
        $terminal = new FakeTerminal(20, 6);
        $tui = new TuiAltScreen($terminal);
        $tui->addChild(new Text(implode("\n", array_map(static fn (int $i): string => "line {$i}", range(1, 12))), 0, 0));
        $this->start($tui);
        $topBefore = $tui->viewportTop();

        $terminal->type("\x1b[102;6u");
        self::settle();
        $this->assertTrue(self::screenContains($tui, '↑ ↓'));

        $terminal->type("\x1b[5~");
        $terminal->type("\x1b[<64;1;4M");
        self::settle();
        $this->assertLessThan($topBefore, $tui->viewportTop());
        $this->assertTrue(self::screenContains($tui, '↑ ↓'));
    }
}
