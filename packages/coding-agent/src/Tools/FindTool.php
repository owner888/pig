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
use Pig\Tui\Process;

/**
 * Find files by name, with `fd`.
 *
 * `fd` is required rather than reimplemented: it is multithreaded, it gets `.gitignore`
 * exactly right, and a search tool that behaves slightly differently from the one the
 * upstream agent was built against is a source of confusion nobody needs. A machine
 * without it gets an error saying how to install it — see `ExternalTool`.
 */
final class FindTool implements AgentTool
{
    private const int DEFAULT_LIMIT = 1000;

    /** A search across a large tree, with a ceiling so one cannot hang the agent. */
    private const float TIMEOUT = 30.0;

    public function __construct(private readonly string $cwd)
    {
    }

    #[\Override]
    public function definition(): Tool
    {
        $limit = self::DEFAULT_LIMIT;
        $bytes = Truncate::size(Truncate::MAX_BYTES);

        return new Tool(
            'find',
            "Find files by glob pattern. A pattern with no '/' matches a file name at any "
                . "depth — '*.php' finds every PHP file. A pattern with a '/' matches a path — "
                . "'src/*.php' finds PHP files directly inside any src directory, and "
                . "'src/**/*Test.php' finds them at any depth under one. Returns paths relative to "
                . "the search directory. Respects .gitignore. Cut off at {$limit} results or "
                . "{$bytes}, whichever comes first.",
            [
                'type' => 'object',
                'properties' => [
                    'pattern' => ['type' => 'string', 'description' => 'Glob pattern to match against the path'],
                    'path' => ['type' => 'string', 'description' => 'Directory to search; defaults to the working directory'],
                    'limit' => ['type' => 'number', 'description' => 'Most results to return'],
                ],
                'required' => ['pattern'],
            ],
        );
    }

    #[\Override]
    public function label(): string
    {
        return 'Find';
    }

    #[\Override]
    public function execute(
        string $toolCallId,
        array $arguments,
        ?AbortSignal $signal = null,
        ?Closure $onUpdate = null,
    ): AgentToolResult {
        $signal?->throwIfAborted();

        $fd = ExternalTool::fd();
        $pattern = (string) $arguments['pattern'];
        $given = (string) ($arguments['path'] ?? '.');
        $root = rtrim(Paths::resolve($given, $this->cwd), '/');
        $limit = max(1, (int) ($arguments['limit'] ?? self::DEFAULT_LIMIT));

        if (!is_dir($root)) {
            throw new AgentError("No such directory: {$given}");
        }

        $command = [
            $fd,
            '--glob',
            '--color=never',
            ...self::matching($pattern),
            // Hidden files are searched, but .gitignore still applies: a dotfile is
            // often exactly what is being looked for, and node_modules never is.
            '--hidden',
            // Without this, fd only reads .gitignore inside a git repository, and an
            // agent gets pointed at plenty of directories that are not one.
            '--no-require-git',
            '--max-results', (string) $limit,
            self::pattern($pattern),
            $root,
        ];

        [$exit, $output, $errors] = Process::run($command, self::TIMEOUT);

        // fd exits 0 when it found nothing, so a non-zero code is a real failure — most
        // often a pattern it would not accept. Reporting that as "no files found" sends
        // the model off looking for why its search came up empty.
        if ($exit !== 0) {
            $said = trim($errors);

            throw new AgentError($said === '' ? "fd exited with code {$exit}" : $said);
        }

        $found = $this->relative($output, $root);

        if ($found === []) {
            return new AgentToolResult([new TextContent('No files found matching pattern')]);
        }

        // No line limit: --max-results already caps how many there are.
        $truncation = Truncate::head(implode("\n", $found), PHP_INT_MAX);
        $notices = [];

        if (count($found) >= $limit) {
            $doubled = $limit * 2;
            $notices[] = "{$limit} result limit reached. Use limit={$doubled} for more, or narrow the pattern";
        }

        if ($truncation->truncated) {
            $notices[] = Truncate::size(Truncate::MAX_BYTES) . ' limit reached';
        }

        $notice = $notices === [] ? null : implode('. ', $notices);

        // In the text for the model, and in the details for the transcript: the collapsed view
        // keeps the front of the output, and this line sits at the end of it.
        $output = $notice === null ? $truncation->content : $truncation->content . "\n\n[{$notice}]";

        // Upstream's keys — `details` is written to pi's session file. `notice` is pig's own.
        return new AgentToolResult(
            [new TextContent($output)],
            $notice === null ? null : array_filter(
                [
                    'resultLimitReached' => count($found) >= $limit ? $limit : null,
                    'truncation' => $truncation,
                    'notice' => $notice,
                ],
                static fn (mixed $value): bool => $value !== null,
            ),
        );
    }

    /**
     * The flags that decide what the pattern is matched against.
     *
     * fd matches a glob against the **file name** unless told otherwise, so a pattern
     * with a directory in it silently matches nothing — which is how this shipped broken
     * the first time. A pattern with a '/' in it is plainly about a path, so it gets
     * --full-path; one without is about a name, which is the more useful default for
     * '*.php'.
     *
     * @return list<string>
     */
    private static function matching(string $pattern): array
    {
        return str_contains($pattern, '/') ? ['--full-path'] : [];
    }

    /**
     * The pattern as fd wants it.
     *
     * A --full-path glob has to match the path from its start, and fd walks from the
     * search root, so an unanchored pattern needs a '**\/' in front of it or it matches
     * only things directly in the root.
     */
    private static function pattern(string $pattern): string
    {
        if (!str_contains($pattern, '/') || str_starts_with($pattern, '**/') || str_starts_with($pattern, '/')) {
            return $pattern;
        }

        return '**/' . $pattern;
    }

    /**
     * @param string $output fd's paths, one per line
     * @return list<string>
     */
    private function relative(string $output, string $root): array
    {
        $found = [];

        foreach (explode("\n", trim($output)) as $line) {
            $line = rtrim($line, "\r");

            if ($line === '') {
                continue;
            }

            $found[] = str_starts_with($line, $root . '/') ? substr($line, strlen($root) + 1) : $line;
        }

        return $found;
    }
}
