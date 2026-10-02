<?php

declare(strict_types=1);

namespace Pig\CodingAgent;

use Pig\CodingAgent\Tools\Shell;
use Pig\Tui\Process;
use RuntimeException;

/**
 * A configuration value that may name an environment variable or a command — upstream's
 * `core/resolve-config-value.ts`.
 *
 * - `!command` runs the rest as a shell command and uses its stdout, trimmed. Cached for the life
 *   of the process, so a header that costs a `gh auth token` costs it once.
 * - `${NAME}` and `$NAME` are replaced with the environment variable. A value with a variable
 *   that is not set resolves to **null** rather than to the text with a hole in it: a header
 *   `Bearer ` with nothing after it is a 401 that names nothing.
 * - `$$` is a literal `$` and `$!` a literal `!`, so a password with a dollar in it can be written.
 * - Anything else is itself.
 *
 * This is what `mcp.json`'s `headers` and `env` go through, and it is also the reason secrets
 * can stay out of that file: `"Authorization": "Bearer ${GITHUB_TOKEN}"` is a reference and not a
 * credential in a repository.
 */
final class ConfigValue
{
    /** @var array<string, string> command => its output, for the life of the process */
    private static array $commandCache = [];

    private const string NAME = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    private const string NAME_PREFIX = '/^[A-Za-z_][A-Za-z0-9_]*/';

    /** Resolve, answering null when a referenced variable is not set or the command failed. */
    public static function resolve(string $config): ?string
    {
        if (str_starts_with($config, '!')) {
            return self::command(substr($config, 1));
        }

        $resolved = '';

        foreach (self::parts($config) as [$kind, $value]) {
            if ($kind === 'literal') {
                $resolved .= $value;
                continue;
            }

            $env = getenv($value);

            if (!is_string($env) || $env === '') {
                return null;
            }

            $resolved .= $env;
        }

        return $resolved;
    }

    /**
     * Resolve, or say what is missing — for a value that has to be there.
     *
     * @param string $what names the value in the complaint: `MCP server "docs" header "Authorization"`
     */
    public static function resolveOrThrow(string $config, string $what): string
    {
        $resolved = self::resolve($config);

        if ($resolved !== null) {
            return $resolved;
        }

        if (str_starts_with($config, '!')) {
            throw new RuntimeException("{$what}: the command " . json_encode(substr($config, 1)) . ' produced no output');
        }

        $missing = self::missingVariables($config);

        throw new RuntimeException(
            "{$what} references " . implode(', ', array_map(static fn (string $n): string => "\${$n}", $missing)) . ', which '
            . (count($missing) === 1 ? 'is' : 'are') . ' not set',
        );
    }

    /**
     * Resolve every value of a map, throwing for the first one that cannot be.
     *
     * @param array<string, string> $values
     * @return array<string, string>
     */
    public static function resolveAll(array $values, string $what): array
    {
        $resolved = [];

        foreach ($values as $name => $value) {
            $resolved[$name] = self::resolveOrThrow($value, "{$what} \"{$name}\"");
        }

        return $resolved;
    }

    /** The variables a value references that are not set. @return list<string> */
    public static function missingVariables(string $config): array
    {
        if (str_starts_with($config, '!')) {
            return [];
        }

        $missing = [];

        foreach (self::parts($config) as [$kind, $value]) {
            if ($kind === 'env' && !in_array($value, $missing, true)) {
                $env = getenv($value);

                if (!is_string($env) || $env === '') {
                    $missing[] = $value;
                }
            }
        }

        return $missing;
    }

    /** For tests: forget what commands answered. */
    public static function forgetCommands(): void
    {
        self::$commandCache = [];
    }

    /**
     * @return list<array{0: 'literal'|'env', 1: string}>
     */
    private static function parts(string $config): array
    {
        $parts = [];
        $literal = static function (string $text) use (&$parts): void {
            if ($text === '') {
                return;
            }

            $last = count($parts) - 1;

            if ($last >= 0 && $parts[$last][0] === 'literal') {
                $parts[$last][1] .= $text;

                return;
            }

            $parts[] = ['literal', $text];
        };

        $index = 0;
        $length = strlen($config);

        while ($index < $length) {
            $dollar = strpos($config, '$', $index);

            if ($dollar === false) {
                $literal(substr($config, $index));
                break;
            }

            $literal(substr($config, $index, $dollar - $index));
            $next = $config[$dollar + 1] ?? '';

            if ($next === '$' || $next === '!') {
                $literal($next);
                $index = $dollar + 2;
                continue;
            }

            if ($next === '{') {
                $end = strpos($config, '}', $dollar + 2);

                if ($end === false) {
                    $literal('$');
                    $index = $dollar + 1;
                    continue;
                }

                $name = substr($config, $dollar + 2, $end - $dollar - 2);

                if (preg_match(self::NAME, $name) === 1) {
                    $parts[] = ['env', $name];
                } else {
                    $literal(substr($config, $dollar, $end - $dollar + 1));
                }

                $index = $end + 1;
                continue;
            }

            if (preg_match(self::NAME_PREFIX, substr($config, $dollar + 1), $match) === 1) {
                $parts[] = ['env', $match[0]];
                $index = $dollar + 1 + strlen($match[0]);
                continue;
            }

            $literal('$');
            $index = $dollar + 1;
        }

        return $parts;
    }

    private static function command(string $command): ?string
    {
        if (array_key_exists($command, self::$commandCache)) {
            return self::$commandCache[$command];
        }

        [$code, $stdout] = Process::run([Shell::bash(), '-c', $command], 10.0);
        $output = trim($stdout);

        if ($code !== 0 || $output === '') {
            return null;
        }

        return self::$commandCache[$command] = $output;
    }
}
