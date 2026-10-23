<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Prompt;

use Pig\CodingAgent\Packages\ResolvedResource;
use Pig\CodingAgent\Tools\Paths;

/**
 * Prompt templates: markdown files reachable as `/name` — upstream's `core/prompt-templates.ts`.
 *
 * A `.md` in `~/.pig/agent/prompts/` or `.pig/prompts/` becomes a slash command whose body is the
 * prompt. `/review src/Foo.php` sends `review.md` with `$1` filled in — which is the whole
 * feature: the thing people retype every day, typed once.
 *
 * Not the same as a skill, though both are markdown with frontmatter. A skill is
 * *offered* to the model, which reads it when a task matches; a template is *sent* by the
 * person, now, as the message. One is a capability, the other is a macro.
 *
 * **Where templates are looked for is the package manager's** (`PackageManager::
 * resolveLocalResources()` for the two directories and the `prompts` setting, `resolve()` for the
 * packages), as it is upstream's; this loads what it is handed, in that order, and the first
 * template of a name wins — upstream's `dedupePrompts()`, a later one a collision warning.
 */
final class SlashCommands
{
    /** A description taken from the first line is cut here, so the list stays a list. */
    private const int DESCRIPTION_LENGTH = 60;

    /**
     * The templates a resolution names, the enabled ones in its order, labelled `(project)`,
     * `(user)`, `(package)` or `(path)` by where they came from.
     *
     * @param list<ResolvedResource> $resources
     * @return array{0: list<FileCommand>, 1: list<string>} the templates, then the collisions
     */
    public static function fromResources(array $resources): array
    {
        $entries = [];

        foreach ($resources as $resource) {
            if ($resource->enabled) {
                $entries[] = [$resource->path, match (true) {
                    $resource->metadata->origin === 'package' => 'package',
                    $resource->metadata->scope === 'project' => 'project',
                    $resource->metadata->scope === 'user' => 'user',
                    default => 'path',
                }];
            }
        }

        return self::load($entries);
    }

    /**
     * Upstream's `loadPromptTemplates()` with `includeDefaults: false`, for the paths `--prompt-template`
     * named: a directory loads its direct `.md` children (`loadTemplatesFromDir()` is not recursive),
     * a `.md` file loads itself, and anything else — a path that is not there included — is
     * skipped, as upstream skips it (the resource loader names a missing one; see
     * `CodingAgent::session()`).
     *
     * @param list<string> $paths absolute
     * @return array{0: list<FileCommand>, 1: list<string>}
     */
    public static function fromPaths(array $paths, string $source = 'path'): array
    {
        return self::load(array_map(static fn (string $path): array => [$path, $source], $paths));
    }

    /**
     * Prompt templates from the directories an extension adds (`resources_discover`), under the
     * source `extension`, each loaded as a templates directory is: its direct `.md` children.
     *
     * @param list<string> $directories
     * @return list<FileCommand>
     */
    public static function fromDirectories(array $directories): array
    {
        return self::fromPaths(array_map(Paths::expand(...), $directories), 'extension')[0];
    }

    /**
     * @param list<array{0: string, 1: string}> $entries path, source
     * @return array{0: list<FileCommand>, 1: list<string>}
     */
    private static function load(array $entries): array
    {
        $commands = [];
        $collisions = [];

        foreach ($entries as [$path, $source]) {
            $files = is_dir($path) ? self::directChildren($path) : (is_file($path) && str_ends_with($path, '.md') ? [$path] : []);

            foreach ($files as $file) {
                $command = self::read($file, $source);

                if ($command === null) {
                    continue;
                }

                if (isset($commands[$command->name])) {
                    $collisions[] = "{$file}: name \"/{$command->name}\" collision: {$commands[$command->name]->path} is loaded and this one is skipped";

                    continue;
                }

                $commands[$command->name] = $command;
            }
        }

        return [array_values($commands), $collisions];
    }

    /**
     * The `.md` files directly in $directory, sorted.
     *
     * `scandir()` sorts, which upstream's `readdirSync` does not promise, and the reason is
     * `CustomToolLoader::discover()`'s: two templates of one name is decided by load order, and
     * readdir order is not an order.
     *
     * @return list<string>
     */
    private static function directChildren(string $directory): array
    {
        $entries = is_readable($directory) ? scandir($directory) : false;
        $files = [];

        foreach ($entries === false ? [] : $entries as $entry) {
            $path = $directory . '/' . $entry;

            if (str_ends_with($entry, '.md') && is_file($path)) {
                $files[] = $path;
            }
        }

        return $files;
    }

    /**
     * What `/name args` should be sent as, or null when no template has that name — upstream's
     * `expandPromptTemplate()`: the name runs to the first whitespace, the arguments are what
     * follows it.
     *
     * @param list<FileCommand> $commands
     */
    public static function expand(string $text, array $commands): ?string
    {
        if (preg_match('/^\/(\S+)(?:\s+(.*))?$/s', $text, $match) !== 1) {
            return null;
        }

        foreach ($commands as $command) {
            if ($command->name === $match[1]) {
                return self::substitute($command->content, self::arguments($match[2] ?? ''));
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
     * an index or a length rather than a scan — see `Pig\Tui\Fuzzy` and the editor's cursor.)
     *
     * @return list<string>
     */
    public static function arguments(string $text): array
    {
        $arguments = [];
        $current = '';
        // Upstream's `hasToken`: whether a token has started, so a quoted empty string (`""` or
        // `''`) is kept as an argument and the ones after it keep their positions (#10867).
        $hasToken = false;
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
                $hasToken = true;

                continue;
            }

            // Upstream's `/\s/`, for the bytes it can be: a newline separates two arguments as a
            // space does.
            if (in_array($character, [' ', "\t", "\n", "\r", "\v", "\f"], true)) {
                if ($hasToken) {
                    $arguments[] = $current;
                    $current = '';
                    $hasToken = false;
                }

                continue;
            }

            $current .= $character;
            $hasToken = true;
        }

        if ($hasToken) {
            $arguments[] = $current;
        }

        return $arguments;
    }

    /**
     * Upstream's `substituteArgs()`:
     *
     * - `$1`, `$2`, … one positional argument (nothing when it is missing)
     * - `$@` and `$ARGUMENTS` every argument, joined by spaces
     * - `${N:-default}` argument N, or the default when it is missing or empty
     * - `${@:-default}` and `${ARGUMENTS:-default}` every argument, or the default when there are none
     * - `${@:N}` the arguments from the Nth on; `${@:N:L}` L of them from the Nth (0 counts as 1)
     *
     * One pass over the template, so an argument or a default holding `$1` is not substituted
     * again. A callback rather than a replacement string for the same reason: `$&` in an argument
     * is the argument, not the match.
     *
     * @param list<string> $arguments
     */
    public static function substitute(string $content, array $arguments): string
    {
        $all = implode(' ', $arguments);

        return (string) preg_replace_callback(
            '/\$\{(\d+|ARGUMENTS|@):-([^}]*)\}|\$\{@:(\d+)(?::(\d+))?\}|\$(ARGUMENTS|@|\d+)/',
            static function (array $match) use ($arguments, $all): string {
                if (($match[1] ?? '') !== '') {
                    $value = $match[1] === '@' || $match[1] === 'ARGUMENTS' ? $all : ($arguments[(int) $match[1] - 1] ?? '');

                    return $value !== '' ? $value : $match[2];
                }

                if (($match[3] ?? '') !== '') {
                    $start = max(0, (int) $match[3] - 1);
                    $slice = ($match[4] ?? '') !== ''
                        ? array_slice($arguments, $start, (int) $match[4])
                        : array_slice($arguments, $start);

                    return implode(' ', $slice);
                }

                if ($match[5] === '@' || $match[5] === 'ARGUMENTS') {
                    return $all;
                }

                return $arguments[(int) $match[5] - 1] ?? '';
            },
            $content,
        );
    }

    // ---- reading -------------------------------------------------------------------------

    /**
     * Upstream's `loadTemplateFromFile()`: the name is the file's, the description the
     * frontmatter's or else the first line with something on it, and `argument-hint` is kept for
     * the completion list.
     */
    private static function read(string $path, string $source): ?FileCommand
    {
        $raw = is_readable($path) ? file_get_contents($path) : false;

        if ($raw === false) {
            return null;
        }

        [$frontmatter, $content] = self::frontmatter($raw);
        $label = "({$source})";
        $description = $frontmatter['description'] ?? '';

        if ($description === '') {
            $description = self::firstLine($content);
        }

        return new FileCommand(
            basename($path, '.md'),
            $description === '' ? $label : "{$description} {$label}",
            $content,
            $label,
            $frontmatter['argument-hint'] ?? null,
            $path,
        );
    }

    /**
     * The first line with something on it, as a description, cut to fit a list.
     *
     * Trimmed, which upstream does not do: an indented first line would otherwise be an indented
     * description in the completion list.
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
     * Upstream's `parseFrontmatter()`, for the string fields a template has: the `---` block's
     * `key: value` lines, and the body after it, trimmed. Without a block the body is the file
     * (byte-order mark gone, line ends `\n`), untrimmed, as upstream's is.
     *
     * A value is the string YAML would give it or nothing: quotes come off, and an unquoted value
     * that YAML reads as something else — a `[list]`, a `{map}`, a number, `true` — is not a string,
     * so `typeof === "string"` upstream leaves it out. That is why the docs quote
     * `argument-hint: "[focus]"`: unquoted, it is a list.
     *
     * @return array{0: array<string, string>, 1: string} fields, then the body
     */
    private static function frontmatter(string $raw): array
    {
        $raw = str_replace(["\r\n", "\r"], "\n", str_starts_with($raw, "\u{FEFF}") ? substr($raw, 3) : $raw);

        if (!str_starts_with($raw, '---')) {
            return [[], $raw];
        }

        $end = strpos($raw, "\n---", 3);

        if ($end === false) {
            return [[], $raw];
        }

        $fields = [];

        foreach (explode("\n", substr($raw, 4, $end - 4)) as $line) {
            if (preg_match('/^(\w[\w-]*):\s*(.*)$/', $line, $match) === 1) {
                $value = self::yamlString(trim($match[2]));

                if ($value !== null) {
                    $fields[$match[1]] = $value;
                }
            }
        }

        return [$fields, trim(substr($raw, $end + 4))];
    }

    /** One scalar as YAML reads it, when it is a string; null when it is anything else. */
    private static function yamlString(string $value): ?string
    {
        foreach (['"', "'"] as $quote) {
            if (strlen($value) >= 2 && str_starts_with($value, $quote) && str_ends_with($value, $quote)) {
                return substr($value, 1, -1);
            }
        }

        $value = trim(preg_replace('/\s+#.*$/', '', $value) ?? $value);

        if ($value === '' || $value[0] === '[' || $value[0] === '{' || is_numeric($value)
            || in_array(strtolower($value), ['true', 'false', 'null', '~'], true)
        ) {
            return null;
        }

        return $value;
    }
}
