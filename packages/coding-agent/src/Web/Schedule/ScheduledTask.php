<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Web\Schedule;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Pig\Agent\ThinkingLevel;

/**
 * One scheduled task: a prompt, the directory it runs in, and when.
 *
 * pig's own, modelled on the Claude app's scheduled tasks — upstream has none. A task has one
 * conversation of its own, `$sessionFile`, made when the task is saved so it is in the sidebar at
 * once, and every run is a turn in it: the next run sees what the last one found, as the Claude app's
 * task conversation does. `ScheduleManager` gives it the file; nothing a client sends sets it.
 *
 * `$triggers` is what runs it, any number of them, as the Claude app's form adds one row per "+":
 *
 * - `{type: cron, cron}` — a five-field `CronExpression`, in `$timezone`. The page's presets
 *   (hourly, daily, weekdays, weekly) are all written as one.
 * - `{type: once, runAt}` — epoch milliseconds; dropped once it has fired, and a task left with
 *   no trigger by that is switched off.
 *
 * None at all is a task that runs only when somebody presses "Run now". `$schedule`, `$cron` and
 * `$runAt` are the first trigger, as a task with one was written before there could be more —
 * what an older `scheduled-tasks.json` holds, and what `fromArray()` still reads when there is no
 * `triggers`.
 *
 * `$permissionMode` is the Claude app's per-task "permission": `auto` answers yes to a run's
 * confirmations as they come (`ScheduleManager::observe()`), so the run does not stop to wait;
 * `ask` leaves every question for the person, as runs always did. A question that wants more than
 * a yes — a choice, some text — waits in both. A task written before there was a choice is `ask`.
 *
 * `$timezone` is the browser's, recorded when the task is saved: a daemon started from launchd has
 * whatever `date.timezone` PHP was built with, and "every day at 09:00" means the person's 09:00.
 *
 * `$anchorAt` is where the next occurrence is counted from — the last occurrence that fired, or the
 * moment the task was saved or switched on. Counting from it rather than from now is what lets a
 * run missed while the machine slept, or the server was down, happen once when it comes back.
 *
 * `fromArray()` refuses rather than defaults: a task with no prompt or a directory that is not there
 * is something somebody has to fix, and a run that quietly did something else is worse than none.
 */
final class ScheduledTask
{
    public const string MANUAL = 'manual';
    public const string CRON = 'cron';
    public const string ONCE = 'once';

    public const string AUTO = 'auto';
    public const string ASK = 'ask';

    /** The first trigger, as one task with one trigger was written: `MANUAL`, `CRON` or `ONCE`. */
    public readonly string $schedule;

    public readonly ?string $cron;

    public readonly ?int $runAt;

    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $prompt,
        public readonly string $cwd,
        /** @var list<array{type: string, cron?: string, runAt?: int}> */
        public readonly array $triggers,
        public readonly string $timezone,
        public readonly ?string $model,
        public readonly ?string $thinkingLevel,
        public readonly bool $enabled,
        public readonly int $createdAt,
        public readonly int $anchorAt,
        public readonly ?string $sessionFile = null,
        public readonly string $permissionMode = self::ASK,
    ) {
        $first = $triggers[0] ?? null;
        $this->schedule = $first['type'] ?? self::MANUAL;
        $this->cron = $first !== null && $first['type'] === self::CRON ? $first['cron'] : null;
        $this->runAt = $first !== null && $first['type'] === self::ONCE ? $first['runAt'] : null;
    }

    /**
     * @param array<string, mixed> $data
     * @throws InvalidArgumentException naming the field that is wrong
     */
    public static function fromArray(array $data): self
    {
        $name = trim(self::string($data, 'name'));
        $prompt = trim(self::string($data, 'prompt'));
        $cwd = rtrim(self::string($data, 'cwd'), '/') ?: '/';
        $timezone = self::string($data, 'timezone');

        if ($name === '') {
            throw new InvalidArgumentException('A scheduled task needs a name.');
        }

        if ($prompt === '') {
            throw new InvalidArgumentException('A scheduled task needs a prompt.');
        }

        if (!is_dir($cwd)) {
            throw new InvalidArgumentException("The folder '{$cwd}' does not exist.");
        }

        try {
            new DateTimeZone($timezone);
        } catch (\Exception $e) {
            throw new InvalidArgumentException("'{$timezone}' is not a timezone.", 0, $e);
        }

        $triggers = self::triggers($data);

        $mode = $data['permissionMode'] ?? self::ASK;
        if (!in_array($mode, [self::AUTO, self::ASK], true)) {
            throw new InvalidArgumentException("'" . (is_string($mode) ? $mode : gettype($mode)) . "' is not a permission mode: auto or ask.");
        }

        $model = $data['model'] ?? null;
        if ($model !== null && (!is_string($model) || preg_match('#^[^/\s]+/\S+$#', $model) !== 1)) {
            throw new InvalidArgumentException('A model is written provider/id.');
        }

        $thinking = $data['thinkingLevel'] ?? null;
        if ($thinking !== null && (!is_string($thinking) || ThinkingLevel::tryFrom($thinking) === null)) {
            throw new InvalidArgumentException("'" . (is_string($thinking) ? $thinking : gettype($thinking)) . "' is not a thinking level.");
        }

        $now = (int) floor(microtime(true) * 1000);
        $createdAt = isset($data['createdAt']) ? self::int($data, 'createdAt') : $now;

        return new self(
            // A letter in front, or an id of only digits becomes an integer the moment it is an array key.
            id: isset($data['id']) && is_string($data['id']) && $data['id'] !== '' ? $data['id'] : 't' . bin2hex(random_bytes(8)),
            name: $name,
            prompt: $prompt,
            cwd: $cwd,
            triggers: $triggers,
            timezone: $timezone,
            model: $model,
            thinkingLevel: $thinking,
            enabled: (bool) ($data['enabled'] ?? true),
            createdAt: $createdAt,
            anchorAt: isset($data['anchorAt']) ? self::int($data, 'anchorAt') : $now,
            sessionFile: is_string($data['sessionFile'] ?? null) && $data['sessionFile'] !== '' ? $data['sessionFile'] : null,
            permissionMode: $mode,
        );
    }

    /**
     * `triggers` as it was sent, or the one trigger `schedule` / `cron` / `runAt` say, as a task was
     * written before it could have more.
     *
     * @param array<string, mixed> $data
     * @return list<array{type: string, cron?: string, runAt?: int}>
     */
    private static function triggers(array $data): array
    {
        if (!array_key_exists('triggers', $data) || $data['triggers'] === null) {
            $schedule = self::string($data, 'schedule');

            return match ($schedule) {
                self::MANUAL => [],
                self::CRON => [self::trigger(['type' => self::CRON, 'cron' => $data['cron'] ?? null])],
                self::ONCE => [self::trigger(['type' => self::ONCE, 'runAt' => $data['runAt'] ?? null])],
                default => throw new InvalidArgumentException("'{$schedule}' is not a schedule: manual, cron or once."),
            };
        }

        if (!is_array($data['triggers']) || !array_is_list($data['triggers'])) {
            throw new InvalidArgumentException("'triggers' must be a list.");
        }

        return array_map(
            static fn (mixed $trigger): array => is_array($trigger) ? self::trigger($trigger) : throw new InvalidArgumentException('A trigger is an object with a type.'),
            $data['triggers'],
        );
    }

    /**
     * @param array<string, mixed> $trigger
     * @return array{type: string, cron?: string, runAt?: int}
     */
    private static function trigger(array $trigger): array
    {
        $type = $trigger['type'] ?? null;

        return match ($type) {
            self::CRON => ['type' => self::CRON, 'cron' => CronExpression::parse(self::string($trigger, 'cron'))->expression],
            self::ONCE => ['type' => self::ONCE, 'runAt' => self::int($trigger, 'runAt')],
            default => throw new InvalidArgumentException("'" . (is_string($type) ? $type : gettype($type)) . "' is not a trigger: cron or once."),
        };
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'prompt' => $this->prompt,
            'cwd' => $this->cwd,
            'triggers' => $this->triggers,
            'schedule' => $this->schedule,
            'cron' => $this->cron,
            'runAt' => $this->runAt,
            'timezone' => $this->timezone,
            'model' => $this->model,
            'thinkingLevel' => $this->thinkingLevel,
            'enabled' => $this->enabled,
            'createdAt' => $this->createdAt,
            'anchorAt' => $this->anchorAt,
            'sessionFile' => $this->sessionFile,
            'permissionMode' => $this->permissionMode,
        ];
    }

    /**
     * When this task next runs, in epoch milliseconds, or null when nothing will run it.
     *
     * Counted from `$anchorAt`, so an occurrence that has already passed is answered as it is — the
     * caller fires it at once — rather than skipped to the next one.
     */
    public function nextRunAt(): ?int
    {
        if (!$this->enabled) {
            return null;
        }

        $next = null;

        foreach ($this->triggers as $trigger) {
            $at = $trigger['type'] === self::ONCE
                ? $trigger['runAt']
                : (int) CronExpression::parse($trigger['cron'])->next(self::dateTime($this->anchorAt, $this->timezone))->format('Uv');
            $next = $next === null ? $at : min($next, $at);
        }

        return $next;
    }

    /** The `once` triggers whose moment is at or before `$now`, which have fired by then. */
    public function hasPastOnce(int $now): bool
    {
        foreach ($this->triggers as $trigger) {
            if ($trigger['type'] === self::ONCE && $trigger['runAt'] <= $now) {
                return true;
            }
        }

        return false;
    }

    /**
     * The task after its occurrence at `$now` fired: the `once` triggers that have passed are
     * dropped, and a task that had some and has no trigger left is switched off — it has done
     * what it was set up to do.
     */
    public function fired(int $now): self
    {
        $left = array_values(array_filter(
            $this->triggers,
            static fn (array $trigger): bool => $trigger['type'] !== self::ONCE || $trigger['runAt'] > $now,
        ));
        $spent = count($left) !== count($this->triggers);

        return new self(
            $this->id, $this->name, $this->prompt, $this->cwd, $left, $this->timezone, $this->model, $this->thinkingLevel,
            $spent && $left === [] ? false : $this->enabled, $this->createdAt, $now, $this->sessionFile, $this->permissionMode,
        );
    }

    /**
     * The same task switched on or off, counted from another moment, or with its conversation. Not
     * re-validated: these are the scheduler's, and a task whose folder has gone still has to be able
     * to record that its run could not start.
     */
    public function with(?bool $enabled = null, ?int $anchorAt = null, ?string $sessionFile = null): self
    {
        return new self(
            $this->id, $this->name, $this->prompt, $this->cwd, $this->triggers,
            $this->timezone, $this->model, $this->thinkingLevel,
            $enabled ?? $this->enabled, $this->createdAt, $anchorAt ?? $this->anchorAt, $sessionFile ?? $this->sessionFile,
            $this->permissionMode,
        );
    }

    /** A moment, in milliseconds, as a date in `$timezone` — the run's session name uses it too. */
    public static function dateTime(int $millis, string $timezone): DateTimeImmutable
    {
        return (new DateTimeImmutable('@' . intdiv($millis, 1000)))->setTimezone(new DateTimeZone($timezone));
    }

    /** @param array<string, mixed> $data */
    private static function string(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        if (!is_string($value)) {
            throw new InvalidArgumentException("'{$key}' must be a string.");
        }

        return $value;
    }

    /** @param array<string, mixed> $data */
    private static function int(array $data, string $key): int
    {
        $value = $data[$key] ?? null;

        if (!is_int($value)) {
            throw new InvalidArgumentException("'{$key}' must be a whole number.");
        }

        return $value;
    }
}
