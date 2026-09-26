<?php

declare(strict_types=1);

namespace Pig\Tui\Test;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pig\Tui\Images\Capabilities;
use Pig\Tui\Images\ImageProtocol;

/**
 * Which terminal this is, worked out from the environment.
 *
 * Worth its own file for the reason `Config` was: twenty-five lines of environment
 * arithmetic with no test is exactly the size that goes unchecked, and being wrong here is
 * not cosmetic — a terminal wrongly believed to draw pictures gets tens of kilobytes of
 * base64 printed into the transcript instead of a picture.
 */
final class CapabilitiesTest extends TestCase
{
    /** Every variable `detect()` reads, so one test cannot leak into the next. */
    private const array VARIABLES = [
        'TERM_PROGRAM',
        'TERM',
        'COLORTERM',
        'KITTY_WINDOW_ID',
        'GHOSTTY_RESOURCES_DIR',
        'WEZTERM_PANE',
        'ITERM_SESSION_ID',
    ];

    /** @var array<string, string|false> */
    private array $saved = [];

    #[\Override]
    protected function setUp(): void
    {
        foreach (self::VARIABLES as $name) {
            $this->saved[$name] = getenv($name);
            putenv($name);
        }
    }

    #[\Override]
    protected function tearDown(): void
    {
        foreach ($this->saved as $name => $value) {
            if ($value === false) {
                putenv($name);

                continue;
            }

            putenv("{$name}={$value}");
        }
    }

    /** @return array<string, array{array<string, string>, ImageProtocol|null}> */
    public static function terminals(): array
    {
        return [
            'kitty by its window id' => [['KITTY_WINDOW_ID' => '3'], ImageProtocol::Kitty],
            'kitty by name' => [['TERM_PROGRAM' => 'kitty'], ImageProtocol::Kitty],
            'ghostty by name' => [['TERM_PROGRAM' => 'ghostty'], ImageProtocol::Kitty],
            'ghostty by TERM' => [['TERM' => 'xterm-ghostty'], ImageProtocol::Kitty],
            'ghostty by its resources dir' => [['GHOSTTY_RESOURCES_DIR' => '/opt/ghostty'], ImageProtocol::Kitty],
            'wezterm by its pane' => [['WEZTERM_PANE' => '0'], ImageProtocol::Kitty],
            'wezterm by name' => [['TERM_PROGRAM' => 'wezterm'], ImageProtocol::Kitty],
            'iterm2 by its session id' => [['ITERM_SESSION_ID' => 'w0t0p0'], ImageProtocol::ITerm2],
            'iterm2 by name' => [['TERM_PROGRAM' => 'iTerm.app'], ImageProtocol::ITerm2],
            'vscode' => [['TERM_PROGRAM' => 'vscode'], null],
            'alacritty' => [['TERM_PROGRAM' => 'alacritty'], null],
            'anything else' => [['TERM' => 'xterm-256color'], null],
        ];
    }

    /**
     * @param array<string, string> $environment
     */
    #[DataProvider('terminals')]
    public function testTheProtocolIsReadOffTheEnvironment(array $environment, ?ImageProtocol $expected): void
    {
        foreach ($environment as $name => $value) {
            putenv("{$name}={$value}");
        }

        $this->assertSame($expected, Capabilities::detect()->images);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function presenceVariables(): array
    {
        return [
            'KITTY_WINDOW_ID' => ['KITTY_WINDOW_ID'],
            'GHOSTTY_RESOURCES_DIR' => ['GHOSTTY_RESOURCES_DIR'],
            'WEZTERM_PANE' => ['WEZTERM_PANE'],
            'ITERM_SESSION_ID' => ['ITERM_SESSION_ID'],
        ];
    }

    /**
     * Four of the tests here are presence tests, and an exported-but-empty variable is not
     * presence. JavaScript reads one as falsy and so upstream ignores it; `getenv()` answers
     * `''`, which is not `false`, so a bare `export ITERM_SESSION_ID` — or `docker run -e
     * ITERM_SESSION_ID`, or an ssh or tmux environment that forwards the name without a value
     * — made pig send iTerm2 image sequences to a terminal that cannot draw them.
     */
    #[DataProvider('presenceVariables')]
    public function testAnExportedButEmptyVariableIsNotATerminal(string $name): void
    {
        putenv("{$name}=");

        $this->assertNull(Capabilities::detect()->images, "{$name} is set but empty");
    }

    public function testTrueColourIsTakenFromColortermWhenNothingElseSaysSo(): void
    {
        putenv('COLORTERM=truecolor');
        $this->assertTrue(Capabilities::detect()->trueColor);

        putenv('COLORTERM=24bit');
        $this->assertTrue(Capabilities::detect()->trueColor);

        putenv('COLORTERM=');
        $this->assertFalse(Capabilities::detect()->trueColor);
    }

    public function testTheNamesAreMatchedWithoutRegardToCase(): void
    {
        putenv('TERM_PROGRAM=ITERM.APP');

        $this->assertSame(ImageProtocol::ITerm2, Capabilities::detect()->images);
    }

    public function testEveryTerminalIsAssumedToUnderstandHyperlinks(): void
    {
        // Upstream's answer in all seven branches, and the reason there is no eighth:
        // an OSC 8 sequence a terminal does not know is drawn as its label and nothing else.
        putenv('TERM=dumb');

        $this->assertTrue(Capabilities::detect()->hyperlinks);
    }
}
