<?php

declare(strict_types=1);

namespace Pig\CodingAgent\CustomTools;

/**
 * A tool file that would not load, or a session callback that failed, and where it was.
 *
 * Not an exception, for the same reason a hook's complaint is not one: a tool file with a
 * mistake in it is a fact about that file, and pig starting without it is better than pig
 * not starting.
 */
final readonly class ToolProblem
{
    public function __construct(
        public string $path,
        public string $error,
    ) {
    }

    public function toText(): string
    {
        return "{$this->path}: {$this->error}";
    }
}
