<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;
use Pig\Agent\AgentError;
use Pig\CodingAgent\Cli\FileArguments;
use Pig\Test\AssertsThrows;

/**
 * `@file` on the command line.
 *
 * Every case writes a real file, because the whole class is about reading them — a fake
 * filesystem here would test the fake.
 */
final class FileArgumentsTest extends TestCase
{
    use AssertsThrows;

    private string $cwd;

    #[\Override]
    protected function setUp(): void
    {
        $this->cwd = sys_get_temp_dir() . '/pig-fileargs-' . bin2hex(random_bytes(4));
        mkdir($this->cwd, 0o755, true);
    }

    #[\Override]
    protected function tearDown(): void
    {
        foreach (glob($this->cwd . '/*') ?: [] as $entry) {
            unlink($entry);
        }

        rmdir($this->cwd);
    }

    private function write(string $name, string $contents): string
    {
        file_put_contents($this->cwd . '/' . $name, $contents);

        return $this->cwd . '/' . $name;
    }

    public function testATextFileIsWrappedWithItsAbsolutePath(): void
    {
        $path = $this->write('thing.php', "<?php echo 1;\n");
        $read = FileArguments::read(['thing.php'], $this->cwd);

        // The absolute path, not what was typed: the model is being told which file this is,
        // and `thing.php` means nothing without knowing where it was said.
        $this->assertSame("<file name=\"{$path}\">\n<?php echo 1;\n\n</file>\n", $read->text);
        $this->assertSame([], $read->images);
    }

    public function testAFileThatIsNotUtf8ArrivesAsTextRatherThanAsItsBytes(): void
    {
        // Upstream reads with `readFile(path, "utf-8")`, whose decoder substitutes U+FFFD for a
        // byte it cannot read, so its `<file>` element is always text. `file_get_contents()` hands
        // over the file as it is — and a latin-1 file, or anything saved from an editor with
        // another default, then travels into the conversation as bytes nothing downstream expects.
        $this->write('notes.txt', "caf\xe9 opens at 8\n");

        $read = FileArguments::read(['notes.txt'], $this->cwd);

        $this->assertTrue(mb_check_encoding($read->text, 'UTF-8'));
        $this->assertStringContainsString('opens at 8', $read->text);
    }

    public function testFilesArriveInTheOrderTheyWereGiven(): void
    {
        $this->write('a.txt', 'first');
        $this->write('b.txt', 'second');

        $read = FileArguments::read(['b.txt', 'a.txt'], $this->cwd);
        $this->assertLessThan(strpos($read->text, 'first'), strpos($read->text, 'second'));
    }

    public function testAnImageBecomesAnAttachmentAndAnEmptyElement(): void
    {
        $png = TinyImages::png();
        $path = $this->write('shot.png', $png);

        $read = FileArguments::read(['shot.png'], $this->cwd);

        $this->assertCount(1, $read->images);
        $this->assertSame('image/png', $read->images[0]->mimeType);
        $this->assertSame(base64_encode($png), $read->images[0]->data);

        // Empty, and still sent: it is what says which file the picture came from.
        $this->assertSame("<file name=\"{$path}\"></file>\n", $read->text);
    }

    public function testAnImageThatCannotBeFittedSaysSoInItsElement(): void
    {
        \Pig\CodingAgent\Utils\ImageProcess::$codec = false;

        try {
            $path = $this->write('wide.png', "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . pack('NN', 3000, 1000) . "\x08\x06\x00\x00\x00");
            $read = FileArguments::read(['wide.png'], $this->cwd);
        } finally {
            \Pig\CodingAgent\Utils\ImageProcess::$codec = null;
        }

        $this->assertSame([], $read->images);
        $this->assertSame("<file name=\"{$path}\">[Image omitted: could not be resized below the inline image size limit.]</file>\n", $read->text);
    }

    #[\PHPUnit\Framework\Attributes\RequiresFunction('imagecreatetruecolor')]
    public function testAResizedImageCarriesItsNoteInItsElement(): void
    {
        $image = imagecreatetruecolor(3000, 1000);
        ob_start();
        imagepng($image);
        $this->write('wide.png', (string) ob_get_clean());

        $read = FileArguments::read(['wide.png'], $this->cwd);

        $this->assertCount(1, $read->images);
        $this->assertStringContainsString('original 3000x1000', $read->text);
        $this->assertSame(base64_encode((string) file_get_contents($this->cwd . '/wide.png')), FileArguments::read(['wide.png'], $this->cwd, autoResizeImages: false)->images[0]->data);
    }

    public function testTheMimeTypeIsReadFromTheBytesNotTheName(): void
    {
        $this->write('lying.txt', TinyImages::png());

        $this->assertCount(1, FileArguments::read(['lying.txt'], $this->cwd)->images);
    }

    public function testAnEmptyFileIsSkippedRatherThanSentAsAnEmptyElement(): void
    {
        $this->write('nothing.md', '');
        $this->write('something.md', 'here');

        $read = FileArguments::read(['nothing.md', 'something.md'], $this->cwd);
        $this->assertStringNotContainsString('nothing.md', $read->text);
        $this->assertStringContainsString('something.md', $read->text);
    }

    public function testNoFilesAtAllIsEmptyRatherThanAnEmptyElement(): void
    {
        $read = FileArguments::read([], $this->cwd);

        $this->assertTrue($read->isEmpty());
        $this->assertSame('', $read->text);
    }

    public function testAFileThatIsNotThereThrowsWithTheNameThatWasTyped(): void
    {
        $error = $this->assertThrows(
            AgentError::class,
            fn () => FileArguments::read(['missing.php'], $this->cwd),
        );

        // What was typed, not the resolved path: the mistake is in what they wrote, and
        // showing them a path they never said makes it harder to spot, not easier.
        $this->assertStringContainsString('missing.php', $error->getMessage());
    }

    public function testADirectoryIsNotAFile(): void
    {
        mkdir($this->cwd . '/sub');

        $this->assertThrows(AgentError::class, fn () => FileArguments::read(['sub'], $this->cwd));

        rmdir($this->cwd . '/sub');
    }

    public function testTheFilesGoInFrontOfTheMessage(): void
    {
        $this->write('a.txt', 'contents');
        $read = FileArguments::read(['a.txt'], $this->cwd);

        $whole = $read->before('why is this slow?');
        $this->assertStringEndsWith('why is this slow?', $whole);
        $this->assertLessThan(strpos($whole, 'why is this slow?'), strpos($whole, 'contents'));
    }

    public function testWithNothingSaidTheFilesAreTheWholeMessage(): void
    {
        $this->write('log.txt', 'a stack trace');
        $read = FileArguments::read(['log.txt'], $this->cwd);

        // `bin/pig -p @error.log` is a reasonable way to ask what is in a log.
        $this->assertSame($read->text, $read->before(null));
        $this->assertSame($read->text, $read->before(''));
    }
}
