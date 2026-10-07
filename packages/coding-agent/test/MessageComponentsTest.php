<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;
use Pig\Ai\Api;
use Pig\Ai\AssistantMessage;
use Pig\Ai\StopReason;
use Pig\Ai\TextContent;
use Pig\Ai\ThinkingContent;
use Pig\Ai\ToolCall;
use Pig\Ai\Usage;
use Pig\CodingAgent\Interactive\AssistantMessageComponent;
use Pig\CodingAgent\Interactive\BranchSummaryComponent;
use Pig\CodingAgent\Interactive\CompactionComponent;
use Pig\CodingAgent\Interactive\UserMessageComponent;
use Pig\CodingAgent\Session\BranchSummary;
use Pig\CodingAgent\Session\CompactionSummary;
use Pig\CodingAgent\Theme\Palette;
use Pig\Tui\Ansi;
use Pig\Tui\Components\Spacer;
use Pig\Tui\Container;

/** The two halves of the conversation, as they appear in the scrollback. */
final class MessageComponentsTest extends TestCase
{
    private const int WIDTH = 60;

    private Palette $palette;

    #[\Override]
    protected function setUp(): void
    {
        $this->palette = Palette::dark(true);
    }

    /** @param list<string> $lines */
    private function text(array $lines): string
    {
        return implode("\n", array_map(Ansi::strip(...), $lines));
    }

    /**
     * @param list<mixed> $content
     */
    private function assistant(array $content, StopReason $stop = StopReason::Stop, ?string $error = null): AssistantMessage
    {
        return new AssistantMessage($content, Api::AnthropicMessages, 'anthropic', 'm', new Usage(), $stop, $error);
    }

    // ---- what the person said ---------------------------------------------------------

    public function testAUserMessageCarriesItsBackgroundToTheEdge(): void
    {
        $lines = (new UserMessageComponent('hello', $this->palette))->render(self::WIDTH);

        // A background that stops where the text stops leaves a ragged block, which is
        // the one thing marking where one exchange ended in the scrollback.
        // The theme's own `userMessageBg`, not a hex written into the test: this asserted
        // `#343541` and went red the day the palette was aligned with upstream's (`#213b49`),
        // which is a test of the colour table and not of the component.
        $bg = ltrim($this->palette->hex('userMessageBg'), '#');
        [$r, $g, $b] = array_map(hexdec(...), str_split($bg, 2));

        foreach (array_slice($lines, 1) as $line) {
            $this->assertStringContainsString("\e[48;2;{$r};{$g};{$b}m", $line);
            $this->assertSame(self::WIDTH, mb_strwidth(Ansi::strip($line)));
        }
    }

    public function testMarkdownInAUserMessageIsStillRendered(): void
    {
        $lines = (new UserMessageComponent('some **bold** text', $this->palette))->render(self::WIDTH);

        $this->assertStringContainsString("\e[1m", implode('', $lines));
        $this->assertStringContainsString('some bold text', $this->text($lines));
    }

    // ---- summary blocks -----------------------------------------------------------------

    public function testACompactionIsDrawnAsAPiStyleBlock(): void
    {
        $summary = new CompactionSummary('what changed', tokensBefore: 256653, replaced: 42);
        $component = new CompactionComponent($summary, $this->palette);
        $lines = $component->render(self::WIDTH);
        $text = $this->text($lines);

        $this->assertStringContainsString('[compaction]', $text);
        $this->assertStringContainsString('Compacted from 256,653 tokens (ctrl+o to expand)', $text);
        $this->assertStringNotContainsString('what changed', $text);
        $this->assertBlockBackground($lines, 'customMessageBg');
    }

    public function testExpandingACompactionKeepsTheSummaryInsideTheBlock(): void
    {
        $summary = new CompactionSummary('**important** summary', tokensBefore: 1200, replaced: 3);
        $component = new CompactionComponent($summary, $this->palette, expanded: true);
        $lines = $component->render(self::WIDTH);
        $text = $this->text($lines);

        $this->assertStringContainsString('[compaction]', $text);
        $this->assertStringNotContainsString('ctrl+o to expand', $text);
        $this->assertStringContainsString('important summary', $text);
        $this->assertBlockBackground($lines, 'customMessageBg');
    }

    public function testABranchSummaryUsesTheSameBlockShape(): void
    {
        $summary = new BranchSummary('handover notes');
        $lines = (new BranchSummaryComponent($summary, $this->palette))->render(self::WIDTH);
        $text = $this->text($lines);

        $this->assertStringContainsString('[branch summary]', $text);
        $this->assertStringContainsString('Branch summar', $text);
        $this->assertStringContainsString('ctrl+o to expand', $text);
        $this->assertBlockBackground($lines, 'customMessageBg');
    }

    /** @param list<string> $lines */
    private function assertBlockBackground(array $lines, string $colour): void
    {
        $bg = ltrim($this->palette->hex($colour), '#');
        [$r, $g, $b] = array_map(hexdec(...), str_split($bg, 2));
        $escape = "\e[48;2;{$r};{$g};{$b}m";

        $painted = array_values(array_filter($lines, static fn (string $line): bool => str_contains($line, $escape)));
        $this->assertNotSame([], $painted, 'no line carried the block background');

        foreach ($painted as $line) {
            $this->assertSame(self::WIDTH, mb_strwidth(Ansi::strip($line)));
        }
    }

    // ---- what the model said ------------------------------------------------------------

    public function testTextAndThinkingAppearInTheOrderTheyArrived(): void
    {
        $message = $this->assistant([
            new ThinkingContent('let me look'),
            new TextContent('Found it.'),
        ]);

        $text = $this->text((new AssistantMessageComponent($this->palette, $message))->render(self::WIDTH));

        $this->assertLessThan(strpos($text, 'Found it.'), strpos($text, 'let me look'));
    }

    public function testThinkingCanBeCollapsedToALabel(): void
    {
        $message = $this->assistant([new ThinkingContent('a long private train of thought')]);
        $component = new AssistantMessageComponent($this->palette, $message, true);

        $text = $this->text($component->render(self::WIDTH));

        $this->assertStringContainsString('Thinking...', $text);
        $this->assertStringNotContainsString('private train', $text);
    }

    public function testEmptyContentDrawsNoBlankLine(): void
    {
        // A message that is only tool calls gets no spacing of its own: the tools draw
        // theirs, and a blank line above the first one is a hole where text never came.
        $message = $this->assistant([new ToolCall('1', 'read', [])]);

        $this->assertSame([], (new AssistantMessageComponent($this->palette, $message))->render(self::WIDTH));
    }

    public function testAnAbortedTurnSaysSo(): void
    {
        $message = $this->assistant([new TextContent('I was saying')], StopReason::Aborted);

        $this->assertStringContainsString('Operation aborted', $this->text(
            (new AssistantMessageComponent($this->palette, $message))->render(self::WIDTH),
        ));
    }

    public function testAFailedTurnSaysWhy(): void
    {
        $message = $this->assistant([], StopReason::Error, 'overloaded_error');

        $this->assertStringContainsString('Error: overloaded_error', $this->text(
            (new AssistantMessageComponent($this->palette, $message))->render(self::WIDTH),
        ));
    }

    public function testATurnWithToolCallsLeavesTheErrorToTheTools(): void
    {
        // Each tool's own component carries the failure. Saying it here as well makes
        // one failure look like two.
        $message = $this->assistant([new TextContent('trying'), new ToolCall('1', 'bash', [])], StopReason::Aborted);

        $this->assertStringNotContainsString('Aborted', $this->text(
            (new AssistantMessageComponent($this->palette, $message))->render(self::WIDTH),
        ));
    }

    public function testUpdatingReplacesTheContentRatherThanAppendingIt(): void
    {
        // A streamed message arrives complete every time, with the last block longer
        // than before — appending would print the whole answer once per token.
        $component = new AssistantMessageComponent($this->palette);
        $component->update($this->assistant([new TextContent('Par')]));
        $component->update($this->assistant([new TextContent('Partial')]));

        $text = $this->text($component->render(self::WIDTH));

        $this->assertStringContainsString('Partial', $text);
        $this->assertSame(1, substr_count($text, 'Par'));
    }

    public function testABlockThatHasStoppedChangingKeepsTheComponentThatDrewIt(): void
    {
        // Every block is re-read on every delta, and all but the last one are re-read as
        // exactly what they already say. Building them again means laying the whole document
        // out again, which `Markdown`'s cache exists to avoid and a component thrown away
        // between frames can never reach: 19.6ms a delta against 0.1ms, measured over a
        // 1,600-line answer whose first two blocks had settled.
        $component = new AssistantMessageComponent($this->palette);
        $settled = new TextContent('the first thing it said');

        $component->update($this->assistant([$settled, new TextContent('and now it is s')]));
        $before = self::blocks($component);

        $component->update($this->assistant([$settled, new TextContent('and now it is saying more')]));
        $after = self::blocks($component);

        $this->assertSame($before[0], $after[0], 'the settled block was drawn by a new component');
        $this->assertSame($before[1], $after[1], 'the growing block was too');
        $this->assertStringContainsString('and now it is saying more', $this->text($component->render(self::WIDTH)));
    }

    public function testABlockWhoseKindChangesDoesNotInheritTheWrongComponent(): void
    {
        // Reuse is by position, so the thing that makes it safe is that a slot only ever
        // holds one kind: a thinking block drawn in italics is not a message drawn plain,
        // and a spacer appearing between them shifts every slot below by one.
        $component = new AssistantMessageComponent($this->palette);

        $component->update($this->assistant([new TextContent('said')]));
        $before = self::blocks($component);

        $component->update($this->assistant([new ThinkingContent('thought'), new TextContent('said')]));
        $after = self::blocks($component);

        $this->assertNotSame($before[0], $after[0]);

        $text = $this->text($component->render(self::WIDTH));

        $this->assertStringContainsString('thought', $text);
        $this->assertStringContainsString('said', $text);
    }

    public function testAMessageWithFewerBlocksThanLastTimeDrawsOnlyWhatItHasNow(): void
    {
        // Slots only ever grow while one message streams, so the kept components are only
        // ever *read* up to what this update filled — but `update()` is a public method and
        // a shorter message is a legal thing to hand it, and what it would otherwise draw is
        // the tail of the message before it.
        $component = new AssistantMessageComponent($this->palette);

        $component->update($this->assistant([new TextContent('first'), new TextContent('second')]));
        $component->update($this->assistant([new TextContent('first')]));

        $text = $this->text($component->render(self::WIDTH));

        $this->assertStringContainsString('first', $text);
        $this->assertStringNotContainsString('second', $text);
    }

    /**
     * What it is drawing with, so a test can ask whether it is the same thing as last time.
     *
     * @return list<object>
     */
    private static function blocks(AssistantMessageComponent $component): array
    {
        $content = $component->children()[0];
        self::assertInstanceOf(Container::class, $content);

        return array_values(array_filter(
            $content->children(),
            static fn (object $child): bool => !$child instanceof Spacer,
        ));
    }

    public function testWhitespaceOnlyContentIsNotDrawn(): void
    {
        $message = $this->assistant([new TextContent("  \n  ")]);

        $this->assertSame([], (new AssistantMessageComponent($this->palette, $message))->render(self::WIDTH));
    }

    public function testInlineThinkingTagsAreExtractedAndRenderedAsThinkingBlocks(): void
    {
        $raw = "<thinking> Syncing CHANGELOG.md to smart-book\n\nI'm copying the updated file.\n</thinking>\n\nHere is the final output.";
        $message = $this->assistant([new TextContent($raw)]);

        // 1. When thinking is visible: tags are stripped, thinking content is rendered in italic thinkingText style
        $component = new AssistantMessageComponent($this->palette, $message, hideThinking: false);
        $rendered = implode("\n", $component->render(self::WIDTH));

        $this->assertStringNotContainsString('<thinking>', $rendered);
        $this->assertStringNotContainsString('</thinking>', $rendered);
        $this->assertStringContainsString('Syncing CHANGELOG.md to smart-book', Ansi::strip($rendered));
        $this->assertStringContainsString('Here is the final output.', Ansi::strip($rendered));
        // Thinking color check (\e[3m for italic)
        $this->assertStringContainsString("\e[3m", $rendered);

        // 2. When thinking is hidden: the inline thinking collapses into Thinking... label
        $hiddenComponent = new AssistantMessageComponent($this->palette, $message, hideThinking: true);
        $hiddenRendered = Ansi::strip(implode("\n", $hiddenComponent->render(self::WIDTH)));

        $this->assertStringContainsString('Thinking...', $hiddenRendered);
        $this->assertStringNotContainsString('Syncing CHANGELOG.md', $hiddenRendered);
        $this->assertStringContainsString('Here is the final output.', $hiddenRendered);
    }
}
