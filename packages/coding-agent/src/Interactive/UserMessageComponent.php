<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Interactive;

use Pig\CodingAgent\Theme\Palette;
use Pig\CodingAgent\Tools\Shell;
use Pig\Tui\Components\DefaultTextStyle;
use Pig\Tui\Components\Markdown;
use Pig\Tui\Components\Spacer;
use Pig\Tui\Container;

/**
 * What the person said, on its own background.
 *
 * The background is what makes a transcript readable at a glance — it is the only thing
 * in the scrollback that marks where one exchange ended and the next began.
 *
 * **The fifth transcript path, and the last one to get a guard.** The other four are a tool's
 * output, a diff, a hook's message and a typed command's output; this one is what the person
 * said, which read as obviously safe — a keystroke is a keystroke. It is not: `pig @notes.txt`
 * puts a file's bytes in here, `pig $'ask about \x80 this'` puts a shell argument's, and a paste
 * arrives as whatever was on the clipboard. Both command-line doors are cleaned where they read
 * now, for the reason written there; this covers the paste, and is what the other four have.
 *
 * Ported from upstream's `components/user-message.ts`.
 */
final class UserMessageComponent extends Container
{
    public function __construct(string $text, Palette $palette)
    {
        $this->addChild(new Spacer(1));
        $this->addChild(new Markdown(
            Shell::sanitize($text),
            1,
            1,
            $palette->markdownTheme(),
            new DefaultTextStyle(
                colour: $palette->of('userMessageText'),
                background: $palette->of('userMessageBg'),
            ),
        ));
    }
}
