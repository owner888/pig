<?php

declare(strict_types=1);

// Upstream's `test/fixtures/exit-without-stop.ts`, with a third way out PHP has: a fatal error.

use Pig\Tui\ProcessTerminal;
use Pig\Tui\TuiAltScreen;
use Pig\Tui\TuiAltScreenOptions;
use Pig\Tui\TuiStopOptions;

require __DIR__ . '/../../../../vendor/autoload.php';

$tui = new TuiAltScreen(new ProcessTerminal(), false, null, new TuiAltScreenOptions(mouse: true));
$tui->start();
if ($argv[1] === 'stop') {
    $tui->stop(new TuiStopOptions(preserveScreen: true));
}
if ($argv[1] === 'fatal') {
    ini_set('memory_limit', '16M');
    $grow = [];
    while (true) {
        $grow[] = str_repeat('x', 1 << 20);
    }
}
exit(0);
