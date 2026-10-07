<?php

declare(strict_types=1);

namespace Pig\Tui\Test;

use PHPUnit\Framework\TestCase;
use Pig\Async\Loop;
use Pig\Tui\Components\ScrollView;
use Pig\Tui\Tui;

/**
 * The fullscreen renderer: upstream's `TuiAltScreen` — the frame, the wheel, and the selection
 * it owns because mouse reporting takes selection away from the terminal.
 */
final class TuiAltScreenTest extends TestCase
{
    private FakeTerminal $terminal;

    private Tui $tui;

    private ScrollView $transcript;

    private TextComponent $prompt;

    /** @var list<string> */
    private array $copied = [];

    #[\Override]
    protected function setUp(): void
    {
        Loop::reset();
        $this->terminal = new FakeTerminal(columns: 20, rows: 6);
        $this->tui = new Tui($this->terminal);
        $this->tui->setAltScreen(true);

        $lines = array_map(static fn (int $i): string => "line {$i}", range(1, 20));
        $this->transcript = new ScrollView(new TextComponent(implode("\n", $lines)));
        $this->prompt = new TextComponent('> prompt');
        $this->tui->addChild($this->prompt);
        $this->tui->setFocus($this->prompt);

        // Transcript on top, a two-line dock pinned under it, as ChatViewport lays them out.
        $this->tui->setViewportRenderer(fn (int $width, int $height): array => [
            ...$this->transcript->renderViewport($width, $height - 2),
            '> prompt',
            'footer',
        ]);
        $this->tui->setPrimaryScrollView($this->transcript);
        $this->tui->setCopySelection(function (string $text): bool {
            $this->copied[] = $text;

            return true;
        });
    }

    private function frame(): string
    {
        $this->terminal->clearWrites();
        Loop::get()->tick();

        return $this->terminal->output();
    }

    public function testEntersWithAutowrapOffAndMouseReportingOnAndRestoresBoth(): void
    {
        $this->tui->start();
        $start = $this->terminal->output();

        $this->assertStringContainsString("\x1b[?1049h\x1b[?7l", $start);
        $this->assertStringContainsString("\x1b[?1006h", $start);
        $this->assertStringContainsString("\x1b[?1004h", $start);

        $this->terminal->clearWrites();
        $this->tui->stop();
        $stop = $this->terminal->output();

        $this->assertStringContainsString("\x1b[?1006l", $stop);
        $this->assertStringContainsString("\x1b[?7h", $stop);
        $this->assertStringContainsString("\x1b[?1049l", $stop);
    }

    public function testEveryRowIsAddressedAndAHeightChangeRedrawsEverything(): void
    {
        $this->tui->start();
        $first = $this->frame();

        for ($row = 1; $row <= 6; $row++) {
            $this->assertStringContainsString("\x1b[{$row};1H", $first);
        }
        $this->assertStringNotContainsString("\x1b[7;1H", $first);

        // Same width, one row shorter: the dock must land on the new last rows.
        $this->terminal->resize(20, 5);
        $resized = $this->frame();

        $this->assertStringContainsString("\x1b[2J", $resized);
        $this->assertStringContainsString("\x1b[5;1H\x1b[2Kfooter", $resized);
    }

    public function testWheelReportsInsideOneReadScrollAndTheRestStillReachesTheFocusedComponent(): void
    {
        $this->tui->start();
        $this->frame();
        $before = $this->transcript->scrollTop();

        $this->terminal->type("\x1b[I\x1b[<64;1;1M\x1b[<64;1;1Mx");
        $this->frame();

        $this->assertSame($before - 2, $this->transcript->scrollTop());
        $this->assertFalse($this->transcript->isFollowingEnd());
        $this->assertSame(['x'], $this->prompt->typed);
    }

    public function testDraggingSelectsTranscriptTextHighlightsItAndCopiesOnRelease(): void
    {
        $this->tui->start();
        $this->frame();

        // Rows 0-3 show lines 17-20. Press on "line 17", drag to column 4 of "line 18".
        $this->terminal->type("\x1b[<0;1;1M\x1b[<32;5;2M");
        $dragged = $this->frame();
        $this->assertStringContainsString("\x1b[7m", $dragged);
        $this->assertTrue($this->tui->hasActiveSelection());

        $this->terminal->type("\x1b[<0;5;2m");
        $this->frame();

        $this->assertSame(["line 17\nline"], $this->copied);
        $this->assertSame([], $this->prompt->typed);
    }

    public function testDoubleClickSelectsTheWord(): void
    {
        $this->tui->start();
        $this->frame();

        $this->terminal->type("\x1b[<0;2;1M\x1b[<0;2;1m\x1b[<0;2;1M\x1b[<0;2;1m");
        $this->frame();

        $this->assertSame(['line'], array_slice($this->copied, -1));
    }

    public function testClickingWithoutDraggingCopiesNothing(): void
    {
        $this->tui->start();
        $this->frame();

        $this->terminal->type("\x1b[<0;3;2M\x1b[<0;3;2m");
        $this->frame();

        $this->assertSame([], $this->copied);
        $this->assertFalse($this->tui->hasActiveSelection());
    }
}
