<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test\Utils;

use PHPUnit\Framework\Attributes\RequiresFunction;
use PHPUnit\Framework\TestCase;
use Pig\Ai\ImageContent;
use Pig\Ai\TextContent;
use Pig\CodingAgent\Test\TinyImages;
use Pig\CodingAgent\Utils\ExifOrientation;
use Pig\CodingAgent\Utils\ImageProcess;
use Pig\Tui\Images\TerminalImage;

/** Upstream's `image-process.ts`, `image-resize-core.ts`, `tool-result-images.ts` and `exif-orientation.ts`. */
final class ImageProcessTest extends TestCase
{
    protected function tearDown(): void
    {
        ImageProcess::$codec = null;
    }

    public function testAnImageInsideTheLimitsGoesThroughAsItIs(): void
    {
        $result = ImageProcess::process(TinyImages::png(), 'image/png');

        $this->assertTrue($result['ok']);
        $this->assertSame(TinyImages::PNG_BASE64, $result['data']);
        $this->assertSame('image/png', $result['mimeType']);
        $this->assertSame([], $result['hints']);
    }

    #[RequiresFunction('imagecreatetruecolor')]
    public function testAnOversizedImageIsFittedAndSaysHowToMapCoordinatesBack(): void
    {
        $result = ImageProcess::process(self::png(3000, 1000), 'image/png');

        $this->assertTrue($result['ok']);
        $size = TerminalImage::getImageDimensions($result['data'], $result['mimeType']);
        $this->assertSame([2000, 667], [$size?->widthPx, $size?->heightPx]);
        $this->assertSame(['[Image: original 3000x1000, displayed at 2000x667. Multiply coordinates by 1.50 to map to original image.]'], $result['hints']);
    }

    #[RequiresFunction('imagecreatetruecolor')]
    public function testTheModelsResizeProfileIsTheLimit(): void
    {
        $result = ImageProcess::process(self::png(300, 100), 'image/png', resize: ['maxWidth' => 150]);

        $size = TerminalImage::getImageDimensions($result['data'], $result['mimeType']);
        $this->assertSame([150, 50], [$size?->widthPx, $size?->heightPx]);
    }

    public function testWithAutoResizeOffAnOversizedImageIsLeftAlone(): void
    {
        $big = self::header(3000, 1000);

        $result = ImageProcess::process($big, 'image/png', autoResize: false);

        $this->assertTrue($result['ok']);
        $this->assertSame(base64_encode($big), $result['data']);
    }

    public function testWithNoCodecAnImageInsideTheLimitsStillGoesThrough(): void
    {
        ImageProcess::$codec = false;

        $this->assertSame(TinyImages::PNG_BASE64, ImageProcess::process(TinyImages::png(), 'image/png')['data']);
    }

    public function testWithNoCodecAnImageThatMustBeFittedIsOmittedInUpstreamsWords(): void
    {
        ImageProcess::$codec = false;

        $this->assertSame(
            ['ok' => false, 'message' => '[Image omitted: could not be resized below the inline image size limit.]'],
            ImageProcess::process(self::header(3000, 1000), 'image/png'),
        );
    }

    #[RequiresFunction('imagebmp')]
    public function testAFormatNoProviderTakesIsConvertedToPngAndSaysSo(): void
    {
        $image = imagecreatetruecolor(4, 3);
        ob_start();
        imagebmp($image);
        $bmp = (string) ob_get_clean();

        $result = ImageProcess::process($bmp, 'image/bmp');

        $this->assertTrue($result['ok']);
        $this->assertSame('image/png', $result['mimeType']);
        $this->assertSame(['[Image converted from image/bmp to image/png.]'], $result['hints']);
    }

    public function testBytesThatAreNoPictureInAFormatNoProviderTakesAreOmitted(): void
    {
        $this->assertSame(
            ['ok' => false, 'message' => '[Image omitted: could not be converted to a supported inline image format.]'],
            ImageProcess::process('not a picture', 'image/tiff'),
        );
    }

    public function testTheExifOrientationIsReadOffAJpeg(): void
    {
        $this->assertSame(6, ExifOrientation::of(self::withOrientation(TinyImages::jpeg(), 6)));
        $this->assertSame(1, ExifOrientation::of(TinyImages::jpeg()));
        $this->assertSame(1, ExifOrientation::of('nothing'));
    }

    #[RequiresFunction('imagecreatetruecolor')]
    public function testAPhotoOnItsSideIsMeasuredTheWayItIsSeen(): void
    {
        // 40 wide and 10 high as stored, 10 by 40 as seen: inside 20 by 50 only once it is turned.
        $image = imagecreatetruecolor(40, 10);
        ob_start();
        imagejpeg($image);
        $jpeg = self::withOrientation((string) ob_get_clean(), 6);

        $result = ImageProcess::process($jpeg, 'image/jpeg', resize: ['maxWidth' => 20, 'maxHeight' => 50]);

        $this->assertSame(base64_encode($jpeg), $result['data'], 'already inside the limits, so not touched');

        ImageProcess::$codec = false;
        $this->assertSame(base64_encode($jpeg), ImageProcess::process($jpeg, 'image/jpeg', resize: ['maxWidth' => 20, 'maxHeight' => 50])['data'], 'and with no codec, measured turned too');
        ImageProcess::$codec = null;

        $turned = ImageProcess::process($jpeg, 'image/jpeg', resize: ['maxWidth' => 5, 'maxHeight' => 50]);
        $size = TerminalImage::getImageDimensions($turned['data'], $turned['mimeType']);
        $this->assertSame([5, 20], [$size?->widthPx, $size?->heightPx], 'resized standing up');
    }

    #[RequiresFunction('imagecreatetruecolor')]
    public function testAToolsOversizedPictureIsFittedAndTheNoteFollowsIt(): void
    {
        $content = [new TextContent('screenshot'), new ImageContent(base64_encode(self::png(3000, 1000)), 'image/png')];

        $normalized = ImageProcess::normalizeToolResult($content);

        $this->assertCount(3, $normalized);
        $this->assertInstanceOf(ImageContent::class, $normalized[1]);
        $this->assertNotSame($content[1]->data, $normalized[1]->data);
        $this->assertInstanceOf(TextContent::class, $normalized[2]);
        $this->assertStringContainsString('original 3000x1000', $normalized[2]->text);
    }

    public function testAToolsPictureThatCannotBeProcessedIsKeptAsTheToolMadeIt(): void
    {
        ImageProcess::$codec = false;
        $content = [new ImageContent(base64_encode(self::header(3000, 1000)), 'image/png')];

        $this->assertSame($content, ImageProcess::normalizeToolResult($content));
        $text = [new TextContent('x')];
        $this->assertSame($text, ImageProcess::normalizeToolResult($text));
    }

    private static function png(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    /** A PNG whose header says $width by $height and nothing else — no codec reads it. */
    private static function header(int $width, int $height): string
    {
        return "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . pack('NN', $width, $height) . "\x08\x06\x00\x00\x00";
    }

    /** $jpeg with an APP1 EXIF segment holding only an orientation, put after the SOI marker. */
    private static function withOrientation(string $jpeg, int $orientation): string
    {
        $tiff = 'MM' . pack('n', 42) . pack('N', 8) . pack('n', 1)
            . pack('n', 0x0112) . pack('n', 3) . pack('N', 1) . pack('n', $orientation) . "\0\0" . pack('N', 0);
        $exif = "Exif\0\0" . $tiff;

        return "\xff\xd8\xff\xe1" . pack('n', strlen($exif) + 2) . $exif . substr($jpeg, 2);
    }
}
