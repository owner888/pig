<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Prompt;

/**
 * `SYSTEM.md` and `APPEND_SYSTEM.md`: the system prompt somebody wrote down — the part of
 * upstream's `DefaultResourceLoader.reload()` that settles `getSystemPrompt()` and
 * `getAppendSystemPrompt()`.
 *
 * `SYSTEM.md` replaces pig's default prompt (the role, the tool list and the rules; the context
 * files, the skills and the directory still follow it), and `APPEND_SYSTEM.md` is added after
 * whichever prompt that leaves. Each is looked for in the trusted project's `.pig/` first and the
 * agent directory second, and the first found is the one used: the two files of one name are not
 * combined.
 *
 * A value given for the run (upstream's `systemPrompt` / `appendSystemPrompt` options, which its
 * `--system-prompt` / `--append-system-prompt` flags feed) is used **instead of** the discovered
 * file, not as well — and is a path to read when one exists there, the text itself otherwise.
 */
final readonly class SystemPromptFiles
{
    /**
     * @param string|null  $systemPrompt replaces the default prompt; null keeps it
     * @param list<string> $append       added after it, joined by blank lines
     * @param string|null  $systemPromptPath the file `$systemPrompt` was read from, when it was one
     * @param list<string> $appendPaths  the files `$append` was read from
     * @param list<string> $warnings     files that exist and could not be read
     */
    public function __construct(
        public ?string $systemPrompt = null,
        public array $append = [],
        public ?string $systemPromptPath = null,
        public array $appendPaths = [],
        public array $warnings = [],
    ) {
    }

    /**
     * Discover and read both.
     *
     * @param string|null       $systemPrompt upstream's `systemPrompt` option: text or a path; null
     *        discovers `SYSTEM.md`
     * @param list<string>|null $append upstream's `appendSystemPrompt` option, each text or a path;
     *        null discovers `APPEND_SYSTEM.md`
     */
    public static function load(string $cwd, string $agentDir, bool $projectTrusted, ?string $systemPrompt = null, ?array $append = null): self
    {
        $warnings = [];
        $systemPromptSource = $systemPrompt ?? self::discover($cwd, $agentDir, $projectTrusted, 'SYSTEM.md');
        $appendSources = $append ?? array_values(array_filter([self::discover($cwd, $agentDir, $projectTrusted, 'APPEND_SYSTEM.md')]));

        $resolvedSystemPrompt = self::resolve($systemPromptSource, 'system prompt', $warnings);
        $resolvedAppend = [];

        foreach ($appendSources as $source) {
            $text = self::resolve($source, 'append system prompt', $warnings);

            if ($text !== null) {
                $resolvedAppend[] = $text;
            }
        }

        return new self(
            $resolvedSystemPrompt,
            $resolvedAppend,
            $systemPromptSource !== null && $systemPromptSource !== '' && file_exists($systemPromptSource) ? $systemPromptSource : null,
            array_values(array_filter($appendSources, static fn (string $source): bool => $source !== '' && file_exists($source))),
            $warnings,
        );
    }

    /**
     * What `SystemPrompt` takes as its `$custom`: the replacement, or null for the default — an
     * empty one included, as upstream's `if (customPrompt)` leaves the default in place.
     */
    public function custom(): ?string
    {
        return $this->systemPrompt === null || $this->systemPrompt === '' ? null : $this->systemPrompt;
    }

    /** What `SystemPrompt` takes as its `$append`: upstream's `appendSystemPrompt.join("\n\n")`, null when empty. */
    public function appended(): ?string
    {
        $text = implode("\n\n", $this->append);

        return $text === '' ? null : $text;
    }

    /**
     * Upstream's `discoverSystemPromptFile()` / `discoverAppendSystemPromptFile()`: the trusted
     * project's `.pig/<name>`, else `<agent-dir>/<name>`, else nothing.
     */
    public static function discover(string $cwd, string $agentDir, bool $projectTrusted, string $name): ?string
    {
        $project = rtrim($cwd, '/') . '/.pig/' . $name;

        if ($projectTrusted && file_exists($project)) {
            return $project;
        }

        $global = rtrim($agentDir, '/') . '/' . $name;

        return file_exists($global) ? $global : null;
    }

    /**
     * Upstream's `resolvePromptInput()`: a path that exists is read, anything else is the text. A
     * path that exists and cannot be read is said, and the input is used as the text — upstream's
     * fallback, which is not silent: the warning names the file.
     *
     * @param list<string> $warnings
     */
    private static function resolve(?string $input, string $description, array &$warnings): ?string
    {
        if ($input === null || $input === '') {
            return null;
        }

        if (!file_exists($input)) {
            return $input;
        }

        $content = is_file($input) && is_readable($input) ? file_get_contents($input) : false;

        if ($content === false) {
            $warnings[] = "Could not read {$description} file {$input}";

            return $input;
        }

        return str_starts_with($content, "\u{FEFF}") ? substr($content, 3) : $content;
    }
}
