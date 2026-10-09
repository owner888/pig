<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Interactive;

use Pig\CodingAgent\Theme\Themes;
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
    private const string OSC133_ZONE_START = "\x1b]133;A\x07";
    private const string OSC133_ZONE_END = "\x1b]133;B\x07";
    private const string OSC133_ZONE_FINAL = "\x1b]133;C\x07";

    private readonly Markdown $markdown;

    /**
     * @param list<Closure(string, array{role: string, isStreaming: bool}): string> $transformers
     */
    public function __construct(string $text, array $transformers = [], int $outputPad = 1)
    {
        foreach ($transformers as $transformer) {
            $text = $transformer($text, ['role' => 'user', 'isStreaming' => false]);
        }

        $this->addChild(new Spacer(1));
        $this->markdown = new Markdown(
            Shell::sanitize($text),
            $outputPad,
            1,
            Themes::getMarkdownTheme(),
            new DefaultTextStyle(
                colour: static fn (string $text): string => Themes::theme()->fg('userMessageText', $text),
                background: static fn (string $text): string => Themes::theme()->bg('userMessageBg', $text),
            ),
        );
        $this->addChild($this->markdown);
    }

    /** Upstream's `setOutputPad()`. */
    public function setOutputPad(int $outputPad): void
    {
        $this->markdown->setPaddingX($outputPad);
    }

    /**
     * Wrapped in OSC 133 prompt marks, as upstream's is: terminals use them to jump between
     * prompts, and `TuiAltScreen` does the same for `tui.altScreen.previousPrompt`/`nextPrompt`.
     */
    #[\Override]
    public function render(int $width): array
    {
        $lines = parent::render($width);
        if ($lines === []) {
            return $lines;
        }

        $lines[0] = self::OSC133_ZONE_START . $lines[0];
        $last = count($lines) - 1;
        $lines[$last] = self::OSC133_ZONE_END . self::OSC133_ZONE_FINAL . $lines[$last];

        return $lines;
    }
}
