<?php

declare(strict_types=1);

namespace Pig\Tui\Test;

use PHPUnit\Framework\TestCase;
use Pig\Tui\WheelScrollAccelerator;

/** Upstream's `wheel-scroll.test.ts`. */
final class WheelScrollAcceleratorTest extends TestCase
{
    /**
     * @param list<float> $times
     * @param -1|1 $direction
     * @return list<int>
     */
    private static function scroll(WheelScrollAccelerator $accelerator, array $times, int $direction = 1): array
    {
        return array_map(static fn (float $time): int => $accelerator->next($direction, $time), $times);
    }

    public function testUsesFixedLineCountsRegardlessOfTiming(): void
    {
        $accelerator = new WheelScrollAccelerator(3, true);
        $this->assertSame([3, 3, 3, 3], self::scroll($accelerator, [0, 10, 20, 1000]));
        $accelerator->setLines(0);
        $this->assertSame(1, $accelerator->next(1, 2000));
    }

    public function testKeepsOneLinePerEventInAutoModeWhenTheTerminalAlreadyAccelerates(): void
    {
        $accelerator = new WheelScrollAccelerator('auto', false);
        $this->assertSame([1, 1, 1, 1], self::scroll($accelerator, [0, 10, 20, 30]));
    }

    public function testScalesAutoModeWithWheelVelocity(): void
    {
        $accelerator = new WheelScrollAccelerator('auto', true);
        $this->assertSame([1, 1, 1, 1], self::scroll($accelerator, [0, 150, 300, 450]));
        $this->assertSame([1, 2, 2, 2], self::scroll($accelerator, [1000, 1050, 1100, 1150]));
        $this->assertSame([1, 5, 5, 5], self::scroll($accelerator, [2000, 2020, 2040, 2060]));
        $this->assertSame([1, 6, 6, 6], self::scroll($accelerator, [3000, 3010, 3020, 3030]));
    }

    public function testDoesNotAccelerateBurstsOfEventsForASingleNotch(): void
    {
        $accelerator = new WheelScrollAccelerator('auto', true);
        $this->assertSame([1, 1, 1, 1], self::scroll($accelerator, [0, 3, 6, 9]));
    }

    public function testResetsAccelerationOnDirectionChangesAndPauses(): void
    {
        $accelerator = new WheelScrollAccelerator('auto', true);
        $this->assertSame([1, 5, 5], self::scroll($accelerator, [0, 20, 40]));
        $this->assertSame(1, $accelerator->next(-1, 60));
        $this->assertSame([1, 5], self::scroll($accelerator, [500, 520]));
    }

    public function testCarriesFractionalLinesBetweenEvents(): void
    {
        $accelerator = new WheelScrollAccelerator('auto', true);
        $this->assertSame([1, 2, 3, 2, 3], self::scroll($accelerator, [0, 40, 80, 120, 160]));
    }
}
