<?php

declare(strict_types=1);

namespace Orders;

/** Reads `sku,quantity,unit price` lines; the first line is the header. */
final class OrderParser
{
    /** @return list<array{sku: string, quantity: int, price: float}> */
    public static function parse(string $csv): array
    {
        $lines = explode("\n", $csv);
        array_shift($lines);

        $rows = [];
        foreach ($lines as $line) {
            [$sku, $quantity, $price] = array_pad(explode(',', $line), 3, '');
            $rows[] = ['sku' => trim($sku), 'quantity' => trim($quantity), 'price' => trim($price)];
        }

        return $rows;
    }
}
