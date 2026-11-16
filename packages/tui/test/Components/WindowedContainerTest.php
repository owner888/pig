<?php

declare(strict_types=1);

namespace Pig\Tui\Test\Components;

use PHPUnit\Framework\TestCase;
use Pig\Async\Loop;
use Pig\Tui\Ansi;
use Pig\Tui\Component;
use Pig\Tui\Components\ScrollView;
use Pig\Tui\Components\WindowedContainer;
use Pig\Tui\Container;
use Pig\Tui\Keybindings;
use Pig\Tui\Layout;
use Pig\Tui\Test\FakeTerminal;
use Pig\Tui\TextWrap;
use Pig\Tui\TuiAltScreen;

/**
 * pig's own: at a new width only what is in view is wrapped again at once, the rest afterwards.
 * `docs/dev/decisions.md`, "A resize re-wraps what is on screen first".
 */
final class WindowedContainerTest extends TestCase
{
    #[\Override]
    protected function setUp(): void
    {
        Loop::reset();
        Keybindings::reset();
    }

    /** Forty words that wrap differently at every width, and a count of how often it was asked. */
    private static function paragraph(int $number, array &$renders): Component
    {
        return new class($number, $renders) implements Component {
            public function __construct(private readonly int $number, private array &$renders)
            {
            }

            #[\Override]
            public function render(int $width): array
            {
                $this->renders[] = [$this->number, $width];

                return TextWrap::wrap("#{$this->number} " . implode(' ', array_fill(0, 40, "word{$this->number}")), $width);
            }

            #[\Override]
            public function invalidate(): void
            {
            }
        };
    }

    /** @return array{0: WindowedContainer, 1: Container} the same hundred paragraphs in each */
    private static function pair(array &$renders, int $count = 100): array
    {
        $ignored = [];
        $windowed = new WindowedContainer();
        $plain = new Container();

        for ($number = 1; $number <= $count; $number++) {
            $windowed->addChild(self::paragraph($number, $renders));
            $plain->addChild(self::paragraph($number, $ignored));
        }

        return [$windowed, $plain];
    }

    /** @return list<int> which paragraphs were rendered, in the order they were asked */
    private static function asked(array $renders): array
    {
        return array_column($renders, 0);
    }

    public function testRenderIsEveryChildInOrderAsAContainerDoesIt(): void
    {
        $renders = [];
        [$windowed, $plain] = self::pair($renders);

        $this->assertSame($plain->render(60), $windowed->render(60));
        $this->assertSame(range(1, 100), self::asked($renders));
        $this->assertSame(0, $windowed->staleCount());

        // And again at another width: `render()` never leaves anything behind.
        $this->assertSame($plain->render(33), $windowed->render(33));
        $this->assertSame(0, $windowed->staleCount());
    }

    public function testAtANewWidthTheEndIsDrawnAndTheRestKeepsItsPlace(): void
    {
        $renders = [];
        [$windowed, $plain] = self::pair($renders);
        $before = $windowed->render(60);
        $renders = [];

        $lines = $windowed->renderWindow(40, null, 20);
        $full = $plain->render(40);

        $this->assertSame(array_slice($full, -20), array_slice($lines, -20), 'the rows in view are the ones a full render gives');
        $this->assertLessThan(8, count($renders), 'a hundred paragraphs, a handful drawn: ' . implode(',', self::asked($renders)));
        $this->assertSame(100 - count($renders), $windowed->staleCount());
        $this->assertSame(array_slice($before, 0, 50), array_slice($lines, 0, 50), 'the rest is what was drawn before');
    }

    public function testAtAWiderWidthTheRowsAreFilledWithWhatWasDrawnNow(): void
    {
        $renders = [];
        [$windowed, $plain] = self::pair($renders);
        $windowed->render(20);

        // Six times as wide: every paragraph is a sixth as tall, so the rows that held one hold six.
        $lines = $windowed->renderWindow(120, null, 30);
        $this->assertSame(array_slice($plain->render(120), -30), array_slice($lines, -30));

        $top = 40;
        $lines = $windowed->renderWindow(200, $top, 30);
        $fresh = array_slice($lines, $top, 30);

        foreach ($fresh as $line) {
            $this->assertLessThanOrEqual(200, mb_strlen($line));
        }

        $this->assertContains(200, array_map(static fn (string $line): int => mb_strlen(rtrim($line)) > 120 ? 200 : 0, $fresh), 'rows in view are wrapped at 200 columns, not left at 20 or 120');
    }

    public function testAScrolledUpViewKeepsItsRowsAndGetsThemAtTheNewWidth(): void
    {
        $renders = [];
        [$windowed] = self::pair($renders);
        $before = $windowed->render(60);
        // Row 200 is where some paragraph starts or continues; find the one it belongs to.
        $heights = array_map(static fn (int $n): int => count(TextWrap::wrap("#{$n} " . implode(' ', array_fill(0, 40, "word{$n}")), 60)), range(1, 100));
        $y = 0;
        $first = 0;

        foreach ($heights as $index => $height) {
            if ($y + $height > 200) {
                $first = $index + 1;
                break;
            }

            $y += $height;
        }

        $renders = [];
        $lines = $windowed->renderWindow(50, 200, 10);

        $this->assertSame($first, self::asked($renders)[0], 'drawing starts at the paragraph the first row in view belongs to');
        $this->assertSame(array_slice($before, 0, $y), array_slice($lines, 0, $y), 'what lies above is untouched, so the same paragraph is still at the top of the view');
        $this->assertStringContainsString("word{$first}", $lines[200]);
    }

    public function testRefineBringsTheRestUpToDateAndSaysHowFarTheViewMoved(): void
    {
        $renders = [];
        [$windowed, $plain] = self::pair($renders);
        $windowed->render(60);
        $lines = $windowed->renderWindow(30, 300, 10);
        $this->assertGreaterThan(80, $windowed->staleCount());

        // Paragraphs wholly above row 300 were 60 wide and are now 30: about twice as tall.
        $above = 0;
        $turns = 0;

        while ($windowed->staleCount() > 0) {
            $above += $windowed->refine(0.0, 300);
            $this->assertLessThan(200, ++$turns, 'each turn draws at least one child');
        }

        $this->assertGreaterThan(1, $turns, 'a budget of nothing is still one child a turn, not all of them');
        $full = $plain->render(30);
        $after = $windowed->renderWindow(30, 300 + $above, 10);
        $this->assertSame($full, $after, 'refined, it is a full render');
        $this->assertGreaterThan(200, $above);
        $this->assertSame(array_slice($lines, 300, 5), array_slice($after, 300 + $above, 5), 'moved by that much, the view shows the rows it showed');
    }

    public function testAContainerInsideOneIsAskedForItsShareOfTheRows(): void
    {
        $renders = [];
        $header = [];
        [$chat, $plainChat] = self::pair($renders);
        $document = new WindowedContainer();
        $document->addChild(self::paragraph(900, $header));
        $document->addChild($chat);
        $plain = new Container();
        $plain->addChild(self::paragraph(900, $header));
        $plain->addChild($plainChat);

        $document->render(60);
        $renders = [];
        $header = [];
        $lines = $document->renderWindow(40, null, 20);

        $this->assertSame(array_slice($plain->render(40), -20), array_slice($lines, -20));
        $this->assertLessThan(8, count($renders));
        $this->assertSame([[900, 40]], $header, 'only the plain container drew the header at the new width');
        $this->assertGreaterThan(90, $document->staleCount(), 'the header and most of the chat');

        while ($document->staleCount() > 0) {
            $document->refine(0.005, null);
        }

        $this->assertSame($plain->render(40), $document->renderWindow(40, null, 20));
    }

    public function testAChildAddedSinceIsDrawnWhereverItIs(): void
    {
        $renders = [];
        [$windowed] = self::pair($renders, 10);
        $windowed->render(60);
        $late = [];
        $windowed->addChild(self::paragraph(500, $late));
        $more = [];

        for ($number = 600; $number < 640; $number++) {
            $windowed->addChild(self::paragraph($number, $more));
        }

        // Nothing stands in for a child that was never drawn, in view or not.
        $lines = $windowed->renderWindow(60, null, 5);
        $this->assertSame([[500, 60]], $late);
        $this->assertContains('#500 word500 word500 word500 word500 word500 word500 word500', array_map('rtrim', $lines));
    }

    /** The frame the layout paints is the frame a plain container gives, following the end or scrolled up. */
    public function testTheLayoutPaintsTheSameFrameAsWithAPlainContainer(): void
    {
        $renders = [];
        [$windowed, $plain] = self::pair($renders);
        $a = new ScrollView($windowed, follow: 'end', primary: true);
        $b = new ScrollView($plain, follow: 'end', primary: true);
        $none = static function (): void {
        };
        $frame = static fn (ScrollView $view, int $width): array => Layout::renderLayoutFrame($view, $width, 12, $none)->lines;

        $this->assertSame($frame($b, 60), $frame($a, 60));
        $renders = [];
        $this->assertSame($frame($b, 44), $frame($a, 44), 'following the end, at a new width');
        $this->assertLessThan(8, count($renders));

        // Scrolled up. The plain container re-wraps what is above the view, so the same row
        // number is other text there; the windowed one is compared with itself once refined.
        $windowed->refine(1.0, null);
        $frame($a, 44);
        $a->scrollTo(150, disableFollow: true);
        $b->scrollTo(150, disableFollow: true);
        $this->assertSame($frame($b, 44), $frame($a, 44));

        $shown = $frame($a, 70);
        $this->assertSame(150, $a->scrollTop());
        $a->shiftBy($windowed->refine(1.0, $a->scrollTop()));
        $this->assertSame(0, $windowed->staleCount());
        $this->assertSame($shown, $frame($a, 70), 'refining what is above moves the view with it');

        // Far wider, the content is shorter than the scroll position: the end is what is shown.
        $a->scrollTo(10_000, disableFollow: true);
        $b->scrollTo(10_000, disableFollow: true);
        $frame($a, 20);
        $frame($b, 20);
        $a->scrollTo($a->scrollTop() - 1, disableFollow: true);
        $b->scrollTo($b->scrollTop() - 1, disableFollow: true);
        $this->assertSame(array_slice($frame($b, 200), -12), array_slice($frame($a, 200), -12));
    }

    public function testTheFullscreenRendererRefinesOnceTheWidthHasSettled(): void
    {
        $renders = [];
        [$windowed, $plain] = self::pair($renders);
        $terminal = new FakeTerminal(60, 12);
        $tui = new TuiAltScreen($terminal);
        $tui->setLayoutRoot(new ScrollView($windowed, follow: 'end', primary: true));
        $tui->start();

        try {
            self::settle(0.05);
            $renders = [];

            // A drag: a width a frame.
            foreach ([58, 56, 54, 52, 50] as $columns) {
                $terminal->resize($columns, 12);
                self::settle(0.03);
            }

            $this->assertLessThan(60, count($renders), 'five frames drew what five viewports hold, not five hundred paragraphs');
            $this->assertGreaterThan(80, $windowed->staleCount(), 'nothing is refined while the width is still changing');
            $this->assertSame(array_map('rtrim', array_slice($plain->render(50), -12)), array_map(static fn (string $line): string => rtrim(Ansi::strip($line)), $tui->getScreenLines()));

            self::settle(0.5);
            $this->assertSame(0, $windowed->staleCount());
            $this->assertSame($plain->render(50), $windowed->renderWindow(50, null, 12));
        } finally {
            $tui->stop();
        }
    }

    private static function settle(float $seconds): void
    {
        $deadline = microtime(true) + $seconds;

        do {
            Loop::get()->defer(static function (): void {
                Loop::get()->defer(static function (): void {
                });
            });
            Loop::get()->tick();
        } while (microtime(true) < $deadline);
    }
}
