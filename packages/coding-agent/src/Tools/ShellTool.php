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
 * Run a shell command — the engine under `bash` and `powershell`, upstream's
 * `createShellToolDefinition(cwd, config, options)` in `core/tools/bash.ts`.
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
 *
 * Two results, as upstream returns them: the text the model reads, cut to 2000 lines or 50KB,
 * and `structuredContent` — `{output, truncated, full_output_path?, exit_code, wall_time_seconds}`,
 * the `outputSchema` below — for a codemode script, whose `output` holds up to 1 MiB. A command that
 * exits non-zero is an error to the model and still carries the structured result, on the
 * `AgentError`, so a script resolves to it either way.
 *
 * @internal built through `BashTool` and `PowerShellTool`
 */
final class ShellTool implements AgentTool
{
    /** Upstream's `MAX_TIMEOUT_MS`, the largest delay `setTimeout` takes. */
    private const int MAX_TIMEOUT_MS = 2_147_483_647;

    /** Upstream's `STRUCTURED_OUTPUT_MAX_BYTES`: the limit of `structuredContent.output`. */
    public const int STRUCTURED_OUTPUT_MAX_BYTES = 1024 * 1024;

    /** Upstream's `bashOutputSchema`, which `powershell` shares. */
    public const array OUTPUT_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'output' => ['type' => 'string', 'description' => 'Combined stdout and stderr, possibly truncated'],
            'truncated' => ['type' => 'boolean'],
            'full_output_path' => ['type' => 'string', 'description' => 'Full output, when truncated'],
            'exit_code' => ['type' => 'number'],
            'wall_time_seconds' => ['type' => 'number'],
        ],
        'required' => ['output', 'truncated', 'exit_code', 'wall_time_seconds'],
    ];

    /**
     * @param string $name upstream's `ShellToolConfig.name` — `bash`, `powershell`
     * @param string $label what the UI calls it while it runs
     * @param string $shellName how the description names the shell — `bash`, `PowerShell`
     * @param string $spillPrefix upstream's `tempFilePrefix`
     * @param Closure(): ShellConfig $resolveShell asked when a command starts, so a shell that is not
     *        there is an error on the call rather than when the tool is built
     * @param Closure(string): string $wrapCommand what the shell is actually handed for a command —
     *        `powershell`'s UTF-8 output prefix; the identity for `bash`
     * @param ?string $commandPrefix upstream's `commandPrefix` (`shellCommandPrefix`): a line run in
     *        front of the command — `shopt -s expand_aliases`, say — joined to it with a newline
     * @param bool $exposeSessionEnvironment upstream's option of the same name, default true: the
     *        `PIG_*` session variables go into the command's environment
     * @param (Closure(): ?SessionEnvironment)|null $sessionEnvironment the session to describe, asked
     *        when each command starts — pig's stand-in for upstream's `ctx`. Null describes none
     * @param (Closure(SpawnContext): SpawnContext)|null $spawnHook upstream's `spawnHook`: adjust the
     *        command, the directory or the environment before it runs
     */
    public function __construct(
        private readonly string $cwd,
        private readonly string $name,
        private readonly string $label,
        private readonly string $shellName,
        private readonly string $spillPrefix,
        private readonly Closure $resolveShell,
        private readonly Closure $wrapCommand,
        private readonly ?string $commandPrefix = null,
        private readonly bool $exposeSessionEnvironment = true,
        private readonly ?Closure $sessionEnvironment = null,
        private readonly ?Closure $spawnHook = null,
    ) {
    }

    #[\Override]
    public function definition(): Tool
    {
        $lines = Truncate::MAX_LINES;
        $bytes = Truncate::size(Truncate::MAX_BYTES);

        return new Tool(
            $this->name,
            "Run a {$this->shellName} command in the working directory. Returns stdout and stderr together. "
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
            // Upstream's `constrainedSampling: { type: "json_schema", strict: "prefer" }` on bash,
            // edit, read and write (and on none of grep, find or ls): strict sampling where the
            // provider has it, the schema as written where it does not. Under strict sampling an
            // optional parameter arrives as null, which `ToolArguments` drops before validation.
            ['type' => 'json_schema', 'strict' => 'prefer'],
            // Upstream's schema is TypeBox's `Type.Object(…)`, so its arguments go through
            // `Value.Convert` before validation — `Tool::$typeBox`, read by `ToolArguments`.
            typeBox: true,
            outputSchema: self::OUTPUT_SCHEMA,
        );
    }

    #[\Override]
    public function label(): string
    {
        return $this->label;
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
        $timeout = isset($arguments['timeout']) ? self::timeout($arguments['timeout']) : null;

        $context = $this->spawnContext($this->commandPrefix !== null ? "{$this->commandPrefix}\n{$command}" : $command);

        // Upstream's `createLocalShellOperations()` check, before anything is started.
        if (!is_dir($context->cwd)) {
            throw new AgentError("Working directory does not exist: {$context->cwd}\nCannot execute {$this->shellName} commands.");
        }

        $startedAt = microtime(true);
        $run = new Run(
            $context->cwd,
            ($this->wrapCommand)($context->command),
            $onUpdate,
            ($this->resolveShell)(),
            $context->env,
            $this->spillPrefix,
            // Asked whether or not the variables are exposed: a private session's output stays in
            // its own directory either way.
            spillDirectory: $this->sessionEnvironment !== null ? ($this->sessionEnvironment)()?->tempDirectory : null,
        );
        $run->start();
        $exit = $run->wait($signal, $timeout);
        $output = $run->output();

        if ($run->aborted) {
            throw new AgentError(self::withNote($output, 'Command aborted'));
        }

        if ($run->timedOut) {
            throw new AgentError(self::withNote($output, 'Command timed out after ' . self::seconds($timeout ?? 0.0) . ' seconds'));
        }

        $truncation = Truncate::tail($output);
        $text = $truncation->content === '' ? '(no output)' : $truncation->content;
        $notice = $truncation->truncated ? self::notice($truncation, $output, $run->spillPath) : null;

        if ($notice !== null) {
            $text .= "\n\n[{$notice}]";
        }

        // `notice` is beside the other two so every tool that cuts its own output carries the
        // same three keys. The transcript does not need it here — a command's preview keeps the
        // *tail*, which is where this line already is — and a shape that holds for four tools
        // and not the fifth is the next person's puzzle.
        $details = $notice === null ? null : array_filter(
            [
                'truncation' => $truncation,
                'fullOutputPath' => $run->spillPath,
                'notice' => $notice,
            ],
            static fn (mixed $value): bool => $value !== null,
        );

        // Upstream rounds to a tenth of a second: `Math.round(ms / 100) / 10`.
        $wallTime = round((microtime(true) - $startedAt) * 10) / 10;
        $full = $run->fullOutput(self::STRUCTURED_OUTPUT_MAX_BYTES);
        $structured = [
            'output' => $full['content'],
            'truncated' => $full['truncated'],
            ...($full['truncated'] && $run->spillPath !== null ? ['full_output_path' => $run->spillPath] : []),
            'exit_code' => $exit,
            'wall_time_seconds' => $wallTime,
        ];

        if ($exit !== 0) {
            // A failing command is something the model reads and reacts to, so the
            // output goes with the error rather than being swallowed by it.
            throw new AgentError($text . "\n\nCommand exited with code {$exit}", $details, structuredContent: $structured);
        }

        return new AgentToolResult([new TextContent($text)], $details, structuredContent: $structured);
    }

    /**
     * Upstream's `resolveSpawnContext()`: the inherited environment without any `PIG_*` session
     * variables, those of this session put in when `exposeSessionEnvironment` is on, then the
     * `spawnHook` — so a hook sees the variables and can change or drop them.
     */
    private function spawnContext(string $command): SpawnContext
    {
        $session = $this->exposeSessionEnvironment && $this->sessionEnvironment !== null
            ? ($this->sessionEnvironment)()
            : null;

        $context = new SpawnContext($command, $this->cwd, SessionEnvironment::apply(Shell::environment(), $session));

        return $this->spawnHook !== null ? ($this->spawnHook)($context) : $context;
    }

    /** Upstream's `resolveTimeoutMs()`: a positive, finite number of seconds that `setTimeout` can hold. */
    private static function timeout(mixed $value): float
    {
        $seconds = is_int($value) || is_float($value) ? (float) $value : NAN;

        if (!is_finite($seconds) || $seconds <= 0) {
            throw new AgentError('Invalid timeout: must be a finite number of seconds');
        }

        if ($seconds * 1000 > self::MAX_TIMEOUT_MS) {
            throw new AgentError('Invalid timeout: maximum is ' . self::seconds(self::MAX_TIMEOUT_MS / 1000) . ' seconds');
        }

        return $seconds;
    }

    /** A number of seconds as JavaScript prints it: `2`, not `2.0`. */
    private static function seconds(float $seconds): string
    {
        return $seconds == floor($seconds) ? (string) (int) $seconds : (string) $seconds;
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

        if ($truncation->lastLinePartial) {
            // One line longer than the whole budget — a progress bar with no newlines,
            // usually. There are no line numbers worth quoting.
            $lines = explode("\n", $output);
            $size = Truncate::size(strlen($lines[count($lines) - 1]));

            return 'Showing the last ' . Truncate::size($truncation->outputBytes)
                . " of line {$last}, which is {$size}{$where}";
        }

        $limit = $truncation->truncatedBy === 'lines' ? '' : ' (' . Truncate::size(Truncate::MAX_BYTES) . ' limit)';

        return "Showing lines {$first}-{$last} of {$truncation->totalLines}{$limit}{$where}";
    }
}
