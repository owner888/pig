<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Prompt;

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
     * @param list<string>           $tools        tool names, as ToolSet knows them
     * @param string|null            $custom       replaces the default prompt entirely
     * @param string|null            $append       added after it, whichever was used
     * @param list<ContextFile>|null $contextFiles discovered from $cwd when not given
     * @param list<Skill>            $skills       passed in rather than discovered, so the
     *        banner and the prompt cannot disagree about what was picked up
     */
    public static function build(
        string $cwd,
        array $tools = ToolSet::CODING,
        ?string $custom = null,
        ?string $append = null,
        ?array $contextFiles = null,
        array $skills = [],
    ): string {
        $contextFiles ??= ContextFiles::load($cwd);
        $prompt = $custom ?? self::instructions($tools);

        if ($append !== null && trim($append) !== '') {
            $prompt .= "\n\n" . $append;
        }

        $prompt .= self::context($contextFiles);

        // After the project's own instructions: a skill is a thing to reach for, and the
        // rules about how to work here apply whichever one is reached for.
        $prompt .= Skills::forPrompt($skills);

        // Last, because they are facts rather than instructions and the model should not
        // have to read past them to reach the part that tells it what to do.
        $prompt .= "\n\nCurrent date and time: " . date('l, j F Y, H:i:s T');
        $prompt .= "\nCurrent working directory: {$cwd}";

        return $prompt;
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

    /** @param list<string> $tools */
    private static function instructions(array $tools): string
    {
        $list = implode("\n", array_map(
            static fn (string $name): string => "- {$name}: " . ToolSet::describe($name),
            $tools,
        ));

        $guidelines = implode("\n", array_map(
            static fn (string $line): string => "- {$line}",
            self::guidelines($tools),
        ));

        return self::ROLE . "\n\nAvailable tools:\n{$list}\n\nGuidelines:\n{$guidelines}";
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
            $guidelines[] = 'Use bash for file operations like ls, grep, find';
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
