<?php

declare(strict_types=1);

namespace Billing;

final class Ledger
{
    /** @var list<Invoice> */
    private array $invoices = [];

    public function record(Invoice $invoice): void
    {
        $this->invoices[] = $invoice;
    }

    /** The sum of every invoice, in cents. */
    public function balance(): int
    {
        $balance = 0;
        foreach ($this->invoices as $invoice) {
            $balance += $invoice->sum();
        }

        return $balance;
    }

    /** @return list<Invoice> invoices whose sum is at least $cents */
    public function atLeast(int $cents): array
    {
        return array_values(array_filter($this->invoices, static fn (Invoice $i): bool => $i->sum() >= $cents));
    }
}
