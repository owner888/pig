<?php

declare(strict_types=1);

namespace Pig\CodingAgent;

use Closure;
use Fiber;
use Pig\Async\Async;
use RuntimeException;

/**
 * Run something while no other process — and no other fiber — runs it on the same file.
 *
 * What upstream reaches for `proper-lockfile` for: two pigs renewing one OAuth token at the same
 * instant each send the same refresh token, the provider rotates it on the first, and the second
 * is refused — one of them is signed out. `Auth::fresh()` and the MCP extension's refresh take
 * this around the renewal, and re-read the file once they hold it, so the second to arrive finds
 * the first's token and sends nothing (upstream's double-checked locking in `auth/resolve.ts`).
 *
 * `flock()` rather than a port of the package: it is in PHP core, and it is the better lock. A
 * `proper-lockfile` lock is a directory that has to be *renewed* on a timer so a killed holder's
 * lock can be told stale; an `flock()` is released by the kernel the moment the holder's handle
 * closes, however it died, so there is no stale case and no `onCompromised`. The one thing to
 * know is that it is advisory and per *open file description*, which is why every holder opens
 * its own handle here — two fibers of one process then wait on each other as two processes do.
 *
 * Waiting polls, `LOCK_NB` every 100ms: a blocking `flock()` would stop the event loop, and a
 * renewal mid-turn is exactly where the loop has a socket and a keyboard to serve. In a fiber the
 * wait is `Async::delay()`; outside one it is `usleep()`, since there is no loop to keep.
 */
final class FileLock
{
    /**
     * The lock file for $file: `<file>.flock`.
     *
     * Not `<file>.lock`, which is what this used to take. That name is `proper-lockfile`'s, and to
     * it the lock is a *directory*: pi `mkdir`s `auth.json.lock` to write `auth.json`, finds a
     * plain file there, takes it for a stale lock, and `rmdir` fails with ENOTDIR — on every start,
     * as "models.json error: Availability refresh: ENOTDIR". pig shares pi's `auth.json`
     * (`Auth::discover()`) and its MCP token store, so the two names met in `~/.pi/agent`.
     */
    public static function pathFor(string $file): string
    {
        return $file . '.flock';
    }

    /**
     * Remove the `<file>.lock` an older pig left as a plain file (`pathFor()`), so pi can lock
     * $file again. A directory there is pi's own lock and is left alone.
     */
    public static function clearLegacy(string $file): void
    {
        $legacy = $file . '.lock';

        // Gone already, or not ours to remove: either way pi reports it, as before.
        if (is_file($legacy) && !is_link($legacy) && is_writable(dirname($legacy))) {
            set_error_handler(static fn (): bool => true);

            try {
                unlink($legacy);
            } finally {
                restore_error_handler();
            }
        }
    }

    /** How long to wait for another holder before giving up — upstream's `REFRESH_LOCK_WAIT_MS`. */
    public const float WAIT = 25.0;

    private const int POLL_MICROSECONDS = 100_000;

    /**
     * Run $fn holding the exclusive lock on $path, which is created `0600` if it is not there.
     *
     * @template T
     * @param Closure(): T $fn
     * @return T
     * @throws RuntimeException when the lock could not be taken within $wait seconds
     */
    public static function hold(string $path, Closure $fn, float $wait = self::WAIT): mixed
    {
        $directory = dirname($path);

        if (!is_dir($directory) && !mkdir($directory, 0o700, true) && !is_dir($directory)) {
            throw new RuntimeException("Could not create {$directory} for the lock file.");
        }

        $fresh = !is_file($path);
        $handle = fopen($path, 'c');

        if ($handle === false) {
            throw new RuntimeException("Could not open the lock file {$path}.");
        }

        if ($fresh) {
            chmod($path, 0o600);
        }

        $deadline = microtime(true) + $wait;

        while (!flock($handle, LOCK_EX | LOCK_NB)) {
            if (microtime(true) >= $deadline) {
                fclose($handle);

                throw new RuntimeException(sprintf('Another process has held %s for %.0fs; giving up.', $path, $wait));
            }

            if (Fiber::getCurrent() !== null) {
                Async::delay(self::POLL_MICROSECONDS / 1_000_000);
            } else {
                usleep(self::POLL_MICROSECONDS);
            }
        }

        try {
            return $fn();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
