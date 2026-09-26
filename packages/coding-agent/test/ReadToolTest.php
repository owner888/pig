<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\Attributes\DataProvider;
use Pig\Agent\AgentError;
use Pig\Ai\ImageContent;
use Pig\CodingAgent\Tools\Paths;
use Pig\CodingAgent\Tools\ReadTool;
use Pig\CodingAgent\Tools\Truncate;
use Pig\Test\AssertsThrows;

final class ReadToolTest extends ToolTestCase
{
    use AssertsThrows;

    private function read(): ReadTool
    {
        return new ReadTool($this->cwd);
    }

    public function testReadsAFile(): void
    {
        $this->file('notes.txt', "one\ntwo\nthree");

        $this->assertSame("one\ntwo\nthree", $this->output($this->run($this->read(), ['path' => 'notes.txt'])));
    }

    public function testAnAbsolutePathIsUsedAsGiven(): void
    {
        $path = $this->file('deep/inside.txt', 'found');

        $this->assertSame('found', $this->output($this->run($this->read(), ['path' => $path])));
    }

    public function testAMissingFileIsAnErrorTheModelCanRead(): void
    {
        $error = $this->assertThrows(
            AgentError::class,
            fn () => $this->run($this->read(), ['path' => 'nope.txt']),
            'No such file',
        );

        // The path as the model wrote it, not the resolved one: that is what it can fix.
        $this->assertStringContainsString('nope.txt', $error->getMessage());
    }

    public function testADirectoryIsNotAFile(): void
    {
        mkdir($this->cwd . '/adir');

        $this->assertThrows(AgentError::class, fn () => $this->run($this->read(), ['path' => 'adir']), 'No such file');
    }

    public function testOffsetAndLimitSelectARange(): void
    {
        $this->file('numbers.txt', implode("\n", range(1, 100)));

        $output = $this->output($this->run($this->read(), ['path' => 'numbers.txt', 'offset' => 10, 'limit' => 3]));

        $this->assertStringStartsWith("10\n11\n12", $output);
    }

    public function testALimitedReadSaysWhereToContinue(): void
    {
        $this->file('numbers.txt', implode("\n", range(1, 100)));

        $output = $this->output($this->run($this->read(), ['path' => 'numbers.txt', 'limit' => 3]));

        // A next step, not a dead end.
        $this->assertStringContainsString('[97 more lines in file. Use offset=4 to continue]', $output);
    }

    public function testReadingToTheEndSaysNothingExtra(): void
    {
        $this->file('short.txt', "a\nb");

        $this->assertSame("a\nb", $this->output($this->run($this->read(), ['path' => 'short.txt', 'limit' => 10])));
    }

    public function testAnOffsetPastTheEndIsAnError(): void
    {
        $this->file('short.txt', "a\nb");

        $this->assertThrows(
            AgentError::class,
            fn () => $this->run($this->read(), ['path' => 'short.txt', 'offset' => 99]),
            'past the end',
        );
    }

    public function testALongFileIsCutAtTheLineLimitAndSaysSo(): void
    {
        $total = Truncate::MAX_LINES + 500;
        $this->file('big.txt', implode("\n", array_fill(0, $total, 'x')));

        $output = $this->output($this->run($this->read(), ['path' => 'big.txt']));

        $limit = Truncate::MAX_LINES;
        $next = $limit + 1;
        $this->assertStringContainsString("[Showing lines 1-{$limit} of {$total}. Use offset={$next} to continue]", $output);
    }

    public function testAWideFileIsCutAtTheByteLimit(): void
    {
        // Two hundred lines of 1KB is over the byte limit and under the line limit.
        $this->file('wide.txt', implode("\n", array_fill(0, 200, str_repeat('x', 1024))));

        $output = $this->output($this->run($this->read(), ['path' => 'wide.txt']));

        $this->assertStringContainsString('limit). Use offset=', $output);
        $this->assertStringContainsString('of 200', $output);
    }

    public function testOneEnormousLineSendsTheCommandToGetAPieceOfIt(): void
    {
        $this->file('minified.js', str_repeat('a', Truncate::MAX_BYTES + 10));

        $output = $this->output($this->run($this->read(), ['path' => 'minified.js']));

        // Nothing whole fits, so what comes back is a way forward rather than half a line.
        $this->assertStringContainsString('Use bash:', $output);
        $this->assertStringContainsString("sed -n '1p' minified.js", $output);
    }

    public function testTruncationDetailsGoToTheUiAsWellAsTheModel(): void
    {
        $this->file('big.txt', implode("\n", array_fill(0, Truncate::MAX_LINES + 10, 'x')));

        $result = $this->run($this->read(), ['path' => 'big.txt']);

        $this->assertTrue($result->details['truncation']->truncated);
        $this->assertSame('lines', $result->details['truncation']->truncatedBy);

        // The same sentence the output ends with, handed over as data. The transcript's
        // collapsed view keeps the front of the output, so the copy in the text is the one
        // the person never sees.
        $this->assertSame(
            'Showing lines 1-2000 of 2010. Use offset=2001 to continue',
            $result->details['notice'],
        );
        $this->assertStringEndsWith(
            "[{$result->details['notice']}]",
            $this->output($result),
        );
    }

    public function testAFileThatFitsHasNoDetailsAtAll(): void
    {
        $this->file('small.txt', "one\ntwo\n");

        $result = $this->run($this->read(), ['path' => 'small.txt']);

        // Upstream's shape: a read that fitted says nothing about truncation rather than
        // recording that none happened.
        $this->assertNull($result->details);
        $this->assertStringNotContainsString('[', $this->output($result));
    }

    public function testALimitThatStopsShortIsRecordedForTheTranscript(): void
    {
        $this->file('hundred.txt', implode("\n", array_fill(0, 100, 'x')));

        $result = $this->run($this->read(), ['path' => 'hundred.txt', 'limit' => 5]);

        // Nothing was *truncated* — the model asked for five lines and got five — so there is
        // no truncation record, which is upstream's shape. The notice is pig's: the collapsed
        // tool view keeps the front of the output and this line sits at the end of it.
        $this->assertArrayNotHasKey('truncation', $result->details);
        $this->assertSame('95 more lines in file. Use offset=6 to continue', $result->details['notice']);
    }

    public function testAnImageComesBackAsSomethingTheModelCanLookAt(): void
    {
        $png = "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . pack('NN', 4, 3) . "\x08\x06\x00\x00\x00";
        $this->file('shot.png', $png);

        $result = $this->run($this->read(), ['path' => 'shot.png']);

        $this->assertCount(2, $result->content);
        $this->assertInstanceOf(ImageContent::class, $result->content[1]);
        $this->assertSame('image/png', $result->content[1]->mimeType);
        $this->assertSame(base64_encode($png), $result->content[1]->data);
    }

    public function testTheFormatIsDecidedByTheBytesNotTheExtension(): void
    {
        // A screenshot tool that writes JPEG into a .png would otherwise be sent as PNG,
        // and the provider rejects the whole request.
        $this->file('lying.png', "\xff\xd8\xff\xe0" . str_repeat("\x00", 20));

        $result = $this->run($this->read(), ['path' => 'lying.png']);

        $this->assertSame('image/jpeg', $result->content[1]->mimeType);
    }

    public function testATextFileIsNotMistakenForAnImage(): void
    {
        $this->file('notes.png', 'this is not a picture');

        $this->assertSame('this is not a picture', $this->output($this->run($this->read(), ['path' => 'notes.png'])));
    }

    // ---- paths ---------------------------------------------------------------------

    public function testATildePathExpandsToTheHomeDirectory(): void
    {
        $home = getenv('HOME');

        if ($home === false || $home === '') {
            $this->markTestSkipped('no HOME set');
        }

        $this->assertSame($home, Paths::expand('~'));
        $this->assertSame($home . '/x', Paths::expand('~/x'));
    }

    public function testACopiedPathWithUnicodeSpacesStillResolves(): void
    {
        $this->file('my file.txt', 'found');

        // A path pasted out of a browser or a chat window carries U+00A0, which looks
        // exactly like a space and matches nothing.
        $this->assertSame('found', $this->output($this->run($this->read(), ['path' => "my\u{00A0}file.txt"])));
    }

    public function testAMacScreenshotNameIsTriedWithItsOwnNarrowSpace(): void
    {
        // macOS names screenshots with U+202F before AM/PM. Normalising spaces — which
        // has to happen, because most mismatches go the other way — breaks this one, so
        // the original spelling is tried before giving up.
        $name = "Screenshot 2026-09-22 at 4.31.05\u{202F}PM.png";
        $this->file($name, 'shot');

        $resolved = Paths::resolveForRead('Screenshot 2026-09-22 at 4.31.05 PM.png', $this->cwd);

        $this->assertSame($this->cwd . '/' . $name, $resolved);
    }

    public function testRelativeShowsAPathTheWayAPersonWouldWriteIt(): void
    {
        $this->assertSame('src/Main.php', Paths::relative('/work/src/Main.php', '/work'));
        $this->assertSame('/elsewhere/x', Paths::relative('/elsewhere/x', '/work'));
    }

    /**
     * The answers are Node's `path.resolve`, which is what upstream uses, read off it.
     *
     * @return array<string, array{string, string}>
     */
    public static function paths(): array
    {
        return [
            'a step up' => ['../README.md', '/home/dev/README.md'],
            'a dot' => ['./x', '/home/dev/pkg/x'],
            'a doubled slash' => ['a//b', '/home/dev/pkg/a/b'],
            'up and down again' => ['t/../a/s', '/home/dev/pkg/a/s'],
            'already absolute' => ['/etc/hosts', '/etc/hosts'],
            'a bare name' => ['plain.txt', '/home/dev/pkg/plain.txt'],
            'nothing at all' => ['', '/home/dev/pkg'],
            'just a dot' => ['.', '/home/dev/pkg'],
            'just up' => ['..', '/home/dev'],
            'up twice' => ['../..', '/home'],
            'past the root' => ['../../..', '/'],
            'up twice mid-path' => ['a/b/../../c', '/home/dev/pkg/c'],
            'absolute with a step up' => ['/a/b/../c', '/a/c'],
            'absolute past the root' => ['/../..', '/'],
            'a trailing slash' => ['trailing/', '/home/dev/pkg/trailing'],
            'absolute trailing slash' => ['/abs/trailing/', '/abs/trailing'],
            'several dots' => ['./././x', '/home/dev/pkg/x'],
            'dots and a trailing slash' => ['a/./b/../c/', '/home/dev/pkg/a/c'],
        ];
    }

    #[DataProvider('paths')]
    public function testAResolvedPathIsTheOneAPersonWouldRecogniseIt(string $path, string $expected): void
    {
        // Not just "does it open the same file" — the OS collapses `..` either way. The
        // resolved path is what `grep` and `find` prefix every output line with, so the model
        // reads it and writes it back into its next call.
        $this->assertSame($expected, Paths::resolve($path, '/home/dev/pkg'));
    }
}
