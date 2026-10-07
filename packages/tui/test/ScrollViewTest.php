<?php

declare(strict_types=1);

namespace Pig\Tui\Test;

use PHPUnit\Framework\TestCase;
use Pig\Tui\Ansi;
use Pig\Tui\Components\ScrollView;
use Pig\Tui\Components\Text;

final class ScrollViewTest extends TestCase
{
    public function testFollowsEndByDefault(): void
    {
        $content = new Text("line 1\nline 2\nline 3\nline 4\nline 5", 0, 0);
        $scroll = new ScrollView($content, followEnd: true);

        // Viewport of 3 lines: should show last 3 lines
        $visible = $scroll->renderViewport(20, 3);
        $clean = array_map(static fn (string $line): string => trim(Ansi::strip($line)), $visible);

        $this->assertSame(['line 3', 'line 4', 'line 5'], $clean);
        $this->assertTrue($scroll->isFollowingEnd());
    }

    public function testScrollingUpDetachesFromEndAndShowsIndicator(): void
    {
        $lines = array_map(static fn (int $i): string => "line {$i}", range(1, 20));
        $content = new Text(implode("\n", $lines), 0, 0);
        $scroll = new ScrollView($content, followEnd: true);

        // First render follows end (shows lines 16-20 for 5 lines height)
        $scroll->renderViewport(30, 5);
        $this->assertTrue($scroll->isFollowingEnd());

        // Scroll up by 5 lines
        $scroll->scrollBy(-5);
        $this->assertFalse($scroll->isFollowingEnd());

        $indicator = static fn (): string => " ↓ Jump to end · End ";
        $visible = $scroll->renderViewport(30, 5, $indicator);
        $clean = array_map(Ansi::strip(...), $visible);

        // Should now show lines 11-15, and on the last row should be the jump indicator
        $this->assertStringContainsString('line 11', $clean[0]);
        $this->assertStringContainsString('Jump to end · End', $clean[4]);

        // Scroll to bottom restores following
        $scroll->scrollToBottom();
        $this->assertTrue($scroll->isFollowingEnd());
        $visibleBottom = $scroll->renderViewport(30, 5, $indicator);
        $this->assertStringNotContainsString('Jump to end', implode("\n", $visibleBottom));
    }

    public function testContentShorterThanViewportIsPaddedTop(): void
    {
        $content = new Text("one\ntwo", 0, 0);
        $scroll = new ScrollView($content, followEnd: true);

        $visible = $scroll->renderViewport(20, 4);
        $clean = array_map(static fn (string $line): string => trim(Ansi::strip($line)), $visible);

        $this->assertSame(['', '', 'one', 'two'], $clean);
    }
}
