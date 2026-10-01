<?php

declare(strict_types=1);

namespace Pig\CodingAgent;

use Throwable;

/**
 * The last few times pig fell over, written down so `/bug` can attach them — upstream's
 * `core/crash-log.ts`.
 *
 * `~/.pig/agent/crashes.json`: at most five records, the newest last, each with the time, the
 * version, what kind of failure, the message, the stack, the session file and the directory.
 * Everything in here is **best effort**: the callers are already crashing, and a crash log that
 * throws is a second crash on top of the first with the first one lost. So `record()` swallows
 * everything — the one place in `coding-agent` that is allowed to — and `read()` answers `[]` for
 * a file it cannot make sense of.
 *
 * Two kinds here, where upstream has one. `fatal_error` is what `bin/pig`'s uncaught-exception
 * handler writes on the way out. `loop_error` is a throw the event loop's handler caught — a
 * render that died, a callback that threw — which pig *survives* (CLAUDE.md, the entry on
 * `Loop::setErrorHandler`) and draws as a red line. It is recorded all the same, because a thing
 * the loop had to catch is a pig bug by definition, and the person who sees the red line is
 * exactly who `/bug` is for.
 */
final class CrashLog
{
    public const string FILE = 'crashes.json';

    private const int MAX_RECORDS = 5;

    /** A week, after which a crash is too old to greet somebody with. */
    private const int MAX_AGE = 7 * 24 * 60 * 60;

    /**
     * @return list<array<string, mixed>>
     */
    public static function read(?string $path = null): array
    {
        $path ??= self::path();

        if (!is_readable($path)) {
            return [];
        }

        $raw = file_get_contents($path);
        $parsed = $raw === false ? null : json_decode($raw, true);

        if (!is_array($parsed)) {
            return [];
        }

        return array_values(array_filter(
            $parsed,
            static fn ($record): bool => is_array($record)
                && is_string($record['timestamp'] ?? null)
                && is_string($record['message'] ?? null),
        ));
    }

    /**
     * Write one down. Never throws: see the class docblock.
     *
     * @return array<string, mixed>|null the record, or null when it could not be written
     */
    public static function record(string $kind, Throwable $error, ?string $sessionFile, string $cwd, ?string $path = null): ?array
    {
        try {
            $path ??= self::path();
            $record = [
                'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
                'version' => Version::current(),
                'kind' => $kind,
                'message' => $error->getMessage() !== '' ? $error->getMessage() : $error::class,
                // Where it was thrown goes first: `getTraceAsString()` starts at the caller of
                // the throwing frame, so the one line a report needs most is not in it.
                'stack' => sprintf(
                    "%s: %s in %s:%d\n%s",
                    $error::class,
                    $error->getMessage(),
                    $error->getFile(),
                    $error->getLine(),
                    $error->getTraceAsString(),
                ),
                'sessionFile' => $sessionFile,
                'cwd' => $cwd,
            ];

            self::write([...self::read($path), $record], $path);

            return $record;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The newest crash nobody has been told about yet, if it is recent — and mark them all told.
     *
     * @return array<string, mixed>|null
     */
    public static function takeUnnotified(?string $path = null, ?int $now = null): ?array
    {
        $path ??= self::path();
        $now ??= time();
        $records = self::read($path);
        $crash = null;

        foreach (array_reverse($records) as $record) {
            $at = strtotime($record['timestamp']);

            if (($record['notified'] ?? false) !== true && $at !== false && $now - $at <= self::MAX_AGE) {
                $crash = $record;
                break;
            }
        }

        if ($crash === null) {
            return null;
        }

        try {
            self::write(array_map(
                static fn (array $record): array => [...$record, 'notified' => true],
                $records,
            ), $path);
        } catch (Throwable) {
            // Showing the notice again is harmless.
        }

        return $crash;
    }

    /** After a report has been sent: these have been handed over. Never throws. */
    public static function clear(?string $path = null): void
    {
        $path ??= self::path();

        // Checked rather than suppressed: a file that cannot be removed is attached again next
        // time, which is harmless.
        if (is_file($path) && is_writable(dirname($path))) {
            unlink($path);
        }
    }

    /** The notice at startup, worded as upstream words it. */
    public static function notice(array $crash): string
    {
        $when = date('Y-m-d H:i', strtotime($crash['timestamp']) ?: time());

        return "pig crashed on {$when} ({$crash['message']}). Run /bug to report it; the crash details are attached automatically.";
    }

    public static function path(): string
    {
        return Config::home() . '/' . self::FILE;
    }

    /** @param list<array<string, mixed>> $records */
    private static function write(array $records, string $path): void
    {
        $records = array_slice($records, -self::MAX_RECORDS);
        $dir = dirname($path);

        // `mkdir()` and `file_put_contents()` warn *and* answer false, and a warning out of a
        // crash handler is the second crash this class exists not to be. The handler turns the
        // warning into the throw `record()` already swallows; `@` is not allowed here.
        set_error_handler(static function (int $no, string $message): never {
            throw new \RuntimeException($message);
        });

        try {
            if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
                throw new \RuntimeException("Could not create {$dir}");
            }

            $json = json_encode($records, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

            if ($json === false || file_put_contents($path, $json . "\n") === false) {
                throw new \RuntimeException("Could not write {$path}");
            }
        } finally {
            restore_error_handler();
        }
    }
}
