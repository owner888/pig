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

        $new = $this->replace($old, $find, $replace, $path);

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

    /** The content with the one occurrence swapped, or an error explaining why not. */
    private function replace(string $content, string $find, string $replace, string $path): string
    {
        if ($find === '') {
            throw new AgentError('oldText is empty. Give the exact text to replace.');
        }

        $count = substr_count($content, $find);

        if ($count === 0) {
            throw new AgentError(
                "Could not find that exact text in {$path}. It must match the file exactly, "
                    . 'including whitespace and line breaks — read the file and copy the text from it.',
            );
        }

        if ($count > 1) {
            throw new AgentError(
                "Found {$count} occurrences of that text in {$path}. It has to be unique — "
                    . 'include the lines around it so there is only one match.',
            );
        }

        $at = strpos($content, $find);

        // Spliced rather than replaced: str_replace is fine, but preg_replace and friends
        // read `$1` in a replacement, and a model editing a shell script or a regex will
        // sooner or later hand one over.
        $new = substr($content, 0, (int) $at) . $replace . substr($content, (int) $at + strlen($find));

        if ($new === $content) {
            throw new AgentError("No change made to {$path}: newText is identical to oldText.");
        }

        return $new;
    }
}
