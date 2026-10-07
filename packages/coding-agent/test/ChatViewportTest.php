<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;
use Pig\CodingAgent\Interactive\ChatViewport;
use Pig\Tui\Ansi;
use Pig\Tui\Components\Text;
use Pig\Tui\Container;
use Pig\Tui\Layout;

final class ChatViewportTest extends TestCase
{
    /** Upstream's `chat-viewport.test.ts`. */
    public function testDefaultsTheTranscriptScrollbarToAutoAndAcceptsOverrides(): void
    {
        $automatic = ChatViewport::create(new Container(), new Container(), new Container(), new Container(), new Container());
        $hidden = ChatViewport::create(new Container(), new Container(), new Container(), new Container(), new Container(), scrollbar: 'hidden');

        $this->assertSame('auto', $automatic->transcript->scrollbar());
        $this->assertSame('hidden', $hidden->transcript->scrollbar());
    }

    public function testTheDockIsPinnedToTheBottomAndTheTranscriptGetsTheRest(): void
    {
        $document = new Container();
        for ($i = 1; $i <= 20; $i++) {
            $document->addChild(new Text("message {$i}", 0, 0));
        }

        $viewport = ChatViewport::create(
            $document,
            new Container(),
            new Container(),
            new Text("editor top\neditor prompt\neditor bottom", 0, 0),
            new Text('footer line', 0, 0),
            scrollbar: 'hidden',
        );

        $clean = fn (): array => array_map(
            static fn (string $line): string => trim(Ansi::strip($line)),
            Layout::renderLayoutFrame($viewport->root, 30, 8, static function (): void {
            })->lines,
        );

        $lines = $clean();
        $this->assertSame(['message 17', 'message 18', 'message 19', 'message 20', 'editor top', 'editor prompt', 'editor bottom', 'footer line'], $lines);
        $this->assertTrue($viewport->transcript->isFollowingEnd());
    }

    public function testATallDockShrinksButKeepsThreeEditorRowsAndOneTranscriptRow(): void
    {
        $pending = new Text(implode("\n", array_map(static fn (int $i): string => "queued {$i}", range(1, 10))), 0, 0);
        $viewport = ChatViewport::create(
            new Text("history", 0, 0),
            $pending,
            new Container(),
            new Text("e1\ne2\ne3\ne4", 0, 0),
            new Text('footer', 0, 0),
        );

        $lines = array_map(
            static fn (string $line): string => trim(Ansi::strip($line)),
            Layout::renderLayoutFrame($viewport->root, 30, 8, static function (): void {
            })->lines,
        );

        $this->assertCount(8, $lines);
        $this->assertContains('e1', $lines);
        $this->assertContains('e3', $lines);
        $this->assertSame('history', $lines[0]);
    }
}
