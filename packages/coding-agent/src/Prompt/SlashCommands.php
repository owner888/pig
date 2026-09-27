<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Prompt;

use Pig\CodingAgent\Config;

/**
 * Prompts kept as files, reachable as `/name`.
 *
 * A markdown file in `~/.pig/commands/` or `.pig/commands/` becomes a slash command whose
 * body is the prompt. `/review src/Foo.php` sends `review.md` with `$1` filled in — which
 * is the whole feature: the thing people retype every day, typed once.
 *
 * Not the same as a skill, though both are markdown with frontmatter. A skill is
 * *offered* to the model, which reads it when a task matches; a command is *sent* by the
 * person, now, as the message. One is a capability, the other is a macro.
 *
 * Ported from upstream's `core/slash-commands.ts`.
 */
final class SlashCommands
{
    /** A description taken from the first line is cut here, so the list stays a list. */
    private const int DESCRIPTION_LENGTH = 60;

    /**
     * Every command this machine offers.
     *
     * @return list<FileCommand>
     */
    public static function load(string $cwd, ?string $home = null): array
    {
        $home ??= Config::home();

        return [
            ...self::scan($home . '/commands', 'user'),
            ...self::scan(rtrim($cwd, '/') . '/.pig/commands', 'project'),
        ];
    }

    /**
     * What `/name args` should be sent as, or null when no command has that name.
     *
     * @param list<FileCommand> $commands
     */
    public static function expand(string $text, array $commands): ?string
    {
        if (!str_starts_with($text, '/')) {
            return null;
        }

        $space = strpos($text, ' ');
        $name = $space === false ? substr($text, 1) : substr($text, 1, $space - 1);
        $rest = $space === false ? '' : substr($text, $space + 1);

        foreach ($commands as $command) {
            if ($command->name === $name) {
                return self::substitute($command->content, self::arguments($rest));
            }
        }

        return null;
    }

    /**
     * What was typed after the name, split the way a shell would.
     *
     * Quoted so an argument can hold a space: `/review "the parser" --strict` is two
     * things and a flag, not four words.
     *
     * A walk over **bytes** where upstream walks code units, and here that is the same answer
     * rather than the usual trap: the four characters it looks for — the two quotes, space and
     * tab — are all below 0x80, so none of them can occur inside a UTF-8 sequence, and
     * concatenating the bytes in between puts the characters back exactly. `/review "解析器"`
     * comes out as one argument either way. (Where the same shape *is* a trap, the number is
     * an index or a length rather than a scan — see `Utils\Fuzzy` and the editor's cursor.)
     *
     * @return list<string>
     */
    public static function arguments(string $text): array
    {
        $arguments = [];
        $current = '';
        $quote = null;

        foreach (str_split($text) as $character) {
            if ($quote !== null) {
                if ($character === $quote) {
                    $quote = null;
                } else {
                    $current .= $character;
                }

                continue;
            }

            if ($character === '"' || $character === "'") {
                $quote = $character;

                continue;
            }

            if ($character === ' ' || $character === "\t") {
                if ($current !== '') {
                    $arguments[] = $current;
                    $current = '';
                }

                continue;
            }

            $current .= $character;
        }

        if ($current !== '') {
            $arguments[] = $current;
        }

        return $arguments;
    }

    /**
     * Fill `$1`, `$2` and `$@` in.
     *
     * A placeholder with no argument behind it becomes nothing rather than staying as
     * `$2`, because a prompt that asks the model about `$2` is worse than one that asks
     * about nothing.
     *
     * @param list<string> $arguments
     */
    public static function substitute(string $content, array $arguments): string
    {
        // `$@` first: it is the only one whose replacement can itself contain a `$1`,
        // and substituting it last would fill in arguments that came from an argument.
        //
        // `str_replace`, so the arguments go in as themselves. Upstream uses
        // `String.replace()` with a string replacement, where `$&` and `$1` in the
        // *replacement* are special — so an argument that is literally `$&` inserts the match
        // there. Same reason `EditTool` splices rather than replaces.
        $content = str_replace('$@', implode(' ', $arguments), $content);

        return (string) preg_replace_callback(
            '/\$(\d+)/',
            static fn (array $match): string => $arguments[(int) $match[1] - 1] ?? '',
            $content,
        );
    }

    // ---- looking -------------------------------------------------------------------------

    /**
     * Every `.md` under $directory, and under its folders.
     *
     * `scandir()` sorts, which upstream's `readdirSync` does not, and the reason is
     * `CustomToolLoader::discover()`'s: two commands of one name is decided by load order, and
     * readdir order is not an order. Here the first one found wins in `expand()`, so without this
     * which of two files answers `/review` could differ between two machines holding the same
     * folders.
     *
     * @return list<FileCommand>
     */
    private static function scan(string $directory, string $source, string $under = ''): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        $entries = scandir($directory);

        if ($entries === false) {
            return [];
        }

        $commands = [];

        foreach ($entries as $entry) {
            // Dotted names are skipped, which upstream does not do here: it would recurse into
            // a `.git` in a commands folder and offer every `.md` in the history of it as a
            // command. The same call `HookLoader::discover()`'s note argues for.
            if (str_starts_with($entry, '.')) {
                continue;
            }

            $path = $directory . '/' . $entry;

            if (is_dir($path)) {
                // A folder becomes part of the label rather than part of the name:
                // `/review` is what someone types whichever folder it is filed under.
                $commands = [...$commands, ...self::scan($path, $source, $under === '' ? $entry : "{$under}:{$entry}")];

                continue;
            }

            if (!str_ends_with($entry, '.md')) {
                continue;
            }

            $command = self::read($path, substr($entry, 0, -3), $source, $under);

            if ($command !== null) {
                $commands[] = $command;
            }
        }

        return $commands;
    }

    private static function read(string $path, string $name, string $source, string $under): ?FileCommand
    {
        $raw = is_readable($path) ? file_get_contents($path) : false;

        if ($raw === false) {
            return null;
        }

        [$frontmatter, $content] = self::frontmatter($raw);
        $label = '(' . ($under === '' ? $source : "{$source}:{$under}") . ')';
        $description = trim($frontmatter['description'] ?? '');

        if ($description === '') {
            $description = self::firstLine($content);
        }

        return new FileCommand(
            $name,
            $description === '' ? $label : "{$description} {$label}",
            $content,
            $label,
        );
    }

    /**
     * The first line with something on it, as a description, cut to fit a list.
     *
     * Trimmed once rather than in one of the two branches. Upstream trims in neither and gets an
     * indented description; this trimmed the short one and not the cut one, which is only invisible
     * because `frontmatter()` trims the whole body, so the first line with anything on it cannot
     * start with a space. A rule that holds because of a `trim()` in another method is a rule that
     * breaks when that method changes.
     */
    private static function firstLine(string $content): string
    {
        foreach (explode("\n", $content) as $line) {
            $line = trim($line);

            if ($line !== '') {
                return mb_strlen($line) > self::DESCRIPTION_LENGTH
                    ? mb_substr($line, 0, self::DESCRIPTION_LENGTH) . '...'
                    : $line;
            }
        }

        return '';
    }

    /**
     * The `---` block at the top, if there is one.
     *
     * The same hand-rolled reader as `Skills`: key-and-scalar lines, because that is all
     * either format uses and pig has no YAML parser to reach for.
     *
     * **The key pattern is `Skills`', not this file's upstream.** Upstream has two readers and two
     * answers — `skills.ts` matches `\w[\w-]*` and `slash-commands.ts` only `\w+` — and a
     * hyphen in a frontmatter key is ordinary (`allowed-tools`, `argument-hint`). Nothing here
     * reads such a key yet, so today the two spellings behave the same; taking the wider one in
     * both places is what stops that from being luck the day one of them does.
     *
     * CRLF is normalised first, which upstream does not do. `indexOf("\n---")` survives a CRLF
     * file by accident — `\r\n---` contains `\n---` — and every value then carries a trailing
     * `\r` that only `.trim()` removes. Doing it once at the top is the same fact in one place.
     *
     * @return array{0: array<string, string>, 1: string} fields, then the body
     */
    private static function frontmatter(string $raw): array
    {
        $raw = str_replace(["\r\n", "\r"], "\n", $raw);

        if (!str_starts_with($raw, '---')) {
            return [[], trim($raw)];
        }

        $end = strpos($raw, "\n---", 3);

        if ($end === false) {
            return [[], trim($raw)];
        }

        $fields = [];

        foreach (explode("\n", substr($raw, 4, $end - 4)) as $line) {
            if (preg_match('/^(\w[\w-]*):\s*(.*)$/', $line, $match) === 1) {
                $fields[$match[1]] = trim($match[2]);
            }
        }

        return [$fields, trim(substr($raw, $end + 4))];
    }
}
