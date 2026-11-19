<?php

declare(strict_types=1);

namespace Billing;

final class Printer
{
    public static function render(Invoice $invoice): string
    {
        $out = "Invoice {$invoice->number}\n";
        foreach ($invoice->lines() as [$description, $cents]) {
            $out .= sprintf("  %-12s %8s\n", $description, self::money($cents));
        }

        return $out . sprintf("  %-12s %8s\n", 'Total', self::money($invoice->sum()));
    }

    public static function money(int $cents): string
    {
        return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
    }
}
