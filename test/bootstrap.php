<?php

declare(strict_types=1);

/*
 * The suite runs in a home directory of its own.
 *
 * pig's files default to `$HOME/.pig/agent` and pi's to `$HOME/.pi/agent` (`Config::home()`,
 * `Config::piHome()`), and a test that forgets to point `PIG_CODING_AGENT_DIR` somewhere — or that
 * clears it in `tearDown()` — falls back to them. Before this, a run on the developer's machine left
 * sessions under the real `~/.pig/agent/sessions` (named after the temp directory, so they showed
 * up in the web sidebar as "hi"), wrote `~/.pig/agent/nodes.json`, and put test `.jsonl` files in
 * the real Trash. Pointing `HOME` at a throwaway directory makes the fallback harmless for every
 * test, including the ones not written yet.
 *
 * `trash` on the PATH is a stand-in that deletes, because macOS's `trash` and Finder ignore `$HOME`
 * and would still reach the person's own Trash. The real `~/.pig/agent/bin` is appended to the PATH
 * so `fd` and `rg` that pig downloaded there are still found.
 */

require __DIR__ . '/../vendor/autoload.php';

$realHome = getenv('HOME');
$home = sys_get_temp_dir() . '/pig-test-home-' . getmypid() . '-' . bin2hex(random_bytes(3));
$bin = $home . '/.test-bin';
mkdir($bin, 0o700, true);

file_put_contents($bin . '/trash', "#!/bin/sh\n# Test stand-in: delete instead of trashing.\nfor f in \"\$@\"; do [ \"\$f\" = -- ] || rm -rf -- \"\$f\"; done\n");
chmod($bin . '/trash', 0o755);

$set = static function (string $name, ?string $value): void {
    if ($value === null) {
        putenv($name);
        unset($_ENV[$name], $_SERVER[$name]);
    } else {
        putenv("{$name}={$value}");
        $_ENV[$name] = $_SERVER[$name] = $value;
    }
};

$set('HOME', $home);
foreach (['PIG_CODING_AGENT_DIR', 'PI_CODING_AGENT_DIR', 'PI_HOME', 'XDG_DATA_HOME', 'XDG_CONFIG_HOME'] as $name) {
    $set($name, null);
}

$path = $bin . PATH_SEPARATOR . (string) getenv('PATH');
if (is_string($realHome) && $realHome !== '' && is_dir($realHome . '/.pig/agent/bin')) {
    $path .= PATH_SEPARATOR . $realHome . '/.pig/agent/bin';
}
$set('PATH', $path);

register_shutdown_function(static function () use ($home): void {
    if (!is_dir($home)) {
        return;
    }
    // A child a test started (a web daemon, a shell) can still be writing here; a file that
    // will not go is left for the OS to clear from the temp directory, not reported.
    set_error_handler(static fn (): bool => true);
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($home, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($items as $item) {
        $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($home);
    restore_error_handler();
});
