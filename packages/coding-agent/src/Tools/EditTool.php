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
 * Change part of a file by replacing exact text.
 *
 * The text must appear exactly once. Both halves of that are deliberate: matching
 * nothing means the model is working from something other than the file as it is, and
 * matching twice means it does not know which one it meant. Either way the right answer
 * is to say so and let it read the file again, not to guess and edit the wrong line.
 *
 * Run against upstream's `edit.ts` over 307 cases — BOM, every mix of line endings, a
 * lone CR, overlapping matches, `$1` in the replacement, invalid UTF-8, and 260 random
 * files with a random slice of each as `oldText`. **The bytes on disk agree everywhere but
 * the three cases below**, and the diffs differ only in the three ways `EditDiff::render()`
 * already documents. Four deviations, all deliberate:
 *
 * - **Bytes that are not UTF-8 survive an edit.** Upstream reads with
 *   `readFile(path, "utf-8")`, so every such byte becomes U+FFFD *and is written back that
 *   way*: one stray byte in a latin-1 file, and an edit to line 90 rewrites line 3 as well.
 *   This reads bytes and splices bytes, so the untouched part of the file is untouched.
 * - **An empty `oldText` is refused.** Upstream's `includes('')` is true and its count is
 *   `length - 1`, so a non-empty file is told "Found N occurrences" and an *empty* one has
 *   the text inserted — replacing nothing with something, from a tool whose whole contract
 *   is that the text appears exactly once.
 * - **A missing file and an unreadable one are told apart.** Upstream folds both into
 *   `File not found`, and its `access(R_OK | W_OK)` passes for a *directory*, which then
 *   fails on the read with whatever Node calls `EISDIR`.
 * - **The wording is this file's.** Same three refusals, in the same three places, worded
 *   to say what to do about it — the reader is the model, and it is about to correct itself
 *   from the sentence.
 */
final class EditTool implements AgentTool
{
    public function __construct(private readonly string $cwd)
    {
    }

    #[\Override]
    public function definition(): Tool
    {
        return new Tool(
            'edit',
            'Edit a file by replacing exact text. oldText must match the file exactly, whitespace '
                . 'and all, and must appear exactly once — include the surrounding lines if it does not. '
                . 'Use this for surgical changes; use write to replace a whole file.',
            [
                'type' => 'object',
                'properties' => [
                    'path' => ['type' => 'string', 'description' => 'Path to the file, relative or absolute'],
                    'oldText' => ['type' => 'string', 'description' => 'The exact text to replace'],
                    'newText' => ['type' => 'string', 'description' => 'What to put in its place'],
                ],
                'required' => ['path', 'oldText', 'newText'],
            ],
        );
    }

    #[\Override]
    public function label(): string
    {
        return 'Edit';
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
        $absolute = Paths::resolve($path, $this->cwd);

        if (!is_file($absolute)) {
            throw new AgentError("File not found: {$path}");
        }

        if (!is_readable($absolute) || !is_writable($absolute)) {
            throw new AgentError("Not readable and writable: {$path}");
        }

        $raw = file_get_contents($absolute);

        if ($raw === false) {
            throw new AgentError("Could not read {$path}");
        }

        [$bom, $content] = EditDiff::splitBom($raw);
        $ending = EditDiff::lineEnding($content);

        // Matched with LF throughout, so a model that wrote `\n` can still edit a file
        // saved with CRLF — and the file gets its own endings back on the way out.
        $old = EditDiff::toLf($content);
        $find = EditDiff::toLf((string) $arguments['oldText']);
        $replace = EditDiff::toLf((string) $arguments['newText']);

        $new = EditDiff::apply($old, $find, $replace, $path);

        $signal?->throwIfAborted();

        if (file_put_contents($absolute, $bom . EditDiff::restore($new, $ending)) === false) {
            throw new AgentError("Could not write {$path}");
        }

        [$diff, $firstChangedLine] = EditDiff::render($old, $new);

        return new AgentToolResult(
            [new TextContent("Replaced text in {$path}.")],
            ['diff' => $diff, 'firstChangedLine' => $firstChangedLine],
        );
    }

}
