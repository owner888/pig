<?php

declare(strict_types=1);

require __DIR__ . '/src/OrderParser.php';
require __DIR__ . '/src/Report.php';

echo Orders\Report::render(Orders\OrderParser::parse((string) file_get_contents($argv[1] ?? __DIR__ . '/orders.csv')));
