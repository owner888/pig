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
 * Search file contents, with ripgrep.
 *
 * `rg --json` rather than its plain output: a filename with a colon in it makes
 * `path:line:text` ambiguous, and the model would have to guess where the path ended.
 *
 * Matches are read as they arrive and the search is killed once there are enough of
 * them — a bare pattern across a large repository finds tens of thousands, and waiting
 * for all of them to send a hundred is time and memory spent on nothing.
 */
final class GrepTool implements AgentTool
{
    private const int DEFAULT_LIMIT = 100;

    private const float TIMEOUT = 30.0;

    public function __construct(private readonly string $cwd)
    {
    }

    #[\Override]
    public function definition(): Tool
    {
        $limit = self::DEFAULT_LIMIT;
        $bytes = Truncate::size(Truncate::MAX_BYTES);
        $chars = Truncate::MAX_MATCH_CHARS;

        return new Tool(
            'grep',
            "Search file contents for a pattern. Returns matching lines with their file and line "
                . "number. Respects .gitignore. Cut off at {$limit} matches or {$bytes}, whichever comes "
                . "first; lines longer than {$chars} characters are shortened.",
            [
                'type' => 'object',
                'properties' => [
                    'pattern' => ['type' => 'string', 'description' => 'Regular expression, or a literal string with literal=true'],
                    'path' => ['type' => 'string', 'description' => 'File or directory to search; defaults to the working directory'],
                    'glob' => ['type' => 'string', 'description' => "Only search files matching this glob, e.g. '*.php'"],
                    'ignoreCase' => ['type' => 'boolean', 'description' => 'Match regardless of case'],
                    'literal' => ['type' => 'boolean', 'description' => 'Treat the pattern as text rather than a regular expression'],
                    'context' => ['type' => 'number', 'description' => 'Lines of context to show around each match'],
                    'limit' => ['type' => 'number', 'description' => 'Most matches to return'],
                ],
                'required' => ['pattern'],
            ],
        );
    }

    #[\Override]
    public function label(): string
    {
        return 'Search';
    }

    #[\Override]
    public function execute(
        string $toolCallId,
        array $arguments,
        ?AbortSignal $signal = null,
        ?Closure $onUpdate = null,
    ): AgentToolResult {
        $signal?->throwIfAborted();

        $rg = ExternalTool::ripgrep();
        $given = (string) ($arguments['path'] ?? '.');
        $root = Paths::resolve($given, $this->cwd);

        if (!file_exists($root)) {
            throw new AgentError("No such file or directory: {$given}");
        }

        $limit = max(1, (int) ($arguments['limit'] ?? self::DEFAULT_LIMIT));
        $context = max(0, (int) ($arguments['context'] ?? 0));
        $searchingDirectory = is_dir($root);

        $lines = [];
        $matches = 0;
        $shortened = false;
        $cache = [];

        [$exit, $errors] = Process::stream(
            $this->command($rg, $arguments, $root),
            function (string $line) use (
                &$lines,
                &$matches,
                &$shortened,
                &$cache,
                $limit,
                $context,
                $root,
                $searchingDirectory,
                $signal,
            ): bool {
                $signal?->throwIfAborted();

                $match = self::parse($line);

                if ($match === null) {
                    return true;
                }

                [$file, $number] = $match;
                $matches++;

                foreach (self::block($file, $number, $context, $root, $searchingDirectory, $cache, $shortened) as $out) {
                    $lines[] = $out;
                }

                return $matches < $limit;
            },
            self::TIMEOUT,
        );

        // 0 is matches, 1 is none, STOPPED is us having had enough. Anything else is rg
        // objecting to the pattern, which the model needs to hear about rather than see
        // as "no matches".
        //
        // And it hears rg's own sentence, not the code: `regex parse error: … ^` names the
        // character to fix, where "exited with code 2" names nothing anybody can act on. Same
        // rule as `find`, which the trap about fd's exit code was written for — this is its
        // sibling, and it was the one not following it.
        if ($exit !== 0 && $exit !== 1 && $exit !== Process::STOPPED) {
            throw new AgentError(trim($errors) === '' ? "ripgrep exited with code {$exit}" : trim($errors));
        }

        if ($matches === 0) {
            return new AgentToolResult([new TextContent('No matches found')]);
        }

        $truncation = Truncate::head(implode("\n", $lines), PHP_INT_MAX);
        $notices = [];

        if ($matches >= $limit) {
            $doubled = $limit * 2;
            $notices[] = "{$limit} match limit reached. Use limit={$doubled} for more, or narrow the pattern";
        }

        if ($truncation->truncated) {
            $notices[] = Truncate::size(Truncate::MAX_BYTES) . ' limit reached';
        }

        if ($shortened) {
            $chars = Truncate::MAX_MATCH_CHARS;
            $notices[] = "Some lines shortened to {$chars} characters. Use read to see them in full";
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
                    'matchLimitReached' => $matches >= $limit ? $limit : null,
                    'truncation' => $truncation,
                    'linesTruncated' => $shortened ? true : null,
                    'notice' => $notice,
                ],
                static fn (mixed $value): bool => $value !== null,
            ),
        );
    }

    /**
     * @param array<string, mixed> $arguments
     * @return list<string>
     */
    private function command(string $rg, array $arguments, string $root): array
    {
        // --no-require-git so .gitignore is honoured outside a repository too; without
        // it a search of any plain directory walks straight into vendor and node_modules.
        $command = [$rg, '--json', '--line-number', '--color=never', '--hidden', '--no-require-git'];

        if (($arguments['ignoreCase'] ?? false) === true) {
            $command[] = '--ignore-case';
        }

        if (($arguments['literal'] ?? false) === true) {
            $command[] = '--fixed-strings';
        }

        if (isset($arguments['glob']) && $arguments['glob'] !== '') {
            $command[] = '--glob';
            $command[] = (string) $arguments['glob'];
        }

        $command[] = (string) $arguments['pattern'];
        $command[] = $root;

        return $command;
    }

    /**
     * One line of `rg --json`, if it is a match.
     *
     * @return array{0: string, 1: int}|null the file and the line number
     */
    private static function parse(string $line): ?array
    {
        if (trim($line) === '') {
            return null;
        }

        $event = json_decode($line, true);

        if (!is_array($event) || ($event['type'] ?? null) !== 'match') {
            return null;
        }

        $file = $event['data']['path']['text'] ?? null;
        $number = $event['data']['line_number'] ?? null;

        return is_string($file) && is_int($number) ? [$file, $number] : null;
    }

    /**
     * The matched line, and the context around it.
     *
     * The match is marked with `:` and context with `-`, which is what grep does and
     * what anyone reading the output will already expect.
     *
     * @param array<string, list<string>> $cache lines per file, since several matches in
     *        one file are the common case and re-reading it each time is not free
     * @return list<string>
     */
    private static function block(
        string $file,
        int $number,
        int $context,
        string $root,
        bool $searchingDirectory,
        array &$cache,
        bool &$shortened,
    ): array {
        $name = $searchingDirectory ? Paths::relative($file, $root) : basename($file);
        $lines = $cache[$file] ??= self::read($file);

        if ($lines === []) {
            return ["{$name}:{$number}: (unable to read file)"];
        }

        $from = $context > 0 ? max(1, $number - $context) : $number;
        $to = $context > 0 ? min(count($lines), $number + $context) : $number;
        $block = [];

        for ($current = $from; $current <= $to; $current++) {
            [$text, $wasShortened] = Truncate::line(str_replace("\r", '', $lines[$current - 1] ?? ''));
            $shortened = $shortened || $wasShortened;
            $separator = $current === $number ? ':' : '-';

            $block[] = "{$name}{$separator}{$current}{$separator} {$text}";
        }

        return $block;
    }

    /** @return list<string> */
    private static function read(string $file): array
    {
        $contents = file_get_contents($file);

        // A file rg could read and this cannot — a race with a build, most likely. The
        // match is still reported, with a note instead of the line.
        return $contents === false ? [] : explode("\n", str_replace(["\r\n", "\r"], "\n", $contents));
    }
}
