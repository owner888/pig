<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\Attributes\DataProvider;
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
use Pig\Tui\Width;
use Closure;
use RuntimeException;

/** One tool call drawn as it happens: heading, state, and output cut to size. */
final class ToolExecutionTest extends TestCase
{
    private const int WIDTH = 76;

    private Palette $palette;

    /** @var list<string> scratch directories made by `project()` */
    private array $scratch = [];

    #[\Override]
    protected function setUp(): void
    {
        $this->palette = Palette::dark(true);
    }

    #[\Override]
    protected function tearDown(): void
    {
        foreach ($this->scratch as $directory) {
            foreach (glob($directory . '/*') ?: [] as $file) {
                unlink($file);
            }

            rmdir($directory);
        }

        $this->scratch = [];
    }

    /** A directory holding one file, for the edit preview to read. */
    private function project(string $name, string $contents): string
    {
        $directory = sys_get_temp_dir() . '/pig-preview-' . getmypid() . '-' . bin2hex(random_bytes(4));
        mkdir($directory, 0o700, true);
        file_put_contents($directory . '/' . $name, $contents);
        $this->scratch[] = $directory;

        return $directory;
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

    public function testWithPicturesTurnedOffAnImageIsNamedInstead(): void
    {
        $tool = new ToolExecutionComponent('screenshot', [], $this->palette, showImages: false);
        $tool->updateResult(new AgentToolResult([
            new TextContent('captured the window'),
            new ImageContent(self::PNG, 'image/png'),
        ]));

        $shown = $this->text($tool);

        // Named, not dropped: a result that came back with a picture in it still says so.
        // And named once, the same as when the terminal cannot draw it — the label comes
        // from the same function, with the size read from the same header.
        $this->assertStringContainsString('captured the window', $shown);
        $this->assertStringContainsString('[Image: [image/png] 1x1]', $shown);
        $this->assertSame(1, substr_count($shown, 'image/png'));
    }

    public function testTurningPicturesOffRedrawsWhatIsAlreadyOnScreen(): void
    {
        $tool = $this->tool('screenshot', []);
        $tool->updateResult(new AgentToolResult([new ImageContent(self::PNG, 'image/png')]));

        $tool->setShowImages(false);

        // The setting is changed from `/settings` while a transcript is already drawn, so a
        // component that kept the answer from construction would apply it to nothing.
        $this->assertStringContainsString('[Image: [image/png] 1x1]', $this->text($tool));
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

    public function testWhatTheToolCutIsSaidWhereCollapsingCannotHideIt(): void
    {
        // The tool puts its notice at the *end* of the output, for the model to read last —
        // and the collapsed view keeps the *front* of it, so that one line is exactly the
        // line that gets cut. Upstream says it twice for this reason: once in the text for
        // the model, once in the component for the person.
        $listing = implode("\n", array_map(static fn (int $i): string => "f{$i}.php", range(1, 30)));
        $notice = '200 entry limit reached. Use limit=400 for more';

        $tool = $this->tool('ls', ['path' => '.']);
        $tool->updateResult(new AgentToolResult(
            [new TextContent($listing . "\n\n[{$notice}]")],
            ['notice' => $notice],
        ));

        $text = $this->text($tool);

        $this->assertStringContainsString('... (12 more lines)', $text);
        $this->assertStringContainsString("[{$notice}]", $text);
    }

    public function testItIsNotSaidTwiceWhenTheWholeOutputIsOnScreen(): void
    {
        // Expanded, the output's own notice is right there — a second copy of the same
        // sentence under it reads as a rendering fault rather than as a warning.
        $notice = '200 entry limit reached. Use limit=400 for more';

        $tool = $this->tool('ls', ['path' => '.']);
        $tool->updateResult(new AgentToolResult(
            [new TextContent("f1.php\nf2.php\n\n[{$notice}]")],
            ['notice' => $notice],
        ));

        $this->assertSame(1, substr_count($this->text($tool), $notice));

        $tool->setExpanded(true);

        $this->assertSame(1, substr_count($this->text($tool), $notice));
    }

    public function testATruncatedReadSaysSoUnderThePreview(): void
    {
        $notice = 'Showing lines 1-2000 of 8431. Use offset=2001 to continue';

        $tool = $this->tool('read', ['path' => '/tmp/big.txt']);
        $tool->updateResult(new AgentToolResult(
            [new TextContent(implode("\n", array_fill(0, 40, 'x')) . "\n\n[{$notice}]")],
            ['notice' => $notice],
        ));

        $this->assertStringContainsString("[{$notice}]", $this->text($tool));
    }

    public function testAToolThatCutNothingAddsNoLine(): void
    {
        $tool = $this->tool('ls', ['path' => '.']);
        $tool->updateResult($this->said(implode("\n", array_map(static fn (int $i): string => "f{$i}.php", range(1, 30)))));

        $this->assertStringNotContainsString('[', $this->text($tool));
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

    public function testACutWriteSaysHowManyLinesTheWholeFileHas(): void
    {
        // Upstream's write says `(N more lines, M total)` where every other tool says only
        // `(N more lines)`, and the extra number is the one that matters here: the content is
        // the whole file, so how big it is about to be is the thing being decided.
        $content = implode("\n", array_map(static fn (int $i): string => "line {$i}", range(1, 25)));
        $tool = $this->tool('write', ['path' => 'notes.txt', 'content' => $content]);

        $this->assertStringContainsString('... (15 more lines, 25 total)', $this->text($tool));
    }

    public function testAReadSaysOnlyHowManyMoreLinesThereAre(): void
    {
        // Not a total: what a read shows is a window into a file, and the number of lines in
        // the window is not the number of lines in the file.
        $lines = implode("\n", array_map(static fn (int $i): string => "line {$i}", range(1, 25)));
        $tool = $this->tool('read', ['path' => 'notes.txt']);
        $tool->updateResult($this->said($lines));

        $text = $this->text($tool);

        $this->assertStringContainsString('... (15 more lines)', $text);
        $this->assertStringNotContainsString('total', $text);
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

    /** @return array<string, array{string}> */
    public static function binaryOutputs(): array
    {
        return [
            'a stray continuation byte' => ["hello \x80 world"],
            'a truncated multi-byte sequence' => ["prefix \xe4\xbd suffix"],
            'a lone 0xff' => ["\xff\xfe binary header"],
            'a surrogate encoded as UTF-8' => ["a \xed\xa0\x80 b"],
        ];
    }

    /**
     * `cat` on anything that is not UTF-8 used to kill the session.
     *
     * `Graphemes::split()` is `preg_match_all('/\X/u')`, which answers false on malformed
     * UTF-8 and therefore throws — out of `render()`, in the loop's own input callback.
     */
    #[DataProvider('binaryOutputs')]
    public function testOutputThatIsNotUtf8IsDrawnRatherThanFatal(string $output): void
    {
        $tool = $this->tool('bash', ['command' => 'cat thing']);
        $tool->updateResult($this->said($output));

        $lines = $tool->render(60);

        $this->assertNotSame([], $lines);
        $this->assertTrue(mb_check_encoding(implode("\n", $lines), 'UTF-8'), 'the drawn frame is not UTF-8');
    }

    /**
     * The same killer through the one path `Utf8::sanitize()` did not cover.
     *
     * `edit`'s diff is built from the file's own bytes and drawn through `DiffView` rather
     * than through `output()`, so editing any file with one byte that is not UTF-8 in it
     * took the session down — no tool output involved, and nothing on screen to say why.
     */
    #[DataProvider('binaryOutputs')]
    public function testAnEditDiffThatIsNotUtf8IsDrawnRatherThanFatal(string $content): void
    {
        $tool = $this->tool('edit', ['path' => 'notes.txt']);
        $tool->updateResult(new AgentToolResult(
            [new TextContent('Replaced text in notes.txt.')],
            ['diff' => " 1 keep\n-2 {$content}\n+2 {$content} changed\n", 'firstChangedLine' => 2],
        ));

        $lines = $tool->render(60);

        $this->assertNotSame([], $lines);
        $this->assertTrue(mb_check_encoding(implode("\n", $lines), 'UTF-8'), 'the drawn frame is not UTF-8');
    }

    // ---- an edit shown before it happens -----------------------------------------------

    /** @param array<string, mixed> $arguments */
    private function editing(string $directory, array $arguments): ToolExecutionComponent
    {
        return new ToolExecutionComponent('edit', $arguments, $this->palette, cwd: $directory);
    }

    public function testAnEditIsShownAsADiffBeforeItHappens(): void
    {
        $directory = $this->project('notes.txt', "one\ntwo\nthree\n");
        $tool = $this->editing($directory, ['path' => 'notes.txt', 'oldText' => 'two', 'newText' => 'TWO']);

        $tool->setArgsComplete();
        $shown = $this->text($tool);

        // No result yet: this is the file as it still stands, read to answer "what is this
        // call about to do" while a hook may be asking whether to let it.
        $this->assertStringContainsString('-2 two', $shown);
        $this->assertStringContainsString('+2 TWO', $shown);
        $this->assertStringContainsString('notes.txt:2', $shown);
        $this->assertSame("one\ntwo\nthree\n", file_get_contents($directory . '/notes.txt'));
    }

    public function testAnEditThatCannotBeMadeSaysSoBeforeItIsTried(): void
    {
        $directory = $this->project('notes.txt', "same\nsame\n");
        $tool = $this->editing($directory, ['path' => 'notes.txt', 'oldText' => 'same', 'newText' => 'other']);

        $tool->setArgsComplete();

        // The tool's own words, from the tool's own check — worth reading before the call
        // runs rather than after it has failed.
        $this->assertStringContainsString('Found 2 occurrences', $this->text($tool));
    }

    public function testTheToolsOwnDiffReplacesThePreviewOnceItHasRun(): void
    {
        $directory = $this->project('notes.txt', "one\ntwo\n");
        $tool = $this->editing($directory, ['path' => 'notes.txt', 'oldText' => 'two', 'newText' => 'TWO']);

        $tool->setArgsComplete();
        $tool->updateResult(new AgentToolResult(
            [new TextContent('Replaced text in notes.txt.')],
            ['diff' => " 1 one\n-2 two\n+2 WROTE\n", 'firstChangedLine' => 2],
        ));

        // The preview read the file as it was; the result was built from the file actually
        // written, and that is the one on disk.
        $shown = $this->text($tool);
        $this->assertStringContainsString('+2 WROTE', $shown);
        $this->assertStringNotContainsString('+2 TWO', $shown);
    }

    public function testThereIsNoPreviewWithoutSomewhereToReadFrom(): void
    {
        $this->project('notes.txt', "one\ntwo\n");
        $tool = $this->tool('edit', ['path' => 'notes.txt', 'oldText' => 'two', 'newText' => 'TWO']);

        $tool->setArgsComplete();

        // No cwd given, so a relative path names nothing — answering from the process's own
        // directory would preview an edit to whichever file happened to be there.
        $this->assertStringNotContainsString('+2', $this->text($tool));
        $this->assertStringNotContainsString('Could not find', $this->text($tool));
    }

    public function testArgumentsThatNeverFinishedArrivingAreNotPreviewed(): void
    {
        $directory = $this->project('notes.txt', "one\ntwo\n");
        $tool = $this->editing($directory, ['path' => 'notes.txt', 'oldText' => 'two']);

        $tool->setArgsComplete();

        // A turn can end on a half-written call; there is nothing to preview and nothing
        // to complain about either.
        $this->assertStringNotContainsString('Could not find', $this->text($tool));
        $this->assertStringContainsString('notes.txt', $this->text($tool));
    }

    public function testTheEarlierLinesNoteFitsANarrowTerminal(): void
    {
        // Every line a component hands back has to fit, because `Tui::checkWidth()` throws on
        // one that does not. `... (35 earlier lines)` is 22 columns and was never cut to the
        // width, so a narrow pane took the session down as soon as any output was truncated.
        $tool = $this->tool('bash', ['command' => 'make']);
        $tool->updateResult($this->said(implode("\n", array_map(static fn (int $i): string => "line {$i}", range(1, 40)))));

        for ($width = 8; $width <= 60; $width++) {
            foreach ($tool->render($width) as $index => $line) {
                $this->assertLessThanOrEqual(
                    $width,
                    Width::visible($line),
                    "line {$index} at width {$width}: " . Ansi::strip($line),
                );
            }
        }
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

    public function testACommandTypedWithABangKeepsFourTimesAsMuch(): void
    {
        $output = implode("\n", array_map(static fn (int $i): string => "line {$i}", range(1, 30)));

        $model = $this->tool('bash', ['command' => 'make test']);
        $model->updateResult($this->said($output));

        $typed = new ToolExecutionComponent(
            'bash',
            ['command' => 'make test'],
            $this->palette,
            bashLines: ToolExecutionComponent::TYPED_BASH_LINES,
        );
        $typed->updateResult($this->said($output));

        // Upstream's two components, upstream's two numbers: 5 for a command the model ran,
        // 20 for one the person typed and is looking at.
        $this->assertStringContainsString('... (25 earlier lines)', $this->text($model));
        $this->assertStringContainsString('... (10 earlier lines)', $this->text($typed));
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
