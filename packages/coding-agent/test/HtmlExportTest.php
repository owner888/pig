<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;
use Pig\Ai\Api;
use Pig\Ai\AssistantMessage;
use Pig\Ai\ImageContent;
use Pig\Ai\StopReason;
use Pig\Ai\TextContent;
use Pig\Ai\ThinkingContent;
use Pig\Ai\ToolCall;
use Pig\Ai\ToolResultMessage;
use Pig\Ai\Usage;
use Pig\Ai\UserMessage;
use Pig\CodingAgent\Export\HtmlExport;
use Pig\CodingAgent\Export\MarkdownHtml;
use Pig\CodingAgent\Session\BashExecution;
use Pig\CodingAgent\Session\CompactionSummary;

/** A conversation as one HTML file, with no JavaScript in it. */
final class HtmlExportTest extends TestCase
{
    /** @param list<mixed> $messages */
    private function html(array $messages): string
    {
        return HtmlExport::render($messages, '/some/project');
    }

    private function assistant(array $content, StopReason $stop = StopReason::Stop, ?string $error = null): AssistantMessage
    {
        return new AssistantMessage($content, Api::AnthropicMessages, 'anthropic', 'claude-x', new Usage(), $stop, $error);
    }

    // ---- the document --------------------------------------------------------------------

    public function testTheFileCarriesNoScriptAtAll(): void
    {
        $html = $this->html([
            new UserMessage('hello'),
            $this->assistant([new TextContent('hi back')]),
        ]);

        // The whole reason this is rendered in PHP: upstream ships 160KB of somebody
        // else's minified JavaScript to do it in the browser.
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringStartsWith('<!DOCTYPE html>', $html);
        $this->assertStringContainsString('hi back', $html);
    }

    public function testTheFirstThingSaidIsTheTitle(): void
    {
        $html = $this->html([new UserMessage('fix the parser'), $this->assistant([new TextContent('ok')])]);

        $this->assertStringContainsString('<title>pig — fix the parser</title>', $html);
    }

    public function testTheThemeIsCarriedAsAnAttributeRatherThanTwoStylesheets(): void
    {
        $this->assertStringContainsString('data-theme="light"', HtmlExport::render([new UserMessage('x')], '/p', 'light'));
    }

    // ---- what each kind of message becomes -------------------------------------------------

    public function testMarkdownInAnAnswerIsRendered(): void
    {
        $html = $this->html([$this->assistant([new TextContent("# Heading\n\nSome **bold**.")])]);

        $this->assertStringContainsString('<h1>Heading</h1>', $html);
        $this->assertStringContainsString('<strong>bold</strong>', $html);
    }

    public function testThinkingIsFoldedAwayRatherThanDropped(): void
    {
        $html = $this->html([$this->assistant([
            new ThinkingContent(str_repeat("a fairly long thought about the problem\n", 30), 'sig'),
            new TextContent('the answer'),
        ])]);

        // A transcript is read for the conversation; thinking is what you open when the
        // conversation stops making sense without it.
        $this->assertStringContainsString('<summary>Thinking</summary>', $html);
        $this->assertStringNotContainsString('<details class="thinking" open>', $html);
    }

    public function testSomethingShortIsLeftOpenBecauseFoldingItSavesNothing(): void
    {
        $html = $this->html([$this->assistant([new ThinkingContent('one line', 'sig')])]);

        $this->assertStringContainsString('<details class="thinking" open>', $html);
    }

    public function testAToolCallSaysWhichToolAndWhatOn(): void
    {
        $html = $this->html([
            $this->assistant([new ToolCall('c1', 'read', ['path' => 'src/Foo.php'])]),
            new ToolResultMessage('c1', 'read', [new TextContent('<?php')]),
        ]);

        $this->assertStringContainsString('read src/Foo.php', $html);
        $this->assertStringContainsString('&lt;?php', $html);
    }

    public function testAFailedToolIsMarkedAsOne(): void
    {
        $html = $this->html([
            $this->assistant([new ToolCall('c1', 'read', ['path' => 'nope'])]),
            new ToolResultMessage('c1', 'read', [new TextContent('no such file')], true),
        ]);

        $this->assertStringContainsString('class="result failed"', $html);
        $this->assertStringContainsString('read — failed', $html);
    }

    public function testACommandTheirsAndItsOutputBothAppear(): void
    {
        $html = $this->html([new BashExecution('ls -la', "one\ntwo", 0)]);

        $this->assertStringContainsString('$ ls -la', $html);
        $this->assertStringContainsString('two', $html);
    }

    public function testAFailedCommandSaysWhatItExitedWith(): void
    {
        $html = $this->html([new BashExecution('false', '', 7)]);

        $this->assertStringContainsString('exited 7', $html);
    }

    public function testACompactionSaysHowMuchItReplaced(): void
    {
        $html = $this->html([new CompactionSummary('we talked about things', [], [], 0, replaced: 42)]);

        $this->assertStringContainsString('42 earlier messages summarised', $html);
        $this->assertStringContainsString('we talked about things', $html);
    }

    public function testAFailedTurnSaysWhy(): void
    {
        $html = $this->html([$this->assistant([], StopReason::Error, 'overloaded_error')]);

        $this->assertStringContainsString('overloaded_error', $html);
    }

    public function testAnEmptyTurnDrawsNothing(): void
    {
        // What an interrupted turn leaves behind: a section with a heading and no body
        // is a hole in the page.
        $this->assertStringNotContainsString('class="turn assistant"', $this->html([$this->assistant([])]));
    }

    public function testAnImageTravelsInsideTheFile(): void
    {
        $html = $this->html([new UserMessage([new TextContent('look'), new ImageContent('QUJD', 'image/png')])]);

        // One file means one file: an export whose pictures live beside it is an export
        // that arrives without them.
        $this->assertStringContainsString('src="data:image/png;base64,QUJD"', $html);
    }

    // ---- what must not get out ---------------------------------------------------------------

    public function testAnythingTheModelSaidIsEscaped(): void
    {
        $html = $this->html([$this->assistant([new TextContent('<script>alert(1)</script>')])]);

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testAJavascriptLinkIsNotALink(): void
    {
        $html = MarkdownHtml::render('[click me](javascript:alert(1))');

        // A `javascript:` href in a transcript is a script the model wrote, running when
        // someone opens the file.
        $this->assertStringNotContainsString('href="javascript', $html);
        $this->assertStringContainsString('click me', $html);
    }

    public function testAnOrdinaryLinkIsStillALink(): void
    {
        $html = MarkdownHtml::render('[docs](https://example.com/a)');

        $this->assertStringContainsString('<a href="https://example.com/a"', $html);
        $this->assertStringContainsString('rel="noreferrer noopener"', $html);
    }

    public function testCodeIsColouredWithSpansAndNotEscapeSequences(): void
    {
        $html = MarkdownHtml::code('<?php echo "hi";', 'php');

        $this->assertStringContainsString('<span class="hl-keyword">echo</span>', $html);
        $this->assertStringContainsString('&lt;?php', $html);
        $this->assertStringNotContainsString("\e[", $html);
    }

    public function testATightListStaysTight(): void
    {
        $html = MarkdownHtml::render("- one\n- two");

        // `<li><p>one</p></li>` renders with a blank line above and below in every
        // browser, which turns a tight list into a loose one for no reason.
        $this->assertStringContainsString('<li>one</li>', $html);
    }
}
