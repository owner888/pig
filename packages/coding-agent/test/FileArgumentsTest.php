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

    public function testFilesArriveInTheOrderTheyWereGiven(): void
    {
        $this->write('a.txt', 'first');
        $this->write('b.txt', 'second');

        $read = FileArguments::read(['b.txt', 'a.txt'], $this->cwd);
        $this->assertLessThan(strpos($read->text, 'first'), strpos($read->text, 'second'));
    }

    public function testAnImageBecomesAnAttachmentAndAnEmptyElement(): void
    {
        $png = "\x89PNG\r\n\x1a\n" . str_repeat("\x00", 16);
        $path = $this->write('shot.png', $png);

        $read = FileArguments::read(['shot.png'], $this->cwd);

        $this->assertCount(1, $read->images);
        $this->assertSame('image/png', $read->images[0]->mimeType);
        $this->assertSame(base64_encode($png), $read->images[0]->data);

        // Empty, and still sent: it is what says which file the picture came from.
        $this->assertSame("<file name=\"{$path}\"></file>\n", $read->text);
    }

    public function testTheMimeTypeIsReadFromTheBytesNotTheName(): void
    {
        $this->write('lying.txt', "\x89PNG\r\n\x1a\n" . str_repeat("\x00", 16));

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
