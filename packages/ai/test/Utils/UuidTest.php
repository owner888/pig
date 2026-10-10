<?php

declare(strict_types=1);

namespace Pig\Ai\Test\Utils;

use PHPUnit\Framework\TestCase;
use Pig\Ai\Utils\Uuid;
use RangeException;

/** Upstream's `uuidv7()`: the version and variant bits, the millisecond up front, and order kept within a millisecond. */
final class UuidTest extends TestCase
{
    public function testItIsAVersionSevenUuidNamingTheMillisecond(): void
    {
        $uuid = Uuid::v7(0x0123456789AB);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $uuid);
        $this->assertStringStartsWith('01234567-89ab-', $uuid);
    }

    public function testIdsMadeInTheSameMillisecondStillSortInOrder(): void
    {
        $ids = array_map(static fn (): string => Uuid::v7(1_700_000_000_000), range(1, 50));
        $sorted = $ids;
        sort($sorted);

        $this->assertSame($sorted, $ids);
        $this->assertCount(50, array_unique($ids));
    }

    public function testATimestampOutsideFortyEightBitsIsRefused(): void
    {
        $this->expectException(RangeException::class);
        Uuid::v7(-1);
    }
}
