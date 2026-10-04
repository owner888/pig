<?php

declare(strict_types=1);

namespace Pig\Tui;

/**
 * What key was that.
 *
 * A terminal has two ways of telling you. The old one sends control characters and a
 * handful of escape sequences, and cannot express Shift+Enter at all — it is a plain `\r`,
 * the same as Enter. The Kitty keyboard protocol sends `\e[<codepoint>;<modifier>u` and can
 * express everything, but only terminals that opted in speak it, and `ProcessTerminal` asks
 * for it at startup.
 *
 * So every test here accepts both spellings. Ported from upstream's keys.ts, one function
 * per key, because a caller asking "is this Ctrl+C" should not have to know which era of
 * terminal it is talking to.
 *
 * @see https://sw.kovidgoyal.net/kitty/keyboard-protocol/
 */
final class Keys
{
    private const int SHIFT = 1;
    private const int ALT = 2;
    private const int CTRL = 4;
    private const int SUPER = 8;

    /**
     * Caps Lock and Num Lock, which some terminals report as modifiers.
     *
     * Ghostty on Linux sets them. Ctrl+C with Caps Lock on is still Ctrl+C, so both sides
     * of every comparison have these bits cleared.
     */
    private const int LOCK_MASK = 64 + 128;

    /** Keys that have no codepoint of their own, numbered off the bottom so nothing collides. */
    private const int UP = -1;
    private const int DOWN = -2;
    private const int RIGHT = -3;
    private const int LEFT = -4;
    private const int DELETE = -10;
    private const int INSERT = -11;
    private const int PAGE_UP = -12;
    private const int PAGE_DOWN = -13;
    private const int HOME = -14;
    private const int END = -15;

    private const int ESCAPE = 27;
    private const int TAB = 9;
    private const int ENTER = 13;
    private const int BACKSPACE = 127;

    /** The Kitty sequence for a key with a modifier. */
    public static function kitty(int $codepoint, int $modifier = 0): string
    {
        $value = $modifier + 1;

        return "\x1b[{$codepoint};{$value}u";
    }

    /**
     * Ctrl and a lowercase letter, in either spelling.
     *
     * The old spelling is the letter with its top three bits cleared — Ctrl+A is 0x01 —
     * which is where upstream's table of thirteen raw constants comes from.
     *
     * **Wider than upstream's `isKittyCtrl()`, which is only the new spelling**: there the raw byte
     * is a thirteen-entry table consulted by each `isCtrlX()` separately, so adding Ctrl+B means
     * adding a constant. Here the arithmetic covers all twenty-six.
     *
     * **A letter that is not a lowercase letter throws**, where upstream answers false for anything
     * whose length is not 1 and then compares against `charCodeAt(0)` of whatever it got — so
     * `isKittyCtrl(data, 'C')` is silently false there, for a caller who meant Ctrl+C. Nothing can
     * pass a wrong letter except a mistake in this repository, and a predicate that answers "no" to
     * a question it could not understand is the kind of thing this file's conventions call a silent
     * fallback.
     */
    public static function isCtrl(string $data, string $letter): bool
    {
        if (strlen($letter) !== 1 || $letter < 'a' || $letter > 'z') {
            throw new TuiError("isCtrl() takes one lowercase letter, got '{$letter}'");
        }

        return $data === chr(ord($letter) & 0x1f) || self::matches($data, ord($letter), self::CTRL);
    }

    public static function isCtrlA(string $data): bool
    {
        return self::isCtrl($data, 'a');
    }

    public static function isCtrlC(string $data): bool
    {
        return self::isCtrl($data, 'c');
    }

    public static function isCtrlD(string $data): bool
    {
        return self::isCtrl($data, 'd');
    }

    public static function isCtrlE(string $data): bool
    {
        return self::isCtrl($data, 'e');
    }

    public static function isCtrlG(string $data): bool
    {
        return self::isCtrl($data, 'g');
    }

    public static function isCtrlK(string $data): bool
    {
        return self::isCtrl($data, 'k');
    }

    public static function isCtrlL(string $data): bool
    {
        return self::isCtrl($data, 'l');
    }

    public static function isCtrlO(string $data): bool
    {
        return self::isCtrl($data, 'o');
    }

    public static function isCtrlP(string $data): bool
    {
        return self::isCtrl($data, 'p');
    }

    public static function isCtrlT(string $data): bool
    {
        return self::isCtrl($data, 't');
    }

    public static function isCtrlU(string $data): bool
    {
        return self::isCtrl($data, 'u');
    }

    public static function isCtrlW(string $data): bool
    {
        return self::isCtrl($data, 'w');
    }

    public static function isCtrlZ(string $data): bool
    {
        return self::isCtrl($data, 'z');
    }

    // Shift+Ctrl has no control character at all: only a Kitty terminal can report it.

    public static function isShiftCtrlD(string $data): bool
    {
        return self::matches($data, ord('d'), self::SHIFT + self::CTRL);
    }

    public static function isShiftCtrlO(string $data): bool
    {
        return self::matches($data, ord('o'), self::SHIFT + self::CTRL);
    }

    public static function isShiftCtrlP(string $data): bool
    {
        return self::matches($data, ord('p'), self::SHIFT + self::CTRL);
    }

    public static function isEscape(string $data): bool
    {
        return $data === "\x1b" || self::matches($data, self::ESCAPE, 0);
    }

    public static function isTab(string $data): bool
    {
        return $data === "\t" || self::matches($data, self::TAB, 0);
    }

    public static function isShiftTab(string $data): bool
    {
        return $data === "\x1b[Z" || self::matches($data, self::TAB, self::SHIFT);
    }

    public static function isEnter(string $data): bool
    {
        return $data === "\r" || self::matches($data, self::ENTER, 0);
    }

    public static function isShiftEnter(string $data): bool
    {
        return self::matches($data, self::ENTER, self::SHIFT);
    }

    public static function isCmdEnter(string $data): bool
    {
        return self::matches($data, self::ENTER, self::SUPER);
    }

    public static function isAltEnter(string $data): bool
    {
        return $data === "\x1b\r" || self::matches($data, self::ENTER, self::ALT);
    }

    public static function isBackspace(string $data): bool
    {
        return $data === "\x7f" || $data === "\x08" || self::matches($data, self::BACKSPACE, 0);
    }

    public static function isAltBackspace(string $data): bool
    {
        return $data === "\x1b\x7f" || self::matches($data, self::BACKSPACE, self::ALT);
    }

    public static function isArrowUp(string $data): bool
    {
        return $data === "\x1b[A" || self::matches($data, self::UP, 0);
    }

    public static function isArrowDown(string $data): bool
    {
        return $data === "\x1b[B" || self::matches($data, self::DOWN, 0);
    }

    public static function isArrowRight(string $data): bool
    {
        return $data === "\x1b[C" || self::matches($data, self::RIGHT, 0);
    }

    public static function isArrowLeft(string $data): bool
    {
        return $data === "\x1b[D" || self::matches($data, self::LEFT, 0);
    }

    /** Alt+Left. `\eb` is what a macOS Terminal sends for Option+Left. */
    public static function isAltLeft(string $data): bool
    {
        return $data === "\x1b[1;3D" || $data === "\x1bb" || self::matches($data, self::LEFT, self::ALT);
    }

    public static function isAltRight(string $data): bool
    {
        return $data === "\x1b[1;3C" || $data === "\x1bf" || self::matches($data, self::RIGHT, self::ALT);
    }

    public static function isCtrlLeft(string $data): bool
    {
        return $data === "\x1b[1;5D" || self::matches($data, self::LEFT, self::CTRL);
    }

    public static function isCtrlRight(string $data): bool
    {
        return $data === "\x1b[1;5C" || self::matches($data, self::RIGHT, self::CTRL);
    }

    public static function isHome(string $data): bool
    {
        return $data === "\x1b[H" || $data === "\x1b[1~" || $data === "\x1b[7~" || self::matches($data, self::HOME, 0);
    }

    public static function isEnd(string $data): bool
    {
        return $data === "\x1b[F" || $data === "\x1b[4~" || $data === "\x1b[8~" || self::matches($data, self::END, 0);
    }

    public static function isDelete(string $data): bool
    {
        return $data === "\x1b[3~" || self::matches($data, self::DELETE, 0);
    }

    public static function isInsert(string $data): bool
    {
        return $data === "\x1b[2~" || self::matches($data, self::INSERT, 0);
    }

    public static function isPageUp(string $data): bool
    {
        return $data === "\x1b[5~" || self::matches($data, self::PAGE_UP, 0);
    }

    public static function isPageDown(string $data): bool
    {
        return $data === "\x1b[6~" || self::matches($data, self::PAGE_DOWN, 0);
    }

    /** Whether $data is that key with exactly that modifier, lock keys disregarded. */
    /**
     * Whether $data is the key a person would write as $name — upstream's `matchesKey()`, the
     * half of `keybindings.json` that reads the right-hand side.
     *
     * `ctrl+x`, `shift+ctrl+p`, `alt+enter`, `escape`, `shift+tab`, `up`, `ctrl+left`, `home`,
     * `delete`, `pageup` — modifiers in any order, case-insensitive, `esc` for `escape`. The
     * legacy sequences are honoured where the terminal sends them: Ctrl+<letter> as the control
     * character, Alt+<letter> as ESC then the letter, plain arrows as `\e[A`. Anything this
     * cannot read is a key that never matches — and `Keybindings::problems()` names it at load,
     * so the silence is not where the mistake is discovered.
     */
    public static function matchesName(string $data, string $name): bool
    {
        $spec = self::spec($name);

        if ($spec === null) {
            return false;
        }

        [$codepoint, $modifier, $legacy] = $spec;

        foreach ($legacy as $raw) {
            if ($data === $raw) {
                return true;
            }
        }

        return self::matches($data, $codepoint, $modifier);
    }

    /** Whether $name is a key this can read at all. */
    public static function isKeyName(string $name): bool
    {
        return self::spec($name) !== null;
    }

    /**
     * @return array{0: int, 1: int, 2: list<string>}|null codepoint, modifier bits, legacy sequences
     */
    private static function spec(string $name): ?array
    {
        $parts = array_map('strtolower', array_map('trim', explode('+', $name)));
        $key = array_pop($parts);
        $modifier = 0;

        foreach ($parts as $part) {
            $bit = match ($part) {
                'ctrl', 'control' => self::CTRL,
                'shift' => self::SHIFT,
                'alt', 'option' => self::ALT,
                'super', 'cmd', 'command', 'meta' => self::SUPER,
                default => null,
            };

            if ($bit === null) {
                return null;
            }

            $modifier |= $bit;
        }

        if ($key === null || $key === '') {
            return null;
        }

        if (strlen($key) === 1 && $key >= 'a' && $key <= 'z') {
            $legacy = match ($modifier) {
                self::CTRL => [chr(ord($key) - 96)],
                self::ALT => ["\x1b" . $key],
                0 => [$key],
                default => [],
            };

            return [ord($key), $modifier, $legacy];
        }

        $named = [
            'escape' => [self::ESCAPE, ["\x1b"]],
            'esc' => [self::ESCAPE, ["\x1b"]],
            'tab' => [self::TAB, ["\t"]],
            'enter' => [self::ENTER, ["\r"]],
            'return' => [self::ENTER, ["\r"]],
            'backspace' => [self::BACKSPACE, ["\x7f", "\x08"]],
            'up' => [self::UP, ["\x1b[A"]],
            'down' => [self::DOWN, ["\x1b[B"]],
            'right' => [self::RIGHT, ["\x1b[C"]],
            'left' => [self::LEFT, ["\x1b[D"]],
            'home' => [self::HOME, ["\x1b[H", "\x1b[1~"]],
            'end' => [self::END, ["\x1b[F", "\x1b[4~"]],
            'delete' => [self::DELETE, ["\x1b[3~"]],
            'insert' => [self::INSERT, ["\x1b[2~"]],
            'pageup' => [self::PAGE_UP, ["\x1b[5~"]],
            'pagedown' => [self::PAGE_DOWN, ["\x1b[6~"]],
        ];

        if (!isset($named[$key])) {
            return null;
        }

        [$codepoint, $plain] = $named[$key];

        // The legacy forms with a modifier: Shift+Tab is its own sequence, Alt+<key> is ESC in
        // front of the plain one, and `\e[1;<mod>X` is what `parse()` already reads.
        $legacy = match (true) {
            $modifier === 0 => $plain,
            $modifier === self::SHIFT && $key === 'tab' => ["\x1b[Z"],
            $modifier === self::ALT => array_map(static fn (string $raw): string => "\x1b" . $raw, $plain),
            default => [],
        };

        return [$codepoint, $modifier, $legacy];
    }

    private static function matches(string $data, int $codepoint, int $modifier): bool
    {
        $parsed = self::parse($data);

        if ($parsed === null) {
            return false;
        }

        return $parsed[0] === $codepoint && ($parsed[1] & ~self::LOCK_MASK) === ($modifier & ~self::LOCK_MASK);
    }

    /**
     * Read an escape sequence as a key and its modifiers.
     *
     * Four shapes, because the protocol kept the legacy sequences and added modifiers to
     * them rather than replacing them: `\e[<cp>;<mod>u` for ordinary keys, `\e[1;<mod>A`
     * for arrows, `\e[<n>;<mod>~` for the editing keys, and `\e[1;<mod>H` for Home and End.
     *
     * @return array{0: int, 1: int}|null the codepoint and the modifier bits
     */
    private static function parse(string $data): ?array
    {
        if (preg_match('/^\x1b\[(\d+)(?:;(\d+))?u$/', $data, $match) === 1) {
            return [(int) $match[1], (int) ($match[2] ?? 1) - 1];
        }

        if (preg_match('/^\x1b\[1;(\d+)([ABCD])$/', $data, $match) === 1) {
            $arrows = ['A' => self::UP, 'B' => self::DOWN, 'C' => self::RIGHT, 'D' => self::LEFT];

            return [$arrows[$match[2]], (int) $match[1] - 1];
        }

        if (preg_match('/^\x1b\[(\d+)(?:;(\d+))?~$/', $data, $match) === 1) {
            $keys = [
                2 => self::INSERT,
                3 => self::DELETE,
                5 => self::PAGE_UP,
                6 => self::PAGE_DOWN,
                7 => self::HOME,
                8 => self::END,
            ];

            $codepoint = $keys[(int) $match[1]] ?? null;

            if ($codepoint !== null) {
                return [$codepoint, (int) ($match[2] ?? 1) - 1];
            }
        }

        if (preg_match('/^\x1b\[1;(\d+)([HF])$/', $data, $match) === 1) {
            return [$match[2] === 'H' ? self::HOME : self::END, (int) $match[1] - 1];
        }

        return null;
    }
}
