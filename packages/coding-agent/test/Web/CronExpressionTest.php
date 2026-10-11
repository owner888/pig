<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test\Web;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pig\CodingAgent\Web\Schedule\CronExpression;

final class CronExpressionTest extends TestCase
{
    private const string TZ = 'Asia/Singapore';

    private static function at(string $when, string $tz = self::TZ): DateTimeImmutable
    {
        return new DateTimeImmutable($when, new DateTimeZone($tz));
    }

    private static function next(string $cron, string $after, string $tz = self::TZ): string
    {
        return CronExpression::parse($cron)->next(self::at($after, $tz))->format('Y-m-d H:i D');
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function nexts(): iterable
    {
        yield 'every minute is the next one' => ['* * * * *', '2026-10-11 09:00:30', '2026-10-11 09:01 Sun'];
        yield 'a minute exactly on the time is strictly after it' => ['0 9 * * *', '2026-10-11 09:00:00', '2026-10-12 09:00 Mon'];
        yield 'daily before the time is today' => ['0 9 * * *', '2026-10-11 08:59:59', '2026-10-11 09:00 Sun'];
        yield 'hourly at minute 0' => ['0 * * * *', '2026-10-11 09:00:00', '2026-10-11 10:00 Sun'];
        yield 'weekdays skip the weekend' => ['30 8 * * 1-5', '2026-10-10 12:00:00', '2026-10-12 08:30 Mon'];
        yield 'seven is Sunday as well' => ['0 10 * * 7', '2026-10-12 00:00:00', '2026-10-18 10:00 Sun'];
        yield 'a step over the hours' => ['0 */6 * * *', '2026-10-11 07:00:00', '2026-10-11 12:00 Sun'];
        yield 'a step from a start' => ['5/20 * * * *', '2026-10-11 09:30:00', '2026-10-11 09:45 Sun'];
        yield 'a list' => ['0 9,17 * * *', '2026-10-11 10:00:00', '2026-10-11 17:00 Sun'];
        yield 'both day fields restricted means either' => ['0 9 1 * 1', '2026-10-02 00:00:00', '2026-10-05 09:00 Mon'];
        yield 'the month rolls over' => ['0 0 1 * *', '2026-10-11 00:00:00', '2026-11-01 00:00 Sun'];
        yield 'the 31st skips the short months' => ['0 0 31 * *', '2026-11-01 00:00:00', '2026-12-31 00:00 Thu'];
        yield 'leap day waits for a leap year' => ['0 0 29 2 *', '2026-10-11 00:00:00', '2028-02-29 00:00 Tue'];
    }

    #[DataProvider('nexts')]
    public function testNextIsTheFirstMatchingMinuteStrictlyAfter(string $cron, string $after, string $expected): void
    {
        self::assertSame($expected, self::next($cron, $after));
    }

    public function testTheTimeIsWallClockInTheTimezoneItIsAskedIn(): void
    {
        $cron = CronExpression::parse('0 9 * * *');
        $after = new DateTimeImmutable('2026-10-11 00:00:00', new DateTimeZone('UTC'));

        self::assertSame(
            '2026-10-11 09:00 +08:00',
            $cron->next($after->setTimezone(new DateTimeZone(self::TZ)))->format('Y-m-d H:i P'),
        );
        self::assertSame('2026-10-11 09:00 +00:00', $cron->next($after)->format('Y-m-d H:i P'));
    }

    public function testAnHourADaylightSavingChangeSkipsIsNotWaitedForEver(): void
    {
        // 2026-03-08 02:30 does not exist in New York; the next match is the day after.
        $next = CronExpression::parse('30 2 * * *')->next(self::at('2026-03-07 03:00:00', 'America/New_York'));

        self::assertGreaterThan(self::at('2026-03-07 03:00:00', 'America/New_York'), $next);
        self::assertSame('30', $next->format('i'));
    }

    public function testTheExpressionIsKeptWithItsSpacingTidied(): void
    {
        self::assertSame('0 9 * * 1-5', CronExpression::parse("  0  9 *\t* 1-5 ")->expression);
    }

    /** @return iterable<string, array{string, string}> */
    public static function refusals(): iterable
    {
        yield 'four fields' => ['* * * *', 'five fields'];
        yield 'six fields' => ['* * * * * *', 'five fields'];
        yield 'a minute past 59' => ['60 * * * *', 'minute field is out of range'];
        yield 'an hour past 23' => ['0 24 * * *', 'hour field is out of range'];
        yield 'day zero' => ['0 0 0 * *', 'day of month field is out of range'];
        yield 'month thirteen' => ['0 0 1 13 *', 'month field is out of range'];
        yield 'weekday eight' => ['0 0 * * 8', 'day of week field is out of range'];
        yield 'a backwards range' => ['0 5-1 * * *', 'hour field is out of range'];
        yield 'a step of zero' => ['*/0 * * * *', 'step must be at least 1'];
        yield 'a name' => ['0 9 * * MON', 'day of week field is not understood'];
        yield 'an empty list entry' => ['0 9,,17 * * *', 'hour field is not understood'];
    }

    #[DataProvider('refusals')]
    public function testAnExpressionThatIsNotOneIsRefusedNamingTheField(string $cron, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        CronExpression::parse($cron);
    }

    public function testADateThatNeverComesIsRefusedRatherThanSearchedForEver(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('never matches');

        CronExpression::parse('0 0 30 2 *')->next(self::at('2026-10-11 00:00:00'));
    }
}
