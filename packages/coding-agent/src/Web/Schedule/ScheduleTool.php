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
use Pig\CodingAgent\Hooks\HookContext;
use RuntimeException;

/**
 * The `schedule` tool — scheduled tasks set up by asking, as the Claude app's are: "every morning,
 * look at what changed upstream and tell me whether this code needs it" becomes a task in the page's
 * Scheduled list. `builtin:schedule` (`extensions/pig-schedule`) registers it.
 *
 * Three rules, each the developer's:
 *
 * - **Every change is asked first.** Creating, editing, deleting, switching on or off and running
 *   now each show the person what will be saved, in a confirm dialog, and do nothing unless they
 *   say yes. Without anybody to ask — `-p`, a JSON run — the tool only reads.
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

    public const array ACTIONS = ['list', 'runs', 'create', 'update', 'delete', 'enable', 'disable', 'run'];

    /** Runs `runs` shows, newest first. */
    private const int RUNS = 10;

    /**
     * @param (Closure(): ScheduleClient)|null $client how the server is found; `ScheduleClient::find()`
     * @param (Closure(): string)|null $timezone the timezone a task is in when none is given; this machine's
     */
    public function __construct(
        private readonly ?Closure $client = null,
        private readonly ?Closure $timezone = null,
    ) {
    }

    public static function description(): string
    {
        return 'Create and manage scheduled tasks: a prompt pig runs on its own on a schedule — every day at 9, '
            . 'on weekdays, once at a given time, or only when asked — each in the task\'s own conversation in a given folder (in the sidebar from the moment it is created), '
            . 'listed in the web UI\'s Scheduled page. Runs happen while `pig web` runs on this machine. '
            . 'Every change is shown to the user to confirm first. '
            . "Actions: list; runs (a task's recent runs); create; update; delete; enable; disable; run (now). "
            . 'The schedule is a five-field cron expression in the task\'s timezone (`0 9 * * *` every day at 9:00, '
            . '`0 9 * * 1-5` weekdays), or `at` for one run, or neither for a task that runs only when asked.';
    }

    /** @return array<string, mixed> */
    public static function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action' => ['type' => 'string', 'enum' => self::ACTIONS, 'description' => 'What to do'],
                'id' => ['type' => 'string', 'description' => 'The task, for every action but list and create'],
                'name' => ['type' => 'string', 'description' => 'A short name for the task'],
                'prompt' => ['type' => 'string', 'description' => 'What each run is asked, in the task\'s own conversation — which knows nothing of this one, so say everything: the repository or files, what to check, what to report, and what to ask before doing'],
                'cwd' => ['type' => 'string', 'description' => 'The folder each run starts in; defaults to the current one'],
                'cron' => ['type' => 'string', 'description' => 'Five-field cron expression: minute hour day-of-month month day-of-week'],
                'at' => ['type' => 'string', 'description' => "One run at this local time, e.g. '2026-10-12 09:00'"],
                'manual' => ['type' => 'boolean', 'description' => 'update: make the task run only when asked'],
                'timezone' => ['type' => 'string', 'description' => "IANA timezone the schedule is in, e.g. 'Asia/Singapore'; defaults to this machine's"],
                'model' => ['type' => 'string', 'description' => 'provider/id for the runs; defaults to pig\'s default model'],
                'thinking' => ['type' => 'string', 'description' => 'Thinking level for the runs: off, minimal, low, medium, high, xhigh'],
                'permission' => ['type' => 'string', 'enum' => [ScheduledTask::AUTO, ScheduledTask::ASK], 'description' => "auto (the default for a new task): a run's confirmations are answered yes and it does not stop to wait; ask: a run stops at each one until the user answers"],
            ],
            'required' => ['action'],
        ];
    }

    /** One line for the system prompt's tool list. */
    public static function promptSnippet(): string
    {
        return 'schedule: set up tasks pig runs on its own on a schedule (cron), each task with a conversation of its own';
    }

    /** @return list<string> */
    public static function promptGuidelines(): array
    {
        return [
            'When the user asks for something to happen on a schedule ("every morning", "each Monday", "tomorrow at 9"), use the schedule tool rather than describing how to set it up.',
            'A scheduled task runs in a conversation of its own that knows nothing of this one: write its prompt to stand on its own — the repository or folder, what to check, what to report.',
            'A scheduled run reports by default: unless the user asked otherwise, write its prompt so that it asks before pulling, merging, committing or editing files.',
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @throws AgentError with what went wrong, for the model
     */
    public function execute(array $params, HookContext $ctx): AgentToolResult
    {
        $action = (string) ($params['action'] ?? '');

        if (!in_array($action, self::ACTIONS, true)) {
            throw new AgentError("action must be one of: " . implode(', ', self::ACTIONS) . '.');
        }

        $reads = $action === 'list' || $action === 'runs';
        if (!$reads && !$ctx->hasUi) {
            throw new AgentError('Scheduled tasks are only changed with the user\'s confirmation, and nobody is here to confirm. Tell the user what you would set up instead.');
        }

        try {
            $client = $this->client !== null ? ($this->client)() : ScheduleClient::find();

            return match ($action) {
                'list' => self::text(self::describeList($client->list())),
                'runs' => self::text(self::describeRuns($this->existing($client, $params), $client)),
                'create', 'update' => $this->save($client, $params, $ctx, $action === 'update'),
                default => $this->act($client, $action, $this->existing($client, $params), $ctx),
            };
        } catch (AgentError $e) {
            throw $e;
        } catch (RuntimeException | InvalidArgumentException $e) {
            throw new AgentError($e->getMessage(), previous: $e);
        }
    }

    /** @param array<string, mixed> $params */
    private function save(ScheduleClient $client, array $params, HookContext $ctx, bool $update): AgentToolResult
    {
        $existing = $update ? $this->existing($client, $params) : null;
        $data = $existing !== null ? array_intersect_key($existing, array_flip(['id', 'name', 'prompt', 'cwd', 'triggers', 'timezone', 'model', 'thinkingLevel', 'enabled', 'permissionMode'])) : [];

        foreach (['name' => 'name', 'prompt' => 'prompt', 'cwd' => 'cwd', 'timezone' => 'timezone', 'model' => 'model', 'thinking' => 'thinkingLevel', 'permission' => 'permissionMode'] as $param => $field) {
            if (isset($params[$param])) {
                $data[$field] = is_string($params[$param]) ? trim($params[$param]) : $params[$param];
            }
        }

        $data['cwd'] = self::folder((string) ($data['cwd'] ?? ''), $ctx->cwd);
        $data['timezone'] ??= $this->timezone !== null ? ($this->timezone)() : self::localTimezone();
        $data['enabled'] ??= true;
        // The Claude app's default for a new task: it works without stopping for approval.
        $data['permissionMode'] ??= ScheduledTask::AUTO;

        $cron = isset($params['cron']) ? trim((string) $params['cron']) : null;
        $at = isset($params['at']) ? trim((string) $params['at']) : null;
        $manual = ($params['manual'] ?? false) === true;

        if (count(array_filter([$cron !== null, $at !== null, $manual])) > 1) {
            throw new AgentError('Give one of cron, at or manual — not more.');
        }

        if ($cron !== null) {
            $data['triggers'] = [['type' => ScheduledTask::CRON, 'cron' => $cron]];
        } elseif ($at !== null) {
            $data['triggers'] = [['type' => ScheduledTask::ONCE, 'runAt' => self::millisAt($at, (string) $data['timezone'])]];
        } elseif ($manual || !isset($data['triggers'])) {
            $data['triggers'] = [];
        }

        // Checked here before anybody is asked, so the dialog never shows a task that cannot be saved.
        $task = ScheduledTask::fromArray($data);

        $title = $update ? "Change scheduled task '{$task->name}'?" : 'Create a scheduled task?';
        if (!$ctx->ui->confirm($title, self::summary($task))) {
            return self::text('The user declined; nothing was saved.');
        }

        $saved = $client->save($data);

        return self::text(($update ? 'Saved' : 'Created') . " scheduled task {$saved['id']}; its runs carry on in its own conversation, in the sidebar under its name.\n" . self::summary(ScheduledTask::fromArray($saved)));
    }

    /** @param array<string, mixed> $task */
    private function act(ScheduleClient $client, string $action, array $task, HookContext $ctx): AgentToolResult
    {
        $name = (string) $task['name'];
        $question = match ($action) {
            'delete' => ["Delete scheduled task '{$name}'?", 'It and the record of its runs are removed. Conversations its runs wrote are kept.'],
            'enable' => ["Switch on scheduled task '{$name}'?", self::when($task)],
            'disable' => ["Switch off scheduled task '{$name}'?", 'It stays in the list and runs only when asked again.'],
            default => ["Run scheduled task '{$name}' now?", 'Its prompt is sent in its conversation, in ' . $task['cwd'] . '.'],
        };

        if (!$ctx->ui->confirm($question[0], $question[1])) {
            return self::text('The user declined; nothing was changed.');
        }

        match ($action) {
            'delete' => $client->delete((string) $task['id']),
            'enable' => $client->setEnabled((string) $task['id'], true),
            'disable' => $client->setEnabled((string) $task['id'], false),
            default => $client->runNow((string) $task['id']),
        };

        return self::text(match ($action) {
            'delete' => "Deleted scheduled task '{$name}'.",
            'enable' => "Switched on '{$name}'.",
            'disable' => "Switched off '{$name}'.",
            default => "Started a run of '{$name}'; it is listed under the task in the web UI's Scheduled page.",
        });
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function existing(ScheduleClient $client, array $params): array
    {
        $id = trim((string) ($params['id'] ?? ''));
        if ($id === '') {
            throw new AgentError('This action needs the task\'s id; list shows them.');
        }

        return $client->get($id) ?? throw new AgentError("There is no scheduled task '{$id}'; list shows the ones there are.");
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

    /** @param list<array<string, mixed>> $tasks */
    private static function describeList(array $tasks): string
    {
        if ($tasks === []) {
            return 'No scheduled tasks.';
        }

        $lines = [];
        foreach ($tasks as $task) {
            $last = $task['lastRun'] ?? null;
            $lines[] = "- {$task['id']} \"{$task['name']}\": " . self::when($task) . "; folder {$task['cwd']}"
                . (($task['running'] ?? false) === true ? '; running now' : '')
                . (is_array($last) ? "; last run {$last['status']} " . self::date((int) $last['startedAt'], (string) $task['timezone']) : '; never run');
        }

        return implode("\n", $lines);
    }

    /** @param array<string, mixed> $task */
    private static function describeRuns(array $task, ScheduleClient $client): string
    {
        $runs = array_slice($client->runs((string) $task['id']), 0, self::RUNS);
        if ($runs === []) {
            return "'{$task['name']}' has not run yet.";
        }

        $lines = ["Recent runs of '{$task['name']}', newest first:"];
        foreach ($runs as $run) {
            $lines[] = "- {$run['status']} " . self::date((int) $run['startedAt'], (string) $task['timezone'])
                . " ({$run['trigger']})"
                . (is_string($run['error'] ?? null) ? ": {$run['error']}" : '')
                . (is_string($run['sessionFile'] ?? null) ? "; conversation {$run['sessionFile']}" : '');
        }

        return implode("\n", $lines);
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
