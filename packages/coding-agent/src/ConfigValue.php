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
 * - `!command` runs the rest as a shell command and uses its stdout, trimmed; no output, a non-zero
 *   exit or a timeout (ten seconds) is no value.
 * - `${NAME}` and `$NAME` are replaced with the environment variable — the `$env` given first, then
 *   the process's. A value with a variable that is not set resolves to **null** rather than to the
 *   text with a hole in it: a header `Bearer ` with nothing after it is a 401 that names nothing.
 * - `$$` is a literal `$` and `$!` a literal `!`, so a password with a dollar in it can be written.
 * - Anything else is itself — **a bare `MY_KEY` included**, which is a literal and not a variable.
 *
 * Two flavours, as upstream has them: `resolve()` caches a command's answer for the life of the
 * process (upstream's `resolveConfigValue()`, which `auth.json` keys go through), and
 * `resolveUncached()` / `resolveOrThrow()` run it every time (upstream's `resolveConfigValueUncached()`
 * and `resolveConfigValueOrThrow()`, which `models.json` keys and headers, extension providers and
 * `mcp.json` go through — "Commands in `models.json` run at request time and are not cached").
 */
final class ConfigValue
{
    /** @var array<string, string|null> command => its output (null for none), for the life of the process */
    private static array $commandCache = [];

    private const string NAME = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    private const string NAME_PREFIX = '/^[A-Za-z_][A-Za-z0-9_]*/';

    /**
     * Upstream's `resolveConfigValue()`: null when a referenced variable is not set or the command
     * failed. A command's answer — none included — is cached.
     *
     * @param array<string, string>|null $env looked in before the process environment
     */
    public static function resolve(string $config, ?array $env = null): ?string
    {
        if (self::isCommand($config)) {
            $command = substr($config, 1);

            if (!array_key_exists($command, self::$commandCache)) {
                self::$commandCache[$command] = self::command($command);
            }

            return self::$commandCache[$command];
        }

        return self::template($config, $env);
    }

    /**
     * Upstream's `resolveConfigValueUncached()`: `resolve()` with the command run every time.
     *
     * @param array<string, string>|null $env
     */
    public static function resolveUncached(string $config, ?array $env = null): ?string
    {
        return self::isCommand($config) ? self::command(substr($config, 1)) : self::template($config, $env);
    }

    /**
     * Upstream's `resolveConfigValueOrThrow()`: resolve (uncached), or say what is missing in
     * upstream's words — "Failed to resolve <what> from shell command: <command>", "… from environment
     * variable: NAME", "… from environment variables: A, B".
     *
     * @param string $what names the value in the complaint: `MCP server "docs" header "Authorization"`
     * @param array<string, string>|null $env
     */
    public static function resolveOrThrow(string $config, string $what, ?array $env = null): string
    {
        $resolved = self::resolveUncached($config, $env);

        if ($resolved !== null) {
            return $resolved;
        }

        if (self::isCommand($config)) {
            throw new RuntimeException("Failed to resolve {$what} from shell command: " . substr($config, 1));
        }

        $missing = self::missingVariables($config, $env);

        if (count($missing) === 1) {
            throw new RuntimeException("Failed to resolve {$what} from environment variable: {$missing[0]}");
        }

        if ($missing !== []) {
            throw new RuntimeException("Failed to resolve {$what} from environment variables: " . implode(', ', $missing));
        }

        throw new RuntimeException("Failed to resolve {$what}");
    }

    /**
     * Upstream's `resolveHeadersOrThrow()`: every value of a map, throwing for the first one that cannot
     * be — `<what> "<name>"` in the complaint, so `MCP server "docs" header` names `… header "X-Key"`.
     *
     * @param array<string, string> $values
     * @param array<string, string>|null $env
     * @return array<string, string>
     */
    public static function resolveAll(array $values, string $what, ?array $env = null): array
    {
        $resolved = [];

        foreach ($values as $name => $value) {
            $resolved[$name] = self::resolveOrThrow($value, "{$what} \"{$name}\"", $env);
        }

        return $resolved;
    }

    /** Upstream's `isCommandConfigValue()`. */
    public static function isCommand(string $config): bool
    {
        return str_starts_with($config, '!');
    }

    /**
     * Upstream's `getConfigValueEnvVarNames()`: the variables a value references, once each, in order.
     * None for a command.
     *
     * @return list<string>
     */
    public static function variables(string $config): array
    {
        if (self::isCommand($config)) {
            return [];
        }

        $names = [];

        foreach (self::parts($config) as [$kind, $value]) {
            if ($kind === 'env' && !in_array($value, $names, true)) {
                $names[] = $value;
            }
        }

        return $names;
    }

    /**
     * Upstream's `getMissingConfigValueEnvVarNames()`: the variables a value references that are not set.
     *
     * @param array<string, string>|null $env
     * @return list<string>
     */
    public static function missingVariables(string $config, ?array $env = null): array
    {
        return array_values(array_filter(self::variables($config), static fn (string $name): bool => self::env($name, $env) === null));
    }

    /**
     * Upstream's `isConfigValueConfigured()`: every variable it references is set. A command and a
     * literal always are — whether the command answers is only known by running it.
     *
     * @param array<string, string>|null $env
     */
    public static function isConfigured(string $config, ?array $env = null): bool
    {
        return self::missingVariables($config, $env) === [];
    }

    /** For tests: forget what commands answered — upstream's `clearConfigValueCache()`. */
    public static function forgetCommands(): void
    {
        self::$commandCache = [];
    }

    /** Upstream's `resolveEnvConfigValue()`: `env?.[name] || process.env[name] || undefined`. */
    private static function env(string $name, ?array $env): ?string
    {
        $given = $env[$name] ?? null;

        if (is_string($given) && $given !== '') {
            return $given;
        }

        $value = getenv($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** Upstream's `resolveTemplate()`. */
    private static function template(string $config, ?array $env): ?string
    {
        $resolved = '';

        foreach (self::parts($config) as [$kind, $value]) {
            if ($kind === 'literal') {
                $resolved .= $value;
                continue;
            }

            $found = self::env($value, $env);

            if ($found === null) {
                return null;
            }

            $resolved .= $found;
        }

        return $resolved;
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

    /** Upstream's `executeWithDefaultShell()`: stdout trimmed, null for none, a failure or a timeout. */
    private static function command(string $command): ?string
    {
        [$code, $stdout] = Process::run([Shell::bash(), '-c', $command], 10.0);
        $output = trim($stdout);

        return $code !== 0 || $output === '' ? null : $output;
    }
}
