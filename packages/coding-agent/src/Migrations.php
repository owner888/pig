<?php

declare(strict_types=1);

namespace Pig\CodingAgent;

use Pig\CodingAgent\Session\SessionManager;

/**
 * Two one-time tidies of **pi's** directory, run at startup.
 *
 * Upstream's `migrations.ts`, and the reason it belongs here is the one thing that makes pig
 * unusual: pig does not have a directory of its own for these — it reads pi's sessions and it
 * shares pi's `auth.json`. So both of these are about somebody else's history, and both of them
 * are pig's problem: a pi install left mid-upgrade has credentials and conversations that pi can
 * see and pig cannot.
 *
 * That is why the old reason for leaving this unported — "pig writes pi's format and has never
 * shipped another" — was the wrong question. These migrate *pi's* old shapes, not pig's.
 *
 * **pig writing in pi's directory is a real thing to be uneasy about**, so the bound is worth
 * stating: what happens here is exactly what pi itself would do on its next start, so running it
 * converges rather than diverges — pi checks `auth.json` first and skips its own migration when
 * one is there. Nothing is deleted: `oauth.json` is renamed, not removed, and a session file is
 * moved rather than rewritten. And every step is skipped on the first sign of trouble, because a
 * half-migration of another tool's data is worse than none.
 */
final class Migrations
{
    /**
     * Run both. The provider names whose credentials moved, for the caller to mention.
     *
     * @return list<string>
     */
    public static function run(): array
    {
        $providers = self::authToAuthJson();
        self::sessionsFromAgentRoot();

        return $providers;
    }

    /**
     * pi's old `oauth.json` and `settings.json`'s `apiKeys` into one `auth.json`.
     *
     * **Nothing happens if `auth.json` is already there**, which is upstream's first line and the
     * whole safety of this: a file that exists is the current shape, and whatever is in the old
     * ones was superseded by it.
     *
     * @return list<string>
     */
    public static function authToAuthJson(?string $directory = null): array
    {
        $directory ??= Config::piHome();
        $authPath = $directory . '/auth.json';

        if (is_file($authPath)) {
            return [];
        }

        $migrated = [];
        $providers = [];

        // The OAuth half. Upstream spreads the old entry into `{type: "oauth", …}`, so a
        // credential keeps every field it had and gains the tag the new shape wants.
        $oauthPath = $directory . '/oauth.json';
        $oauth = self::read($oauthPath);

        if ($oauth !== null) {
            foreach ($oauth as $provider => $credentials) {
                if (is_string($provider) && is_array($credentials)) {
                    $migrated[$provider] = ['type' => 'oauth', ...$credentials];
                    $providers[] = $provider;
                }
            }

            // Renamed rather than deleted: this is the only copy of somebody's refresh tokens
            // until the new file is written, and a rename is reversible by hand.
            rename($oauthPath, $oauthPath . '.migrated');
        }

        // The api-key half, which also rewrites pi's settings file to drop the moved keys.
        $settingsPath = $directory . '/settings.json';
        $settings = self::read($settingsPath);
        $keys = $settings['apiKeys'] ?? null;

        if (is_array($keys)) {
            foreach ($keys as $provider => $key) {
                // An OAuth credential for the same provider wins, because it is the newer way
                // in and upstream keeps it for the same reason.
                if (is_string($provider) && is_string($key) && !isset($migrated[$provider])) {
                    $migrated[$provider] = ['type' => 'api_key', 'key' => $key];
                    $providers[] = $provider;
                }
            }

            unset($settings['apiKeys']);
            // No mode, which is upstream's plain `writeFileSync` and is also the only defensible
            // thing: this is pi's settings file and tightening it was never pig's to do. It stops
            // holding keys, which is the point; what it stops holding them *at* is pi's business.
            self::write($settingsPath, $settings);
        }

        if ($migrated !== []) {
            if (!is_dir($directory)) {
                mkdir($directory, 0o700, true);
            }

            // `0600` before there is anything to read, the same as `Auth::save()` — upstream
            // passes the mode to `writeFileSync`, which is the same idea and the same reason.
            self::write($authPath, $migrated, 0o600);
        }

        return $providers;
    }

    /**
     * Session files that pi 0.30.0 left at the root of its own directory.
     *
     * That version wrote them to `~/.pi/agent/*.jsonl` instead of
     * `~/.pi/agent/sessions/<the project's path, flattened>/`, which is where both tools look —
     * so a conversation from that version is invisible to `--resume` in either of them until it
     * is moved. Upstream's issue #320.
     *
     * Where each one belongs is read out of its own first line, which carries the `cwd` it was
     * recorded in. A file whose first line is not a session header is left alone: something else
     * put it there.
     */
    public static function sessionsFromAgentRoot(?string $directory = null): void
    {
        $directory ??= Config::piHome();
        $entries = is_dir($directory) ? scandir($directory) : false;

        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            // A scan rather than `glob('*.jsonl')`, which skips a name beginning with a dot —
            // upstream reads the directory, so a `.something.jsonl` is a stray session there and
            // would have been invisible here.
            if (!str_ends_with($entry, '.jsonl')) {
                continue;
            }

            $path = $directory . '/' . $entry;

            if (!is_file($path)) {
                continue;
            }

            $cwd = self::recordedCwd($path);

            if ($cwd === null) {
                continue;
            }

            $target = $directory . '/sessions/' . SessionManager::slug($cwd);

            // `0700`, which is what `SessionManager` and `Auth` create directories with — not
            // upstream's default umask. pig creates this exact directory itself in ordinary use,
            // so matching pig is what keeps one project's sessions from living under two modes.
            if (!is_dir($target) && !mkdir($target, 0o700, true) && !is_dir($target)) {
                continue;
            }

            $moved = $target . '/' . basename($path);

            // Never over the top of one that is already there. Two files with the same name are
            // two conversations, and the one already filed is the one that was filed on purpose.
            if (!is_file($moved)) {
                rename($path, $moved);
            }
        }
    }

    /** The `cwd` on a session file's header line, or null when it has no header. */
    private static function recordedCwd(string $path): ?string
    {
        $handle = is_readable($path) ? fopen($path, 'rb') : false;

        if ($handle === false) {
            return null;
        }

        // The first line only: these files are a conversation long and the header is line one.
        $first = fgets($handle);
        fclose($handle);

        if (!is_string($first) || trim($first) === '') {
            return null;
        }

        $header = json_decode($first, true);

        if (!is_array($header) || ($header['type'] ?? null) !== 'session') {
            return null;
        }

        $cwd = $header['cwd'] ?? null;

        return is_string($cwd) && $cwd !== '' ? $cwd : null;
    }

    /**
     * A JSON object from a file, or null for anything that is not one.
     *
     * Every failure is the same answer, which is upstream's `catch {}` written out: a file that
     * cannot be read, is not JSON, or is not an object is one this has no business rewriting.
     *
     * @return array<string, mixed>|null
     */
    private static function read(string $path): ?array
    {
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }

        $raw = file_get_contents($path);

        if ($raw === false) {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Written the way pig writes these files, not the way upstream does.
     *
     * Upstream's `JSON.stringify(x, null, 2)` indents by two and ends without a newline;
     * `Auth::save()` and `Settings::save()` both use `JSON_PRETTY_PRINT` — four — and end with
     * one. **`auth.json` is a file pig itself writes**, so matching pig is what keeps the next
     * ordinary save from reformatting the whole file; matching upstream here would mean the
     * migration produced a shape pig never produces.
     *
     * @param array<string, mixed> $data
     * @param int|null $mode for a file being created here; null leaves an existing file's alone
     */
    private static function write(string $path, array $data, ?int $mode = null): void
    {
        $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($encoded === false) {
            return;
        }

        // Only when creating, and narrowed before there is anything in it to read — `Auth::save()`'s
        // exact condition. A chmod that follows the write leaves the file world-readable for as
        // long as the two calls take, and that is long enough.
        if ($mode !== null && !is_file($path)) {
            touch($path);
            chmod($path, $mode);
        }

        file_put_contents($path, $encoded . "\n");
    }
}
