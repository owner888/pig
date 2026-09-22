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

        $kitty = getenv('KITTY_WINDOW_ID') !== false || $program === 'kitty';
        $ghostty = $program === 'ghostty' || str_contains($term, 'ghostty') || getenv('GHOSTTY_RESOURCES_DIR') !== false;
        $wezterm = getenv('WEZTERM_PANE') !== false || $program === 'wezterm';

        if ($kitty || $ghostty || $wezterm) {
            return new self(ImageProtocol::Kitty, true, true);
        }

        if (getenv('ITERM_SESSION_ID') !== false || $program === 'iterm.app') {
            return new self(ImageProtocol::ITerm2, true, true);
        }

        // Both do true colour and neither does images; named so the next reader does not
        // have to test them again.
        if ($program === 'vscode' || $program === 'alacritty') {
            return new self(null, true, true);
        }

        return new self(null, $colour === 'truecolor' || $colour === '24bit', true);
    }
}
