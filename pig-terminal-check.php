#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * What does this terminal actually do with the characters pig draws with?
 *
 * Throwaway diagnostic — not part of pig. Run it in the same terminal where the UI
 * came out wrong:   php pig-terminal-check.php
 */

$cols = (int) (trim((string) shell_exec('tput cols')) ?: 0);
$size = trim((string) shell_exec('stty size < /dev/tty 2>&1'));

echo "TERM       = " . (getenv('TERM') ?: '(unset)') . "\n";
echo "TERM_PROGRAM = " . (getenv('TERM_PROGRAM') ?: '(unset)') . "\n";
echo "LANG       = " . (getenv('LANG') ?: '(unset)') . "\n";
echo "LC_ALL     = " . (getenv('LC_ALL') ?: '(unset)') . "\n";
echo "tput cols  = {$cols}\n";
echo "stty size  = {$size}   (rows cols — this is what pig reads)\n";
echo "PHP        = " . PHP_VERSION . "\n\n";

if ($cols < 10) {
    echo "tput cols gave nothing useful; stop here and paste what you see.\n";
    exit(1);
}

/** Write $count copies of $char, then ask the terminal where the cursor ended up. */
function columnAfter(string $char, int $count): int
{
    $tty = @fopen('/dev/tty', 'r+');

    if ($tty === false) {
        return -1;
    }

    $before = trim((string) shell_exec('stty -g < /dev/tty'));
    shell_exec('stty raw -echo < /dev/tty');

    fwrite($tty, "\r" . str_repeat($char, $count) . "\x1b[6n");

    $reply = '';

    while (($byte = fread($tty, 1)) !== false && $byte !== 'R') {
        $reply .= $byte;

        if (strlen($reply) > 20) {
            break;
        }
    }

    shell_exec("stty {$before} < /dev/tty");
    fwrite($tty, "\r\x1b[2K");
    fclose($tty);

    return preg_match('/\[(\d+);(\d+)/', $reply, $m) === 1 ? (int) $m[2] : -1;
}

echo "Drawing 10 of each character, then asking the terminal which column it reached.\n";
echo "pig counts every one of these as 1 column wide.\n\n";

$suspects = [
    'ASCII #'                 => '#',
    'U+2500 ─ box drawing'    => '─',
    'U+2502 │ box drawing'    => '│',
    'U+2191 ↑ arrow'          => '↑',
    'U+2022 • bullet'         => '•',
    'U+2026 … ellipsis'       => '…',
];

$wide = [];

foreach ($suspects as $name => $char) {
    $column = columnAfter($char, 10);
    $drawn = $column - 1;
    $verdict = match (true) {
        $column === -1 => 'could not ask — run this in a real terminal, not through a pipe',
        $drawn === 10 => 'one column each  — fine',
        $drawn === 20 => 'TWO columns each — pig will draw off the right edge',
        default => "reached column {$column}?",
    };

    if ($drawn === 20) {
        $wide[] = $name;
    }

    printf("  %-26s %s\n", $name, $verdict);
}

echo "\n";

if ($wide !== []) {
    echo "Found it: this terminal renders ambiguous-width characters double-wide.\n";
    echo "pig's editor border is one U+2500 per column, so it comes out twice the\n";
    echo "terminal's width, wraps, and every cursor move after it lands a row low.\n";
} else {
    echo "Widths all look normal, so it is something else. Paste the whole output.\n";
}

echo "\nLast thing — a ruler, then pig's border at full width.\n";
echo "If the second line wraps onto a third, that is the bug:\n\n";
echo substr(str_repeat('1234567890', (int) ceil($cols / 10)), 0, $cols) . "\n";
echo str_repeat('─', $cols) . "\n";
