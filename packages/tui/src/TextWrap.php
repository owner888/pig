<?php

declare(strict_types=1);

namespace Pig\Tui;

/**
 * Word wrapping that survives colour.
 *
 * Wrapping is done on tokens rather than characters so words stay whole, and every token
 * carries the escape codes that preceded it, so a break never separates a colour from the
 * text it was meant for. Lines come back unpadded — padding is the caller's business,
 * because only the caller knows whether a background should run to the edge.
 */
final class TextWrap
{
    /**
     * Wrap to $width columns, keeping styles across the breaks.
     *
     * @return list<string> at least one line, even for empty input
     */
    public static function wrap(string $text, int $width): array
    {
        if ($text === '') {
            return [''];
        }

        $lines = [];
        $tracker = new AnsiTracker();

        foreach (explode("\n", $text) as $line) {
            // A literal newline does not reset the terminal, so styles carry over it.
            $prefix = $lines === [] ? '' : $tracker->activeCodes();

            foreach (self::wrapLine($prefix . $line, $width) as $wrapped) {
                $lines[] = $wrapped;
            }

            $tracker->processText($line);
        }

        return $lines === [] ? [''] : $lines;
    }

    /** @return list<string> */
    private static function wrapLine(string $line, int $width): array
    {
        if ($line === '') {
            return [''];
        }

        if (Width::visible($line) <= $width) {
            return [$line];
        }

        $lines = [];
        $tracker = new AnsiTracker();
        $current = '';
        $currentWidth = 0;

        foreach (self::tokenize($line) as $token) {
            $tokenWidth = Width::visible($token);
            $isWhitespace = trim($token) === '';

            // A single word wider than the line has to be cut mid-word.
            if ($tokenWidth > $width && !$isWhitespace) {
                if ($current !== '') {
                    $lines[] = $current . $tracker->lineEndReset();
                }

                $broken = self::breakWord($token, $width, $tracker);
                $current = array_pop($broken);

                foreach ($broken as $brokenLine) {
                    $lines[] = $brokenLine;
                }

                // breakWord already fed the tracker the codes inside the word.
                $currentWidth = Width::visible($current);

                continue;
            }

            if ($currentWidth + $tokenWidth > $width && $currentWidth > 0) {
                $lines[] = rtrim($current) . $tracker->lineEndReset();

                // A line never starts with the space that pushed it over.
                $current = $tracker->activeCodes() . ($isWhitespace ? '' : $token);
                $currentWidth = $isWhitespace ? 0 : $tokenWidth;
            } else {
                $current .= $token;
                $currentWidth += $tokenWidth;
            }

            $tracker->processText($token);
        }

        if ($current !== '') {
            // No reset on the last line: the caller may be continuing the same style.
            $lines[] = $current;
        }

        return $lines === [] ? [''] : $lines;
    }

    /**
     * Split into runs of spaces and runs of non-spaces, escape codes attached to what follows.
     *
     * Attaching codes forward rather than leaving them where they fell is what makes a break
     * safe: the token that gets moved to the next line brings its colour with it.
     *
     * @return list<string>
     */
    private static function tokenize(string $text): array
    {
        $tokens = [];
        $current = '';
        $pending = '';
        $inWhitespace = false;
        $pos = 0;
        $length = strlen($text);

        while ($pos < $length) {
            $code = Ansi::at($text, $pos);

            if ($code !== null) {
                $pending .= $code[0];
                $pos += $code[1];

                continue;
            }

            $char = $text[$pos];
            $isSpace = $char === ' ';

            if ($isSpace !== $inWhitespace && $current !== '') {
                $tokens[] = $current;
                $current = '';
            }

            $current .= $pending;
            $pending = '';
            $inWhitespace = $isSpace;
            $current .= $char;
            $pos++;
        }

        $current .= $pending;

        if ($current !== '') {
            $tokens[] = $current;
        }

        return $tokens;
    }

    /**
     * Cut one over-long word into lines, grapheme by grapheme.
     *
     * $tracker is advanced as the word's own codes go by, so each new line reopens the
     * style that was in force at the point it was cut.
     *
     * @return non-empty-list<string>
     */
    private static function breakWord(string $word, int $width, AnsiTracker $tracker): array
    {
        $lines = [];
        $current = $tracker->activeCodes();
        $currentWidth = 0;

        foreach (Ansi::segment($word) as [$isCode, $value]) {
            if ($isCode) {
                $current .= $value;
                $tracker->process($value);

                continue;
            }

            $graphemeWidth = Width::visible($value);

            if ($currentWidth + $graphemeWidth > $width) {
                $lines[] = $current . $tracker->lineEndReset();
                $current = $tracker->activeCodes();
                $currentWidth = 0;
            }

            $current .= $value;
            $currentWidth += $graphemeWidth;
        }

        if ($current !== '') {
            $lines[] = $current;
        }

        return $lines === [] ? [''] : $lines;
    }
}
