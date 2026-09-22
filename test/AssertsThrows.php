<?php

declare(strict_types=1);

namespace Pig\Test;

use Closure;
use Throwable;

/**
 * assertThrows(): assert a throw mid-test and keep going with the exception in hand.
 *
 * PHPUnit's expectException() applies to a whole test method, which does not fit the
 * cases here that throw and then assert something about what was thrown.
 */
trait AssertsThrows
{
    /**
     * @param class-string<Throwable> $expected
     */
    protected function assertThrows(string $expected, Closure $fn, string $messageContains = ''): Throwable
    {
        try {
            $fn();
        } catch (Throwable $thrown) {
            self::assertInstanceOf($expected, $thrown);

            if ($messageContains !== '') {
                self::assertStringContainsString($messageContains, $thrown->getMessage());
            }

            return $thrown;
        }

        self::fail("Expected {$expected}, nothing thrown");
    }
}
