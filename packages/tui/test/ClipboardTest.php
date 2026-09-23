<?php

declare(strict_types=1);

namespace Pig\Tui\Test;

use PHPUnit\Framework\TestCase;
use Pig\Tui\Clipboard\Clipboard;
use Pig\Tui\Clipboard\ClipboardFile;
use Pig\Tui\Clipboard\ClipboardImage;
use Pig\Tui\Components\Editor;
use Pig\Tui\Process;
use Pig\Tui\TuiError;

/** A clipboard holding exactly what a test put on it. */
final class FakeClipboard implements Clipboard
{
    public int $imageReads = 0;

    /** What was last written to it, so a test can see what a copy would have put there. */
    public ?string $written = null;

    /** Set false to stand in for a machine with no way to copy. */
    public bool $writable = true;

    public function __construct(private ?string $text = null, private ?ClipboardImage $image = null)
    {
    }

    #[\Override]
    public function write(string $text): bool
    {
        if (!$this->writable) {
            return false;
        }

        $this->written = $text;

        return true;
    }

    #[\Override]
    public function text(): ?string
    {
        return $this->text;
    }

    #[\Override]
    public function image(): ?ClipboardImage
    {
        $this->imageReads++;

        return $this->image;
    }
}

final class ClipboardTest extends TestCase
{
    private string $directory;

    #[\Override]
    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/pig-clipboard-test-' . getmypid();

        if (!is_dir($this->directory)) {
            mkdir($this->directory, 0o755, true);
        }
    }

    #[\Override]
    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }

        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    private function editor(?string $text = null, ?ClipboardImage $image = null): array
    {
        $editor = new Editor();
        $clipboard = new FakeClipboard($text, $image);
        $editor->setClipboard($clipboard);

        return [$editor, $clipboard];
    }

    // ---- running other programs ----------------------------------------------------

    public function testCaptureTakesWhatTheCommandPrinted(): void
    {
        $this->assertSame("hello\n", Process::capture(['echo', 'hello']));
    }

    public function testAnArgumentWithSpacesStaysOneArgument(): void
    {
        $this->assertSame("a b c\n", Process::capture(['echo', 'a b c']));
    }

    public function testAMissingProgramIsNullRatherThanAnError(): void
    {
        // "this machine has no wl-paste" is an answer, not a failure.
        $this->assertNull(Process::capture(['pig-definitely-not-installed']));
    }

    public function testAFailedCommandIsNull(): void
    {
        $this->assertNull(Process::capture(['false']));
    }

    public function testACommandThatHangsIsKilled(): void
    {
        $started = microtime(true);

        // A paste that never returns would freeze the whole UI.
        $this->assertNull(Process::capture(['sleep', '30'], 0.3));
        $this->assertLessThan(3.0, microtime(true) - $started);
    }

    // ---- handing the terminal over ----------------------------------------------------

    public function testInteractiveNeedsACommand(): void
    {
        try {
            Process::interactive([]);
            $this->fail('expected an empty command to be refused');
        } catch (TuiError $error) {
            $this->assertStringContainsString('needs a command', $error->getMessage());
        }
    }

    /**
     * With no controlling terminal there is nothing to hand over.
     *
     * Which is also why nothing here runs a real editor: `interactive()` opens `/dev/tty`
     * for all three streams, and a test runner has no tty to open. This is the one branch
     * reachable without one — and it has to be STOPPED rather than a crash, because a
     * session started from a script is exactly where it happens.
     */
    public function testInteractiveWithNoTerminalIsStoppedRatherThanFatal(): void
    {
        // Caught rather than suppressed: `@` is forbidden in this repository and the lint
        // pass fails the build on it.
        set_error_handler(static fn (): bool => true);

        try {
            $tty = fopen('/dev/tty', 'r');
        } finally {
            restore_error_handler();
        }

        if (is_resource($tty)) {
            fclose($tty);
            $this->markTestSkipped('this run has a controlling terminal');
        }

        $polled = 0;

        $exit = Process::interactive(['true'], static function () use (&$polled): void {
            $polled++;
        });

        $this->assertSame(Process::STOPPED, $exit);

        // Never started, so never polled: a caller yielding into an event loop is not left
        // yielding forever over a process that does not exist.
        $this->assertSame(0, $polled);
    }

    // ---- giving a program its input --------------------------------------------------

    public function testFeedHandsTheTextToTheCommandsStandardInput(): void
    {
        $path = $this->directory . '/fed.txt';

        $this->assertTrue(Process::feed(['sh', '-c', 'cat > ' . escapeshellarg($path)], 'hello'));
        $this->assertSame('hello', file_get_contents($path));
    }

    public function testMoreThanFitsInThePipeIsStillWrittenWhole(): void
    {
        $path = $this->directory . '/big.txt';
        // Larger than a pipe buffer, which is where a single fwrite would stop short and
        // a copy would silently lose its tail.
        $text = str_repeat('x', 300_000);

        $this->assertTrue(Process::feed(['sh', '-c', 'cat > ' . escapeshellarg($path)], $text, 10.0));
        $this->assertSame(300_000, (int) filesize($path));
    }

    public function testFeedingAMissingProgramIsFalseRatherThanAnError(): void
    {
        $this->assertFalse(Process::feed(['pig-definitely-not-installed'], 'hello'));
    }

    public function testAProgramThatFailsIsFalse(): void
    {
        $this->assertFalse(Process::feed(['false'], 'hello'));
    }

    public function testAProgramThatNeverReadsIsKilled(): void
    {
        $started = microtime(true);

        // `sleep` never reads its input, so this is both halves of the timeout at once.
        $this->assertFalse(Process::feed(['sleep', '30'], 'hello', 0.3));
        $this->assertLessThan(3.0, microtime(true) - $started);
    }

    // ---- writing the file ----------------------------------------------------------

    public function testAnImageIsWrittenWithAnExtensionMatchingItsFormat(): void
    {
        $path = ClipboardFile::write(new ClipboardImage('PNGBYTES', 'image/png'), $this->directory);

        $this->assertNotNull($path);
        $this->assertStringEndsWith('.png', $path);
        $this->assertSame('PNGBYTES', file_get_contents($path));
    }

    public function testTwoPastesDoNotLandOnTheSameFile(): void
    {
        $first = ClipboardFile::write(new ClipboardImage('a', 'image/jpeg'), $this->directory);
        $second = ClipboardFile::write(new ClipboardImage('b', 'image/jpeg'), $this->directory);

        $this->assertNotSame($first, $second);
        $this->assertSame('a', file_get_contents((string) $first));
    }

    public function testAFormatWithNoNameIsNotWritten(): void
    {
        // Nothing downstream could tell what the file was, so there is no point.
        $this->assertNull(ClipboardFile::write(new ClipboardImage('bytes', 'image/tiff'), $this->directory));
        $this->assertNull(ClipboardFile::write(new ClipboardImage('', 'image/png'), $this->directory));
    }

    public function testEveryNamedFormatGetsAnExtension(): void
    {
        $extensions = array_map(
            static fn (string $mime): ?string => (new ClipboardImage('x', $mime))->extension(),
            ['image/png', 'image/jpeg', 'image/webp', 'image/gif', 'image/tiff'],
        );

        $this->assertSame(['png', 'jpg', 'webp', 'gif', null], $extensions);
    }

    // ---- pasting into the editor ---------------------------------------------------

    public function testCtrlVPastesAPictureAsThePathToIt(): void
    {
        [$editor] = $this->editor(image: new ClipboardImage('PNGBYTES', 'image/png'));

        $editor->handleInput("\x16");

        // A terminal cannot carry image bytes in a line of text, and the agent's tools
        // take paths, so the path is what goes into the prompt.
        $text = $editor->text();
        $this->assertStringContainsString('pig-clipboard-', $text);
        $this->assertStringEndsWith('.png', $text);
        $this->assertSame('PNGBYTES', file_get_contents($text));

        unlink($text);
    }

    public function testThePastedPathLandsAtTheCursor(): void
    {
        [$editor] = $this->editor(image: new ClipboardImage('B', 'image/png'));
        $editor->setText('look at  please');

        // Back up over " please".
        for ($index = 0; $index < 7; $index++) {
            $editor->handleInput("\x1b[D");
        }

        $editor->handleInput("\x16");

        $this->assertStringStartsWith('look at ', $editor->text());
        $this->assertStringEndsWith(' please', $editor->text());

        preg_match('#/\S+\.png#', $editor->text(), $match);
        unlink($match[0]);
    }

    public function testWithNoPictureCtrlVPastesTheText(): void
    {
        [$editor] = $this->editor(text: 'some text');

        $editor->handleInput("\x16");

        $this->assertSame('some text', $editor->text());
    }

    public function testPastedTextKeepsItsLines(): void
    {
        [$editor] = $this->editor(text: "one\r\ntwo");

        $editor->handleInput("\x16");

        // Through the paste path, so newlines become lines rather than literal escapes.
        $this->assertSame("one\ntwo", $editor->text());
    }

    public function testAPictureIsPreferredOverTheTextBesideIt(): void
    {
        [$editor] = $this->editor(text: 'ignored', image: new ClipboardImage('B', 'image/png'));

        $editor->handleInput("\x16");

        $this->assertStringNotContainsString('ignored', $editor->text());

        unlink($editor->text());
    }

    public function testAnUnnameableFormatFallsBackToTheText(): void
    {
        [$editor] = $this->editor(text: 'fallback', image: new ClipboardImage('B', 'image/tiff'));

        $editor->handleInput("\x16");

        $this->assertSame('fallback', $editor->text());
    }

    public function testAnEmptyClipboardChangesNothing(): void
    {
        [$editor] = $this->editor();
        $editor->setText('typed');

        $editor->handleInput("\x16");

        $this->assertSame('typed', $editor->text());
    }

    public function testWithoutAClipboardCtrlVIsNotEvenAsked(): void
    {
        $editor = new Editor();
        $editor->setText('typed');

        $editor->handleInput("\x16");

        // And the byte is not inserted either: it would be invisible and undeletable.
        $this->assertSame('typed', $editor->text());
    }

    public function testCtrlVDuringABracketedPasteIsJustText(): void
    {
        [$editor, $clipboard] = $this->editor(text: 'clipboard');

        $editor->handleInput("\x1b[200~a\x16b\x1b[201~");

        // Inside a paste, every byte is content; the clipboard is not consulted.
        $this->assertSame('ab', $editor->text());
        $this->assertSame(0, $clipboard->imageReads);
    }

    public function testInsertAtCursorIsUsableOnItsOwn(): void
    {
        $editor = new Editor();
        $editor->setText('ab');
        $editor->handleInput("\x01");

        $editor->insertAtCursor('X');

        $this->assertSame('Xab', $editor->text());
        $this->assertSame(1, $editor->cursor()['col']);
    }
}
