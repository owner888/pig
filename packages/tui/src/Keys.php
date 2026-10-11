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
    private const int CLEAR = -16;

    /** The function keys match by their legacy sequences only, so they share one codepoint nothing parses to. */
    private const int FUNCTION_KEY = -100;

    /**
     * Kitty's keypad keys (`KP_Left` … `KP_Delete`) with Num Lock off are the navigation keys
     * they are printed with; upstream's `keys.ts` reads them the same way.
     */
    private const array KITTY_KEYPAD_EQUIVALENTS = [
        57417 => self::LEFT,
        57418 => self::RIGHT,
        57419 => self::UP,
        57420 => self::DOWN,
        57421 => self::PAGE_UP,
        57422 => self::PAGE_DOWN,
        57423 => self::HOME,
        57424 => self::END,
        57425 => self::INSERT,
        57426 => self::DELETE,
    ];

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

    public static function isCtrlS(string $data): bool
    {
        return self::isCtrl($data, 's');
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

    /**
     * Alt+Enter — the kitty form only. `\e\r` is *not* read as it, deliberately: it is what
     * Apple Terminal sends for **Shift+Enter** (measured), as well as what some terminals send
     * for Alt+Enter, and the two have opposite meanings at a prompt — a new line and "send".
     * Shift+Enter is the one somebody presses a hundred times a day, so the ambiguous bytes go
     * to it; Alt+Enter keeps its unambiguous `\e[13;3u`, and `command+enter` is bound to the
     * same action for a terminal that speaks the protocol. `Editor::isNewLine()` reads `\e\r`.
     */
    public static function isAltEnter(string $data): bool
    {
        return self::matches($data, self::ENTER, self::ALT);
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

    /**
     * A Kitty key-release event — upstream's `isKeyRelease()`. Only sent when the terminal was
     * asked for event types (flag 2); bracketed paste is never one, even when the pasted text
     * holds something like `90:62:3F:A5`.
     */
    public static function isKeyRelease(string $data): bool
    {
        if (str_contains($data, "\x1b[200~")) {
            return false;
        }

        foreach ([':3u', ':3~', ':3A', ':3B', ':3C', ':3D', ':3H', ':3F'] as $release) {
            if (str_contains($data, $release)) {
                return true;
            }
        }

        return false;
    }

    /** Whether $name is a key this can read at all. */
    public static function isKeyName(string $name): bool
    {
        return self::spec($name) !== null;
    }

    /**
     * The symbol keys a key id may name — upstream's `SYMBOL_KEYS` in `keys.ts`.
     *
     * @var list<string>
     */
    private const array SYMBOL_KEYS = ['`', '-', '=', '[', ']', '\\', ';', "'", ',', '.', '/', '!', '@', '#', '$', '%', '^', '&', '*', '(', ')', '_', '+', '|', '~', '{', '}', ':', '<', '>', '?'];

    /**
     * The legacy sequences of the keys that have no codepoint — upstream's `LEGACY_KEY_SEQUENCES`,
     * `LEGACY_SHIFT_SEQUENCES` and `LEGACY_CTRL_SEQUENCES`. The function keys and `clear` are only
     * ever these: no terminal reports them through the kitty protocol in a form `parse()` reads.
     *
     * @var array<string, array{0: int, 1: list<string>, 2: list<string>, 3: list<string>}> codepoint, plain, shift, ctrl
     */
    private const array NAMED_KEYS = [
        'escape' => [self::ESCAPE, ["\x1b"], [], []],
        'esc' => [self::ESCAPE, ["\x1b"], [], []],
        'tab' => [self::TAB, ["\t"], ["\x1b[Z"], []],
        'enter' => [self::ENTER, ["\r", "\x1bOM"], [], []],
        'return' => [self::ENTER, ["\r", "\x1bOM"], [], []],
        'space' => [32, [' '], [], ["\x00"]],
        'backspace' => [self::BACKSPACE, ["\x7f", "\x08"], [], []],
        'up' => [self::UP, ["\x1b[A", "\x1bOA"], ["\x1b[a"], ["\x1bOa"]],
        'down' => [self::DOWN, ["\x1b[B", "\x1bOB"], ["\x1b[b"], ["\x1bOb"]],
        'right' => [self::RIGHT, ["\x1b[C", "\x1bOC"], ["\x1b[c"], ["\x1bOc"]],
        'left' => [self::LEFT, ["\x1b[D", "\x1bOD"], ["\x1b[d"], ["\x1bOd"]],
        'home' => [self::HOME, ["\x1b[H", "\x1bOH", "\x1b[1~", "\x1b[7~"], ["\x1b[7$"], ["\x1b[7^"]],
        'end' => [self::END, ["\x1b[F", "\x1bOF", "\x1b[4~", "\x1b[8~"], ["\x1b[8$"], ["\x1b[8^"]],
        'delete' => [self::DELETE, ["\x1b[3~"], ["\x1b[3$"], ["\x1b[3^"]],
        'insert' => [self::INSERT, ["\x1b[2~"], ["\x1b[2$"], ["\x1b[2^"]],
        'pageup' => [self::PAGE_UP, ["\x1b[5~", "\x1b[[5~"], ["\x1b[5$"], ["\x1b[5^"]],
        'pagedown' => [self::PAGE_DOWN, ["\x1b[6~", "\x1b[[6~"], ["\x1b[6$"], ["\x1b[6^"]],
        'clear' => [self::CLEAR, ["\x1b[E", "\x1bOE"], ["\x1b[e"], ["\x1bOe"]],
        'f1' => [self::FUNCTION_KEY, ["\x1bOP", "\x1b[11~", "\x1b[[A"], [], []],
        'f2' => [self::FUNCTION_KEY, ["\x1bOQ", "\x1b[12~", "\x1b[[B"], [], []],
        'f3' => [self::FUNCTION_KEY, ["\x1bOR", "\x1b[13~", "\x1b[[C"], [], []],
        'f4' => [self::FUNCTION_KEY, ["\x1bOS", "\x1b[14~", "\x1b[[D"], [], []],
        'f5' => [self::FUNCTION_KEY, ["\x1b[15~", "\x1b[[E"], [], []],
        'f6' => [self::FUNCTION_KEY, ["\x1b[17~"], [], []],
        'f7' => [self::FUNCTION_KEY, ["\x1b[18~"], [], []],
        'f8' => [self::FUNCTION_KEY, ["\x1b[19~"], [], []],
        'f9' => [self::FUNCTION_KEY, ["\x1b[20~"], [], []],
        'f10' => [self::FUNCTION_KEY, ["\x1b[21~"], [], []],
        'f11' => [self::FUNCTION_KEY, ["\x1b[23~"], [], []],
        'f12' => [self::FUNCTION_KEY, ["\x1b[24~"], [], []],
    ];

    /**
     * Upstream's `rawCtrlChar()`: the control character Ctrl+<key> sends in a legacy terminal —
     * `code & 0x1f` for the letters and `[`, `\`, `]`, `_`, and `-` as `_` (one physical key on a
     * US keyboard), which is how `ctrl+-` (undo) and `ctrl+]` (jump) arrive without the protocol.
     */
    private static function rawCtrlChar(string $key): ?string
    {
        if (($key >= 'a' && $key <= 'z') || in_array($key, ['[', '\\', ']', '_'], true)) {
            return chr(ord($key) & 0x1f);
        }

        return $key === '-' ? chr(31) : null;
    }

    /**
     * Split a key id into its modifier names and its key — upstream's `parseKeyId()`. A `+` at the
     * end is the plus key (`ctrl++`), not a missing one.
     *
     * @return array{0: list<string>, 1: string}|null
     */
    private static function splitName(string $name): ?array
    {
        $name = strtolower(trim($name));

        if ($name === '+') {
            return [[], '+'];
        }

        if (str_ends_with($name, '++')) {
            return [array_map('trim', explode('+', substr($name, 0, -2))), '+'];
        }

        $parts = array_map('trim', explode('+', $name));
        $key = array_pop($parts);

        return $key === null || $key === '' ? null : [$parts, $key];
    }

    /**
     * Upstream's `matchesKey()` table, as data: what each key id is as a codepoint and modifier for
     * `parse()`, and which legacy byte strings also mean it.
     *
     * @return array{0: int, 1: int, 2: list<string>}|null codepoint, modifier bits, legacy sequences
     */
    private static function spec(string $name): ?array
    {
        $split = self::splitName($name);

        if ($split === null) {
            return null;
        }

        [$parts, $key] = $split;
        $modifier = 0;

        foreach ($parts as $part) {
            $bit = match ($part) {
                'ctrl', 'control' => self::CTRL,
                'shift' => self::SHIFT,
                'alt', 'option' => self::ALT,
                'super', 'cmd', 'command', 'meta' => self::SUPER,
                default => null,
            };

            // A modifier named twice is a typo upstream refuses, and so does this.
            if ($bit === null || ($modifier & $bit) !== 0) {
                return null;
            }

            $modifier |= $bit;
        }

        if (strlen($key) === 1 && (($key >= 'a' && $key <= 'z') || ($key >= '0' && $key <= '9') || in_array($key, self::SYMBOL_KEYS, true))) {
            $rawCtrl = self::rawCtrlChar($key);
            $isLetter = $key >= 'a' && $key <= 'z';

            $legacy = match ($modifier) {
                0 => [$key],
                self::CTRL => $rawCtrl === null ? [] : [$rawCtrl],
                self::ALT => ["\x1b" . $key],
                self::CTRL | self::ALT => $rawCtrl === null ? [] : ["\x1b" . $rawCtrl],
                // Shift+<letter> without the protocol is the capital.
                self::SHIFT => $isLetter ? [strtoupper($key)] : [],
                default => [],
            };

            return [ord($key), $modifier, $legacy];
        }

        if (!isset(self::NAMED_KEYS[$key])) {
            return null;
        }

        [$codepoint, $plain, $shifted, $ctrl] = self::NAMED_KEYS[$key];

        if ($codepoint === self::FUNCTION_KEY && $modifier !== 0) {
            return null;
        }

        $legacy = match (true) {
            $modifier === 0 => $plain,
            $modifier === self::SHIFT => $shifted,
            $modifier === self::CTRL => $ctrl,
            $modifier === self::ALT && $key === 'space' => ["\x1b "],
            // Upstream's `\ep` / `\en` for Alt+Up / Alt+Down, and `\eb` / `\ef` for Alt+Left / Alt+Right.
            $modifier === self::ALT && $key === 'up' => ["\x1bp", "\x1b\x1b[A"],
            $modifier === self::ALT && $key === 'down' => ["\x1bn", "\x1b\x1b[B"],
            $modifier === self::ALT && $key === 'left' => ["\x1bb", "\x1b\x1b[D"],
            $modifier === self::ALT && $key === 'right' => ["\x1bf", "\x1b\x1b[C"],
            $modifier === self::ALT && $key === 'backspace' => ["\x1b\x7f", "\x1b\x08"],
            // Not for Enter: `\e\r` is Apple Terminal's Shift+Enter — see `isAltEnter()`.
            $modifier === self::ALT && $key !== 'enter' && $key !== 'return'
                => array_map(static fn (string $raw): string => "\x1b" . $raw, $plain),
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
        // `<cp>[:<shifted>[:<base>]][;<mod>[:<event>]]u` — the alternate keys and the event type
        // (press, repeat, release) do not change which key it is.
        if (preg_match('/^\x1b\[(\d+)(?::\d*){0,2}(?:;(\d+)(?::\d+)?)?u$/', $data, $match) === 1) {
            $codepoint = (int) $match[1];

            return [self::KITTY_KEYPAD_EQUIVALENTS[$codepoint] ?? $codepoint, (int) (($match[2] ?? '') === '' ? 1 : $match[2]) - 1];
        }

        if (preg_match('/^\x1b\[1;(\d+)(?::\d+)?([ABCD])$/', $data, $match) === 1) {
            $arrows = ['A' => self::UP, 'B' => self::DOWN, 'C' => self::RIGHT, 'D' => self::LEFT];

            return [$arrows[$match[2]], (int) $match[1] - 1];
        }

        // `~` carries its modifiers as a parameter; rxvt instead ends the sequence with `^` for
        // Ctrl, `$` for Shift and `@` for both (`\e[7^` is Ctrl+Home).
        if (preg_match('/^\x1b\[(\d+)(?:;(\d+)(?::\d+)?)?([~^$@])$/', $data, $match) === 1) {
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
                if ($match[3] !== '~') {
                    return ($match[2] ?? '') === ''
                        ? [$codepoint, match ($match[3]) {
                            '^' => self::CTRL,
                            '$' => self::SHIFT,
                            '@' => self::CTRL | self::SHIFT,
                        }]
                        : null;
                }

                return [$codepoint, (int) (($match[2] ?? '') === '' ? 1 : $match[2]) - 1];
            }
        }

        if (preg_match('/^\x1b\[1;(\d+)(?::\d+)?([HF])$/', $data, $match) === 1) {
            return [$match[2] === 'H' ? self::HOME : self::END, (int) $match[1] - 1];
        }

        return null;
    }
}
