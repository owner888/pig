<?php

declare(strict_types=1);

namespace Pig\Tui\Test;

use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pig\Tui\Components\Image;
use Pig\Tui\Components\ImageOptions;
use Pig\Tui\Components\ImageTheme;
use Pig\Tui\Images\CellDimensions;
use Pig\Tui\Images\ImageDimensions;
use Pig\Tui\Images\ImageProtocol;
use Pig\Tui\Images\ImageRenderOptions;
use Pig\Tui\Images\KittyImageMetadata;
use Pig\Tui\Images\TerminalCapabilities;
use Pig\Tui\Images\TerminalImage;
use Pig\Tui\Width;

/**
 * Upstream's `terminal-image.test.ts`, plus pig's own environment cases: an exported-but-empty
 * variable is not presence, names match without regard to case, and `PIG_` overrides come before
 * upstream's `PI_` ones.
 *
 * Upstream's `withEnv()` clears every variable detection reads around one call; here `setUp()`
 * clears them for the whole test and `tearDown()` puts them back.
 */
final class TerminalImageTest extends TestCase
{
    private const array ENV_KEYS = [
        'TERM',
        'TERM_PROGRAM',
        'TERMINAL_EMULATOR',
        'COLORTERM',
        'TMUX',
        'KITTY_WINDOW_ID',
        'GHOSTTY_RESOURCES_DIR',
        'WEZTERM_PANE',
        'ITERM_SESSION_ID',
        'WT_SESSION',
        'CMUX_WORKSPACE_ID',
        'WARP_SESSION_ID',
        'WARP_TERMINAL_SESSION_UUID',
        'PI_HYPERLINKS',
        'PI_IMAGE_PROTOCOL',
        'PI_TRUE_COLOR',
        'PIG_HYPERLINKS',
        'PIG_IMAGE_PROTOCOL',
        'PIG_TRUE_COLOR',
        'HOME',
    ];

    /** @var array<string, string|false> */
    private array $saved = [];

    /** @var list<string> */
    private array $calls = [];

    #[\Override]
    protected function setUp(): void
    {
        foreach (self::ENV_KEYS as $name) {
            $this->saved[$name] = getenv($name);
            putenv($name);
        }
        putenv('HOME=/home/tester');
        TerminalImage::setCapabilityOverrides([]);
        TerminalImage::resetCapabilitiesCache();
        TerminalImage::setCellDimensions(new CellDimensions(9, 18));
    }

    #[\Override]
    protected function tearDown(): void
    {
        foreach ($this->saved as $name => $value) {
            putenv($value === false ? $name : "{$name}={$value}");
        }
        Image::setImageTranscoder(null);
        TerminalImage::setCapabilityOverrides([]);
        TerminalImage::resetCapabilitiesCache();
        TerminalImage::setCellDimensions(new CellDimensions(9, 18));
    }

    /** @param array<string, string> $environment */
    private static function env(array $environment): void
    {
        foreach ($environment as $name => $value) {
            putenv("{$name}={$value}");
        }
    }

    private static function caps(?ImageProtocol $images, bool $trueColor, bool $hyperlinks): TerminalCapabilities
    {
        return new TerminalCapabilities($images, $trueColor, $hyperlinks);
    }

    private static function theme(?Closure $fallbackColor = null): ImageTheme
    {
        return new ImageTheme($fallbackColor ?? static fn (string $value): string => $value);
    }

    // ---- isImageLine ---------------------------------------------------------------

    /** @return array<string, array{string, bool}> */
    public static function imageLines(): array
    {
        return [
            'iTerm2: should detect iTerm2 image escape sequence at start of line' => ["\x1b]1337;File=size=100,100;inline=1:base64encodeddata==\x07", true],
            'iTerm2: should detect iTerm2 image escape sequence with text before it' => ["Some text \x1b]1337;File=size=100,100;inline=1:base64data==\x07 more text", true],
            'iTerm2: should detect iTerm2 image escape sequence in middle of long line' => ['Text before image...' . "\x1b]1337;File=inline=1:verylongbase64data==" . '...text after', true],
            'iTerm2: should detect iTerm2 image escape sequence at end of line' => ["Regular text ending with \x1b]1337;File=inline=1:base64data==\x07", true],
            'iTerm2: should detect minimal iTerm2 image escape sequence' => ["\x1b]1337;File=:\x07", true],
            'Kitty: should detect Kitty image escape sequence at start of line' => ["\x1b_Ga=T,f=100,t=f,d=base64data...\x1b\\\x1b_Gm=i=1;\x1b\\", true],
            'Kitty: should detect Kitty image escape sequence with text before it' => ["Output: \x1b_Ga=T,f=100;data...\x1b\\\x1b_Gm=i=1;\x1b\\", true],
            'Kitty: should detect Kitty image escape sequence with padding' => ["  \x1b_Ga=T,f=100...\x1b\\\x1b_Gm=i=1;\x1b\\  ", true],
            'should detect image sequences when terminal doesn\'t support images' => ["Read image file [image/jpeg]\x1b]1337;File=inline=1:base64data==\x07", true],
            'should detect image sequences with ANSI codes before them' => ["\x1b[31mError output \x1b]1337;File=inline=1:image==\x07", true],
            'should detect image sequences with ANSI codes after them' => ["\x1b_Ga=T,f=100:data...\x1b\\\x1b_Gm=i=1;\x1b\\\x1b[0m reset", true],
            'should not detect images in plain text lines' => ['This is just a regular text line without any escape sequences', false],
            'should not detect images in lines with only ANSI codes' => ["\x1b[31mRed text\x1b[0m and \x1b[32mgreen text\x1b[0m", false],
            'should not detect images in lines with cursor movement codes' => ["\x1b[1A\x1b[2KLine cleared and moved up", false],
            'should not detect images in lines with partial iTerm2 sequences' => ['Some text with ]1337;File but missing ESC at start', false],
            'should not detect images in lines with partial Kitty sequences' => ['Some text with _G but missing ESC at start', false],
            'should not detect images in empty lines' => ['', false],
            'should not detect images in lines with newlines only' => ["\n", false],
            'should not detect images in lines with two newlines' => ["\n\n", false],
            'should detect images when line has both Kitty and iTerm2 sequences' => ["Kitty: \x1b_Ga=T...\x1b\\\x1b_Gm=i=1;\x1b\\ iTerm2: \x1b]1337;File=inline=1:data==\x07", true],
            'should detect image in line with multiple text and image segments' => ["Start \x1b]1337;File=img1==\x07 middle \x1b]1337;File=img2==\x07 end", true],
            'should not falsely detect image in line with file path containing keywords' => ['/path/to/File_1337_backup/image.jpg', false],
        ];
    }

    #[DataProvider('imageLines')]
    public function testIsImageLine(string $line, bool $expected): void
    {
        $this->assertSame($expected, TerminalImage::isImageLine($line));
    }

    public function testShouldDetectImageSequencesInVeryLongLines(): void
    {
        $longLine = 'Text prefix ' . "\x1b]1337;File=size=800,600;inline=1:" . str_repeat(str_repeat('A', 100), 3000) . ' suffix';

        $this->assertGreaterThan(300000, strlen($longLine));
        $this->assertTrue(TerminalImage::isImageLine($longLine));
    }

    // ---- detectCapabilities --------------------------------------------------------

    public function testDefaultsToHyperlinksFalseForUnknownTerminals(): void
    {
        $caps = TerminalImage::detectCapabilities();
        $this->assertFalse($caps->hyperlinks);
        $this->assertNull($caps->images);
    }

    public function testAppliesEnvironmentOverrides(): void
    {
        self::env(['PI_HYPERLINKS' => '1', 'PI_IMAGE_PROTOCOL' => 'kitty', 'PI_TRUE_COLOR' => '1']);
        $this->assertEquals(self::caps(ImageProtocol::Kitty, true, true), TerminalImage::detectCapabilities());

        self::env(['TERM_PROGRAM' => 'iterm.app', 'PI_HYPERLINKS' => '0', 'PI_IMAGE_PROTOCOL' => 'none', 'PI_TRUE_COLOR' => '0']);
        $this->assertEquals(self::caps(null, false, false), TerminalImage::detectCapabilities());
    }

    public function testPreservesAutoDetectionForAutoEnvironmentOverrides(): void
    {
        self::env(['TERM_PROGRAM' => 'ghostty', 'PI_HYPERLINKS' => 'auto', 'PI_IMAGE_PROTOCOL' => 'auto', 'PI_TRUE_COLOR' => 'auto']);

        $this->assertEquals(self::caps(ImageProtocol::Kitty, true, true), TerminalImage::detectCapabilities());
    }

    public function testAppliesAndClearsProgrammaticOverrides(): void
    {
        self::env(['PI_HYPERLINKS' => '1', 'PI_IMAGE_PROTOCOL' => 'kitty', 'PI_TRUE_COLOR' => '1']);
        TerminalImage::setCapabilityOverrides(['images' => null, 'trueColor' => false, 'hyperlinks' => false]);
        $this->assertEquals(self::caps(null, false, false), TerminalImage::getCapabilities());
        TerminalImage::setCapabilityOverrides([]);
        $this->assertEquals(self::caps(ImageProtocol::Kitty, true, true), TerminalImage::getCapabilities());
    }

    public function testBypassesTheTmuxProbeWhenHyperlinksAreOverridden(): void
    {
        self::env(['TMUX' => '/tmp/tmux-1000/default,1234,0', 'PI_HYPERLINKS' => '1', 'PI_IMAGE_PROTOCOL' => 'kitty']);
        $probed = false;
        $caps = TerminalImage::detectCapabilities(static function () use (&$probed): bool {
            $probed = true;

            return false;
        });

        $this->assertFalse($probed);
        $this->assertTrue($caps->hyperlinks);
        $this->assertSame(ImageProtocol::Kitty, $caps->images);
    }

    public function testEnablesHyperlinksUnderTmuxWhenTheClientForwardsThem(): void
    {
        self::env(['TMUX' => '/tmp/tmux-1000/default,1234,0', 'TERM_PROGRAM' => 'ghostty']);
        $caps = TerminalImage::detectCapabilities(static fn (): bool => true);

        $this->assertTrue($caps->hyperlinks);
        $this->assertNull($caps->images);
    }

    public function testDisablesHyperlinksUnderTmuxWhenTheClientDoesNotForwardThem(): void
    {
        self::env(['TMUX' => '/tmp/tmux-1000/default,1234,0', 'TERM_PROGRAM' => 'ghostty']);
        $caps = TerminalImage::detectCapabilities(static fn (): bool => false);

        $this->assertFalse($caps->hyperlinks);
        $this->assertNull($caps->images);
    }

    public function testChecksTmuxCapabilityWhenTermStartsWithTmux(): void
    {
        self::env(['TERM' => 'tmux-256color', 'TERM_PROGRAM' => 'iterm.app']);
        $caps = TerminalImage::detectCapabilities(static fn (): bool => true);
        $this->assertTrue($caps->hyperlinks);
        $this->assertNull($caps->images);

        $this->assertFalse(TerminalImage::detectCapabilities(static fn (): bool => false)->hyperlinks);
    }

    public function testForcesHyperlinksFalseWhenTermStartsWithScreen(): void
    {
        self::env(['TERM' => 'screen-256color']);
        $caps = TerminalImage::detectCapabilities();

        $this->assertFalse($caps->hyperlinks);
        $this->assertNull($caps->images);
    }

    /** @return array<string, array{array<string, string>, TerminalCapabilities}> */
    public static function terminals(): array
    {
        $kitty = self::caps(ImageProtocol::Kitty, true, true);

        return [
            'enables hyperlinks for Ghostty' => [['TERM_PROGRAM' => 'ghostty'], $kitty],
            'does not disable Ghostty images solely because cmux is present' => [['TERM_PROGRAM' => 'ghostty', 'CMUX_WORKSPACE_ID' => 'workspace'], $kitty],
            'enables hyperlinks for Kitty' => [['KITTY_WINDOW_ID' => '1'], $kitty],
            'enables hyperlinks for WezTerm' => [['WEZTERM_PANE' => '0'], $kitty],
            'enables images and hyperlinks for Warp via TERM_PROGRAM' => [['TERM_PROGRAM' => 'WarpTerminal'], $kitty],
            'enables images and hyperlinks for Warp via WARP_SESSION_ID' => [['WARP_SESSION_ID' => 'some-session-id'], $kitty],
            'enables images and hyperlinks for Warp via WARP_TERMINAL_SESSION_UUID' => [['WARP_TERMINAL_SESSION_UUID' => 'd0e1a2e5-7ca7-44cd-9037-ac7222011161'], $kitty],
            'enables hyperlinks for iTerm2' => [['TERM_PROGRAM' => 'iterm.app'], self::caps(ImageProtocol::ITerm2, true, true)],
            'enables hyperlinks for VSCode' => [['TERM_PROGRAM' => 'vscode'], self::caps(null, true, true)],
            'enables Alacritty capabilities for Zed' => [['TERM_PROGRAM' => 'zed'], self::caps(null, true, true)],
            'enables truecolor and hyperlinks for Windows Terminal outside multiplexers' => [['WT_SESSION' => 'session', 'TERM' => 'xterm-256color'], self::caps(null, true, true)],
            'enables truecolor without hyperlinks for JetBrains terminal' => [['TERMINAL_EMULATOR' => 'JetBrains-JediTerm', 'TERM' => 'xterm-256color'], self::caps(null, true, false)],
            // pig: the names are matched without regard to case.
            'matches iTerm2 by name without regard to case' => [['TERM_PROGRAM' => 'ITERM.APP'], self::caps(ImageProtocol::ITerm2, true, true)],
            'ghostty by TERM' => [['TERM' => 'xterm-ghostty'], $kitty],
            'ghostty by its resources dir' => [['GHOSTTY_RESOURCES_DIR' => '/opt/ghostty'], $kitty],
            'iterm2 by its session id' => [['ITERM_SESSION_ID' => 'w0t0p0'], self::caps(ImageProtocol::ITerm2, true, true)],
        ];
    }

    /** @param array<string, string> $environment */
    #[DataProvider('terminals')]
    public function testDetectsTheTerminal(array $environment, TerminalCapabilities $expected): void
    {
        self::env($environment);

        $this->assertEquals($expected, TerminalImage::detectCapabilities(static fn (): bool => false));
    }

    public function testDisablesImagesForWarpInsideTmux(): void
    {
        self::env(['TERM_PROGRAM' => 'WarpTerminal', 'TMUX' => '/tmp/tmux-1000/default,1234,0', 'TERM' => 'tmux-256color']);
        $caps = TerminalImage::detectCapabilities(static fn (): bool => true);

        $this->assertNull($caps->images);
        $this->assertTrue($caps->hyperlinks);
    }

    public function testDoesNotInheritWindowsTerminalTruecolorThroughTmux(): void
    {
        self::env(['WT_SESSION' => 'session', 'TMUX' => '/tmp/tmux-1000/default,1234,0', 'TERM' => 'tmux-256color']);

        $this->assertEquals(self::caps(null, false, false), TerminalImage::detectCapabilities(static fn (): bool => false));
    }

    public function testTrustsExplicitTruecolorHintsThroughTmux(): void
    {
        self::env(['COLORTERM' => 'truecolor', 'TMUX' => '/tmp/tmux-1000/default,1234,0', 'TERM' => 'tmux-256color']);

        $this->assertEquals(self::caps(null, true, false), TerminalImage::detectCapabilities(static fn (): bool => false));
    }

    public function testDetectsTruecolorFromDirectColorTermValues(): void
    {
        self::env(['TERM' => 'xterm-direct']);

        $this->assertTrue(TerminalImage::detectCapabilities(static fn (): bool => false)->trueColor);
    }

    /** @return array<string, array{string}> */
    public static function presenceVariables(): array
    {
        return [
            'KITTY_WINDOW_ID' => ['KITTY_WINDOW_ID'],
            'GHOSTTY_RESOURCES_DIR' => ['GHOSTTY_RESOURCES_DIR'],
            'WEZTERM_PANE' => ['WEZTERM_PANE'],
            'ITERM_SESSION_ID' => ['ITERM_SESSION_ID'],
            'WARP_SESSION_ID' => ['WARP_SESSION_ID'],
            'TMUX' => ['TMUX'],
        ];
    }

    /**
     * pig: an exported-but-empty variable is not presence. JavaScript reads one as falsy and so
     * upstream ignores it; `getenv()` answers `''`, so a bare `export ITERM_SESSION_ID` made pig
     * send iTerm2 image sequences to a terminal that cannot draw them.
     */
    #[DataProvider('presenceVariables')]
    public function testAnExportedButEmptyVariableIsNotATerminal(string $name): void
    {
        putenv("{$name}=");

        $this->assertEquals(self::caps(null, false, false), TerminalImage::detectCapabilities(static fn (): bool => true), "{$name} is set but empty");
    }

    public function testPigOverridesComeBeforeUpstreamOnes(): void
    {
        // `PIG_HYPERLINKS=0` must win over `PI_HYPERLINKS=1` — `'0'` is falsy, so a `?:` chain
        // would have fallen through to the `PI_` value.
        self::env([
            'PIG_HYPERLINKS' => '0', 'PI_HYPERLINKS' => '1',
            'PIG_IMAGE_PROTOCOL' => 'iterm2', 'PI_IMAGE_PROTOCOL' => 'kitty',
            'PIG_TRUE_COLOR' => '0', 'PI_TRUE_COLOR' => '1',
        ]);

        $this->assertEquals(self::caps(ImageProtocol::ITerm2, false, false), TerminalImage::detectCapabilities());
    }

    // ---- encoding ------------------------------------------------------------------

    public function testIncludesTheDecodedPayloadSizeInOsc1337Metadata(): void
    {
        $this->assertSame(
            "\x1b]1337;File=inline=1;size=3;width=2;height=auto:AAAA\x07",
            TerminalImage::encodeITerm2('AAAA', width: 2, height: 'auto'),
        );
    }

    public function testCanRequestNoTerminalSideCursorMovement(): void
    {
        $sequence = TerminalImage::encodeKitty('AAAA', columns: 2, rows: 2, moveCursor: false);

        $this->assertStringStartsWith("\x1b_Ga=T,f=100,q=2,C=1,c=2,r=2;", $sequence);
    }

    public function testReadsExplicitPlacementRowsWithoutRegisteredMetadata(): void
    {
        $sequence = TerminalImage::encodeKitty('AAAA', columns: 2, rows: 3, moveCursor: false);

        $this->assertSame(3, TerminalImage::getKittyImagePlacementRows($sequence));
    }

    public function testSuppressesKittyRepliesForDeleteCommands(): void
    {
        $this->assertSame("\x1b_Ga=d,d=I,i=42,q=2\x1b\\", TerminalImage::deleteKittyImage(42));
        $this->assertSame("\x1b_Ga=d,d=A,q=2\x1b\\", TerminalImage::deleteAllKittyImages());
        $this->assertSame("\x1b_Ga=d,d=a,q=2\x1b\\", TerminalImage::deleteAllKittyPlacements());
    }

    public function testPreservesRenderImagesDefaultTerminalSideCursorMovement(): void
    {
        TerminalImage::setCapabilities(self::caps(ImageProtocol::Kitty, true, true));
        TerminalImage::setCellDimensions(new CellDimensions(10, 10));
        $result = TerminalImage::renderImage('AAAA', new ImageDimensions(20, 20), new ImageRenderOptions(maxWidthCells: 2));

        $this->assertNotNull($result);
        $this->assertStringNotContainsString(',C=1,', $result->sequence);
        $this->assertSame(2, $result->rows);
    }

    public function testCanOptRenderImageIntoNoTerminalSideCursorMovement(): void
    {
        TerminalImage::setCapabilities(self::caps(ImageProtocol::Kitty, true, true));
        TerminalImage::setCellDimensions(new CellDimensions(10, 10));
        $result = TerminalImage::renderImage('AAAA', new ImageDimensions(20, 20), new ImageRenderOptions(maxWidthCells: 2, moveCursor: false));

        $this->assertNotNull($result);
        $this->assertStringContainsString(',C=1,', $result->sequence);
        $this->assertSame(2, $result->rows);
    }

    public function testRegistersMetadataAndCropsAPartiallyVisiblePlacement(): void
    {
        TerminalImage::setCapabilities(self::caps(ImageProtocol::Kitty, true, true));
        TerminalImage::setCellDimensions(new CellDimensions(10, 10));
        $result = TerminalImage::renderImage(
            'AAAA',
            new ImageDimensions(100, 100),
            new ImageRenderOptions(maxWidthCells: 3, imageId: 42, moveCursor: false),
        );

        $this->assertNotNull($result);
        $this->assertEquals(new KittyImageMetadata(42, 3, 3, 100, 100), TerminalImage::getKittyImageMetadata($result->sequence));
        $this->assertStringContainsString('y=66,h=34,r=1', TerminalImage::cropKittyImageLine($result->sequence, 2, 1));
    }

    public function testCreatesPlacementOnlyCommandsForUploadedAndCroppedImages(): void
    {
        TerminalImage::registerKittyImageMetadata(new KittyImageMetadata(42, 3, 3, 100, 100));
        $transmission = TerminalImage::encodeKitty(str_repeat('A', 8192), columns: 3, rows: 3, imageId: 42, moveCursor: false);
        $line = 'left ' . TerminalImage::cropKittyImageLine($transmission, 2, 1) . ' right';
        $placement = TerminalImage::getKittyImagePlacement($line);

        $this->assertNotNull($placement);
        $this->assertSame(1, TerminalImage::getKittyImagePlacementRows($line));
        $this->assertSame(strlen($line) - strlen('left ') - strlen(' right'), $placement->transmissionBytes);
        $this->assertSame(100 * 100 * 4, $placement->estimatedDecodedBytes);
        $this->assertSame(1, $placement->rows);
        $this->assertSame("\x1b_Ga=p,q=2,C=1,c=3,i=42,y=66,h=34,r=1\x1b\\", $placement->sequence);
        $this->assertSame("left {$placement->sequence} right", $placement->replacementLine);
        $this->assertStringNotContainsString('AAAA', $placement->replacementLine);
    }

    public function testHonorsMaxHeightCellsByReducingRenderedWidth(): void
    {
        TerminalImage::setCapabilities(self::caps(ImageProtocol::Kitty, true, true));
        TerminalImage::setCellDimensions(new CellDimensions(10, 10));
        $result = TerminalImage::renderImage('AAAA', new ImageDimensions(10, 100), new ImageRenderOptions(maxWidthCells: 10, maxHeightCells: 5));

        $this->assertNotNull($result);
        $this->assertSame(5, $result->rows);
        $this->assertStringContainsString(',c=1,r=5', $result->sequence);
    }

    public function testCapsImageComponentHeightToASquarePixelBoxByDefault(): void
    {
        TerminalImage::setCapabilities(self::caps(ImageProtocol::Kitty, true, true));
        TerminalImage::setCellDimensions(new CellDimensions(10, 20));
        $image = new Image('AAAA', 'image/png', self::theme(), new ImageOptions(maxWidthCells: 10), new ImageDimensions(10, 100));
        $lines = $image->render(12);

        $this->assertCount(5, $lines);
        $this->assertStringContainsString(',c=1,r=5', $lines[0]);
    }

    public function testPlacesImageSequenceOnFirstLineWithEmptyPaddingRows(): void
    {
        TerminalImage::setCapabilities(self::caps(ImageProtocol::Kitty, true, true));
        TerminalImage::setCellDimensions(new CellDimensions(10, 10));
        $image = new Image('AAAA', 'image/png', self::theme(), new ImageOptions(maxWidthCells: 2), new ImageDimensions(20, 20));
        $lines = $image->render(4);
        $imageId = $image->getImageId();

        $this->assertIsInt($imageId);
        $this->assertStringStartsWith("\x1b_G", $lines[0]);
        $this->assertStringContainsString(',C=1,', $lines[0]);
        $this->assertStringContainsString(",i={$imageId}", $lines[0]);
        $this->assertStringEndsWith("\x1b\\", $lines[0]);
        $this->assertSame([''], array_slice($lines, 1));
    }

    public function testTruncatesLongImageFallbackLinesToRenderWidth(): void
    {
        TerminalImage::setCapabilities(self::caps(null, false, false));
        $longPath = '/home/tester/images/' . str_repeat('generated-image-with-a-very-long-absolute-path', 4) . '.png';
        $width = 40;
        $image = new Image(
            'AAAA',
            'image/png',
            self::theme(static fn (string $value): string => "\x1b[33m{$value}\x1b[0m"),
            new ImageOptions(filename: $longPath),
            new ImageDimensions(1280, 720),
        );
        $lines = $image->render($width);

        $this->assertCount(1, $lines);
        $this->assertLessThanOrEqual($width, Width::visible($lines[0]), "fallback line wider than {$width}: " . json_encode($lines[0]));
        $this->assertStringContainsString('...', $lines[0], 'expected ellipsis when truncating long fallback path');
        $this->assertStringContainsString('~', $lines[0], 'expected home-shortened path in fallback');
    }

    // ---- image cell sizing (#8938) -------------------------------------------------

    private function kitty(): void
    {
        TerminalImage::setCapabilities(self::caps(ImageProtocol::Kitty, true, true));
    }

    public function testReservesAtLeastOneKittyRowForThinImages(): void
    {
        $this->kitty();
        $result = TerminalImage::renderImage('AAAA', new ImageDimensions(1200, 12), new ImageRenderOptions(maxWidthCells: 60));

        $this->assertNotNull($result);
        $this->assertSame(1, $result->rows);
        $this->assertStringContainsString(',c=60,r=1;', $result->sequence);
    }

    public function testKeepsKittyPlacementReservedLinesAndCroppingMetadataConsistentAcrossWidthChanges(): void
    {
        $this->kitty();
        $image = new Image('AAAA', 'image/png', self::theme(), new ImageOptions(maxWidthCells: 60, imageId: 8938), new ImageDimensions(615, 86));
        $lines = $image->render(62);

        $this->assertCount(4, $lines);
        $this->assertSame(['', '', ''], array_slice($lines, 1));
        $this->assertStringContainsString(',c=60,r=4,i=8938;', $lines[0]);
        $this->assertEquals(new KittyImageMetadata(8938, 60, 4, 615, 86), TerminalImage::getKittyImageMetadata($lines[0]));
        $cropped = TerminalImage::cropKittyImageLine($lines[0], 1, 2);
        $this->assertSame("\x1b_Ga=p,q=2,C=1,c=60,i=8938,y=21,h=44,r=2\x1b\\", TerminalImage::getKittyImagePlacement($cropped)?->sequence);

        $narrowerLines = $image->render(32);
        $this->assertCount(2, $narrowerLines);
        $this->assertStringContainsString(',c=30,r=2,i=8938;', $narrowerLines[0]);
        $this->assertSame(2, TerminalImage::getKittyImageMetadata($narrowerLines[0])?->rows);
    }

    public function testKeepsTheCeilingPlacementWhenRoundingDownWouldIncreaseDistortion(): void
    {
        $this->kitty();
        TerminalImage::setCellDimensions(new CellDimensions(15, 28));
        $result = TerminalImage::renderImage('AAAA', new ImageDimensions(615, 86), new ImageRenderOptions(maxWidthCells: 60));

        $this->assertNotNull($result);
        $this->assertSame(5, $result->rows);
        $this->assertStringContainsString(',c=60,r=5;', $result->sequence);
    }

    public function testKeepsHeightLimitedKittyColumnsReservationsAndCropMetadataConsistent(): void
    {
        $this->kitty();
        TerminalImage::setCellDimensions(new CellDimensions(14, 28));
        $image = new Image('AAAA', 'image/png', self::theme(), new ImageOptions(maxWidthCells: 30, imageId: 8938), new ImageDimensions(400, 900));
        $lines = $image->render(32);

        $this->assertCount(15, $lines);
        $this->assertStringContainsString(',c=13,r=15,i=8938;', $lines[0]);
        $this->assertEquals(new KittyImageMetadata(8938, 13, 15, 400, 900), TerminalImage::getKittyImageMetadata($lines[0]));
        $this->assertSame(
            "\x1b_Ga=p,q=2,C=1,c=13,i=8938,y=60,h=120,r=2\x1b\\",
            TerminalImage::getKittyImagePlacement(TerminalImage::cropKittyImageLine($lines[0], 1, 2))?->sequence,
        );
        $narrowerLines = $image->render(22);
        $this->assertCount(10, $narrowerLines);
        $this->assertStringContainsString(',c=9,r=10,i=8938;', $narrowerLines[0]);
    }

    public function testChoosesThinKittyWidthsByProportionsWhileKeepingAtLeastOneColumn(): void
    {
        $this->kitty();
        TerminalImage::setCellDimensions(new CellDimensions(1, 1));
        foreach ([[1, 1], [140, 1], [149, 2]] as [$widthPx, $columns]) {
            $result = TerminalImage::renderImage('AAAA', new ImageDimensions($widthPx, 1000), new ImageRenderOptions(maxWidthCells: 30, maxHeightCells: 10));
            $this->assertNotNull($result);
            $this->assertSame($columns, $result->columns);
            $this->assertSame(10, $result->rows);
            $this->assertStringContainsString(",c={$columns},r=10;", $result->sequence);
        }
    }

    public function testKeepsITerm2sCeilingWidthWhenHeightLimited(): void
    {
        TerminalImage::setCapabilities(self::caps(ImageProtocol::ITerm2, true, true));
        TerminalImage::setCellDimensions(new CellDimensions(14, 28));
        $result = TerminalImage::renderImage('AAAA', new ImageDimensions(400, 900), new ImageRenderOptions(maxWidthCells: 30, maxHeightCells: 15));

        $this->assertNotNull($result);
        $this->assertSame(14, $result->columns);
        $this->assertSame(15, $result->rows);
        $this->assertSame("\x1b]1337;File=inline=1;size=3;width=14;height=auto:AAAA\x07", $result->sequence);
    }

    public function testKeepsITerm2sCeilingBasedReservedLinesAndCursorOffset(): void
    {
        TerminalImage::setCapabilities(self::caps(ImageProtocol::ITerm2, true, true));
        $image = new Image('AAAA', 'image/png', self::theme(), new ImageOptions(maxWidthCells: 60), new ImageDimensions(615, 86));

        $this->assertSame(
            ['', '', '', '', "\x1b[4A\x1b]1337;File=inline=1;size=3;width=60;height=auto:AAAA\x07"],
            $image->render(62),
        );
    }

    // ---- imageFallback -------------------------------------------------------------

    public function testShortensHomePrefixedAbsolutePathsWithoutHyperlinks(): void
    {
        TerminalImage::setCapabilities(self::caps(null, false, false));

        $this->assertSame(
            '[Image: ~/.pi/agent/shot.png [image/png] 1280x720]',
            TerminalImage::imageFallback('image/png', new ImageDimensions(1280, 720), '/home/tester/.pi/agent/shot.png'),
        );
    }

    public function testWrapsShortenedAbsolutePathsInOsc8FileLinksWhenHyperlinksAreEnabled(): void
    {
        TerminalImage::setCapabilities(self::caps(null, false, true));
        $abs = '/home/tester/.pi/agent/shot.png';
        $result = TerminalImage::imageFallback('image/png', new ImageDimensions(10, 10), $abs);

        $this->assertStringContainsString("\x1b]8;;file://", $result, 'expected OSC 8 file link');
        $this->assertStringContainsString($abs, $result, 'file URL should target absolute path');
        // Visible text must use ~/... not the expanded home path.
        $this->assertSame('[Image: ~/.pi/agent/shot.png [image/png] 10x10]', preg_replace('/\x1b\]8;;.*?\x1b\\\\/', '', $result));
    }

    public function testLeavesBareBasenamesUnchangedAndDoesNotHyperlinkThem(): void
    {
        TerminalImage::setCapabilities(self::caps(null, false, true));
        $result = TerminalImage::imageFallback('image/png', new ImageDimensions(1, 1), 'clankolas.png');

        $this->assertSame('[Image: clankolas.png [image/png] 1x1]', $result);
        $this->assertStringNotContainsString("\x1b]8;", $result, 'basename must not be hyperlinked');
    }

    public function testOmitsFilenameSegmentWhenNotProvided(): void
    {
        TerminalImage::setCapabilities(self::caps(null, false, false));

        $this->assertSame('[Image: [image/png] 8x6]', TerminalImage::imageFallback('image/png', new ImageDimensions(8, 6)));
    }

    // ---- Image transcoding (#10292) ------------------------------------------------
    // Kitty only accepts PNG (f=100); non-PNG images must be transcoded.

    private static function jpeg(): string
    {
        return base64_encode('jpeg');
    }

    /** Minimal PNG header (signature + IHDR) for a 40x10 image. Enough for getPngDimensions. */
    private static function png(): string
    {
        return base64_encode((string) hex2bin('89504e470d0a1a0a0000000d49484452000000280000000a'));
    }

    /** @return list<string> */
    private static function renderTranscoded(string $data, string $mimeType): array
    {
        return (new Image($data, $mimeType, self::theme(), new ImageOptions(), new ImageDimensions(20, 20)))->render(20);
    }

    private function transcode(): Closure
    {
        return function (string $data): ?string {
            $this->calls[] = $data;

            return $data === self::jpeg() ? self::png() : null;
        };
    }

    private function transcoding(): void
    {
        TerminalImage::setCapabilities(self::caps(ImageProtocol::Kitty, true, true));
        TerminalImage::setCellDimensions(new CellDimensions(10, 10));
    }

    public function testSendsConvertedPngDataSizedFromThePng(): void
    {
        $this->transcoding();
        Image::setImageTranscoder($this->transcode());
        $lines = self::renderTranscoded(self::jpeg(), 'image/jpeg');

        $this->assertStringContainsString('f=100', $lines[0]);
        $this->assertStringContainsString(';' . self::png() . "\x1b\\", $lines[0]);
        // 40x10 PNG at 18 columns: 5 rows, not the 18 rows of the 20x20 source dimensions.
        $this->assertCount(5, $lines);
    }

    public function testRendersATextFallbackUntilAWorkingTranscoderIsRegistered(): void
    {
        $this->transcoding();
        $image = new Image(self::jpeg(), 'image/jpeg', self::theme());
        $this->assertMatchesRegularExpression('/^\[Image: \[image\/jpeg\]/', $image->render(80)[0]);
        Image::setImageTranscoder(static fn (): ?string => null);
        $image->invalidate();
        $this->assertMatchesRegularExpression('/^\[Image: \[image\/jpeg\]/', $image->render(80)[0]);
        Image::setImageTranscoder($this->transcode());
        $image->invalidate();
        $this->assertStringContainsString("\x1b_G", $image->render(80)[0]);
    }

    public function testConvertsEachImageOnce(): void
    {
        $this->transcoding();
        Image::setImageTranscoder($this->transcode());
        $image = new Image(self::jpeg(), 'image/jpeg', self::theme());
        $image->render(80);
        self::renderTranscoded(self::jpeg(), 'image/jpeg'); // New instance hits the shared cache.
        for ($i = 0; $i < 40; $i++) {
            self::renderTranscoded("other-{$i}", 'image/jpeg'); // Evicts the shared entry.
        }
        $image->invalidate();
        $image->render(40); // Instance keeps its own PNG.

        $this->assertCount(1, array_filter($this->calls, static fn (string $data): bool => $data === self::jpeg()));
    }

    public function testDoesNotConvertPngDataOrITerm2Output(): void
    {
        $this->transcoding();
        Image::setImageTranscoder($this->transcode());
        $this->assertStringContainsString(';' . self::png() . "\x1b\\", self::renderTranscoded(self::png(), 'image/png')[0]);
        TerminalImage::setCapabilities(self::caps(ImageProtocol::ITerm2, true, true));
        $lines = self::renderTranscoded(self::jpeg(), 'image/jpeg');
        $this->assertStringEndsWith(':' . self::jpeg() . "\x07", $lines[count($lines) - 1]);
        $this->assertSame([], $this->calls);
    }

    // ---- hyperlink -----------------------------------------------------------------

    public function testWrapsTextInOsc8OpenAndCloseSequences(): void
    {
        $this->assertSame(
            "\x1b]8;;https://example.com\x1b\\click me\x1b]8;;\x1b\\",
            TerminalImage::hyperlink('click me', 'https://example.com'),
        );
    }

    public function testPreservesAnsiStylingInsideTheHyperlink(): void
    {
        $styled = "\x1b[4m\x1b[34mclick me\x1b[0m";
        $result = TerminalImage::hyperlink($styled, 'https://example.com');

        $this->assertStringStartsWith("\x1b]8;;https://example.com\x1b\\", $result);
        $this->assertStringContainsString($styled, $result);
        $this->assertStringEndsWith("\x1b]8;;\x1b\\", $result);
    }

    public function testWorksWithEmptyText(): void
    {
        $this->assertSame("\x1b]8;;https://example.com\x1b\\\x1b]8;;\x1b\\", TerminalImage::hyperlink('', 'https://example.com'));
    }

    public function testWorksWithFileUris(): void
    {
        $result = TerminalImage::hyperlink('README.md', 'file:///home/user/README.md');

        $this->assertStringContainsString('file:///home/user/README.md', $result);
        $this->assertStringContainsString('README.md', $result);
    }
}
