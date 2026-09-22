<?php

declare(strict_types=1);

namespace Pig\Tui\Images;

/**
 * The two ways a terminal will draw a picture.
 *
 * Both work by writing the image bytes into the output stream as an escape sequence, so
 * a picture is a "line" like any other as far as the renderer is concerned — one that
 * happens to be forty kilobytes long and zero columns wide.
 */
enum ImageProtocol: string
{
    /** Kitty's graphics protocol, also spoken by Ghostty and WezTerm. */
    case Kitty = 'kitty';

    /** iTerm2's inline image protocol, an OSC 1337 sequence. */
    case ITerm2 = 'iterm2';
}
