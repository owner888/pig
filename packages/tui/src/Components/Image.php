<?php

declare(strict_types=1);

namespace Pig\Tui\Components;

use Pig\Tui\Component;
use Pig\Tui\Images\ImageDimensions;
use Pig\Tui\Images\ImageSize;
use Pig\Tui\Images\TerminalImage;

/**
 * A picture, on terminals that can draw one.
 *
 * The trick is the shape of what `render()` returns. The image sequence puts the picture
 * at the cursor and the picture grows *downwards* from there, but the renderer works by
 * comparing lines and has to know how many rows this occupies. So it returns that many
 * lines: the first few empty, and the last one a cursor-up followed by the image. By the
 * time the terminal draws the picture the cursor is back at the top of the block, and the
 * picture fills exactly the rows the renderer already accounted for.
 */
final class Image implements Component
{
    /** What to assume when the header could not be read: a 4:3 picture of no known size. */
    private const int FALLBACK_WIDTH_PX = 800;
    private const int FALLBACK_HEIGHT_PX = 600;

    /** Widest a picture is drawn, and the margin left beside it. */
    private const int DEFAULT_MAX_CELLS = 60;
    private const int SIDE_MARGIN = 2;

    private readonly ImageSize $size;

    private readonly ImageTheme $theme;

    /** @var list<string>|null */
    private ?array $cachedLines = null;

    private ?int $cachedWidth = null;

    public function __construct(
        private readonly string $base64,
        private readonly string $mimeType,
        ?ImageTheme $theme = null,
        private readonly int $maxWidthCells = self::DEFAULT_MAX_CELLS,
        private readonly ?string $filename = null,
        ?ImageSize $size = null,
    ) {
        $this->theme = $theme ?? ImageTheme::default();
        $this->size = $size
            ?? ImageDimensions::of($base64, $mimeType)
            ?? new ImageSize(self::FALLBACK_WIDTH_PX, self::FALLBACK_HEIGHT_PX);
    }

    public function size(): ImageSize
    {
        return $this->size;
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

        $this->cachedWidth = $width;
        $this->cachedLines = $this->lines(max(1, min($width - self::SIDE_MARGIN, $this->maxWidthCells)));

        return $this->cachedLines;
    }

    /** @return list<string> */
    private function lines(int $cells): array
    {
        $drawn = TerminalImage::render($this->base64, $this->size, $cells);

        if ($drawn === null) {
            return [($this->theme->fallback)(
                TerminalImage::fallback($this->mimeType, $this->size, $this->filename),
            )];
        }

        [$sequence, $rows] = $drawn;
        $lines = array_fill(0, $rows - 1, '');
        $lines[] = ($rows > 1 ? "\x1b[" . ($rows - 1) . 'A' : '') . $sequence;

        return $lines;
    }
}
