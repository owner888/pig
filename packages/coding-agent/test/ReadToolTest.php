<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\Attributes\DataProvider;
use Pig\Agent\AgentError;
use Pig\Async\AbortController;
use Pig\Async\AbortError;
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

        $this->assertSame("one\ntwo\nthree", $this->textOf($this->execute($this->read(), ['path' => 'notes.txt'])));
    }

    public function testAnAbsolutePathIsUsedAsGiven(): void
    {
        $path = $this->file('deep/inside.txt', 'found');

        $this->assertSame('found', $this->textOf($this->execute($this->read(), ['path' => $path])));
    }

    public function testAMissingFileIsAnErrorTheModelCanRead(): void
    {
        $error = $this->assertThrows(
            AgentError::class,
            fn () => $this->execute($this->read(), ['path' => 'nope.txt']),
            'No such file',
        );

        // The path as the model wrote it, not the resolved one: that is what it can fix.
        $this->assertStringContainsString('nope.txt', $error->getMessage());
    }

    public function testADirectoryIsNotAFile(): void
    {
        mkdir($this->cwd . '/adir');

        $this->assertThrows(AgentError::class, fn () => $this->execute($this->read(), ['path' => 'adir']), 'No such file');
    }

    public function testOffsetAndLimitSelectARange(): void
    {
        $this->file('numbers.txt', implode("\n", range(1, 100)));

        $output = $this->textOf($this->execute($this->read(), ['path' => 'numbers.txt', 'offset' => 10, 'limit' => 3]));

        $this->assertStringStartsWith("10\n11\n12", $output);
    }

    public function testALimitedReadSaysWhereToContinue(): void
    {
        $this->file('numbers.txt', implode("\n", range(1, 100)));

        $output = $this->textOf($this->execute($this->read(), ['path' => 'numbers.txt', 'limit' => 3]));

        // A next step, not a dead end.
        $this->assertStringContainsString('[97 more lines in file. Use offset=4 to continue]', $output);
    }

    public function testReadingToTheEndSaysNothingExtra(): void
    {
        $this->file('short.txt', "a\nb");

        $this->assertSame("a\nb", $this->textOf($this->execute($this->read(), ['path' => 'short.txt', 'limit' => 10])));
    }

    /** @return iterable<string, array{0: array<string, mixed>, 1: string}> */
    public static function badRanges(): iterable
    {
        yield 'a negative limit' => [['limit' => -1], '[4 more lines in file. Use offset=1 to continue]'];
        yield 'a negative offset' => [['offset' => -3], "a\nb\nc\nd"];
        yield 'a fractional limit' => [['limit' => 1.5], "a\n\n[3 more lines in file. Use offset=2 to continue]"];
        yield 'a fractional offset' => [['offset' => 2.7], "b\nc\nd"];
    }

    /**
     * The schema says `number`, so a model can send a fraction or a minus sign.
     *
     * Upstream carries both through: a fraction reaches its own notice as `Use offset=2.5 to
     * continue`, which is not an offset anything can be called with, and `limit: -1` becomes
     * `slice(0, -1)` — all but the last line, silently. Clamped and cast to whole here.
     *
     * @param array<string, mixed> $range
     */
    #[DataProvider('badRanges')]
    public function testAnOffsetOrLimitThatIsNotAWholeCountIsMadeOne(array $range, string $expected): void
    {
        $this->file('short.txt', "a\nb\nc\nd");

        $this->assertSame($expected, $this->textOf($this->execute($this->read(), ['path' => 'short.txt', ...$range])));
    }

    public function testAFileThatIsNotUtf8IsHandedOverAsItIs(): void
    {
        $this->file('binary.log', "header\n\x80stray\nfooter\n");

        // Upstream decodes as UTF-8, so the byte reaches the model as U+FFFD. Sanitising
        // happens once, at the request boundary, where all four providers already do it —
        // and the display path has its own guard. Reading is not the place for either.
        $this->assertSame(
            "header\n\x80stray\nfooter\n",
            $this->textOf($this->execute($this->read(), ['path' => 'binary.log'])),
        );
    }

    public function testAnOffsetPastTheEndIsAnError(): void
    {
        $this->file('short.txt', "a\nb");

        $this->assertThrows(
            AgentError::class,
            fn () => $this->execute($this->read(), ['path' => 'short.txt', 'offset' => 99]),
            'past the end',
        );
    }

    public function testTheLastLineCanBeReadAndTheOneAfterItCannot(): void
    {
        $this->file('three.txt', "a\nb\nc");

        // The pair is the test: asserting that offset 99 is refused says nothing about where
        // the end is, and one line past the end is the offset a model asks for after reading
        // the last page. Answering it with nothing reads as an empty file.
        $this->assertSame('c', $this->textOf($this->execute($this->read(), ['path' => 'three.txt', 'offset' => 3])));

        $this->assertThrows(
            AgentError::class,
            fn () => $this->execute($this->read(), ['path' => 'three.txt', 'offset' => 4]),
            'past the end',
        );
    }

    public function testALimitThatEndsExactlyAtTheLastLineSaysNothingAboutContinuing(): void
    {
        $this->file('four.txt', "a\nb\nc\nd");

        // The other end of the same rule. "0 more lines in file. Use offset=5 to continue" is an
        // instruction to read past the end, which the tool then refuses.
        $this->assertSame("a\nb\nc\nd", $this->textOf($this->execute($this->read(), ['path' => 'four.txt', 'limit' => 4])));
        $this->assertStringContainsString(
            '[1 more lines in file. Use offset=4 to continue]',
            $this->textOf($this->execute($this->read(), ['path' => 'four.txt', 'limit' => 3])),
        );
    }

    public function testAnAbortedReadStopsBeforeItOpensAnything(): void
    {
        $this->file('notes.txt', 'one');

        $controller = new AbortController();
        $controller->abort();

        $this->assertThrows(
            AbortError::class,
            fn () => $this->read()->execute('call-1', ['path' => 'notes.txt'], $controller->signal),
        );
    }

    public function testALongFileIsCutAtTheLineLimitAndSaysSo(): void
    {
        $total = Truncate::MAX_LINES + 500;
        $this->file('big.txt', implode("\n", array_fill(0, $total, 'x')));

        $output = $this->textOf($this->execute($this->read(), ['path' => 'big.txt']));

        $limit = Truncate::MAX_LINES;
        $next = $limit + 1;
        $this->assertStringContainsString("[Showing lines 1-{$limit} of {$total}. Use offset={$next} to continue]", $output);
    }

    public function testAWideFileIsCutAtTheByteLimit(): void
    {
        // Two hundred lines of 1KB is over the byte limit and under the line limit.
        $this->file('wide.txt', implode("\n", array_fill(0, 200, str_repeat('x', 1024))));

        $output = $this->textOf($this->execute($this->read(), ['path' => 'wide.txt']));

        $this->assertStringContainsString('limit). Use offset=', $output);
        $this->assertStringContainsString('of 200', $output);
    }

    public function testOneEnormousLineSendsTheCommandToGetAPieceOfIt(): void
    {
        $this->file('minified.js', str_repeat('a', Truncate::MAX_BYTES + 10));

        $output = $this->textOf($this->execute($this->read(), ['path' => 'minified.js']));

        // Nothing whole fits, so what comes back is a way forward rather than half a line.
        $this->assertStringContainsString('Use bash:', $output);
        $this->assertStringContainsString("sed -n '1p' minified.js", $output);
    }

    public function testTheEnormousLineSaysHowBigItIsAndNotJustThatItIsTooBig(): void
    {
        // Three times the budget, so the line's own size and the limit are not the same string —
        // ten bytes over rounds to the same figure and an assertion on it would hold either way.
        $length = Truncate::MAX_BYTES * 3;
        $this->file('minified.js', str_repeat('a', $length));

        $output = $this->textOf($this->execute($this->read(), ['path' => 'minified.js']));

        // Without the size, "over the 50.0KB limit" leaves the model with no idea whether
        // `head -c` gets it a tenth of the line or all but ten bytes of it.
        $this->assertStringContainsString('Line 1 is ' . Truncate::size($length) . ',', $output);
        $this->assertStringContainsString('over the ' . Truncate::size(Truncate::MAX_BYTES) . ' limit', $output);
    }

    public function testTruncationDetailsGoToTheUiAsWellAsTheModel(): void
    {
        $this->file('big.txt', implode("\n", array_fill(0, Truncate::MAX_LINES + 10, 'x')));

        $result = $this->execute($this->read(), ['path' => 'big.txt']);

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
            $this->textOf($result),
        );
    }

    public function testAFileThatFitsHasNoDetailsAtAll(): void
    {
        $this->file('small.txt', "one\ntwo\n");

        $result = $this->execute($this->read(), ['path' => 'small.txt']);

        // Upstream's shape: a read that fitted says nothing about truncation rather than
        // recording that none happened.
        $this->assertNull($result->details);
        $this->assertStringNotContainsString('[', $this->textOf($result));
    }

    public function testALimitThatStopsShortIsRecordedForTheTranscript(): void
    {
        $this->file('hundred.txt', implode("\n", array_fill(0, 100, 'x')));

        $result = $this->execute($this->read(), ['path' => 'hundred.txt', 'limit' => 5]);

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

        $result = $this->execute($this->read(), ['path' => 'shot.png']);

        $this->assertCount(2, $result->content);
        $this->assertInstanceOf(ImageContent::class, $result->content[1]);
        $this->assertSame('image/png', $result->content[1]->mimeType);
        $this->assertSame(base64_encode($png), $result->content[1]->data);
    }

    #[\PHPUnit\Framework\Attributes\RequiresFunction('imagecreatetruecolor')]
    public function testAnOversizedImageIsFittedAndTheReadSaysHow(): void
    {
        $image = imagecreatetruecolor(3000, 1000);
        ob_start();
        imagepng($image);
        $this->file('wide.png', (string) ob_get_clean());

        $result = $this->execute($this->read(), ['path' => 'wide.png']);

        $this->assertStringStartsWith("Read image file [image/", $this->textOf($result));
        $this->assertStringContainsString('original 3000x1000, displayed at 2000x667', $this->textOf($result));
        $this->assertInstanceOf(ImageContent::class, $result->content[1]);
    }

    public function testAnImageThatCannotBeFittedIsNotAttachedAndTheReadSaysWhy(): void
    {
        \Pig\CodingAgent\Utils\ImageProcess::$codec = false;

        try {
            $this->file('wide.png', "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . pack('NN', 3000, 1000) . "\x08\x06\x00\x00\x00");
            $result = $this->execute($this->read(), ['path' => 'wide.png']);
        } finally {
            \Pig\CodingAgent\Utils\ImageProcess::$codec = null;
        }

        $this->assertCount(1, $result->content);
        $this->assertSame("Read image file [image/png]\n[Image omitted: could not be resized below the inline image size limit.]", $this->textOf($result));
    }

    public function testWithAutoResizeOffAnOversizedImageIsSentAsItIs(): void
    {
        $png = "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . pack('NN', 3000, 1000) . "\x08\x06\x00\x00\x00";
        $this->file('wide.png', $png);

        $result = $this->execute(new ReadTool($this->cwd, autoResizeImages: false), ['path' => 'wide.png']);

        $this->assertSame(base64_encode($png), $result->content[1]->data);
    }

    public function testTheFormatIsDecidedByTheBytesNotTheExtension(): void
    {
        // A screenshot tool that writes JPEG into a .png would otherwise be sent as PNG,
        // and the provider rejects the whole request.
        $this->file('lying.png', TinyImages::jpeg());

        $result = $this->execute($this->read(), ['path' => 'lying.png']);

        $this->assertSame('image/jpeg', $result->content[1]->mimeType);
    }

    public function testATextFileIsNotMistakenForAnImage(): void
    {
        $this->file('notes.png', 'this is not a picture');

        $this->assertSame('this is not a picture', $this->textOf($this->execute($this->read(), ['path' => 'notes.png'])));
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
        $this->assertSame('found', $this->textOf($this->execute($this->read(), ['path' => "my\u{00A0}file.txt"])));
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
