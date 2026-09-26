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
 *
 * Run against upstream's `read.ts` over 193 cases — every offset and limit around the ends of
 * a file, both truncation limits and the line that is over the budget by itself, the six image
 * signatures, and 120 random files with random offsets and limits. **Every field of
 * `details.truncation` agrees in all 62 cases that have one**, byte counts and
 * `lastLinePartial` included, and the text agrees except in five places, each deliberate:
 *
 * - **A bad offset or limit is clamped and made whole.** Upstream carries a fraction through
 *   into its own notice (`Use offset=2.5 to continue`) and a negative limit into `slice()`,
 *   where `limit: -1` quietly reads all but the last line. The schema says `number`, so a
 *   model can send either — and here the cast is load-bearing rather than tidy, because
 *   `array_slice()` takes `int` and a float is a `TypeError` rather than a rounded read.
 * - **An empty read's notice has no blank line in front of it.** Upstream appends `\n\n` to
 *   content that is the empty string.
 * - **The errors are this file's words**, and the missing-file one is the reason: upstream
 *   lets Node's `access` throw, so the model is handed `ENOENT: … access '/tmp/x/…'` — an
 *   absolute path it never wrote, and an errno it can do nothing with.
 * - **Bytes that are not UTF-8 stay as they are.** Upstream reads with `readFile(path,
 *   "utf-8")` and every such byte reaches the model as U+FFFD. Sanitising happens once, at
 *   the request boundary, where the four providers already do it.
 * - **A bare `GIF` is not a GIF.** `file-type`, which upstream asks, accepts those three
 *   bytes; `ImageType` wants `GIF87a` or `GIF89a`, as a real GIF has.
 *
 * `details.notice` is this file's own key and the one case upstream leaves empty — see the
 * note on `notice()`.
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
        $notice = $this->notice($truncation, $lines, $path, $offset, $limit);

        // The notice goes in the text *and* in the details, because it has two readers: the
        // model reads the text, and the transcript's collapsed view keeps the front of the
        // output — where this line, which sits at the end, is never on screen.
        $text = $notice === null
            ? $truncation->content
            : ($truncation->content === '' ? "[{$notice}]" : $truncation->content . "\n\n[{$notice}]");

        // `truncation` only when something was actually cut, which is upstream's shape: a read
        // that fitted has no details there either. `notice` is pig's own key, and it is set in
        // one case upstream leaves empty — a `limit` that stopped short of the end of the file,
        // where the model is told and the transcript should be too.
        $details = array_filter(
            [
                'truncation' => $truncation->truncated ? $truncation : null,
                'notice' => $notice,
            ],
            static fn (mixed $value): bool => $value !== null,
        );

        return new AgentToolResult(
            [new TextContent($text)],
            $details === [] ? null : $details,
        );
    }

    /**
     * What was left out and how to get it, or null when nothing was.
     *
     * "Showing lines 1-2000 of 8431, use offset=2001" is a next step; "output truncated"
     * is a dead end that costs another turn to get past.
     *
     * @param list<string> $lines
     */
    private function notice(Truncation $truncation, array $lines, string $path, int $offset, ?int $limit): ?string
    {
        $total = count($lines);
        $start = $offset - 1;

        if ($truncation->firstLineExceedsLimit) {
            // One line longer than the whole budget. There is nothing useful to send, so
            // send the command that gets a piece of it instead.
            $size = Truncate::size(strlen($lines[$start]));
            $cap = Truncate::MAX_BYTES;

            return "Line {$offset} is {$size}, over the " . Truncate::size($cap) . ' limit. '
                . "Use bash: sed -n '{$offset}p' {$path} | head -c {$cap}";
        }

        if ($truncation->truncated) {
            $last = $offset + $truncation->outputLines - 1;
            $next = $last + 1;
            $why = $truncation->truncatedBy === 'lines' ? '' : ' (' . Truncate::size(Truncate::MAX_BYTES) . ' limit)';

            return "Showing lines {$offset}-{$last} of {$total}{$why}. Use offset={$next} to continue";
        }

        if ($limit !== null && $start + $limit < $total) {
            $remaining = $total - ($start + $limit);
            $next = $start + $limit + 1;

            return "{$remaining} more lines in file. Use offset={$next} to continue";
        }

        return null;
    }
}
