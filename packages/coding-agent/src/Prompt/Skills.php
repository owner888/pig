<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Prompt;

use Pig\Ai\Utils\Utf8;
use Pig\CodingAgent\Config;
use Pig\CodingAgent\Packages\PathMetadata;
use Pig\CodingAgent\Packages\ResolvedResource;
use Pig\CodingAgent\Tools\Paths;
use Pig\CodingAgent\Utils\YamlError;

/**
 * Loading skills, and listing them for the model — upstream's `core/skills.ts`.
 *
 * A skill is a folder with a `SKILL.md` in it, whose frontmatter says what it is for.
 * Only the name and description go into the prompt; the instructions are a file the
 * model reads when a task matches. That is what makes many skills affordable — a
 * hundred of them cost a hundred lines, not a hundred documents.
 *
 * **Where skills are looked for is not this class's business**, as it is not upstream's: the
 * package manager resolves the directories and the settings to files (`PackageManager::
 * resolveLocalResources()` — `<agent-dir>/skills`, `.pig/skills`, `~/.agents/skills`, the
 * ancestors' `.agents/skills`, the `skills` setting — and `resolve()` for the packages), and this
 * loads the files and directories it is handed, in the order handed: **the first skill of a name
 * wins** and every later one is a collision warning, which is upstream's `loadSkills()`.
 *
 * Not ported: the `.gitignore` / `.ignore` / `.fdignore` rules upstream applies while walking a
 * directory (see `Packages\ResourceDiscovery` for why). The frontmatter is YAML (`Frontmatter`),
 * so a malformed block is refused as upstream refuses it.
 */
final class Skills
{
    /**
     * The spec's ceilings, in **characters**. https://agentskills.io/specification#frontmatter-required
     *
     * Counted with `mb_strlen()`, not `strlen()`. The spec says characters and upstream counts
     * UTF-16 code units, which for anything either project reads is the same number; bytes are
     * neither, and a 341-character Chinese description would be complained about for being
     * 1024 bytes long — with a number in the complaint that counts nothing anybody asked about.
     * Same family as the editor's cursor and `write`'s byte count: port the unit, not the
     * expression.
     */
    private const int MAX_NAME = 64;

    private const int MAX_DESCRIPTION = 1024;

    /** Directories that are never walked: upstream's `node_modules`, and Composer's `vendor`. */
    private const array SKIPPED = ['node_modules', 'vendor'];

    /**
     * Upstream's `loadSkills()` with `includeDefaults: false`: skill files and directories, in
     * precedence order. A path that is not there, or a file that is not markdown, is a warning; a
     * name already loaded is a collision warning and the later skill is skipped; the same file
     * reached twice (a symlink) is one skill, silently.
     *
     * The source label is upstream's `getSource()` for a path given here: `user` under
     * `<agent-dir>/skills`, `project` under `<cwd>/.pig/skills`, otherwise $source.
     *
     * @param list<string> $paths absolute, or relative to $cwd
     * @return array{0: list<Skill>, 1: list<SkillWarning>}
     */
    public static function fromPaths(array $paths, string $cwd, ?string $home = null, string $source = 'path'): array
    {
        $home ??= Config::home();
        $entries = [];

        foreach ($paths as $path) {
            $resolved = Paths::resolve(trim($path), $cwd);
            $entries[] = [$resolved, self::sourceFor($resolved, $cwd, $home, $source)];
        }

        return self::load($entries);
    }

    /**
     * The skills a resolution names (`PackageManager`), the enabled ones in its order, labelled by
     * where they came from: `package` for a package's, else the scope (`project`, `user`, `path`).
     *
     * @param list<ResolvedResource> $resources
     * @return array{0: list<Skill>, 1: list<SkillWarning>}
     */
    public static function fromResources(array $resources): array
    {
        $entries = [];

        foreach ($resources as $resource) {
            if ($resource->enabled) {
                $entries[] = [$resource->path, self::label($resource->metadata)];
            }
        }

        return self::load($entries);
    }

    /**
     * Skills from the directories an extension adds (`resources_discover`), under the source
     * `extension` — upstream's `extendResources()`, whose paths load as any other skill path does.
     *
     * @param list<string> $directories
     * @return array{0: list<Skill>, 1: list<SkillWarning>}
     */
    public static function fromDirectories(array $directories): array
    {
        return self::load(array_map(static fn (string $directory): array => [Paths::expand($directory), 'extension'], $directories));
    }

    /** The label `/skills` shows for a resolved resource. */
    private static function label(PathMetadata $metadata): string
    {
        if ($metadata->origin === 'package') {
            return 'package';
        }

        return match ($metadata->scope) {
            'project' => 'project',
            'user' => 'user',
            default => 'path',
        };
    }

    private static function sourceFor(string $path, string $cwd, string $home, string $fallback): string
    {
        foreach (['user' => $home . '/skills', 'project' => rtrim($cwd, '/') . '/.pig/skills'] as $label => $root) {
            if ($path === $root || str_starts_with($path, $root . '/')) {
                return $label;
            }
        }

        return $fallback;
    }

    /**
     * The body of `loadSkills()`: each entry a file or a directory, first name wins.
     *
     * @param list<array{0: string, 1: string}> $entries path, source label
     * @return array{0: list<Skill>, 1: list<SkillWarning>}
     */
    private static function load(array $entries): array
    {
        $skills = [];
        $realPaths = [];
        $warnings = [];
        $collisions = [];

        foreach ($entries as [$path, $source]) {
            if (!file_exists($path)) {
                $warnings[] = new SkillWarning($path, 'skill path does not exist');

                continue;
            }

            if (is_dir($path)) {
                [$found, $complaints] = self::fromDir($path, $source, true);
            } elseif (is_file($path) && str_ends_with($path, '.md')) {
                [$skill, $complaints] = self::read($path, $source);
                $found = $skill === null ? [] : [$skill];
            } else {
                $warnings[] = new SkillWarning($path, 'skill path is not a markdown file');

                continue;
            }

            $warnings = [...$warnings, ...$complaints];

            foreach ($found as $skill) {
                // Two paths reaching the same file through a symlink is one skill, not a collision:
                // `~/.agents/skills` symlinked into `~/.pig/agent/skills` is a normal way to keep
                // one copy.
                $real = realpath($skill->path) ?: $skill->path;

                if (isset($realPaths[$real])) {
                    continue;
                }

                if (isset($skills[$skill->name])) {
                    $collisions[] = new SkillWarning(
                        $skill->path,
                        "name \"{$skill->name}\" collision: {$skills[$skill->name]->path} is loaded and this one is skipped",
                    );

                    continue;
                }

                $skills[$skill->name] = $skill;
                $realPaths[$real] = true;
            }
        }

        return [array_values($skills), [...$warnings, ...$collisions]];
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
        // Upstream's `formatSkillsForPrompt()`: a skill with `disable-model-invocation` is reached
        // only by `/skill:name`, so the model is not told about it.
        $skills = array_values(array_filter($skills, static fn (Skill $skill): bool => !$skill->disableModelInvocation));

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
     * Upstream's `loadSkillsFromDirInternal()`: a directory with a `SKILL.md` is one skill and the
     * walk stops there; otherwise its subdirectories are walked, and — at the root only — each `.md`
     * beside them is a skill of its own when it has a description. Dot entries, `node_modules` and
     * `vendor` are skipped, and so is anything a symlink cannot be followed to.
     *
     * `scandir()` sorts where upstream's `readdirSync()` does not promise to, which is the order a
     * name collision is decided by — the same reason `CustomToolLoader::discover()` sorts.
     *
     * @return array{0: list<Skill>, 1: list<SkillWarning>}
     */
    private static function fromDir(string $dir, string $source, bool $includeRootFiles): array
    {
        if (is_file($dir . '/SKILL.md')) {
            [$skill, $warnings] = self::read($dir . '/SKILL.md', $source);

            return [$skill === null ? [] : [$skill], $warnings];
        }

        $entries = is_readable($dir) ? scandir($dir) : false;

        if ($entries === false) {
            return [[], []];
        }

        $skills = [];
        $warnings = [];

        foreach ($entries as $entry) {
            if (str_starts_with($entry, '.') || in_array($entry, self::SKIPPED, true)) {
                continue;
            }

            $path = $dir . '/' . $entry;

            if (is_dir($path)) {
                [$found, $complaints] = self::fromDir($path, $source, false);
                $skills = [...$skills, ...$found];
                $warnings = [...$warnings, ...$complaints];

                continue;
            }

            if (!$includeRootFiles || !is_file($path) || !str_ends_with($entry, '.md')) {
                continue;
            }

            [$skill, $complaints] = self::read($path, $source);
            $warnings = [...$warnings, ...$complaints];

            if ($skill !== null) {
                $skills[] = $skill;
            }
        }

        return [$skills, $warnings];
    }

    /**
     * Upstream's `loadSkillFromFile()`. A `SKILL.md` is a declared skill: everything wrong with it is
     * said, and it is loaded despite all of it but a missing description — the only thing the model
     * sees, so a skill without one cannot be chosen. Any other `.md` is a skill only if it has a
     * description, and is skipped without a word when it has none: a stray README is not a broken
     * skill. The name is the frontmatter's, else the folder's; it is not compared with the folder,
     * as upstream does not compare it.
     *
     * `disable-model-invocation: true` keeps the skill out of the prompt (`forPrompt()`); it is still
     * a skill, and `/skill:name` still reaches it.
     *
     * @return array{0: Skill|null, 1: list<SkillWarning>}
     */
    private static function read(string $path, string $source): array
    {
        $content = is_readable($path) ? file_get_contents($path) : false;

        if ($content === false) {
            return [null, [new SkillWarning($path, 'could not be read')]];
        }

        $declared = basename($path) === 'SKILL.md';

        try {
            [$fields] = Frontmatter::parse($content);
        } catch (YamlError $error) {
            // Upstream's: said for a `SKILL.md`, which was meant to be a skill; any other `.md` is
            // passed over as it would be for having no description.
            return [null, $declared ? [new SkillWarning($path, $error->getMessage())] : []];
        }

        // Strings only, as upstream's `typeof … === "string"`: `description: 123` is no description.
        $description = is_string($fields['description'] ?? null) ? trim($fields['description']) : '';

        if (!$declared && $description === '') {
            return [null, []];
        }

        $baseDir = dirname($path);
        $messages = [];

        if ($description === '') {
            $messages[] = 'description is required';
        } elseif (($length = mb_strlen($description, 'UTF-8')) > self::MAX_DESCRIPTION) {
            $messages[] = 'description exceeds ' . self::MAX_DESCRIPTION . " characters ({$length})";
        }

        $name = is_string($fields['name'] ?? null) && $fields['name'] !== '' ? $fields['name'] : basename($baseDir);
        $messages = [...$messages, ...self::nameProblems($name)];
        $warnings = array_map(static fn (string $message): SkillWarning => new SkillWarning($path, $message), $messages);

        if ($description === '') {
            return [null, $warnings];
        }

        $disabled = ($fields['disable-model-invocation'] ?? null) === true;

        return [new Skill($name, $description, $path, $baseDir, $source, $disabled), $warnings];
    }

    /**
     * Upstream's `validateName()`, its sentences.
     *
     * @return list<string>
     */
    private static function nameProblems(string $name): array
    {
        $problems = [];
        $length = mb_strlen($name, 'UTF-8');

        if ($length > self::MAX_NAME) {
            $problems[] = 'name exceeds ' . self::MAX_NAME . " characters ({$length})";
        }

        if (preg_match('/^[a-z0-9-]+$/', $name) !== 1) {
            $problems[] = 'name contains invalid characters (must be lowercase a-z, 0-9, hyphens only)';
        }

        if (str_starts_with($name, '-') || str_ends_with($name, '-')) {
            $problems[] = 'name must not start or end with a hyphen';
        }

        if (str_contains($name, '--')) {
            $problems[] = 'name must not contain consecutive hyphens';
        }

        return $problems;
    }

    /** Upstream's `stripFrontmatter()`: the body, without a byte-order mark, with `\n` line ends. */
    public static function stripFrontmatter(string $content): string
    {
        return Frontmatter::body($content);
    }

    /**
     * For the prompt, which is XML-ish and is read by the model.
     *
     * Sanitised first, and that is the whole reason this is not one call:
     * `htmlspecialchars()` answers the **empty string** for input that is not UTF-8 — so one
     * stray byte in a `SKILL.md` put the skill in the prompt with `<description></description>`.
     * Listed, loaded, complained about for nothing, and impossible for the model to ever
     * choose, with nothing anywhere saying why. A skill's file is a file somebody wrote in
     * whatever their editor saved, so this is not an exotic input.
     *
     * `ENT_SUBSTITUTE` was the other candidate and is worse here: it would put U+FFFD in front
     * of the model, where dropping the byte leaves the sentence readable. The terminal's
     * equivalent of this decision is `Tools\Shell::sanitize()`, which strips more because a
     * terminal acts on what it is sent; a prompt does not.
     */
    private static function escape(string $text): string
    {
        return htmlspecialchars(Utf8::sanitize($text), ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
