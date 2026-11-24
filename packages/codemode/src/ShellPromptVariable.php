<?php

declare(strict_types=1);

namespace Pig\Codemode;

/**
 * What a `ParseError` from `$ name` really means — pig's own; upstream runs JavaScript, where `$` is
 * an ordinary identifier character.
 *
 * A script that starts with a variable starts with `$` at the start of a line, and a model reading
 * that as a shell prompt writes `$ dec_src = ...`; the space turns up mid-line too, rarer. PHP says only `unexpected identifier "dec_src",
 * expecting variable`; a model shown that has given up rather than retried. Shown the space, it
 * removes it.
 */
final class ShellPromptVariable
{
    /**
     * The sentence to add to the parse error, or null when no `$` in `$code` is followed by a space
     * and a name on the same line. Found with the tokenizer, so a `$ ls` inside a string or heredoc is not one. Lines
     * are counted as the script's error counts them: after a leading `<?php`, as the sandbox takes
     * it off.
     */
    public static function hint(string $code): ?string
    {
        $body = preg_replace('/^\s*<\?php\s*/', '', $code) ?? $code;
        // A space, not a newline, after the tag, so token lines are the body's lines.
        $tokens = token_get_all('<?php ' . $body);
        $count = count($tokens);

        for ($i = 1; $i + 2 < $count; $i++) {
            $space = $tokens[$i + 1];
            $name = $tokens[$i + 2];

            if ($tokens[$i] === '$' && is_array($space) && $space[0] === T_WHITESPACE && !str_contains($space[1], "\n") && is_array($name) && $name[0] === T_STRING) {
                $written = "\"\${$space[1]}{$name[1]}\"";
                // Only at the start of a line does it look like a prompt: the tag, or a token ending
                // in a newline and indentation, before it.
                $before = $tokens[$i - 1];
                $where = is_array($before) && ($before[0] === T_OPEN_TAG || preg_match('/\n[ \t]*$/', $before[1]) === 1)
                    ? "Line {$space[2]} starts with {$written}, which is how a shell prompt looks, not PHP:"
                    : "Line {$space[2]} has {$written}:";

                return "{$where} a PHP variable has no space after \$. Write \${$name[1]}.";
            }
        }

        return null;
    }
}
