<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use Pig\Agent\AgentError;
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
        $result = $this->run(new WriteTool($this->cwd), ['path' => 'out.txt', 'content' => 'hello']);

        $this->assertSame('hello', file_get_contents($this->cwd . '/out.txt'));
        $this->assertStringContainsString('Wrote 5 bytes to out.txt', $this->output($result));
    }

    public function testWriteMakesTheDirectoriesItNeeds(): void
    {
        $this->run(new WriteTool($this->cwd), ['path' => 'a/b/c/deep.txt', 'content' => 'x']);

        $this->assertSame('x', file_get_contents($this->cwd . '/a/b/c/deep.txt'));
    }

    public function testWriteReplacesWhatWasThere(): void
    {
        $this->file('out.txt', 'old content that is longer');

        $this->run(new WriteTool($this->cwd), ['path' => 'out.txt', 'content' => 'new']);

        // Overwrites outright, which is what the model is told; changing part of a file
        // is the edit tool's job.
        $this->assertSame('new', file_get_contents($this->cwd . '/out.txt'));
    }

    public function testWritingOverADirectoryIsRefused(): void
    {
        mkdir($this->cwd . '/adir');

        $this->assertThrows(
            AgentError::class,
            fn () => $this->run(new WriteTool($this->cwd), ['path' => 'adir', 'content' => 'x']),
            'is a directory',
        );
    }

    // ---- ls ------------------------------------------------------------------------

    public function testLsListsEntriesWithDirectoriesMarked(): void
    {
        $this->file('b.txt');
        $this->file('sub/nested.txt');
        $this->file('.hidden');

        $output = $this->output($this->run(new LsTool($this->cwd), []));

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
            explode("\n", $this->output($this->run(new LsTool($this->cwd), []))),
        );
    }

    public function testLsOfASubdirectory(): void
    {
        $this->file('sub/one.txt');

        $this->assertSame('one.txt', $this->output($this->run(new LsTool($this->cwd), ['path' => 'sub'])));
    }

    public function testAnEmptyDirectorySaysSo(): void
    {
        mkdir($this->cwd . '/empty');

        $this->assertSame('(empty directory)', $this->output($this->run(new LsTool($this->cwd), ['path' => 'empty'])));
    }

    public function testLsOfAFileOrAMissingPathIsAnError(): void
    {
        $this->file('a.txt');

        $this->assertThrows(AgentError::class, fn () => $this->run(new LsTool($this->cwd), ['path' => 'a.txt']), 'Not a directory');
        $this->assertThrows(AgentError::class, fn () => $this->run(new LsTool($this->cwd), ['path' => 'nope']), 'No such directory');
    }

    public function testLsSaysWhenItStoppedEarly(): void
    {
        for ($index = 0; $index < 10; $index++) {
            $this->file("file{$index}.txt");
        }

        $result = $this->run(new LsTool($this->cwd), ['limit' => 3]);
        $output = $this->output($result);

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
