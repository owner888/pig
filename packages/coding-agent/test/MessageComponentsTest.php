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
use Pig\CodingAgent\Interactive\UserMessageComponent;
use Pig\CodingAgent\Theme\Palette;
use Pig\Tui\Ansi;

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
        foreach (array_slice($lines, 1) as $line) {
            $this->assertStringContainsString("\e[48;2;52;53;65m", $line);
            $this->assertSame(self::WIDTH, mb_strwidth(Ansi::strip($line)));
        }
    }

    public function testMarkdownInAUserMessageIsStillRendered(): void
    {
        $lines = (new UserMessageComponent('some **bold** text', $this->palette))->render(self::WIDTH);

        $this->assertStringContainsString("\e[1m", implode('', $lines));
        $this->assertStringContainsString('some bold text', $this->text($lines));
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

        $this->assertStringContainsString('Aborted', $this->text(
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

    public function testWhitespaceOnlyContentIsNotDrawn(): void
    {
        $message = $this->assistant([new TextContent("  \n  ")]);

        $this->assertSame([], (new AssistantMessageComponent($this->palette, $message))->render(self::WIDTH));
    }
}
