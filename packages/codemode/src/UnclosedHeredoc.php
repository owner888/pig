<?php

declare(strict_types=1);

namespace Pig\Codemode;

/**
 * What a `ParseError` from a heredoc or nowdoc that never closes really means — pig's own; upstream
 * runs JavaScript, which has neither.
 *
 * PHP looks for the closing label and, finding none, takes everything after the opening line into
 * the string, then reports whatever that left open, at the script's last line: `Unclosed '[' on
 * line 7`, when the mistake is a `<<<'NEW'` on line 51 closed with `OLD,`. A model shown that
 * rewrites the brackets; shown the label, it fixes the one line.
 */
final class UnclosedHeredoc
{
    /**
     * The sentence to add to the parse error, or null when every heredoc and nowdoc in `$code`
     * closes. Lines are counted as the script's error counts them: after a leading `<?php`, as the
     * sandbox takes it off.
     */
    public static function hint(string $code): ?string
    {
        $body = preg_replace('/^\s*<\?php\s*/', '', $code) ?? $code;
        $lines = preg_split('/\r\n|\n|\r/', $body) ?: [];
        $count = count($lines);

        for ($i = 0; $i < $count; $i++) {
            // An opening is `<<<` and a label, quoted or not, with nothing after it on its line.
            if (preg_match('/<<<[ \t]*(["\']?)([A-Za-z_][A-Za-z0-9_]*)\1[ \t]*$/', $lines[$i], $open) !== 1) {
                continue;
            }

            $label = $open[2];
            $closed = false;

            for ($j = $i + 1; $j < $count; $j++) {
                if (preg_match('/^[ \t]*' . $label . '(?![A-Za-z0-9_])/', $lines[$j]) === 1) {
                    $closed = true;
                    $i = $j;
                    break;
                }
            }

            if (!$closed) {
                $kind = $open[1] === "'" ? 'nowdoc' : 'heredoc';
                $opening = '<<<' . $open[1] . $label . $open[1];

                return "The {$kind} {$opening} opened on line " . ($i + 1) . " never closes: no line after it starts with {$label}, so it takes in the rest of the script. Close it with a line that starts with {$label}.";
            }
        }

        return null;
    }
}
