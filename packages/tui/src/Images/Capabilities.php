<?php

declare(strict_types=1);

namespace Pig\Tui\Images;

/**
 * What this terminal can do, worked out from the environment.
 *
 * Asking would be better and is not possible in time: the answer arrives as input, and
 * the first frame has to be drawn before it does. So the terminal is identified by the
 * variables it sets — every one of these sets something, because every one of them had
 * the same problem.
 */
final readonly class Capabilities
{
    public function __construct(
        public ?ImageProtocol $images,
        public bool $trueColor,
        public bool $hyperlinks,
    ) {
    }

    public function drawsImages(): bool
    {
        return $this->images !== null;
    }

    public static function detect(): self
    {
        $program = strtolower((string) getenv('TERM_PROGRAM'));
        $term = strtolower((string) getenv('TERM'));
        $colour = strtolower((string) getenv('COLORTERM'));

        $kitty = self::isSet('KITTY_WINDOW_ID') || $program === 'kitty';
        $ghostty = $program === 'ghostty' || str_contains($term, 'ghostty') || self::isSet('GHOSTTY_RESOURCES_DIR');
        $wezterm = self::isSet('WEZTERM_PANE') || $program === 'wezterm';

        if ($kitty || $ghostty || $wezterm) {
            return new self(ImageProtocol::Kitty, true, true);
        }

        if (self::isSet('ITERM_SESSION_ID') || $program === 'iterm.app') {
            return new self(ImageProtocol::ITerm2, true, true);
        }

        // Both do true colour and neither does images; named so the next reader does not
        // have to test them again.
        if ($program === 'vscode' || $program === 'alacritty') {
            return new self(null, true, true);
        }

        return new self(null, $colour === 'truecolor' || $colour === '24bit', true);
    }

    /**
     * Whether a variable is set to anything at all.
     *
     * Four of the tests above are presence tests, and **an exported-but-empty variable is not
     * presence**. `getenv()` answers `false` for absent and `''` for set-but-empty, so the
     * obvious `!== false` calls an empty one present; upstream reads the same variable through
     * JavaScript's truthiness, where `''` is falsy, and therefore ignores it. A bare `export
     * ITERM_SESSION_ID`, a `docker run -e ITERM_SESSION_ID`, or an ssh or tmux environment
     * that forwards the name without a value made pig send iTerm2 image sequences to a
     * terminal that cannot draw them — which is not a missing picture, it is tens of kilobytes
     * of base64 printed into the transcript.
     */
    private static function isSet(string $name): bool
    {
        return (string) getenv($name) !== '';
    }
}
