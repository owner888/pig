<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Interactive;

use Pig\Ai\AssistantMessage;
use Pig\Ai\StopReason;
use Pig\Ai\TextContent;
use Pig\Ai\ThinkingContent;
use Pig\Ai\ToolCall;
use Pig\CodingAgent\Theme\Palette;
use Pig\Tui\Components\DefaultTextStyle;
use Pig\Tui\Components\Markdown;
use Pig\Tui\Components\Spacer;
use Pig\Tui\Components\Text;
use Pig\Tui\Container;

/**
 * What the model said, redrawn every time more of it arrives.
 *
 * Rebuilt from the whole message rather than appended to, because a streamed message is
 * re-sent complete on every update — the last block grows and the ones before it do not.
 * Markdown cannot be rendered incrementally anyway: a `*` is emphasis or a bullet
 * depending on what follows it.
 *
 * Ported from upstream's `components/assistant-message.ts`.
 */
final class AssistantMessageComponent extends Container
{
    private readonly Container $content;

    public function __construct(
        private readonly Palette $palette,
        ?AssistantMessage $message = null,
        private bool $hideThinking = false,
    ) {
        $this->content = new Container();
        $this->addChild($this->content);

        if ($message !== null) {
            $this->update($message);
        }
    }

    public function setHideThinking(bool $hide): void
    {
        $this->hideThinking = $hide;
    }

    public function update(AssistantMessage $message): void
    {
        $this->content->clear();

        if (self::hasSomethingToSay($message)) {
            $this->content->addChild(new Spacer(1));
        }

        foreach ($message->content as $index => $block) {
            if ($block instanceof TextContent && trim($block->text) !== '') {
                // paddingY = 0: a tool execution follows immediately after, and a blank
                // line between the sentence and the tool it describes reads as a gap.
                $this->content->addChild(new Markdown(trim($block->text), 1, 0, $this->palette->markdownTheme()));

                continue;
            }

            if ($block instanceof ThinkingContent && trim($block->thinking) !== '') {
                $this->thinking(trim($block->thinking), self::hasTextAfter($message, $index));
            }
        }

        $this->ending($message);
    }

    private function thinking(string $thinking, bool $textAfter): void
    {
        if ($this->hideThinking) {
            $label = $this->palette->fg('thinkingText', "\e[3mThinking...\e[23m");
            $this->content->addChild(new Text($label, 1, 0));

            if ($textAfter) {
                $this->content->addChild(new Spacer(1));
            }

            return;
        }

        $this->content->addChild(new Markdown(
            $thinking,
            1,
            0,
            $this->palette->markdownTheme(),
            new DefaultTextStyle(colour: $this->palette->of('thinkingText'), italic: true),
        ));

        $this->content->addChild(new Spacer(1));
    }

    /**
     * How the turn ended, when that is not obvious.
     *
     * Only when there were no tool calls: with them, each tool's own component carries
     * the error, and saying it twice makes one failure look like two.
     */
    private function ending(AssistantMessage $message): void
    {
        foreach ($message->content as $block) {
            if ($block instanceof ToolCall) {
                return;
            }
        }

        if ($message->stopReason === StopReason::Aborted) {
            $this->content->addChild(new Text($this->palette->fg('error', "\nAborted"), 1, 0));

            return;
        }

        if ($message->stopReason === StopReason::Error) {
            $this->content->addChild(new Spacer(1));
            $this->content->addChild(new Text(
                $this->palette->fg('error', 'Error: ' . ($message->errorMessage ?? 'Unknown error')),
                1,
                0,
            ));
        }
    }

    /**
     * Whether there is anything worth a blank line above.
     *
     * A message that is only tool calls gets none: the tools draw their own spacing, and
     * an empty line above the first one leaves a hole where text never arrived.
     */
    private static function hasSomethingToSay(AssistantMessage $message): bool
    {
        foreach ($message->content as $block) {
            if ($block instanceof TextContent && trim($block->text) !== '') {
                return true;
            }

            if ($block instanceof ThinkingContent && trim($block->thinking) !== '') {
                return true;
            }
        }

        return false;
    }

    private static function hasTextAfter(AssistantMessage $message, int $index): bool
    {
        foreach (array_slice($message->content, $index + 1) as $block) {
            if ($block instanceof TextContent && trim($block->text) !== '') {
                return true;
            }
        }

        return false;
    }
}
