<?php

declare(strict_types=1);

namespace Pig\Tui\Components;

use Closure;
use Pig\Tui\Component;
use Pig\Tui\Images\ImageDimensions;
use Pig\Tui\Images\ImageProtocol;
use Pig\Tui\Images\ImageRenderOptions;
use Pig\Tui\Images\TerminalImage;
use Pig\Tui\Width;

/**
 * A picture, on terminals that can draw one — upstream's `Image` in `components/image.ts`.
 *
 * `render()` returns one line per row the picture occupies, so the renderer accounts for its
 * height. Kitty: the sequence on the first line with `C=1` (no cursor movement) and empty lines
 * after it. iTerm2: empty lines first and, on the last, a cursor-up back to the top of the block
 * followed by the sequence.
 */
final class Image implements Component
{
    /** @var (Closure(string, string): ?string)|null */
    private static ?Closure $imageTranscoder = null;

    /**
     * Backstop for callers that recreate Image instances. Keyed by source data, least recently
     * used first.
     *
     * @var array<array-key, ?string>
     */
    private static array $pngCache = [];

    private readonly ImageDimensions $dimensions;

    private ?int $imageId;

    /** Converted PNG data for Kitty. Failures are not stored so a later transcoder can retry. */
    private ?string $pngData = null;

    /** @var list<string>|null */
    private ?array $cachedLines = null;

    private ?int $cachedWidth = null;

    public function __construct(
        private readonly string $base64Data,
        private readonly string $mimeType,
        private readonly ImageTheme $theme,
        private readonly ImageOptions $options = new ImageOptions(),
        ?ImageDimensions $dimensions = null,
    ) {
        $this->dimensions = $dimensions
            ?? TerminalImage::getImageDimensions($base64Data, $mimeType)
            ?? new ImageDimensions(800, 600);
        $this->imageId = $options->imageId;
    }

    /**
     * Register the converter used for non-PNG images on Kitty-protocol terminals, which only
     * accept PNG. Without one, such images render as text fallbacks. Called synchronously during
     * rendering; it converts base64 image data to base64 PNG data, or returns null if it cannot.
     *
     * @param (Closure(string, string): ?string)|null $transcoder
     */
    public static function setImageTranscoder(?Closure $transcoder): void
    {
        self::$imageTranscoder = $transcoder;
        self::$pngCache = [];
    }

    private static function toPng(string $base64Data, string $mimeType): ?string
    {
        if (self::$imageTranscoder === null) {
            return null;
        }
        $png = array_key_exists($base64Data, self::$pngCache)
            ? self::$pngCache[$base64Data]
            : (self::$imageTranscoder)($base64Data, $mimeType);
        unset(self::$pngCache[$base64Data]);
        self::$pngCache[$base64Data] = $png;
        if (count(self::$pngCache) > 32) {
            unset(self::$pngCache[array_key_first(self::$pngCache)]);
        }

        return $png;
    }

    /** Get the Kitty image ID used by this image (if any). */
    public function getImageId(): ?int
    {
        return $this->imageId;
    }

    #[\Override]
    public function invalidate(): void
    {
        $this->cachedLines = null;
        $this->cachedWidth = null;
    }

    #[\Override]
    public function render(int $width): array
    {
        if ($this->cachedLines !== null && $this->cachedWidth === $width) {
            return $this->cachedLines;
        }

        $maxWidth = max(1, min($width - 2, $this->options->maxWidthCells ?? 60));
        $cellDimensions = TerminalImage::getCellDimensions();
        $defaultMaxHeight = max(1, (int) ceil(($maxWidth * $cellDimensions->widthPx) / max(1, $cellDimensions->heightPx)));
        $maxHeight = $this->options->maxHeightCells ?? $defaultMaxHeight;

        $caps = TerminalImage::getCapabilities();
        $data = $this->base64Data;
        $dimensions = $this->dimensions;
        if ($caps->images === ImageProtocol::Kitty && $this->mimeType !== 'image/png') {
            $this->pngData ??= self::toPng($this->base64Data, $this->mimeType);
            $data = $this->pngData;
            // Conversion may apply EXIF rotation, so prefer the PNG's own dimensions.
            if ($data !== null && $data !== '') {
                $dimensions = TerminalImage::getPngDimensions($data) ?? $dimensions;
            }
        }
        $result = null;

        if ($caps->images !== null && $data !== null && $data !== '') {
            if ($caps->images === ImageProtocol::Kitty && $this->imageId === null) {
                $this->imageId = TerminalImage::allocateImageId();
            }
            $result = TerminalImage::renderImage($data, $dimensions, new ImageRenderOptions(
                maxWidthCells: $maxWidth,
                maxHeightCells: $maxHeight,
                imageId: $this->imageId,
                moveCursor: false,
            ));
        }

        if ($result !== null) {
            // Store the image ID for later cleanup
            if ($result->imageId !== null) {
                $this->imageId = $result->imageId;
            }

            if ($caps->images === ImageProtocol::Kitty) {
                // For Kitty: C=1 prevents cursor movement.
                // Return `rows` lines so TUI accounts for image height.
                $lines = [$result->sequence, ...array_fill(0, $result->rows - 1, '')];
            } else {
                // Return `rows` lines so TUI accounts for image height.
                // First (rows-1) lines are empty and cleared before the image is drawn.
                // Last line: move cursor back up, draw the image, then move back down
                // so TUI cursor accounting stays inside the scroll area.
                $rowOffset = $result->rows - 1;
                $moveUp = $rowOffset > 0 ? "\x1b[{$rowOffset}A" : '';
                $lines = [...array_fill(0, $rowOffset, ''), $moveUp . $result->sequence];
            }
        } else {
            $fallback = TerminalImage::imageFallback($this->mimeType, $this->dimensions, $this->options->filename);
            $lines = [Width::truncate(($this->theme->fallbackColor)($fallback), $width)];
        }

        $this->cachedLines = $lines;
        $this->cachedWidth = $width;

        return $lines;
    }
}
