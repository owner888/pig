<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Prompt;

/**
 * A folder of instructions for one kind of task, and where it came from.
 *
 * Only the name and the description reach the model up front; the instructions
 * themselves are a file it reads when the task matches. That is the whole idea — a
 * hundred skills cost a hundred lines of prompt, not a hundred documents.
 *
 * Ported from upstream's `Skill` in `core/skills.ts`.
 */
final readonly class Skill
{
    /**
     * @param string $path   the SKILL.md itself, which is what the model is told to read
     * @param string $baseDir the folder it sits in, which its own relative paths are from
     * @param string $source which root it was found under, for `/skills` and the banner
     */
    public function __construct(
        public string $name,
        public string $description,
        public string $path,
        public string $baseDir,
        public string $source,
    ) {
    }
}
