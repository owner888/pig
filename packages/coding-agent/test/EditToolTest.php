<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use Pig\Agent\AgentError;
use Pig\CodingAgent\Tools\EditDiff;
use Pig\CodingAgent\Tools\EditTool;
use Pig\Test\AssertsThrows;

final class EditToolTest extends ToolTestCase
{
    use AssertsThrows;

    private function edit(): EditTool
    {
        return new EditTool($this->cwd);
    }

    /** @param array<string, mixed> $arguments */
    private function apply(array $arguments): string
    {
        $this->run($this->edit(), $arguments);

        return (string) file_get_contents($this->cwd . '/' . $arguments['path']);
    }

    // ---- replacing -----------------------------------------------------------------

    public function testReplacesTheTextItWasGiven(): void
    {
        $this->file('a.php', "<?php\n\$x = 1;\n");

        $after = $this->apply(['path' => 'a.php', 'oldText' => '$x = 1;', 'newText' => '$x = 2;']);

        $this->assertSame("<?php\n\$x = 2;\n", $after);
    }

    public function testReplacesAcrossSeveralLines(): void
    {
        $this->file('a.php', "one\ntwo\nthree\nfour\n");

        $after = $this->apply(['path' => 'a.php', 'oldText' => "two\nthree", 'newText' => 'TWO']);

        $this->assertSame("one\nTWO\nfour\n", $after);
    }

    public function testTextThatIsNotThereIsAnErrorSayingWhatToDo(): void
    {
        $this->file('a.php', "<?php\n");

        $error = $this->assertThrows(
            AgentError::class,
            fn () => $this->run($this->edit(), ['path' => 'a.php', 'oldText' => 'nope', 'newText' => 'x']),
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
            fn () => $this->run($this->edit(), ['path' => 'a.php', 'oldText' => 'return null;', 'newText' => 'return 1;']),
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
            fn () => $this->run($this->edit(), ['path' => 'a.php', 'oldText' => 'same', 'newText' => 'same']),
            'identical',
        );
    }

    public function testAMissingFileIsAnError(): void
    {
        $this->assertThrows(
            AgentError::class,
            fn () => $this->run($this->edit(), ['path' => 'nope.php', 'oldText' => 'a', 'newText' => 'b']),
            'File not found',
        );
    }

    public function testAnEmptyOldTextIsRefused(): void
    {
        $this->file('a.php', "content\n");

        // It matches everywhere, so "exactly once" would be meaningless.
        $this->assertThrows(
            AgentError::class,
            fn () => $this->run($this->edit(), ['path' => 'a.php', 'oldText' => '', 'newText' => 'x']),
            'oldText is empty',
        );
    }

    public function testADollarSignInTheReplacementIsLiteral(): void
    {
        $this->file('a.sh', "echo OLD\n");

        $after = $this->apply(['path' => 'a.sh', 'oldText' => 'OLD', 'newText' => '$1 and ${2} and \\0']);

        // Spliced rather than passed to a replace function that reads backreferences.
        $this->assertSame("echo \$1 and \${2} and \\0\n", $after);
    }

    // ---- line endings and the BOM --------------------------------------------------

    public function testAFileWithCrlfKeepsItsCrlf(): void
    {
        $this->file('a.txt', "one\r\ntwo\r\nthree\r\n");

        // The model writes \n; rewriting the whole file with LF would turn a one-line
        // edit into a whole-file change in the person's version control.
        $after = $this->apply(['path' => 'a.txt', 'oldText' => 'two', 'newText' => 'TWO']);

        $this->assertSame("one\r\nTWO\r\nthree\r\n", $after);
    }

    public function testTextSpanningLinesMatchesWhateverEndingsTheFileUses(): void
    {
        $this->file('a.txt', "one\r\ntwo\r\nthree\r\n");

        $after = $this->apply(['path' => 'a.txt', 'oldText' => "one\ntwo", 'newText' => "ONE\nTWO"]);

        $this->assertSame("ONE\r\nTWO\r\nthree\r\n", $after);
    }

    public function testOneStrayCrlfDoesNotMakeItACrlfFile(): void
    {
        $this->file('a.txt', "one\ntwo\r\nthree\n");

        $after = $this->apply(['path' => 'a.txt', 'oldText' => 'one', 'newText' => 'ONE']);

        $this->assertStringStartsWith("ONE\n", $after);
    }

    public function testAByteOrderMarkIsKeptAndNotMatchedAgainst(): void
    {
        $this->file('a.php', "\u{FEFF}<?php\necho 1;\n");

        // The mark is invisible, so the model will not have included it; leaving it on
        // would make a match against the first line fail for no visible reason.
        $after = $this->apply(['path' => 'a.php', 'oldText' => '<?php', 'newText' => '<?php declare(strict_types=1);']);

        $this->assertSame("\u{FEFF}<?php declare(strict_types=1);\necho 1;\n", $after);
    }

    // ---- the diff ------------------------------------------------------------------

    public function testTheDiffGoesToTheUiWithLineNumbers(): void
    {
        $this->file('a.txt', "one\ntwo\nthree\n");

        $result = $this->run($this->edit(), ['path' => 'a.txt', 'oldText' => 'two', 'newText' => 'TWO']);

        $this->assertSame(2, $result->details['firstChangedLine']);
        $this->assertSame(
            [' 1 one', '-2 two', '+2 TWO', ' 3 three', ' 4 '],
            explode("\n", $result->details['diff']),
        );
    }

    public function testTheModelIsToldItWorkedAndNotShownTheDiff(): void
    {
        $this->file('a.txt', "one\n");

        $result = $this->run($this->edit(), ['path' => 'a.txt', 'oldText' => 'one', 'newText' => 'two']);

        // Two audiences: the model gets the outcome, the UI gets the diff.
        $this->assertSame('Replaced text in a.txt.', $this->output($result));
    }

    public function testDistantContextIsElidedWithAnEllipsis(): void
    {
        $lines = array_map(static fn (int $n): string => "line{$n}", range(1, 30));
        $this->file('a.txt', implode("\n", $lines));

        $result = $this->run($this->edit(), ['path' => 'a.txt', 'oldText' => 'line15', 'newText' => 'CHANGED']);
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

        $result = $this->run($this->edit(), ['path' => 'a.txt', 'oldText' => 'needle', 'newText' => 'found']);

        // Three digits in the file, so every number is three wide and the gutter lines up.
        $this->assertStringContainsString('-121 needle', $result->details['diff']);
        $this->assertStringContainsString(' 120 x', $result->details['diff']);
    }

    public function testAddingAndRemovingDifferentNumbersOfLines(): void
    {
        $this->file('a.txt', "a\nb\nc\n");

        $result = $this->run($this->edit(), ['path' => 'a.txt', 'oldText' => 'b', 'newText' => "x\ny\nz"]);

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
