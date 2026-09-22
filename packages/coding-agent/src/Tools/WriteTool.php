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
