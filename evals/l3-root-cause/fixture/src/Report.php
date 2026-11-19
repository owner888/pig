<?php

declare(strict_types=1);

namespace Orders;

final class Report
{
    /** @param list<array{sku: string, quantity: int, price: float}> $rows */
    public static function render(array $rows): string
    {
        $out = '';
        $total = 0.0;
        foreach ($rows as $row) {
            $out .= self::line($row['sku'], $row['quantity'], $row['price']);
            $total += $row['quantity'] * $row['price'];
        }

        return $out . sprintf("%-8s %14.2f\n", 'TOTAL', $total);
    }

    private static function line(string $sku, int $quantity, float $price): string
    {
        return sprintf("%-8s %4d x %7.2f\n", $sku, $quantity, $price);
    }
}
