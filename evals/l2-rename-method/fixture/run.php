<?php

declare(strict_types=1);

use Billing\Invoice;
use Billing\Ledger;
use Billing\Printer;
use Billing\Stats;

spl_autoload_register(static function (string $class): void {
    require __DIR__ . '/src/' . substr($class, strlen('Billing\\')) . '.php';
});

$a = new Invoice('A-1');
$a->addLine('Design', 120000);
$a->addLine('Hosting', 2500);

$b = new Invoice('A-2');
$b->addLine('Support', 9900);

$ledger = new Ledger();
$ledger->record($a);
$ledger->record($b);

echo Printer::render($a);
echo Printer::render($b);
echo 'Balance: ', Printer::money($ledger->balance()), "\n";
echo 'Large: ', implode(',', array_map(static fn (Invoice $i): string => $i->number, $ledger->atLeast(10000))), "\n";
echo 'Stats: ', Printer::money(Stats::sum([$a, $b])), ' / ', round(Stats::mean([$a, $b])), "\n";
