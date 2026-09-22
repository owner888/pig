<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Tools;

use Closure;
use Pig\Agent\AgentError;
use Pig\Agent\AgentTool;
use Pig\Agent\AgentToolResult;
use Pig\Ai\ImageContent;
use Pig\Ai\TextContent;
use Pig\Ai\Tool;
use Pig\Async\AbortSignal;
use Pig\Tui\Images\ImageType;

/**
 * Read a file.
 *
 * Two things make this more than `file_get_contents`. An image comes back as an image
 * block rather than as bytes, so the model can look at it. And the output is cut to
 * something worth sending, with a line saying exactly where to resume — the model's next
 * move after a truncated read should be obvious from the read itself.
 */
final class ReadTool implements AgentTool
{
    public function __construct(private readonly string $cwd)
    {
    }

    #[\Override]
    public function definition(): Tool
    {
        $lines = Truncate::MAX_LINES;
        $bytes = Truncate::size(Truncate::MAX_BYTES);

        return new Tool(
            'read',
            "Read the contents of a file. Handles text and images (png, jpeg, gif, webp); "
                . "images are returned as attachments you can look at. Text output is cut off at "
                . "{$lines} lines or {$bytes}, whichever comes first — use offset and limit to read "
                . 'the rest of a large file.',
            [
                'type' => 'object',
                'properties' => [
                    'path' => ['type' => 'string', 'description' => 'Path to the file, relative or absolute'],
                    'offset' => ['type' => 'number', 'description' => 'Line to start at, counting from 1'],
                    'limit' => ['type' => 'number', 'description' => 'How many lines to read'],
                ],
                'required' => ['path'],
            ],
        );
    }

    #[\Override]
    public function label(): string
    {
        return 'Read';
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
        $absolute = Paths::resolveForRead($path, $this->cwd);

        if (!is_file($absolute)) {
            throw new AgentError("No such file: {$path}");
        }

        if (!is_readable($absolute)) {
            throw new AgentError("Not readable: {$path}");
        }

        $mimeType = ImageType::ofFile($absolute);

        if ($mimeType !== null) {
            return $this->image($absolute, $mimeType);
        }

        return $this->text($absolute, $path, $arguments);
    }

    private function image(string $absolute, string $mimeType): AgentToolResult
    {
        $bytes = file_get_contents($absolute);

        if ($bytes === false) {
            throw new AgentError("Could not read {$absolute}");
        }

        return new AgentToolResult([
            new TextContent("Read image file [{$mimeType}]"),
            new ImageContent(base64_encode($bytes), $mimeType),
        ]);
    }

    /** @param array<string, mixed> $arguments */
    private function text(string $absolute, string $path, array $arguments): AgentToolResult
    {
        $contents = file_get_contents($absolute);

        if ($contents === false) {
            throw new AgentError("Could not read {$absolute}");
        }

        $lines = explode("\n", $contents);
        $total = count($lines);
        $offset = isset($arguments['offset']) ? max(1, (int) $arguments['offset']) : 1;
        $start = $offset - 1;

        if ($start >= $total) {
            throw new AgentError("Offset {$offset} is past the end of the file, which has {$total} lines");
        }

        $limit = isset($arguments['limit']) ? max(0, (int) $arguments['limit']) : null;
        $selected = $limit === null ? array_slice($lines, $start) : array_slice($lines, $start, $limit);
        $truncation = Truncate::head(implode("\n", $selected));

        return new AgentToolResult(
            [new TextContent($this->render($truncation, $lines, $path, $offset, $limit))],
            $truncation,
        );
    }

    /**
     * The text, plus a line saying what was left out and how to get it.
     *
     * "Showing lines 1-2000 of 8431, use offset=2001" is a next step; "output truncated"
     * is a dead end that costs another turn to get past.
     *
     * @param list<string> $lines
     */
    private function render(Truncation $truncation, array $lines, string $path, int $offset, ?int $limit): string
    {
        $total = count($lines);
        $start = $offset - 1;

        if ($truncation->firstTooBig) {
            // One line longer than the whole budget. There is nothing useful to send, so
            // send the command that gets a piece of it instead.
            $size = Truncate::size(strlen($lines[$start]));
            $cap = Truncate::MAX_BYTES;

            return "[Line {$offset} is {$size}, over the " . Truncate::size($cap) . ' limit. '
                . "Use bash: sed -n '{$offset}p' {$path} | head -c {$cap}]";
        }

        if ($truncation->truncated) {
            $last = $offset + $truncation->outputLines - 1;
            $next = $last + 1;
            $why = $truncation->by === 'lines' ? '' : ' (' . Truncate::size(Truncate::MAX_BYTES) . ' limit)';

            return $truncation->content
                . "\n\n[Showing lines {$offset}-{$last} of {$total}{$why}. Use offset={$next} to continue]";
        }

        if ($limit !== null && $start + $limit < $total) {
            $remaining = $total - ($start + $limit);
            $next = $start + $limit + 1;

            return $truncation->content . "\n\n[{$remaining} more lines in file. Use offset={$next} to continue]";
        }

        return $truncation->content;
    }
}
