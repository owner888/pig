<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Web\Schedule;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Pig\Agent\AgentError;
use Pig\Agent\AgentToolResult;
use Pig\Ai\TextContent;
use Pig\CodingAgent\Extensions\ExtensionContext;
use RuntimeException;

/**
 * The `schedule` tool — scheduled tasks set up by asking, as the Claude app's are: "every morning,
 * look at what changed upstream and tell me whether this code needs it" becomes a task in the page's
 * Scheduled list. `builtin:schedule` (`extensions/pig-schedule`) registers it, in `pig web`'s
 * conversations only.
 *
 * Four rules, each the developer's:
 *
 * - **It only creates.** The schedule runs in the server, for every conversation, so a
 *   conversation needs no more than a way to add to it; changing, switching off, running and
 *   deleting tasks is the Scheduled page's, and the schema the model is sent stays small.
 * - **Creating is asked first.** The person is shown what will be saved, in a confirm dialog, and
 *   nothing is saved unless they say yes. Without anybody to ask — a JSON run — the tool refuses.
 * - **The server that runs the schedule makes the change** (`ScheduleClient`), and without one
 *   there is no change: the tasks only run while `pig web` does.
 * - **A run only reports by default.** The guidelines tell the model to write a run's prompt so it
 *   asks before pulling or editing. A question in a run waits for the person (`needs_input`) when
 *   the task's permission is `ask`; `auto` — the Claude app's default, and this tool's for a new
 *   task — answers its confirmations yes (`ScheduleManager::observe()`).
 */
final class ScheduleTool
{
    public const string NAME = 'schedule';

    /**
     * What the server sets, to `1`, in the environment of the child it starts for a conversation
     * (`HttpServer`), so `builtin:schedule` registers the tool there and nowhere else.
     */
    public const string WEB_CONVERSATION = 'PIG_WEB_CONVERSATION';

    /**
     * @param (Closure(): ScheduleClient)|null $client how the server is found; `ScheduleClient::find()`
     * @param (Closure(): string)|null $timezone the timezone a task is in; this machine's
     */
    public function __construct(
        private readonly ?Closure $client = null,
        private readonly ?Closure $timezone = null,
    ) {
    }

    public static function description(): string
    {
        return 'Create a scheduled task: a prompt pig runs on its own, in a conversation of its own in a folder. '
            . 'The user confirms it first; the web UI\'s Scheduled page changes, pauses and deletes tasks. '
            . 'Give cron (five fields, e.g. `0 9 * * 1-5` weekdays at 9), or at for one run, or neither to run only when asked.';
    }

    /**
     * Only what creating a task needs: the timezone is this machine's and the model and thinking
     * level pig's defaults, which the Scheduled page changes — the developer's call, so the schema
     * sent with every request of a web conversation stays small.
     *
     * @return array<string, mixed>
     */
    public static function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'description' => 'Short name'],
                'prompt' => ['type' => 'string', 'description' => 'What each run is asked; it knows nothing of this conversation'],
                'cwd' => ['type' => 'string', 'description' => 'Folder; defaults to the current one'],
                'cron' => ['type' => 'string', 'description' => 'minute hour day-of-month month day-of-week'],
                'at' => ['type' => 'string', 'description' => "Local time of one run, e.g. '2026-10-12 09:00'"],
                'permission' => ['type' => 'string', 'enum' => [ScheduledTask::AUTO, ScheduledTask::ASK], 'description' => "auto (default) answers the run's confirmations yes; ask waits for the user"],
            ],
            'required' => ['name', 'prompt'],
        ];
    }

    /** One line for the system prompt's tool list. */
    public static function promptSnippet(): string
    {
        return 'Create a task pig runs on its own on a schedule';
    }

    /** @return list<string> */
    public static function promptGuidelines(): array
    {
        return [
            'When the user wants something to happen on a schedule, use the schedule tool.',
            'Write a scheduled task\'s prompt to stand on its own (the folder, what to check, what to report) and, unless the user asked otherwise, to ask before pulling, committing or editing files.',
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @throws AgentError with what went wrong, for the model
     */
    public function execute(array $params, ExtensionContext $ctx): AgentToolResult
    {
        if (!$ctx->hasUi) {
            throw new AgentError('Scheduled tasks are only created with the user\'s confirmation, and nobody is here to confirm. Tell the user what you would set up instead.');
        }

        try {
            return $this->save($this->client !== null ? ($this->client)() : ScheduleClient::find(), $params, $ctx);
        } catch (AgentError $e) {
            throw $e;
        } catch (RuntimeException | InvalidArgumentException $e) {
            throw new AgentError($e->getMessage(), previous: $e);
        }
    }

    /** @param array<string, mixed> $params */
    private function save(ScheduleClient $client, array $params, ExtensionContext $ctx): AgentToolResult
    {
        $data = [];

        foreach (['name' => 'name', 'prompt' => 'prompt', 'cwd' => 'cwd', 'permission' => 'permissionMode'] as $param => $field) {
            if (isset($params[$param])) {
                $data[$field] = is_string($params[$param]) ? trim($params[$param]) : $params[$param];
            }
        }

        $data['cwd'] = self::folder((string) ($data['cwd'] ?? ''), $ctx->cwd);
        $data['timezone'] = $this->timezone !== null ? ($this->timezone)() : self::localTimezone();
        $data['enabled'] = true;
        // The Claude app's default for a new task: it works without stopping for approval.
        $data['permissionMode'] ??= ScheduledTask::AUTO;

        $cron = isset($params['cron']) ? trim((string) $params['cron']) : null;
        $at = isset($params['at']) ? trim((string) $params['at']) : null;

        if ($cron !== null && $at !== null) {
            throw new AgentError('Give cron or at — not both.');
        }

        $data['triggers'] = match (true) {
            $cron !== null => [['type' => ScheduledTask::CRON, 'cron' => $cron]],
            $at !== null => [['type' => ScheduledTask::ONCE, 'runAt' => self::millisAt($at, (string) $data['timezone'])]],
            default => [],
        };

        // Checked here before anybody is asked, so the dialog never shows a task that cannot be saved.
        // A folder where the tools run elsewhere (an SSH account's) is the server's to check.
        $here = \Pig\CodingAgent\Tools\ToolSet::operations() === null;
        $task = ScheduledTask::fromArray($data, $here);

        if (!$ctx->ui->confirm('Create a scheduled task?', self::summary($task))) {
            return self::text('The user declined; nothing was saved.');
        }

        $saved = $client->save($data);

        return self::text("Created scheduled task {$saved['id']}; its runs carry on in its own conversation, in the sidebar under its name.\n" . self::summary(ScheduledTask::fromArray($saved, $here)));
    }

    /** What the person is asked to confirm, and what the model is told was saved. */
    private static function summary(ScheduledTask $task): string
    {
        return implode("\n", array_filter([
            "Name: {$task->name}",
            'When: ' . self::when($task->toArray() + ['nextRunAt' => $task->nextRunAt()]),
            "Folder: {$task->cwd}",
            $task->model !== null ? "Model: {$task->model}" : null,
            $task->thinkingLevel !== null ? "Thinking: {$task->thinkingLevel}" : null,
            'Permission: ' . ($task->permissionMode === ScheduledTask::AUTO ? 'auto — runs without stopping for approval' : 'ask — stops at each approval until answered'),
            "Prompt:\n{$task->prompt}",
        ]));
    }

    /** @param array<string, mixed> $task as `list()` has it */
    private static function when(array $task): string
    {
        $tz = (string) $task['timezone'];
        $triggers = is_array($task['triggers'] ?? null) ? $task['triggers'] : [];
        $when = $triggers === [] ? 'only when asked' : implode('; ', array_map(
            static fn (array $trigger): string => $trigger['type'] === ScheduledTask::CRON
                ? "cron `{$trigger['cron']}` ({$tz})"
                : 'once, ' . self::date((int) $trigger['runAt'], $tz),
            $triggers,
        ));

        if (($task['enabled'] ?? true) === false) {
            return "{$when} — switched off";
        }

        $next = $task['nextRunAt'] ?? null;

        return is_int($next) && $triggers !== [] ? "{$when} — next " . self::date($next, $tz) : $when;
    }

    /** A folder as given — absolute, `~/…` or relative to where the conversation is — made absolute. */
    private static function folder(string $path, string $cwd): string
    {
        $home = (string) \Pig\Tui\Env::home();
        $path = match (true) {
            $path === '' => $cwd,
            $path === '~' => $home,
            str_starts_with($path, '~/') => $home . substr($path, 1),
            str_starts_with($path, '/') => $path,
            default => rtrim($cwd, '/') . '/' . $path,
        };

        return realpath($path) ?: $path;
    }

    private static function date(int $millis, string $timezone): string
    {
        return ScheduledTask::dateTime($millis, $timezone)->format('Y-m-d H:i T');
    }

    /** `at`, read in the task's timezone unless it names its own offset, in epoch milliseconds. */
    private static function millisAt(string $at, string $timezone): int
    {
        try {
            $moment = new DateTimeImmutable($at, new DateTimeZone($timezone));
        } catch (\Exception $e) {
            throw new AgentError("'{$at}' is not a date and time; write it like '2026-10-12 09:00'.", previous: $e);
        }

        return (int) $moment->format('Uv');
    }

    /**
     * This machine's timezone: `TZ`, then what `/etc/localtime` points at (macOS and most Linux),
     * then `/etc/timezone`, then PHP's own setting.
     */
    public static function localTimezone(): string
    {
        $candidates = [getenv('TZ') ?: null];

        $link = is_link('/etc/localtime') ? readlink('/etc/localtime') : false;
        if (is_string($link) && ($at = strpos($link, 'zoneinfo/')) !== false) {
            $candidates[] = substr($link, $at + strlen('zoneinfo/'));
        }

        $candidates[] = is_readable('/etc/timezone') ? trim((string) file_get_contents('/etc/timezone')) : null;

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '' && in_array(ltrim($candidate, ':'), DateTimeZone::listIdentifiers(), true)) {
                return ltrim($candidate, ':');
            }
        }

        return date_default_timezone_get();
    }

    private static function text(string $text): AgentToolResult
    {
        return new AgentToolResult([new TextContent($text)]);
    }
}
