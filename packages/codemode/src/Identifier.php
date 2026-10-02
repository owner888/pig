<?php

declare(strict_types=1);

namespace Pig\Codemode;

/**
 * The identifier a script uses for a tool — upstream's `identifier.ts`: characters that are not
 * valid in an identifier become `_`. `mcp__docs__search` stays as is, `my-tool` becomes `my_tool`.
 *
 * A PHP method name allows the same characters as a JavaScript identifier minus `$`, so a `$` in
 * a tool name becomes `_` here where upstream keeps it.
 */
final class Identifier
{
    public static function of(string $name): string
    {
        $identifier = '';

        foreach (mb_str_split($name) as $char) {
            $valid = $identifier === '' ? preg_match('/^[A-Za-z_]$/', $char) === 1 : preg_match('/^[A-Za-z0-9_]$/', $char) === 1;
            $identifier .= $valid ? $char : '_';
        }

        return $identifier === '' ? '_' : $identifier;
    }
}
