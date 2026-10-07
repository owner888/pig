<?php

declare(strict_types=1);

namespace Pig\Tui\Test;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pig\Async\Loop;
use Pig\Tui\Components\Image;
use Pig\Tui\Components\ImageOptions;
use Pig\Tui\Components\ImageTheme;
use Pig\Tui\Images\CellDimensions;
use Pig\Tui\Images\ImageDimensions;
use Pig\Tui\Images\ImageProtocol;
use Pig\Tui\Images\TerminalCapabilities;
use Pig\Tui\Images\TerminalImage;
use Pig\Tui\TuiMainScreen;

/**
 * pig's own image cases: the header readers, the guards pig keeps over upstream, and the
 * cell-size query that pig's unsplit input has to sift for. Upstream's `terminal-image.test.ts`
 * is `TerminalImageTest`.
 */
final class ImageTest extends TestCase
{
    #[\Override]
    protected function setUp(): void
    {
        Loop::reset();
        // The environment this runs in must not decide what the tests see.
        TerminalImage::setCapabilities(new TerminalCapabilities(null, false, false));
        TerminalImage::setCellDimensions(new CellDimensions(9, 18));
    }

    #[\Override]
    protected function tearDown(): void
    {
        TerminalImage::resetCapabilitiesCache();
        TerminalImage::setCellDimensions(new CellDimensions(9, 18));
    }

    private function drawsWith(?ImageProtocol $protocol): void
    {
        TerminalImage::setCapabilities(new TerminalCapabilities($protocol, true, true));
    }

    private static function image(string $base64, string $mimeType, ImageOptions $options = new ImageOptions()): Image
    {
        return new Image($base64, $mimeType, new ImageTheme(static fn (string $text): string => $text), $options);
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
        $size = TerminalImage::getImageDimensions(self::png(320, 240), 'image/png');

        $this->assertSame(320, $size?->widthPx);
        $this->assertSame(240, $size?->heightPx);
    }

    public function testGifHeader(): void
    {
        $size = TerminalImage::getImageDimensions(self::gif(64, 48), 'image/gif');

        $this->assertSame(64, $size?->widthPx);
        $this->assertSame(48, $size?->heightPx);
    }

    public function testJpegHeaderIsFoundAfterSkippingSegments(): void
    {
        $size = TerminalImage::getImageDimensions(self::jpeg(800, 600), 'image/jpeg');

        // The frame header puts height before width, unlike everything else here.
        $this->assertSame(800, $size?->widthPx);
        $this->assertSame(600, $size?->heightPx);
    }

    public function testWebpInItsTwoCommonShapes(): void
    {
        $lossy = TerminalImage::getImageDimensions(self::webpLossy(200, 100), 'image/webp');
        $this->assertSame(200, $lossy?->widthPx);
        $this->assertSame(100, $lossy?->heightPx);

        $extended = TerminalImage::getImageDimensions(self::webpExtended(1024, 768), 'image/webp');
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
            [base64_encode("\xc7IF87a\x01\x00\x01\x00"), 'image/gif', 'one byte off a GIF signature'],
        ];
    }

    #[DataProvider('misses')]
    public function testAnUnreadableHeaderIsAMissNotACrash(string $base64, string $mime, string $why): void
    {
        $this->assertNull(TerminalImage::getImageDimensions($base64, $mime), $why);
    }

    public function testAHeaderSayingZeroIsNotASize(): void
    {
        // A zero cannot be scaled to a width, and what it produced was a picture 0 wide and 20480
        // tall asking the renderer for 150,000 lines. The bytes come from outside: a model's
        // image, a hook's screenshot, a custom tool. So a header that says zero is read as no
        // header at all, and the component falls back to the size it assumes for one it could
        // not read.
        $this->assertNull(TerminalImage::getPngDimensions(self::png(0, 20480)));
        $this->assertNull(TerminalImage::getPngDimensions(self::png(0, 0)));
        $this->assertNull(TerminalImage::getPngDimensions(self::png(100, 0)));
        $this->assertNotNull(TerminalImage::getPngDimensions(self::png(1, 1)));

        $this->assertSame(['[Image: [image/png] 800x600]'], self::image(self::png(0, 20480), 'image/png')->render(80));
    }

    // ---- sizing --------------------------------------------------------------------

    public function testRowsFollowFromTheCellSize(): void
    {
        // 40 cells of 9px is 360px wide; a 720×360 image halves to 180px tall, which is
        // ten rows of 18px.
        $this->assertSame(10, TerminalImage::calculateImageRows(new ImageDimensions(720, 360), 40, new CellDimensions(9, 18)));
    }

    public function testATinyImageStillGetsARow(): void
    {
        // Zero rows would put the renderer's idea of the cursor out by one for good.
        $this->assertSame(1, TerminalImage::calculateImageRows(new ImageDimensions(1000, 1), 10, new CellDimensions(9, 18)));
    }

    public function testACellSizeOfZeroIsNotDividedBy(): void
    {
        // Upstream clamps the image's size to a pixel and divides by the cell size as given. A
        // cell size of zero is something `parseCellSizeReply()` refuses but a caller can
        // construct, and dividing by it is a `DivisionByZeroError` out of a render.
        $this->assertSame(40, TerminalImage::calculateImageRows(new ImageDimensions(0, 0), 80, new CellDimensions(9, 18)));
        $this->assertGreaterThan(0, TerminalImage::calculateImageRows(new ImageDimensions(10, 10), 80, new CellDimensions(9, 0)));
        $this->assertGreaterThan(0, TerminalImage::calculateImageRows(new ImageDimensions(10, 10), 80, new CellDimensions(0, 18)));
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
        $this->assertSame("\x1b_Ga=T,f=100,q=2,c=20,r=5;AAAA\x1b\\", TerminalImage::encodeKitty('AAAA', columns: 20, rows: 5));
    }

    public function testALongKittyPayloadIsChunkedWithContinuationFlags(): void
    {
        $sequence = TerminalImage::encodeKitty(str_repeat('A', 9000));

        // Three pieces: the first carries the parameters, the last says the data ended.
        $this->assertSame(3, substr_count($sequence, "\x1b_G"));
        $this->assertStringContainsString('a=T,f=100,q=2,m=1;', $sequence);
        $this->assertStringContainsString("\x1b_Gm=0;", $sequence);
        $this->assertSame(1, substr_count($sequence, 'm=0'));
    }

    public function testASizeThatIsNotASizeIsLeftOutRatherThanSentAsOne(): void
    {
        // JavaScript's `if (options.columns)` skips 0 but lets a negative through as `c=-5`: a
        // kitty parameter that is not a size at all. Left out instead, which the protocol reads as
        // "draw it at its natural size".
        $this->assertSame("\x1b_Ga=T,f=100,q=2;AAAA\x1b\\", TerminalImage::encodeKitty('AAAA', 0, 0, 0));
        $this->assertSame("\x1b_Ga=T,f=100,q=2;AAAA\x1b\\", TerminalImage::encodeKitty('AAAA', -5, -1, -2));
        $this->assertSame("\x1b_Ga=T,f=100,q=2,c=1,r=1,i=1;AAAA\x1b\\", TerminalImage::encodeKitty('AAAA', 1, 1, 1));
    }

    public function testITerm2EncodingCarriesTheNameAndTheAspectRatioFlag(): void
    {
        $sequence = TerminalImage::encodeITerm2('AAAA', width: 40, height: 'auto', name: 'cat.png', preserveAspectRatio: false);

        $this->assertSame(
            "\x1b]1337;File=inline=1;size=3;width=40;height=auto;name=" . base64_encode('cat.png') . ';preserveAspectRatio=0:AAAA' . "\x07",
            $sequence,
        );
    }

    public function testAnEmptyNameIsNotAName(): void
    {
        // `name=` with nothing after it is a base64 field holding nothing, which iTerm2 has no
        // reading for. Upstream's `if (options.name)` skips it.
        $this->assertStringNotContainsString('name=', TerminalImage::encodeITerm2('AAAA', name: ''));
        $this->assertStringContainsString('name=' . base64_encode('a'), TerminalImage::encodeITerm2('AAAA', name: 'a'));
    }

    public function testAnEmptyFilenameDoesNotLeaveASpaceWhereANameWouldBe(): void
    {
        $this->assertSame('[Image: [image/png]]', TerminalImage::imageFallback('image/png', null, ''));
        $this->assertSame('[Image: cat.png [image/png]]', TerminalImage::imageFallback('image/png', null, 'cat.png'));
    }

    public function testAHugeMimeTypeOrFilenameIsNotPrintedWhole(): void
    {
        // Raw base64 passed where a mime type or a filename belongs would otherwise be printed
        // into the transcript in full.
        $this->assertSame('[Image: [image]]', TerminalImage::imageFallback(str_repeat('A', 100)));
        $this->assertSame('[Image: [image/png;base64,' . str_repeat('A', 15) . ']]', TerminalImage::imageFallback('data:image/png;base64,' . str_repeat('A', 100)));
        $this->assertSame(
            '[Image: ' . str_repeat('b', 60) . '... [image/png]]',
            TerminalImage::imageFallback('image/png', null, 'dir/' . str_repeat('a', 250) . '/' . str_repeat('b', 100)),
        );
    }

    // ---- the component -------------------------------------------------------------

    public function testWithoutImageSupportTheComponentSaysWhatItWouldHaveDrawn(): void
    {
        $lines = self::image(self::png(320, 240), 'image/png', new ImageOptions(filename: 'cat.png'))->render(40);

        $this->assertSame(['[Image: cat.png [image/png] 320x240]'], $lines);
    }

    public function testTheImageIsNeverWiderThanTheTerminalAllows(): void
    {
        $this->drawsWith(ImageProtocol::ITerm2);

        $lines = self::image(self::png(1000, 1000), 'image/png', new ImageOptions(maxWidthCells: 60))->render(20);

        // Twenty columns less the margin, not the sixty it was allowed.
        $this->assertStringContainsString('width=18;', implode('', $lines));
    }

    public function testTheRendererDoesNotMeasureAnImageLine(): void
    {
        $this->drawsWith(ImageProtocol::Kitty);
        $terminal = new FakeTerminal(columns: 20, rows: 10);
        $tui = new TuiMainScreen($terminal);
        $tui->addChild(self::image(self::png(300, 400), 'image/png', new ImageOptions(maxWidthCells: 10)));
        $tui->start();

        // An image line is tens of kilobytes long and zero columns wide; measuring it
        // would fail the width check and stop the program.
        Loop::get()->tick();

        $this->assertStringContainsString("\x1b_G", $terminal->output());
    }

    // ---- the cell-size query -------------------------------------------------------

    public function testTheCellSizeQueryOnlyGoesOutWhenItCouldBeUseful(): void
    {
        $this->drawsWith(null);
        $plain = new FakeTerminal();
        (new TuiMainScreen($plain))->start();
        $this->assertStringNotContainsString("\x1b[16t", $plain->output());

        Loop::reset();
        $this->drawsWith(ImageProtocol::Kitty);
        $graphical = new FakeTerminal();
        (new TuiMainScreen($graphical))->start();
        $this->assertStringContainsString("\x1b[16t", $graphical->output());
    }

    public function testTheReplyIsTakenOutOfTheInputAndTheRestStillArrives(): void
    {
        $this->drawsWith(ImageProtocol::Kitty);
        $terminal = new FakeTerminal();
        $tui = new TuiMainScreen($terminal);
        $typed = new TextComponent('x');
        $tui->addChild($typed);
        $tui->start();
        $tui->setFocus($typed);

        $terminal->type("\x1b[6;20;10ta");

        $this->assertSame(10, TerminalImage::getCellDimensions()->widthPx);
        // The reply is consumed; what the user typed alongside it is not.
        $this->assertSame(['a'], $typed->typed);
    }

    public function testATerminalThatNeverAnswersDoesNotSwallowTyping(): void
    {
        $this->drawsWith(ImageProtocol::Kitty);
        $terminal = new FakeTerminal();
        $tui = new TuiMainScreen($terminal);
        $typed = new TextComponent('x');
        $tui->addChild($typed);
        $tui->start();
        $tui->setFocus($typed);

        $terminal->type('hello');

        $this->assertSame(['hello'], $typed->typed);
    }
}
