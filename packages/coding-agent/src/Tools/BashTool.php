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
 * Run a shell command.
 *
 * The one tool that has to cooperate with the event loop. A build takes minutes, and for
 * every second of it the UI has to keep drawing, the keyboard has to keep working, and
 * Escape has to actually stop the thing. So the pipes are watched by the loop and the
 * coroutine suspends — the same `select()` that waits on the model's socket waits on
 * this command's output. Reading it with a blocking loop would freeze the screen and
 * make Escape arrive after the command had already finished.
 *
 * It follows that `execute()` must be called inside `Async::run()`, like everything else
 * here that waits for something.
 *
 * Output is tail-truncated, not head: the interesting part of a failed build is the error
 * at the bottom, and the first nine hundred lines are a log.
 */
final class BashTool implements AgentTool
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
            'bash',
            "Run a bash command in the working directory. Returns stdout and stderr together. "
                . "Output is cut to the last {$lines} lines or {$bytes}, whichever comes first, and the "
                . 'full output is written to a file whose path is included when that happens. '
                . 'A timeout in seconds can be given; there is none by default.',
            [
                'type' => 'object',
                'properties' => [
                    'command' => ['type' => 'string', 'description' => 'The command to run'],
                    'timeout' => ['type' => 'number', 'description' => 'Seconds to allow before killing it'],
                ],
                'required' => ['command'],
            ],
        );
    }

    #[\Override]
    public function label(): string
    {
        return 'Bash';
    }

    #[\Override]
    public function execute(
        string $toolCallId,
        array $arguments,
        ?AbortSignal $signal = null,
        ?Closure $onUpdate = null,
    ): AgentToolResult {
        $signal?->throwIfAborted();

        $command = (string) $arguments['command'];
        $timeout = isset($arguments['timeout']) ? (float) $arguments['timeout'] : null;

        $run = new Run($this->cwd, $command, $onUpdate);
        $run->start();
        $exit = $run->wait($signal, $timeout);
        $output = $run->output();

        if ($run->aborted) {
            throw new AgentError(self::withNote($output, 'Command aborted'));
        }

        if ($run->timedOut) {
            throw new AgentError(self::withNote($output, "Command timed out after {$timeout} seconds"));
        }

        $truncation = Truncate::tail($output);
        $text = $truncation->content === '' ? '(no output)' : $truncation->content;

        if ($truncation->truncated) {
            $text .= "\n\n[" . self::notice($truncation, $output, $run->spillPath) . ']';
        }

        if ($exit !== 0) {
            // A failing command is something the model reads and reacts to, so the
            // output goes with the error rather than being swallowed by it.
            throw new AgentError($text . "\n\nCommand exited with code {$exit}");
        }

        return new AgentToolResult(
            [new TextContent($text)],
            $truncation->truncated ? ['truncation' => $truncation, 'fullOutput' => $run->spillPath] : null,
        );
    }

    private static function withNote(string $output, string $note): string
    {
        return $output === '' ? $note : $output . "\n\n" . $note;
    }

    /** Where the output was cut, and where the whole of it is. */
    private static function notice(Truncation $truncation, string $output, ?string $spillPath): string
    {
        $first = $truncation->totalLines - $truncation->outputLines + 1;
        $last = $truncation->totalLines;
        $where = $spillPath === null ? '' : ". Full output: {$spillPath}";

        if ($truncation->lastPartial) {
            // One line longer than the whole budget — a progress bar with no newlines,
            // usually. There are no line numbers worth quoting.
            $lines = explode("\n", $output);
            $size = Truncate::size(strlen($lines[count($lines) - 1]));

            return 'Showing the last ' . Truncate::size($truncation->outputBytes)
                . " of line {$last}, which is {$size}{$where}";
        }

        $limit = $truncation->by === 'lines' ? '' : ' (' . Truncate::size(Truncate::MAX_BYTES) . ' limit)';

        return "Showing lines {$first}-{$last} of {$truncation->totalLines}{$limit}{$where}";
    }
}
