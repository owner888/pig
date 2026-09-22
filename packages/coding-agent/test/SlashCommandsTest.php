<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;
use Pig\CodingAgent\Prompt\FileCommand;
use Pig\CodingAgent\Prompt\SlashCommands;

/** Prompts kept as files, and what `/name args` turns into. */
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

    /** @return list<FileCommand> */
    private function load(): array
    {
        return SlashCommands::load($this->cwd, $this->home);
    }

    // ---- where they are found ----------------------------------------------------------

    public function testBothRootsAreRead(): void
    {
        $this->command('home/.pig/commands/mine.md', 'A prompt of my own.');
        $this->command('project/.pig/commands/ours.md', 'A prompt for this project.');

        $names = array_map(static fn (FileCommand $c): string => $c->name, $this->load());

        sort($names);
        $this->assertSame(['mine', 'ours'], $names);
    }

    public function testAFolderBecomesALabelAndNotPartOfTheName(): void
    {
        $this->command('project/.pig/commands/frontend/review.md', 'Review the CSS.');

        $command = $this->load()[0];

        // `/review` is what someone types, whichever folder it is filed under.
        $this->assertSame('review', $command->name);
        $this->assertSame('(project:frontend)', $command->source);
    }

    public function testOnlyMarkdownFilesAreCommands(): void
    {
        $this->command('home/.pig/commands/notes.txt', 'not a command');
        $this->command('home/.pig/commands/real.md', 'a command');

        $this->assertCount(1, $this->load());
    }

    public function testNoCommandsAnywhereIsAnEmptyList(): void
    {
        $this->assertSame([], $this->load());
    }

    // ---- what they say they are ----------------------------------------------------------

    public function testTheDescriptionComesFromTheFrontmatterWhenThereIsOne(): void
    {
        $this->command('home/.pig/commands/review.md', "---\ndescription: Review a file closely\n---\n\nReview $1.");

        $command = $this->load()[0];

        $this->assertSame('Review a file closely (user)', $command->description);
        $this->assertSame('Review $1.', $command->content);
    }

    public function testWithNoFrontmatterTheFirstLineIsTheDescription(): void
    {
        $this->command('home/.pig/commands/tidy.md', "Tidy up the file I name.\n\nDo it carefully.");

        $this->assertSame('Tidy up the file I name. (user)', $this->load()[0]->description);
    }

    public function testALongFirstLineIsCutSoTheListStaysAList(): void
    {
        $this->command('home/.pig/commands/long.md', str_repeat('word ', 40));

        $this->assertStringEndsWith('... (user)', $this->load()[0]->description);
    }

    public function testACommandWithNothingInItIsStillOfferedUnderItsSource(): void
    {
        $this->command('home/.pig/commands/empty.md', '');

        $this->assertSame('(user)', $this->load()[0]->description);
    }

    // ---- expanding ------------------------------------------------------------------------

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
