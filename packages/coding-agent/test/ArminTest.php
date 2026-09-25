<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pig\Async\Loop;
use Pig\CodingAgent\Interactive\ArminComponent;
use Pig\CodingAgent\Theme\Palette;
use Pig\Tui\Ansi;
use Pig\Tui\Test\FakeTerminal;
use Pig\Tui\Tui;
use Pig\Tui\Width;

/**
 * The easter egg, which is worth a test file for one reason that is not the joke: it is the only
 * thing in either tree that draws a bitmap with half-block characters, and it is where the
 * never-ending effect was found.
 */
final class ArminTest extends TestCase
{
    private Tui $tui;

    #[\Override]
    protected function setUp(): void
    {
        Loop::reset();
        $this->tui = new Tui(new FakeTerminal(80, 24));
    }

    private function armin(?string $effect = null): ArminComponent
    {
        return new ArminComponent($this->tui, Palette::dark(true), $effect);
    }

    /**
     * Run an effect to its end by hand.
     *
     * Not by ticking the loop: a frame is a real timer of a thirtieth of a second and `tick()`
     * waits it out, so `rain`'s hundred and fifty frames would be five seconds of test. The frames
     * are what is under test and the timer is `Loader`'s pattern, tested there.
     *
     * @return int the number of frames it took, or -1 if it never finished
     */
    private function play(ArminComponent $armin, int $limit = 100_000): int
    {
        // Disposed first, so nothing is also animating it underneath this.
        $armin->dispose();

        $tick = new \ReflectionMethod($armin, 'tick');

        for ($frame = 0; $frame < $limit; $frame++) {
            if ($tick->invoke($armin) === true) {
                return $frame;
            }
        }

        return -1;
    }

    private function drawn(ArminComponent $armin, int $width = 40): string
    {
        return implode("\n", array_map(Ansi::strip(...), $armin->render($width)));
    }

    // ---- the picture ---------------------------------------------------------------------

    public function testThePictureIsDrawnWithHalfBlocksAndIsNotEmpty(): void
    {
        $armin = $this->armin('scanline');
        $this->play($armin);

        $drawn = $this->drawn($armin);

        // Two pixel rows to a cell, so all four characters have to appear — a bitmap that came
        // out as only `█` and space would mean the two rows were read as one.
        foreach (['█', '▀', '▄'] as $block) {
            $this->assertStringContainsString($block, $drawn, $block);
        }

        $this->assertStringContainsString('ARMIN SAYS HI', $drawn);
    }

    public function testEveryLineIsExactlyTheWidthItWasGiven(): void
    {
        $armin = $this->armin('scanline');
        $this->play($armin);

        foreach ($armin->render(40) as $line) {
            // A line that is sometimes padded and sometimes not differs from itself, and the
            // renderer redraws it for nothing.
            $this->assertSame(40, Width::visible($line));
        }
    }

    public function testTheArtIsClippedRatherThanWrappedOnANarrowTerminal(): void
    {
        $armin = $this->armin('scanline');
        $this->play($armin);

        $lines = $armin->render(12);

        $this->assertCount(ArminComponent::displayHeight() + 1, $lines, 'and no extra rows');

        foreach ($lines as $line) {
            $this->assertSame(12, Width::visible($line));
        }
    }

    public function testThePictureIsTallerThanItIsWideInPixelsAndHalfThatInRows(): void
    {
        // 36 pixel rows packed two to a cell. Stated because the packing is the whole trick.
        $this->assertSame(18, ArminComponent::displayHeight());
    }

    // ---- the effects ----------------------------------------------------------------------

    /** @return list<array{0: string}> */
    public static function effects(): array
    {
        return array_map(static fn (string $effect): array => [$effect], ArminComponent::EFFECTS);
    }

    #[DataProvider('effects')]
    public function testEveryEffectEndsAndEndsOnTheSamePicture(string $effect): void
    {
        $armin = $this->armin($effect);
        $frames = $this->play($armin);

        // **`rain` did not, and pi's still does not.** A column is finished there when
        // `settled >= height`, and `settled` is `height - target`, so it only reaches `height`
        // for a column with ink in row 0 — four of these 31 columns. The other 27 can never
        // satisfy it, and since pi creates this component and never disposes of it, a
        // one-in-seven roll leaves a 30fps redraw running for the rest of the session.
        $this->assertGreaterThan(0, $frames, "{$effect} never finished");
        $this->assertLessThan(1_000, $frames, "{$effect} took an unreasonable number of frames");

        $wanted = $this->drawn($this->settled());

        $this->assertSame($wanted, $this->drawn($armin), "{$effect} ended somewhere else");
    }

    public function testAnUnknownEffectNameFallsBackToARealOne(): void
    {
        // Rather than animating nothing at all: the name is a test seam, and a typo in one should
        // not produce a blank easter egg.
        $armin = $this->armin('interpretive-dance');

        $this->assertGreaterThan(0, $this->play($armin));
        $this->assertStringContainsString('█', $this->drawn($armin));
    }

    // ---- the timer ------------------------------------------------------------------------

    public function testTheFramesStopOnTheirOwnAndDoNotHoldTheLoopOpen(): void
    {
        $armin = $this->armin('glitch');

        // Eight frames at sixty a second, so this is a tenth of a second of real time — the one
        // effect short enough to let the timer itself be tested.
        for ($tick = 0; $tick < 200 && !Loop::get()->isIdle(); $tick++) {
            Loop::get()->tick();
        }

        $this->assertTrue(Loop::get()->isIdle(), 'the animation ended and took its timer with it');
        $this->assertStringContainsString('ARMIN SAYS HI', $this->drawn($armin));
    }

    public function testDisposingMidAnimationLetsTheLoopSettle(): void
    {
        $armin = $this->armin('typewriter');

        Loop::get()->tick();
        $this->assertFalse(Loop::get()->isIdle(), 'still going');

        $armin->dispose();

        for ($tick = 0; $tick < 20 && !Loop::get()->isIdle(); $tick++) {
            Loop::get()->tick();
        }

        // A frame timer outliving the screen it drew on keeps the loop from ever going idle,
        // which is a `bin/pig` that does not exit.
        $this->assertTrue(Loop::get()->isIdle());
    }

    /** The finished picture, for comparing an effect's last frame against. */
    private function settled(): ArminComponent
    {
        $armin = $this->armin('scanline');
        $this->play($armin);

        return $armin;
    }
}
