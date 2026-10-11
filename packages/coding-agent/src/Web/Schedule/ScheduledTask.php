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
 * pig's own, modelled on the Claude app's scheduled tasks — upstream has none. Each run is a fresh
 * conversation in a `pig --mode rpc` child, so nothing here carries state from one run to the next
 * except the run log `ScheduleManager` keeps beside it.
 *
 * Three kinds of `schedule`:
 *
 * - `manual` — runs only when somebody presses "Run now".
 * - `cron` — a five-field `CronExpression`, in `$timezone`. The page's presets (hourly, daily,
 *   weekdays, weekly) are all written as one.
 * - `once` — `$runAt`, epoch milliseconds; the task is switched off after it has run.
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

    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $prompt,
        public readonly string $cwd,
        public readonly string $schedule,
        public readonly ?string $cron,
        public readonly ?int $runAt,
        public readonly string $timezone,
        public readonly ?string $model,
        public readonly ?string $thinkingLevel,
        public readonly bool $enabled,
        public readonly int $createdAt,
        public readonly int $anchorAt,
    ) {
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
        $schedule = self::string($data, 'schedule');
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

        $cron = null;
        $runAt = null;

        match ($schedule) {
            self::MANUAL => null,
            self::CRON => $cron = CronExpression::parse(self::string($data, 'cron'))->expression,
            self::ONCE => $runAt = self::int($data, 'runAt'),
            default => throw new InvalidArgumentException("'{$schedule}' is not a schedule: manual, cron or once."),
        };

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
            schedule: $schedule,
            cron: $cron,
            runAt: $runAt,
            timezone: $timezone,
            model: $model,
            thinkingLevel: $thinking,
            enabled: (bool) ($data['enabled'] ?? true),
            createdAt: $createdAt,
            anchorAt: isset($data['anchorAt']) ? self::int($data, 'anchorAt') : $now,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'prompt' => $this->prompt,
            'cwd' => $this->cwd,
            'schedule' => $this->schedule,
            'cron' => $this->cron,
            'runAt' => $this->runAt,
            'timezone' => $this->timezone,
            'model' => $this->model,
            'thinkingLevel' => $this->thinkingLevel,
            'enabled' => $this->enabled,
            'createdAt' => $this->createdAt,
            'anchorAt' => $this->anchorAt,
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

        return match ($this->schedule) {
            self::ONCE => $this->runAt,
            self::CRON => (int) CronExpression::parse((string) $this->cron)
                ->next(self::dateTime($this->anchorAt, $this->timezone))
                ->format('Uv'),
            default => null,
        };
    }

    /**
     * The same task switched on or off, or counted from another moment. Not re-validated: these two
     * are the scheduler's, and a task whose folder has gone still has to be able to record that its
     * run could not start.
     */
    public function with(?bool $enabled = null, ?int $anchorAt = null): self
    {
        return new self(
            $this->id, $this->name, $this->prompt, $this->cwd, $this->schedule, $this->cron, $this->runAt,
            $this->timezone, $this->model, $this->thinkingLevel,
            $enabled ?? $this->enabled, $this->createdAt, $anchorAt ?? $this->anchorAt,
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
