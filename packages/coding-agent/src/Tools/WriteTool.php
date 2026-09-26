<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Tools;

use Closure;
use Pig\Agent\AgentError;
use Pig\Agent\AgentTool;
use Pig\Agent\AgentToolResult;
use Pig\Ai\TextContent;
use Pig\Ai\Tool;
use Pig\Async\AbortSignal;

/**
 * Write a whole file.
 *
 * Overwrites without asking, which is the behaviour the model is told about: a write is
 * for a new file or for replacing one outright, and anything else should be an edit,
 * which can only change text it has already seen.
 *
 * Three deviations from upstream's `write.ts`, found by running both over one corpus:
 *
 * - **The byte count is bytes.** Upstream reports `content.length`, which is UTF-16 code
 *   units, in a sentence that says "bytes" — so writing `你好世界` tells the model it wrote 4
 *   bytes into a file of 12, and a family emoji comes out as 8 of its 18. This reports what
 *   `file_put_contents()` actually wrote. Same family as the editor's cursor and `Fuzzy`'s
 *   walk: a JavaScript `length` is not a byte count, and porting the expression instead of
 *   the unit is how that stays invisible for as long as the text is ASCII.
 * - **A path that is a directory is refused by name**, where upstream lets `writeFile` fail
 *   with whatever Node calls `EISDIR`.
 * - **One abort check, at the door.** Upstream has three, between its `await`s. Nothing here
 *   suspends between them — `mkdir` and `file_put_contents` are synchronous, so no other
 *   fiber can run and no signal can change — which makes a second check a line that cannot
 *   fire. `EditTool` keeps upstream's mid-way one; it is inert there for the same reason.
 */
final class WriteTool implements AgentTool
{
    public function __construct(private readonly string $cwd)
    {
    }

    #[\Override]
    public function definition(): Tool
    {
        return new Tool(
            'write',
            'Write content to a file. Creates it if it does not exist and overwrites it if it does, '
                . 'so use edit to change part of an existing file. Parent directories are created as needed.',
            [
                'type' => 'object',
                'properties' => [
                    'path' => ['type' => 'string', 'description' => 'Path to the file, relative or absolute'],
                    'content' => ['type' => 'string', 'description' => 'What to write'],
                ],
                'required' => ['path', 'content'],
            ],
        );
    }

    #[\Override]
    public function label(): string
    {
        return 'Write';
    }

    #[\Override]
    public function execute(
        string $toolCallId,
        array $arguments,
        ?AbortSignal $signal = null,
        ?Closure $onUpdate = null,
    ): AgentToolResult {
        $signal?->throwIfAborted();

        $path = (string) $arguments['path'];
        $content = (string) $arguments['content'];
        $absolute = Paths::resolve($path, $this->cwd);
        $directory = dirname($absolute);

        if (!is_dir($directory) && !mkdir($directory, 0o755, true) && !is_dir($directory)) {
            throw new AgentError("Could not create directory {$directory}");
        }

        if (is_dir($absolute)) {
            throw new AgentError("{$path} is a directory");
        }

        $written = file_put_contents($absolute, $content);

        if ($written === false) {
            throw new AgentError("Could not write {$path}");
        }

        return new AgentToolResult([new TextContent("Wrote {$written} bytes to {$path}")]);
    }
}
