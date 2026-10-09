<?php

declare(strict_types=1);

namespace Pig\CodingAgent;

use Pig\Ai\Utils\Retry;

use Pig\Agent\QueueMode;
use Pig\Agent\ThinkingLevel;

/**
 * What someone chose last time, and what this project insists on.
 *
 * Two files, both JSON, both optional: `~/.pig/agent/settings.json` is the person's and is
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

    /** @var array<string, mixed> the project's own, written back by the `-l` package commands */
    private array $project;

    /**
     * @param array<string, mixed> $global
     * @param array<string, mixed> $project
     * @param string|null $projectPath the project's file, for the package commands that write it
     */
    private function __construct(
        private readonly ?string $path,
        array $global,
        array $project,
        private readonly ?string $projectPath = null,
        private readonly bool $projectTrusted = true,
    ) {
        $this->global = $global;
        $this->project = $project;
        $this->merged = self::merge($global, $project);
    }

    /**
     * Read both files. A missing one is not a problem; an unreadable one is said out loud.
     *
     * An untrusted project's file is not read at all rather than read and ignored — see
     * `ProjectTrust`. `shellPath` alone is reason enough: it names what every command runs in.
     */
    public static function load(string $cwd, ?string $home = null, bool $projectTrusted = true): self
    {
        $home ??= Config::home();
        $path = $home . '/' . self::FILE;

        $projectPath = rtrim($cwd, '/') . '/.pig/' . self::FILE;
        [$global, $globalProblem] = self::read($path);
        [$project, $projectProblem] = $projectTrusted
            ? self::read($projectPath)
            : [[], null];

        $settings = new self($path, $global, $project, $projectPath, $projectTrusted);
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

    /** Whether the project's file was read — `ProjectTrust`'s answer, kept for the package commands. */
    public function isProjectTrusted(): bool
    {
        return $this->projectTrusted;
    }

    // ---- packages ------------------------------------------------------------------------------

    /**
     * Upstream's `packages` array, per scope: each entry a source string, or an object with
     * `source` and the resource filters (`autoload`, `extensions`, `skills`, `prompts`, `themes`).
     * Read per scope rather than merged, because the two lists are reconciled by package identity
     * (`PackageManager::resolve()`), not by position.
     *
     * @param 'user'|'project' $scope
     * @return list<string|array<string, mixed>>
     */
    public function packages(string $scope = 'user'): array
    {
        $value = ($scope === 'project' ? $this->project : $this->global)['packages'] ?? null;

        if (!is_array($value)) {
            return [];
        }

        $packages = [];

        foreach ($value as $entry) {
            if (is_string($entry) && trim($entry) !== '') {
                $packages[] = $entry;
            } elseif (is_array($entry) && is_string($entry['source'] ?? null)) {
                $packages[] = $entry;
            }
        }

        return $packages;
    }

    /**
     * Upstream's `setPackages()` / `setProjectPackages()`: the list replaced and that scope's file
     * written.
     *
     * @param list<string|array<string, mixed>> $packages
     * @param 'user'|'project' $scope
     */
    public function setPackages(array $packages, string $scope = 'user'): void
    {
        if ($scope === 'project') {
            $this->project['packages'] = array_values($packages);
            $this->saveProject();

            return;
        }

        $this->global['packages'] = array_values($packages);
        $this->save();
    }

    // ---- the ones something reads ------------------------------------------------------

    public function tuiMode(): string
    {
        $mode = $this->get('tui.mode') ?? $this->get('tuiMode');

        return $mode === 'regular' ? 'regular' : 'fullscreen';
    }

    public function setTuiMode(string $mode): void
    {
        $this->set('tuiMode', $mode === 'regular' ? 'regular' : 'fullscreen');
    }

    /** Upstream's `getFullscreenExitOutput()`: print the transcript, or only the resume hint, on leaving fullscreen. */
    public function fullscreenExitOutput(): string
    {
        return $this->get('fullscreenExitOutput') === 'resume-hint' ? 'resume-hint' : 'transcript';
    }

    public function setFullscreenExitOutput(string $output): void
    {
        $this->set('fullscreenExitOutput', $output === 'resume-hint' ? 'resume-hint' : 'transcript');
    }

    /** @return 'auto'|'always'|'hidden' upstream's `getFullscreenScrollbar()` */
    public function fullscreenScrollbar(): string
    {
        $mode = $this->get('fullscreenScrollbar');

        return $mode === 'always' || $mode === 'hidden' ? $mode : 'auto';
    }

    /** @param 'auto'|'always'|'hidden' $mode */
    public function setFullscreenScrollbar(string $mode): void
    {
        $this->set('fullscreenScrollbar', $mode);
    }

    public function fullscreenCopyOnSelect(): bool
    {
        $value = $this->get('fullscreenCopyOnSelect');

        return is_bool($value) ? $value : true;
    }

    public function setFullscreenCopyOnSelect(bool $enabled): void
    {
        $this->set('fullscreenCopyOnSelect', $enabled);
    }

    /** @return int|'auto' upstream's `getFullscreenWheelScrollLines()`: a whole number from 1 to 100, or `'auto'` */
    public function fullscreenWheelScrollLines(): int|string
    {
        $lines = $this->get('fullscreenWheelScrollLines');

        return is_int($lines) || (is_float($lines) && is_finite($lines)) ? max(1, min(100, (int) floor($lines))) : 'auto';
    }

    /** @param int|'auto' $lines */
    public function setFullscreenWheelScrollLines(int|string $lines): void
    {
        $this->set('fullscreenWheelScrollLines', $lines === 'auto' ? 'auto' : max(1, min(100, (int) $lines)));
    }

    public function theme(): ?string
    {
        $theme = $this->get('theme');

        return is_string($theme) && $theme !== '' ? $theme : null;
    }

    /**
     * The shell to run commands with, when this machine wants a particular one.
     *
     * Upstream's key. It earns a typed accessor because of macOS: `/bin/bash` there is **3.2**
     * — no `mapfile`, no `${var^^}` — and a homebrew bash 5 does not get picked up by being
     * installed, since `Shell::bash()` prefers `/bin/bash`. pig stored this key and nothing read
     * it, which is the `terminal.showImages` shape again.
     */
    public function shellPath(): ?string
    {
        $path = $this->get('shellPath');

        return is_string($path) && $path !== '' ? $path : null;
    }

    /**
     * External editor command for Ctrl+G external prompt editing.
     * Takes precedence over VISUAL / EDITOR environment variables (aligned with upstream pi).
     */
    public function externalEditor(): ?string
    {
        $editor = $this->get('externalEditor');

        return is_string($editor) && trim($editor) !== '' ? trim($editor) : null;
    }

    public function setExternalEditor(?string $editor): void
    {
        $this->set('externalEditor', $editor !== null && trim($editor) !== '' ? trim($editor) : null);
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

    /**
     * Which provider the remembered model belongs to.
     *
     * **Written since the beginning and read by nothing until now**, which is only visible for a
     * resold id: `defaultModel` alone is a *bare* id, and a bare id means the direct provider by
     * `Models::RESOLD`'s rule. So a session last used on `antigravity/gemini-3.8-flash` came back
     * on Google's public `gemini-3.8-flash` — same id, different model, different price — and for
     * `claude-sonnet-4-5` the bare answer happened to be the right one, which is why it went
     * unnoticed. See `CodingAgent`, which reads both together now.
     */
    public function defaultProvider(): ?string
    {
        $provider = $this->get('defaultProvider');

        return is_string($provider) && $provider !== '' ? $provider : null;
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

    /**
     * The models a turn moves on to when the current one has run out of quota, in order.
     *
     * pig's own key, with no upstream counterpart: `fallbackModels`, a list of patterns in
     * `--models`' spelling (`google/gemini-3.8-flash:medium`). `defaultModel` and `defaultProvider`
     * are untouched by it — this is what comes *after* them, not a replacement. Anything that is
     * not a list of strings reads as none.
     *
     * @return list<string>
     */
    public function fallbackModels(): array
    {
        $patterns = $this->get('fallbackModels');

        if (!is_array($patterns)) {
            return [];
        }

        return array_values(array_filter($patterns, static fn (mixed $pattern): bool => is_string($pattern) && trim($pattern) !== ''));
    }

    /**
     * Upstream's `getEditorPaddingX()`: `editorPaddingX`, whole columns from 0 to 3, 0 unless set.
     * Blank columns either side of what is typed; the rules above and below are not narrowed.
     */
    public function editorPaddingX(): int
    {
        $value = $this->get('editorPaddingX');

        return is_int($value) || is_float($value) ? max(0, min(3, (int) floor($value))) : 0;
    }

    /** Upstream's `setEditorPaddingX()`: `Math.max(0, Math.min(3, Math.floor(padding)))`. */
    public function setEditorPaddingX(int $padding): void
    {
        $this->set('editorPaddingX', max(0, min(3, $padding)));
    }

    /**
     * Upstream's `getOutputPad()`: `outputPad`, the blank column either side of a message, a
     * tool's output and a command's — 1 unless the file says exactly 0.
     *
     * @return 0|1
     */
    public function outputPad(): int
    {
        return $this->get('outputPad') === 0 ? 0 : 1;
    }

    /** @param 0|1 $padding */
    public function setOutputPad(int $padding): void
    {
        $this->set('outputPad', $padding === 0 ? 0 : 1);
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

    /** Upstream's `getClearOnShrink()`: the setting, then `PIG_CLEAR_ON_SHRINK`/`PI_CLEAR_ON_SHRINK=1`, then off. */
    public function clearOnShrink(): bool
    {
        $value = $this->get('terminal.clearOnShrink');
        if (is_bool($value)) {
            return $value;
        }

        return (getenv('PIG_CLEAR_ON_SHRINK') ?: getenv('PI_CLEAR_ON_SHRINK')) === '1';
    }

    /** Upstream's `getShowHardwareCursor()`: the setting, then `PIG_HARDWARE_CURSOR`/`PI_HARDWARE_CURSOR=1`, then off. */
    public function showHardwareCursor(): bool
    {
        $value = $this->get('showHardwareCursor');
        if (is_bool($value)) {
            return $value;
        }

        return (getenv('PIG_HARDWARE_CURSOR') ?: getenv('PI_HARDWARE_CURSOR')) === '1';
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

    /**
     * Whether to ask Packagist at startup whether there is a newer pig.
     *
     * On unless turned off, like compaction and retries — the developer's call, and the switch is
     * what makes it defensible: reaching for the network at every start is a habit this project
     * refuses elsewhere, so the person who does not want it has a way to say so. `--no-update-check`
     * is the same answer for one run.
     */
    public function updateCheckEnabled(): bool
    {
        return $this->get('update.check') !== false;
    }

    public function setRetryEnabled(bool $enabled): void
    {
        $this->set('retry.enabled', $enabled);
    }

    /**
     * Upstream's `getRetrySettings()`: `retry.enabled` (true), `retry.maxRetries` (3),
     * `retry.baseDelayMs` (2,000) and `retry.maxAgentDelayMs` (60,000), each the setting when it is a
     * number and the default otherwise — `settings.retry?.maxRetries ?? 3`, so 0 means no retries.
     *
     * @return array{enabled: bool, maxRetries: int, baseDelayMs: float, maxAgentDelayMs: float}
     */
    public function retrySettings(): array
    {
        $maxRetries = $this->get('retry.maxRetries');
        $baseDelayMs = $this->get('retry.baseDelayMs');
        $maxAgentDelayMs = $this->get('retry.maxAgentDelayMs');

        return [
            'enabled' => $this->retryEnabled(),
            'maxRetries' => is_int($maxRetries) || is_float($maxRetries) ? (int) $maxRetries : 3,
            'baseDelayMs' => is_int($baseDelayMs) || is_float($baseDelayMs) ? (float) $baseDelayMs : 2000.0,
            'maxAgentDelayMs' => is_int($maxAgentDelayMs) || is_float($maxAgentDelayMs) ? (float) $maxAgentDelayMs : (float) Retry::DEFAULT_MAX_AGENT_RETRY_DELAY_MS,
        ];
    }

    /**
     * Upstream's `getProviderRetrySettings()`: `retry.provider.timeoutMs` and `.maxRetries` (unset
     * unless given) and `.maxRetryDelayMs` (60,000) — the retries a provider makes of one request,
     * as opposed to the session's of a whole turn. The legacy `retry.maxDelayMs` counts as
     * `retry.provider.maxRetryDelayMs` when that is unset, which is what upstream's load-time
     * migration of the file makes of it.
     *
     * @return array{timeoutMs: int|null, maxRetries: int|null, maxRetryDelayMs: int}
     */
    public function providerRetrySettings(): array
    {
        $number = static fn (mixed $value): ?int => is_int($value) || is_float($value) ? (int) $value : null;
        $legacy = $number($this->get('retry.maxDelayMs'));

        return [
            'timeoutMs' => $number($this->get('retry.provider.timeoutMs')),
            'maxRetries' => $number($this->get('retry.provider.maxRetries')),
            'maxRetryDelayMs' => $number($this->get('retry.provider.maxRetryDelayMs')) ?? $legacy ?? 60_000,
        ];
    }

    /**
     * Upstream's `getHttpIdleTimeoutMs()`: `httpIdleTimeoutMs`, a non-negative number of
     * milliseconds or `"disabled"` (0), 300,000 when unset (`DEFAULT_HTTP_IDLE_TIMEOUT_MS`); a value
     * that is neither is refused, `Invalid httpIdleTimeoutMs setting: <value>`. It is the default
     * request `timeoutMs` the session hands every provider.
     */
    public function httpIdleTimeoutMs(): int
    {
        $value = $this->get('httpIdleTimeoutMs');
        $parsed = self::parseHttpIdleTimeoutMs($value);

        if ($parsed !== null) {
            return $parsed;
        }

        if ($value !== null) {
            throw new \RuntimeException('Invalid httpIdleTimeoutMs setting: ' . \Pig\Ai\Utils\JsJson::toString($value));
        }

        return 300_000;
    }

    /** Upstream's `parseHttpIdleTimeoutMs()`. */
    private static function parseHttpIdleTimeoutMs(mixed $value): ?int
    {
        if (is_string($value)) {
            $trimmed = \Pig\Ai\Utils\JsJson::trim($value);

            if (strtolower($trimmed) === 'disabled') {
                return 0;
            }

            if ($trimmed === '') {
                return null;
            }

            return is_numeric($trimmed) ? self::parseHttpIdleTimeoutMs((float) $trimmed) : null;
        }

        if (!(is_int($value) || is_float($value)) || !is_finite((float) $value) || $value < 0) {
            return null;
        }

        return (int) floor((float) $value);
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

    /**
     * Extension files or directories named in the settings.
     *
     * Upstream's key exactly: a top-level `extensions` array of paths.
     *
     * @return list<string>
     */
    public function extensions(): array
    {
        $value = $this->get('extensions');

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

    /**
     * Whether one of the standard skill roots is read, by upstream's own key name.
     *
     * `enableCodexUser`, `enableClaudeUser`, `enableClaudeProject`, `enablePiUser`,
     * `enablePiProject` — the five directories pig reads because another tool owns them. On
     * unless the file says `false`, which is `skillsEnabled()`'s rule and stops a key somebody
     * typed as `"no"` from quietly turning a root off.
     */
    public function skillRoot(string $key): bool
    {
        return $this->get("skills.{$key}") !== false;
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
        if (is_array($this->merged) && array_key_exists($key, $this->merged)) {
            return $this->merged[$key];
        }

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

    /** The project's file, for `-l`. Written only when there is one: in-memory settings have none. */
    private function saveProject(): void
    {
        $this->merged = self::merge($this->global, $this->project);

        if ($this->projectPath === null) {
            return;
        }

        $directory = dirname($this->projectPath);

        if (!is_dir($directory) && !mkdir($directory, 0o700, true) && !is_dir($directory)) {
            $this->problems[] = "Could not create {$directory}";

            return;
        }

        $json = json_encode($this->project, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($json === false || file_put_contents($this->projectPath, $json . "\n") === false) {
            $this->problems[] = "Could not write {$this->projectPath}";
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
