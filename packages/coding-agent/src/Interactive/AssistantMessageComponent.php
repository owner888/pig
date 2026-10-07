<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Interactive;

use Closure;
use Pig\Ai\AssistantMessage;
use Pig\Ai\StopReason;
use Pig\Ai\TextContent;
use Pig\Ai\ThinkingContent;
use Pig\Ai\ToolCall;
use Pig\CodingAgent\Theme\Palette;
use Pig\Tui\Component;
use Pig\Tui\Components\DefaultTextStyle;
use Pig\Tui\Components\Markdown;
use Pig\Tui\Components\Spacer;
use Pig\Tui\Components\Text;
use Pig\Tui\Container;

/**
 * What the model said, redrawn every time more of it arrives.
 *
 * Read from the whole message rather than appended to, because a streamed message is
 * re-sent complete on every update — the last block grows and the ones before it do not.
 * Markdown cannot be rendered incrementally anyway: a `*` is emphasis or a bullet
 * depending on what follows it.
 *
 * **The components are not rebuilt, though**, and `$built` is why: every block but the last one
 * is set to the text it is already showing, which is free, and throwing the component away was
 * 20ms a delta on a long answer.
 *
 * Ported from upstream's `components/assistant-message.ts`.
 */
final class AssistantMessageComponent extends Container
{
    private readonly Container $content;

    /**
     * What the last update built, each slot tagged with what it is for.
     *
     * The components are **kept and set again** rather than built afresh, which is the whole
     * of the difference: `Markdown` caches its laid-out lines against the text it was given,
     * and a component thrown away between frames can never hit that cache. Measured on a plain
     * answer, per delta, with the whole UI waiting on it: 0.5ms at 40 lines, 2.2 at 200, 8.8 at
     * 800, **20.6 at 2,000** — the same at 2,000 lines of ASCII, so it is the parsing and not
     * the character widths. The same text in the same component costs nothing.
     *
     * Reused by position, which holds because content blocks are appended and an existing one
     * never changes kind. The tag is what makes that safe when the shape *does* change: a
     * `Markdown` drawn in the thinking style is not one drawn in the message style, and every
     * slot below shifts by one the moment a spacer appears between them. A tag that does not
     * match is a component built again, which is the old behaviour for that one slot.
     *
     * @var list<array{0:string,1:Component}>
     */
    private array $built = [];

    /** Which slot `keep()` is filling, for the length of one update. */
    private int $slot = 0;

    private ?string $hiddenThinkingLabel = null;

    /**
     * @param list<Closure(string, array{role: string, isStreaming: bool}): string> $transformers
     */
    public function __construct(
        private readonly Palette $palette,
        ?AssistantMessage $message = null,
        private bool $hideThinking = false,
        private readonly array $transformers = [],
        ?string $hiddenThinkingLabel = null,
    ) {
        $this->hiddenThinkingLabel = $hiddenThinkingLabel;
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

    public function setHiddenThinkingLabel(?string $label): void
    {
        $this->hiddenThinkingLabel = $label;
    }

    public function update(AssistantMessage $message): void
    {
        $this->slot = 0;

        if (self::hasSomethingToSay($message)) {
            $this->keep('gap', static fn (): Component => new Spacer(1));
        }

        foreach ($message->content as $index => $block) {
            if ($block instanceof TextContent && trim($block->text) !== '') {
                // paddingY = 0: a tool execution follows immediately after, and a blank
                // line between the sentence and the tool it describes reads as a gap.
                $saidText = trim($block->text);
                $isStreaming = $message->stopReason === null;
                foreach ($this->transformers as $transformer) {
                    $saidText = $transformer($saidText, ['role' => 'assistant', 'isStreaming' => $isStreaming]);
                }

                $parts = self::extractInlineThinking($saidText);
                foreach ($parts as $partIndex => $part) {
                    $partText = trim($part['text']);
                    if ($partText === '') {
                        continue;
                    }

                    if ($part['type'] === 'thinking') {
                        $hasTextAfter = $partIndex < count($parts) - 1 || self::hasTextAfter($message, $index);
                        $this->thinking($partText, $hasTextAfter);

                        continue;
                    }

                    $said = $this->keep('said', fn (): Component => new Markdown('', 1, 0, $this->palette->markdownTheme()));

                    if ($said instanceof Markdown) {
                        $said->setText($partText);
                    }
                }

                continue;
            }

            if ($block instanceof ThinkingContent && trim($block->thinking) !== '') {
                $this->thinking(trim($block->thinking), self::hasTextAfter($message, $index));
            }
        }

        $this->ending($message);

        $this->built = array_slice($this->built, 0, $this->slot);
        $this->content->clear();

        foreach ($this->built as [, $child]) {
            $this->content->addChild($child);
        }
    }

    /**
     * The component for the next slot: the one that was there last time, or a new one.
     *
     * @param Closure(): Component $make
     */
    private function keep(string $tag, Closure $make): Component
    {
        $existing = $this->built[$this->slot] ?? null;
        $child = $existing !== null && $existing[0] === $tag ? $existing[1] : $make();

        $this->built[$this->slot] = [$tag, $child];
        $this->slot++;

        return $child;
    }

    private function thinking(string $thinking, bool $textAfter): void
    {
        if ($this->hideThinking) {
            $txt = $this->hiddenThinkingLabel ?? 'Thinking...';
            $label = $this->palette->fg('thinkingText', "\e[3m{$txt}\e[23m");
            $this->keep('thought-label', static fn (): Component => new Text($label, 1, 0));

            if ($textAfter) {
                $this->keep('thought-gap', static fn (): Component => new Spacer(1));
            }

            return;
        }

        $thought = $this->keep('thought', fn (): Component => new Markdown(
            '',
            1,
            0,
            $this->palette->markdownTheme(),
            new DefaultTextStyle(colour: $this->palette->of('thinkingText'), italic: true),
        ));

        if ($thought instanceof Markdown) {
            $thought->setText($thinking);
        }

        $this->keep('thought-gap', static fn (): Component => new Spacer(1));
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
            $aborted = $this->keep('aborted', static fn (): Component => new Text('', 1, 0));

            if ($aborted instanceof Text) {
                $abortMessage = $message->errorMessage !== null && $message->errorMessage !== '' && $message->errorMessage !== 'Request was aborted'
                    ? $message->errorMessage
                    : 'Operation aborted';
                $aborted->setText($this->palette->fg('error', "\n" . $abortMessage));
            }

            return;
        }

        if ($message->stopReason === StopReason::Error) {
            $this->keep('error-gap', static fn (): Component => new Spacer(1));
            $error = $this->keep('error', static fn (): Component => new Text('', 1, 0));

            if ($error instanceof Text) {
                $error->setText($this->palette->fg('error', 'Error: ' . ($message->errorMessage ?? 'Unknown error')));
            }
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

    /**
     * Separate embedded <thinking>...</thinking> pseudo-tags in text into thinking parts.
     *
     * Models that mimic literal thinking tags or streaming pseudo-tags in text are cleanly
     * separated so the thought process renders in the dedicated thinking style rather than
     * printing raw HTML-like tags.
     *
     * @return list<array{type: 'text'|'thinking', text: string}>
     */
    public static function extractInlineThinking(string $text): array
    {
        if (!str_contains($text, '<thinking>')) {
            return [['type' => 'text', 'text' => $text]];
        }

        $parts = [];
        $pattern = '/<thinking>(.*?)<\/thinking>/s';
        $lastOffset = 0;

        if (preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $i => $fullMatch) {
                $matchStr = $fullMatch[0];
                $matchOffset = $fullMatch[1];
                $thinkingContent = trim($matches[1][$i][0]);

                if ($matchOffset > $lastOffset) {
                    $prefix = trim(substr($text, $lastOffset, $matchOffset - $lastOffset));
                    if ($prefix !== '') {
                        $parts[] = ['type' => 'text', 'text' => $prefix];
                    }
                }

                if ($thinkingContent !== '') {
                    $parts[] = ['type' => 'thinking', 'text' => $thinkingContent];
                }

                $lastOffset = $matchOffset + strlen($matchStr);
            }

            if ($lastOffset < strlen($text)) {
                $suffix = trim(substr($text, $lastOffset));
                if ($suffix !== '') {
                    $parts[] = ['type' => 'text', 'text' => $suffix];
                }
            }

            return $parts !== [] ? $parts : [['type' => 'text', 'text' => $text]];
        }

        // Unclosed <thinking> tag during streaming
        if (preg_match('/<thinking>([\s\S]*)$/', $text, $m, PREG_OFFSET_CAPTURE)) {
            $prefix = trim(substr($text, 0, $m[0][1]));
            if ($prefix !== '') {
                $parts[] = ['type' => 'text', 'text' => $prefix];
            }
            $streamThinking = trim($m[1][0]);
            if ($streamThinking !== '') {
                $parts[] = ['type' => 'thinking', 'text' => $streamThinking];
            }

            return $parts !== [] ? $parts : [['type' => 'text', 'text' => $text]];
        }

        return [['type' => 'text', 'text' => $text]];
    }
}
