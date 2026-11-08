<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use Pig\Agent\AgentError;
use Pig\Agent\AgentTool;
use Pig\Agent\AgentToolResult;
use Pig\Agent\InvalidToolArguments;
use Pig\Agent\ToolArguments;
use Pig\Ai\ToolCall;
use Pig\Ai\Utils\ConstrainedSampling;
use Pig\Async\AbortController;
use Pig\Async\AbortError;
use Pig\CodingAgent\Extensions\ExtensionGuardedTool;
use Pig\CodingAgent\Extensions\ExtensionRunner;
use Pig\CodingAgent\Tools\DescribedTool;
use Pig\CodingAgent\Tools\EditDiff;
use Pig\CodingAgent\Tools\EditTool;
use Pig\CodingAgent\Tools\ResultImagesTool;
use Pig\Test\AssertsThrows;

final class EditToolTest extends ToolTestCase
{
    use AssertsThrows;

    private function edit(): EditTool
    {
        return new EditTool($this->cwd);
    }

    /**
     * The arguments go the way the loop sends them: `prepareArguments()`, then the schema, then
     * the tool — so a shape the tool only accepts through its shim is tested through it.
     */
    #[\Override]
    protected function execute(AgentTool $tool, array $arguments): AgentToolResult
    {
        $call = ToolArguments::prepare($tool, new ToolCall('call-1', 'edit', $arguments));

        return $tool->execute('call-1', ToolArguments::validate($tool->definition(), $call));
    }

    /** @param array<string, mixed> $arguments */
    private function apply(array $arguments): string
    {
        $this->execute($this->edit(), $arguments);

        return (string) file_get_contents($this->cwd . '/' . $arguments['path']);
    }

    // ---- when the turn was called off ----------------------------------------------

    public function testAnEditThatEscapeStoppedLeavesTheFileAsItWas(): void
    {
        $this->file('a.php', "<?php\n\$x = 1;\n");

        $controller = new AbortController();
        $controller->abort();

        // `edit` changes somebody else's file, so the one thing escape has to mean here is that
        // the file on disk is the file that was there before the turn.
        $this->assertThrows(
            AbortError::class,
            fn () => (new EditTool($this->cwd))->execute(
                'call-1',
                ['path' => 'a.php', 'edits' => [['oldText' => '$x = 1;', 'newText' => '$x = 2;']]],
                $controller->signal,
            ),
        );

        $this->assertSame("<?php\n\$x = 1;\n", (string) file_get_contents($this->cwd . '/a.php'));
    }

    public function testAFileThatCannotBeWrittenIsRefusedBeforeItIsRead(): void
    {
        $path = $this->file('locked.php', "<?php\n\$x = 1;\n");
        chmod($path, 0o444);

        if (is_writable($path)) {
            // root writes to anything, so the condition this is about cannot be staged here.
            $this->markTestSkipped('cannot make a file read-only as this user');
        }

        // Read-only is refused by name. Reading it first and letting the write fail would report
        // the same file as unreadable, which sends somebody looking at the wrong permission.
        $this->assertThrows(
            AgentError::class,
            fn () => $this->execute($this->edit(), ['path' => 'locked.php', 'edits' => [['oldText' => '$x = 1;', 'newText' => '$x = 2;']]]),
            'Not readable and writable',
        );
    }

    // ---- replacing -----------------------------------------------------------------

    public function testReplacesTheTextItWasGiven(): void
    {
        $this->file('a.php', "<?php\n\$x = 1;\n");

        $after = $this->apply(['path' => 'a.php', 'edits' => [['oldText' => '$x = 1;', 'newText' => '$x = 2;']]]);

        $this->assertSame("<?php\n\$x = 2;\n", $after);
    }

    public function testReplacesAcrossSeveralLines(): void
    {
        $this->file('a.php', "one\ntwo\nthree\nfour\n");

        $after = $this->apply(['path' => 'a.php', 'edits' => [['oldText' => "two\nthree", 'newText' => 'TWO']]]);

        $this->assertSame("one\nTWO\nfour\n", $after);
    }

    public function testTextThatIsNotThereIsAnErrorSayingWhatToDo(): void
    {
        $this->file('a.php', "<?php\n");

        $error = $this->assertThrows(
            AgentError::class,
            fn () => $this->execute($this->edit(), ['path' => 'a.php', 'edits' => [['oldText' => 'nope', 'newText' => 'x']]]),
            'Could not find',
        );

        // The model is working from something other than the file; tell it to look again.
        $this->assertStringContainsString('read the file', $error->getMessage());
    }

    public function testAmbiguousTextIsRefusedRatherThanGuessedAt(): void
    {
        $this->file('a.php', "return null;\nreturn null;\n");

        $error = $this->assertThrows(
            AgentError::class,
            fn () => $this->execute($this->edit(), ['path' => 'a.php', 'edits' => [['oldText' => 'return null;', 'newText' => 'return 1;']]]),
            'Found 2 occurrences',
        );

        $this->assertStringContainsString('include the lines around it', $error->getMessage());
        // And nothing was written.
        $this->assertSame("return null;\nreturn null;\n", file_get_contents($this->cwd . '/a.php'));
    }

    public function testReplacingTextWithItselfIsAnError(): void
    {
        $this->file('a.php', "same\n");

        $this->assertThrows(
            AgentError::class,
            fn () => $this->execute($this->edit(), ['path' => 'a.php', 'edits' => [['oldText' => 'same', 'newText' => 'same']]]),
            'identical',
        );
    }

    public function testAMissingFileIsAnError(): void
    {
        $this->assertThrows(
            AgentError::class,
            fn () => $this->execute($this->edit(), ['path' => 'nope.php', 'edits' => [['oldText' => 'a', 'newText' => 'b']]]),
            'File not found',
        );
    }

    public function testAnEmptyOldTextIsRefused(): void
    {
        $this->file('a.php', "content\n");

        // It matches everywhere, so "exactly once" would be meaningless.
        $this->assertThrows(
            AgentError::class,
            fn () => $this->execute($this->edit(), ['path' => 'a.php', 'edits' => [['oldText' => '', 'newText' => 'x']]]),
            'oldText is empty',
        );
    }

    public function testOverlappingMatchesCountAsOneAndAreReplaced(): void
    {
        $this->file('a.txt', "aaa\n");

        // `substr_count` does not count overlaps, and neither does the `split(old).length - 1`
        // upstream counts with — so `aa` in `aaa` is one match on both sides, not two.
        $after = $this->apply(['path' => 'a.txt', 'edits' => [['oldText' => 'aa', 'newText' => 'b']]]);

        $this->assertSame("ba\n", $after);
    }

    public function testAFileThatIsNotUtf8KeepsItsBytes(): void
    {
        $this->file('a.txt', "header\n\x80stray\nfooter\n");

        $after = $this->apply(['path' => 'a.txt', 'edits' => [['oldText' => 'footer', 'newText' => 'FOOTER']]]);

        // The deliberate divergence: upstream decodes the file as UTF-8, so the stray byte
        // comes back as U+FFFD and is written over — an edit at the bottom of the file
        // rewriting a line at the top. Reading bytes and splicing bytes leaves it alone.
        $this->assertSame("header\n\x80stray\nFOOTER\n", $after);
    }

    public function testADollarSignInTheReplacementIsLiteral(): void
    {
        $this->file('a.sh', "echo OLD\n");

        $after = $this->apply(['path' => 'a.sh', 'edits' => [['oldText' => 'OLD', 'newText' => '$1 and ${2} and \\0']]]);

        // Spliced rather than passed to a replace function that reads backreferences.
        $this->assertSame("echo \$1 and \${2} and \\0\n", $after);
    }

    // ---- line endings and the BOM --------------------------------------------------

    public function testAFileWithCrlfKeepsItsCrlf(): void
    {
        $this->file('a.txt', "one\r\ntwo\r\nthree\r\n");

        // The model writes \n; rewriting the whole file with LF would turn a one-line
        // edit into a whole-file change in the person's version control.
        $after = $this->apply(['path' => 'a.txt', 'edits' => [['oldText' => 'two', 'newText' => 'TWO']]]);

        $this->assertSame("one\r\nTWO\r\nthree\r\n", $after);
    }

    public function testTextSpanningLinesMatchesWhateverEndingsTheFileUses(): void
    {
        $this->file('a.txt', "one\r\ntwo\r\nthree\r\n");

        $after = $this->apply(['path' => 'a.txt', 'edits' => [['oldText' => "one\ntwo", 'newText' => "ONE\nTWO"]]]);

        $this->assertSame("ONE\r\nTWO\r\nthree\r\n", $after);
    }

    public function testOneStrayCrlfDoesNotMakeItACrlfFile(): void
    {
        $this->file('a.txt', "one\ntwo\r\nthree\n");

        $after = $this->apply(['path' => 'a.txt', 'edits' => [['oldText' => 'one', 'newText' => 'ONE']]]);

        $this->assertStringStartsWith("ONE\n", $after);
    }

    public function testAByteOrderMarkIsKeptAndNotMatchedAgainst(): void
    {
        $this->file('a.php', "\u{FEFF}<?php\necho 1;\n");

        // The mark is invisible, so the model will not have included it; leaving it on
        // would make a match against the first line fail for no visible reason.
        $after = $this->apply(['path' => 'a.php', 'edits' => [['oldText' => '<?php', 'newText' => '<?php declare(strict_types=1);']]]);

        $this->assertSame("\u{FEFF}<?php declare(strict_types=1);\necho 1;\n", $after);
    }

    public function testAMarkAndCrlfBothSurviveTheSameEdit(): void
    {
        $this->file('a.txt', "\u{FEFF}one\r\ntwo\r\n");

        // Both are restored, and in the right order — the mark goes back in front of text
        // whose endings have already been put back. Upstream detects the ending on the
        // mark-stripped text and so does this, though nothing can tell: the three bytes of
        // the mark hold no CR and no LF, so they cannot move where the first one is found.
        $after = $this->apply(['path' => 'a.txt', 'edits' => [['oldText' => 'two', 'newText' => 'TWO']]]);

        $this->assertSame("\u{FEFF}one\r\nTWO\r\n", $after);
    }

    // ---- several edits in one call ------------------------------------------------

    public function testSeveralSeparateRegionsAreReplacedInOneCall(): void
    {
        $this->file('a.txt', "alpha\nbeta\ngamma\ndelta\n");

        $result = $this->execute($this->edit(), ['path' => 'a.txt', 'edits' => [
            ['oldText' => "alpha\n", 'newText' => "ALPHA\n"],
            ['oldText' => "gamma\n", 'newText' => "GAMMA\n"],
        ]]);

        $this->assertSame('Successfully replaced 2 block(s) in a.txt.', $this->textOf($result));
        $this->assertSame("ALPHA\nbeta\nGAMMA\ndelta\n", file_get_contents($this->cwd . '/a.txt'));
        $this->assertSame(
            ['-1 alpha', '+1 ALPHA', ' 2 beta', '-3 gamma', '+3 GAMMA', ' 4 delta', ' 5 '],
            explode("\n", $result->details['diff']),
        );
        $this->assertSame(1, $result->details['firstChangedLine']);
    }

    public function testEditsAreMatchedAgainstTheOriginalNotOneAfterAnother(): void
    {
        $this->file('a.txt', "foo\nbar\nbaz\n");

        // Applied one after another, the second would find `bar` inside `foo bar` and fail as
        // ambiguous; against the original each has its one place.
        $this->apply(['path' => 'a.txt', 'edits' => [
            ['oldText' => "foo\n", 'newText' => "foo bar\n"],
            ['oldText' => "bar\n", 'newText' => "BAR\n"],
        ]]);

        $this->assertSame("foo bar\nBAR\nbaz\n", file_get_contents($this->cwd . '/a.txt'));
    }

    public function testOverlappingEditsAreRefusedAndNothingIsWritten(): void
    {
        $this->file('a.txt', "one\ntwo\nthree\n");

        $this->assertThrows(
            AgentError::class,
            fn () => $this->execute($this->edit(), ['path' => 'a.txt', 'edits' => [
                ['oldText' => "one\ntwo\n", 'newText' => "ONE\nTWO\n"],
                ['oldText' => "two\nthree\n", 'newText' => "TWO\nTHREE\n"],
            ]]),
            'edits[0] and edits[1] overlap in a.txt',
        );

        $this->assertSame("one\ntwo\nthree\n", file_get_contents($this->cwd . '/a.txt'));
    }

    public function testTheSameEditTwiceIsRefusedAsAnOverlap(): void
    {
        $this->file('a.txt', "one\ntwo\n");

        // Both match the one place, so neither knows which text it is replacing.
        $this->assertThrows(
            AgentError::class,
            fn () => $this->execute($this->edit(), ['path' => 'a.txt', 'edits' => [
                ['oldText' => 'two', 'newText' => 'TWO'],
                ['oldText' => 'two', 'newText' => 'TWO'],
            ]]),
            'edits[0] and edits[1] overlap',
        );
    }

    public function testEditsThatOnlyTouchAreNotAnOverlap(): void
    {
        $this->file('a.txt', "abcd\n");

        $after = $this->apply(['path' => 'a.txt', 'edits' => [
            ['oldText' => 'cd', 'newText' => 'CD'],
            ['oldText' => 'ab', 'newText' => 'AB'],
        ]]);

        $this->assertSame("ABCD\n", $after);
    }

    public function testOneEditThatFailsLeavesTheWholeFileAlone(): void
    {
        $this->file('a.txt', "alpha\nbeta\ngamma\n");

        $error = $this->assertThrows(
            AgentError::class,
            fn () => $this->execute($this->edit(), ['path' => 'a.txt', 'edits' => [
                ['oldText' => "alpha\n", 'newText' => "ALPHA\n"],
                ['oldText' => "missing\n", 'newText' => "MISSING\n"],
            ]]),
            'Could not find edits[1].oldText in a.txt',
        );

        $this->assertStringContainsString('read the file', $error->getMessage());
        $this->assertSame("alpha\nbeta\ngamma\n", file_get_contents($this->cwd . '/a.txt'));
    }

    public function testWhichEditIsAmbiguousOrEmptyIsNamed(): void
    {
        $this->file('a.txt', "x\nx\ny\n");

        $this->assertThrows(
            AgentError::class,
            fn () => $this->execute($this->edit(), ['path' => 'a.txt', 'edits' => [
                ['oldText' => 'y', 'newText' => 'Y'],
                ['oldText' => 'x', 'newText' => 'X'],
            ]]),
            'Found 2 occurrences of edits[1].oldText in a.txt',
        );
        $this->assertThrows(
            AgentError::class,
            fn () => $this->execute($this->edit(), ['path' => 'a.txt', 'edits' => [
                ['oldText' => 'y', 'newText' => 'Y'],
                ['oldText' => '', 'newText' => 'X'],
            ]]),
            'edits[1].oldText is empty',
        );
    }

    public function testAnEmptyListOfEditsIsRefused(): void
    {
        $this->file('a.txt', "hello\n");

        $this->assertThrows(
            AgentError::class,
            fn () => $this->execute($this->edit(), ['path' => 'a.txt', 'edits' => []]),
            'edits must contain at least one replacement',
        );
    }

    public function testCrlfAndAByteOrderMarkSurviveSeveralEdits(): void
    {
        $this->file('a.txt', "\u{FEFF}first\r\nsecond\r\nthird\r\nfourth\r\n");

        $after = $this->apply(['path' => 'a.txt', 'edits' => [
            ['oldText' => "second\n", 'newText' => "SECOND\n"],
            ['oldText' => "fourth\n", 'newText' => "FOURTH\n"],
        ]]);

        $this->assertSame("\u{FEFF}first\r\nSECOND\r\nthird\r\nFOURTH\r\n", $after);
    }

    public function testDistantEditsAreDrawnAsSeparateHunks(): void
    {
        $lines = array_map(static fn (int $n): string => sprintf('line %03d', $n), range(1, 600));
        $this->file('a.txt', implode("\n", $lines) . "\n");

        $result = $this->execute($this->edit(), ['path' => 'a.txt', 'edits' => [
            ['oldText' => "line 300\n", 'newText' => "LINE 300\n"],
            ['oldText' => "line 100\n", 'newText' => "LINE 100\n"],
            ['oldText' => "line 500\n", 'newText' => "LINE 500\n"],
        ]]);
        $diff = $result->details['diff'];

        // Upstream's case: three hunks with an ellipsis between, not one block of 400 lines.
        $this->assertStringContainsString('+100 LINE 100', $diff);
        $this->assertStringContainsString('+300 LINE 300', $diff);
        $this->assertStringContainsString('+500 LINE 500', $diff);
        $this->assertStringNotContainsString('line 250', $diff);
        $this->assertLessThan(50, count(explode("\n", $diff)));
        $this->assertSame(100, $result->details['firstChangedLine']);
        $this->assertSame(
            [' 104 line 104', '     ...', ' 296 line 296'],
            array_slice(explode("\n", $diff), 10, 3),
        );
    }

    public function testHunksWithLittleBetweenThemShowAllOfIt(): void
    {
        $lines = array_map(static fn (int $n): string => "l{$n}", range(1, 20));
        $this->file('a.txt', implode("\n", $lines));

        // Eight unchanged lines between: twice the context, so drawn whole, no ellipsis.
        $result = $this->execute($this->edit(), ['path' => 'a.txt', 'edits' => [
            ['oldText' => "l2\n", 'newText' => "L2\nadded\n"],
            ['oldText' => "l11\n", 'newText' => ''],
        ]]);
        $diff = explode("\n", $result->details['diff']);

        $this->assertSame(
            ['  1 l1', '- 2 l2', '+ 2 L2', '+ 3 added', '  4 l3', '  5 l4', '  6 l5', '  7 l6', '  8 l7', '  9 l8',
                ' 10 l9', ' 11 l10', '-11 l11', ' 12 l12', ' 13 l13', ' 14 l14', ' 15 l15', '    ...'],
            $diff,
        );
    }

    public function testAPreviewOfSeveralEditsIsTheDiffTheyWouldProduce(): void
    {
        $this->file('a.txt', "one\ntwo\nthree\nfour\n");
        $edits = [['oldText' => 'one', 'newText' => 'ONE'], ['oldText' => 'four', 'newText' => 'FOUR']];

        [$diff, $line] = EditDiff::preview('a.txt', $edits, $this->cwd);
        $result = $this->execute($this->edit(), ['path' => 'a.txt', 'edits' => $edits]);

        $this->assertSame($result->details['diff'], $diff);
        $this->assertSame($result->details['firstChangedLine'], $line);
    }

    // ---- the shapes the arguments arrive in ------------------------------------------

    public function testTheSchemaHasEditsAndNotTheOldTopLevelFields(): void
    {
        $parameters = $this->edit()->definition()->parameters;

        $this->assertSame(['path', 'edits'], $parameters['required']);
        $this->assertArrayNotHasKey('oldText', $parameters['properties']);
        $this->assertArrayNotHasKey('newText', $parameters['properties']);
        $this->assertSame(['oldText', 'newText'], $parameters['properties']['edits']['items']['required']);
    }

    public function testTheSchemaHoldsUpUnderStrictSampling(): void
    {
        // Sent with `strict: prefer`: strict JSON-schema mode wants every object closed and every
        // property required, the nested `edits[]` objects included.
        $strict = ConstrainedSampling::makeStrictJsonSchema($this->edit()->definition()->parameters);
        $item = $strict['properties']['edits']['items'];

        $this->assertFalse($strict['additionalProperties']);
        $this->assertFalse($item['additionalProperties']);
        $this->assertSame(['oldText', 'newText'], $item['required']);
    }

    public function testTopLevelOldAndNewTextBecomeOneEdit(): void
    {
        $this->assertSame(
            ['path' => 'file.txt', 'edits' => [['oldText' => 'before', 'newText' => 'after']]],
            EditTool::prepare(['path' => 'file.txt', 'oldText' => 'before', 'newText' => 'after']),
        );
    }

    public function testTopLevelOldAndNewTextAreAddedAfterTheEdits(): void
    {
        $this->assertSame(
            ['path' => 'file.txt', 'edits' => [['oldText' => 'a', 'newText' => 'b'], ['oldText' => 'c', 'newText' => 'd']]],
            EditTool::prepare(['path' => 'file.txt', 'edits' => [['oldText' => 'a', 'newText' => 'b']], 'oldText' => 'c', 'newText' => 'd']),
        );
    }

    public function testArgumentsInTheRightShapePassThroughUnchanged(): void
    {
        $arguments = ['path' => 'file.txt', 'edits' => [['oldText' => 'a', 'newText' => 'b']]];

        $this->assertSame($arguments, EditTool::prepare($arguments));
        $call = new ToolCall('call-1', 'edit', $arguments);
        $this->assertSame($call, ToolArguments::prepare($this->edit(), $call));
    }

    public function testEditsSentAsAJsonStringAreDecoded(): void
    {
        $this->assertSame(
            ['path' => 'file.txt', 'edits' => [['oldText' => 'a', 'newText' => 'b']]],
            EditTool::prepare(['path' => 'file.txt', 'edits' => '[{"oldText":"a","newText":"b"}]']),
        );
        $this->assertSame(
            ['path' => 'file.txt', 'edits' => [['oldText' => 'a', 'newText' => 'b']]],
            EditTool::prepare(['path' => 'file.txt', 'edits' => '{"oldText":"a","newText":"b"}']),
        );
    }

    public function testEditsThatAreNotJsonAreLeftForTheSchemaToRefuse(): void
    {
        $this->file('a.txt', "a\n");

        $this->assertSame(['path' => 'a.txt', 'edits' => 'not json'], EditTool::prepare(['path' => 'a.txt', 'edits' => 'not json']));
        $this->assertThrows(
            InvalidToolArguments::class,
            fn () => $this->execute($this->edit(), ['path' => 'a.txt', 'edits' => 'not json']),
            'edits',
        );
    }

    public function testTheToolsWrappersReshapeItsArgumentsAsTheToolWould(): void
    {
        $legacy = new ToolCall('call-1', 'edit', ['path' => 'a.txt', 'oldText' => 'a', 'newText' => 'b']);

        // The loop meets the edit tool only through these, so one that did not pass the shim
        // on would leave every saved session's edits to fail the schema.
        foreach ([
            new ExtensionGuardedTool($this->edit(), new ExtensionRunner()),
            new DescribedTool($this->edit(), 'described'),
            new ResultImagesTool($this->edit(), static fn (array $content): array => $content),
        ] as $wrapper) {
            $this->assertSame(
                ['path' => 'a.txt', 'edits' => [['oldText' => 'a', 'newText' => 'b']]],
                ToolArguments::prepare($wrapper, $legacy)->arguments,
                $wrapper::class,
            );
        }
    }

    public function testOneEditObjectBecomesAListOfOne(): void
    {
        $this->assertSame(
            ['path' => 'file.txt', 'edits' => [['oldText' => 'a', 'newText' => 'b']]],
            EditTool::prepare(['path' => 'file.txt', 'edits' => ['oldText' => 'a', 'newText' => 'b']]),
        );
    }

    /** @param array<string, mixed> $arguments */
    #[\PHPUnit\Framework\Attributes\DataProvider('looserShapes')]
    public function testEveryShapeTheToolAcceptsEditsTheFile(array $arguments): void
    {
        $this->file('a.txt', "before\n");

        $result = $this->execute($this->edit(), ['path' => 'a.txt', ...$arguments]);

        $this->assertSame('Successfully replaced 1 block(s) in a.txt.', $this->textOf($result));
        $this->assertSame("after\n", file_get_contents($this->cwd . '/a.txt'));
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function looserShapes(): array
    {
        return [
            'top-level oldText/newText, as a saved session has it' => [['oldText' => 'before', 'newText' => 'after']],
            'edits as a JSON string' => [['edits' => '[{"oldText":"before","newText":"after"}]']],
            'edits as one object' => [['edits' => ['oldText' => 'before', 'newText' => 'after']]],
        ];
    }

    // ---- the same edit, previewed ---------------------------------------------------

    public function testAPreviewIsTheDiffTheEditWouldProduceAndChangesNothing(): void
    {
        $this->file('a.txt', "one\ntwo\nthree\n");

        [$diff, $line] = EditDiff::preview('a.txt', [['oldText' => 'two', 'newText' => 'TWO']], $this->cwd);

        $this->assertSame("one\ntwo\nthree\n", file_get_contents($this->cwd . '/a.txt'));
        $this->assertSame(2, $line);

        // And it is the diff, not an approximation of it: the edit goes through the same
        // `apply()`, so the preview cannot show one change and the tool make another.
        $result = $this->execute($this->edit(), ['path' => 'a.txt', 'edits' => [['oldText' => 'two', 'newText' => 'TWO']]]);
        $this->assertSame($diff, $result->details['diff']);
        $this->assertSame($line, $result->details['firstChangedLine']);
    }

    public function testAPreviewRefusesInTheWordsTheEditWouldHaveUsed(): void
    {
        $this->file('a.txt', "same\nsame\n");

        $previewed = $this->assertThrows(
            AgentError::class,
            fn () => EditDiff::preview('a.txt', [['oldText' => 'same', 'newText' => 'other']], $this->cwd),
            'Found 2 occurrences',
        );

        $attempted = $this->assertThrows(
            AgentError::class,
            fn () => $this->execute($this->edit(), ['path' => 'a.txt', 'edits' => [['oldText' => 'same', 'newText' => 'other']]]),
        );

        // One sentence, not two that can drift — upstream's have already drifted.
        $this->assertSame($attempted->getMessage(), $previewed->getMessage());
    }

    public function testAPreviewOfAFileThatIsNotThereSaysSo(): void
    {
        $this->assertThrows(
            AgentError::class,
            fn () => EditDiff::preview('nope.txt', [['oldText' => 'a', 'newText' => 'b']], $this->cwd),
            'File not found',
        );
    }

    // ---- the diff ------------------------------------------------------------------

    public function testTheDiffGoesToTheUiWithLineNumbers(): void
    {
        $this->file('a.txt', "one\ntwo\nthree\n");

        $result = $this->execute($this->edit(), ['path' => 'a.txt', 'edits' => [['oldText' => 'two', 'newText' => 'TWO']]]);

        $this->assertSame(2, $result->details['firstChangedLine']);
        $this->assertSame(
            [' 1 one', '-2 two', '+2 TWO', ' 3 three', ' 4 '],
            explode("\n", $result->details['diff']),
        );
    }

    public function testTheModelIsToldItWorkedAndNotShownTheDiff(): void
    {
        $this->file('a.txt', "one\n");

        $result = $this->execute($this->edit(), ['path' => 'a.txt', 'edits' => [['oldText' => 'one', 'newText' => 'two']]]);

        // Two audiences: the model gets the outcome, the UI gets the diff.
        $this->assertSame('Successfully replaced 1 block(s) in a.txt.', $this->textOf($result));
    }

    public function testDistantContextIsElidedWithAnEllipsis(): void
    {
        $lines = array_map(static fn (int $n): string => "line{$n}", range(1, 30));
        $this->file('a.txt', implode("\n", $lines));

        $result = $this->execute($this->edit(), ['path' => 'a.txt', 'edits' => [['oldText' => 'line15', 'newText' => 'CHANGED']]]);
        $diff = explode("\n", $result->details['diff']);

        // Four lines of context either side, and an ellipsis for the rest.
        $this->assertSame('    ...', $diff[0]);
        $this->assertSame(' 11 line11', $diff[1]);
        $this->assertSame('-15 line15', $diff[5]);
        $this->assertSame('+15 CHANGED', $diff[6]);
        $this->assertSame(' 16 line16', $diff[7]);
        $this->assertSame('    ...', $diff[count($diff) - 1]);
    }

    public function testLineNumbersArePaddedToTheWidestOne(): void
    {
        $this->file('a.txt', implode("\n", array_fill(0, 120, 'x')) . "\nneedle\n");

        $result = $this->execute($this->edit(), ['path' => 'a.txt', 'edits' => [['oldText' => 'needle', 'newText' => 'found']]]);

        // Three digits in the file, so every number is three wide and the gutter lines up.
        $this->assertStringContainsString('-121 needle', $result->details['diff']);
        $this->assertStringContainsString(' 120 x', $result->details['diff']);
    }

    public function testAddingAndRemovingDifferentNumbersOfLines(): void
    {
        $this->file('a.txt', "a\nb\nc\n");

        $result = $this->execute($this->edit(), ['path' => 'a.txt', 'edits' => [['oldText' => 'b', 'newText' => "x\ny\nz"]]]);

        $this->assertSame("a\nx\ny\nz\nc\n", file_get_contents($this->cwd . '/a.txt'));
        $this->assertSame(
            [' 1 a', '-2 b', '+2 x', '+3 y', '+4 z', ' 5 c', ' 6 '],
            explode("\n", $result->details['diff']),
        );
    }

    public function testAFileOfIdenticalLinesDoesNotCountTheSameLineTwice(): void
    {
        // Without the guard in commonSuffix, the prefix and suffix would overlap and the
        // middle would come out negative.
        [$diff, $first] = EditDiff::render("x\nx\nx\nx", "x\nx\nx\nx\nx");

        $this->assertSame(5, $first);
        $this->assertSame('+5 x', explode("\n", $diff)[count(explode("\n", $diff)) - 1]);
    }

    public function testRenderingTwoIdenticalTextsIsNoDiffAtAll(): void
    {
        $this->assertSame(['', null], EditDiff::render("a\nb", "a\nb"));
    }

    public function testEmptyingAFileDoesNotClaimABlankLineWasAdded(): void
    {
        // `explode("\n", '')` is one empty line, and an empty document has none. So an edit
        // that emptied a file drew a `+1 ` under the removals — a blank line nobody wrote.
        [$diff] = EditDiff::render("one\ntwo\nthree", '');

        $this->assertSame(['-1 one', '-2 two', '-3 three'], explode("\n", $diff));
    }

    public function testFillingAnEmptyFileDoesNotClaimABlankLineWasRemoved(): void
    {
        [$diff] = EditDiff::render('', 'now has content');

        $this->assertSame(['+1 now has content'], explode("\n", $diff));
    }

    public function testLosingATrailingNewlineIsStillARemovedLine(): void
    {
        // Not the same thing: the last element of `a\nb\n` really is an empty line, and
        // dropping it really is a change. The fix above must not swallow this one.
        [$diff] = EditDiff::render("a\nb\n", "a\nb");

        $this->assertSame(' 1 a', explode("\n", $diff)[0]);
        $this->assertSame('-3 ', explode("\n", $diff)[2]);
    }
}
