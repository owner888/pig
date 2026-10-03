<?php

declare(strict_types=1);

namespace Pig\Tui\Images;

/**
 * Turning an image into something a terminal will draw.
 *
 * Both protocols work the same way: the image bytes go down the output stream inside an
 * escape sequence, and the terminal puts the picture where the cursor is. Neither gives
 * anything back, so how tall the result will be has to be worked out here, from the
 * image's pixel size and how large a character cell is.
 */
final class TerminalImage
{
    /** Kitty takes its payload in pieces of at most this many base64 characters. */
    private const int CHUNK = 4096;

    private static ?Capabilities $capabilities = null;

    private static ?CellSize $cellSize = null;

    public static function capabilities(): Capabilities
    {
        return self::$capabilities ??= Capabilities::detect();
    }

    public static function cellSize(): CellSize
    {
        return self::$cellSize ??= new CellSize(9, 18);
    }

    /** Told to us by the terminal, in reply to `CSI 16 t`. */
    public static function setCellSize(CellSize $size): void
    {
        self::$cellSize = $size;
    }

    /** @internal Tests, which must not inherit the environment's answer. */
    public static function reset(?Capabilities $capabilities = null): void
    {
        self::$capabilities = $capabilities;
        self::$cellSize = null;
    }

    /**
     * How many rows a picture drawn this wide will occupy.
     *
     * At least one: a picture shorter than a line still has to have a line to be on, or
     * the renderer's idea of where the cursor is goes wrong by one from then on.
     *
     * **The two divisors are guarded because this takes an `ImageSize` rather than reading one.**
     * `ImageDimensions` refuses a header that says zero, so nothing that came off a file arrives
     * here with one; a caller that built its own can, and dividing by it is a `DivisionByZeroError`
     * thrown out of a render. A cell height of zero is the same shape from the other side —
     * `parseCellSizeReply()` refuses one, a constructor does not. Upstream divides either way and
     * gets `Infinity`, which then travels as a row count.
     */
    public static function rows(ImageSize $image, int $widthCells, ?CellSize $cell = null): int
    {
        $cell ??= self::cellSize();
        $scale = $widthCells * $cell->widthPx / max(1, $image->widthPx);

        return max(1, (int) ceil($image->heightPx * $scale / max(1, $cell->heightPx)));
    }

    /**
     * The Kitty graphics protocol.
     *
     * `a=T` means transmit and display at once, `f=100` means the payload is a PNG (or
     * anything else the terminal can decode) rather than raw pixels, and `q=2` suppresses
     * the reply — which would otherwise arrive as keystrokes.
     */
    public static function kitty(string $base64, ?int $columns = null, ?int $rows = null, ?int $imageId = null): string
    {
        $params = ['a=T', 'f=100', 'q=2'];

        // Only when it is a number the protocol can use. Upstream's `if (options.columns)` skips a
        // zero because JavaScript reads it as absent, and `!== null` did not — so a caller asking
        // for no columns sent `c=0` and one asking for a negative sent `c=-5`, which is not a size
        // at all. Left out, kitty draws the picture at its natural size, which is what a caller
        // with no width in mind meant.
        foreach (['c' => $columns, 'r' => $rows, 'i' => $imageId] as $key => $value) {
            if ($value !== null && $value > 0) {
                $params[] = "{$key}={$value}";
            }
        }

        $header = implode(',', $params);

        if (strlen($base64) <= self::CHUNK) {
            return "\x1b_G{$header};{$base64}\x1b\\";
        }

        // Split: every piece but the last says "more follows", and only the first
        // carries the parameters.
        $pieces = str_split($base64, self::CHUNK);
        $last = count($pieces) - 1;
        $sequence = '';

        foreach ($pieces as $index => $piece) {
            $prefix = match (true) {
                $index === 0 => "{$header},m=1",
                $index === $last => 'm=0',
                default => 'm=1',
            };

            $sequence .= "\x1b_G{$prefix};{$piece}\x1b\\";
        }

        return $sequence;
    }

    /**
     * iTerm2's inline image protocol.
     *
     * Sizes are cells unless given a unit, so `width=40` is forty columns and
     * `height=auto` lets the terminal keep the aspect ratio.
     */
    public static function iterm2(
        string $base64,
        int|string|null $width = null,
        int|string|null $height = null,
        ?string $name = null,
        bool $preserveAspectRatio = true,
        bool $inline = true,
    ): string {
        $params = ['inline=' . ($inline ? '1' : '0')];

        if ($width !== null) {
            $params[] = "width={$width}";
        }

        if ($height !== null) {
            $params[] = "height={$height}";
        }

        // An empty name is not a name: `name=` with nothing after it is a base64 field holding
        // nothing, which iTerm2 has no reading for. Upstream's `if (options.name)` skips it.
        if ($name !== null && $name !== '') {
            $params[] = 'name=' . base64_encode($name);
        }

        if (!$preserveAspectRatio) {
            $params[] = 'preserveAspectRatio=0';
        }

        return "\x1b]1337;File=" . implode(';', $params) . ":{$base64}\x07";
    }

    /**
     * The sequence to draw this image, and how many rows it will take.
     *
     * Null when the terminal cannot draw images at all, which is the caller's cue to
     * write something readable instead.
     *
     * @return array{0: string, 1: int}|null
     */
    public static function render(
        string $base64,
        ImageSize $image,
        int $maxWidthCells = 80,
        bool $preserveAspectRatio = true,
    ): ?array {
        $protocol = self::capabilities()->images;

        if ($protocol === null) {
            return null;
        }

        $rows = self::rows($image, $maxWidthCells);

        return match ($protocol) {
            ImageProtocol::Kitty => [self::kitty($base64, $maxWidthCells, $rows), $rows],
            ImageProtocol::ITerm2 => [
                self::iterm2($base64, $maxWidthCells, 'auto', preserveAspectRatio: $preserveAspectRatio),
                $rows,
            ],
        };
    }

    /**
     * What to show where a picture cannot be drawn.
     *
     * An empty filename is left out rather than joined, which is the same falsiness rule as the two
     * encoders above and the only one of the three anybody reads: `[Image:  [image/png]]`, with two
     * spaces, looks like the name went missing rather than never existing.
     */
    public static function fallback(string $mimeType, ?ImageSize $size = null, ?string $filename = null): string
    {
        // Guard against accidental huge strings (e.g. raw base64 passed as mimeType or filename)
        if (strlen($mimeType) > 64) {
            $mimeType = str_starts_with($mimeType, 'data:') ? substr($mimeType, 5, 32) : 'image';
        }
        if ($filename !== null && strlen($filename) > 256) {
            $filename = basename($filename);
            if (strlen($filename) > 64) {
                $filename = substr($filename, 0, 60) . '...';
            }
        }

        $parts = $filename === null || $filename === '' ? [] : [$filename];
        $parts[] = "[{$mimeType}]";

        if ($size !== null) {
            $parts[] = "{$size->widthPx}x{$size->heightPx}";
        }

        return '[Image: ' . implode(' ', $parts) . ']';
    }

    /**
     * The terminal's reply to `CSI 16 t`, if this is one.
     *
     * The reply is `CSI 6 ; height ; width t` — height first, which is the opposite of
     * every other pair in this file.
     */
    public static function parseCellSizeReply(string $data): ?CellSize
    {
        if (preg_match('/\x1b\[6;(\d+);(\d+)t/', $data, $match) !== 1) {
            return null;
        }

        $height = (int) $match[1];
        $width = (int) $match[2];

        return $height > 0 && $width > 0 ? new CellSize($width, $height) : null;
    }
}
