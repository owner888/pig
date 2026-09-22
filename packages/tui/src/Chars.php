<?php

declare(strict_types=1);

namespace Pig\Tui;

/**
 * Where a word begins and ends, and the two questions that decides it.
 *
 * Ctrl+W and Alt+Left skip a run of one kind and stop at the first of another, so
 * "whitespace" and "punctuation" are the only classes that need naming — everything else
 * is a word character by elimination.
 *
 * Both editors ask the same question of the same kind of text, so they ask it here.
 * Upstream repeats the scan in `input.ts` and `editor.ts`; the developer asked for one copy.
 */
final class Chars
{
    /**
     * What Ctrl+W treats as a boundary.
     *
     * Deliberately not `\p{P}`: `_` is punctuation to Unicode and part of a word to anyone
     * editing code, and this list is upstream's, chosen for the same reason.
     */
    private const string PUNCTUATION = '(){}[]<>.,;:\'"!?+-=*/\\|&%^$#@~`';

    public static function isWhitespace(string $char): bool
    {
        return $char !== '' && preg_match('/^\s+$/u', $char) === 1;
    }

    public static function isPunctuation(string $char): bool
    {
        return strlen($char) === 1 && str_contains(self::PUNCTUATION, $char);
    }

    /** Neither whitespace nor punctuation: the stuff a word is made of. */
    public static function isWord(string $char): bool
    {
        return $char !== '' && !self::isWhitespace($char) && !self::isPunctuation($char);
    }

    /**
     * Where the word before byte offset $offset begins.
     *
     * Trailing spaces are skipped first, then a run of one kind — all punctuation, or all
     * word characters. Stopping where the kind changes is what makes Ctrl+W useful on a
     * path or an expression rather than swallowing the whole line.
     *
     * $offset must sit on a grapheme boundary, which is the only place a cursor ever is.
     */
    public static function wordStart(string $text, int $offset): int
    {
        $graphemes = Graphemes::split(substr($text, 0, $offset));

        while ($graphemes !== [] && self::isWhitespace(end($graphemes))) {
            $offset -= strlen((string) array_pop($graphemes));
        }

        if ($graphemes === []) {
            return $offset;
        }

        $matches = self::isPunctuation(end($graphemes)) ? self::isPunctuation(...) : self::isWord(...);

        while ($graphemes !== [] && $matches(end($graphemes))) {
            $offset -= strlen((string) array_pop($graphemes));
        }

        return $offset;
    }

    /** Where the word after byte offset $offset ends. The mirror of wordStart(). */
    public static function wordEnd(string $text, int $offset): int
    {
        $graphemes = Graphemes::split(substr($text, $offset));
        $index = 0;
        $count = count($graphemes);

        while ($index < $count && self::isWhitespace($graphemes[$index])) {
            $offset += strlen($graphemes[$index]);
            $index++;
        }

        if ($index >= $count) {
            return $offset;
        }

        $matches = self::isPunctuation($graphemes[$index]) ? self::isPunctuation(...) : self::isWord(...);

        while ($index < $count && $matches($graphemes[$index])) {
            $offset += strlen($graphemes[$index]);
            $index++;
        }

        return $offset;
    }

    /**
     * Whether $data is safe to insert as literal text.
     *
     * C0 controls, DEL and C1 controls are rejected: they are either keys that should have
     * been recognised earlier or the remains of an escape sequence, and putting either into
     * a buffer produces text the user cannot see and cannot delete.
     */
    public static function isPrintable(string $data): bool
    {
        if ($data === '') {
            return false;
        }

        foreach (mb_str_split($data, 1, 'UTF-8') as $char) {
            $code = mb_ord($char, 'UTF-8');

            if ($code === false || $code < 0x20 || $code === 0x7f || ($code >= 0x80 && $code <= 0x9f)) {
                return false;
            }
        }

        return true;
    }
}
