<?php

declare(strict_types=1);

namespace Pig\Tui\Test;

use Closure;
use PHPUnit\Framework\TestCase;
use Pig\Async\Loop;
use Pig\Tui\Ansi;
use Pig\Tui\Component;
use Pig\Tui\Components\HStack;
use Pig\Tui\Components\MouseRegion;
use Pig\Tui\Components\ScrollView;
use Pig\Tui\Components\SelectItem;
use Pig\Tui\Components\SelectList;
use Pig\Tui\Components\SelectListTheme;
use Pig\Tui\Components\StackEntry;
use Pig\Tui\Components\Text;
use Pig\Tui\Components\VStack;
use Pig\Tui\Keybindings;
use Pig\Tui\KeybindingsManager;
use Pig\Tui\MouseHandler;
use Pig\Tui\OverlayOptions;
use Pig\Tui\TuiAltScreen;
use Pig\Tui\TuiAltScreenOptions;
use Pig\Tui\TuiMouseDispatchResult;
use Pig\Tui\TuiMouseEvent;
use Pig\Tui\TuiMouseEventResult;
use Pig\Tui\Width;

/**
 * Upstream's `tui-alt-screen.test.ts`: the viewport, the jump indicator, wheel routing, the
 * scrollbar, the `tui.altScreen.*` keys, OSC 133 prompt jumps, overlays, component mouse dispatch,
 * OSC 8 link clicks, selection and copy, flashes, and leaving. The transcript search cases are in
 * `TuiAltScreenSearchTest`.
 *
 * Upstream reads the screen back through a headless xterm; this reads the renderer's own
 * `getScreenLines()`, which is the frame it wrote.
 *
 * Not ported, because the alternate screen has no image support in pig yet:
 *
 * - "does not emit Kitty graphics commands or OSC 133 zones in iTerm2"
 * - "clears stale iTerm2 image placements when they leave the viewport"
 * - "crops a Kitty image whose first line is above the viewport"
 * - "redraws WezTerm Kitty images after writes to covered rows"
 * - "reuses moved Kitty images without dropping HStack siblings"
 * - "retains recently offscreen Kitty images for placement-only reuse"
 * - "evicts the least recently visible Kitty image when the cache is full"
 * - "evicts offscreen Kitty images when decoded raster memory exceeds the cache quota"
 *
 * Ported in part: the right-click paste case only checks the platform pig runs on
 * (`PHP_OS_FAMILY` cannot be faked), and the specific copy error case does not check the flash
 * duration (upstream swaps `tui.flash` on the instance, which PHP cannot).
 */
final class TuiAltScreenTest extends TestCase
{
    private const string OSC133_ZONE_START = "\x1b]133;A\x07";

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

    /** Upstream's `waitForRender()`: run the loop until the throttled frame is out. */
    private static function settle(float $seconds = 0.04): void
    {
        $deadline = microtime(true) + $seconds;
        do {
            // A tick with nothing queued sleeps until the next timer — a one-second flash would
            // expire inside a 40 ms settle.
            // The queue is drained before the poll, so what keeps the poll from waiting is
            // something the drained callback queues for the next tick.
            Loop::get()->defer(static function (): void {
                Loop::get()->defer(static function (): void {
                });
            });
            Loop::get()->tick();
            usleep(1000);
        } while (microtime(true) < $deadline);
    }

    /** @return list<string> */
    private static function viewport(TuiAltScreen $tui): array
    {
        return array_map(static fn (string $line): string => rtrim(Ansi::strip($line)), $tui->getScreenLines());
    }

    private static function lines(int $count, string $prefix = 'line '): string
    {
        return implode("\n", array_map(static fn (int $i): string => "{$prefix}{$i}", range(1, $count)));
    }

    private static function wrote(FakeTerminal $terminal, string $needle): bool
    {
        foreach ($terminal->events as $event) {
            if ($event[0] === 'write' && str_contains($event[1], $needle)) {
                return true;
            }
        }

        return false;
    }

    private static function osc52(string $text): string
    {
        return "\x1b]52;c;" . base64_encode($text) . "\x07";
    }

    public function testRendersATerminalHeightViewportAndPreservesManualScrollPosition(): void
    {
        $terminal = new FakeTerminal(20, 4);
        $tui = new TuiAltScreen($terminal);
        $text = new Text(self::lines(10), 0, 0);
        $tui->addChild($text);
        $this->start($tui);

        $this->assertSame(['line 7', 'line 8', 'line 9', 'line 10'], self::viewport($tui));
        $this->assertTrue($tui->isFollowingOutput());

        $terminal->type("\x1b[<64;1;1M");
        self::settle();
        $this->assertSame(['line 6', 'line 7', 'line 8', 'line 9'], self::viewport($tui));
        $this->assertSame(5, $tui->viewportTop());
        $this->assertFalse($tui->isFollowingOutput());

        $text->setText(self::lines(12));
        $tui->requestRender();
        self::settle();
        $this->assertSame(['line 6', 'line 7', 'line 8', 'line 9'], self::viewport($tui));
    }

    public function testShowsAClickableJumpToEndIndicatorOnTheTranscriptsLastRowWhileScrolledUp(): void
    {
        $terminal = new FakeTerminal(30, 6);
        $tui = new TuiAltScreen($terminal, options: new TuiAltScreenOptions(scrollToEndIndicator: static fn (): string => "\x1b[7m ↓ Jump to end \x1b[27m"));
        $transcript = new ScrollView(new Text(self::lines(8), 0, 0), follow: 'end', primary: true);
        $tui->setLayoutRoot(new VStack([
            new StackEntry($transcript, basis: 0, grow: 1, minSize: 1),
            new StackEntry(new Text("editor\nfooter", 0, 0), basis: 'auto', minSize: 1),
        ]));
        $this->start($tui);
        $this->assertStringNotContainsString('Jump to end', implode("\n", self::viewport($tui)));

        $terminal->type("\x1b[<64;1;1M");
        self::settle();
        $this->assertFalse($transcript->isFollowingEnd());
        $this->assertSame('line 7  ↓ Jump to end', self::viewport($tui)[3]);
        $this->assertSame('editor', self::viewport($tui)[4]);

        // Pressing next to the label starts a selection instead of jumping.
        $terminal->type("\x1b[<0;2;4M");
        $terminal->type("\x1b[<0;2;4m");
        self::settle();
        $this->assertFalse($transcript->isFollowingEnd());

        $terminal->type("\x1b[<0;15;4M");
        $terminal->type("\x1b[<0;15;4m");
        self::settle();
        $this->assertTrue($transcript->isFollowingEnd());
        $this->assertSame(['line 5', 'line 6', 'line 7', 'line 8', 'editor', 'footer'], self::viewport($tui));
    }

    public function testNeverShowsTheJumpToEndIndicatorForAPrimaryScrollViewWithoutFollowEnd(): void
    {
        $tui = new TuiAltScreen(new FakeTerminal(30, 3), options: new TuiAltScreenOptions(scrollToEndIndicator: static fn (): string => ' ↓ Jump to end '));
        $transcript = new ScrollView(new Text("one\ntwo\nthree\nfour\nfive", 0, 0), primary: true);
        $tui->setLayoutRoot($transcript);
        $this->start($tui);

        $this->assertFalse($transcript->isFollowingEnd());
        $this->assertStringNotContainsString('Jump to end', implode("\n", self::viewport($tui)));
    }

    public function testKeepsAnExplicitDockFixedWhileTheTranscriptScrolls(): void
    {
        $terminal = new FakeTerminal(20, 6);
        $tui = new TuiAltScreen($terminal);
        $transcriptText = new Text(self::lines(8), 0, 0);
        $transcript = new ScrollView($transcriptText, follow: 'end', primary: true);
        $dock = new VStack([new Text('editor', 0, 0), new Text('footer', 0, 0)]);
        $tui->setLayoutRoot(new VStack([
            new StackEntry($transcript, basis: 0, grow: 1, minSize: 1),
            new StackEntry($dock, basis: 'auto', minSize: 1),
        ]));
        $this->start($tui);
        $this->assertSame(['line 5', 'line 6', 'line 7', 'line 8', 'editor', 'footer'], self::viewport($tui));

        // Wheel over the dock falls back to the primary transcript scroll view.
        $terminal->type("\x1b[<64;1;6M");
        self::settle();
        $this->assertSame(['line 4', 'line 5', 'line 6', 'line 7', 'editor', 'footer'], self::viewport($tui));
        $this->assertFalse($transcript->isFollowingEnd());

        $transcriptText->setText(self::lines(10));
        $tui->requestRender();
        self::settle();
        $this->assertSame(['line 4', 'line 5', 'line 6', 'line 7', 'editor', 'footer'], self::viewport($tui));

        $tui->scrollToBottom();
        self::settle();
        $this->assertSame(['line 7', 'line 8', 'line 9', 'line 10', 'editor', 'footer'], self::viewport($tui));
    }

    public function testRoutesWheelInputToTheScrollViewUnderThePointer(): void
    {
        $terminal = new FakeTerminal(20, 4);
        $tui = new TuiAltScreen($terminal);
        $left = new ScrollView(new Text(self::lines(7, 'a'), 0, 0), follow: 'end', primary: true);
        $right = new ScrollView(new Text(self::lines(7, 'b'), 0, 0), follow: 'end');
        $tui->setLayoutRoot(new HStack([
            new StackEntry($left, basis: 10, shrink: 0),
            new StackEntry($right, basis: 10, shrink: 0),
        ]));
        $this->start($tui);

        $terminal->type("\x1b[<64;15;1M");
        self::settle();
        $this->assertSame(3, $left->scrollTop());
        $this->assertSame(2, $right->scrollTop());
        $this->assertSame(['a4        b3', 'a5        b4', 'a6        b5', 'a7        b6'], self::viewport($tui));
    }

    public function testScrollsFasterWhileAltIsHeldDuringWheelInput(): void
    {
        $terminal = new FakeTerminal(20, 4);
        $tui = new TuiAltScreen($terminal);
        $tui->addChild(new Text(self::lines(12), 0, 0));
        $this->start($tui);
        $this->assertSame(8, $tui->viewportTop());

        // Alt modifier sets bit 8 on the wheel button (72 = 64 + 8).
        $terminal->type("\x1b[<72;1;1M");
        self::settle();
        $this->assertSame(3, $tui->viewportTop());
    }

    public function testUsesButtonMotionTrackingInsideTerminalMultiplexers(): void
    {
        $environmentKeys = ['TMUX', 'ZELLIJ', 'STY', 'TERM'];
        $previousEnvironment = [];
        foreach ($environmentKeys as $key) {
            $previousEnvironment[$key] = getenv($key);
        }
        try {
            foreach ($environmentKeys as $key) {
                putenv($key);
            }
            putenv('TERM=xterm-256color');
            $directTerminal = new FakeTerminal();
            $directTui = new TuiAltScreen($directTerminal);
            $directTui->start();
            $this->assertStringContainsString("\x1b[?1003h", $directTerminal->output());
            $directTui->stop();

            $multiplexers = [
                'tmux environment' => ['TMUX' => '/tmp/tmux/default,1,0'],
                'tmux TERM' => ['TERM' => 'tmux-256color'],
                'Zellij environment' => ['ZELLIJ' => '0'],
                'Screen environment' => ['STY' => '123.session'],
                'Screen TERM' => ['TERM' => 'screen-256color'],
            ];
            foreach ($multiplexers as $name => $environment) {
                foreach ($environmentKeys as $key) {
                    putenv($key);
                }
                foreach ($environment as $key => $value) {
                    putenv("{$key}={$value}");
                }
                $terminal = new FakeTerminal();
                $tui = new TuiAltScreen($terminal);
                $tui->start();
                $writes = $terminal->output();
                $this->assertStringContainsString("\x1b[?1002h", $writes, "{$name} should enable button-motion tracking");
                $this->assertStringNotContainsString("\x1b[?1003h", $writes, "{$name} should not enable all-motion tracking");
                $this->assertStringContainsString("\x1b[?1006h", $writes, "{$name} should enable SGR mouse encoding");
                $tui->stop();
            }
        } finally {
            foreach ($previousEnvironment as $key => $value) {
                putenv($value === false ? $key : "{$key}={$value}");
            }
        }
    }

    public function testRevealsAnAutoScrollbarWhenThePointerEntersItsHiddenTrack(): void
    {
        $terminal = new FakeTerminal(10, 5);
        $tui = new TuiAltScreen($terminal);
        $scrollView = new ScrollView(new Text(self::lines(20), 0, 0), primary: true, scrollbar: 'auto', scrollbarHideDelayMs: 20);
        $tui->setLayoutRoot($scrollView);
        $this->start($tui);
        $this->assertFalse($scrollView->isScrollbarVisible());

        $terminal->type("\x1b[<35;10;3M");
        self::settle();
        $this->assertTrue($scrollView->isScrollbarVisible());
        $this->assertTrue($scrollView->isScrollbarActive());
        $this->assertMatchesRegularExpression('/[│█]/u', implode("\n", self::viewport($tui)));

        $terminal->type("\x1b[<35;9;3M");
        self::settle(0.06);
        $this->assertFalse($scrollView->isScrollbarVisible());
    }

    public function testJumpsToAScrollbarTrackPositionAndContinuesDraggingFromThere(): void
    {
        $terminal = new FakeTerminal(10, 10);
        $tui = new TuiAltScreen($terminal);
        $scrollView = new ScrollView(new Text(self::lines(50), 0, 0), primary: true, scrollbar: 'always');
        $tui->setLayoutRoot($scrollView);
        $this->start($tui);
        $this->assertSame(0, $scrollView->scrollTop());

        $terminal->type("\x1b[<0;10;6M");
        self::settle();
        $this->assertSame(20, $scrollView->scrollTop());

        $terminal->type("\x1b[<32;10;10M");
        self::settle();
        $this->assertSame(40, $scrollView->scrollTop());

        $terminal->type("\x1b[<0;10;10m");
        self::settle();
        $this->assertFalse(self::wrote($terminal, "\x1b]52;c;"));
    }

    public function testChainsUnusedWheelDeltaToAnOuterScrollView(): void
    {
        $terminal = new FakeTerminal(20, 4);
        $tui = new TuiAltScreen($terminal, options: new TuiAltScreenOptions(wheelScrollLines: 3));
        $inner = new ScrollView(new Text(self::lines(6, 'i'), 0, 0));
        $outer = new ScrollView(new VStack([new StackEntry($inner, basis: 2), new Text(self::lines(5, 'tail'), 0, 0)]), primary: true);
        $tui->setLayoutRoot($outer);
        $this->start($tui);

        $terminal->type("\x1b[<65;1;1M");
        self::settle();
        $this->assertSame(3, $inner->scrollTop());
        $this->assertSame(0, $outer->scrollTop());

        $terminal->type("\x1b[<65;1;1M");
        self::settle();
        $this->assertSame(4, $inner->scrollTop());
        $this->assertSame(2, $outer->scrollTop());
    }

    public function testSelectsVisibleTextWithTheMouseAndCopiesItWithOsc52AfterAGenericRelease(): void
    {
        $terminal = new FakeTerminal(20, 4);
        $tui = new TuiAltScreen($terminal);
        $tui->addChild(new Text("\x1b[1mal\x1b[0mpha\nbeta\ngamma\ndelta", 0, 0));
        $this->start($tui);

        $terminal->type("\x1b[<0;1;1M");
        $terminal->type("\x1b[<32;4;2M");
        $terminal->type("\x1b[<3;4;2m");
        self::settle();

        $this->assertTrue(self::wrote($terminal, self::osc52("alpha\nbeta")));
        $this->assertTrue(self::wrote($terminal, "\x1b[7m"));
        $this->assertTrue(self::wrote($terminal, "al\x1b[0m\x1b[7mpha"), 'selection inverse must be reapplied after a reset inside the selection');
        $this->assertStringContainsString('Copied!', implode("\n", self::viewport($tui)));
    }

    public function testUsesAnInjectedCopySelectionHandlerInsteadOfOsc52AndReportsSuccess(): void
    {
        $terminal = new FakeTerminal(20, 4);
        $copied = [];
        $tui = new TuiAltScreen($terminal, options: new TuiAltScreenOptions(copySelection: static function (string $text) use (&$copied): bool {
            $copied[] = $text;

            return true;
        }));
        $tui->addChild(new Text("alpha\nbeta\ngamma\ndelta", 0, 0));
        $this->start($tui);

        $terminal->type("\x1b[<0;1;1M");
        $terminal->type("\x1b[<32;4;2M");
        $terminal->type("\x1b[<0;4;2m");
        self::settle();

        $this->assertSame(["alpha\nbeta"], $copied);
        $this->assertFalse(self::wrote($terminal, "\x1b]52;c;"), 'must not emit OSC 52 when a copySelection handler is provided');
        $this->assertStringContainsString('Copied!', implode("\n", self::viewport($tui)));
    }

    public function testLeavesSelectionsVisibleWithoutCopyingWhenCopyOnSelectIsDisabled(): void
    {
        $terminal = new FakeTerminal(20, 4);
        $copied = [];
        $tui = new TuiAltScreen($terminal, options: new TuiAltScreenOptions(copyOnSelect: false, copySelection: static function (string $text) use (&$copied): bool {
            $copied[] = $text;

            return true;
        }));
        $tui->addChild(new Text("alpha\nbeta\ngamma\ndelta", 0, 0));
        $this->start($tui);

        $terminal->type("\x1b[<0;1;1M");
        $terminal->type("\x1b[<32;4;2M");
        $terminal->type("\x1b[<0;4;2m");
        self::settle();

        $this->assertSame([], $copied);
        $this->assertTrue($tui->hasActiveSelection());
        $this->assertTrue(self::wrote($terminal, "\x1b[7m"));
        $this->assertStringNotContainsString('Copied!', implode("\n", self::viewport($tui)));
    }

    public function testCopiesAnActiveSelectionProgrammatically(): void
    {
        $terminal = new FakeTerminal(20, 4);
        $copied = [];
        $tui = new TuiAltScreen($terminal, options: new TuiAltScreenOptions(copySelection: static function (string $text) use (&$copied): bool {
            $copied[] = $text;

            return true;
        }));
        $tui->addChild(new Text("alpha\nbeta\ngamma\ndelta", 0, 0));
        $this->start($tui);

        $this->assertFalse($tui->hasActiveSelection());
        $this->assertFalse($tui->copyActiveSelectionToClipboard());

        $terminal->type("\x1b[<0;1;1M");
        $terminal->type("\x1b[<32;4;2M");
        $terminal->type("\x1b[<0;4;2m");
        self::settle();
        $this->assertSame(["alpha\nbeta"], $copied);
        $this->assertTrue($tui->hasActiveSelection());

        $copied = [];
        $this->assertTrue($tui->copyActiveSelectionToClipboard());
        self::settle();
        $this->assertSame(["alpha\nbeta"], $copied);
        $this->assertStringContainsString('Copied!', implode("\n", self::viewport($tui)));
    }

    public function testFlashesAnErrorWhenTheInjectedCopySelectionHandlerFails(): void
    {
        $terminal = new FakeTerminal(20, 4);
        $tui = new TuiAltScreen($terminal, options: new TuiAltScreenOptions(copySelection: static fn (): bool => false));
        $tui->addChild(new Text("alpha\nbeta\ngamma\ndelta", 0, 0));
        $this->start($tui);

        $terminal->type("\x1b[<0;1;1M");
        $terminal->type("\x1b[<32;4;2M");
        $terminal->type("\x1b[<0;4;2m");
        self::settle();

        $this->assertStringContainsString('Copy failed', implode("\n", self::viewport($tui)));
        $this->assertFalse(self::wrote($terminal, "\x1b]52;c;"));
    }

    public function testFlashesASpecificErrorReturnedByTheInjectedCopySelectionHandler(): void
    {
        $terminal = new FakeTerminal(80, 4);
        $tui = new TuiAltScreen($terminal, options: new TuiAltScreenOptions(copyOnSelect: false, copySelection: static fn (): string => 'Clipboard unavailable: install wl-clipboard'));
        $tui->addChild(new Text("alpha\nbeta\ngamma\ndelta", 0, 0));
        $this->start($tui);

        $terminal->type("\x1b[<0;1;1M");
        $terminal->type("\x1b[<32;4;2M");
        $terminal->type("\x1b[<0;4;2m");
        self::settle();
        $this->assertFalse($tui->copyActiveSelectionToClipboard());
        self::settle();

        $screen = implode("\n", self::viewport($tui));
        $this->assertStringContainsString('Clipboard unavailable: install wl-clipboard', $screen);
        $this->assertStringNotContainsString('Copy failed', $screen);
    }

    public function testDoesNotAppendWhitespaceToDoubleClickWordHighlighting(): void
    {
        $terminal = new FakeTerminal(20, 1);
        $tui = new TuiAltScreen($terminal);
        $tui->addChild(new Text('foo  bar', 0, 0));
        $this->start($tui);

        $terminal->type("\x1b[<0;1;1M");
        $terminal->type("\x1b[<0;1;1m");
        $terminal->type("\x1b[<0;3;1M");
        self::settle();

        $this->assertTrue(self::wrote($terminal, "foo\x1b[27m"));
    }

    public function testCoalescesSlashAndHyphenSeparatedSegmentsForDoubleClickWordSelection(): void
    {
        foreach ([
            ['extensions/starline/fixed-editor/compositor.ts', 'starline'],
            ['earendil-works/pi-tui', 'works'],
        ] as [$line, $needle]) {
            $copied = [];
            $terminal = new FakeTerminal(80, 1);
            $tui = new TuiAltScreen($terminal, options: new TuiAltScreenOptions(copySelection: static function (string $text) use (&$copied): bool {
                $copied[] = $text;

                return true;
            }));
            $tui->addChild(new Text($line, 0, 0));
            $this->start($tui);

            $column = strpos($line, $needle) + 1;
            $terminal->type("\x1b[<0;{$column};1M\x1b[<0;{$column};1m\x1b[<0;{$column};1M\x1b[<0;{$column};1m");
            self::settle();

            $this->assertSame([$line], $copied);
        }
    }

    public function testHighlightsACompleteWhitespaceSegmentDuringAWordDrag(): void
    {
        $terminal = new FakeTerminal(20, 1);
        $tui = new TuiAltScreen($terminal);
        $tui->addChild(new Text('foo  bar', 0, 0));
        $this->start($tui);

        $terminal->type("\x1b[<0;1;1M");
        $terminal->type("\x1b[<0;1;1m");
        $terminal->type("\x1b[<0;2;1M");
        $terminal->type("\x1b[<32;4;1M");
        self::settle();

        $this->assertTrue(self::wrote($terminal, "foo  \x1b[27m"));
    }

    public function testSelectsWholeWordsOnDoubleClickExtendsWordDragsAndSelectsLinesOnTripleClick(): void
    {
        $terminal = new FakeTerminal(20, 2);
        $tui = new TuiAltScreen($terminal);
        $tui->addChild(new Text("zero alpha beta\ngamma delta", 0, 0));
        $this->start($tui);

        // The second click lands on a different character in alpha.
        $terminal->type("\x1b[<0;6;1M\x1b[<0;6;1m\x1b[<0;10;1M\x1b[<0;10;1m");
        self::settle();
        $this->assertTrue(self::wrote($terminal, self::osc52('alpha')));

        // A double-click drag includes each word touched, rather than partial words.
        $terminal->type("\x1b[<0;12;1M\x1b[<0;12;1m\x1b[<0;14;1M\x1b[<32;3;2M\x1b[<0;3;2m");
        self::settle();
        $this->assertTrue(self::wrote($terminal, self::osc52("beta\ngamma")));

        $terminal->type("\x1b[<0;7;2M\x1b[<0;7;2m\x1b[<0;9;2M\x1b[<0;9;2m\x1b[<0;11;2M\x1b[<0;11;2m");
        self::settle();
        $this->assertTrue(self::wrote($terminal, self::osc52('gamma delta')));
    }

    public function testDoesNotRepaintIdleOrZeroWidthSelectionsOnFocusLoss(): void
    {
        $terminal = new FakeTerminal(20, 4);
        $tui = new TuiAltScreen($terminal);
        $tui->addChild(new Text("alpha\nbeta\ngamma\ndelta", 0, 0));
        $this->start($tui);
        $writeCount = static fn (): int => count(array_filter($terminal->events, static fn (array $event): bool => $event[0] === 'write'));

        $idle = $writeCount();
        $terminal->type("\x1b[O");
        $terminal->type("\x1b[I");
        self::settle();
        $this->assertSame($idle, $writeCount());

        // A completed click leaves a zero-width anchor; later orphaned drag/release events must not extend it.
        $terminal->type("\x1b[<0;1;1M\x1b[<0;1;1m\x1b[<32;4;2M\x1b[<0;4;2m");
        self::settle();
        $this->assertFalse(self::wrote($terminal, "\x1b]52;c;"));

        // Losing focus after a press without a drag cancels the press without repainting.
        $terminal->type("\x1b[<0;1;3M");
        self::settle();
        $pressed = $writeCount();
        $terminal->type("\x1b[O");
        $terminal->type("\x1b[I");
        self::settle();
        $this->assertSame($pressed, $writeCount());
        $terminal->type("\x1b[<32;4;2M\x1b[<0;4;2m");
        self::settle();
        $this->assertFalse(self::wrote($terminal, "\x1b]52;c;"));
        $this->assertTrue(self::wrote($terminal, "\x1b[?1004h"));

        $tui->stop();
        $this->started = [];
        $this->assertTrue(self::wrote($terminal, "\x1b[?1004l"));
    }

    public function testClearsAnActiveVisibleSelectionOnFocusLossAndIgnoresOrphanEvents(): void
    {
        $terminal = new FakeTerminal(20, 4);
        $tui = new TuiAltScreen($terminal);
        $tui->addChild(new Text("alpha\nbeta\ngamma\ndelta", 0, 0));
        $this->start($tui);

        $terminal->type("\x1b[<0;1;1M\x1b[<32;4;2M");
        self::settle();
        $terminal->clearWrites();
        $terminal->type("\x1b[O");
        $terminal->type("\x1b[I");
        self::settle();
        $writes = $terminal->output();
        $this->assertStringContainsString('alpha', $writes);
        $this->assertStringContainsString('beta', $writes);
        $this->assertStringNotContainsString("\x1b[7m", $writes);

        $terminal->type("\x1b[<32;4;2M\x1b[<0;4;2m");
        self::settle();
        $this->assertFalse(self::wrote($terminal, "\x1b]52;c;"));
    }

    public function testRetainsACompletedVisibleSelectionAcrossFocusChanges(): void
    {
        $terminal = new FakeTerminal(20, 4);
        $tui = new TuiAltScreen($terminal);
        $tui->addChild(new Text("alpha\nbeta\ngamma\ndelta", 0, 0));
        $this->start($tui);

        $terminal->type("\x1b[<0;1;1M\x1b[<32;4;2M\x1b[<0;4;2m");
        self::settle();
        $completed = count($terminal->writes);
        $terminal->type("\x1b[O");
        $terminal->type("\x1b[I");
        self::settle();
        $this->assertSame($completed, count($terminal->writes));

        $terminal->clearWrites();
        $tui->renderNow(true);
        $writes = $terminal->output();
        $this->assertStringContainsString('alpha', $writes);
        $this->assertStringContainsString('beta', $writes);
        $this->assertStringContainsString("\x1b[7m", $writes);
    }

    public function testStacksFlashMessagesAndCollapsesThemAsTheyExpire(): void
    {
        $tui = new TuiAltScreen(new FakeTerminal(20, 4));
        $tui->addChild(new Text("one\ntwo\nthree\nfour", 0, 0));
        $this->start($tui);

        $tui->flash('First', 0.08);
        $tui->flash('Second', 0.5);
        self::settle();
        $screen = self::viewport($tui);
        $this->assertStringEndsWith(' First', $screen[0]);
        $this->assertStringEndsWith(' Second', $screen[1]);

        self::settle(0.12);
        $screen = self::viewport($tui);
        $this->assertStringEndsWith(' Second', $screen[0]);
        $this->assertStringNotContainsString('First', implode("\n", $screen));
    }

    public function testAutoScrollsAndExtendsADragSelectionHeldAtTheViewportEdge(): void
    {
        $terminal = new FakeTerminal(20, 4);
        $tui = new TuiAltScreen($terminal);
        $tui->addChild(new Text(self::lines(10), 0, 0));
        $this->start($tui);
        $this->assertSame(6, $tui->viewportTop());

        $terminal->type("\x1b[<0;1;3M\x1b[<32;1;1M");
        self::settle(0.15);
        $selectionTop = $tui->viewportTop();
        $this->assertLessThan(6, $selectionTop);
        $terminal->type("\x1b[<0;1;1m");
        self::settle();

        $selected = array_map(static fn (int $i): string => 'line ' . ($selectionTop + $i + 1), range(0, 8 - $selectionTop - 1));
        $selected[] = 'l';
        $this->assertTrue(self::wrote($terminal, self::osc52(implode("\n", $selected))));
    }

    public function testSnapsMouseSelectionToCjkEmojiAndCombiningGraphemeBoundaries(): void
    {
        $terminal = new FakeTerminal(20, 2);
        $tui = new TuiAltScreen($terminal);
        $tui->addChild(new Text("A界🙂éZ", 0, 0));
        $this->start($tui);
        $count = static fn (string $needle): int => count(array_filter($terminal->events, static fn (array $event): bool => $event[0] === 'write' && str_contains($event[1], $needle)));

        $wide = self::osc52('界🙂');
        $terminal->type("\x1b[<0;3;1M\x1b[<32;4;1M\x1b[<0;4;1m");
        self::settle();
        $this->assertSame(1, $count($wide));

        $terminal->type("\x1b[<0;5;1M\x1b[<32;2;1M\x1b[<0;2;1m");
        self::settle();
        $this->assertSame(2, $count($wide));

        $terminal->type("\x1b[<0;6;1M\x1b[<32;7;1M\x1b[<0;7;1m");
        self::settle();
        $this->assertTrue(self::wrote($terminal, self::osc52('éZ')));
    }

    public function testIgnoresHorizontalTrackpadWheelEvents(): void
    {
        $terminal = new FakeTerminal(20, 4);
        $tui = new TuiAltScreen($terminal);
        $tui->addChild(new Text(self::lines(8), 0, 0));
        $this->start($tui);

        $terminal->type("\x1b[<66;1;1M\x1b[<67;1;1M");
        self::settle();
        $this->assertSame(4, $tui->viewportTop());
        $this->assertSame(['line 5', 'line 6', 'line 7', 'line 8'], self::viewport($tui));
    }

    /** pig reads input in chunks, so a gesture's reports and a keystroke can share one read. */
    public function testReportsInsideOneReadAreHandledAndTheRestStillReachesTheFocusedComponent(): void
    {
        $terminal = new FakeTerminal(20, 4);
        $tui = new TuiAltScreen($terminal);
        $focused = new TextComponent('');
        $tui->addChild(new Text(self::lines(8), 0, 0));
        $tui->addChild($focused);
        $tui->setFocus($focused);
        $this->start($tui);

        $terminal->type("\x1b[I\x1b[<64;1;1M\x1b[<64;1;1Mx");
        self::settle();

        $this->assertSame(2, $tui->viewportTop());
        $this->assertSame(['x'], $focused->typed);
    }

    public function testRestoresKeyboardStateBeforeLeavingAltModeAndPrintsTheFullDocument(): void
    {
        $terminal = new FakeTerminal(20, 3);
        $tui = new TuiAltScreen($terminal);
        $tui->addChild(new Text("first\nsecond\nthird\nfourth\nfifth\nsixth", 0, 0));
        $tui->start();
        self::settle();
        $tui->stop();

        $index = static function (callable $match) use ($terminal): int {
            foreach ($terminal->events as $i => $event) {
                if ($match($event)) {
                    return $i;
                }
            }

            return -1;
        };
        $start = $index(static fn (array $event): bool => $event[0] === 'start');
        $enter = $index(static fn (array $event): bool => $event[0] === 'write' && str_contains($event[1], "\x1b[?1049h"));
        $stop = $index(static fn (array $event): bool => $event[0] === 'stop');
        $mouseOff = $index(static fn (array $event): bool => $event[0] === 'write' && str_contains($event[1], "\x1b[?1006l"));
        $restore = $index(static fn (array $event): bool => $event[0] === 'write' && str_contains($event[1], "\x1b[?1049l"));
        $this->assertTrue($enter >= 0 && $enter < $start);
        $this->assertTrue($mouseOff >= 0 && $mouseOff < $stop);
        $this->assertGreaterThan($stop, $restore);

        $restoreData = $terminal->events[$restore][1];
        foreach (['first', 'second', 'third', 'fourth', 'fifth', 'sixth'] as $word) {
            $this->assertStringContainsString($word, $restoreData);
        }
        $this->assertLessThan(strpos($restoreData, 'sixth'), strpos($restoreData, 'first'));
    }

    public function testAWideCharacterUnderTheJumpPillDoesNotWidenTheRow(): void
    {
        $terminal = new FakeTerminal(171, 6);
        $tui = new TuiAltScreen($terminal, options: new TuiAltScreenOptions(scrollToEndIndicator: static fn (): string => ' ↓ Jump to latest message · Ctrl+End '));
        $tui->addChild(new Text(implode("\n", array_fill(0, 20, str_repeat('中', 85))), 0, 0));
        $this->start($tui);

        $terminal->type("\x1b[<64;1;1M");
        self::settle();

        foreach ($tui->getScreenLines() as $line) {
            $this->assertLessThanOrEqual(171, \Pig\Tui\Width::visible($line));
        }
        $this->assertStringContainsString('Jump to latest message', implode("\n", self::viewport($tui)));
    }

    public function testKeepsTheJumpToEndIndicatorCenteredAsTheAutoScrollbarHidesAndReappears(): void
    {
        // Regression test for #9136: auto scrollbar visibility must not move the indicator.
        $terminal = new FakeTerminal(80, 6);
        $label = ' ↓ Jump to latest message · End ';
        $tui = new TuiAltScreen($terminal, options: new TuiAltScreenOptions(scrollToEndIndicator: static fn (): string => $label));
        $transcript = new ScrollView(new Text(self::lines(20), 0, 0), follow: 'end', primary: true, scrollbar: 'auto', scrollbarHideDelayMs: 0);
        $tui->setLayoutRoot($transcript);
        $this->start($tui);

        // Scrolling over the track keeps the scrollbar visible until the pointer leaves.
        $terminal->type("\x1b[<64;80;1M");
        self::settle();
        $this->assertTrue($transcript->isScrollbarVisible());
        $this->assertFalse($transcript->isFollowingEnd());
        $scrollTop = $transcript->scrollTop();
        $visibleColumn = self::column(self::rawViewport($tui)[5], $label);

        // Leaving the track lets the auto-hide timer expire without changing the content.
        $terminal->type("\x1b[<35;79;1M");
        self::settle();
        $this->assertFalse($transcript->isScrollbarVisible());
        $this->assertSame($scrollTop, $transcript->scrollTop());
        $hiddenColumn = self::column(self::rawViewport($tui)[5], $label);

        $terminal->type("\x1b[<35;80;1M");
        self::settle();
        $this->assertTrue($transcript->isScrollbarVisible());
        $this->assertSame($scrollTop, $transcript->scrollTop());
        $revealedColumn = self::column(self::rawViewport($tui)[5], $label);

        $this->assertSame([24, 24, 24], [$visibleColumn, $hiddenColumn, $revealedColumn]);
    }

    public function testLeavesTheScrollbarVisibleAndClickableWhenTheJumpToEndIndicatorSpansTheTranscript(): void
    {
        // Regression coverage for #9136: centering must not paint or capture clicks over the scrollbar.
        $terminal = new FakeTerminal(30, 6);
        $tui = new TuiAltScreen($terminal, options: new TuiAltScreenOptions(scrollToEndIndicator: static fn (): string => str_repeat('↓', 30)));
        $transcript = new ScrollView(new Text(self::lines(12), 0, 0), follow: 'end', primary: true, scrollbar: 'always');
        $tui->setLayoutRoot(new VStack([
            new StackEntry($transcript, basis: 0, grow: 1, minSize: 1),
            new StackEntry(new Text("editor\nfooter", 0, 0), basis: 'auto', minSize: 1),
        ]));
        $this->start($tui);

        $terminal->type("\x1b[<64;1;1M");
        self::settle();
        $this->assertFalse($transcript->isFollowingEnd());

        // The indicator must not intercept a press on the scrollbar's last column.
        $this->assertSame(str_repeat('↓', 29) . '┃', self::rawViewport($tui)[3]);
        $terminal->type("\x1b[<0;30;4M");
        $terminal->type("\x1b[<0;30;4m");
        self::settle();
        $this->assertFalse($transcript->isFollowingEnd());
    }

    public function testInvalidatesOverlaysWithAnExplicitLayoutRoot(): void
    {
        $tui = new TuiAltScreen(new FakeTerminal());
        $overlay = new TextComponent('overlay');
        $tui->setLayoutRoot(new Text('root', 0, 0));
        $tui->showOverlay($overlay);

        $tui->invalidate();

        $this->assertSame(1, $overlay->invalidated);
    }

    /** Passes in pig without component mouse support in `SelectList`: the miss must not reach it either way. */
    public function testDoesNotVerticallyRedispatchMissesThroughHorizontalLayoutContainers(): void
    {
        $terminal = new FakeTerminal(20, 2);
        $tui = new TuiAltScreen($terminal);
        $selections = 0;
        $identity = static fn (string $text): string => $text;
        $list = new SelectList([new SelectItem('a', 'A'), new SelectItem('b', 'B')], 2, new SelectListTheme($identity, $identity, $identity, $identity));
        $list->setSelectHandler(static function () use (&$selections): void {
            $selections++;
        });
        $tui->setLayoutRoot(new HStack([
            new StackEntry($list, basis: 10),
            new StackEntry(new Text('plain', 0, 0), basis: 10),
        ]));
        $this->start($tui);

        $terminal->type("\x1b[<0;15;1M");
        $terminal->type("\x1b[<0;15;1m");
        self::settle();
        $this->assertSame(0, $selections);
    }

    /**
     * Upstream also fakes `process.platform` as win32 and expects the handler to run, and not in
     * VS Code. pig reads `PHP_OS_FAMILY`, a constant a test cannot change, so only the
     * non-Windows half is ported.
     */
    public function testInvokesTheRightClickPasteHandlerOnlyOnWindowsOutsideVsCode(): void
    {
        $terminal = new FakeTerminal();
        $pasteCount = 0;
        $tui = new TuiAltScreen($terminal, options: new TuiAltScreenOptions(onRightClickPaste: static function () use (&$pasteCount): void {
            $pasteCount++;
        }));
        $this->start($tui);

        $terminal->type("\x1b[<2;1;1M");
        $terminal->type("\x1b[<2;1;1m");
        $this->assertSame(PHP_OS_FAMILY === 'Windows' && getenv('TERM_PROGRAM') !== 'vscode' ? 1 : 0, $pasteCount);
    }

    /** #9758: wheel line counts can change at runtime; Alt keeps its multiplier. */
    public function testAppliesRuntimeWheelLineCountUpdates(): void
    {
        $terminal = new FakeTerminal(20, 4);
        $tui = new TuiAltScreen($terminal, options: new TuiAltScreenOptions(wheelScrollLines: 3));
        $deltas = [];
        $tui->addChild(new MouseRegion(new Text('wheel target', 0, 0), static function (TuiMouseEvent $event) use (&$deltas): ?TuiMouseEventResult {
            if ($event->type !== 'wheel') {
                return null;
            }
            $deltas[] = $event->wheelDelta;

            return new TuiMouseEventResult(handled: true);
        }));
        $this->start($tui);

        $terminal->type("\x1b[<64;1;1M");
        $tui->setWheelScrollLines(2);
        $terminal->type("\x1b[<65;1;1M");
        $terminal->type("\x1b[<72;1;1M");
        $this->assertSame([-3, 2, -10], $deltas);
    }

    public function testSupportsConfigurableKeyboardViewportNavigationWithFourRowsOfPageOverlap(): void
    {
        $terminal = new FakeTerminal(20, 8);
        $tui = new TuiAltScreen($terminal);
        $tui->addChild(new Text(self::lines(12), 0, 0));
        $this->start($tui);

        $terminal->type("\x1b[57421u");
        $terminal->type("\x1b[57421;1:3u");
        self::settle();
        $this->assertSame(['line 1', 'line 2', 'line 3', 'line 4', 'line 5', 'line 6', 'line 7', 'line 8'], self::viewport($tui));

        $terminal->type("\x1b[57422u");
        $terminal->type("\x1b[57422;1:3u");
        self::settle();
        $this->assertSame(['line 5', 'line 6', 'line 7', 'line 8', 'line 9', 'line 10', 'line 11', 'line 12'], self::viewport($tui));

        $terminal->type("\x1b[7^");
        self::settle();
        $this->assertSame(['line 1', 'line 2', 'line 3', 'line 4', 'line 5', 'line 6', 'line 7', 'line 8'], self::viewport($tui));

        $terminal->type("\x1b[8^");
        self::settle();
        $this->assertSame(['line 5', 'line 6', 'line 7', 'line 8', 'line 9', 'line 10', 'line 11', 'line 12'], self::viewport($tui));
    }

    public function testScrollsTheTranscriptByHalfAPageWithCustomBindings(): void
    {
        $terminal = new FakeTerminal(20, 10);
        $tui = new TuiAltScreen($terminal);
        Keybindings::setKeybindings(new KeybindingsManager(Keybindings::definitions(), [
            'tui.altScreen.halfPageUp' => 'ctrl+u',
            'tui.altScreen.halfPageDown' => 'ctrl+d',
        ]));
        $tui->addChild(new Text(self::lines(30), 0, 0));
        $this->start($tui);
        $this->assertSame(20, $tui->viewportTop());

        $terminal->type("\x15");
        self::settle();
        $this->assertSame(15, $tui->viewportTop());

        $terminal->type("\x04");
        self::settle();
        $this->assertSame(20, $tui->viewportTop());
    }

    public function testScrollsTheTranscriptByOneLineWithCustomBindings(): void
    {
        $terminal = new FakeTerminal(20, 10);
        $tui = new TuiAltScreen($terminal);
        Keybindings::setKeybindings(new KeybindingsManager(Keybindings::definitions(), [
            'tui.altScreen.lineUp' => 'ctrl+y',
            'tui.altScreen.lineDown' => 'ctrl+e',
        ]));
        $tui->addChild(new Text(self::lines(30), 0, 0));
        $this->start($tui);
        $this->assertSame(20, $tui->viewportTop());

        $terminal->type("\x19");
        self::settle();
        $this->assertSame(19, $tui->viewportTop());

        $terminal->type("\x05");
        self::settle();
        $this->assertSame(20, $tui->viewportTop());
    }

    public function testRoutesHomeAndEndToTheFocusedComponentAndCtrlHomeEndToTheTranscript(): void
    {
        $terminal = new FakeTerminal(20, 6);
        $tui = new TuiAltScreen($terminal);
        $transcript = new ScrollView(new Text(self::lines(12), 0, 0), follow: 'end', primary: true);
        $editor = new FocusableOverlay(['editor']);
        $tui->setLayoutRoot(new VStack([
            new StackEntry($transcript, basis: 0, grow: 1, minSize: 1),
            new StackEntry($editor, basis: 1, shrink: 0),
        ]));
        $tui->setFocus($editor);
        $this->start($tui);

        $bottom = $transcript->scrollTop();
        $this->assertGreaterThan(0, $bottom);

        // #10314: unmodified Home/End belong to the editor in every UI mode.
        $editorKeys = ["\x1bOH", "\x1b[F", "\x1b[57423u", "\x1b[5;5~", "\x1b[6;5~"];
        foreach ($editorKeys as $input) {
            $terminal->type($input);
        }
        self::settle();
        $this->assertSame($bottom, $transcript->scrollTop());
        $this->assertSame($editorKeys, $editor->inputs);

        $terminal->type("\x1b[1;5H");
        self::settle();
        $this->assertSame(0, $transcript->scrollTop());

        $terminal->type("\x1b[1;5F");
        self::settle();
        $this->assertSame($bottom, $transcript->scrollTop());
        $this->assertTrue($transcript->isFollowingEnd());

        $terminal->type("\x1b[57423;5u");
        $terminal->type("\x1b[57423;5:3u");
        self::settle();
        $this->assertSame(0, $transcript->scrollTop());

        $terminal->type("\x1b[6~");
        self::settle();
        $this->assertSame(1, $transcript->scrollTop());
        $this->assertSame($editorKeys, $editor->inputs);
    }

    public function testJumpsBetweenOsc133SemanticPromptMarkers(): void
    {
        $terminal = new FakeTerminal(20, 3);
        $tui = new TuiAltScreen($terminal);
        $lines = [];
        foreach ([1, 2, 3, 4] as $message) {
            $lines[] = self::OSC133_ZONE_START . "message {$message}";
            $lines[] = 'detail';
        }
        $tui->addChild(new Text(implode("\n", $lines), 0, 0));
        $this->start($tui);
        $this->assertSame(5, $tui->viewportTop());

        $terminal->type("\x1b[57419;6u");
        $terminal->type("\x1b[57419;6:3u");
        self::settle();
        $this->assertSame(4, $tui->viewportTop());
        $this->assertSame('message 3', self::viewport($tui)[0]);

        $terminal->type("\x1b[1;6A");
        self::settle();
        $this->assertSame(2, $tui->viewportTop());
        $this->assertSame('message 2', self::viewport($tui)[0]);

        $terminal->type("\x1b[57420;6u");
        $terminal->type("\x1b[57420;6:3u");
        self::settle();
        $this->assertSame(4, $tui->viewportTop());
        $this->assertSame('message 3', self::viewport($tui)[0]);

        $terminal->type("\x1b[1;6B");
        self::settle();
        $this->assertSame(5, $tui->viewportTop());
        $this->assertSame('message 4', self::viewport($tui)[1]);
        $this->assertTrue($tui->isFollowingOutput());
    }

    public function testOpensAnOsc8HyperlinkWithSpecificOrGenericReleaseCodesButNotOnDrag(): void
    {
        $terminal = new FakeTerminal(20, 3);
        $openedUrls = [];
        $tui = new TuiAltScreen($terminal, options: new TuiAltScreenOptions(openUrl: static function (string $url) use (&$openedUrls): void {
            $openedUrls[] = $url;
        }));
        $url = 'https://example.com/path?q=1';
        $belUrl = 'https://example.com/bel';
        $emojiUrl = 'https://example.com/emoji';
        $tui->addChild(new Text(
            self::hyperlink('link', $url) . "\n\x1b]8;;{$belUrl}\x07link\x1b]8;;\x07\n" . self::hyperlink('🙂', $emojiUrl),
            0,
            0,
        ));
        $this->start($tui);

        $terminal->type("\x1b[<0;2;1M");
        $terminal->type("\x1b[<3;2;1m");
        self::settle();
        $this->assertSame([$url], $openedUrls);

        $terminal->type("\x1b[<0;2;2M");
        $terminal->type("\x1b[<0;2;2m");
        self::settle();
        $this->assertSame([$url, $belUrl], $openedUrls);

        $terminal->type("\x1b[<0;2;3M");
        $terminal->type("\x1b[<0;2;3m");
        self::settle();
        $this->assertSame([$url, $belUrl, $emojiUrl], $openedUrls);

        $terminal->type("\x1b[<0;2;1M");
        $terminal->type("\x1b[<32;4;1M");
        $terminal->type("\x1b[<0;4;1m");
        self::settle();
        $this->assertSame([$url, $belUrl, $emojiUrl], $openedUrls);
    }

    public function testDispatchesClicksToNestedMouseRegionsWithoutBreakingDragSelection(): void
    {
        $terminal = new FakeTerminal(20, 2);
        $tui = new TuiAltScreen($terminal);
        $clicks = 0;
        $tui->addChild(new MouseRegion(new Text("clickable\nselectable", 0, 0), static function (TuiMouseEvent $event) use (&$clicks): ?TuiMouseEventResult {
            if ($event->type !== 'click') {
                return null;
            }
            $clicks++;

            return new TuiMouseEventResult(handled: true);
        }));
        $this->start($tui);

        $terminal->type("\x1b[<0;2;1M");
        $terminal->type("\x1b[<0;2;1m");
        self::settle();
        $this->assertSame(1, $clicks);

        $terminal->type("\x1b[<0;1;1M");
        $terminal->type("\x1b[<32;4;2M");
        $terminal->type("\x1b[<0;4;2m");
        self::settle();
        $this->assertSame(1, $clicks);
        $this->assertTrue(self::wrote($terminal, "\x1b]52;c;"));
    }

    public function testFocusesAndCapturesDragGesturesForMouseAwareComponents(): void
    {
        $terminal = new FakeTerminal(20, 2);
        $tui = new TuiAltScreen($terminal);
        $component = self::mouseComponent(['control'], static fn (TuiMouseEvent $event): TuiMouseEventResult => $event->type === 'press'
            ? new TuiMouseEventResult(handled: true, capture: true, focus: true)
            : new TuiMouseEventResult(handled: true));
        $tui->addChild($component);
        $this->start($tui);

        $terminal->type("\x1b[<0;1;1M");
        $terminal->type("\x1b[<32;5;2M");
        $terminal->type("\x1b[<0;5;2m");
        self::settle();

        $this->assertSame(['press', 'drag', 'release'], $component->events);
        $this->assertSame($component, $tui->getFocusedComponent());
    }

    public function testReportsConsecutiveClickCountsToComponentOwnedControls(): void
    {
        $terminal = new FakeTerminal(20, 1);
        $tui = new TuiAltScreen($terminal);
        $clickCounts = [];
        $tui->addChild(self::mouseComponent(['control'], static function (TuiMouseEvent $event) use (&$clickCounts): ?TuiMouseEventResult {
            if ($event->type === 'press') {
                return new TuiMouseEventResult(handled: true);
            }
            if ($event->type === 'click') {
                $clickCounts[] = $event->clickCount ?? 0;

                return new TuiMouseEventResult(handled: true);
            }

            return null;
        }));
        $this->start($tui);

        for ($count = 0; $count < 3; $count++) {
            $terminal->type("\x1b[<0;1;1M");
            $terminal->type("\x1b[<0;1;1m");
        }
        self::settle();
        $this->assertSame([1, 2, 3], $clickCounts);
    }

    public function testDoesNotRerenderForHandledNoOpPointerMotion(): void
    {
        $terminal = new FakeTerminal(20, 2);
        $tui = new TuiAltScreen($terminal);
        $component = self::mouseComponent(['hover target'], static fn (TuiMouseEvent $event): ?TuiMouseEventResult => $event->type === 'move' ? new TuiMouseEventResult(handled: true) : null);
        $tui->addChild($component);
        $this->start($tui);
        $renderedBeforeMotion = $component->renders;
        $writesBeforeMotion = count($terminal->writes);

        $terminal->type("\x1b[<35;1;1M");
        self::settle();
        $this->assertSame($renderedBeforeMotion, $component->renders);
        $this->assertCount($writesBeforeMotion, $terminal->writes);
    }

    public function testLetsMouseAwareComponentsConsumeWheelEventsBeforeViewportScrolling(): void
    {
        $terminal = new FakeTerminal(20, 3);
        $tui = new TuiAltScreen($terminal);
        $wheelEvents = 0;
        $tui->addChild(new MouseRegion(new Text(self::lines(8), 0, 0), static function (TuiMouseEvent $event) use (&$wheelEvents): ?TuiMouseEventResult {
            if ($event->type !== 'wheel') {
                return null;
            }
            $wheelEvents++;

            return new TuiMouseEventResult(handled: true);
        }));
        $this->start($tui);
        $viewportTop = $tui->viewportTop();

        $terminal->type("\x1b[<64;1;1M");
        self::settle();
        $this->assertSame(1, $wheelEvents);
        $this->assertSame($viewportTop, $tui->viewportTop());
    }

    public function testGivesWheelAndViewportKeysToAFocusedOverlay(): void
    {
        $terminal = new FakeTerminal(20, 6);
        $tui = new TuiAltScreen($terminal);
        $tui->addChild(new Text(self::lines(12), 0, 0));
        $overlay = new FocusableOverlay(['overlay']);
        $this->start($tui);
        $topBefore = $tui->viewportTop();
        $handle = $tui->showOverlay($overlay);
        self::settle();
        $this->assertTrue($overlay->focused);

        $wheel = "\x1b[<64;10;3M";
        $keys = ["\x1b[5~", "\x1b[6~", "\x1bOH", "\x1bOF", $wheel];
        foreach ($keys as $key) {
            $terminal->type($key);
        }
        self::settle();

        $this->assertSame($keys, $overlay->inputs);
        $this->assertSame($topBefore, $tui->viewportTop());

        $handle->hide();
        self::settle();
        $terminal->type("\x1b[5~");
        self::settle();
        $this->assertLessThan($topBefore, $tui->viewportTop());
    }

    public function testKeepsViewportScrollingWhenAnOverlayIsNotFocused(): void
    {
        $terminal = new FakeTerminal(20, 6);
        $tui = new TuiAltScreen($terminal);
        $editor = new FocusableOverlay(['overlay']);
        $tui->addChild(new Text(self::lines(12), 0, 0));
        $tui->setFocus($editor);
        $this->start($tui);
        $topBefore = $tui->viewportTop();

        $hidden = $tui->showOverlay(new FocusableOverlay(['overlay']));
        $hidden->setHidden(true);
        $nonCapturing = new FocusableOverlay(['overlay']);
        $tui->showOverlay($nonCapturing, new OverlayOptions(nonCapturing: true));
        $unfocused = new FocusableOverlay(['overlay']);
        $unfocusedHandle = $tui->showOverlay($unfocused);
        $unfocusedHandle->unfocus();
        self::settle();
        $this->assertFalse($nonCapturing->focused);
        $this->assertFalse($unfocused->focused);

        $terminal->type("\x1b[5~");
        $terminal->type("\x1b[<64;10;3M");
        self::settle();
        $this->assertLessThan($topBefore, $tui->viewportTop());
        $this->assertSame([], $nonCapturing->inputs);
        $this->assertSame([], $unfocused->inputs);
    }

    /** Upstream's `hyperlink()` from `terminal-image.ts`: an OSC 8 link with ST terminators. */
    private static function hyperlink(string $text, string $url): string
    {
        return "\x1b]8;;{$url}\x1b\\{$text}\x1b]8;;\x1b\\";
    }

    /** @return list<string> the last frame, styling stripped, trailing blanks kept */
    private static function rawViewport(TuiAltScreen $tui): array
    {
        return array_map(static fn (string $line): string => Ansi::strip($line), $tui->getScreenLines());
    }

    /** JavaScript's `indexOf()` on a terminal row: the column a substring starts at, or -1. */
    private static function column(string $line, string $needle): int
    {
        $index = strpos($line, $needle);

        return $index === false ? -1 : Width::visible(substr($line, 0, $index));
    }

    /**
     * A plain component with a mouse handler, as upstream's object literals with `handleMouse`.
     *
     * @param list<string> $lines
     * @param Closure(TuiMouseEvent): ?TuiMouseEventResult $onMouse
     */
    private static function mouseComponent(array $lines, Closure $onMouse): Component&MouseHandler
    {
        return new class ($lines, $onMouse) implements Component, MouseHandler {
            /** @var list<string> */
            public array $events = [];

            public int $renders = 0;

            /** @param list<string> $lines */
            public function __construct(private readonly array $lines, private readonly Closure $onMouse)
            {
            }

            #[\Override]
            public function render(int $width): array
            {
                $this->renders++;

                return $this->lines;
            }

            #[\Override]
            public function invalidate(): void
            {
            }

            #[\Override]
            public function handleMouse(TuiMouseEvent $event): TuiMouseEventResult|TuiMouseDispatchResult|null
            {
                $this->events[] = $event->type;

                return ($this->onMouse)($event);
            }
        };
    }
}
