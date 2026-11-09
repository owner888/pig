<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Tools;

use Closure;
use Pig\Agent\AgentError;
use Pig\Agent\AgentTool;
use Pig\Agent\AgentToolResult;
use Pig\Agent\PreparesArguments;
use Pig\Ai\TextContent;
use Pig\Ai\Tool;
use Pig\Async\AbortSignal;

/**
 * Change parts of a file by replacing exact text — upstream's `edits[]` shape, one or more
 * `{oldText, newText}` pairs per call, every one matched against the file as it was before the
 * call (`EditDiff::apply()`).
 *
 * Each text must appear exactly once. Both halves of that are deliberate: matching
 * nothing means the model is working from something other than the file as it is, and
 * matching twice means it does not know which one it meant. Either way the right answer
 * is to say so and let it read the file again, not to guess and edit the wrong line.
 *
 * Run against upstream's `edit.ts` over 307 cases — BOM, every mix of line endings, a
 * lone CR, overlapping matches, `$1` in the replacement, invalid UTF-8, and 260 random
 * files with a random slice of each as `oldText`. **The bytes on disk agree everywhere but
 * the three cases below**, and the diffs differ only in the three ways `EditDiff::render()`
 * already documents. That run was of the single-edit tool; each of the four deviations below
 * applies to every edit of a call. Four deviations, all deliberate:
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
final class EditTool implements AgentTool, PreparesArguments
{
    public function __construct(private readonly string $cwd)
    {
    }

    #[\Override]
    public function definition(): Tool
    {
        return new Tool(
            'edit',
            'Edit a file by replacing exact text. Each edits[].oldText must match the original file '
                . 'exactly, whitespace and all, and appear exactly once — include the surrounding lines '
                . 'if it does not. Edits are matched against the file as it was, not one after another, '
                . 'and must not overlap: merge changes to the same or nearby lines into one edit, without '
                . 'large unchanged regions in between. Use write to replace a whole file.',
            [
                'type' => 'object',
                'properties' => [
                    'path' => ['type' => 'string', 'description' => 'Path to the file, relative or absolute'],
                    'edits' => [
                        'type' => 'array',
                        'description' => 'One or more replacements, each matched against the original file. '
                            . 'No two may overlap; merge changes to the same or nearby lines into one.',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'oldText' => [
                                    'type' => 'string',
                                    'description' => 'The exact text to replace, unique in the original file',
                                ],
                                'newText' => ['type' => 'string', 'description' => 'What to put in its place'],
                            ],
                            'required' => ['oldText', 'newText'],
                        ],
                    ],
                ],
                'required' => ['path', 'edits'],
            ],
            // Upstream's `constrainedSampling: { type: "json_schema", strict: "prefer" }` on bash,
            // edit, read and write (and on none of grep, find or ls): strict sampling where the
            // provider has it, the schema as written where it does not. Under strict sampling an
            // optional parameter arrives as null, which `ToolArguments` drops before validation.
            ['type' => 'json_schema', 'strict' => 'prefer'],
        );
    }

    /**
     * Upstream's `prepareEditArguments()`: arguments in an older or looser shape, made into
     * `edits[]` before the schema sees them.
     *
     * - `edits` as a JSON string, as some models send it, is decoded: a list is used as it is,
     *   one `{oldText, newText}` object becomes a list of one. Anything else is left for the
     *   schema to refuse.
     * - `edits` as one object rather than a list of one becomes that list.
     * - Top-level `oldText` and `newText` — the shape this tool had before, which a saved
     *   session's calls still have — become one more edit, after any in `edits`.
     *
     * Public and static because the renderers read a call's arguments the same way: a session
     * saved before `edits[]` must still draw its edits (`ToolExecutionComponent`).
     *
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    #[\Override]
    public function prepareArguments(array $arguments): array
    {
        return self::prepare($arguments);
    }

    /**
     * `prepareArguments()`, for a caller without a tool.
     *
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public static function prepare(array $arguments): array
    {
        $edits = $arguments['edits'] ?? null;

        if (is_string($edits)) {
            $parsed = json_decode($edits, true);

            // `json_decode()` gives an array for a JSON object too; only `[…]` is a list.
            if (is_array($parsed) && str_starts_with(ltrim($edits), '[')) {
                $arguments['edits'] = $parsed;
            } elseif (self::isSingleEdit($parsed)) {
                $arguments['edits'] = [$parsed];
            }
        } elseif (self::isSingleEdit($edits)) {
            $arguments['edits'] = [$edits];
        }

        $oldText = $arguments['oldText'] ?? null;
        $newText = $arguments['newText'] ?? null;

        if (!is_string($oldText) || !is_string($newText)) {
            return $arguments;
        }

        $edits = is_array($arguments['edits'] ?? null) && array_is_list($arguments['edits']) ? $arguments['edits'] : [];
        $edits[] = ['oldText' => $oldText, 'newText' => $newText];
        unset($arguments['oldText'], $arguments['newText']);
        $arguments['edits'] = $edits;

        return $arguments;
    }

    /** One `{oldText, newText}` object with both texts strings — upstream's `isSingleEditInput()`. */
    private static function isSingleEdit(mixed $value): bool
    {
        return is_array($value)
            && ($value === [] || !array_is_list($value))
            && is_string($value['oldText'] ?? null)
            && is_string($value['newText'] ?? null);
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

        // Upstream's `validateEditInput()`: the schema has no `minItems`, so an empty list gets here.
        $edits = $arguments['edits'] ?? null;

        if (!is_array($edits) || $edits === []) {
            throw new AgentError('Edit tool input is invalid. edits must contain at least one replacement.');
        }

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

        // Matched with LF throughout, so a model that wrote `\n` can still edit a file saved
        // with CRLF — and only the replaced ranges are written back, so every other line keeps
        // the ending it had (upstream #11751). A file with no CR is the LF text as it is.
        $old = EditDiff::toLf($content);

        [$new, $replacements] = EditDiff::apply($old, array_values($edits), $path);

        // The diff before the write, as upstream now has it: a failure drawing it leaves the
        // file as it was rather than changed with nothing said about it.
        [$diff, $firstChangedLine] = EditDiff::renderEdits($old, $new, $replacements);
        $written = str_contains($content, "\r") ? EditDiff::applyToOriginal($content, $replacements) : $new;

        $signal?->throwIfAborted();

        if (file_put_contents($absolute, $bom . $written) === false) {
            throw new AgentError("Could not write {$path}");
        }

        return new AgentToolResult(
            [new TextContent('Successfully replaced ' . count($edits) . " block(s) in {$path}.")],
            ['diff' => $diff, 'firstChangedLine' => $firstChangedLine],
        );
    }

}
