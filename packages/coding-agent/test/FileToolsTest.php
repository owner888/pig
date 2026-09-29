<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use Pig\Agent\AgentError;
use Pig\Async\AbortController;
use Pig\Async\AbortError;
use Pig\CodingAgent\Tools\LsTool;
use Pig\CodingAgent\Tools\Truncate;
use Pig\CodingAgent\Tools\WriteTool;
use Pig\Test\AssertsThrows;

final class FileToolsTest extends ToolTestCase
{
    use AssertsThrows;

    // ---- write ---------------------------------------------------------------------

    public function testWriteCreatesAFile(): void
    {
        $result = $this->execute(new WriteTool($this->cwd), ['path' => 'out.txt', 'content' => 'hello']);

        $this->assertSame('hello', file_get_contents($this->cwd . '/out.txt'));
        $this->assertStringContainsString('Wrote 5 bytes to out.txt', $this->textOf($result));
    }

    public function testWriteMakesTheDirectoriesItNeeds(): void
    {
        $this->execute(new WriteTool($this->cwd), ['path' => 'a/b/c/deep.txt', 'content' => 'x']);

        $this->assertSame('x', file_get_contents($this->cwd . '/a/b/c/deep.txt'));
    }

    public function testWriteReplacesWhatWasThere(): void
    {
        $this->file('out.txt', 'old content that is longer');

        $this->execute(new WriteTool($this->cwd), ['path' => 'out.txt', 'content' => 'new']);

        // Overwrites outright, which is what the model is told; changing part of a file
        // is the edit tool's job.
        $this->assertSame('new', file_get_contents($this->cwd . '/out.txt'));
    }

    public function testTheByteCountIsBytesAndNotCharacters(): void
    {
        $result = $this->execute(new WriteTool($this->cwd), ['path' => 'out.txt', 'content' => '你好世界']);

        // Upstream reports `content.length` — UTF-16 code units — in a sentence that says
        // bytes, so this file is 12 bytes and the model is told 4. The number here is what
        // was written.
        $this->assertSame(12, strlen((string) file_get_contents($this->cwd . '/out.txt')));
        $this->assertStringContainsString('Wrote 12 bytes to out.txt', $this->textOf($result));
    }

    public function testWritingOverADirectoryIsRefused(): void
    {
        mkdir($this->cwd . '/adir');

        $this->assertThrows(
            AgentError::class,
            fn () => $this->execute(new WriteTool($this->cwd), ['path' => 'adir', 'content' => 'x']),
            'is a directory',
        );
    }

    public function testAWriteThatEscapeStoppedLeavesNothingBehind(): void
    {
        $controller = new AbortController();
        $controller->abort();

        // `write` is one of the four tools whose mistakes are not undoable, so the check is at
        // the top: escape between the model asking for a write and the write happening has to
        // mean the file is not there afterwards, not that it is there and nobody wanted it.
        $this->assertThrows(
            AbortError::class,
            fn () => (new WriteTool($this->cwd))->execute('call-1', ['path' => 'out.txt', 'content' => 'x'], $controller->signal),
        );

        $this->assertFileDoesNotExist($this->cwd . '/out.txt');
    }

    public function testAWriteThatDidNotLandIsNotReportedAsHavingLanded(): void
    {
        // A symlink into a directory that is not there: the link's own directory exists, so
        // nothing refuses first, and `file_put_contents` fails on the target.
        symlink($this->cwd . '/nowhere/target.txt', $this->cwd . '/link.txt');

        // The failing write warns as well as answering false, and a warning fails a test under
        // this project's phpunit.xml — caught here so it cannot fail the assertion below.
        set_error_handler(static fn (): bool => true);

        try {
            $problem = $this->assertThrows(
                AgentError::class,
                fn () => $this->execute(new WriteTool($this->cwd), ['path' => 'link.txt', 'content' => 'x']),
            );
        } finally {
            restore_error_handler();
        }

        // Without the check the model is told `Wrote  bytes` — false cast to a string — about a
        // file that does not exist, and carries on editing it.
        $this->assertStringContainsString('Could not write link.txt', $problem->getMessage());
    }

    // ---- ls ------------------------------------------------------------------------

    public function testLsListsEntriesWithDirectoriesMarked(): void
    {
        $this->file('b.txt');
        $this->file('sub/nested.txt');
        $this->file('.hidden');

        $output = $this->textOf($this->execute(new LsTool($this->cwd), []));

        // Dotfiles included: a model looking for config needs to see them.
        $this->assertSame([".hidden", "b.txt", "sub/"], explode("\n", $output));
    }

    public function testLsSortsTheWayAPersonReads(): void
    {
        foreach (['Zebra', 'apple', 'Banana'] as $name) {
            $this->file($name);
        }

        $this->assertSame(
            ['apple', 'Banana', 'Zebra'],
            explode("\n", $this->textOf($this->execute(new LsTool($this->cwd), []))),
        );
    }

    public function testLsOfASubdirectory(): void
    {
        $this->file('sub/one.txt');

        $this->assertSame('one.txt', $this->textOf($this->execute(new LsTool($this->cwd), ['path' => 'sub'])));
    }

    public function testAnEmptyDirectorySaysSo(): void
    {
        mkdir($this->cwd . '/empty');

        $this->assertSame('(empty directory)', $this->textOf($this->execute(new LsTool($this->cwd), ['path' => 'empty'])));
    }

    public function testLsOfAFileOrAMissingPathIsAnError(): void
    {
        $this->file('a.txt');

        $this->assertThrows(AgentError::class, fn () => $this->execute(new LsTool($this->cwd), ['path' => 'a.txt']), 'Not a directory');
        $this->assertThrows(AgentError::class, fn () => $this->execute(new LsTool($this->cwd), ['path' => 'nope']), 'No such directory');
    }

    public function testLsSaysWhenItStoppedEarly(): void
    {
        for ($index = 0; $index < 10; $index++) {
            $this->file("file{$index}.txt");
        }

        $result = $this->execute(new LsTool($this->cwd), ['limit' => 3]);
        $output = $this->textOf($result);

        $this->assertStringContainsString('[3 entry limit reached. Use limit=6 for more]', $output);
        $this->assertCount(3, explode("\n", explode("\n\n", $output)[0]));

        // And again as data, for the transcript: the notice is the last line of the output and
        // a collapsed tool view keeps the first twenty.
        $this->assertSame('3 entry limit reached. Use limit=6 for more', $result->details['notice']);

        // Under upstream's key, because `details` goes into pi's session file and pi's own tool
        // view reads this one to draw its warning.
        $this->assertSame(3, $result->details['entryLimitReached']);
    }

    public function testTruncationLimitsAreWhatTheToolsAdvertise(): void
    {
        $this->assertSame(2000, Truncate::MAX_LINES);
        $this->assertSame('50.0KB', Truncate::size(Truncate::MAX_BYTES));
    }
}
