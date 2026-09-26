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
 * List a directory.
 *
 * Dotfiles included, directories marked with a trailing slash, sorted the way a person
 * reads rather than the way the filesystem returns them. One level only — walking a tree
 * is `find`, and a model that asks for a listing usually wants to know what is here.
 */
final class LsTool implements AgentTool
{
    private const int DEFAULT_LIMIT = 500;

    public function __construct(private readonly string $cwd)
    {
    }

    #[\Override]
    public function definition(): Tool
    {
        $limit = self::DEFAULT_LIMIT;
        $bytes = Truncate::size(Truncate::MAX_BYTES);

        return new Tool(
            'ls',
            "List the contents of a directory, sorted alphabetically, with a trailing '/' on "
                . "directories. Dotfiles are included. Cut off at {$limit} entries or {$bytes}, "
                . 'whichever comes first.',
            [
                'type' => 'object',
                'properties' => [
                    'path' => ['type' => 'string', 'description' => 'Directory to list; defaults to the working directory'],
                    'limit' => ['type' => 'number', 'description' => 'Most entries to return'],
                ],
                'required' => [],
            ],
        );
    }

    #[\Override]
    public function label(): string
    {
        return 'List';
    }

    #[\Override]
    public function execute(
        string $toolCallId,
        array $arguments,
        ?AbortSignal $signal = null,
        ?Closure $onUpdate = null,
    ): AgentToolResult {
        $signal?->throwIfAborted();

        $path = (string) ($arguments['path'] ?? '.');
        $absolute = Paths::resolve($path, $this->cwd);
        $limit = max(1, (int) ($arguments['limit'] ?? self::DEFAULT_LIMIT));

        if (!file_exists($absolute)) {
            throw new AgentError("No such directory: {$path}");
        }

        if (!is_dir($absolute)) {
            throw new AgentError("Not a directory: {$path}");
        }

        $entries = scandir($absolute);

        if ($entries === false) {
            throw new AgentError("Could not read directory: {$path}");
        }

        $names = array_values(array_diff($entries, ['.', '..']));

        // Case-insensitive, so `README` and `src` sort where a person would look for them
        // rather than in two groups by case.
        usort($names, static fn (string $a, string $b): int => strcasecmp($a, $b) ?: strcmp($a, $b));

        $listed = [];
        $hitLimit = false;

        foreach ($names as $name) {
            if (count($listed) >= $limit) {
                $hitLimit = true;

                break;
            }

            $listed[] = $name . (is_dir($absolute . '/' . $name) ? '/' : '');
        }

        if ($listed === []) {
            return new AgentToolResult([new TextContent('(empty directory)')]);
        }

        // No line limit: the entry limit above already caps how many there are.
        $truncation = Truncate::head(implode("\n", $listed), PHP_INT_MAX);
        $notices = [];

        if ($hitLimit) {
            $doubled = $limit * 2;
            $notices[] = "{$limit} entry limit reached. Use limit={$doubled} for more";
        }

        if ($truncation->truncated) {
            $notices[] = Truncate::size(Truncate::MAX_BYTES) . ' limit reached';
        }

        $notice = $notices === [] ? null : implode('. ', $notices);

        // In the text for the model, and in the details for the transcript: the collapsed view
        // keeps the front of the output, and this line sits at the end of it.
        $output = $notice === null ? $truncation->content : $truncation->content . "\n\n[{$notice}]";

        // Upstream's keys, because `details` ends up in the session file and that file is pi's:
        // `entryLimitReached` is the limit that was hit, which is what pi's own tool view reads.
        // `notice` is pig's and has no counterpart there — see CLAUDE.md.
        return new AgentToolResult(
            [new TextContent($output)],
            $notice === null ? null : array_filter(
                [
                    'entryLimitReached' => $hitLimit ? $limit : null,
                    'truncation' => $truncation,
                    'notice' => $notice,
                ],
                static fn (mixed $value): bool => $value !== null,
            ),
        );
    }
}
