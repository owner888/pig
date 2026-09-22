<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Prompt;

/**
 * A prompt someone saved as a file, reachable as `/name`.
 *
 * The content is what gets sent — a slash command is a stored prompt, not a program.
 * `$1`, `$2` and `$@` in it are filled from whatever was typed after the name.
 *
 * Ported from upstream's `FileSlashCommand` in `core/slash-commands.ts`.
 */
final readonly class FileCommand
{
    /**
     * @param string $description shown in the completion list; the frontmatter's, or the
     *        first line of the prompt when it has none
     * @param string $source      `(user)` or `(project:sub)` — which folder it came from
     */
    public function __construct(
        public string $name,
        public string $description,
        public string $content,
        public string $source,
    ) {
    }
}
