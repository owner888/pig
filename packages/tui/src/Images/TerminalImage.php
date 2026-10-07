<?php

declare(strict_types=1);

namespace Pig\Tui\Images;

use Closure;
use Pig\Tui\Env;
use Pig\Tui\Process;

/**
 * Upstream's `terminal-image.ts`: terminal capability detection, the Kitty and iTerm2 image
 * encoders, the Kitty placement metadata the alternate screen crops and reuses, image cell
 * sizing, image header readers, OSC 8 hyperlinks and the text fallback for an image.
 *
 * Upstream's module-level functions are static methods here, under the same names, and its
 * module-level state (cached capabilities, overrides, cell dimensions, the Kitty metadata map) is
 * static state — so tests reset it with `setCapabilities()` / `resetCapabilitiesCache()` /
 * `setCellDimensions()`.
 *
 * `getTerminalColorMode()` is not ported: pig has no `TerminalColorMode`.
 */
final class TerminalImage
{
    private const string KITTY_PREFIX = "\x1b_G";
    private const string ITERM2_PREFIX = "\x1b]1337;File=";

    /** Kitty takes its payload in pieces of at most this many base64 characters. */
    private const int CHUNK_SIZE = 4096;

    private const array KITTY_PLACEMENT_CONTROL_KEYS = [
        'i', 'p', 'x', 'y', 'w', 'h', 'X', 'Y', 'c', 'r', 'C', 'U', 'z', 'P', 'Q', 'H', 'V',
    ];

    private static ?TerminalCapabilities $cachedCapabilities = null;

    /** @var array{images?: ?ImageProtocol, trueColor?: bool, hyperlinks?: bool} */
    private static array $capabilityOverrides = [];

    /** Default cell dimensions - updated by TUI when terminal responds to query. */
    private static ?CellDimensions $cellDimensions = null;

    /**
     * Upstream's `kittyImageMetadata` map, oldest first.
     *
     * @var array<int, array{metadata: KittyImageMetadata, transmissionGeneration: int}>
     */
    private static array $kittyImageMetadata = [];

    private static int $kittyTransmissionGeneration = 0;

    public static function getCellDimensions(): CellDimensions
    {
        return self::$cellDimensions ??= new CellDimensions(9, 18);
    }

    public static function setCellDimensions(CellDimensions $dims): void
    {
        self::$cellDimensions = $dims;
    }

    /**
     * Checks whether the attached tmux client forwards OSC 8 hyperlinks to the outer terminal.
     * tmux only re-emits them when its `client_termfeatures` lists `hyperlinks`, and strips them
     * otherwise. On any error answers `false`, as upstream does.
     */
    private static function probeTmuxHyperlinks(): bool
    {
        [$exit, $termfeatures] = Process::run(['tmux', 'display-message', '-p', '#{client_termfeatures}'], 0.25);
        if ($exit !== 0) {
            return false;
        }

        return in_array('hyperlinks', array_map(trim(...), explode(',', $termfeatures)), true);
    }

    /** @param Closure(): bool $tmuxForwardsHyperlink */
    private static function detectCapabilitiesFromEnvironment(Closure $tmuxForwardsHyperlink): TerminalCapabilities
    {
        $termProgram = strtolower((string) getenv('TERM_PROGRAM'));
        $terminalEmulator = strtolower((string) getenv('TERMINAL_EMULATOR'));
        $term = strtolower((string) getenv('TERM'));
        $colorTerm = strtolower((string) getenv('COLORTERM'));
        $hasTrueColorHint = $colorTerm === 'truecolor' || $colorTerm === '24bit' || str_ends_with($term, '-direct');
        $isWindowsConsole = PHP_OS_FAMILY === 'Windows';

        // Emit OSC 8 hyperlinks only when tmux confirms it forwards.
        // Image protocols are unreliable under tmux, so leave `images: null`.
        if (Env::isSet('TMUX') || str_starts_with($term, 'tmux')) {
            return new TerminalCapabilities(null, $hasTrueColorHint, $tmuxForwardsHyperlink());
        }

        // screen does not forward OSC 8 hyperlinks, so keep them off there.
        if (str_starts_with($term, 'screen')) {
            return new TerminalCapabilities(null, $hasTrueColorHint, false);
        }

        if (Env::isSet('KITTY_WINDOW_ID') || $termProgram === 'kitty') {
            return new TerminalCapabilities(ImageProtocol::Kitty, true, true);
        }

        if ($termProgram === 'ghostty' || str_contains($term, 'ghostty') || Env::isSet('GHOSTTY_RESOURCES_DIR')) {
            return new TerminalCapabilities(ImageProtocol::Kitty, true, true);
        }

        if (Env::isSet('WEZTERM_PANE') || $termProgram === 'wezterm') {
            return new TerminalCapabilities(ImageProtocol::Kitty, true, true);
        }

        // Warp supports the Kitty graphics protocol and OSC 8 hyperlinks.
        if ($termProgram === 'warpterminal' || Env::isSet('WARP_SESSION_ID') || Env::isSet('WARP_TERMINAL_SESSION_UUID')) {
            return new TerminalCapabilities(ImageProtocol::Kitty, true, true);
        }

        if (Env::isSet('ITERM_SESSION_ID') || $termProgram === 'iterm.app') {
            return new TerminalCapabilities(ImageProtocol::ITerm2, true, true);
        }

        if (Env::isSet('WT_SESSION')) {
            return new TerminalCapabilities(null, true, true);
        }

        if ($termProgram === 'alacritty' || $termProgram === 'vscode' || $termProgram === 'zed') {
            return new TerminalCapabilities(null, true, true);
        }

        if ($terminalEmulator === 'jetbrains-jediterm') {
            return new TerminalCapabilities(null, true, false);
        }

        // Windows Terminal does not always set WT_SESSION, for example when it hosts
        // a cmd.exe launched directly from Win+R. Modern Windows consoles support
        // truecolor; keep hyperlinks off unless we positively detected support above.
        if ($isWindowsConsole) {
            return new TerminalCapabilities(null, true, false);
        }

        // Unknown terminal: be conservative. OSC 8 is rendered invisibly as "just
        // text" on terminals that swallow it, which means the URL disappears from
        // the rendered output. Default to the legacy `text (url)` behavior unless we
        // have positively identified a hyperlink-capable terminal above.
        return new TerminalCapabilities(null, $hasTrueColorHint, false);
    }

    private static function parseBooleanCapabilityOverride(?string $value): ?bool
    {
        return $value === '1' ? true : ($value === '0' ? false : null);
    }

    /**
     * An override variable, `PIG_` first and then upstream's `PI_` name.
     *
     * Not `getenv('PIG_…') ?: getenv('PI_…')`: `'0'` is falsy in PHP, so `PIG_HYPERLINKS=0` would
     * fall through to the `PI_` variable instead of turning hyperlinks off.
     */
    private static function overrideVariable(string $name): ?string
    {
        if (Env::isSet("PIG_{$name}")) {
            return (string) getenv("PIG_{$name}");
        }
        $value = getenv("PI_{$name}");

        return $value === false ? null : $value;
    }

    /** @param (Closure(): bool)|null $tmuxForwardsHyperlink defaults to probing tmux */
    public static function detectCapabilities(?Closure $tmuxForwardsHyperlink = null): TerminalCapabilities
    {
        $tmuxForwardsHyperlink ??= self::probeTmuxHyperlinks(...);
        $hyperlinks = self::parseBooleanCapabilityOverride(self::overrideVariable('HYPERLINKS'));
        $detected = self::detectCapabilitiesFromEnvironment(
            $hyperlinks === null ? $tmuxForwardsHyperlink : static fn (): bool => $hyperlinks,
        );
        $imageProtocol = strtolower((string) self::overrideVariable('IMAGE_PROTOCOL'));
        $images = match ($imageProtocol) {
            'kitty' => ImageProtocol::Kitty,
            'iterm2' => ImageProtocol::ITerm2,
            'none', '0' => null,
            default => $detected->images,
        };
        $trueColor = self::parseBooleanCapabilityOverride(self::overrideVariable('TRUE_COLOR'));

        return new TerminalCapabilities(
            $images,
            $trueColor ?? $detected->trueColor,
            $hyperlinks ?? $detected->hyperlinks,
        );
    }

    public static function getCapabilities(): TerminalCapabilities
    {
        if (self::$cachedCapabilities === null) {
            $overrides = self::$capabilityOverrides;
            $hyperlinks = $overrides['hyperlinks'] ?? null;
            $detected = self::detectCapabilities($hyperlinks === null ? null : static fn (): bool => $hyperlinks);
            self::$cachedCapabilities = new TerminalCapabilities(
                array_key_exists('images', $overrides) ? $overrides['images'] : $detected->images,
                $overrides['trueColor'] ?? $detected->trueColor,
                $overrides['hyperlinks'] ?? $detected->hyperlinks,
            );
        }

        return self::$cachedCapabilities;
    }

    public static function resetCapabilitiesCache(): void
    {
        self::$cachedCapabilities = null;
    }

    /**
     * Override selected auto-detected capabilities.
     *
     * Upstream takes a `Partial<TerminalCapabilities>`; here a key that is absent is not
     * overridden, and `'images' => null` overrides images to none.
     *
     * @param array{images?: ?ImageProtocol, trueColor?: bool, hyperlinks?: bool} $overrides
     */
    public static function setCapabilityOverrides(array $overrides): void
    {
        $current = self::$capabilityOverrides;
        $same = static fn (string $key): bool => array_key_exists($key, $current) === array_key_exists($key, $overrides)
            && ($current[$key] ?? null) === ($overrides[$key] ?? null);
        if ($same('images') && $same('trueColor') && $same('hyperlinks')) {
            return;
        }
        self::$capabilityOverrides = $overrides;
        self::$cachedCapabilities = null;
    }

    /** Override the cached capabilities. Useful in tests to exercise both code paths. */
    public static function setCapabilities(TerminalCapabilities $caps): void
    {
        self::$cachedCapabilities = $caps;
    }

    public static function isImageLine(string $line): bool
    {
        // Fast path: sequence at line start (single-row images)
        if (str_starts_with($line, self::KITTY_PREFIX) || str_starts_with($line, self::ITERM2_PREFIX)) {
            return true;
        }

        // Slow path: sequence elsewhere (multi-row images have cursor-up prefix)
        return str_contains($line, self::KITTY_PREFIX) || str_contains($line, self::ITERM2_PREFIX);
    }

    /**
     * Generate a random image ID for Kitty graphics protocol.
     * Uses random IDs to avoid collisions between different module instances
     * (e.g., main app vs extensions).
     */
    public static function allocateImageId(): int
    {
        // Use random ID in range [1, 0xffffffff] to avoid collisions
        return random_int(1, 0xffffffff);
    }

    /**
     * The Kitty graphics protocol.
     *
     * `a=T` means transmit and display at once, `f=100` means the payload is a PNG rather than raw
     * pixels, and `q=2` suppresses the reply — which would otherwise arrive as keystrokes.
     *
     * @param bool $moveCursor Whether Kitty should apply its default cursor movement after placement.
     */
    public static function encodeKitty(
        string $base64Data,
        ?int $columns = null,
        ?int $rows = null,
        ?int $imageId = null,
        bool $moveCursor = true,
    ): string {
        $params = ['a=T', 'f=100', 'q=2'];

        if (!$moveCursor) {
            $params[] = 'C=1';
        }
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

        if (strlen($base64Data) <= self::CHUNK_SIZE) {
            return "\x1b_G{$header};{$base64Data}\x1b\\";
        }

        $chunks = '';
        $offset = 0;
        $isFirst = true;
        $length = strlen($base64Data);

        while ($offset < $length) {
            $chunk = substr($base64Data, $offset, self::CHUNK_SIZE);
            $isLast = $offset + self::CHUNK_SIZE >= $length;

            if ($isFirst) {
                $chunks .= "\x1b_G{$header},m=1;{$chunk}\x1b\\";
                $isFirst = false;
            } elseif ($isLast) {
                $chunks .= "\x1b_Gm=0;{$chunk}\x1b\\";
            } else {
                $chunks .= "\x1b_Gm=1;{$chunk}\x1b\\";
            }

            $offset += self::CHUNK_SIZE;
        }

        return $chunks;
    }

    /**
     * Delete a Kitty graphics image by ID.
     * Uses uppercase 'I' to also free the image data.
     */
    public static function deleteKittyImage(int $imageId): string
    {
        return "\x1b_Ga=d,d=I,i={$imageId},q=2\x1b\\";
    }

    /**
     * Delete all visible Kitty graphics images.
     * Uses uppercase 'A' to also free the image data.
     */
    public static function deleteAllKittyImages(): string
    {
        return "\x1b_Ga=d,d=A,q=2\x1b\\";
    }

    /** Delete all visible Kitty placements while retaining their uploaded image data. */
    public static function deleteAllKittyPlacements(): string
    {
        return "\x1b_Ga=d,d=a,q=2\x1b\\";
    }

    /**
     * iTerm2's inline image protocol.
     *
     * Sizes are cells unless given a unit, so `width=40` is forty columns and `height=auto` lets
     * the terminal keep the aspect ratio.
     */
    public static function encodeITerm2(
        string $base64Data,
        int|string|null $width = null,
        int|string|null $height = null,
        ?string $name = null,
        bool $preserveAspectRatio = true,
        bool $inline = true,
    ): string {
        $params = [
            'inline=' . ($inline ? '1' : '0'),
            'size=' . self::base64ByteLength($base64Data),
        ];

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

        return "\x1b]1337;File=" . implode(';', $params) . ":{$base64Data}\x07";
    }

    /** Node's `Buffer.byteLength(data, "base64")`: the decoded length, worked out without decoding. */
    private static function base64ByteLength(string $base64Data): int
    {
        $length = strlen($base64Data);
        if ($length > 0 && $base64Data[$length - 1] === '=') {
            $length--;
        }
        if ($length > 1 && $base64Data[$length - 1] === '=') {
            $length--;
        }

        return ($length * 3) >> 2;
    }

    public static function registerKittyImageMetadata(KittyImageMetadata $metadata): void
    {
        self::$kittyTransmissionGeneration += 1;
        unset(self::$kittyImageMetadata[$metadata->imageId]);
        self::$kittyImageMetadata[$metadata->imageId] = [
            'metadata' => $metadata,
            'transmissionGeneration' => self::$kittyTransmissionGeneration,
        ];
        if (count(self::$kittyImageMetadata) > 1000) {
            $oldestImageId = array_key_first(self::$kittyImageMetadata);
            unset(self::$kittyImageMetadata[$oldestImageId]);
        }
    }

    /** @return array{metadata: KittyImageMetadata, transmissionGeneration: int}|null */
    private static function getRegisteredKittyImageMetadataFromControls(string $controls): ?array
    {
        if (preg_match('/(?:^|,)i=(\d+)(?:,|$)/', $controls, $match) !== 1) {
            return null;
        }

        return self::$kittyImageMetadata[(int) $match[1]] ?? null;
    }

    /**
     * The control data of the first Kitty command in a line, and where that command starts —
     * upstream's `/\x1b_G([^;]*);/.exec(line)`.
     *
     * @return array{index: int, whole: string, controls: string}|null
     */
    private static function matchKittyControls(string $line): ?array
    {
        if (preg_match('/\x1b_G([^;]*);/', $line, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        return ['index' => $match[0][1], 'whole' => $match[0][0], 'controls' => $match[1][0]];
    }

    private static function getExplicitKittyImageRows(string $controls): ?int
    {
        if (preg_match('/(?:^|,)r=(\d+)(?:,|$)/', $controls, $match) !== 1) {
            return null;
        }
        $rows = (int) $match[1];

        return $rows > 0 ? $rows : null;
    }

    private static function getKittyImageRowsFromControls(string $controls, int $fallbackRows): int
    {
        return self::getExplicitKittyImageRows($controls) ?? $fallbackRows;
    }

    public static function getKittyImageMetadata(string $line): ?KittyImageMetadata
    {
        $match = self::matchKittyControls($line);
        $registered = $match === null ? null : self::getRegisteredKittyImageMetadataFromControls($match['controls']);

        return $registered['metadata'] ?? null;
    }

    /** Read the number of rows covered by an image placement without scanning its payload. */
    public static function getKittyImagePlacementRows(string $line): ?int
    {
        $match = self::matchKittyControls($line);
        if ($match === null) {
            return null;
        }
        $explicitRows = self::getExplicitKittyImageRows($match['controls']);
        if ($explicitRows !== null) {
            return $explicitRows;
        }

        return self::getRegisteredKittyImageMetadataFromControls($match['controls'])['metadata']->rows ?? null;
    }

    /** Build a placement-only command for an image line emitted by {@see renderImage()}. */
    public static function getKittyImagePlacement(string $line): ?KittyImagePlacement
    {
        $match = self::matchKittyControls($line);
        if ($match === null) {
            return null;
        }
        $registered = self::getRegisteredKittyImageMetadataFromControls($match['controls']);
        if ($registered === null) {
            return null;
        }
        $metadata = $registered['metadata'];

        $prefixLength = strlen(self::KITTY_PREFIX);
        $commandStart = $match['index'];
        $commandControls = $match['controls'];
        while (true) {
            $terminator = strpos($line, "\x1b\\", $commandStart + $prefixLength);
            if ($terminator === false) {
                return null;
            }
            $transmissionEnd = $terminator + 2;
            if (preg_match('/(?:^|,)m=1(?:,|$)/', $commandControls) !== 1) {
                break;
            }
            $commandStart = $transmissionEnd;
            if (substr($line, $commandStart, $prefixLength) !== self::KITTY_PREFIX) {
                return null;
            }
            $controlsEnd = strpos($line, ';', $commandStart + $prefixLength);
            if ($controlsEnd === false) {
                return null;
            }
            $commandControls = substr($line, $commandStart + $prefixLength, $controlsEnd - $commandStart - $prefixLength);
        }

        $controls = array_values(array_filter(
            explode(',', $match['controls']),
            static fn (string $control): bool => in_array(explode('=', $control, 2)[0], self::KITTY_PLACEMENT_CONTROL_KEYS, true),
        ));
        $sequence = "\x1b_Ga=p,q=2," . implode(',', $controls) . "\x1b\\";

        return new KittyImagePlacement(
            imageId: $metadata->imageId,
            transmissionGeneration: $registered['transmissionGeneration'],
            transmissionBytes: $transmissionEnd - $match['index'],
            estimatedDecodedBytes: $metadata->widthPx * $metadata->heightPx * 4,
            rows: self::getKittyImageRowsFromControls($match['controls'], $metadata->rows),
            sequence: $sequence,
            replacementLine: substr($line, 0, $match['index']) . $sequence . substr($line, $transmissionEnd),
        );
    }

    public static function cropKittyImageLine(string $line, int $hiddenRows, int $visibleRows): string
    {
        $metadata = self::getKittyImageMetadata($line);
        $match = self::matchKittyControls($line);
        if ($metadata === null || $match === null || $hiddenRows < 0 || $hiddenRows >= $metadata->rows || $visibleRows <= 0) {
            return $line;
        }
        $croppedRows = min($visibleRows, $metadata->rows - $hiddenRows);
        if ($hiddenRows === 0 && $croppedRows === $metadata->rows) {
            return $line;
        }
        $sourceY = (int) floor($metadata->heightPx * $hiddenRows / $metadata->rows);
        $sourceEnd = (int) ceil($metadata->heightPx * ($hiddenRows + $croppedRows) / $metadata->rows);
        $sourceHeight = max(1, min($metadata->heightPx, $sourceEnd) - $sourceY);
        $controls = array_values(array_filter(
            explode(',', $match['controls']),
            static fn (string $control): bool => preg_match('/^[yhr]=/', $control) !== 1,
        ));
        array_push($controls, "y={$sourceY}", "h={$sourceHeight}", "r={$croppedRows}");

        return substr($line, 0, $match['index']) . "\x1b_G" . implode(',', $controls) . ';'
            . substr($line, $match['index'] + strlen($match['whole']));
    }

    private static function chooseLessDistortedCellCount(int $upperCount, float $idealCount): int
    {
        if ($upperCount <= 1) {
            return $upperCount;
        }

        $lowerCount = $upperCount - 1;
        $upperDistortion = max($upperCount / $idealCount, $idealCount / $upperCount);
        $lowerDistortion = max($lowerCount / $idealCount, $idealCount / $lowerCount);

        return $lowerDistortion < $upperDistortion ? $lowerCount : $upperCount;
    }

    /**
     * **The cell divisors are guarded with `max(1, …)`, which upstream does not do.** Upstream
     * clamps the image's own size to at least one pixel and divides by the cell size as given; a
     * cell size of zero is something the cell-size reply parser refuses but a caller can
     * construct, and dividing by it is a `DivisionByZeroError` thrown out of a render where
     * upstream gets `Infinity` and carries it on as a cell count.
     */
    public static function calculateImageCellSize(
        ImageDimensions $imageDimensions,
        int|float $maxWidthCells,
        int|float|null $maxHeightCells = null,
        ?CellDimensions $cellDimensions = null,
        bool $optimizeAspectRatio = false,
    ): ImageCellSize {
        $cellDimensions ??= new CellDimensions(9, 18);
        $cellWidth = max(1, $cellDimensions->widthPx);
        $cellHeight = max(1, $cellDimensions->heightPx);
        $maxWidth = max(1, (int) floor($maxWidthCells));
        $maxHeight = $maxHeightCells === null ? null : max(1, (int) floor($maxHeightCells));
        $imageWidth = max(1, $imageDimensions->widthPx);
        $imageHeight = max(1, $imageDimensions->heightPx);

        $widthScale = ($maxWidth * $cellWidth) / $imageWidth;
        $heightScale = $maxHeight === null ? $widthScale : ($maxHeight * $cellHeight) / $imageHeight;
        $scale = min($widthScale, $heightScale);

        $scaledWidthPx = $imageWidth * $scale;
        $scaledHeightPx = $imageHeight * $scale;
        $columns = max(1, min($maxWidth, (int) ceil($scaledWidthPx / $cellWidth)));
        $heightRows = $scaledHeightPx / $cellHeight;
        $rows = max(1, (int) ceil($heightRows));
        if ($maxHeight !== null) {
            $rows = min($maxHeight, $rows);
        }

        if (!$optimizeAspectRatio) {
            return new ImageCellSize($columns, $rows);
        }

        if ($widthScale <= $heightScale) {
            $idealRows = ($columns * $cellWidth * $imageHeight) / ($imageWidth * $cellHeight);
            $rows = self::chooseLessDistortedCellCount($rows, $idealRows);
        } else {
            $idealColumns = ($rows * $cellHeight * $imageWidth) / ($imageHeight * $cellWidth);
            $columns = self::chooseLessDistortedCellCount($columns, $idealColumns);
        }

        return new ImageCellSize($columns, $rows);
    }

    public static function calculateImageRows(
        ImageDimensions $imageDimensions,
        int|float $targetWidthCells,
        ?CellDimensions $cellDimensions = null,
    ): int {
        return self::calculateImageCellSize($imageDimensions, $targetWidthCells, null, $cellDimensions)->rows;
    }

    /*
     * The four header readers.
     *
     * Three deliberate differences from upstream, verified against it over 136 payloads — every
     * format at its edges, truncated, wrong-magic, zero-sized, random bytes:
     *
     * - **A header that says zero is read as no header at all.** Upstream hands back `{0, 0}`.
     *   These bytes come from outside (a model's image, a hook's screenshot), and a picture 0 wide
     *   and 20,480 tall once asked the renderer for 150,000 lines on every frame. Null instead, and
     *   `Components\Image` already has the path for it: the size it assumes for a header it could
     *   not read.
     * - **Base64 is decoded strictly**: a payload that is not base64 at all is a miss, not
     *   silently decoded into whatever bytes could be salvaged from it.
     * - **The GIF signature is compared byte for byte**, where upstream reads it as ASCII: Node's
     *   `toString("ascii")` masks the high bit, so `\xc7IF87a` is `GIF87a` to upstream.
     */

    /** IHDR is always the first chunk, so width and height are at a fixed offset. */
    public static function getPngDimensions(string $base64Data): ?ImageDimensions
    {
        $bytes = self::decodeImageHeader($base64Data, 24);

        if ($bytes === null || substr($bytes, 0, 4) !== "\x89PNG") {
            return null;
        }

        return self::imageDimensions(self::uint32be($bytes, 16), self::uint32be($bytes, 20));
    }

    /** Walk the segment chain to the frame header (SOF0 to SOF2). */
    public static function getJpegDimensions(string $base64Data): ?ImageDimensions
    {
        $bytes = self::decodeImageHeader($base64Data, 2);

        if ($bytes === null || substr($bytes, 0, 2) !== "\xff\xd8") {
            return null;
        }

        $length = strlen($bytes);
        $offset = 2;

        while ($offset < $length - 9) {
            if ($bytes[$offset] !== "\xff") {
                $offset++;

                continue;
            }

            $marker = ord($bytes[$offset + 1]);

            if ($marker >= 0xc0 && $marker <= 0xc2) {
                return self::imageDimensions(self::uint16be($bytes, $offset + 7), self::uint16be($bytes, $offset + 5));
            }

            $segment = self::uint16be($bytes, $offset + 2);

            if ($segment < 2) {
                return null;
            }

            $offset += 2 + $segment;
        }

        return null;
    }

    public static function getGifDimensions(string $base64Data): ?ImageDimensions
    {
        $bytes = self::decodeImageHeader($base64Data, 10);
        $signature = $bytes === null ? '' : substr($bytes, 0, 6);

        if ($bytes === null || ($signature !== 'GIF87a' && $signature !== 'GIF89a')) {
            return null;
        }

        return self::imageDimensions(self::uint16le($bytes, 6), self::uint16le($bytes, 8));
    }

    /**
     * WebP, in its three shapes: lossy keeps the size in the VP8 frame tag, lossless packs both
     * into fourteen bits each of one little-endian word, and the extended form has a 24-bit pair.
     */
    public static function getWebpDimensions(string $base64Data): ?ImageDimensions
    {
        $bytes = self::decodeImageHeader($base64Data, 30);

        if ($bytes === null || substr($bytes, 0, 4) !== 'RIFF' || substr($bytes, 8, 4) !== 'WEBP') {
            return null;
        }

        switch (substr($bytes, 12, 4)) {
            case 'VP8 ':
                return self::imageDimensions(self::uint16le($bytes, 26) & 0x3fff, self::uint16le($bytes, 28) & 0x3fff);
            case 'VP8L':
                $bits = self::uint32le($bytes, 21);

                return self::imageDimensions(($bits & 0x3fff) + 1, (($bits >> 14) & 0x3fff) + 1);
            case 'VP8X':
                return self::imageDimensions(self::uint24le($bytes, 24) + 1, self::uint24le($bytes, 27) + 1);
            default:
                return null;
        }
    }

    public static function getImageDimensions(string $base64Data, string $mimeType): ?ImageDimensions
    {
        return match ($mimeType) {
            'image/png' => self::getPngDimensions($base64Data),
            'image/jpeg' => self::getJpegDimensions($base64Data),
            'image/gif' => self::getGifDimensions($base64Data),
            'image/webp' => self::getWebpDimensions($base64Data),
            default => null,
        };
    }

    /** A size, or null when either half of it is zero — the one place that rule lives. */
    private static function imageDimensions(int $width, int $height): ?ImageDimensions
    {
        return $width > 0 && $height > 0 ? new ImageDimensions($width, $height) : null;
    }

    /** Decoded bytes, or null when the data is not base64 or too short for a header. */
    private static function decodeImageHeader(string $base64Data, int $minimum): ?string
    {
        $bytes = base64_decode($base64Data, true);

        if ($bytes === false || strlen($bytes) < $minimum) {
            return null;
        }

        return $bytes;
    }

    private static function uint32be(string $bytes, int $offset): int
    {
        return (int) unpack('N', substr($bytes, $offset, 4))[1];
    }

    private static function uint16be(string $bytes, int $offset): int
    {
        return (int) unpack('n', substr($bytes, $offset, 2))[1];
    }

    private static function uint16le(string $bytes, int $offset): int
    {
        return (int) unpack('v', substr($bytes, $offset, 2))[1];
    }

    private static function uint32le(string $bytes, int $offset): int
    {
        return (int) unpack('V', substr($bytes, $offset, 4))[1];
    }

    private static function uint24le(string $bytes, int $offset): int
    {
        return ord($bytes[$offset]) | (ord($bytes[$offset + 1]) << 8) | (ord($bytes[$offset + 2]) << 16);
    }

    public static function renderImage(
        string $base64Data,
        ImageDimensions $imageDimensions,
        ?ImageRenderOptions $options = null,
    ): ?ImageRenderResult {
        $options ??= new ImageRenderOptions();
        $caps = self::getCapabilities();

        if ($caps->images === null) {
            return null;
        }

        $maxWidth = $options->maxWidthCells ?? 80;
        // Reduce Kitty's cell-aligned distortion without shrinking iTerm2 reservations.
        $size = self::calculateImageCellSize(
            $imageDimensions,
            $maxWidth,
            $options->maxHeightCells,
            self::getCellDimensions(),
            $caps->images === ImageProtocol::Kitty,
        );

        if ($caps->images === ImageProtocol::Kitty) {
            if ($options->imageId !== null) {
                self::registerKittyImageMetadata(new KittyImageMetadata(
                    imageId: $options->imageId,
                    columns: $size->columns,
                    rows: $size->rows,
                    widthPx: $imageDimensions->widthPx,
                    heightPx: $imageDimensions->heightPx,
                ));
            }
            $sequence = self::encodeKitty(
                $base64Data,
                columns: $size->columns,
                rows: $size->rows,
                imageId: $options->imageId,
                moveCursor: $options->moveCursor ?? true,
            );

            return new ImageRenderResult($sequence, $size->columns, $size->rows, $options->imageId);
        }

        $sequence = self::encodeITerm2(
            $base64Data,
            width: $size->columns,
            height: 'auto',
            preserveAspectRatio: $options->preserveAspectRatio ?? true,
        );

        return new ImageRenderResult($sequence, $size->columns, $size->rows);
    }

    /**
     * Wrap text in an OSC 8 hyperlink sequence.
     * The text is rendered as a clickable hyperlink in terminals that support OSC 8
     * (Ghostty, Kitty, WezTerm, iTerm2, VSCode, and others).
     * In terminals that do not support OSC 8, the escape sequences are ignored
     * and only the plain text is displayed.
     */
    public static function hyperlink(string $text, string $url): string
    {
        return "\x1b]8;;{$url}\x1b\\{$text}\x1b]8;;\x1b\\";
    }

    /** Shorten home-prefixed absolute paths to ~/... for compact display. */
    private static function shortenImagePath(string $filename): string
    {
        $home = Env::home();
        if ($home !== null && ($filename === $home || str_starts_with($filename, "{$home}/") || str_starts_with($filename, "{$home}\\"))) {
            return '~' . substr($filename, strlen($home));
        }

        return $filename;
    }

    /**
     * Node's `pathToFileURL(path).href` for an absolute POSIX path: each segment percent-encoded.
     * `rawurlencode()` encodes a few characters Node leaves alone (`!`, `(`, `)`, `*`, `'`), which
     * a terminal's link handler decodes the same way.
     */
    private static function pathToFileUrl(string $path): string
    {
        return 'file://' . implode('/', array_map(rawurlencode(...), explode('/', $path)));
    }

    /**
     * Text fallback when the terminal cannot render inline images.
     * Absolute paths are shown shortened (~/...) and, when OSC 8 hyperlinks are
     * available, linked to file:// so the full path remains openable.
     *
     * An empty filename is left out rather than joined, which is upstream's `if (filename)`:
     * `[Image:  [image/png]]`, with two spaces, looks like the name went missing.
     */
    public static function imageFallback(string $mimeType, ?ImageDimensions $dimensions = null, ?string $filename = null): string
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

        $parts = [];
        if ($filename !== null && $filename !== '') {
            $display = self::shortenImagePath($filename);
            if (self::getCapabilities()->hyperlinks && str_starts_with($filename, '/')) {
                $parts[] = self::hyperlink($display, self::pathToFileUrl($filename));
            } else {
                $parts[] = $display;
            }
        }
        $parts[] = "[{$mimeType}]";
        if ($dimensions !== null) {
            $parts[] = "{$dimensions->widthPx}x{$dimensions->heightPx}";
        }

        return '[Image: ' . implode(' ', $parts) . ']';
    }

    /**
     * The terminal's reply to `CSI 16 t`, if the data holds one — pig's half of upstream's
     * `consumeCellSizeResponse()` in `tui.ts`.
     *
     * Upstream's input arrives already split into single sequences, so it matches the whole
     * string; pig's reads are not split, so `TuiBase` buffers and looks for the reply inside
     * whatever arrived. The reply is `CSI 6 ; height ; width t` — height first.
     */
    public static function parseCellSizeReply(string $data): ?CellDimensions
    {
        if (preg_match('/\x1b\[6;(\d+);(\d+)t/', $data, $match) !== 1) {
            return null;
        }

        $heightPx = (int) $match[1];
        $widthPx = (int) $match[2];

        return $heightPx > 0 && $widthPx > 0 ? new CellDimensions($widthPx, $heightPx) : null;
    }
}
