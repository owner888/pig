<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Tools;

use Pig\Agent\AgentError;
use Pig\Ai\Utils\Utf8;
use Pig\Tui\Ansi;
use Pig\Tui\Process;

/**
 * Which shell to run commands with, how to stop them, and how to read what they printed.
 *
 * bash rather than whatever `$SHELL` says: the model writes bash, and a command that
 * works when the person happens to use bash and fails when they use fish is worse than
 * one that always behaves the same.
 *
 * The three things in here are upstream's `utils/shell.ts`, which exports exactly these three:
 * `getShellConfig`, `killProcessTree` and `sanitizeBinaryOutput`. The third lived in
 * `Interactive\SafeText` while its only callers drew on a screen, and moved here when `Run` needed
 * it too — which is the arrangement upstream had all along, and see `sanitize()` for why the two
 * belong in one place.
 */
final class Shell
{
    /**
     * The control characters a terminal acts on, which is all of them but tab and newline.
     *
     * Byte-oriented on purpose: every character in the class is below 0x80, so it cannot appear
     * inside a multi-byte sequence, and a pattern with no `/u` has no way to fail on the input
     * this exists to handle.
     */
    private const string ACTED_ON = '/[\x00-\x08\x0b-\x1f]/';

    private static ?ShellConfig $bash = null;

    private static ?string $configured = null;

    /**
     * Use this shell instead of looking for one. Null goes back to looking.
     *
     * Static because `bash()` is, and for `Models::register()`'s reason: a command runs from
     * `bash`, from `!command`, and from a hook, and a shell only some of those could see is a
     * shell that works until you use another door. `bin/pig` sets it once from the settings.
     *
     * @internal wired by `CodingAgent::session()`
     */
    public static function useShellPath(?string $path): void
    {
        self::$configured = $path === null || $path === '' ? null : $path;
        self::$bash = null;
    }

    /** The shell to run commands with. */
    public static function bash(): string
    {
        return self::config()->shell;
    }

    /**
     * The shell and its arguments — upstream's `getShellConfig(customShellPath)`, resolved once.
     *
     * $shellPath is a tool's own `shellPath` option (upstream's `createBashTool(cwd, { shellPath })`);
     * without one, the `shellPath` setting `useShellPath()` was given. Either way it is resolved
     * fresh rather than cached, because it is not the process-wide answer.
     */
    public static function config(?string $shellPath = null): ShellConfig
    {
        if ($shellPath !== null && $shellPath !== '') {
            return self::resolve($shellPath, PHP_OS_FAMILY, self::environment());
        }

        return self::$bash ??= self::resolve(self::$configured, PHP_OS_FAMILY, self::environment());
    }

    /**
     * Upstream's `getShellConfig()` resolution order:
     *
     * 1. the configured `shellPath`, refused by name when it is not there;
     * 2. on Windows, Git Bash under `%ProgramFiles%` and then `%ProgramFiles(x86)%`, then `bash.exe`
     *    on `PATH` (Cygwin, MSYS2, the legacy WSL one), else an error saying where it looked;
     * 3. elsewhere `/bin/bash`, then `bash` on `PATH`, then `sh`.
     *
     * pig used to try a fixed list instead (`/usr/bin/bash`, `/usr/local/bin/bash`,
     * `/opt/homebrew/bin/bash`), which found a homebrew bash only where it was installed at that
     * prefix and never the one a person's `PATH` names. And it answered `/bin/sh` by path where
     * upstream answers `sh` — left to `PATH`, as upstream's `spawn("sh")` does.
     *
     * The legacy WSL `bash.exe` (`C:\Windows\System32\bash.exe`, or `Sysnative`) takes its command
     * on stdin — upstream's `isLegacyWslBashPath()`.
     *
     * @internal public for tests, which hand in the platform, the environment and the file check
     * @param array<string, string> $env
     * @param (\Closure(string): bool)|null $exists upstream's `existsSync`
     */
    public static function resolve(?string $shellPath, string $osFamily, array $env, ?\Closure $exists = null): ShellConfig
    {
        $exists ??= static fn (string $path): bool => file_exists($path);

        if ($shellPath !== null && $shellPath !== '') {
            // Refused by name rather than quietly falling back to `/bin/bash`: somebody who
            // wrote this down did it because the default was the wrong one, and silently using
            // it anyway is the bug they were working around, back again with no way to see it.
            if (!$exists($shellPath)) {
                throw new AgentError(
                    "Custom shell path not found: {$shellPath}. Fix or remove shellPath in settings.json.",
                );
            }

            return self::bashConfig($shellPath, $env);
        }

        if ($osFamily === 'Windows') {
            $paths = [];

            foreach (['ProgramFiles', 'ProgramFiles(x86)'] as $variable) {
                $root = self::envValue($env, $variable);

                if ($root !== null) {
                    $paths[] = "{$root}\\Git\\bin\\bash.exe";
                }
            }

            foreach ($paths as $path) {
                if ($exists($path)) {
                    return self::bashConfig($path, $env);
                }
            }

            $onPath = self::findExecutableOnPath('bash.exe', $osFamily, $env, $exists);

            if ($onPath !== null) {
                return self::bashConfig($onPath, $env);
            }

            throw new AgentError(
                "No bash shell found. Options:\n"
                    . "  1. Install Git for Windows: https://git-scm.com/download/win\n"
                    . "  2. Add your bash to PATH (Cygwin, MSYS2, etc.)\n"
                    . "  3. Set shellPath in settings.json\n\n"
                    . "Searched Git Bash in:\n" . implode("\n", array_map(static fn (string $p): string => "  {$p}", $paths)),
            );
        }

        if ($exists('/bin/bash')) {
            return self::bashConfig('/bin/bash', $env);
        }

        $onPath = self::findExecutableOnPath('bash', $osFamily, $env, $exists);

        return $onPath !== null ? self::bashConfig($onPath, $env) : new ShellConfig('sh');
    }

    /** Upstream's `POWERSHELL_ARGS`. */
    public const array POWERSHELL_ARGS = ['-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-Command'];

    /**
     * Upstream's `getPowerShellConfig()`: Windows only, PowerShell 7 (`pwsh.exe`) when it is on
     * `PATH`, else Windows PowerShell (`powershell.exe`), started with `POWERSHELL_ARGS`.
     *
     * @internal the platform, the environment and the file check are parameters for tests
     * @param array<string, string>|null $env null for the process's
     * @param (\Closure(string): bool)|null $exists
     */
    public static function powerShellConfig(string $osFamily = PHP_OS_FAMILY, ?array $env = null, ?\Closure $exists = null): ShellConfig
    {
        if ($osFamily !== 'Windows') {
            throw new AgentError('The powershell tool is only available on Windows.');
        }

        $env ??= self::environment();
        $isFile = $exists ?? static fn (string $candidate): bool => is_file($candidate);
        // "Installation directories may be on any drive and need not be on PATH" (#9501): PowerShell 7
        // under `%ProgramFiles%`, Windows PowerShell under `%SystemRoot%`.
        $programFiles = self::envValue($env, 'ProgramFiles');
        $systemRoot = self::envValue($env, 'SystemRoot');
        $installed = static function (?string $root, string $path) use ($isFile): ?string {
            if ($root === null) {
                return null;
            }

            $candidate = rtrim($root, '\\/') . '\\' . $path;

            return $isFile($candidate) ? $candidate : null;
        };
        $shell = self::findExecutableOnPath('pwsh.exe', $osFamily, $env, $exists)
            ?? $installed($programFiles, 'PowerShell\\7\\pwsh.exe')
            ?? self::findExecutableOnPath('powershell.exe', $osFamily, $env, $exists)
            ?? $installed($systemRoot, 'System32\\WindowsPowerShell\\v1.0\\powershell.exe');

        if ($shell === null) {
            throw new AgentError('No PowerShell executable found. Install PowerShell or add powershell.exe/pwsh.exe to PATH.');
        }

        return new ShellConfig($shell, self::POWERSHELL_ARGS);
    }

    /**
     * Upstream's `findExecutableOnPath()`, which asks `where` on Windows and `which` elsewhere. Here
     * the `PATH` entries are walked by hand, which is what both of those do, and saves a process
     * launch per lookup: the first entry holding the name — that exists on Windows, as upstream
     * checks `where`'s answer exists; that is executable elsewhere, as `which` requires.
     *
     * @param array<string, string> $env
     * @param (\Closure(string): bool)|null $exists
     */
    private static function findExecutableOnPath(string $executable, string $osFamily, array $env, ?\Closure $exists = null): ?string
    {
        $windows = $osFamily === 'Windows';
        $path = self::envValue($env, 'PATH') ?? '';
        $separator = $windows ? ';' : ':';
        $exists ??= $windows
            ? static fn (string $candidate): bool => is_file($candidate)
            : static fn (string $candidate): bool => is_file($candidate) && is_executable($candidate);

        foreach (explode($separator, $path) as $directory) {
            if ($directory === '') {
                continue;
            }

            $candidate = rtrim($directory, $windows ? '\\/' : '/') . ($windows ? '\\' : '/') . $executable;

            if ($exists($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Upstream's `getBashShellConfig()` and `isLegacyWslBashPath()`: the WSL launcher is
     * `%SystemRoot%\System32\bash.exe` (or `Sysnative`) wherever Windows is installed; only when
     * `SystemRoot` is unknown is it taken to be under `C:\Windows` (#9501).
     *
     * @param array<string, string> $env
     */
    private static function bashConfig(string $shell, array $env = []): ShellConfig
    {
        return self::isLegacyWslBashPath($shell, $env)
            ? new ShellConfig($shell, ['-s'], 'stdin')
            : new ShellConfig($shell, ['-c']);
    }

    /** @param array<string, string> $env */
    private static function isLegacyWslBashPath(string $shell, array $env): bool
    {
        $normalized = strtolower(str_replace('/', '\\', $shell));
        $systemRoot = self::envValue($env, 'SystemRoot');

        if ($systemRoot !== null) {
            if (preg_match('/^[a-z]:[\\\\\/]/i', $systemRoot) !== 1) {
                return false;
            }

            $root = rtrim(strtolower(str_replace('/', '\\', $systemRoot)), '\\');

            return $normalized === "{$root}\\system32\\bash.exe" || $normalized === "{$root}\\sysnative\\bash.exe";
        }

        // "Fall back to conventional launcher paths only when the Windows directory is unknown."
        return preg_match('/^[a-z]:\\\\windows\\\\(?:system32|sysnative)\\\\bash\.exe$/', $normalized) === 1;
    }

    /**
     * A variable by name, ignoring case as Windows does — upstream finds `PATH` the same way
     * (`Object.keys(process.env).find((key) => key.toLowerCase() === "path")`).
     *
     * @param array<string, string> $env
     */
    private static function envValue(array $env, string $name): ?string
    {
        foreach ($env as $key => $value) {
            if (strcasecmp((string) $key, $name) === 0 && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * The process's environment as a command inherits it — upstream's `getShellEnv()` without the
     * bin-directory prepend (pig's managed `fd` and `rg` are run by path, not looked up on `PATH`).
     *
     * @return array<string, string>
     */
    public static function environment(): array
    {
        $env = getenv();

        return is_array($env) ? $env : [];
    }

    /** @internal Tests. */
    public static function forget(): void
    {
        self::$bash = null;
        self::$configured = null;
    }

    /**
     * What a command printed, made safe to keep.
     *
     * **Cleaned where the bytes arrive, not where they are drawn**, which is upstream's own comment
     * on the line that does it — *"Sanitize once at the source"*. Everything downstream then holds
     * the same text: the model, the session file, the spill file the truncation notice points at,
     * and the screen. Cleaning at the display boundary instead leaves the escapes in the two places
     * nobody looks at until a conversation is replayed, and a build log is mostly escapes.
     *
     * Three steps, none of them tidiness:
     *
     * - **Escape sequences out.** A command that prints its own colours would otherwise paint over
     *   the component's, including the background that says whether it succeeded — and `npm`,
     *   `cargo` and `docker` colour their output whether or not anybody is watching. A file with a
     *   captured log committed into it does the same through a diff.
     * - **Bytes that are not UTF-8 out.** Everything that measures a line goes through
     *   `Graphemes::split()`, which is `preg_match_all('/\X/u')` and answers **false** on malformed
     *   UTF-8 — so one stray byte threw out of `render()`, inside the loop's own input callback, and
     *   took the session with it. `!cat` of a binary file reaches this, so does an `edit` to a
     *   latin-1 file, so does a hook that sends a build log.
     * - **Every other control character out.** This is the `TuiBase::checkWidth()` failure arriving by
     *   the one route that check cannot see: `\p{Cc}` is **zero columns wide**, so a line carrying a
     *   form feed measures exactly right, passes, and is written to a terminal that then drops a row
     *   — putting every later cursor move one row low, which is the silent screen corruption the
     *   check exists to make loud. A backspace leaves the padding measuring a column that is no
     *   longer there. A bell is worse in its own way: the transcript is redrawn as the conversation
     *   grows, so one `\x07` in a build log beeps again on every redraw.
     *
     * Upstream's `sanitizeBinaryOutput` is the last two steps and each of its three callers adds the
     * first by hand — with two spellings between them, one of which also turns `\r` into a newline
     * and skips the binary step. One composition here, for the reason that keeps coming up in this
     * file. Two deliberate differences from it: **`\r` goes** and stays gone — a bare CR returns the
     * cursor to column 0 and the rest of the line overwrites what was drawn, which upstream gets
     * away with only because its renderer is not differential — and **U+FFF9–FFFB stay**, though
     * upstream strips them, because that is a crash in `string-width` where `Width` reads them as
     * `\p{Cf}` and measures them at nothing, which is what they are.
     *
     * A caller reading a pipe holds back an escape sequence a read cuts short until the rest arrives
     * (`Run::splitIncompleteEnd()`, upstream #10504), so each sequence reaches this whole.
     */
    public static function sanitize(string $text): string
    {
        return (string) preg_replace(self::ACTED_ON, '', Ansi::strip(Utf8::sanitize($text)));
    }

    /**
     * Kill a command and everything it started — `Process::killTree()`, which is where the
     * primitive lives now that `Pig\Mcp` needs it for a stdio server's children too. Kept here
     * so the two callers in this package read as they always did.
     */
    public static function killTree(int $pid): void
    {
        Process::killTree($pid);
    }

    /**
     * @return list<int>
     */
    public static function descendants(int $pid): array
    {
        return Process::descendants($pid);
    }
}
