<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Prompt;

/**
 * A prompt someone saved as a file, reachable as `/name` — upstream's `PromptTemplate` in
 * `core/prompt-templates.ts`.
 *
 * The content is what gets sent — a template is a stored prompt, not a program.
 * `$1`, `$@`, `${1:-default}` and the rest in it are filled from whatever was typed after the name
 * (`SlashCommands::substitute()`).
 */
final readonly class FileCommand
{
    /**
     * @param string $description shown in the completion list; the frontmatter's, or the
     *        first line of the prompt when it has none, followed by the source label
     * @param string $source      `(user)`, `(project)`, `(package)`, `(path)` or `(extension)` —
     *        where it came from
     * @param string|null $argumentHint upstream's `argumentHint`, the frontmatter's `argument-hint`:
     *        shown before the description in the completion list (`<file>`, `[focus]`)
     * @param string|null $path the template file, upstream's `filePath`
     */
    public function __construct(
        public string $name,
        public string $description,
        public string $content,
        public string $source,
        public ?string $argumentHint = null,
        public ?string $path = null,
    ) {
    }
}
