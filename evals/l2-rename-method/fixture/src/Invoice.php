<?php

declare(strict_types=1);

namespace Billing;

final class Invoice
{
    /** @var list<array{string, int}> description and cents */
    private array $lines = [];

    public function __construct(public readonly string $number)
    {
    }

    public function addLine(string $description, int $cents): void
    {
        $this->lines[] = [$description, $cents];
    }

    /** @return list<array{string, int}> */
    public function lines(): array
    {
        return $this->lines;
    }

    /** What the invoice comes to, in cents. */
    public function sum(): int
    {
        return array_sum(array_column($this->lines, 1));
    }
}
