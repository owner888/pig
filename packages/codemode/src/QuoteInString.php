<?php

declare(strict_types=1);

namespace Pig\Codemode;

/**
 * What a `ParseError` from a quote inside a string of the same quotes really means — pig's own;
 * upstream runs JavaScript, whose template literals a model reaches for instead.
 *
 * `'oldText' => "check(str_contains(\$script, "s/#define ...")"` ends the string at the second `"`,
 * and PHP reports what comes after it: `unexpected identifier "s"`. A model shown that has given
 * up rather than retried; shown the quote, it puts the text in a nowdoc.
 */
final class QuoteInString
{
    /**
     * The sentence to add to the parse error, or null when no string in `$code` runs straight into
     * a name or a variable — which PHP never allows, so it is a quote that closed the string early.
     * Lines are counted as the script's error counts them: after a leading `<?php`, as the sandbox
     * takes it off.
     */
    public static function hint(string $code): ?string
    {
        $body = preg_replace('/^\s*<\?php\s*/', '', $code) ?? $code;
        // A space, not a newline, after the tag, so token lines are the body's lines.
        $tokens = token_get_all('<?php ' . $body);
        $count = count($tokens);

        for ($i = 0; $i + 1 < $count; $i++) {
            $string = $tokens[$i];
            $next = $tokens[$i + 1];

            if (is_array($string) && $string[0] === T_CONSTANT_ENCAPSED_STRING && is_array($next) && in_array($next[0], [T_STRING, T_VARIABLE], true)) {
                $quote = $string[1][0];

                return "Line {$next[2]}: the string ends at the {$quote} before {$next[1]} — a {$quote} inside a {$quote}...{$quote} string closes it. Put text that holds quotes in a nowdoc (<<<'TEXT' on its own line, the text, then TEXT), which takes it exactly as written.";
            }
        }

        return null;
    }
}
