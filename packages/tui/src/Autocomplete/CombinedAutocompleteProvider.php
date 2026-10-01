<?php

declare(strict_types=1);

namespace Pig\Tui\Autocomplete;

use Closure;

use Pig\Tui\Env;
use Pig\Tui\Paths;
use Pig\Tui\Process;
use Pig\Tui\TuiError;

/**
 * The three completions a coding agent's prompt needs.
 *
 * `/name` for commands, `@path` for attaching a file anywhere in the project, and a bare
 * path for the directory the agent is working in. Which one is meant is decided by what
 * sits immediately before the cursor, so all three can live on the same line.
 */
final class CombinedAutocompleteProvider implements AutocompleteProvider
{
    /** Most a fuzzy search looks at, and most it offers. */
    private const int FD_MAX_RESULTS = 100;
    private const int FUZZY_MAX_ITEMS = 20;

    /** Longest the picker will freeze waiting for `fd`; see runFd(). */
    private const float FD_TIMEOUT = 2.0;

    /** @var list<SlashCommand|AutocompleteItem> */
    private array $commands;

    /**
     * @param list<SlashCommand|AutocompleteItem> $commands
     * @param string                              $basePath the directory bare paths are relative to
     * @param string|null                         $fdPath   path to `fd`, for the `@` search
     */
    public function __construct(
        array $commands = [],
        private readonly string $basePath = '.',
        private readonly ?string $fdPath = null,
    ) {
        $this->commands = array_values($commands);
    }

    #[\Override]
    public function suggestions(array $lines, int $cursorLine, int $cursorCol): ?Suggestions
    {
        $before = substr($lines[$cursorLine] ?? '', 0, $cursorCol);

        // `@` only starts a reference at the beginning of a word, so an email address in
        // the middle of a sentence does not open a file picker.
        if (preg_match('/(?:^|\s)(@\S*)$/', $before, $match) === 1) {
            return self::offer($this->fuzzyFiles(substr($match[1], 1)), $match[1]);
        }

        if (str_starts_with($before, '/')) {
            return $this->slashSuggestions($before);
        }

        $prefix = $this->pathPrefix($before, force: false);

        return $prefix === null ? null : self::offer($this->files($prefix), $prefix);
    }

    /**
     * What Tab offers when nothing else was triggered: paths, whatever was typed.
     *
     * @param list<string> $lines
     */
    public function forcedFileSuggestions(array $lines, int $cursorLine, int $cursorCol): ?Suggestions
    {
        $before = substr($lines[$cursorLine] ?? '', 0, $cursorCol);

        if (!$this->shouldCompleteFiles($lines, $cursorLine, $cursorCol)) {
            return null;
        }

        $prefix = $this->pathPrefix($before, force: true);

        return $prefix === null ? null : self::offer($this->files($prefix), $prefix);
    }

    /**
     * Whether Tab here means "complete a path".
     *
     * It does not while a command's **name** is being typed — there Tab belongs to the command
     * list, and a path completion would replace the command with a file. Two things have to be
     * true for that: the cursor is still in the first word, and that word could still become a
     * name. Past the first space Tab is a path again, which is what `/export ~/out.html` and
     * `/compact src/Parser.php` need.
     *
     * The second half was missing, and it was the same mistake as everywhere else this rule
     * got spelled by hand: "starts with a slash" also describes `/var/folders/…/x.png`, so Tab
     * refused to complete an absolute path typed at the start of a line. With a space in front
     * of it — `read /var/fol` — it worked, which is a difference nobody could have guessed at.
     *
     * @param list<string> $lines
     */
    public function shouldCompleteFiles(array $lines, int $cursorLine, int $cursorCol): bool
    {
        $before = substr($lines[$cursorLine] ?? '', 0, $cursorCol);

        // A command that knows what its own argument can be answers for it, so Tab there means
        // "complete that" and not "complete a path".
        if ($this->argumentCompleter($before) !== null) {
            return false;
        }

        $trimmed = trim($before);

        return str_contains($trimmed, ' ') || !self::couldBeACommandName($trimmed);
    }

    #[\Override]
    public function apply(
        array $lines,
        int $cursorLine,
        int $cursorCol,
        AutocompleteItem $item,
        string $prefix,
    ): Completion {
        $line = $lines[$cursorLine] ?? '';
        $before = substr($line, 0, max(0, $cursorCol - strlen($prefix)));
        $after = substr($line, $cursorCol);

        // A command name and an attached file both want a space after them, because the
        // next thing typed is an argument. A path does not: the next thing is often `/`.
        [$inserted, $trailing] = match (true) {
            self::isCommandName($before, $prefix) => ['/' . $item->value, ' '],
            str_starts_with($prefix, '@') => [$item->value, ' '],
            default => [$item->value, ''],
        };

        $lines[$cursorLine] = $before . $inserted . $trailing . $after;

        return new Completion(
            array_values($lines),
            $cursorLine,
            strlen($before . $inserted . $trailing),
        );
    }

    /**
     * Whether what has been typed so far could still become a command name.
     *
     * `/` at the start and **no second `/` in the first word**, which is the one thing that
     * tells `/compact` from `/var/folders/…/pig-clipboard-x.png`. It is a question about
     * *typing*, not about dispatch: a bare `/` counts, because that is the prefix every
     * command completion starts from, and so does a half-typed `/mod`.
     *
     * Which is why `InteractiveMode` does not use it and must not. Deciding that a submitted
     * line *is* a command is an exact match against the names that exist — this rule would
     * say yes to `/setings`, and a rule that says yes to a name nothing answers to is the
     * guess that read a pasted path as a command.
     */
    private static function couldBeACommandName(string $text): bool
    {
        $trimmed = ltrim($text);

        if (!str_starts_with($trimmed, '/')) {
            return false;
        }

        // A bare `/` counts: it is the start of a name, which is what the list is for. The
        // requirement is only that the first word have no `/` of its own.
        $name = strtok(substr($trimmed, 1), " \t\n") ?: '';

        return !str_contains($name, '/');
    }

    /** A `/name` at the start of the line, with no path separator inside it. */
    private static function isCommandName(string $before, string $prefix): bool
    {
        return trim($before) === '' && self::couldBeACommandName($prefix);
    }

    private function slashSuggestions(string $before): ?Suggestions
    {
        $space = strpos($before, ' ');

        if ($space === false) {
            $typed = mb_strtolower(substr($before, 1), 'UTF-8');
            $items = [];

            foreach ($this->commands as $command) {
                $name = $command instanceof SlashCommand ? $command->name : $command->value;

                if (str_starts_with(mb_strtolower($name, 'UTF-8'), $typed)) {
                    // The label is the item's own where it has one: a `SlashCommand` is named
                    // and nothing else, but an `AutocompleteItem` carries a value to insert and
                    // a label to show, and they need not be the same string. Nothing passes one
                    // of those as a command today — `InteractiveMode` builds `SlashCommand`s —
                    // so this arm is upstream's shape rather than a behaviour anybody sees.
                    $items[] = new AutocompleteItem(
                        $name,
                        $command instanceof SlashCommand ? $command->name : $command->label,
                        $command->description,
                    );
                }
            }

            return self::offer($items, $before);
        }

        $completer = $this->argumentCompleter($before);

        if ($completer === null) {
            return null;
        }

        $argument = substr($before, (int) $space + 1);

        return self::offer(array_values($completer($argument)), $argument);
    }

    /**
     * The closure that completes this line's argument, or null when nothing does.
     *
     * Asked by two readers — the automatic list and `shouldCompleteFiles()`, which decides what Tab
     * means — and it has to be one answer. `/model n` offering **file names** was what asking two
     * questions cost: the list knew the command had no completions and said nothing, and Tab knew
     * only that there was a space and went to the filesystem.
     *
     * @return Closure(string): list<AutocompleteItem>|null
     */
    private function argumentCompleter(string $before): ?Closure
    {
        $space = strpos($before, ' ');

        if (!str_starts_with($before, '/') || $space === false) {
            return null;
        }

        $name = substr($before, 1, $space - 1);

        foreach ($this->commands as $command) {
            if ($command instanceof SlashCommand && $command->name === $name) {
                return $command->argumentCompletions;
            }
        }

        return null;
    }

    /**
     * The path-looking text before the cursor, or null when there is none.
     *
     * The word is taken from the last delimiter rather than matched as a whole, because a
     * pattern for "a path" ends up with nested quantifiers and a line of quoted flags is
     * enough to make it hang.
     */
    private function pathPrefix(string $text, bool $force): ?string
    {
        if (preg_match('/@\S*$/', $text, $match) === 1) {
            return $match[0];
        }

        $delimiter = -1;

        foreach ([' ', "\t", '"', "'", '='] as $character) {
            $delimiter = max($delimiter, strrpos($text, $character) === false ? -1 : strrpos($text, $character));
        }

        $prefix = $delimiter === -1 ? $text : substr($text, $delimiter + 1);

        if ($force) {
            return $prefix;
        }

        if (str_contains($prefix, '/') || str_starts_with($prefix, '.') || str_starts_with($prefix, '~/')) {
            return $prefix;
        }

        // An empty prefix is a path context after a space — and **not on an empty line**, which
        // upstream's comment spells out ("Empty text should not trigger file suggestions — that's
        // for forced Tab completion") and this port had dropped: typing `/` and deleting it left
        // the line empty, `refreshOrReopenSuggestions()` asked, and the whole directory listing
        // came up under a prompt with nothing in it. Tab on an empty line still lists files, through
        // `forcedFileSuggestions()`, because that is what Tab there asks for.
        return $prefix === '' && $text !== '' && str_ends_with($text, ' ') ? '' : null;
    }

    /**
     * Entries of one directory, matching whatever was typed of their name.
     *
     * @return list<AutocompleteItem>
     */
    private function files(string $prefix): array
    {
        $isAt = str_starts_with($prefix, '@');
        $typed = $isAt ? substr($prefix, 1) : $prefix;
        // `Paths::expand()` rather than a copy of it: it normalises the spaces as well, which is
        // what makes a path pasted out of Finder complete at all.
        $path = Paths::expand($typed);
        [$directory, $namePrefix] = $this->split($prefix, $path);

        // Normalising is what makes a pasted path resolve and is also what breaks a name that
        // really holds one of those spaces — a directory somebody called `Q1 2026` with a no-break
        // space in it. So the spelling that was typed is the second answer, exactly as
        // `CodingAgent\Tools\Paths::resolveForRead()` retries for a macOS screenshot.
        if (!is_dir($directory)) {
            [$asTyped] = $this->split($prefix, self::expandHome($typed));

            if (!is_dir($asTyped)) {
                // A prefix that names no directory is a prefix nobody can complete: the user is
                // mid-word, not in error. Checked rather than suppressed, so a directory that
                // exists but cannot be read still raises instead of silently offering nothing.
                return [];
            }

            $directory = $asTyped;
        }

        $entries = scandir($directory);

        if ($entries === false) {
            throw new TuiError("Could not read directory {$directory}");
        }

        $items = [];
        // Both sides normalised, so a pasted `Shot-4.31.05<U+202F>PM` matches the file actually
        // called `Shot-4.31.05 PM.png` — and a name that really holds one of these spaces still
        // matches itself. Normalising only the needle answers the first and loses the second.
        //
        // The reverse — an ordinary space typed against a name holding U+202F — cannot be reached
        // from here whatever this does, because a space is where `pathPrefix()` ends the word. So
        // a path with a space in it is not completable by either spelling, which is upstream's
        // behaviour too and is the one thing here that is a limit rather than a rule.
        $needle = Paths::normaliseSpaces(mb_strtolower($namePrefix, 'UTF-8'));

        foreach ($entries as $name) {
            $comparable = Paths::normaliseSpaces(mb_strtolower($name, 'UTF-8'));

            if ($name === '.' || $name === '..' || !str_starts_with($comparable, $needle)) {
                continue;
            }

            // is_dir() follows symlinks, so a link to a directory completes with a slash
            // like the directory it points at, and a broken one completes as a file.
            $isDirectory = is_dir($directory . DIRECTORY_SEPARATOR . $name);
            $value = self::rebuild($prefix, $path, $name, $isAt);

            $items[] = new AutocompleteItem(
                $isDirectory ? $value . '/' : $value,
                $isDirectory ? $name . '/' : $name,
            );
        }

        usort($items, static function (AutocompleteItem $a, AutocompleteItem $b): int {
            // Directories first: the usual next keystroke after choosing one is `/`.
            $aIsDirectory = str_ends_with($a->value, '/');
            $bIsDirectory = str_ends_with($b->value, '/');

            // `strcmp` and not a locale comparison, which is where upstream's
            // `localeCompare` differs: in en-US that orders `bin` before `CLAUDE.md`, where
            // this puts every capital first. Deliberate — PHP's equivalent is `strcoll`,
            // whose answer depends on the locale the terminal happens to be in, so the same
            // directory would list in a different order on two machines and a test could
            // only pin one of them. A file list that is always in the same order is worth
            // more here than one that is alphabetical the way a dictionary is.
            return $aIsDirectory === $bIsDirectory ? strcmp($a->label, $b->label) : ($aIsDirectory ? -1 : 1);
        });

        return $items;
    }

    /**
     * Which directory to read, and how much of a name has been typed.
     *
     * @return array{0: string, 1: string}
     */
    private function split(string $prefix, string $path): array
    {
        $rooted = str_starts_with($prefix, '~') || str_starts_with($path, '/');
        $here = ['', './', '../', '/', '~', '~/'];

        if (in_array($path, $here, true) || $prefix === '@') {
            return [$rooted ? $path : self::join($this->basePath, $path), ''];
        }

        if (str_ends_with($path, '/')) {
            return [$rooted ? $path : self::join($this->basePath, $path), ''];
        }

        $directory = dirname($path);

        return [$rooted ? $directory : self::join($this->basePath, $directory), basename($path)];
    }

    /**
     * Put $name back into the shape the user was typing.
     *
     * The value has to read as a continuation of the prefix — a `~/` path stays `~/`, an
     * `@` reference keeps its `@` — because it replaces that prefix in the line verbatim.
     */
    private static function rebuild(string $prefix, string $path, string $name, bool $isAt): string
    {
        $marker = $isAt ? '@' : '';
        $typed = $isAt ? substr($prefix, 1) : $prefix;

        if ($typed === '' || $typed === '~') {
            return $marker . ($typed === '~' ? '~/' : '') . $name;
        }

        if (str_ends_with($typed, '/')) {
            return $marker . $typed . $name;
        }

        if (!str_contains($typed, '/')) {
            return $marker . $name;
        }

        $directory = dirname($typed);

        return $marker . ($directory === '/' ? '/' : $directory . '/') . $name;
    }

    /**
     * A `~` expanded and nothing else touched.
     *
     * The second answer for a directory whose real name holds one of the spaces `Paths::expand()`
     * normalises away — see `files()`. Everything else goes through `Paths::expand()`.
     */
    private static function expandHome(string $path): string
    {
        $home = Env::home();

        if ($home === null) {
            return $path;
        }

        return match (true) {
            $path === '~' => $home,
            str_starts_with($path, '~/') => $home . substr($path, 1),
            default => $path,
        };
    }

    private static function join(string $base, string $path): string
    {
        if ($path === '' || $path === '.') {
            return $base;
        }

        return rtrim($base, '/') . '/' . ltrim($path, '/');
    }

    /**
     * Anything in the project, ranked by how well the name matches.
     *
     * `fd` does the walking: it respects .gitignore, which is the difference between
     * searching a repository and searching its node_modules. Without it `@` offers
     * nothing rather than walking the tree itself and taking a minute over it.
     *
     * @return list<AutocompleteItem>
     */
    private function fuzzyFiles(string $query): array
    {
        if ($this->fdPath === null) {
            return [];
        }

        $found = $this->runFd($query);
        $scored = [];

        foreach ($found as [$path, $isDirectory]) {
            $score = $query === '' ? 1 : self::score($path, $query, $isDirectory);

            if ($score > 0) {
                $scored[] = [$score, $path, $isDirectory];
            }
        }

        usort($scored, static fn (array $a, array $b): int => $b[0] <=> $a[0]);

        $items = [];

        foreach (array_slice($scored, 0, self::FUZZY_MAX_ITEMS) as [, $path, $isDirectory]) {
            $bare = $isDirectory ? rtrim($path, '/') : $path;

            $items[] = new AutocompleteItem(
                '@' . $path,
                basename($bare) . ($isDirectory ? '/' : ''),
                $bare,
            );
        }

        return $items;
    }

    /**
     * Run `fd` and read back what it found.
     *
     * **Through `Process::run()`, which is the whole of the fix that put it there.** This used
     * to open the process itself and read standard output only, closing standard error unread —
     * and `fd` writes a warning per path whose metadata it cannot read, so on a tree with many
     * restricted directories it fills the stderr pipe, blocks writing to it, never closes
     * standard output, and the read never returns. There was no timeout either, and this runs
     * from a keystroke inside the loop's own input callback: pressing `@` froze the whole
     * terminal for good. `Process::run()` drains both pipes in a loop and its own comment names
     * this failure, which is the sibling this should have been using from the start.
     *
     * A non-zero exit is no suggestions, as upstream has it — `fd` exits 0 when it found
     * nothing, so a non-zero one means the command itself failed, and a picker has nowhere to
     * report that to. So does the timeout, and it is short on purpose: this re-runs on every
     * keystroke of the query, so a picker that can freeze the terminal for longer than a
     * moment is worse than one that comes up empty on a very large cold tree.
     *
     * @return list<array{0: string, 1: bool}> path and whether it is a directory
     */
    private function runFd(string $query): array
    {
        $command = [
            (string) $this->fdPath,
            '--base-directory', $this->basePath,
            '--max-results', (string) self::FD_MAX_RESULTS,
            '--type', 'f',
            '--type', 'd',
        ];

        if ($query !== '') {
            $command[] = $query;
        }

        [$exit, $output] = Process::run($command, self::FD_TIMEOUT);

        if ($exit !== 0) {
            return [];
        }

        $results = [];

        foreach (explode("\n", trim($output)) as $line) {
            if ($line !== '') {
                // fd marks directories with a trailing slash.
                $results[] = [$line, str_ends_with($line, '/')];
            }
        }

        return $results;
    }

    /** Exact name beats a name that starts with it beats a name that contains it. */
    private static function score(string $path, string $query, bool $isDirectory): int
    {
        $name = mb_strtolower(basename(rtrim($path, '/')), 'UTF-8');
        $needle = mb_strtolower($query, 'UTF-8');

        $score = match (true) {
            $name === $needle => 100,
            str_starts_with($name, $needle) => 80,
            str_contains($name, $needle) => 50,
            str_contains(mb_strtolower($path, 'UTF-8'), $needle) => 30,
            default => 0,
        };

        return $score > 0 && $isDirectory ? $score + 10 : $score;
    }

    /** @param list<AutocompleteItem> $items */
    private static function offer(array $items, string $prefix): ?Suggestions
    {
        return $items === [] ? null : new Suggestions($items, $prefix);
    }
}
