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

    private static ?string $bash = null;

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
        if (self::$bash !== null) {
            return self::$bash;
        }

        if (self::$configured !== null) {
            // Refused by name rather than quietly falling back to `/bin/bash`: somebody who
            // wrote this down did it because the default was the wrong one, and silently using
            // it anyway is the bug they were working around, back again with no way to see it.
            if (!is_executable(self::$configured)) {
                throw new AgentError(
                    "shellPath is set to '" . self::$configured . "', which is not executable. "
                        . 'Fix or remove it in settings.json.',
                );
            }

            return self::$bash = self::$configured;
        }

        $candidates = ['/bin/bash', '/usr/bin/bash', '/usr/local/bin/bash', '/opt/homebrew/bin/bash'];

        foreach ($candidates as $path) {
            if (is_executable($path)) {
                return self::$bash = $path;
            }
        }

        // sh will run most of what a model writes, and the ones it will not fail loudly.
        if (is_executable('/bin/sh')) {
            return self::$bash = '/bin/sh';
        }

        throw new AgentError('No shell found. Looked for: ' . implode(', ', [...$candidates, '/bin/sh']));
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
     * - **Every other control character out.** This is the `Tui::checkWidth()` failure arriving by
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
     * An escape split across two reads survives, because a caller reading a pipe cleans each chunk
     * on its own. Upstream has that too, and the alternative is holding bytes back in case the rest
     * of a sequence arrives, which would stop the output being live.
     */
    public static function sanitize(string $text): string
    {
        return (string) preg_replace(self::ACTED_ON, '', Ansi::strip(Utf8::sanitize($text)));
    }

    /**
     * Kill a command and everything it started.
     *
     * Killing the shell alone is not enough. `npm test` is the shell's child and the
     * test runner is its grandchild; kill the shell and the runner keeps going, holding
     * the port it bound and writing to a terminal that has moved on.
     *
     * The tree is walked from `ps` rather than by putting the command in its own process
     * group — PHP cannot do that without forking by hand, and macOS has no `setsid`.
     * Children are killed before their parents, so nothing gets a chance to start more.
     */
    public static function killTree(int $pid): void
    {
        foreach (array_reverse(self::descendants($pid)) as $child) {
            self::kill($child);
        }

        self::kill($pid);
    }

    /**
     * Every process below $pid, parents before children.
     *
     * @return list<int>
     */
    private static function descendants(int $pid): array
    {
        $children = self::childrenByParent();
        $found = [];
        $queue = [$pid];

        while ($queue !== []) {
            $current = array_shift($queue);

            foreach ($children[$current] ?? [] as $child) {
                $found[] = $child;
                $queue[] = $child;
            }
        }

        return $found;
    }

    /** @return array<int, list<int>> */
    private static function childrenByParent(): array
    {
        // One `ps` for the whole tree: asking per process would be a fork per node, and
        // this runs when someone has just pressed Escape and is waiting.
        $output = Process::capture(['ps', '-eo', 'pid=,ppid='], 2.0);

        if ($output === null) {
            return [];
        }

        $children = [];

        foreach (explode("\n", $output) as $line) {
            if (preg_match('/^\s*(\d+)\s+(\d+)\s*$/', $line, $match) === 1) {
                $children[(int) $match[2]][] = (int) $match[1];
            }
        }

        return $children;
    }

    private static function kill(int $pid): void
    {
        if (function_exists('posix_kill')) {
            posix_kill($pid, 9);

            return;
        }

        Process::capture(['kill', '-9', (string) $pid], 2.0);
    }
}
