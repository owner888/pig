<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Tools;

use Closure;
use Pig\Agent\AgentError;
use Pig\Agent\AgentTool;
use Pig\Agent\AgentToolResult;
use Pig\Agent\ReplayableTool;
use Pig\Agent\ToolReplay;
use Pig\Ai\ImageContent;
use Pig\Ai\TextContent;
use Pig\Ai\Tool;
use Pig\Async\AbortSignal;
use Pig\Async\Async;
use Pig\CodingAgent\Utils\ImageProcess;

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
final class ReadTool implements AgentTool, ReplayableTool
{
    /**
     * @param bool $autoResizeImages upstream's `autoResizeImages` (`images.autoResize`)
     * @param (Closure(): ?array{maxWidth?: int, maxHeight?: int, maxBytes?: int, jpegQuality?: int})|null $resizeOptions
     *        the model's `inputLimits.images.resize`, asked at each read because the model can change
     */
    private readonly ReadOperations $operations;

    /**
     * @param int $chunkBytes how much one read of the default operations' stream takes in
     * @param ReadOperations|null $operations upstream's `operations`: where the file is read from;
     *        this machine (`LocalReadOperations`) when null
     */
    public function __construct(
        private readonly string $cwd,
        private readonly bool $autoResizeImages = true,
        private readonly ?Closure $resizeOptions = null,
        int $chunkBytes = self::CHUNK_BYTES,
        ?ReadOperations $operations = null,
    ) {
        $this->operations = $operations ?? new LocalReadOperations($chunkBytes);
    }

    /** How much of a file one read takes in: big, because a part only counted is counted in C. */
    public const int CHUNK_BYTES = 1 << 20;

    /** How much is read between two looks at the signal, and two turns of the loop. */
    private const int YIELD_EVERY_BYTES = 8 << 20;

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
            // Upstream's `constrainedSampling: { type: "json_schema", strict: "prefer" }` on bash,
            // edit, read and write (and on none of grep, find or ls): strict sampling where the
            // provider has it, the schema as written where it does not. Under strict sampling an
            // optional parameter arrives as null, which `ToolArguments` drops before validation.
            ['type' => 'json_schema', 'strict' => 'prefer'],
        );
    }

    /** It only reads, so a call a stopped process left half done is run again. */
    #[\Override]
    public function replay(): ToolReplay
    {
        return ToolReplay::Safe;
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

        try {
            $this->operations->access($absolute);
        } catch (FileAccessError $error) {
            throw new AgentError($error->kind === FileAccessError::NOT_FOUND ? "No such file: {$path}" : "Not readable: {$path}");
        }

        $mimeType = $this->operations->detectImageMimeType($absolute);

        if ($mimeType !== null) {
            return $this->image($absolute, $mimeType);
        }

        return $this->text($absolute, $path, $arguments, $signal);
    }

    private function image(string $absolute, string $mimeType): AgentToolResult
    {
        try {
            $bytes = $this->operations->readFile($absolute);
        } catch (FileAccessError $error) {
            throw new AgentError($error->explain("Could not read {$absolute}"));
        }

        // Upstream's `processImage()` with the read's own wording: an image that cannot be fitted
        // inside the limits is not attached, and the text says why; one that was resized says how.
        $processed = ImageProcess::process($bytes, $mimeType, $this->autoResizeImages, $this->resizeOptions !== null ? ($this->resizeOptions)() : null);

        if (!$processed['ok']) {
            return new AgentToolResult([new TextContent("Read image file [{$mimeType}]\n{$processed['message']}")]);
        }

        $note = "Read image file [{$processed['mimeType']}]";

        if ($processed['hints'] !== []) {
            $note .= "\n" . implode("\n", $processed['hints']);
        }

        return new AgentToolResult([
            new TextContent($note),
            new ImageContent($processed['data'], $processed['mimeType']),
        ]);
    }

    /** @param array<string, mixed> $arguments */
    private function text(string $absolute, string $path, array $arguments, ?AbortSignal $signal): AgentToolResult
    {
        $offset = isset($arguments['offset']) ? max(1, (int) $arguments['offset']) : 1;
        $start = $offset - 1;
        $limit = isset($arguments['limit']) ? max(0, (int) $arguments['limit']) : null;

        $scan = $this->scan($absolute, $start, $limit, $signal);
        $total = $scan['totalLines'];

        if ($start >= $total) {
            throw new AgentError("Offset {$offset} is past the end of the file, which has {$total} lines");
        }

        $truncation = Truncate::headOf($scan['prefix'], $scan['selectedLines'], $scan['selectedBytes'], $scan['firstLineBytes']);
        $notice = $this->notice($truncation, $total, $scan['firstLineBytes'], $path, $offset, $limit);

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
     * The file read once, front to back, holding only what the answer can show — upstream's
     * streaming `read` (#11750), which loaded the whole file before applying `offset` / `limit`.
     * So did this, as `file_get_contents()` and `explode()`: a one-line read of a 200 MB log held
     * it three times over, past a `memory_limit` a fatal error nothing catches, and the loop stood
     * still for the whole of it, escape included.
     *
     * In chunks of `$chunkBytes`. Lines are split on the byte `\n`, which never occurs inside a
     * UTF-8 character, so there is nothing to decode — upstream's streaming `TextDecoder` has no
     * counterpart. The lines before `offset` and after what can be shown are only counted, with
     * `substr_count()`; only the selected lines the truncation can keep are stored, and one more
     * (see `Truncate::headOf()`), each cut at `MAX_BYTES + 1` since a longer one is never shown —
     * its length is what counts, and the selection's length is two positions in the file.
     *
     * A regular file is read up to the size it had when opened, as `fs.readFile` reads it: text
     * appended meanwhile is not counted, and a file that keeps growing still ends. One that says
     * it is empty (`/proc`) is read to EOF. Every few megabytes the signal is looked at and, in a
     * coroutine, the loop gets a turn.
     *
     * @return array{totalLines: int, selectedLines: int, selectedBytes: int, firstLineBytes: int, prefix: list<string>}
     */
    private function scan(string $absolute, int $start, ?int $limit, ?AbortSignal $signal): array
    {
        // The bytes come from the operations' stream (`LocalReadOperations` reads a regular file
        // up to the size it had when opened, as `fs.readFile` does); this only counts and keeps.
        $chunks = $this->operations->createReadStream($absolute, $signal);

        $end = $limit === null ? PHP_INT_MAX : $start + $limit;
        $maxLines = Truncate::MAX_LINES;
        $maxBytes = Truncate::MAX_BYTES;

        $line = 0;            // the line being read, from 0
        $read = 0;            // bytes consumed so far
        $startAt = $start === 0 ? 0 : null; // where the first selected line begins
        $endAt = null;        // where the last selected line ends
        $firstLineBytes = null;
        $prefix = [];
        $prefixBytes = 0;
        $storing = true;
        $current = '';        // the selected line being read, cut at $maxBytes + 1
        $sinceYield = 0;

        foreach ($chunks as $data) {
            $length = strlen($data);
            $at = 0;

            while ($at < $length) {
                if ($line < $start) {
                    // Before the selection: count, and find where it begins only when it is here.
                    $needed = $start - $line;
                    $newlines = substr_count($data, "\n", $at);

                    if ($newlines < $needed) {
                        $line += $newlines;
                        break;
                    }

                    for ($i = 0; $i < $needed; $i++) {
                        $at = (int) strpos($data, "\n", $at) + 1;
                    }

                    $line = $start;
                    $startAt = $read + $at;
                    continue;
                }

                if ($line >= $end) {
                    $line += substr_count($data, "\n", $at);
                    break;
                }

                if (!$storing) {
                    // Past what can be shown: only where the selection ends still matters.
                    $needed = $end - $line;
                    $newlines = substr_count($data, "\n", $at);

                    if ($newlines < $needed) {
                        $line += $newlines;
                        break;
                    }

                    for ($i = 0; $i < $needed; $i++) {
                        $at = (int) strpos($data, "\n", $at) + 1;
                    }

                    $line = $end;
                    $endAt = $read + $at - 1;
                    continue;
                }

                // A selected line, of which as much as could be shown is kept.
                $newline = strpos($data, "\n", $at);
                $piece = $newline === false ? substr($data, $at) : substr($data, $at, $newline - $at);

                if ($storing && strlen($current) <= $maxBytes) {
                    $current .= substr($piece, 0, $maxBytes + 1 - strlen($current));
                }

                if ($newline === false) {
                    break;
                }

                $this->finishLine($read + $newline, $line, $start, $startAt, $firstLineBytes, $current, $storing, $prefix, $prefixBytes, $maxLines, $maxBytes);
                $line++;
                $at = $newline + 1;

                if ($line === $end) {
                    $endAt = $read + $newline;
                }
            }

            $read += $length;
            $sinceYield += $length;

            if ($sinceYield >= self::YIELD_EVERY_BYTES) {
                $sinceYield = 0;
                $signal?->throwIfAborted();

                if (\Fiber::getCurrent() !== null) {
                    Async::delay(0);
                }

                $signal?->throwIfAborted();
            }
        }

        // The end of the file ends the line being read: `explode()` counts one more line than
        // there are newlines, the empty one after a final newline included.
        $totalLines = $line + 1;

        // Past the end (an error), or `limit: 0`: nothing selected, which `explode('')` sees as
        // one empty line of no bytes.
        if ($start >= $totalLines || $end <= $start) {
            return ['totalLines' => $totalLines, 'selectedLines' => 1, 'selectedBytes' => 0, 'firstLineBytes' => 0, 'prefix' => ['']];
        }

        if ($endAt === null) {
            if ($firstLineBytes === null || $storing) {
                $this->finishLine($read, $line, $start, $startAt, $firstLineBytes, $current, $storing, $prefix, $prefixBytes, $maxLines, $maxBytes);
            }

            $endAt = $read;
        }

        return [
            'totalLines' => $totalLines,
            'selectedLines' => min($end, $totalLines) - $start,
            'selectedBytes' => $endAt - (int) $startAt,
            'firstLineBytes' => (int) $firstLineBytes,
            'prefix' => $prefix,
        ];
    }

    /**
     * A selected line has ended at byte $endsAt: its length if it is the first, and into the
     * prefix while the prefix is still short of what the truncation can keep.
     *
     * @param list<string> $prefix
     */
    private function finishLine(
        int $endsAt,
        int $line,
        int $start,
        ?int $startAt,
        ?int &$firstLineBytes,
        string &$current,
        bool &$storing,
        array &$prefix,
        int &$prefixBytes,
        int $maxLines,
        int $maxBytes,
    ): void {
        if ($line === $start) {
            $firstLineBytes = $endsAt - (int) $startAt;
        }

        if ($storing) {
            $prefixBytes += strlen($current) + ($prefix === [] ? 0 : 1);
            $prefix[] = $current;

            // One past either limit is enough for `Truncate::headOf()` to see why it stopped.
            if (count($prefix) > $maxLines || $prefixBytes > $maxBytes) {
                $storing = false;
            }
        }

        $current = '';
    }

    /**
     * What was left out and how to get it, or null when nothing was.
     *
     * "Showing lines 1-2000 of 8431, use offset=2001" is a next step; "output truncated"
     * is a dead end that costs another turn to get past.
     *
     */
    private function notice(Truncation $truncation, int $total, int $firstLineBytes, string $path, int $offset, ?int $limit): ?string
    {
        $start = $offset - 1;

        if ($truncation->firstLineExceedsLimit) {
            // One line longer than the whole budget. There is nothing useful to send, so
            // send the command that gets a piece of it instead.
            $size = Truncate::size($firstLineBytes);
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
