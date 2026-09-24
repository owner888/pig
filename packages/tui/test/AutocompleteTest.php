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
        $this->assertNull($this->suggest($provider, 'mail kaka@example'));
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
            ['/Users/kaka/Development/owner/pig', false, 'any absolute path'],
            ['/usr/bin', false, 'two segments is enough'],
            ['hello', false, 'no slash at all'],
        ];
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
        foreach (['src/models/claude.php', 'src/Agent.php', 'README.md'] as $file) {
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
}
