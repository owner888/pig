<?php

declare(strict_types=1);

namespace Pig\Tui\Test;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pig\Tui\Autocomplete\AutocompleteItem;
use Pig\Tui\Autocomplete\CombinedAutocompleteProvider;
use Pig\Tui\Autocomplete\SlashCommand;

final class AutocompleteTest extends TestCase
{
    /** The space macOS puts in a path copied out of Finder, and in a screenshot's own name. */
    private const string NARROW_NO_BREAK_SPACE = "\u{202F}";

    private string $root;

    #[\Override]
    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/pig-autocomplete-' . getmypid();

        foreach (['src/models', 'srcery'] as $directory) {
            if (!is_dir("{$this->root}/{$directory}")) {
                mkdir("{$this->root}/{$directory}", 0o755, true);
            }
        }

        file_put_contents("{$this->root}/README.md", '');
        file_put_contents("{$this->root}/src/Agent.php", '');
        file_put_contents("{$this->root}/src/models/claude.php", '');
    }

    /** @param list<SlashCommand|AutocompleteItem> $commands */
    private function provider(array $commands = []): CombinedAutocompleteProvider
    {
        return new CombinedAutocompleteProvider($commands, $this->root);
    }

    /** Suggest for one line of text, cursor at its end. */
    private function suggest(CombinedAutocompleteProvider $provider, string $line): ?object
    {
        return $provider->suggestions([$line], 0, strlen($line));
    }

    /** @return list<string> */
    private function values(?object $suggestions): array
    {
        return $suggestions === null
            ? []
            : array_map(static fn (AutocompleteItem $item): string => $item->value, $suggestions->items);
    }

    public function testSlashCompletesCommandNames(): void
    {
        $provider = $this->provider([
            new SlashCommand('model', 'Choose a model'),
            new SlashCommand('mode'),
            new SlashCommand('quit'),
        ]);

        $suggestions = $this->suggest($provider, '/mod');

        $this->assertSame(['model', 'mode'], $this->values($suggestions));
        $this->assertSame('/mod', $suggestions?->prefix);
    }

    public function testCompletingACommandLeavesRoomForItsArgument(): void
    {
        $provider = $this->provider([new SlashCommand('model')]);
        $suggestions = $this->suggest($provider, '/mod');

        $completion = $provider->apply(['/mod'], 0, 4, $suggestions->items[0], $suggestions->prefix);

        // The next thing typed after a command is its argument, so the space is there.
        $this->assertSame(['/model '], $completion->lines);
        $this->assertSame(7, $completion->cursorCol);
    }

    public function testCompletingFromABareSlashKeepsTheSlash(): void
    {
        $provider = $this->provider([new SlashCommand('compact')]);

        $completion = $provider->apply(['/'], 0, 1, new AutocompleteItem('compact'), '/');

        // A bare `/` is the prefix every command completion starts from, so it has to count
        // as naming one. Requiring a non-empty name here — which is tempting, because a
        // *submitted* `/` names nothing — turned `/` plus a pick into `compact`, with the
        // slash eaten and no space after it.
        $this->assertSame(['/compact '], $completion->lines);
    }

    public function testACommandCompletesItsOwnArguments(): void
    {
        $provider = $this->provider([
            new SlashCommand('model', null, static fn (string $typed): array => array_values(array_filter(
                [new AutocompleteItem('opus'), new AutocompleteItem('sonnet'), new AutocompleteItem('haiku')],
                static fn (AutocompleteItem $item): bool => str_starts_with($item->value, $typed),
            ))),
        ]);

        $suggestions = $this->suggest($provider, '/model s');

        // Only the command knows what its argument can be, so it is asked.
        $this->assertSame(['sonnet'], $this->values($suggestions));
        $this->assertSame('s', $suggestions?->prefix);
    }

    public function testACommandWithoutArgumentCompletionsOffersNothing(): void
    {
        $provider = $this->provider([new SlashCommand('quit')]);

        $this->assertNull($this->suggest($provider, '/quit '));
        $this->assertNull($this->suggest($provider, '/unknown '));
    }

    public function testAnUnmatchedCommandPrefixOffersNothing(): void
    {
        $provider = $this->provider([new SlashCommand('model')]);

        $this->assertNull($this->suggest($provider, '/zzz'));
    }

    public function testAPathPrefixListsThatDirectory(): void
    {
        $values = $this->values($this->suggest($this->provider(), 'src/'));

        // Directories first, then alphabetically.
        $this->assertSame(['src/models/', 'src/Agent.php'], $values);
    }

    public function testAPartialNameNarrowsTheDirectory(): void
    {
        $this->assertSame(['src/Agent.php'], $this->values($this->suggest($this->provider(), 'src/Ag')));
    }

    public function testAPathThatNamesNoDirectoryOffersNothing(): void
    {
        $this->assertNull($this->suggest($this->provider(), 'nope/nothing/'));
    }

    public function testABareWordIsNotTreatedAsAPath(): void
    {
        // "hello" in a sentence should not open a file picker.
        $this->assertNull($this->suggest($this->provider(), 'please read'));
    }

    public function testTabForcesPathCompletionOnABareWord(): void
    {
        $suggestions = $this->provider()->forcedFileSuggestions(['src'], 0, 3);

        $this->assertSame(['src/', 'srcery/'], $this->values($suggestions));
    }

    public function testTabDoesNotCompletePathsWhileACommandIsBeingTyped(): void
    {
        $provider = $this->provider([new SlashCommand('model')]);

        $this->assertFalse($provider->shouldCompleteFiles(['/mod'], 0, 4));
        $this->assertNull($provider->forcedFileSuggestions(['/mod'], 0, 4));
        // Once there is an argument, Tab is about paths again.
        $this->assertTrue($provider->shouldCompleteFiles(['/model src/'], 0, 11));
    }

    public function testTabCompletesAnAbsolutePathTypedAtTheStartOfTheLine(): void
    {
        $provider = $this->provider([new SlashCommand('model')]);

        // "Starts with a slash" also describes a path, so Tab used to refuse to complete one
        // here while completing the same path fine one space to the right — a difference
        // nobody could have guessed at. The first word has a second `/` in it, so it is not a
        // name being typed.
        $this->assertTrue($provider->shouldCompleteFiles(['/usr/bi'], 0, 7));
        $this->assertTrue($provider->shouldCompleteFiles(['/var/folders/mk/T/x'], 0, 19));
    }

    public function testAPathCompletionAddsNoTrailingSpace(): void
    {
        $provider = $this->provider();
        $suggestions = $this->suggest($provider, 'src/');
        $completion = $provider->apply(['src/'], 0, 4, $suggestions->items[0], 'src/');

        // The next keystroke after a directory is usually another path segment.
        $this->assertSame(['src/models/'], $completion->lines);
        $this->assertSame(11, $completion->cursorCol);
    }

    public function testACompletionKeepsWhateverFollowsTheCursor(): void
    {
        $provider = $this->provider();
        $completion = $provider->apply(['read src/ please'], 0, 9, new AutocompleteItem('src/Agent.php'), 'src/');

        $this->assertSame(['read src/Agent.php please'], $completion->lines);
        $this->assertSame(18, $completion->cursorCol);
    }

    public function testAtWithoutFdOffersNothing(): void
    {
        // Without fd there is no .gitignore-aware walk, and walking the tree by hand
        // would search node_modules. Upstream makes the same call.
        $this->assertNull($this->suggest($this->provider(), 'see @age'));
    }

    public function testAnAtInTheMiddleOfAWordIsNotAFileReference(): void
    {
        $provider = new CombinedAutocompleteProvider([], $this->root, '/nonexistent/fd');

        // An email address should not open a file picker.
        $this->assertNull($this->suggest($provider, 'mail dev@example'));
    }

    public function testApplyingAnAtCompletionLeavesASpace(): void
    {
        $completion = $this->provider()->apply(['see @age'], 0, 8, new AutocompleteItem('@src/Agent.php'), '@age');

        $this->assertSame(['see @src/Agent.php '], $completion->lines);
        $this->assertSame(19, $completion->cursorCol);
    }

    public function testHomePathsKeepTheirTilde(): void
    {
        $home = getenv('HOME');

        if ($home === false || !is_dir($home)) {
            $this->markTestSkipped('no HOME to complete against');
        }

        $values = $this->values($this->suggest($this->provider(), '~/'));

        foreach ($values as $value) {
            // The value replaces what was typed, so it has to read as a continuation of it.
            $this->assertStringStartsWith('~/', $value);
        }
    }

    // ---- a command name, or a path -----------------------------------------------------

    /**
     * @return list<array{0: string, 1: bool, 2: string}>
     */
    public static function lines(): array
    {
        return [
            ['/compact', true, 'a name'],
            ['/mod', true, 'half of one, which is when completion matters most'],
            ['/', true, 'the start of one — the prefix every command completion begins at'],
            ['/setings', true, 'a name nothing answers to is still being typed as a name'],

            ['/var/folders/mk/T/pig-clipboard-bf66.png', false, 'what pasting a picture leaves'],
            ['/Users/dev/Development/owner/pig', false, 'any absolute path'],
            ['/usr/bin', false, 'two segments is enough'],
            ['hello', false, 'no slash at all'],
        ];
    }

    /** Tab, which is how an absolute path at the start of a line gets completed. */
    private function tabValues(CombinedAutocompleteProvider $provider, string $line): array
    {
        return $this->values($provider->forcedFileSuggestions([$line], 0, strlen($line)));
    }

    public function testAPathCopiedOutOfAFileManagerStillCompletes(): void
    {
        mkdir("{$this->root}/Screenshots", 0o755, true);
        file_put_contents("{$this->root}/Screenshots/one.png", '');

        // What Finder puts on the clipboard has U+202F where the name on disk has a space, and
        // U+202F is not whitespace to `\S`, so the whole pasted path arrives as one word. The
        // path looks identical on screen, `is_dir()` says no, and the picker answered with
        // nothing at all — the same silence `Tools\Paths::expand()` was written to end, from the
        // one caller that could not reach it.
        $directory = str_replace('Screenshots', 'Screen' . self::NARROW_NO_BREAK_SPACE . 'Shots', "{$this->root}/Screenshots/");
        rename("{$this->root}/Screenshots", "{$this->root}/Screen Shots");

        $this->assertSame(
            ["{$directory}one.png"],
            $this->tabValues($this->provider(), $directory),
        );

        unlink("{$this->root}/Screen Shots/one.png");
        rmdir("{$this->root}/Screen Shots");
    }

    public function testAPastedNameMatchesTheFileWhoseNameHasTheOrdinarySpace(): void
    {
        // The directory resolving is not enough on its own: the last segment is a name prefix
        // matched against what `scandir()` returned, so a pasted `Shot-4.31.05<U+202F>PM` had to
        // match the file actually called `Shot-4.31.05 PM.png`. Both sides are normalised for the
        // comparison, which is also what keeps a real U+202F in a name from stopping matching
        // itself.
        file_put_contents("{$this->root}/Shot-4.31.05 PM.png", '');

        $typed = "{$this->root}/Shot-4.31.05" . self::NARROW_NO_BREAK_SPACE . 'PM';

        $this->assertSame(
            ["{$this->root}/Shot-4.31.05 PM.png"],
            $this->tabValues($this->provider(), $typed),
        );

        unlink("{$this->root}/Shot-4.31.05 PM.png");
    }

    public function testAFileWhoseRealNameHoldsThatSpaceStillMatchesItself(): void
    {
        // The other side of the comparison, and the reason both sides are normalised: a macOS
        // screenshot really is called `Screenshot … 4.31.05<U+202F>PM.png` on disk. Normalising
        // only what was typed turns the needle into an ordinary space and the file then matches
        // nothing — including the exact name it was copied from.
        $name = 'Shot-4.31.05' . self::NARROW_NO_BREAK_SPACE . 'PM.png';
        file_put_contents("{$this->root}/{$name}", '');

        $typed = "{$this->root}/Shot-4.31.05" . self::NARROW_NO_BREAK_SPACE . 'PM';

        $this->assertSame(["{$this->root}/{$name}"], $this->tabValues($this->provider(), $typed));

        unlink("{$this->root}/{$name}");
    }

    public function testADirectoryWhoseRealNameHoldsThatSpaceIsStillListed(): void
    {
        // And the directory half of it: normalising is what makes a pasted path resolve, so the
        // spelling that was typed has to stay the second answer or a folder somebody named with
        // a no-break space stops completing.
        $real = "{$this->root}/Q1" . self::NARROW_NO_BREAK_SPACE . '2026';
        mkdir($real, 0o755, true);
        file_put_contents($real . '/plan.md', '');

        $this->assertSame([$real . '/plan.md'], $this->tabValues($this->provider(), $real . '/'));

        unlink($real . '/plan.md');
        rmdir($real);
    }

    /**
     * Tab means "complete a command" or "complete a path", and a second `/` in the first word
     * is what decides. Driven through `apply()` rather than the predicate, which is private:
     * a command name gets its slash and a trailing space, a path gets neither.
     */
    #[DataProvider('lines')]
    public function testACommandNameIsToldFromAPathWhileTyping(string $line, bool $isName, string $why): void
    {
        $provider = $this->provider([new SlashCommand('compact')]);

        $completion = $provider->apply([$line], 0, strlen($line), new AutocompleteItem('compact'), $line);

        $this->assertSame($isName ? '/compact ' : 'compact', $completion->lines[0], $why);
    }

    #[\Override]
    protected function tearDown(): void
    {
        foreach (['src/models/claude.php', 'src/Agent.php', 'README.md', 'noisy-fd', 'mock-fd'] as $file) {
            if (is_file("{$this->root}/{$file}")) {
                unlink("{$this->root}/{$file}");
            }
        }

        foreach (['src/models', 'srcery', 'src', ''] as $directory) {
            $path = rtrim("{$this->root}/{$directory}", '/');

            if (is_dir($path)) {
                rmdir($path);
            }
        }
    }

    // ---- running fd ------------------------------------------------------------------

    /**
     * A stand-in for `fd` that floods standard error before it answers.
     *
     * Real `fd` does this: one warning per path it cannot read metadata for, which on a tree
     * with many restricted directories runs past a pipe buffer long before it finishes.
     */
    private function noisyFd(int $warnings): string
    {
        $path = "{$this->root}/noisy-fd";

        file_put_contents($path, <<<SH
            #!/bin/sh
            i=0
            while [ \$i -lt {$warnings} ]; do
              echo "[fd::warning] Could not retrieve metadata for a/deep/path/\$i: Permission denied" >&2
              i=\$((i+1))
            done
            echo "src/"
            echo "src/Agent.php"
            SH);
        chmod($path, 0o755);

        return $path;
    }

    public function testFdFloodingStandardErrorDoesNotHangThePicker(): void
    {
        // Reading only stdout deadlocks: fd blocks writing to a full stderr pipe, so it never
        // closes stdout, so the read never returns — inside the loop's own input callback,
        // which is the whole terminal. `Process::run()` drains both and says why in a comment.
        $provider = new CombinedAutocompleteProvider([], $this->root, $this->noisyFd(4000));

        $suggestions = $this->suggest($provider, '@Age');

        $this->assertNotNull($suggestions);
        $this->assertSame('@src/Agent.php', $suggestions->items[0]->value);
    }

    public function testAQuietFdStillAnswers(): void
    {
        $provider = new CombinedAutocompleteProvider([], $this->root, $this->noisyFd(0));

        $suggestions = $this->suggest($provider, '@src');

        $this->assertNotNull($suggestions);
        // The directory scores higher than the file inside it, so it comes first.
        $this->assertSame('@src/', $suggestions->items[0]->value);
    }

    public function testModifiedGitFilesArePrioritizedToTheTop(): void
    {
        $fd = "{$this->root}/mock-fd";
        file_put_contents($fd, <<<'SH'
#!/bin/sh
echo "src/Normal.php"
echo "src/Modified.php"
echo "src/Another.php"
SH
        );
        chmod($fd, 0o755);

        // Without provider: normal alphabetical/score order
        $providerWithout = new CombinedAutocompleteProvider([], $this->root, $fd);
        $resWithout = $this->suggest($providerWithout, '@');
        $this->assertSame('@src/Normal.php', $resWithout->items[0]->value);

        // With provider returning modified file: modified file is prioritized first with badge
        $providerWith = new CombinedAutocompleteProvider(
            [],
            $this->root,
            $fd,
            static fn (): array => ['src/Modified.php']
        );
        $resWith = $this->suggest($providerWith, '@');
        $this->assertNotNull($resWith);
        $this->assertSame('@src/Modified.php', $resWith->items[0]->value);
        $this->assertStringContainsString('modified', $resWith->items[0]->description);
    }
}
