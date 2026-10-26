<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;
use Pig\CodingAgent\Prompt\FileCommand;
use Pig\CodingAgent\Prompt\SlashCommands;
use Pig\CodingAgent\Packages\PackageManager;
use Pig\CodingAgent\Settings;

/** Prompt templates, and what `/name args` turns into. */
final class SlashCommandsTest extends TestCase
{
    private string $root;

    private string $home;

    private string $cwd;

    #[\Override]
    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/pig-commands-' . bin2hex(random_bytes(4));
        $this->home = $this->root . '/home/.pig';
        $this->cwd = $this->root . '/project';
        mkdir($this->cwd, 0o755, true);
    }

    #[\Override]
    protected function tearDown(): void
    {
        self::remove($this->root);
    }

    private static function remove(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    self::remove($path . '/' . $entry);
                }
            }

            rmdir($path);

            return;
        }

        if (file_exists($path)) {
            unlink($path);
        }
    }

    private function command(string $relative, string $content): void
    {
        $path = $this->root . '/' . $relative;

        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0o755, true);
        }

        file_put_contents($path, $content);
    }

    /**
     * What a session loads: `PackageManager::resolveLocalResources()`, then `fromResources()`.
     *
     * @param array<string, mixed> $user the person's settings
     * @return list<FileCommand>
     */
    private function load(array $user = [], bool $trusted = true): array
    {
        return $this->loadWithWarnings($user, $trusted)[0];
    }

    /**
     * @param array<string, mixed> $user
     * @return array{0: list<FileCommand>, 1: list<string>}
     */
    private function loadWithWarnings(array $user = [], bool $trusted = true): array
    {
        if ($user !== []) {
            $this->command('home/.pig/settings.json', (string) json_encode($user));
        }

        $settings = Settings::load($this->cwd, $this->home, $trusted);

        return SlashCommands::fromResources((new PackageManager($this->cwd, $settings, $this->home))->resolveLocalResources()->prompts);
    }

    /** @param list<FileCommand> $commands @return list<string> */
    private static function names(array $commands): array
    {
        $names = array_map(static fn (FileCommand $c): string => $c->name, $commands);
        sort($names);

        return $names;
    }

    // ---- where they are found ----------------------------------------------------------

    public function testBothPromptsDirectoriesAreRead(): void
    {
        $this->command('home/.pig/prompts/mine.md', 'A prompt of my own.');
        $this->command('project/.pig/prompts/ours.md', 'A prompt for this project.');
        // The old name is not read.
        $this->command('home/.pig/commands/old.md', 'Not any more.');

        $this->assertSame(['mine', 'ours'], self::names($this->load()));
    }

    public function testAnUntrustedProjectsPromptsAreLeftOut(): void
    {
        $this->command('home/.pig/prompts/mine.md', 'A prompt of my own.');
        $this->command('project/.pig/prompts/ours.md', 'A prompt for this project.');

        $this->assertSame(['mine'], self::names($this->load(trusted: false)));
    }

    public function testAConventionalDirectoryLoadsItsDirectChildrenOnly(): void
    {
        $this->command('home/.pig/prompts/top.md', 'Loaded.');
        $this->command('home/.pig/prompts/frontend/review.md', 'Not loaded: a subdirectory.');

        $this->assertSame(['top'], self::names($this->load()));
    }

    public function testADirectoryInThePromptsSettingIsWalked(): void
    {
        $this->command('home/.pig/library/top.md', 'Loaded.');
        $this->command('home/.pig/library/frontend/review.md', 'Loaded too: a setting may select nested files.');
        $this->command('home/.pig/prompts/drop.md', 'Turned off by a pattern.');

        $this->assertSame(['review', 'top'], self::names($this->load(['prompts' => ['library', '!drop.md']])));
    }

    public function testTheFirstOfANameWinsAndTheProjectsComesFirst(): void
    {
        $this->command('home/.pig/prompts/review.md', 'The person\'s.');
        $this->command('project/.pig/prompts/review.md', 'The project\'s.');

        [$commands, $warnings] = $this->loadWithWarnings();

        $this->assertCount(1, $commands);
        $this->assertSame("The project's.", $commands[0]->content);
        $this->assertSame('(project)', $commands[0]->source);
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('name "/review" collision', $warnings[0]);
    }

    public function testACommandLineDirectoryLoadsItsDirectChildren(): void
    {
        $this->command('typed/one.md', 'One.');
        $this->command('typed/deeper/two.md', 'Not loaded.');

        [$commands] = SlashCommands::fromPaths([$this->root . '/typed', $this->root . '/nowhere']);

        $this->assertSame(['one'], self::names($commands));
        $this->assertSame('(path)', $commands[0]->source);
    }

    public function testOnlyMarkdownFilesAreCommands(): void
    {
        $this->command('home/.pig/prompts/notes.txt', 'not a command');
        $this->command('home/.pig/prompts/real.md', 'a command');

        $this->assertCount(1, $this->load());
    }

    public function testNoCommandsAnywhereIsAnEmptyList(): void
    {
        $this->assertSame([], $this->load());
    }

    // ---- what they say they are ----------------------------------------------------------

    public function testTheDescriptionComesFromTheFrontmatterWhenThereIsOne(): void
    {
        $this->command('home/.pig/prompts/review.md', "---\ndescription: Review a file closely\n---\n\nReview $1.");

        $command = $this->load()[0];

        $this->assertSame('Review a file closely (user)', $command->description);
        $this->assertSame('Review $1.', $command->content);
    }

    public function testWithNoFrontmatterTheFirstLineIsTheDescription(): void
    {
        $this->command('home/.pig/prompts/tidy.md', "Tidy up the file I name.\n\nDo it carefully.");

        $this->assertSame('Tidy up the file I name. (user)', $this->load()[0]->description);
    }

    public function testALongFirstLineIsCutSoTheListStaysAList(): void
    {
        $this->command('home/.pig/prompts/long.md', str_repeat('word ', 40));

        $this->assertStringEndsWith('... (user)', $this->load()[0]->description);
    }

    public function testACommandWithNothingInItIsStillOfferedUnderItsSource(): void
    {
        $this->command('home/.pig/prompts/empty.md', '');

        $this->assertSame('(user)', $this->load()[0]->description);
    }

    public function testATemplateWhoseFrontmatterIsNotYamlIsLeftOutWithWhy(): void
    {
        $this->command('home/.pig/prompts/broken.md', "---\ndescription: review: the diff\n---\nx");
        $this->command('home/.pig/prompts/fine.md', "---\ndescription: review the diff\n---\nx");

        [$commands, $warnings] = $this->loadWithWarnings();

        $this->assertSame(['fine'], self::names($commands));
        $this->assertSame([$this->root . '/home/.pig/prompts/broken.md: Nested mappings are not allowed in compact mappings at line 2'], $warnings);
    }

    public function testArgumentHintIsReadWhenItIsAString(): void
    {
        $this->command('home/.pig/prompts/quoted.md', "---\ndescription: d\nargument-hint: \"[focus]\"\n---\nx");
        $this->command('home/.pig/prompts/plain.md', "---\nargument-hint: <file>\n---\nx");
        // Unquoted, `[focus]` is a YAML list, and upstream keeps only a string.
        $this->command('home/.pig/prompts/list.md', "---\nargument-hint: [focus]\n---\nx");

        $byName = [];

        foreach ($this->load() as $command) {
            $byName[$command->name] = $command->argumentHint;
        }

        $this->assertSame(['list' => null, 'plain' => '<file>', 'quoted' => '[focus]'], $byName);
    }

    // ---- expanding ------------------------------------------------------------------------

    /** @return iterable<string, array{0: string, 1: string, 2: string}> template, typed, expected */
    public static function substitutions(): iterable
    {
        yield 'positional' => ['$1 and $2', 'a b', 'a and b'];
        yield '$ARGUMENTS' => ['all: $ARGUMENTS', 'a b c', 'all: a b c'];
        yield 'default used when missing' => ['focus on ${1:-correctness}', '', 'focus on correctness'];
        yield 'default not used when given' => ['focus on ${1:-correctness}', 'speed', 'focus on speed'];
        yield 'default for all' => ['${@:-nothing}', '', 'nothing'];
        yield 'default for ARGUMENTS' => ['${ARGUMENTS:-nothing}', 'x y', 'x y'];
        yield 'slice from N' => ['${@:2}', 'a b c d', 'b c d'];
        yield 'slice N L' => ['${@:2:2}', 'a b c d', 'b c'];
        yield 'slice from 0 is from 1' => ['${@:0}', 'a b', 'a b'];
        yield '$0 is nothing' => ['[$0]', 'a', '[]'];
        yield 'a default is not substituted again' => ['${2:-$1}', 'a', '$1'];
        yield 'newline separates arguments' => ['$2', "a\nb", 'b'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('substitutions')]
    public function testUpstreamsSubstitutions(string $template, string $typed, string $expected): void
    {
        $commands = [new FileCommand('t', 'd', $template, '(user)')];

        $this->assertSame($expected, SlashCommands::expand($typed === '' ? '/t' : "/t {$typed}", $commands));
    }

    public function testACommandExpandsToItsContent(): void
    {
        $commands = [new FileCommand('review', 'd', 'Review the code.', '(user)')];

        $this->assertSame('Review the code.', SlashCommands::expand('/review', $commands));
    }

    public function testPositionalArgumentsAreFilledIn(): void
    {
        $commands = [new FileCommand('review', 'd', 'Review $1 for $2.', '(user)')];

        $this->assertSame(
            'Review src/Foo.php for bugs.',
            SlashCommands::expand('/review src/Foo.php bugs', $commands),
        );
    }

    public function testAllArgumentsTogetherAreAvailableAsOne(): void
    {
        $commands = [new FileCommand('ask', 'd', 'Answer this: $@', '(user)')];

        $this->assertSame('Answer this: why is the sky blue', SlashCommands::expand('/ask why is the sky blue', $commands));
    }

    public function testAPlaceholderWithNothingBehindItBecomesNothing(): void
    {
        $commands = [new FileCommand('review', 'd', 'Review $1 for $2.', '(user)')];

        // A prompt that asks the model about `$2` is worse than one that asks about
        // nothing.
        $this->assertSame('Review a.php for .', SlashCommands::expand('/review a.php', $commands));
    }

    public function testAQuotedArgumentKeepsItsSpaces(): void
    {
        $this->assertSame(['the parser', '--strict'], SlashCommands::arguments('"the parser" --strict'));
        $this->assertSame(['the parser'], SlashCommands::arguments("'the parser'"));
        $this->assertSame(['a', 'b', 'c'], SlashCommands::arguments("a  b\tc"));
        $this->assertSame([], SlashCommands::arguments('   '));
    }

    public function testAnEmptyQuotedArgumentKeepsItsPlace(): void
    {
        // #10867: `""` and `''` are arguments, so the ones after them keep their positions.
        $this->assertSame(['', 'b'], SlashCommands::arguments('"" b'));
        $this->assertSame(['a', '', 'c'], SlashCommands::arguments("a '' c"));
        $this->assertSame([''], SlashCommands::arguments('""'));
    }

    public function testAnArgumentThatLooksLikeAPlaceholderIsNotSubstitutedAgain(): void
    {
        $commands = [new FileCommand('echo', 'd', 'Say: $@ and $1', '(user)')];

        // `$@` is filled first, so an argument containing `$1` stays what it was.
        $this->assertSame('Say: $1 and $1', SlashCommands::expand('/echo \'$1\'', $commands));
    }

    public function testSomethingThatIsNotACommandIsNull(): void
    {
        $commands = [new FileCommand('review', 'd', 'x', '(user)')];

        $this->assertNull(SlashCommands::expand('/nonsense', $commands));
        $this->assertNull(SlashCommands::expand('just talking', $commands));
    }
}
