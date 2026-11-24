<?php

declare(strict_types=1);

namespace Pig\Codemode;

/**
 * What `Call to undefined function file_get_contents()` really means — pig's own; upstream runs
 * JavaScript in a VM that has no `fs` to reach for.
 *
 * The function exists and was turned off (`CodemodeSandbox::DISABLED_FUNCTIONS`) so that files,
 * commands and the network go through `$tools`, where the session's sandbox, permission checks
 * and history see them. A model told only that the function is undefined has given up; told it
 * was turned off and which tools do the job, it uses them.
 */
final class DisabledFunction
{
    /** The tools that do each group's job, in the order to name them; only those given are named. */
    private const array FILE_TOOLS = ['read', 'write', 'edit', 'ls', 'find', 'grep'];

    private const array COMMAND_TOOLS = ['bash', 'powershell'];

    /**
     * The sentence to add to the error, or null when `$message` is not a call to a function the
     * sandbox turned off — a misspelt function stays PHP's own error.
     *
     * @param list<string> $tools the tool names the script was given
     */
    public static function hint(string $message, array $tools): ?string
    {
        if (preg_match('/^Call to undefined function ([A-Za-z_][A-Za-z0-9_]*)\(\)/', $message, $match) !== 1) {
            return null;
        }

        $function = strtolower($match[1]);

        if (!in_array($function, CodemodeSandbox::DISABLED_FUNCTIONS, true)) {
            return null;
        }

        $wanted = in_array($function, CodemodeSandbox::FILE_FUNCTIONS, true) ? self::FILE_TOOLS : self::COMMAND_TOOLS;
        $named = array_map(
            static fn (string $tool): string => '$tools->' . Identifier::of($tool) . '()',
            array_values(array_filter($wanted, static fn (string $tool): bool => in_array($tool, $tools, true))),
        );
        $use = match (count($named)) {
            0 => '',
            1 => " Use {$named[0]} instead.",
            default => ' Use ' . implode(', ', array_slice($named, 0, -1)) . ' or ' . $named[array_key_last($named)] . ' instead.',
        };

        return "{$match[1]}() is turned off on purpose in codemode: a script reaches files, commands and the network only through \$tools, so the session's sandbox, permission checks and history see them.{$use} ALL_TOOLS lists every tool.";
    }
}
