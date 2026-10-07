<?php

declare(strict_types=1);

namespace Pig\Tui\Test;

use PHPUnit\Framework\TestCase;
use Pig\Async\Loop;
use Pig\Tui\Component;
use Pig\Tui\InputHandler;
use Pig\Tui\RgbColor;
use Pig\Tui\TerminalColors;
use Pig\Tui\TuiMainScreen;

/**
 * Upstream's `terminal-colors.test.ts`, plus the cases pig's batched reads need: replies arriving
 * together in one read, beside a keystroke, and cut across two reads.
 */
final class TerminalColorsTest extends TestCase
{
    private const string DA1 = "\x1b[?62;22c";

    #[\Override]
    protected function setUp(): void
    {
        Loop::reset();
    }

    /** @return list<string> */
    private static function paletteReplies(): array
    {
        return array_map(static fn (int $index): string => "\x1b]4;{$index};#000000\x07", range(0, 15));
    }

    /** @return array{0: FakeTerminal, 1: TuiMainScreen, 2: object} */
    private function setUpTui(): array
    {
        $terminal = new FakeTerminal(80, 24);
        $tui = new TuiMainScreen($terminal);
        $component = new class implements Component, InputHandler {
            /** @var list<string> */
            public array $inputs = [];

            #[\Override]
            public function render(int $width): array
            {
                return [];
            }

            #[\Override]
            public function handleInput(string $data): void
            {
                $this->inputs[] = $data;
            }

            #[\Override]
            public function invalidate(): void
            {
            }
        };
        $tui->addChild($component);
        $tui->setFocus($component);
        $tui->start();

        return [$terminal, $tui, $component];
    }

    public function testParsesColorSchemeReports(): void
    {
        $this->assertSame('dark', TerminalColors::parseTerminalColorSchemeReport("\x1b[?997;1n"));
        $this->assertSame('light', TerminalColors::parseTerminalColorSchemeReport("\x1b[?997;2n"));
        $this->assertSame('dark', TerminalColors::parseTerminalColorSchemeReport("\x1b[?997;2n\x1b[?997;1n\x1b[?997;1n"));
        $this->assertSame('light', TerminalColors::parseTerminalColorSchemeReport("\x1b[?997;1n\x1b[?997;2n\x1b[?997;2n"));
        $this->assertNull(TerminalColors::parseTerminalColorSchemeReport("\x1b[?997;3n"));
        $this->assertNull(TerminalColors::parseTerminalColorSchemeReport("\x1b[?996n"));
        $this->assertNull(TerminalColors::parseTerminalColorSchemeReport("x\x1b[?997;1n"));
    }

    public function testParsesOsc10And11And4Replies(): void
    {
        $this->assertEquals(['target' => 'foreground', 'rgb' => new RgbColor(255, 255, 255)], TerminalColors::parseOscColorResponse("\x1b]10;rgb:ffff/ffff/ffff\x07"));
        $this->assertEquals(['target' => 13, 'rgb' => new RgbColor(255, 0, 128)], TerminalColors::parseOscColorResponse("\x1b]4;13;#ff0080\x1b\\"));
        $this->assertEquals(['target' => 1, 'rgb' => null], TerminalColors::parseOscColorResponse("\x1b]4;1;bogus\x07"));
        $this->assertNull(TerminalColors::parseOscColorResponse("\x1b]12;#ffffff\x07"));
    }

    public function testQueriesAllColorsInOneWriteAndConsumesTheReplies(): void
    {
        [$terminal, $tui, $component] = $this->setUpTui();
        try {
            $terminal->clearWrites();
            $query = $tui->queryTerminalColors(1000);
            $written = $terminal->output();
            $this->assertStringStartsWith("\x1b]10;?\x07\x1b]11;?\x07\x1b]4;0;?\x07", $written);
            $this->assertStringEndsWith("\x1b[c", $written);

            $terminal->type('x');
            $terminal->type("\x1b]10;#ffffff\x07");
            $terminal->type("\x1b]11;rgb:0000/0000/0000\x1b\\");
            foreach (self::paletteReplies() as $reply) {
                $terminal->type($reply);
            }
            // Completes once every reply arrived, without waiting for DA1.
            $this->assertTrue($query->isComplete());
            $this->assertEquals(new TerminalColors(new RgbColor(255, 255, 255), new RgbColor(0, 0, 0), array_fill(0, 16, new RgbColor(0, 0, 0))), $query->await());
            $terminal->type(self::DA1);
            $this->assertSame(['x'], $component->inputs);
        } finally {
            $tui->stop();
        }
    }

    public function testCompletesOnDa1WithTheRepliesThatArrivedInQueryOrder(): void
    {
        [$terminal, $tui] = $this->setUpTui();
        try {
            $first = $tui->queryTerminalColors(1000);
            $second = $tui->queryTerminalColors(1000);
            $terminal->type("\x1b]11;#000000\x07");
            // An incomplete palette is dropped.
            foreach (array_slice(self::paletteReplies(), 0, 8) as $reply) {
                $terminal->type($reply);
            }
            $terminal->type(self::DA1);
            $terminal->type(self::DA1);

            $this->assertEquals(new TerminalColors(null, new RgbColor(0, 0, 0), null), $first->await());
            $this->assertEquals(new TerminalColors(), $second->await());
        } finally {
            $tui->stop();
        }
    }

    public function testReportsLateRepliesAfterATimeoutAndConsumesThemUntilDa1(): void
    {
        [$terminal, $tui, $component] = $this->setUpTui();
        try {
            $late = [];
            $query = $tui->queryTerminalColors(1, static function (TerminalColors $colors) use (&$late): void {
                $late[] = $colors;
            });
            $deadline = microtime(true) + 0.5;
            while (!$query->isComplete() && microtime(true) < $deadline) {
                Loop::get()->tick();
                usleep(1000);
            }
            $this->assertNull($query->await()->background);

            $terminal->type("\x1b]11;#ffffff\x07");
            $terminal->type(self::DA1);
            $this->assertEquals([new TerminalColors(null, new RgbColor(255, 255, 255), null)], $late);
            $this->assertSame([], $component->inputs);

            // With no query pending, color replies are ordinary input again.
            $terminal->type("\x1b]11;#ffffff\x07");
            $this->assertSame(["\x1b]11;#ffffff\x07"], $component->inputs);
        } finally {
            $tui->stop();
        }
    }

    public function testRepliesInOneReadBesideAKeystrokeAndCutAcrossReads(): void
    {
        // pig's reads are not split into sequences: every reply in one read is taken, the rest
        // goes on, and a reply cut at the end of a read waits for its tail.
        [$terminal, $tui, $component] = $this->setUpTui();
        try {
            $query = $tui->queryTerminalColors(1000);
            $all = "\x1b]10;#ffffff\x07\x1b]11;#000000\x07" . implode('', self::paletteReplies()) . self::DA1;
            $terminal->type('a' . substr($all, 0, 25));
            $terminal->type(substr($all, 25) . 'b');

            $this->assertTrue($query->isComplete());
            $this->assertEquals(new RgbColor(255, 255, 255), $query->await()->foreground);
            $this->assertCount(16, $query->await()->palette ?? []);
            $this->assertSame('ab', implode('', $component->inputs));
        } finally {
            $tui->stop();
        }
    }

    public function testColorSchemeReportsGoToListenersAndNotificationsAreTurnedOnAndOff(): void
    {
        [$terminal, $tui, $component] = $this->setUpTui();
        try {
            $schemes = [];
            $unsubscribe = $tui->onTerminalColorSchemeChange(static function (string $scheme) use (&$schemes): void {
                $schemes[] = $scheme;
            });
            $terminal->clearWrites();
            $tui->setTerminalColorSchemeNotifications(true);
            $this->assertSame("\x1b[?2031h", $terminal->output());

            $terminal->type("x\x1b[?997;2n");
            $this->assertSame(['light'], $schemes);
            $this->assertSame(['x'], $component->inputs);

            $unsubscribe();
            $terminal->type("\x1b[?997;1n");
            $this->assertSame(['light'], $schemes, 'unsubscribed');
            $this->assertSame(['x'], $component->inputs, 'still consumed');

            $terminal->clearWrites();
            $tui->stop();
            $this->assertStringContainsString("\x1b[?2031l", $terminal->output());
        } finally {
            $tui->stop();
        }
    }
}
