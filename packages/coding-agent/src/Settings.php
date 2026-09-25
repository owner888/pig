<?php

declare(strict_types=1);

namespace Pig\CodingAgent;

use Pig\Agent\QueueMode;
use Pig\Agent\ThinkingLevel;

/**
 * What someone chose last time, and what this project insists on.
 *
 * Two files, both JSON, both optional: `~/.pig/settings.json` is the person's and is
 * written back to; `<cwd>/.pig/settings.json` is the project's and is only ever read.
 * The project wins, which is the point of it being separate — a repository can say
 * "compaction keeps more here" without touching anyone's own preferences, and a `/theme`
 * typed in that repository still saves to the person's file and still loses to the
 * project's answer while they are in it.
 *
 * Upstream is 374 lines, most of it forty getter/setter pairs. The pairs here are only
 * for the settings something actually reads; the rest of upstream's keys are readable
 * through `get()` and keep their names, so a settings file written by either is read by
 * both, and a key gains a typed accessor when something needs one.
 *
 * Ported from upstream's `core/settings-manager.ts`.
 */
final class Settings
{
    private const string FILE = 'settings.json';

    /** @var array<string, mixed> the person's own, which is what gets written back */
    private array $global;

    /** @var array<string, mixed> the two merged, which is what gets read */
    private array $merged;

    /** @var list<string> files that could not be read, for the caller to complain about */
    private array $problems = [];

    /**
     * @param array<string, mixed> $global
     * @param array<string, mixed> $project
     */
    private function __construct(
        private readonly ?string $path,
        array $global,
        private readonly array $project,
    ) {
        $this->global = $global;
        $this->merged = self::merge($global, $project);
    }

    /** Read both files. A missing one is not a problem; an unreadable one is said out loud. */
    public static function load(string $cwd, ?string $home = null): self
    {
        $home ??= Config::home();
        $path = $home . '/' . self::FILE;

        [$global, $globalProblem] = self::read($path);
        [$project, $projectProblem] = self::read(rtrim($cwd, '/') . '/.pig/' . self::FILE);

        $settings = new self($path, $global, $project);
        $settings->problems = array_values(array_filter([$globalProblem, $projectProblem]));

        return $settings;
    }

    /**
     * Settings that are never written anywhere, for tests and for `--no-save`.
     *
     * @param array<string, mixed> $values
     */
    public static function inMemory(array $values = []): self
    {
        return new self(null, $values, []);
    }

    /** @return list<string> */
    public function problems(): array
    {
        return $this->problems;
    }

    // ---- the ones something reads ------------------------------------------------------

    public function theme(): ?string
    {
        $theme = $this->get('theme');

        return is_string($theme) && $theme !== '' ? $theme : null;
    }

    public function setTheme(string $theme): void
    {
        $this->set('theme', $theme);
    }

    public function defaultModel(): ?string
    {
        $model = $this->get('defaultModel');

        return is_string($model) && $model !== '' ? $model : null;
    }

    public function setDefaultModel(string $id, string $provider): void
    {
        $this->global['defaultModel'] = $id;
        $this->global['defaultProvider'] = $provider;
        $this->save();
    }

    public function defaultThinkingLevel(): ?ThinkingLevel
    {
        $level = $this->get('defaultThinkingLevel');

        return is_string($level) ? ThinkingLevel::tryFrom($level) : null;
    }

    public function setDefaultThinkingLevel(ThinkingLevel $level): void
    {
        $this->set('defaultThinkingLevel', $level->value);
    }

    public function hideThinking(): bool
    {
        return $this->get('hideThinkingBlock') === true;
    }

    public function setHideThinking(bool $hide): void
    {
        $this->set('hideThinkingBlock', $hide);
    }

    /**
     * How queued messages are handed over. Upstream's key and upstream's default.
     *
     * `one-at-a-time` unless the file says otherwise, which is the answer somebody typing three
     * separate thoughts usually means — an unknown value falls back to it rather than to `all`,
     * because getting all three at once is the surprising half of the choice.
     */
    /**
     * The version whose changelog this person has already been shown.
     *
     * Null means never — a first run, which upstream shows the whole file to once.
     */
    public function lastChangelogVersion(): ?string
    {
        $version = $this->get('lastChangelogVersion');

        return is_string($version) && $version !== '' ? $version : null;
    }

    public function setLastChangelogVersion(string $version): void
    {
        $this->set('lastChangelogVersion', $version);
    }

    public function queueMode(): QueueMode
    {
        $mode = $this->get('queueMode');

        return is_string($mode) ? QueueMode::tryFrom($mode) ?? QueueMode::OneAtATime : QueueMode::OneAtATime;
    }

    public function setQueueMode(QueueMode $mode): void
    {
        $this->set('queueMode', $mode->value);
    }

    /**
     * The proxy URL to reach providers through, or null for direct.
     *
     * A settings entry as well as an environment variable because the environment belongs to a
     * shell: pig started from a launcher, a desktop entry or another program has none, and the
     * network that needs a proxy needs it every time.
     */
    public function proxyUrl(): ?string
    {
        $url = $this->get('proxy.url');

        return is_string($url) && trim($url) !== '' ? trim($url) : null;
    }

    public function setProxyUrl(?string $url): void
    {
        $this->set('proxy.url', $url === null || trim($url) === '' ? null : trim($url));
    }

    /**
     * Hosts to reach directly anyway, the `no_proxy` list as an array.
     *
     * @return list<string>
     */
    public function proxyBypass(): array
    {
        $value = $this->get('proxy.bypass');

        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $entry): string => is_string($entry) ? trim($entry) : '', $value),
            static fn (string $entry): bool => $entry !== '',
        ));
    }

    public function showImages(): bool
    {
        return $this->get('terminal.showImages') !== false;
    }

    public function setShowImages(bool $show): void
    {
        $this->set('terminal.showImages', $show);
    }

    public function compactionEnabled(): bool
    {
        return $this->get('compaction.enabled') !== false;
    }

    public function setCompactionEnabled(bool $enabled): void
    {
        $this->set('compaction.enabled', $enabled);
    }

    public function compactionReserveTokens(int $fallback): int
    {
        $value = $this->get('compaction.reserveTokens');

        return is_int($value) && $value > 0 ? $value : $fallback;
    }

    public function compactionKeepRecentTokens(int $fallback): int
    {
        $value = $this->get('compaction.keepRecentTokens');

        return is_int($value) && $value > 0 ? $value : $fallback;
    }

    /**
     * Whether a 503 is waited out rather than reported.
     *
     * On unless it is turned off, like compaction: the failures this covers are the ones that
     * go away on their own, and someone who did not ask for auto-retry still did not ask to
     * lose a turn because the provider was busy for two seconds.
     */
    public function retryEnabled(): bool
    {
        return $this->get('retry.enabled') !== false;
    }

    public function setRetryEnabled(bool $enabled): void
    {
        $this->set('retry.enabled', $enabled);
    }

    /**
     * How many times a failed turn is tried again.
     *
     * **The key is `retry.maxRetries`, which is upstream's spelling, and it used to be
     * `retry.maxAttempts` here.** Its sibling `retry.baseDelayMs` was upstream's all along, and one
     * of two keys under the same object matching is the shape that gave this away — a `settings.json`
     * written for pi had its `maxRetries` silently ignored and got the built-in three. CLAUDE.md said
     * these were "upstream's keys" while one of the three was not. See CLAUDE.md.
     *
     * The method keeps the name `retryMaxAttempts`, because attempts is what the number counts and
     * `Retry::MAX_ATTEMPTS` is what it falls back to. The file speaks upstream's dialect; the code
     * speaks its own.
     */
    public function retryMaxAttempts(int $fallback): int
    {
        $value = $this->get('retry.maxRetries');

        return is_int($value) && $value > 0 ? $value : $fallback;
    }

    /** Seconds, though the setting is written in milliseconds as upstream writes it. */
    public function retryBaseDelay(float $fallback): float
    {
        $value = $this->get('retry.baseDelayMs');

        return is_int($value) && $value > 0 ? $value / 1000 : $fallback;
    }

    /**
     * Hook files named in the settings, on top of the two standard folders.
     *
     * Upstream's key exactly: a top-level `hooks` array of paths. The folders are not a
     * setting there either — they are always read, and `--no-hooks` is how they are not:
     * a switch here would have to live under `hooks` and that name is already this list.
     *
     * @return list<string>
     */
    public function hooks(): array
    {
        $value = $this->get('hooks');

        return is_array($value) ? array_values(array_map(strval(...), $value)) : [];
    }

    /**
     * Custom tool files named in the settings, on top of the two standard folders.
     *
     * Upstream's key exactly: a top-level `customTools` array of paths, each one a tool's
     * entry file. `--no-tools` is how none of them are loaded, for the same reason
     * `--no-hooks` is a flag rather than a setting — the name here is already this list.
     *
     * @return list<string>
     */
    public function customTools(): array
    {
        $value = $this->get('customTools');

        return is_array($value) ? array_values(array_map(strval(...), $value)) : [];
    }

    public function skillsEnabled(): bool
    {
        return $this->get('skills.enabled') !== false;
    }

    /**
     * @param string $key one of upstream's skill list settings
     * @return list<string>
     */
    public function skillList(string $key): array
    {
        $value = $this->get("skills.{$key}");

        return is_array($value) ? array_values(array_map(strval(...), $value)) : [];
    }

    // ---- everything else -----------------------------------------------------------------

    /**
     * One setting by name, with `.` for nesting: `compaction.enabled`.
     *
     * Returns null for anything not set, which every caller here turns into its own
     * default — a default kept next to the thing that uses it rather than here, where
     * nothing else would explain it.
     */
    public function get(string $key): mixed
    {
        $value = $this->merged;

        foreach (explode('.', $key) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return null;
            }

            $value = $value[$part];
        }

        return $value;
    }

    /** Set one, and write the person's file. A project setting still wins afterwards. */
    public function set(string $key, mixed $value): void
    {
        $parts = explode('.', $key);
        $target = &$this->global;

        foreach (array_slice($parts, 0, -1) as $part) {
            if (!isset($target[$part]) || !is_array($target[$part])) {
                $target[$part] = [];
            }

            $target = &$target[$part];
        }

        $target[$parts[count($parts) - 1]] = $value;
        unset($target);

        $this->save();
    }

    // ---- the files --------------------------------------------------------------------------

    /**
     * Write the person's settings, and merge the project's back over them.
     *
     * Re-merged rather than assumed: a project file can change between two saves, and a
     * setting that silently stopped applying would be a very quiet bug.
     */
    private function save(): void
    {
        $this->merged = self::merge($this->global, $this->project);

        if ($this->path === null) {
            return;
        }

        $directory = dirname($this->path);

        if (!is_dir($directory) && !mkdir($directory, 0o700, true) && !is_dir($directory)) {
            $this->problems[] = "Could not create {$directory}";

            return;
        }

        $json = json_encode($this->global, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($json === false || file_put_contents($this->path, $json . "\n") === false) {
            // Not fatal: someone with an unwritable home directory should still be able
            // to use the thing, just without it remembering.
            $this->problems[] = "Could not write {$this->path}";
        }
    }

    /**
     * @return array{0: array<string, mixed>, 1: string|null} the settings, then what went wrong
     */
    private static function read(string $path): array
    {
        if (!is_file($path)) {
            return [[], null];
        }

        $raw = is_readable($path) ? file_get_contents($path) : false;

        if ($raw === false) {
            return [[], "Could not read {$path}"];
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            // Named rather than ignored: a typo in a settings file is otherwise a
            // setting that quietly does nothing for the rest of its life.
            return [[], "{$path} is not valid JSON, so it was ignored"];
        }

        return [$decoded, null];
    }

    /**
     * The project's over the person's, one level deep.
     *
     * One level, not recursive: every nested thing in this format is a flat group of
     * scalars, and a deeper merge would be answering a question the format never asks.
     *
     * @param array<string, mixed> $base
     * @param array<string, mixed> $over
     * @return array<string, mixed>
     */
    private static function merge(array $base, array $over): array
    {
        $merged = $base;

        foreach ($over as $key => $value) {
            $merged[$key] = is_array($value) && !array_is_list($value) && isset($base[$key]) && is_array($base[$key])
                ? [...$base[$key], ...$value]
                : $value;
        }

        return $merged;
    }
}
