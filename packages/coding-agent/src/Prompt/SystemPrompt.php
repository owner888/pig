<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Prompt;

use Pig\Agent\AgentError;
use Pig\Ai\SystemMessage;
use Pig\Ai\Utils\Text;
use Pig\CodingAgent\Tools\ToolSet;

/**
 * What the model is told before anything else.
 *
 * The guidelines are worked out from the tools the run actually has, rather than being a
 * fixed block. A model told to "prefer grep over bash" when it has no grep will waste a
 * turn discovering that, and one told to read before editing when it cannot edit is
 * being given something to ignore — and a prompt with instructions that do not apply
 * teaches it that instructions here are approximate.
 */
final class SystemPrompt
{
    private const string ROLE = 'You are an expert coding assistant. You help users with coding tasks by reading '
        . 'files, executing commands, editing code, and writing new files.';

    /**
     * The prompt as one string — upstream's `buildSystemPrompt()`: "rendered exactly as the
     * transcript's system message replays it", which is `sections()` joined by blank lines.
     *
     * @param list<string>           $tools        tool names, as ToolSet knows them
     * @param string|null            $custom       replaces the default prompt entirely
     * @param string|null            $append       added after it, whichever was used
     * @param list<ContextFile>|null $contextFiles discovered from $cwd when not given
     * @param list<Skill>            $skills       passed in rather than discovered, so the
     *        banner and the prompt cannot disagree about what was picked up
     * @param array<string, string>  $toolSnippets extension tools that asked for a line in the list
     * @param list<string>           $toolGuidelines rules those tools add — see `CustomTool::$promptSnippet`
     */
    public static function build(
        string $cwd,
        array $tools = ToolSet::CODING,
        ?string $custom = null,
        ?string $append = null,
        ?array $contextFiles = null,
        array $skills = [],
        array $toolSnippets = [],
        array $toolGuidelines = [],
    ): string {
        return Text::getSystemMessageText(new SystemMessage(
            '',
            self::sections($cwd, $tools, $custom, $append, $contextFiles, $skills, $toolSnippets, $toolGuidelines),
        ));
    }

    /**
     * The ordered, independently replaceable sections of the prompt — upstream's
     * `buildSystemPromptSections()`, which become `SystemMessage::$sections` in the transcript. A
     * later change to one of them reaches the model as a system message that replaces that section
     * alone (`diffSections()`), rather than as a second copy of the whole prompt.
     *
     * The names are upstream's — `preamble`, `tools`, `rules`, `addendum`, `project_context`,
     * `skills`, `cwd` — and the text is pig's own: upstream wraps every section but the preamble in a
     * tag of its name, and pig's prompt has always been the plain text below, so the sections hold
     * that text verbatim and render, joined by blank lines, to exactly the prompt pig sent before.
     * The `cwd` section is the directory alone, with backslashes as forward slashes, as upstream's
     * is: no date or time, so the section changes only when the directory does.
     *
     * @param list<string>           $tools
     * @param list<ContextFile>|null $contextFiles
     * @param list<Skill>            $skills
     * @param array<string, string>  $toolSnippets
     * @param list<string>           $toolGuidelines
     * @return array<string, string>
     */
    public static function sections(
        string $cwd,
        array $tools = ToolSet::CODING,
        ?string $custom = null,
        ?string $append = null,
        ?array $contextFiles = null,
        array $skills = [],
        array $toolSnippets = [],
        array $toolGuidelines = [],
    ): array {
        $contextFiles ??= ContextFiles::load($cwd);
        $sections = [];

        if ($custom !== null) {
            $sections['preamble'] = $custom;
        } else {
            [$sections['preamble'], $sections['tools'], $sections['rules']] = self::instructions($tools, $toolSnippets, $toolGuidelines);
        }

        if ($append !== null && trim($append) !== '') {
            $sections['addendum'] = $append;
        }

        $context = self::context($contextFiles);

        if ($context !== '') {
            $sections['project_context'] = substr($context, 2);
        }

        // After the project's own instructions: a skill is a thing to reach for, and the
        // rules about how to work here apply whichever one is reached for.
        $skillsPrompt = Skills::forPrompt($skills);

        if ($skillsPrompt !== '') {
            $sections['skills'] = substr($skillsPrompt, 2);
        }

        // Last, because they are facts rather than instructions and the model should not
        // have to read past them to reach the part that tells it what to do.
        $sections['cwd'] = 'Current working directory: ' . str_replace('\\', '/', $cwd);

        return $sections;
    }

    /** Upstream's `SYSTEM_PROMPT_SECTION_NAME`. */
    private const string SECTION_NAME = '/^[a-z][a-z0-9_-]*$/';

    /**
     * $sections with the additional sections a `before_agent_start` handler asked for — upstream's
     * `sections` option of `buildSystemPromptSections()`: each name must be a lowercase tag name and
     * not `preamble` (`Invalid system prompt section name: …` otherwise), an empty one is left out,
     * and each is wrapped in a tag of its name, `<name>\n…\n</name>`. They go after `cwd`; a name
     * that is already a section replaces it where it stands, as a JavaScript object key does.
     *
     * pig's own sections are not tagged (see `sections()`); these are, because a handler's section
     * is matched by the model against the later update that replaces or removes it.
     *
     * @param array<string, string> $sections what `sections()` built
     * @param array<string, string> $custom   name => content
     * @return array<string, string>
     *
     * @throws AgentError for a name upstream refuses
     */
    public static function withSections(array $sections, array $custom): array
    {
        foreach (array_keys($custom) as $name) {
            $name = (string) $name;

            if (preg_match(self::SECTION_NAME, $name) !== 1 || $name === 'preamble') {
                throw new AgentError("Invalid system prompt section name: {$name}");
            }
        }

        foreach ($custom as $name => $content) {
            if ($content !== '') {
                $sections[(string) $name] = "<{$name}>\n{$content}\n</{$name}>";
            }
        }

        return $sections;
    }

    /**
     * Diff the sections the model currently has (replayed from the transcript, so never null)
     * against the desired ones — upstream's `diffSystemPromptSections()`. Returns a
     * `SystemMessage::$sections` patch, or null when nothing changed.
     *
     * @param array<string, string|null> $previous
     * @param array<string, string>      $current
     * @return array<string, string|null>|null
     */
    public static function diffSections(array $previous, array $current): ?array
    {
        $patch = [];

        foreach ($current as $name => $text) {
            if (($previous[$name] ?? null) !== $text) {
                $patch[(string) $name] = $text;
            }
        }

        foreach (array_keys($previous) as $name) {
            if (!array_key_exists($name, $current)) {
                $patch[(string) $name] = null;
            }
        }

        return $patch !== [] ? $patch : null;
    }

    /**
     * Either a path to read the prompt from, or the prompt itself.
     *
     * Someone passing `--system-prompt ./prompt.md` means the file, and someone passing
     * a sentence means the sentence; both are common enough that guessing is kinder than
     * a second flag.
     */
    public static function resolve(?string $input): ?string
    {
        if ($input === null || $input === '') {
            return null;
        }

        if (!is_file($input)) {
            return $input;
        }

        $contents = file_get_contents($input);

        return $contents === false ? $input : $contents;
    }

    /**
     * The default prompt's three sections: the role, the tool list, and the rules.
     *
     * @param list<string> $tools the built-ins
     * @param array<string, string> $toolSnippets extension tools with something to say in the list, name => line
     * @param list<string> $toolGuidelines rules those tools add
     * @return array{0: string, 1: string, 2: string}
     */
    private static function instructions(array $tools, array $toolSnippets = [], array $toolGuidelines = []): array
    {
        $lines = array_map(static fn (string $name): string => "- {$name}: " . ToolSet::describe($name), $tools);

        foreach ($toolSnippets as $name => $snippet) {
            $lines[] = "- {$name}: {$snippet}";
        }

        $list = implode("\n", $lines);

        // A guideline said twice is noise; the built-ins' come first because they are read first.
        $all = [];

        foreach ([...self::guidelines($tools), ...$toolGuidelines] as $line) {
            $all[trim($line)] = true;
        }

        $guidelines = implode("\n", array_map(
            static fn (string $line): string => "- {$line}",
            array_keys($all),
        ));

        return [self::ROLE, "Available tools:\n{$list}", "Guidelines:\n{$guidelines}"];
    }

    /**
     * The advice that applies given these tools, and none that does not.
     *
     * @param list<string> $tools
     * @return list<string>
     */
    private static function guidelines(array $tools): array
    {
        $has = static fn (string $name): bool => in_array($name, $tools, true);
        $guidelines = [];

        if (!$has('bash') && !$has('edit') && !$has('write')) {
            $guidelines[] = 'You are in READ-ONLY mode: you cannot change files or run commands';
        }

        // bash with nothing to write with is still a way to change things, so say which
        // half of it is meant.
        if ($has('bash') && !$has('edit') && !$has('write')) {
            $guidelines[] = 'Use bash ONLY for read-only operations (git log, gh issue view, curl, etc.) - do NOT modify any files';
        }

        if ($has('bash') && !$has('grep') && !$has('find') && !$has('ls')) {
            $guidelines[] = 'Use bash for file operations like ls, rg, find';
        } elseif ($has('bash') && ($has('grep') || $has('find') || $has('ls'))) {
            $guidelines[] = 'Prefer grep/find/ls tools over bash for file exploration (faster, respects .gitignore)';
        }

        if ($has('read') && $has('edit')) {
            $guidelines[] = 'Use read to examine files before editing. You must use this tool instead of cat or sed.';
        }

        if ($has('edit')) {
            $guidelines[] = 'Use edit for precise changes (old text must match exactly)';
        }

        if ($has('write')) {
            $guidelines[] = 'Use write only for new files or complete rewrites';
        }

        if ($has('edit') || $has('write')) {
            $guidelines[] = 'When summarizing your actions, output plain text directly - do NOT use cat or bash to display what you did';
        }

        $guidelines[] = 'Be concise in your responses';
        $guidelines[] = 'Show file paths clearly when working with files';

        return $guidelines;
    }

    /** @param list<ContextFile> $files */
    private static function context(array $files): string
    {
        if ($files === []) {
            return '';
        }

        $section = "\n\n# Project Context\n\nThese files were loaded from the project:\n\n";

        foreach ($files as $file) {
            // The path is given because the model is often asked to update these files,
            // and it cannot do that without knowing which one said what.
            $section .= "## {$file->path}\n\n{$file->content}\n\n";
        }

        return rtrim($section, "\n");
    }
}
