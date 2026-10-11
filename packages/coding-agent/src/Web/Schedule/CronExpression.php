<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Web\Schedule;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * A five-field cron expression — `minute hour day-of-month month day-of-week` — and the next
 * minute it matches.
 *
 * pig's own, for scheduled tasks; upstream has no scheduler. Written by hand rather than pulled
 * in (zero third-party deps): the grammar is Vixie cron's numeric one — `*`, `n`, `a-b`, `* /n`,
 * `a-b/n` and comma lists of those. No month or weekday names, no `@daily`, no seconds: the page
 * builds every preset as a plain expression, and the custom field is for people who already
 * write cron.
 *
 * Two rules from Vixie cron that are easy to get wrong:
 *
 * - **Day-of-week `7` is Sunday**, as `0` is.
 * - **When both day fields are restricted, a day matches if either does** — `0 9 1 * 1` is the
 *   1st of the month *and* every Monday, not the Mondays that fall on the 1st.
 *
 * Times are wall-clock in the timezone of the `DateTimeImmutable` passed to `next()`, so a task
 * at `0 9 * * *` saved from a browser in Asia/Singapore runs at 09:00 there whatever the
 * server's `date.timezone` says.
 */
final class CronExpression
{
    /** @var array<string, array{int, int}> */
    private const array RANGES = [
        'minute' => [0, 59],
        'hour' => [0, 23],
        'day of month' => [1, 31],
        'month' => [1, 12],
        'day of week' => [0, 7],
    ];

    /**
     * How far ahead `next()` looks before deciding the expression never matches — `0 0 30 2 *`
     * (February 30th) parses and names no day. Four years and a day covers every leap cycle.
     */
    private const int HORIZON_DAYS = 1462;

    /**
     * @param array<int, true> $minutes
     * @param array<int, true> $hours
     * @param array<int, true> $days
     * @param array<int, true> $months
     * @param array<int, true> $weekdays 0–6, Sunday 0
     */
    private function __construct(
        public readonly string $expression,
        private readonly array $minutes,
        private readonly array $hours,
        private readonly array $days,
        private readonly array $months,
        private readonly array $weekdays,
        private readonly bool $daysRestricted,
        private readonly bool $weekdaysRestricted,
    ) {
    }

    /** @throws InvalidArgumentException naming the field that is wrong */
    public static function parse(string $expression): self
    {
        $fields = preg_split('/\s+/', trim($expression));

        if ($fields === false || count($fields) !== 5) {
            throw new InvalidArgumentException("A cron expression has five fields (minute hour day month weekday): '{$expression}'");
        }

        $sets = [];
        $names = array_keys(self::RANGES);

        foreach ($fields as $at => $field) {
            $sets[] = self::field($field, $names[$at]);
        }

        $weekdays = [];
        foreach (array_keys($sets[4]) as $day) {
            $weekdays[$day % 7] = true;
        }

        return new self(
            expression: implode(' ', $fields),
            minutes: $sets[0],
            hours: $sets[1],
            days: $sets[2],
            months: $sets[3],
            weekdays: $weekdays,
            daysRestricted: $fields[2] !== '*',
            weekdaysRestricted: $fields[4] !== '*',
        );
    }

    /**
     * The first whole minute strictly after `$after` that matches, in `$after`'s timezone.
     *
     * @throws InvalidArgumentException when nothing matches within four years (February 30th)
     */
    public function next(DateTimeImmutable $after): DateTimeImmutable
    {
        $at = $after->setTime((int) $after->format('G'), (int) $after->format('i'))->modify('+1 minute');
        $limit = $after->modify('+' . self::HORIZON_DAYS . ' days');

        while ($at <= $limit) {
            if (!isset($this->months[(int) $at->format('n')])) {
                $at = $at->modify('first day of next month')->setTime(0, 0);

                continue;
            }

            if (!$this->dayMatches($at)) {
                $at = $at->modify('+1 day')->setTime(0, 0);

                continue;
            }

            if (!isset($this->hours[(int) $at->format('G')])) {
                $at = $at->setTime((int) $at->format('G'), 0)->modify('+1 hour');

                continue;
            }

            if (!isset($this->minutes[(int) $at->format('i')])) {
                $at = $at->modify('+1 minute');

                continue;
            }

            return $at;
        }

        throw new InvalidArgumentException("Cron expression '{$this->expression}' never matches");
    }

    private function dayMatches(DateTimeImmutable $at): bool
    {
        $day = isset($this->days[(int) $at->format('j')]);
        $weekday = isset($this->weekdays[(int) $at->format('w')]);

        if ($this->daysRestricted && $this->weekdaysRestricted) {
            return $day || $weekday;
        }

        return $day && $weekday;
    }

    /** @return array<int, true> */
    private static function field(string $field, string $name): array
    {
        [$min, $max] = self::RANGES[$name];
        $set = [];

        foreach (explode(',', $field) as $part) {
            if (preg_match('/^(\*|\d+(?:-\d+)?)(?:\/(\d+))?$/', $part, $m) !== 1) {
                throw new InvalidArgumentException("Cron {$name} field is not understood: '{$field}'");
            }

            $step = isset($m[2]) ? (int) $m[2] : 1;

            if ($step < 1) {
                throw new InvalidArgumentException("Cron {$name} step must be at least 1: '{$field}'");
            }

            if ($m[1] === '*') {
                [$from, $to] = [$min, $max];
            } elseif (str_contains($m[1], '-')) {
                [$from, $to] = array_map('intval', explode('-', $m[1]));
            } else {
                // `5/15` is Vixie cron's "from 5, every 15" — the range runs to the field's end.
                $from = (int) $m[1];
                $to = isset($m[2]) ? $max : $from;
            }

            if ($from < $min || $to > $max || $from > $to) {
                throw new InvalidArgumentException("Cron {$name} field is out of range {$min}-{$max}: '{$field}'");
            }

            for ($value = $from; $value <= $to; $value += $step) {
                $set[$value] = true;
            }
        }

        return $set;
    }
}
