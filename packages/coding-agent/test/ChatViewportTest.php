<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;
use Pig\CodingAgent\Interactive\ChatViewport;
use Pig\Tui\Ansi;
use Pig\Tui\Components\Text;
use Pig\Tui\Container;

final class ChatViewportTest extends TestCase
{
    public function testRendersDockPinnedToBottomAndTranscriptInRemainingHeight(): void
    {
        $transcript = new Container();
        for ($i = 1; $i <= 20; $i++) {
            $transcript->addChild(new Text("message {$i}", 0, 0));
        }

        $pending = new Container();
        $status = new Container();
        $overlay = new Container();
        $widgetsAbove = new Container();
        $editor = new Text("editor prompt", 0, 0);
        $widgetsBelow = new Container();
        $footer = new Text("footer line", 0, 0);

        $viewport = new ChatViewport(
            $transcript,
            $pending,
            $status,
            $overlay,
            $widgetsAbove,
            $editor,
            $widgetsBelow,
            $footer,
        );

        // Screen is 30 cols wide, 8 lines tall.
        // Dock has Spacer(1) + editor (1 line) + footer (1 line) = 3 lines.
        // Transcript gets 8 - 3 = 5 lines.
        $lines = $viewport->renderViewport(30, 8);
        $this->assertCount(8, $lines);

        $clean = array_map(static fn (string $l): string => trim(Ansi::strip($l)), $lines);

        // Bottom two lines must be editor and footer
        $this->assertSame('editor prompt', $clean[6]);
        $this->assertSame('footer line', $clean[7]);

        // Transcript shows the last 5 messages (16 to 20) by default
        $this->assertSame('message 16', $clean[0]);
        $this->assertSame('message 20', $clean[4]);

        // Scroll up
        $viewport->transcript()->scrollBy(-5);
        $this->assertFalse($viewport->transcript()->isFollowingEnd());

        $scrolledLines = $viewport->renderViewport(30, 8, static fn (): string => " ↓ Jump · End ");
        $cleanScrolled = array_map(static fn (string $l): string => trim(Ansi::strip($l)), $scrolledLines);

        // Dock is still pinned to bottom
        $this->assertSame('editor prompt', $cleanScrolled[6]);
        $this->assertSame('footer line', $cleanScrolled[7]);

        // Indicator appears at bottom of transcript viewport (line index 4)
        $this->assertStringContainsString('Jump · End', $cleanScrolled[4]);

        // Scroll back to bottom
        $viewport->transcript()->scrollToBottom();
        $this->assertTrue($viewport->transcript()->isFollowingEnd());
        $bottomLines = $viewport->renderViewport(30, 8, static fn (): string => " ↓ Jump · End ");
        $cleanBottom = array_map(static fn (string $l): string => trim(Ansi::strip($l)), $bottomLines);
        $this->assertStringNotContainsString('Jump · End', implode("\n", $cleanBottom));
    }

    public function testCaretOffsetIncludesViewportHeight(): void
    {
        $transcript = new Container();
        $transcript->addChild(new Text("line 1\nline 2", 0, 0));

        $pending = new Container();
        $status = new Container();
        $overlay = new Container();
        $widgetsAbove = new Container();
        $widgetsBelow = new Container();
        $footer = new Text("footer line", 0, 0);

        // Editor with caret support
        $editor = new class extends Text implements \Pig\Tui\Caret {
            public function __construct() { parent::__construct("prompt text", 0, 0); }
            public function caret(int $width): ?array { return [0, 4]; }
        };

        $viewport = new ChatViewport(
            $transcript,
            $pending,
            $status,
            $overlay,
            $widgetsAbove,
            $editor,
            $widgetsBelow,
            $footer,
        );

        // 10 height, dock is editor (1) + footer (1) = 2, so transcript gets 8
        $viewport->renderViewport(30, 10);

        $caret = $viewport->caret(30);
        $this->assertNotNull($caret);
        // Row is 8 (transcript) + 0 (editor row in dock) + 0 = 8
        $this->assertSame(8, $caret[0]);
        $this->assertSame(4, $caret[1]);
    }
}
