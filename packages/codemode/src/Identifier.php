<?php

declare(strict_types=1);

namespace Pig\Codemode;

/**
 * The identifier a script uses for a tool — upstream's `identifier.ts`: characters that are not
 * valid in an identifier become `_`. `mcp__docs__search` stays as is, `my-tool` becomes `my_tool`.
 *
 * A PHP method name allows the same characters as a JavaScript identifier minus `$`, so a `$` in
 * a tool name becomes `_` here where upstream keeps it.
 *
 * **One rule upstream does not need.** `$tools` is an object whose tools are reached through
 * `__call`, and PHP calls `__call` only for a method the class does not have. A tool whose
 * identifier is one of `__CodemodeTools`' own public methods would run that method instead, and
 * method names are case-insensitive, so `Has` is caught as well as `has`. Upstream's `tools` is a
 * Proxy, which sees every name. Those identifiers get a trailing `_`: `has` is `$tools->has_()`.
 */
final class Identifier
{
    /**
     * The public methods of `__CodemodeTools` in `Runtime/child.php`, lowercased —
     * `CodemodeTest` checks the two lists agree.
     */
    public const array TOOLS_METHODS = ['__construct', '__call', 'has'];

    public static function of(string $name): string
    {
        $identifier = '';

        foreach (mb_str_split($name) as $char) {
            $valid = $identifier === '' ? preg_match('/^[A-Za-z_]$/', $char) === 1 : preg_match('/^[A-Za-z0-9_]$/', $char) === 1;
            $identifier .= $valid ? $char : '_';
        }

        if ($identifier === '') {
            return '_';
        }

        return in_array(strtolower($identifier), self::TOOLS_METHODS, true) ? $identifier . '_' : $identifier;
    }
}
