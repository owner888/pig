<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Interactive;

use Pig\CodingAgent\Session\HookMessage;
use Pig\CodingAgent\Theme\Palette;
use Pig\Tui\Components\DefaultTextStyle;
use Pig\Tui\Components\Markdown;
use Pig\Tui\Components\Spacer;
use Pig\Tui\Components\Text;
use Pig\Tui\Container;

/**
 * Something a hook said, drawn the ordinary way.
 *
 * The default when a hook has not registered a renderer of its own. It is deliberately not
 * `UserMessageComponent`: a hook's message reaches the model as a user message, and showing
 * it as one would put words in the person's mouth — they would scroll back and find
 * themselves saying something they never typed. So it is labelled with the hook's own type,
 * in the muted colour, and reads as a note rather than as a turn.
 *
 * Ported from upstream's `components/hook-message.ts`.
 */
final class HookMessageComponent extends Container
{
    public function __construct(HookMessage $message, Palette $palette)
    {
        $text = $message->toText();

        $this->addChild(new Spacer(1));
        $this->addChild(new Text(
            $palette->fg('muted', "◆ {$message->customType}"),
            1,
            0,
        ));

        if (trim($text) !== '') {
            $this->addChild(new Markdown(
                $text,
                2,
                0,
                $palette->markdownTheme(),
                new DefaultTextStyle(colour: $palette->of('muted')),
            ));
        }
    }
}
