<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;
use Pig\Agent\AgentToolResult;
use Pig\Ai\TextContent;
use Pig\CodingAgent\Interactive\ToolExecutionComponent;
use Pig\CodingAgent\Theme\Palette;
use Pig\CodingAgent\Tools\EditDiff;
use Pig\Tui\Ansi;

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

    // ---- a tool that never finished --------------------------------------------------------

    public function testAToolCutOffMidRunSaysSoInRed(): void
    {
        $tool = $this->tool('bash', ['command' => 'sleep 100']);
        $tool->fail('Operation aborted');

        $this->assertStringContainsString('Operation aborted', $this->text($tool));
        $this->assertStringContainsString("\e[48;2;60;40;40m", $this->raw($tool));
    }
}
