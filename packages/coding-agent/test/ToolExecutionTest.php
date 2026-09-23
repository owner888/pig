<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;
use Pig\Agent\AgentToolResult;
use Pig\Ai\ImageContent;
use Pig\Ai\TextContent;
use Pig\CodingAgent\Interactive\ToolExecutionComponent;
use Pig\CodingAgent\Theme\Palette;
use Pig\CodingAgent\Tools\EditDiff;
use Pig\CodingAgent\CustomTools\CustomTool;
use Pig\CodingAgent\CustomTools\RenderOptions;
use Pig\Tui\Ansi;
use Pig\Tui\Component;
use Pig\Tui\Components\Text;
use Closure;
use RuntimeException;

/** One tool call drawn as it happens: heading, state, and output cut to size. */
final class ToolExecutionTest extends TestCase
{
    private const int WIDTH = 76;

    private Palette $palette;

    #[\Override]
    protected function setUp(): void
    {
        $this->palette = Palette::dark(true);
    }

    /** @param array<string, mixed> $arguments */
    private function tool(string $name, array $arguments = []): ToolExecutionComponent
    {
        return new ToolExecutionComponent($name, $arguments, $this->palette);
    }

    private function text(ToolExecutionComponent $tool): string
    {
        return implode("\n", array_map(Ansi::strip(...), $tool->render(self::WIDTH)));
    }

    private function raw(ToolExecutionComponent $tool): string
    {
        return implode("\n", $tool->render(self::WIDTH));
    }

    private function said(string $text): AgentToolResult
    {
        return new AgentToolResult([new TextContent($text)]);
    }

    /** A 1x1 PNG, which is enough for anything that reads a header. */
    private const string PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    // ---- images in the result ----------------------------------------------------------

    public function testAnImageInAResultIsDrawnRatherThanNamedTwice(): void
    {
        $tool = $this->tool('screenshot', []);
        $tool->updateResult(new AgentToolResult([
            new TextContent('captured the window'),
            new ImageContent(self::PNG, 'image/png'),
        ]));

        $shown = $this->text($tool);

        $this->assertStringContainsString('captured the window', $shown);

        // On a terminal that cannot draw one, `Image` labels it itself — so the text
        // half must not label it as well.
        $this->assertSame(1, substr_count($shown, 'image'));
    }

    public function testAResultThatArrivesTwiceDoesNotDrawTheImageTwice(): void
    {
        $tool = $this->tool('screenshot', []);
        $result = new AgentToolResult([new ImageContent(self::PNG, 'image/png')]);

        // A running tool reports its result again on every update.
        $tool->updateResult($result, false, true);
        $tool->updateResult($result, false, true);
        $tool->updateResult($result);

        $this->assertSame(1, substr_count($this->text($tool), 'image'));
    }

    public function testAResultWithNoImageDrawsNothingExtra(): void
    {
        $tool = $this->tool('ls', ['path' => '.']);
        $tool->updateResult($this->said("one\ntwo"));

        $lines = explode("\n", rtrim($this->text($tool)));

        // Every tool now carries an image container; an empty one must cost no rows.
        $this->assertStringNotContainsString('image', $this->text($tool));
        $this->assertSame('two', trim($lines[count($lines) - 1]));
    }

    // ---- what state it is in ---------------------------------------------------------

    public function testTheBackgroundGoesFromPendingToDoneToFailed(): void
    {
        // The state has to be readable without reading anything, because that is what
        // someone scrolling past a hundred tool calls is doing.
        $pendingBg = "\e[48;2;40;40;50m";
        $successBg = "\e[48;2;40;50;40m";
        $errorBg = "\e[48;2;60;40;40m";

        $tool = $this->tool('ls', ['path' => '.']);
        $this->assertStringContainsString($pendingBg, $this->raw($tool));

        $tool->updateResult($this->said('a.php'));
        $this->assertStringContainsString($successBg, $this->raw($tool));

        $tool->updateResult($this->said('boom'), true);
        $this->assertStringContainsString($errorBg, $this->raw($tool));
    }

    public function testAPartialResultIsStillPending(): void
    {
        $tool = $this->tool('bash', ['command' => 'sleep 1']);
        $tool->updateResult($this->said('halfway'), false, true);

        $this->assertStringContainsString("\e[48;2;40;40;50m", $this->raw($tool));
    }

    // ---- the heading -------------------------------------------------------------------

    public function testEachToolSaysWhatItDidToWhat(): void
    {
        $this->assertStringContainsString('read /a/b.php', $this->text($this->tool('read', ['path' => '/a/b.php'])));
        $this->assertStringContainsString('ls src', $this->text($this->tool('ls', ['path' => 'src'])));
        $this->assertStringContainsString(
            'find *.php in src',
            $this->text($this->tool('find', ['pattern' => '*.php', 'path' => 'src'])),
        );
        $this->assertStringContainsString(
            'grep /needle/ in . (*.php)',
            $this->text($this->tool('grep', ['pattern' => 'needle', 'glob' => '*.php'])),
        );
    }

    public function testAPathStillArrivingShowsAPlaceholder(): void
    {
        // Arguments stream in, so for a moment there is a tool name and nothing else.
        // An empty line there reads as a tool that did nothing.
        $this->assertStringContainsString('read ...', $this->text($this->tool('read')));
    }

    public function testALineRangeIsShownNextToThePath(): void
    {
        $tool = $this->tool('read', ['path' => 'a.php', 'offset' => 10, 'limit' => 5]);

        $this->assertStringContainsString('a.php:10-14', $this->text($tool));
    }

    public function testAnUnknownToolShowsItsArgumentsRatherThanNothing(): void
    {
        $tool = $this->tool('weather', ['city' => 'Singapore']);
        $tool->updateResult($this->said('31°C'));

        $this->assertStringContainsString('weather', $this->text($tool));
        $this->assertStringContainsString('Singapore', $this->text($tool));
        $this->assertStringContainsString('31°C', $this->text($tool));
    }

    // ---- how much of the output --------------------------------------------------------

    public function testLongOutputIsCutAndSaysHowMuchIsLeft(): void
    {
        $tool = $this->tool('ls', ['path' => '.']);
        $tool->updateResult($this->said(implode("\n", array_map(static fn (int $i): string => "f{$i}.php", range(1, 30)))));

        $text = $this->text($tool);

        $this->assertStringContainsString('f20.php', $text);
        $this->assertStringNotContainsString('f21.php', $text);
        $this->assertStringContainsString('... (10 more lines)', $text);
    }

    public function testExpandingShowsAllOfIt(): void
    {
        $tool = $this->tool('ls', ['path' => '.']);
        $tool->updateResult($this->said(implode("\n", array_map(static fn (int $i): string => "f{$i}.php", range(1, 30)))));
        $tool->setExpanded(true);

        $text = $this->text($tool);

        $this->assertStringContainsString('f30.php', $text);
        $this->assertStringNotContainsString('more lines', $text);
    }

    public function testEscapesInAToolsOutputAreStripped(): void
    {
        // A command that prints its own colours would otherwise paint over the box —
        // including the background that says whether it succeeded.
        $tool = $this->tool('ls', ['path' => '.']);
        $tool->updateResult($this->said("\e[31mred.php\e[0m\e[2J"));

        $raw = $this->raw($tool);

        $this->assertStringContainsString('red.php', Ansi::strip($raw));
        $this->assertStringNotContainsString("\e[31m", $raw);
        $this->assertStringNotContainsString("\e[2J", $raw);
    }

    // ---- the ones with a view of their own ----------------------------------------------

    public function testAReadIsSyntaxColouredByItsFileName(): void
    {
        $tool = $this->tool('read', ['path' => 'a.php']);
        $tool->updateResult($this->said("<?php\nreturn 1;\n"));

        // dark's syntaxKeyword.
        $this->assertStringContainsString("\e[38;2;86;156;214mreturn", $this->raw($tool));
    }

    public function testAFileWithNoKnownLanguageIsShownPlain(): void
    {
        $tool = $this->tool('read', ['path' => 'notes.txt']);
        $tool->updateResult($this->said("return 1;\n"));

        $this->assertStringNotContainsString("\e[38;2;86;156;214m", $this->raw($tool));
        $this->assertStringContainsString('return 1;', $this->text($tool));
    }

    public function testAWriteIsDrawnFromItsArgumentsNotItsResult(): void
    {
        // The point of watching a write is seeing what is about to be written; by the
        // time the result arrives it is too late to care.
        $tool = $this->tool('write', ['path' => 'a.php', 'content' => "<?php\nreturn 2;\n"]);

        $this->assertStringContainsString('return 2;', $this->text($tool));
    }

    public function testAnEditShowsTheDiffTheToolActuallyWrote(): void
    {
        [$diff, $line] = EditDiff::render("a\nold\nc\n", "a\nnew\nc\n");

        $tool = $this->tool('edit', ['path' => 'a.php']);
        $tool->updateResult(new AgentToolResult(
            [new TextContent('Replaced text in a.php.')],
            ['diff' => $diff, 'firstChangedLine' => $line],
        ));

        $text = $this->text($tool);

        $this->assertStringContainsString('a.php:2', $text);
        $this->assertStringContainsString('-2 old', $text);
        $this->assertStringContainsString('+2 new', $text);
    }

    public function testAFailedEditShowsWhyAndNoDiff(): void
    {
        $tool = $this->tool('edit', ['path' => 'a.php']);
        $tool->updateResult($this->said('Could not find that exact text in a.php.'), true);

        $this->assertStringContainsString('Could not find that exact text', $this->text($tool));
    }

    // ---- bash ----------------------------------------------------------------------------

    public function testBashShowsTheCommandAndTheEndOfItsOutput(): void
    {
        $tool = $this->tool('bash', ['command' => 'make test']);
        $tool->updateResult($this->said(implode("\n", array_map(static fn (int $i): string => "line {$i}", range(1, 12)))));

        $text = $this->text($tool);

        // The end, not the beginning: a build says what happened when it finished.
        $this->assertStringContainsString('$ make test', $text);
        $this->assertStringContainsString('line 12', $text);
        $this->assertStringNotContainsString('line 1 ', $text);
        $this->assertStringContainsString('... (7 earlier lines)', $text);
    }

    public function testBashCountsRowsOnScreenNotNewlines(): void
    {
        // One 400-character line is several rows. Counting it as one row pushes
        // everything else off the bottom of the box.
        $tool = $this->tool('bash', ['command' => 'cat long.txt']);
        $tool->updateResult($this->said(str_repeat('x', 400) . "\nlast line"));

        $lines = $tool->render(self::WIDTH);

        // Spacer, command, blank, the note, five kept rows, and the box's own padding.
        $this->assertLessThan(14, count($lines));
        $this->assertStringContainsString('last line', implode("\n", array_map(Ansi::strip(...), $lines)));
    }

    public function testAnExpandedBashShowsEverything(): void
    {
        $tool = $this->tool('bash', ['command' => 'make test']);
        $tool->updateResult($this->said(implode("\n", array_map(static fn (int $i): string => "line {$i}", range(1, 12)))));
        $tool->setExpanded(true);

        $text = $this->text($tool);

        $this->assertStringContainsString('line 1', $text);
        $this->assertStringNotContainsString('earlier lines', $text);
    }

    public function testACommandStillArrivingShowsAPlaceholder(): void
    {
        $this->assertStringContainsString('$ ...', $this->text($this->tool('bash')));
    }

    // ---- a tool that draws itself ------------------------------------------------------

    public function testACustomToolWithNoRenderersIsDrawnLikeAnyOther(): void
    {
        $tool = $this->custom();
        $tool->updateResult($this->said('done'), partial: false);

        $this->assertStringContainsString('done', $this->text($tool));
    }

    public function testARenderCallReplacesTheHeading(): void
    {
        $tool = $this->custom(
            renderCall: static fn (array $arguments, Palette $palette): Component => new Text(
                'counting ' . ($arguments['path'] ?? '?'),
                0,
                0,
            ),
        );

        $this->assertStringContainsString('counting notes.md', $this->text($tool));
    }

    public function testARenderResultReplacesTheOutput(): void
    {
        $tool = $this->custom(
            renderResult: static fn (AgentToolResult $result, RenderOptions $options): Component => new Text(
                'drawn by the tool',
                0,
                0,
            ),
        );

        $tool->updateResult($this->said('the raw text'), partial: false);
        $shown = $this->text($tool);

        $this->assertStringContainsString('drawn by the tool', $shown);
        $this->assertStringNotContainsString('the raw text', $shown);
    }

    /** Two independent halves: a tool may draw one and leave the other to the default. */
    public function testDrawingOnlyTheHeadingLeavesTheDefaultOutput(): void
    {
        $tool = $this->custom(
            renderCall: static fn (): Component => new Text('my own heading', 0, 0),
        );

        $tool->updateResult($this->said('the raw text'), partial: false);
        $shown = $this->text($tool);

        $this->assertStringContainsString('my own heading', $shown);
        $this->assertStringContainsString('the raw text', $shown);
    }

    public function testDrawingOnlyTheResultLeavesTheLabelAsTheHeading(): void
    {
        $tool = $this->custom(
            renderResult: static fn (): Component => new Text('my own result', 0, 0),
        );

        $tool->updateResult($this->said('raw'), partial: false);
        $shown = $this->text($tool);

        $this->assertStringContainsString('Count lines', $shown);
        $this->assertStringContainsString('my own result', $shown);
    }

    public function testTheResultRendererIsToldWhetherItIsExpandedAndStillRunning(): void
    {
        $seen = [];
        $tool = $this->custom(
            renderResult: static function (AgentToolResult $result, RenderOptions $options) use (&$seen): Component {
                $seen[] = [$options->expanded, $options->partial];

                return new Text('drawn', 0, 0);
            },
        );

        $tool->updateResult($this->said('half'), partial: true);
        $tool->setExpanded(true);
        $tool->updateResult($this->said('all of it'), partial: false);

        $this->assertContains([false, true], $seen);
        $this->assertContains([true, false], $seen);
    }

    /** A picture that silently turns into plain text is a bug nobody reports. */
    public function testARendererThatThrowsFallsBackAndSaysSo(): void
    {
        $problems = [];
        $tool = $this->custom(
            renderCall: static function (): Component {
                throw new RuntimeException('no colour for that');
            },
            onError: static function (string $problem) use (&$problems): void {
                $problems[] = $problem;
            },
        );

        $tool->updateResult($this->said('still shown'), partial: false);
        $shown = $this->text($tool);

        // The label, which is what a tool with no renderer would have shown.
        $this->assertStringContainsString('Count lines', $shown);
        $this->assertStringContainsString('still shown', $shown);
        $this->assertCount(1, $problems);
        $this->assertStringContainsString('renderCall failed', $problems[0]);
        $this->assertStringContainsString('no colour for that', $problems[0]);
    }

    /** `draw()` runs on every update, and a broken renderer would fill the transcript. */
    public function testABrokenRendererIsReportedOnceRatherThanEveryFrame(): void
    {
        $problems = [];
        $tool = $this->custom(
            renderResult: static function (): Component {
                throw new RuntimeException('still broken');
            },
            onError: static function (string $problem) use (&$problems): void {
                $problems[] = $problem;
            },
        );

        $tool->updateResult($this->said('one'), partial: true);
        $tool->updateResult($this->said('two'), partial: true);
        $tool->updateResult($this->said('three'), partial: false);
        $tool->setExpanded(true);

        $this->assertCount(1, $problems);
    }

    public function testARendererThatReturnsSomethingElseIsReported(): void
    {
        $problems = [];
        $tool = $this->custom(
            renderCall: static fn (): mixed => 'a string, not a component',
            onError: static function (string $problem) use (&$problems): void {
                $problems[] = $problem;
            },
        );

        $this->assertStringContainsString('Count lines', $this->text($tool));
        $this->assertStringContainsString('returned string, expected a component', $problems[0]);
    }

    /** Returning null is a renderer saying "nothing here", not a failure. */
    public function testARendererThatDrawsNothingIsNotAComplaint(): void
    {
        $problems = [];
        $tool = $this->custom(
            renderResult: static fn (): ?Component => null,
            onError: static function (string $problem) use (&$problems): void {
                $problems[] = $problem;
            },
        );

        $tool->updateResult($this->said('the raw text'), partial: false);

        $this->assertStringContainsString('the raw text', $this->text($tool));
        $this->assertSame([], $problems);
    }

    public function testACustomToolStillCarriesTheStateColour(): void
    {
        $tool = $this->custom(renderCall: static fn (): Component => new Text('mine', 0, 0));
        $tool->fail('it broke');

        $this->assertStringContainsString("\e[48;2;60;40;40m", $this->raw($tool));
    }

    private function custom(
        ?Closure $renderCall = null,
        ?Closure $renderResult = null,
        ?Closure $onError = null,
    ): ToolExecutionComponent {
        $declaration = new CustomTool(
            name: 'wc',
            label: 'Count lines',
            description: 'Counts the lines in a file.',
            parameters: ['type' => 'object', 'properties' => []],
            execute: static fn () => new AgentToolResult([new TextContent('ran')]),
            renderCall: $renderCall,
            renderResult: $renderResult,
        );

        return new ToolExecutionComponent(
            'wc',
            ['path' => 'notes.md'],
            $this->palette,
            $declaration,
            $onError,
        );
    }

    // ---- a tool that never finished --------------------------------------------------------

    public function testAToolCutOffMidRunSaysSoInRed(): void
    {
        $tool = $this->tool('bash', ['command' => 'sleep 100']);
        $tool->fail('Operation aborted');

        $this->assertStringContainsString('Operation aborted', $this->text($tool));
        $this->assertStringContainsString("\e[48;2;60;40;40m", $this->raw($tool));
    }
}
