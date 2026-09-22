<?php

declare(strict_types=1);

namespace Pig\Tui\Autocomplete;

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
     * It does not while a bare `/command` is being typed — there Tab belongs to the
     * command list, and a path completion would replace the command with a file.
     *
     * @param list<string> $lines
     */
    public function shouldCompleteFiles(array $lines, int $cursorLine, int $cursorCol): bool
    {
        $trimmed = trim(substr($lines[$cursorLine] ?? '', 0, $cursorCol));

        return !(str_starts_with($trimmed, '/') && !str_contains($trimmed, ' '));
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

    /** A `/name` at the start of the line, with no path separator inside it. */
    private static function isCommandName(string $before, string $prefix): bool
    {
        return str_starts_with($prefix, '/') && trim($before) === '' && !str_contains(substr($prefix, 1), '/');
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
                    $items[] = new AutocompleteItem($name, $name, $command->description);
                }
            }

            return self::offer($items, $before);
        }

        $name = substr($before, 1, $space - 1);
        $argument = substr($before, $space + 1);

        foreach ($this->commands as $command) {
            if (!$command instanceof SlashCommand || $command->name !== $name) {
                continue;
            }

            if ($command->argumentCompletions === null) {
                return null;
            }

            return self::offer(array_values(($command->argumentCompletions)($argument)), $argument);
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

        // An empty prefix is a path context only at the start of a line or after a space;
        // after a quote or an `=` it is more likely the start of a value.
        return $prefix === '' && ($text === '' || str_ends_with($text, ' ')) ? '' : null;
    }

    /**
     * Entries of one directory, matching whatever was typed of their name.
     *
     * @return list<AutocompleteItem>
     */
    private function files(string $prefix): array
    {
        $isAt = str_starts_with($prefix, '@');
        $path = self::expandHome($isAt ? substr($prefix, 1) : $prefix);
        [$directory, $namePrefix] = $this->split($prefix, $path);

        // A prefix that names no directory is a prefix nobody can complete: the user is
        // mid-word, not in error. Checked rather than suppressed, so a directory that
        // exists but cannot be read still raises instead of silently offering nothing.
        if (!is_dir($directory)) {
            return [];
        }

        $entries = scandir($directory);

        if ($entries === false) {
            throw new TuiError("Could not read directory {$directory}");
        }

        $items = [];
        $needle = mb_strtolower($namePrefix, 'UTF-8');

        foreach ($entries as $name) {
            if ($name === '.' || $name === '..' || !str_starts_with(mb_strtolower($name, 'UTF-8'), $needle)) {
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

    private static function expandHome(string $path): string
    {
        $home = getenv('HOME');

        if ($home === false || $home === '') {
            return $path;
        }

        if ($path === '~') {
            return $home;
        }

        return str_starts_with($path, '~/') ? $home . substr($path, 1) : $path;
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
     * @return list<array{0: string, 1: bool}> path and whether it is a directory
     */
    private function runFd(string $query): array
    {
        $command = [
            $this->fdPath,
            '--base-directory', $this->basePath,
            '--max-results', (string) self::FD_MAX_RESULTS,
            '--type', 'f',
            '--type', 'd',
        ];

        if ($query !== '') {
            $command[] = $query;
        }

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        // The array form: PHP builds the argv itself, so a filename with a space in it
        // is one argument and nothing has to be quoted.
        $process = proc_open($command, $descriptors, $pipes);

        if (!is_resource($process)) {
            return [];
        }

        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        if (proc_close($process) !== 0) {
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
