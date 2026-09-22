<?php

declare(strict_types=1);

namespace Pig\Tui\Test;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pig\Async\Loop;
use Pig\Tui\Components\Image;
use Pig\Tui\Images\Capabilities;
use Pig\Tui\Images\CellSize;
use Pig\Tui\Images\ImageDimensions;
use Pig\Tui\Images\ImageProtocol;
use Pig\Tui\Images\ImageSize;
use Pig\Tui\Images\TerminalImage;
use Pig\Tui\Tui;

final class ImageTest extends TestCase
{
    #[\Override]
    protected function setUp(): void
    {
        Loop::reset();
        // The environment this runs in must not decide what the tests see.
        TerminalImage::reset(new Capabilities(null, false, true));
    }

    #[\Override]
    protected function tearDown(): void
    {
        TerminalImage::reset();
    }

    private function drawsWith(?ImageProtocol $protocol): void
    {
        TerminalImage::reset(new Capabilities($protocol, true, true));
    }

    // ---- reading headers -----------------------------------------------------------

    /** A 3×2 PNG, built by hand: the signature, then an IHDR carrying the size. */
    private static function png(int $width = 3, int $height = 2): string
    {
        return base64_encode("\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . pack('NN', $width, $height) . "\x08\x06\x00\x00\x00");
    }

    private static function gif(int $width = 7, int $height = 5): string
    {
        return base64_encode('GIF89a' . pack('vv', $width, $height) . "\x00\x00");
    }

    private static function jpeg(int $width = 11, int $height = 13): string
    {
        // SOI, a segment to skip over, then the frame header the size lives in.
        $comment = "\xff\xfe" . pack('n', 4) . 'ab';
        $frame = "\xff\xc0" . pack('n', 17) . "\x08" . pack('nn', $height, $width) . str_repeat("\x00", 10);

        return base64_encode("\xff\xd8" . $comment . $frame);
    }

    private static function webpLossy(int $width = 17, int $height = 19): string
    {
        $body = 'WEBP' . 'VP8 ' . str_repeat("\x00", 10) . pack('vv', $width, $height) . "\x00\x00";

        return base64_encode('RIFF' . pack('V', strlen($body)) . $body);
    }

    private static function webpExtended(int $width = 100, int $height = 50): string
    {
        // The extended form stores size minus one, in three bytes each.
        $minusOne = static fn (int $n): string => substr(pack('V', $n - 1), 0, 3);
        $body = 'WEBP' . 'VP8X' . str_repeat("\x00", 8) . $minusOne($width) . $minusOne($height);

        return base64_encode('RIFF' . pack('V', strlen($body)) . $body);
    }

    public function testPngHeader(): void
    {
        $size = ImageDimensions::of(self::png(320, 240), 'image/png');

        $this->assertSame(320, $size?->widthPx);
        $this->assertSame(240, $size?->heightPx);
    }

    public function testGifHeader(): void
    {
        $size = ImageDimensions::of(self::gif(64, 48), 'image/gif');

        $this->assertSame(64, $size?->widthPx);
        $this->assertSame(48, $size?->heightPx);
    }

    public function testJpegHeaderIsFoundAfterSkippingSegments(): void
    {
        $size = ImageDimensions::of(self::jpeg(800, 600), 'image/jpeg');

        // The frame header puts height before width, unlike everything else here.
        $this->assertSame(800, $size?->widthPx);
        $this->assertSame(600, $size?->heightPx);
    }

    public function testWebpInItsTwoCommonShapes(): void
    {
        $lossy = ImageDimensions::of(self::webpLossy(200, 100), 'image/webp');
        $this->assertSame(200, $lossy?->widthPx);
        $this->assertSame(100, $lossy?->heightPx);

        $extended = ImageDimensions::of(self::webpExtended(1024, 768), 'image/webp');
        $this->assertSame(1024, $extended?->widthPx);
        $this->assertSame(768, $extended?->heightPx);
    }

    /** @return list<array{string, string, string}> */
    public static function misses(): array
    {
        return [
            [base64_encode('not an image at all'), 'image/png', 'wrong magic bytes'],
            [base64_encode("\x89PNG"), 'image/png', 'truncated before the size'],
            ['!!!not base64!!!', 'image/png', 'not base64'],
            [self::png(), 'image/tiff', 'a format with no reader here'],
            [base64_encode("\xff\xd8\xff\xd9"), 'image/jpeg', 'a JPEG with no frame header'],
        ];
    }

    #[DataProvider('misses')]
    public function testAnUnreadableHeaderIsAMissNotACrash(string $base64, string $mime, string $why): void
    {
        $this->assertNull(ImageDimensions::of($base64, $mime), $why);
    }

    // ---- sizing --------------------------------------------------------------------

    public function testRowsFollowFromTheCellSize(): void
    {
        // 40 cells of 9px is 360px wide; a 720×360 image halves to 180px tall, which is
        // ten rows of 18px.
        $rows = TerminalImage::rows(new ImageSize(720, 360), 40, new CellSize(9, 18));

        $this->assertSame(10, $rows);
    }

    public function testATinyImageStillGetsARow(): void
    {
        // Zero rows would put the renderer's idea of the cursor out by one for good.
        $this->assertSame(1, TerminalImage::rows(new ImageSize(1000, 1), 10, new CellSize(9, 18)));
    }

    public function testTheCellSizeReplyIsRead(): void
    {
        $size = TerminalImage::parseCellSizeReply("\x1b[6;20;10t");

        // The reply is height then width, the opposite of every other pair.
        $this->assertSame(10, $size?->widthPx);
        $this->assertSame(20, $size?->heightPx);

        $this->assertNull(TerminalImage::parseCellSizeReply("\x1b[6;0;0t"));
        $this->assertNull(TerminalImage::parseCellSizeReply('x'));
    }

    // ---- encoding ------------------------------------------------------------------

    public function testAShortKittyPayloadIsOneSequence(): void
    {
        $sequence = TerminalImage::kitty('AAAA', columns: 20, rows: 5);

        $this->assertSame("\x1b_Ga=T,f=100,q=2,c=20,r=5;AAAA\x1b\\", $sequence);
    }

    public function testALongKittyPayloadIsChunkedWithContinuationFlags(): void
    {
        $sequence = TerminalImage::kitty(str_repeat('A', 9000));

        // Three pieces: the first carries the parameters, the last says the data ended.
        $this->assertSame(3, substr_count($sequence, "\x1b_G"));
        $this->assertStringContainsString('a=T,f=100,q=2,m=1;', $sequence);
        $this->assertStringContainsString("\x1b_Gm=0;", $sequence);
        $this->assertSame(1, substr_count($sequence, 'm=0'));
    }

    public function testKittyAsksTheTerminalNotToReply(): void
    {
        // Without q=2 the acknowledgement arrives as keystrokes.
        $this->assertStringContainsString('q=2', TerminalImage::kitty('AAAA'));
    }

    public function testITerm2Encoding(): void
    {
        $sequence = TerminalImage::iterm2('AAAA', width: 40, height: 'auto', name: 'cat.png');

        $this->assertStringStartsWith("\x1b]1337;File=inline=1;width=40;height=auto;", $sequence);
        $this->assertStringContainsString('name=' . base64_encode('cat.png'), $sequence);
        $this->assertStringEndsWith(":AAAA\x07", $sequence);
    }

    public function testITerm2CanBeToldNotToKeepTheAspectRatio(): void
    {
        $this->assertStringContainsString(
            'preserveAspectRatio=0',
            TerminalImage::iterm2('AAAA', preserveAspectRatio: false),
        );
    }

    public function testRenderPicksTheProtocolTheTerminalSpeaks(): void
    {
        $this->drawsWith(ImageProtocol::Kitty);
        $this->assertStringStartsWith("\x1b_G", TerminalImage::render('AAAA', new ImageSize(10, 10))[0]);

        $this->drawsWith(ImageProtocol::ITerm2);
        $this->assertStringStartsWith("\x1b]1337;", TerminalImage::render('AAAA', new ImageSize(10, 10))[0]);

        $this->drawsWith(null);
        $this->assertNull(TerminalImage::render('AAAA', new ImageSize(10, 10)));
    }

    // ---- the component -------------------------------------------------------------

    public function testWithoutImageSupportTheComponentSaysWhatItWouldHaveDrawn(): void
    {
        $image = new Image(self::png(320, 240), 'image/png', filename: 'cat.png');

        $lines = $image->render(40);

        $this->assertCount(1, $lines);
        $this->assertStringContainsString('cat.png', $lines[0]);
        $this->assertStringContainsString('320x240', $lines[0]);
    }

    public function testAnUnreadableHeaderFallsBackToAnAssumedSize(): void
    {
        $image = new Image(base64_encode('rubbish'), 'image/png');

        $this->assertSame(800, $image->size()->widthPx);
        $this->assertSame(600, $image->size()->heightPx);
    }

    public function testTheComponentReturnsOneLinePerRowItWillOccupy(): void
    {
        $this->drawsWith(ImageProtocol::Kitty);
        TerminalImage::setCellSize(new CellSize(10, 20));

        // 30 cells of 10px is 300px; a 300×400 image is 400px tall, which is 20 rows.
        $image = new Image(self::png(300, 400), 'image/png', maxWidthCells: 30);
        $lines = $image->render(40);

        $this->assertCount(20, $lines);

        foreach (array_slice($lines, 0, -1) as $line) {
            $this->assertSame('', $line);
        }
    }

    public function testTheLastLineMovesTheCursorBackUpBeforeDrawing(): void
    {
        $this->drawsWith(ImageProtocol::Kitty);
        TerminalImage::setCellSize(new CellSize(10, 20));

        $lines = (new Image(self::png(300, 400), 'image/png', maxWidthCells: 30))->render(40);
        $last = $lines[count($lines) - 1];

        // The picture grows downwards from the cursor, so the cursor goes back to the top
        // of the block first and the picture fills exactly the rows already accounted for.
        $this->assertStringStartsWith("\x1b[19A\x1b_G", $last);
    }

    public function testAOneRowImageNeedsNoCursorMove(): void
    {
        $this->drawsWith(ImageProtocol::Kitty);
        TerminalImage::setCellSize(new CellSize(10, 20));

        $lines = (new Image(self::png(1000, 1), 'image/png', maxWidthCells: 10))->render(40);

        $this->assertCount(1, $lines);
        $this->assertStringStartsWith("\x1b_G", $lines[0]);
    }

    public function testTheImageIsNeverWiderThanTheTerminalAllows(): void
    {
        $this->drawsWith(ImageProtocol::ITerm2);

        $lines = (new Image(self::png(1000, 1000), 'image/png', maxWidthCells: 60))->render(20);

        // Twenty columns less the margin, not the sixty it was allowed.
        $this->assertStringContainsString('width=18;', implode('', $lines));
    }

    public function testTheRendererDoesNotMeasureAnImageLine(): void
    {
        $this->drawsWith(ImageProtocol::Kitty);
        $terminal = new FakeTerminal(columns: 20, rows: 10);
        $tui = new Tui($terminal);
        $tui->addChild(new Image(self::png(300, 400), 'image/png', maxWidthCells: 10));
        $tui->start();

        // An image line is tens of kilobytes long and zero columns wide; measuring it
        // would fail the width check and stop the program.
        Loop::get()->tick();

        $this->assertStringContainsString("\x1b_G", $terminal->output());
    }

    // ---- capabilities --------------------------------------------------------------

    public function testTheCellSizeQueryOnlyGoesOutWhenItCouldBeUseful(): void
    {
        $this->drawsWith(null);
        $plain = new FakeTerminal();
        (new Tui($plain))->start();
        $this->assertStringNotContainsString("\x1b[16t", $plain->output());

        Loop::reset();
        $this->drawsWith(ImageProtocol::Kitty);
        $graphical = new FakeTerminal();
        (new Tui($graphical))->start();
        $this->assertStringContainsString("\x1b[16t", $graphical->output());
    }

    public function testTheReplyIsTakenOutOfTheInputAndTheRestStillArrives(): void
    {
        $this->drawsWith(ImageProtocol::Kitty);
        $terminal = new FakeTerminal();
        $tui = new Tui($terminal);
        $typed = new TextComponent('x');
        $tui->addChild($typed);
        $tui->start();
        $tui->setFocus($typed);

        $terminal->type("\x1b[6;20;10ta");

        $this->assertSame(10, TerminalImage::cellSize()->widthPx);
        // The reply is consumed; what the user typed alongside it is not.
        $this->assertSame(['a'], $typed->typed);
    }

    public function testATerminalThatNeverAnswersDoesNotSwallowTyping(): void
    {
        $this->drawsWith(ImageProtocol::Kitty);
        $terminal = new FakeTerminal();
        $tui = new Tui($terminal);
        $typed = new TextComponent('x');
        $tui->addChild($typed);
        $tui->start();
        $tui->setFocus($typed);

        $terminal->type('hello');

        $this->assertSame(['hello'], $typed->typed);
    }
}
