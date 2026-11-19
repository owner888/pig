<?php

declare(strict_types=1);

namespace Billing;

/** Numbers about a set of invoices; `sum` here is a sum of sums, not Invoice::sum(). */
final class Stats
{
    /** @param list<Invoice> $invoices */
    public static function sum(array $invoices): int
    {
        return array_sum(array_map(static fn (Invoice $i): int => $i->sum(), $invoices));
    }

    /** @param list<Invoice> $invoices */
    public static function mean(array $invoices): float
    {
        return $invoices === [] ? 0.0 : self::sum($invoices) / count($invoices);
    }
}
