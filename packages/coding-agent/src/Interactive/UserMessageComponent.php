<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Interactive;

use Pig\CodingAgent\Theme\Palette;
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
 * Ported from upstream's `components/user-message.ts`.
 */
final class UserMessageComponent extends Container
{
    public function __construct(string $text, Palette $palette)
    {
        $this->addChild(new Spacer(1));
        $this->addChild(new Markdown(
            $text,
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
