<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Tools;

use Closure;
use Pig\Agent\AgentError;
use Pig\Agent\AgentTool;
use Pig\Agent\AgentToolResult;
use Pig\Agent\ReplayableTool;
use Pig\Agent\ToolReplay;
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
final class GrepTool implements AgentTool, ReplayableTool
{
    private const int DEFAULT_LIMIT = 100;

    private const float TIMEOUT = 30.0;

    private readonly GrepOperations $operations;

    /** @param GrepOperations|null $operations upstream's `operations`: whether the path is a directory; this machine when null */
    public function __construct(private readonly string $cwd, ?GrepOperations $operations = null)
    {
        $this->operations = $operations ?? new LocalGrepOperations();
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
                    'glob' => [
                        'type' => 'string',
                        'description' => "Filter files by glob pattern, relative to path, e.g. '*.php', 'src/**/*.php' "
                            . "or '**/*Test.php'. A pattern without '/' matches file names at any depth",
                    ],
                    'ignoreCase' => ['type' => 'boolean', 'description' => 'Match regardless of case'],
                    'literal' => ['type' => 'boolean', 'description' => 'Treat the pattern as text rather than a regular expression'],
                    'context' => ['type' => 'number', 'description' => 'Lines of context to show around each match'],
                    'limit' => ['type' => 'number', 'description' => 'Most matches to return'],
                ],
                'required' => ['pattern'],
            ],
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

        // Files somewhere else (`RunsElsewhere`, an account's container) are searched by the `rg` there.
        $elsewhere = $this->operations instanceof RunsElsewhere ? $this->operations : null;
        $rg = $elsewhere !== null ? 'rg' : ExternalTool::ripgrep();
        $given = (string) ($arguments['path'] ?? '.');
        $root = Paths::resolve($given, $this->cwd);

        try {
            $searchingDirectory = $this->operations->isDirectory($root);
        } catch (FileAccessError) {
            throw new AgentError("No such file or directory: {$given}");
        }

        $limit = max(1, (int) ($arguments['limit'] ?? self::DEFAULT_LIMIT));
        $context = max(0, (int) ($arguments['context'] ?? 0));

        // What the model is shown, as rg reports it (upstream #11705): the matches and, with
        // `context`, the `context` events around them — no matched file is ever opened here, so
        // memory follows the output and not the size of the files, and a file elsewhere is read
        // by the `rg` there. Lines past the byte cap are counted but not kept: truncation drops
        // them anyway.
        $lines = [];
        $outputBytes = 0;
        $outputLines = 0;
        $matches = 0;
        $shortened = false;
        $lastFile = null;
        // Set at the last allowed match. rg still sends that match's trailing context, kept up to
        // `$trailingEnd`; the limit notice waits for proof of one more match.
        $limitHit = false;
        $trailingEnd = 0;
        $limitReached = false;

        $push = static function (string $file, int $number, string $text, bool $isMatch) use (&$lines, &$outputBytes, &$outputLines, &$shortened, &$lastFile, $root, $searchingDirectory): void {
            $lastFile = $file;
            $name = $searchingDirectory ? Paths::relative($file, $root) : basename($file);
            // Only the line terminator: a lone CR inside a line is content, as in the read tool.
            [$text, $wasShortened] = Truncate::line((string) preg_replace('/\r?\n?$/', '', $text));
            $shortened = $shortened || $wasShortened;
            $line = $isMatch ? "{$name}:{$number}: {$text}" : "{$name}-{$number}- {$text}";

            if ($outputBytes - 1 <= Truncate::MAX_BYTES) {
                $lines[] = $line;
            }

            $outputBytes += strlen($line) + 1;
            $outputLines++;
        };

        // **`streamAsync()`, not `stream()`.** A tool runs inside the agent's fiber, which the
        // loop is driving, and `stream()` polls with `usleep()` — so an `rg` over a large tree
        // stopped the loop for its whole run: no keystrokes, no spinner, no escape. Upstream's
        // `grep.ts` is an async `spawn`.
        [$command, $directory] = $elsewhere !== null
            ? $elsewhere->elsewhere($this->command($rg, $arguments, $root, $searchingDirectory, $context), $searchingDirectory ? $root : null)
            : [$this->command($rg, $arguments, $root, $searchingDirectory, $context), $searchingDirectory ? $root : null];

        [$exit, $errors] = Process::streamAsync(
            $command,
            static function (string $line) use (&$matches, &$lastFile, &$limitHit, &$trailingEnd, &$limitReached, $push, $limit, $context, $root, $searchingDirectory): bool {
                $event = self::event($line);

                if ($event === null) {
                    return true;
                }

                [$isMatch, $file, $number, $text] = $event;
                // A directory search ran inside the root and reports `./…` paths (#11895).
                $file = $searchingDirectory ? Paths::resolve($file, $root) : $file;

                if (!$limitHit) {
                    $push($file, $number, $text, $isMatch);

                    if ($isMatch && ++$matches >= $limit) {
                        $limitHit = true;
                        $trailingEnd = $number + $context;
                    }

                    return true;
                }

                $inWindow = $file === $lastFile && $number <= $trailingEnd;

                // A match inside the last match's trailing context is shown as context.
                if ($inWindow) {
                    $push($file, $number, $text, false);
                }

                // Only another match proves that the limit cut results off.
                $limitReached = $limitReached || $isMatch;

                return !($limitReached && !($inWindow && $number < $trailingEnd));
            },
            self::TIMEOUT,
            // The abort goes to the runner rather than into the callback. A throw from in there
            // now escapes a *loop* callback, where nothing catches it and the session ends; and
            // the kill is prompt either way, where checking per line only noticed when `rg` had
            // something to say. `throwIfAborted()` below is what the caller still sees.
            $signal,
            // rg roots `--glob` at its own working directory, so a directory search runs inside
            // the root (upstream #11895): `migrations/main/*.js` with `path: repo` was matched
            // from the session's folder and found nothing.
            $directory,
        );

        $signal?->throwIfAborted();

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
        // Totals of everything rg sent, the lines past the cap that were not kept included.
        $truncation = new Truncation(...[...get_object_vars($truncation), 'totalLines' => $outputLines, 'totalBytes' => max(0, $outputBytes - 1)]);
        $notices = [];

        if ($limitReached) {
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
                    'matchLimitReached' => $limitReached ? $limit : null,
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
    private function command(string $rg, array $arguments, string $root, bool $searchingDirectory, int $context): array
    {
        // --no-require-git so .gitignore is honoured outside a repository too; without
        // it a search of any plain directory walks straight into vendor and node_modules.
        // --no-config: a `RIPGREP_CONFIG_PATH` with `--fixed-strings` would silently turn every
        // regex search into a literal one (#11203).
        $command = [$rg, '--no-config', '--json', '--line-number', '--color=never', '--hidden', '--no-require-git'];

        if (($arguments['ignoreCase'] ?? false) === true) {
            $command[] = '--ignore-case';
        }

        if (($arguments['literal'] ?? false) === true) {
            $command[] = '--fixed-strings';
        }

        if (isset($arguments['glob']) && $arguments['glob'] !== '') {
            // rg never matches a leading `./` (`./src/*.php` finds nothing), so it is dropped; `!` negates.
            $command[] = '--glob';
            $command[] = (string) preg_replace('#^(!?)(?:\./)+#', '$1', (string) $arguments['glob']);
        }

        // rg emits the surrounding lines as `context` events, so no matched file is read here
        // (upstream #11705). Clamped so a huge `context` stays a number rg takes.
        if ($context > 0) {
            $command[] = '--context';
            $command[] = (string) min($context, PHP_INT_MAX);
        }

        // `--` so a pattern starting with `-` is not read as a flag. A directory search names `.`:
        // rg canonicalizes its working directory, so the absolute path of a symlinked root would
        // no longer lie under the glob's root. rg ignores `--glob` for a file named directly.
        $command[] = '--';
        $command[] = (string) $arguments['pattern'];
        $command[] = $searchingDirectory ? '.' : $root;

        return $command;
    }

    /**
     * One line of `rg --json`, if it is a `match` or a `context` event: whether it matched, the
     * file, the line number and the line.
     *
     * @return array{0: bool, 1: string, 2: int, 3: string}|null
     */
    private static function event(string $line): ?array
    {
        if (trim($line) === '') {
            return null;
        }

        $event = json_decode($line, true);
        $type = is_array($event) ? ($event['type'] ?? null) : null;

        if ($type !== 'match' && $type !== 'context') {
            return null;
        }

        $file = self::text($event['data']['path'] ?? null);
        $number = $event['data']['line_number'] ?? null;

        return $file !== '' && is_int($number) ? [$type === 'match', $file, $number, self::text($event['data']['lines'] ?? null)] : null;
    }

    /**
     * Upstream's `decodeRgText()`: a path or line of `rg --json`. Data that is not UTF-8 arrives
     * base64-encoded in `bytes`.
     */
    private static function text(mixed $data): string
    {
        if (is_array($data) && is_string($data['text'] ?? null)) {
            return $data['text'];
        }

        return is_array($data) && is_string($data['bytes'] ?? null) ? (string) base64_decode($data['bytes'], true) : '';
    }
}
