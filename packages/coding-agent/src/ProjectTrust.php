<?php

declare(strict_types=1);

namespace Pig\CodingAgent;

use Closure;
use Pig\CodingAgent\Hooks\Events\ProjectTrustEvent;
use Pig\CodingAgent\Hooks\HookRunner;
use RuntimeException;

/**
 * Whether a project's own `.pig/` directory may be loaded — upstream's `trust-manager.ts` and
 * `project-trust.ts`, which arrived there for the reason they arrive here.
 *
 * A hook, a custom tool or an extension under `<cwd>/.pig/` is PHP that is `require`d into this
 * process with this process's permissions, and `.pig/settings.json` can point `shellPath` at
 * anything executable. `git clone` and `cd` were the whole of what stood between a stranger's
 * repository and that. So the first time pig opens a project that *has* such files it asks, and
 * remembers the answer in `~/.pig/agent/trust.json`:
 *
 * ```json
 * { "/Users/dev/work/acme": true, "/Users/dev/scratch": false }
 * ```
 *
 * Keyed by absolute path, **nearest ancestor wins**, so trusting `~/work` once covers every
 * checkout under it. A project with no `.pig/` resources at all is trusted without a question,
 * because there is nothing to gate and a dialog about nothing is the dialog people learn to
 * click through.
 *
 * What an untrusted project loses is exactly its `.pig/` directory and `<cwd>/extensions`:
 * settings, hooks, tools, extensions, skills and commands. The person's own `~/.pig/agent/` is
 * always theirs. `AGENTS.md` / `CLAUDE.md` are **not** gated, as upstream does not gate them:
 * they are text the model reads, not code pig runs.
 *
 * No lock on the file, where upstream takes one through `proper-lockfile`. Two pigs saving a
 * trust decision in the same instant is a race over a one-line JSON object in which the later
 * write wins; the write is a rename so neither reader ever sees half a file.
 */
final class ProjectTrust
{
    public const string FILE = 'trust.json';

    /**
     * What under `<cwd>/.pig/` turns a directory into a project with something to trust. `git`
     * is where `pig install -l` clones a project package, which is code the loader would run.
     */
    public const array RESOURCES = ['settings.json', 'mcp.json', 'hooks', 'tools', 'extensions', 'skills', 'commands', 'git'];

    /**
     * Whether this directory has anything a trust decision would apply to.
     *
     * `<cwd>/extensions` counts only when it holds something the loader would load — a folder
     * called `extensions` is common and a prompt about an empty one is noise.
     */
    public static function hasResources(string $cwd): bool
    {
        return self::resources($cwd) !== [];
    }

    /**
     * What, exactly, a trust decision would apply to here — relative paths, so the question can
     * say *why* it is being asked. A prompt about a directory with no visible `.pig/` reads as
     * a mistake until it says `extensions/pig-antigravity/index.php`.
     *
     * @return list<string>
     */
    public static function resources(string $cwd): array
    {
        $cwd = rtrim($cwd, '/');
        $found = [];

        foreach (self::RESOURCES as $entry) {
            if (file_exists($cwd . '/.pig/' . $entry)) {
                $found[] = '.pig/' . $entry;
            }
        }

        foreach ([...(glob($cwd . '/extensions/*.php') ?: []), ...(glob($cwd . '/extensions/*/index.php') ?: [])] as $path) {
            $found[] = substr($path, strlen($cwd) + 1);
        }

        return $found;
    }

    /** The saved decision for this directory or its nearest ancestor, or null when none. */
    public static function decision(string $cwd, ?string $home = null): ?bool
    {
        return self::entry($cwd, $home)['decision'] ?? null;
    }

    /**
     * The saved decision and which path it was saved on.
     *
     * @return array{path: string, decision: bool}|null
     */
    public static function entry(string $cwd, ?string $home = null): ?array
    {
        $data = self::read(self::path($home));
        $current = self::canonical($cwd);

        while (true) {
            // `is_bool`, not `array_key_exists`: a `null` is a decision that was taken back, which
            // upstream's `findNearestTrustEntry()` walks past the same way.
            if (is_bool($data[$current] ?? null)) {
                return ['path' => $current, 'decision' => $data[$current]];
            }

            $parent = dirname($current);

            if ($parent === $current) {
                return null;
            }

            $current = $parent;
        }
    }

    /**
     * Save decisions. Null forgets the path, which is how "trust the parent" also clears a
     * narrower answer underneath it.
     *
     * @param array<string, bool|null> $updates path => decision
     */
    public static function remember(array $updates, ?string $home = null): void
    {
        $path = self::path($home);
        $data = self::read($path);

        foreach ($updates as $directory => $decision) {
            $key = self::canonical($directory);

            if ($decision === null) {
                unset($data[$key]);
            } else {
                $data[$key] = $decision;
            }
        }

        ksort($data);

        $dir = dirname($path);

        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException("Could not create {$dir}");
        }

        $json = json_encode($data === [] ? new \stdClass() : $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        $temp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';

        if (file_put_contents($temp, $json) === false || !rename($temp, $path)) {
            throw new RuntimeException("Could not write {$path}");
        }
    }

    /**
     * The choices a person is offered, upstream's five in upstream's order.
     *
     * @return list<TrustChoice>
     */
    public static function choices(string $cwd): array
    {
        $path = self::canonical($cwd);
        $parent = dirname($path);

        $choices = [new TrustChoice('Trust', true, [$path => true])];

        if ($parent !== $path) {
            $choices[] = new TrustChoice("Trust parent folder ({$parent})", true, [$parent => true, $path => null]);
        }

        $choices[] = new TrustChoice('Trust (this session only)', true, []);
        $choices[] = new TrustChoice('Do not trust', false, [$path => false]);
        $choices[] = new TrustChoice('Do not trust (this session only)', false, []);

        return $choices;
    }

    /** The question, worded as upstream words it — plus what was found, which upstream leaves out. */
    public static function prompt(string $cwd): string
    {
        $found = self::resources($cwd);
        $shown = array_slice($found, 0, 6);
        $more = count($found) - count($shown);
        $list = implode(', ', $shown) . ($more > 0 ? " and {$more} more" : '');

        return "Trust project folder?\n{$cwd}\n\nIt has: {$list}\n"
            . 'This allows pig to load .pig settings and resources and execute project hooks, tools and extensions.';
    }

    /**
     * Decide: nothing to gate means yes, an extension that answers wins, then a saved answer,
     * otherwise ask — and with nobody to ask, **no**. That last one is upstream's and the only
     * safe direction: `-p` on a stranger's repository must not run its hooks because there was
     * no terminal to refuse on.
     *
     * @param (Closure(list<TrustChoice>): ?TrustChoice)|null $ask draws the question; null
     *        (escape) is "do not trust, this session only"
     * @param HookRunner|null $extensions the extensions loaded before trust — the person's and
     *        the command line's — asked `project_trust` first, as upstream asks them. One that
     *        says `remember` is saved like an answer at the prompt.
     */
    public static function resolve(string $cwd, ?Closure $ask, ?string $home = null, ?HookRunner $extensions = null): bool
    {
        if (!self::hasResources($cwd)) {
            return true;
        }

        $answer = $extensions?->emitProjectTrust(new ProjectTrustEvent($cwd));

        if ($answer !== null) {
            $trusted = $answer->trusted === 'yes';

            if ($answer->remember) {
                self::remember([$cwd => $trusted], $home);
            }

            return $trusted;
        }

        $saved = self::decision($cwd, $home);

        if ($saved !== null) {
            return $saved;
        }

        if ($ask === null) {
            return false;
        }

        $chosen = $ask(self::choices($cwd));

        if ($chosen === null) {
            return false;
        }

        if ($chosen->updates !== []) {
            self::remember($chosen->updates, $home);
        }

        return $chosen->trusted;
    }

    /** What the warning says when a project is not trusted; the same sentence everywhere. */
    public static function warning(): string
    {
        return 'This project is not trusted. Project .pig resources are ignored. Use /trust to save a trust decision, then restart pig.';
    }

    public static function path(?string $home = null): string
    {
        return ($home ?? Config::home()) . '/' . self::FILE;
    }

    /** An absolute path with `.`/`..` collapsed and symlinks resolved where the path exists. */
    public static function canonical(string $path): string
    {
        $real = realpath($path);

        if ($real !== false) {
            return rtrim($real, '/') === '' ? '/' : rtrim($real, '/');
        }

        return Tools\Paths::resolve($path, getcwd() ?: '/');
    }

    /**
     * The file as written, `null` entries included.
     *
     * Upstream's `readTrustFile()` takes `true`, `false` **or `null`** — a `null` is what its
     * "forget this path" option writes, and a file pi wrote may carry one. Refusing it was a pig
     * that could not start on a trust store pi was happy with. Anything else is still refused:
     * a trust store that cannot be read must not be guessed at, and this is read before any
     * screen exists, so the refusal is the crash message and `/bug`.
     *
     * @return array<string, bool|null>
     */
    private static function read(string $path): array
    {
        if (!is_readable($path)) {
            return [];
        }

        $raw = file_get_contents($path);
        $parsed = $raw === false ? null : json_decode($raw, true);

        if (!is_array($parsed)) {
            throw new RuntimeException("Invalid trust store {$path}: expected a JSON object. Fix or delete the file; pig asks again for each project.");
        }

        $data = [];

        foreach ($parsed as $key => $value) {
            if ($value !== null && !is_bool($value)) {
                throw new RuntimeException("Invalid trust store {$path}: value for \"{$key}\" must be true, false or null. Fix or delete the file; pig asks again for each project.");
            }

            $data[(string) $key] = $value;
        }

        return $data;
    }
}
