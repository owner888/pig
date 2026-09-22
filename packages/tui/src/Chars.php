<?php

declare(strict_types=1);

namespace Pig\Tui;

/**
 * The two questions word-wise cursor movement asks of a character.
 *
 * Ctrl+W and Alt+Left skip a run of one kind and stop at the first of another, so
 * "whitespace" and "punctuation" are the only classes that need naming — everything else
 * is a word character by elimination.
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
