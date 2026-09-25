<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Prompt;

use Pig\CodingAgent\Config;

/**
 * Finding the skills on this machine, and listing them for the model.
 *
 * A skill is a folder with a `SKILL.md` in it, whose frontmatter says what it is for.
 * Only the name and description go into the prompt; the instructions are a file the
 * model reads when a task matches. That is what makes many skills affordable — a
 * hundred of them cost a hundred lines, not a hundred documents.
 *
 * The roots are upstream's, and they are other tools' as well as pig's: `~/.claude/skills`
 * and `~/.codex/skills` are read exactly as `~/.pig/skills` is, because a person who has
 * written a skill once should not have to write it again per agent.
 *
 * Two things differ from upstream and both are stated where they happen: a later root **overrides**
 * an earlier one of the same name rather than being skipped, so pig beats pi beats Claude beats
 * codex; and pi's own two roots are read at all.
 *
 * **pi's own two are read as well, which upstream has no reason to do** — it *is* pi, so its
 * `~/.pi/agent/skills` is the root pig renamed to `~/.pig/skills`. Reading pi's as well follows from
 * what pig already does everywhere else: it opens pi's sessions, its `auth.json` and its
 * `models.json`. Keeping somebody's conversations and credentials across the move while silently
 * dropping their skills is the half-migration that is worse than none — and the reason stated above
 * for reading Claude's and Codex's directories applies most of all to the tool pig is a port of.
 *
 * Ported from upstream's `core/skills.ts`. Not ported: the settings file that turns
 * individual roots off (`SkillsSettings`, which needs a settings manager that is not
 * here) — the roots are arguments instead, so `--skills-dir` and a settings file are
 * both a caller away.
 */
final class Skills
{
    /** The spec's ceilings. https://agentskills.io/specification#frontmatter-required */
    private const int MAX_NAME = 64;

    private const int MAX_DESCRIPTION = 1024;

    /** Everything else in the frontmatter is a typo until the spec says otherwise. */
    private const array ALLOWED_FIELDS = ['name', 'description', 'license', 'compatibility', 'metadata', 'allowed-tools'];

    /**
     * Two layouts, because two tools chose differently.
     *
     * `recursive` looks for a `SKILL.md` at any depth, which is how pig and codex lay
     * them out. `claude` looks exactly one level down, because `~/.claude/skills` holds
     * a folder per skill and descending further finds a skill's own examples.
     */
    private const string RECURSIVE = 'recursive';

    private const string ONE_LEVEL = 'claude';

    /** Directories that are never a skill, whatever is in them. */
    private const array SKIPPED = ['node_modules', 'vendor'];

    /**
     * Every skill this machine offers, by name.
     *
     * @param list<string> $extraDirs  scanned after the standard roots, recursively
     * @param list<string> $ignored    fnmatch patterns; a skill whose name matches is left out
     * @param list<string> $only       fnmatch patterns; when given, nothing else is loaded
     * @param string|null  $piHome     pi's agent directory, `~/.pi/agent` unless told otherwise
     * @return array{0: list<Skill>, 1: list<SkillWarning>}
     */
    public static function load(
        string $cwd,
        ?string $home = null,
        array $extraDirs = [],
        array $ignored = [],
        array $only = [],
        ?string $piHome = null,
    ): array {
        $home ??= Config::home();
        $piHome ??= Config::piHome();
        $user = self::userHome();
        $cwd = rtrim($cwd, '/');

        // Order is precedence, lowest first: **a later root overrides an earlier one of the same
        // name**, so pig beats pi beats Claude beats codex, and a project beats a home directory.
        // `--skills-dir` comes last of all, because it was typed.
        //
        // This is a deliberate divergence. Upstream keeps the *first* one and skips the rest, in
        // this same order — which makes `~/.codex/skills` outrank everything, including pi's own.
        // Nobody who edits a skill in `~/.pig/skills` expects a copy in another tool's folder to be
        // the one that runs, and the more specific of two answers is the right one. The override is
        // named in a warning either way, so nothing about it is silent.
        //
        // `$piHome` is `~/.pi/agent`, not `~/.pi`: upstream's `getAgentDir()` is
        // `join(homedir(), ".pi", "agent")`, so its skills are one level deeper than the folder
        // name suggests. The project one is `<cwd>/.pi/skills`, pi's own `CONFIG_DIR_NAME`.
        $roots = [
            [$user . '/.codex/skills', 'codex-user', self::RECURSIVE],
            [$user . '/.claude/skills', 'claude-user', self::ONE_LEVEL],
            [$cwd . '/.claude/skills', 'claude-project', self::ONE_LEVEL],
            [$piHome . '/skills', 'pi-user', self::RECURSIVE],
            [$cwd . '/.pi/skills', 'pi-project', self::RECURSIVE],
            [$home . '/skills', 'user', self::RECURSIVE],
            [$cwd . '/.pig/skills', 'project', self::RECURSIVE],
        ];

        foreach ($extraDirs as $directory) {
            $roots[] = [self::expand($directory, $user), 'custom', self::RECURSIVE];
        }

        $skills = [];
        $warnings = [];
        $seenFiles = [];

        foreach ($roots as [$directory, $source, $format]) {
            [$found, $complaints] = self::scan($directory, $source, $format);
            $warnings = [...$warnings, ...$complaints];

            foreach ($found as $skill) {
                if (self::matchesAny($skill->name, $ignored)) {
                    continue;
                }

                if ($only !== [] && !self::matchesAny($skill->name, $only)) {
                    continue;
                }

                // Two roots reaching the same file through a symlink is one skill, not a
                // collision: `~/.claude/skills` symlinked into `~/.pig/skills` is a
                // normal way to keep one copy.
                $real = realpath($skill->path);
                $real = $real === false ? $skill->path : $real;

                if (isset($seenFiles[$real])) {
                    continue;
                }

                if (isset($skills[$skill->name])) {
                    // Said rather than done quietly: whoever has the same name in two folders is
                    // about to run one of them, and which one is the whole question. Upstream's
                    // wording is "skipping this one", which is the opposite way round.
                    $warnings[] = new SkillWarning(
                        $skill->path,
                        "name taken: \"{$skill->name}\" overrides the one from {$skills[$skill->name]->path}",
                    );
                }

                $skills[$skill->name] = $skill;
                $seenFiles[$real] = true;
            }
        }

        return [array_values($skills), $warnings];
    }

    /**
     * The skills, as the model is told about them.
     *
     * XML rather than a list, per the Agent Skills standard, and the location is the
     * path to read — a description with no path is an instruction the model cannot obey.
     *
     * @param list<Skill> $skills
     */
    public static function forPrompt(array $skills): string
    {
        if ($skills === []) {
            return '';
        }

        $lines = [
            '',
            '',
            'The following skills provide specialized instructions for specific tasks.',
            "Use the read tool to load a skill's file when the task matches its description.",
            '',
            '<available_skills>',
        ];

        foreach ($skills as $skill) {
            $lines[] = '  <skill>';
            $lines[] = '    <name>' . self::escape($skill->name) . '</name>';
            $lines[] = '    <description>' . self::escape($skill->description) . '</description>';
            $lines[] = '    <location>' . self::escape($skill->path) . '</location>';
            $lines[] = '  </skill>';
        }

        $lines[] = '</available_skills>';

        return implode("\n", $lines);
    }

    // ---- looking ----------------------------------------------------------------------

    /**
     * @return array{0: list<Skill>, 1: list<SkillWarning>}
     */
    private static function scan(string $directory, string $source, string $format): array
    {
        if (!is_dir($directory)) {
            return [[], []];
        }

        $entries = scandir($directory);

        if ($entries === false) {
            return [[], []];
        }

        $skills = [];
        $warnings = [];

        foreach ($entries as $entry) {
            // A dotfile is configuration, and `.git` in a skills folder is a repository
            // of them rather than one of them.
            if (str_starts_with($entry, '.') || in_array($entry, self::SKIPPED, true)) {
                continue;
            }

            $path = $directory . '/' . $entry;

            if ($format === self::ONE_LEVEL) {
                if (is_dir($path) && is_file($path . '/SKILL.md')) {
                    [$skill, $complaints] = self::read($path . '/SKILL.md', $source);
                    $warnings = [...$warnings, ...$complaints];

                    if ($skill !== null) {
                        $skills[] = $skill;
                    }
                }

                continue;
            }

            if (is_dir($path)) {
                [$found, $complaints] = self::scan($path, $source, $format);
                $skills = [...$skills, ...$found];
                $warnings = [...$warnings, ...$complaints];

                continue;
            }

            if ($entry === 'SKILL.md') {
                [$skill, $complaints] = self::read($path, $source);
                $warnings = [...$warnings, ...$complaints];

                if ($skill !== null) {
                    $skills[] = $skill;
                }
            }
        }

        return [$skills, $warnings];
    }

    /**
     * One SKILL.md, checked against the spec.
     *
     * Loaded despite most complaints: a name two characters too long is worth saying and
     * not worth refusing over. A missing description is the exception — it is the only
     * thing the model sees, so a skill without one cannot be chosen and would sit in the
     * prompt as a name nobody can use.
     *
     * @return array{0: Skill|null, 1: list<SkillWarning>}
     */
    private static function read(string $path, string $source): array
    {
        $content = file_exists($path) && is_readable($path) ? file_get_contents($path) : false;

        if ($content === false) {
            return [null, [new SkillWarning($path, 'could not be read')]];
        }

        [$fields, $keys] = self::frontmatter($content);
        $baseDir = dirname($path);
        $folder = basename($baseDir);
        $warnings = [];

        foreach ($keys as $key) {
            if (!in_array($key, self::ALLOWED_FIELDS, true)) {
                $warnings[] = new SkillWarning($path, "unknown frontmatter field \"{$key}\"");
            }
        }

        $description = trim($fields['description'] ?? '');

        if ($description === '') {
            return [null, [...$warnings, new SkillWarning($path, 'description is required')]];
        }

        if (strlen($description) > self::MAX_DESCRIPTION) {
            $warnings[] = new SkillWarning(
                $path,
                'description is longer than ' . self::MAX_DESCRIPTION . ' characters (' . strlen($description) . ')',
            );
        }

        $name = $fields['name'] ?? $folder;
        $warnings = [...$warnings, ...self::nameProblems($name, $folder, $path)];

        return [new Skill($name, $description, $path, $baseDir, $source), $warnings];
    }

    /** @return list<SkillWarning> */
    private static function nameProblems(string $name, string $folder, string $path): array
    {
        $problems = [];

        if ($name !== $folder) {
            // The folder is how a skill is referred to everywhere else, so a name that
            // disagrees with it is two names for one thing.
            $problems[] = "name \"{$name}\" does not match the folder \"{$folder}\"";
        }

        if (strlen($name) > self::MAX_NAME) {
            $problems[] = 'name is longer than ' . self::MAX_NAME . ' characters (' . strlen($name) . ')';
        }

        if (preg_match('/^[a-z0-9-]+$/', $name) !== 1) {
            $problems[] = 'name must be lowercase letters, digits and hyphens only';
        }

        if (str_starts_with($name, '-') || str_ends_with($name, '-')) {
            $problems[] = 'name must not start or end with a hyphen';
        }

        if (str_contains($name, '--')) {
            $problems[] = 'name must not contain two hyphens in a row';
        }

        return array_map(static fn (string $message): SkillWarning => new SkillWarning($path, $message), $problems);
    }

    /**
     * The `---` block at the top, as a flat map.
     *
     * A line at a time rather than a YAML parser: the spec's fields are all scalars, and
     * pig has no YAML parser to reach for. What this cannot read stays unread — a nested
     * `metadata:` block contributes its key and nothing else, which is what the key is
     * validated for anyway.
     *
     * @return array{0: array<string, string>, 1: list<string>} values, then every key seen
     */
    private static function frontmatter(string $content): array
    {
        $content = str_replace(["\r\n", "\r"], "\n", $content);

        if (!str_starts_with($content, '---')) {
            return [[], []];
        }

        $end = strpos($content, "\n---", 3);

        if ($end === false) {
            return [[], []];
        }

        $fields = [];
        $keys = [];

        foreach (explode("\n", substr($content, 4, $end - 4)) as $line) {
            if (preg_match('/^(\w[\w-]*):\s*(.*)$/', $line, $match) !== 1) {
                continue;
            }

            $keys[] = $match[1];

            if ($match[1] === 'name' || $match[1] === 'description') {
                $fields[$match[1]] = self::unquote(trim($match[2]));
            }
        }

        return [$fields, $keys];
    }

    private static function unquote(string $value): string
    {
        foreach (['"', "'"] as $quote) {
            if (strlen($value) >= 2 && str_starts_with($value, $quote) && str_ends_with($value, $quote)) {
                return substr($value, 1, -1);
            }
        }

        return $value;
    }

    /** @param list<string> $patterns */
    private static function matchesAny(string $name, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (fnmatch($pattern, $name)) {
                return true;
            }
        }

        return false;
    }

    private static function expand(string $directory, string $home): string
    {
        return $directory === '~' || str_starts_with($directory, '~/')
            ? $home . substr($directory, 1)
            : $directory;
    }

    /** The person's home, which is not `Config::home()` — that is `~/.pig`. */
    private static function userHome(): string
    {
        $home = getenv('HOME');

        return $home === false || $home === '' ? sys_get_temp_dir() : rtrim($home, '/');
    }

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
