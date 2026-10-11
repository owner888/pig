<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Cli;

use Pig\Ai\Utils\Utf8;

/**
 * What was typed on the command line, told apart.
 *
 * Three kinds of thing arrive mixed together: options, messages, and `@file` paths.
 *
 * **Which options take a value is a list, not a guess.** It used to be inferred — take the
 * next argument unless it starts with a dash — which worked for exactly as long as nothing
 * but options was ever passed. The moment a prompt could be passed too,
 * `bin/pig --read-only "fix the bug"` read the prompt as the value of `--read-only` and then
 * had no prompt, silently. A guess about what the next word means is not something a CLI can
 * afford.
 *
 * Ported from upstream's `cli/args.ts` `parseArgs()`, which spells out each option for the same
 * reason, and its rules for what is not on the list:
 *
 * - **An unknown short option is an error** (`Unknown option: -x`), as upstream's diagnostic.
 * - **An unknown long option is kept for the extensions** (`unknownFlags`): `--name=value`, or
 *   `--name value` when the next argument starts with neither `-` nor `@`, else `true`. Whether
 *   some extension registered it is only known once they have loaded, so `bin/pig` refuses the
 *   unregistered ones then — upstream's `applyExtensionFlagValues()`.
 * - **A mistake is a diagnostic, not an exception**, so every one is reported before exiting.
 *
 * Lives here rather than in `bin/pig` so it can be tested: a script that calls `exit()` is
 * not something a test can call twice.
 */
final readonly class Arguments
{
    /** Every option that is followed by its value. Everything else is a flag. */
    public const array TAKES_A_VALUE = [
        'provider', 'model', 'theme', 'thinking', 'cwd', 'skills-dir', 'mode', 'api-key', 'models', 'proxy',
        'tools', 'exclude-tools', 'export', 'extension', 'session', 'session-id', 'fork', 'web-host', 'web-port',
        'mcp-host', 'mcp-port', 'session-dir', 'name', 'use-theme', 'skill', 'prompt-template',
        'system-prompt', 'append-system-prompt', 'tui-mode',
    ];

    /**
     * Every option that is a flag. Upstream's, then pig's own (`--read-only`, `--no-proxy`,
     * `--no-hooks`, `--no-tool-files`, the update check, and the web and MCP modes').
     * `--list-models` is neither list: its search is optional, as upstream's.
     */
    public const array FLAGS = [
        'help', 'version', 'continue', 'resume', 'print', 'no-session', 'no-tools', 'no-builtin-tools',
        'no-extensions', 'no-mcp', 'no-skills', 'no-prompt-templates', 'no-themes', 'no-context-files',
        'verbose', 'approve', 'no-approve', 'offline',
        'read-only', 'no-proxy', 'no-hooks', 'no-tool-files', 'no-update-check', 'update-check', 'mcp-stdio', 'no-browser',
    ];

    /**
     * The options that may be given more than once, each time adding one — upstream's "can be used
     * multiple times". `$options` still holds the last; `values()` has them all, in order.
     */
    public const array REPEATABLE = ['extension', 'skill', 'prompt-template', 'theme', 'append-system-prompt'];

    /**
     * The short ones, and the long option each stands for.
     *
     * Upstream's, every one — the two-letter ones included. A short option is only ever an abbreviation: it resolves to its long
     * name and then goes through exactly the same handling, so `-t list` and `--tools list`
     * cannot come to mean different things.
     */
    public const array SHORT = [
        'a' => 'approve',
        'na' => 'no-approve',
        'c' => 'continue',
        'e' => 'extension',
        'h' => 'help',
        'n' => 'name',
        'nbt' => 'no-builtin-tools',
        'nc' => 'no-context-files',
        'ne' => 'no-extensions',
        'np' => 'no-prompt-templates',
        'ns' => 'no-skills',
        'nt' => 'no-tools',
        'p' => 'print',
        'r' => 'resume',
        't' => 'tools',
        'v' => 'version',
        'xt' => 'exclude-tools',
    ];

    /** The modes `--mode` accepts: upstream's three, then pig's `web` and `mcp`. */
    public const array MODES = ['text', 'json', 'rpc', 'web', 'mcp'];

    /** Upstream's `VALID_THINKING_LEVELS`. */
    public const array THINKING_LEVELS = ['off', 'minimal', 'low', 'medium', 'high', 'xhigh', 'max'];

    /** Upstream's `TuiMode`. */
    public const array TUI_MODES = ['regular', 'fullscreen'];

    /**
     * @param array<string, string> $options a flag is present with an empty value; an unknown long
     *        option is here too (a valueless one as `''`), which is what `ExtensionApi::applyFlags()` reads
     * @param list<string> $messages what to say, in order
     * @param list<string> $files    the `@file` paths, without their `@`
     * @param array<string, list<string>> $repeated every value of each `REPEATABLE` option given
     * @param array<string, string|true> $unknownFlags upstream's `unknownFlags`: the long options no
     *        list names, for an extension to have registered — `true` when no value followed
     * @param list<array{type: 'error'|'warning', message: string}> $diagnostics upstream's, in order
     */
    public function __construct(
        public array $options,
        public array $messages,
        public array $files,
        public array $repeated = [],
        public array $unknownFlags = [],
        public array $diagnostics = [],
    ) {
    }

    /** @param list<string> $arguments usually `array_slice($argv, 1)` */
    public static function parse(array $arguments): self
    {
        $options = [];
        $repeated = [];
        $unknown = [];
        $diagnostics = [];
        $rest = [];
        $count = count($arguments);

        $error = static function (string $message) use (&$diagnostics): void {
            $diagnostics[] = ['type' => 'error', 'message' => $message];
        };

        for ($at = 0; $at < $count; $at++) {
            $argument = $arguments[$at];

            if ($argument === '--') {
                // Upstream's: everything after it is positional — a message even if it starts with a
                // dash, and still a file when it starts with an `@`.
                array_push($rest, ...array_slice($arguments, $at + 1));

                break;
            }

            $value = null;

            if (str_starts_with($argument, '--')) {
                $name = substr($argument, 2);

                if (str_contains($name, '=')) {
                    [$name, $value] = explode('=', $name, 2);
                }
            } elseif (str_starts_with($argument, '-')) {
                $name = self::SHORT[substr($argument, 1)] ?? null;

                if ($name === null) {
                    // Upstream's `Unknown option: -x`. Read as a message, which this used to do,
                    // a typo became the prompt.
                    $error("Unknown option: {$argument}");

                    continue;
                }
            } else {
                $rest[] = $argument;

                continue;
            }

            $next = $arguments[$at + 1] ?? null;
            $known = in_array($name, self::TAKES_A_VALUE, true) || in_array($name, self::FLAGS, true) || $name === 'list-models';

            if (!$known) {
                // Upstream's `unknownFlags`, for an extension's `registerFlag()`.
                if ($value === null && $next !== null && !str_starts_with($next, '-') && !str_starts_with($next, '@')) {
                    $value = $next;
                    $at++;
                }

                $unknown[$name] = $value ?? true;
                $options[$name] = $value ?? '';

                continue;
            }

            if ($name === 'list-models') {
                // The search is optional: upstream takes the next argument unless it is an option
                // or a file.
                if ($value === null && $next !== null && !str_starts_with($next, '-') && !str_starts_with($next, '@')) {
                    $value = $next;
                    $at++;
                }

                $options[$name] = $value ?? '';

                continue;
            }

            if ($name === 'print') {
                $options['print'] = '';

                // Upstream's `-p "prompt"`: the next argument is the message unless it is a file or
                // looks like an option (`---` is text, so a prompt can be a Markdown rule).
                if ($value === null && $next !== null && !str_starts_with($next, '@') && (!str_starts_with($next, '-') || str_starts_with($next, '---'))) {
                    $rest[] = $next;
                    $at++;
                }

                continue;
            }

            if (in_array($name, self::FLAGS, true)) {
                $options[$name] = '';

                continue;
            }

            // From here the long and the short form are the same thing, which is the point:
            // resolving `-t` to `tools` and then asking `TAKES_A_VALUE` about it is what keeps
            // `-t list` and `--tools list` from drifting apart.
            if ($value === null) {
                // The ones upstream refuses when their value is missing or is the next option.
                if (in_array($name, ['mode', 'use-theme', 'tui-mode'], true) && ($next === null || str_starts_with($next, '-'))) {
                    $error(match ($name) {
                        'mode' => '--mode requires ' . self::orList(self::MODES),
                        'use-theme' => '--use-theme requires a theme name',
                        'tui-mode' => '--tui-mode requires regular or fullscreen',
                    });

                    continue;
                }

                if ($name === 'name' && $next === null) {
                    $error('--name requires a value');

                    continue;
                }

                // Any other option at the end with nothing after it gets an empty value rather
                // than reading past the end. Whoever validates it says what is wrong.
                $value = $next ?? '';

                if ($next !== null) {
                    $at++;
                }
            }

            if ($name === 'mode' && !in_array($value, self::MODES, true)) {
                $error("Invalid mode \"{$value}\". Valid values: " . implode(', ', self::MODES));

                continue;
            }

            if ($name === 'tui-mode' && !in_array($value, self::TUI_MODES, true)) {
                $error("Invalid TUI mode \"{$value}\". Valid values: regular, fullscreen");

                continue;
            }

            if ($name === 'thinking' && !in_array($value, self::THINKING_LEVELS, true)) {
                // A warning and no level, as upstream's: the run goes ahead on the default.
                $diagnostics[] = [
                    'type' => 'warning',
                    'message' => "Invalid thinking level \"{$value}\". Valid values: " . implode(', ', self::THINKING_LEVELS),
                ];

                continue;
            }

            $options[$name] = $value;

            if (in_array($name, self::REPEATABLE, true)) {
                $repeated[$name][] = $value;
            }
        }

        [$messages, $files] = self::split($rest);

        // Sanitised because a shell hands over **bytes**, not text: `pig $'ask about \x80 this'`
        // puts a byte that is not UTF-8 into the first message, and everything that measures a
        // line goes through `Graphemes::split()`, which throws on one. Upstream never sees this
        // because Node decodes `process.argv` as UTF-8 and substitutes U+FFFD for what it cannot
        // read — the platform doing for free what has to be said here.
        $said = array_map(Utf8::sanitize(...), $messages);

        return new self($options, $said, $files, $repeated, $unknown, $diagnostics);
    }

    /**
     * Upstream's `resolveAppMode()`: which way in, given whether standard input and output are
     * terminals.
     *
     * `--mode rpc` and `--mode json` say so outright (and pig's `web` and `mcp` likewise); otherwise
     * `-p`, or **either stream not being a terminal**, is print mode — `git diff | pig "review this"`
     * prints rather than drawing a UI nobody can see — and only with both is it the terminal.
     * `--mode text` is the default output and says nothing about which: on a terminal it opens
     * the UI, as upstream's does.
     *
     * @return 'interactive'|'print'|'json'|'rpc'|'web'|'mcp'
     */
    public function appMode(bool $stdinIsTty, bool $stdoutIsTty): string
    {
        $mode = $this->options['mode'] ?? null;

        if (in_array($mode, ['rpc', 'json', 'web', 'mcp'], true)) {
            return $mode;
        }

        if (isset($this->options['print']) || !$stdinIsTty || !$stdoutIsTty) {
            return 'print';
        }

        return 'interactive';
    }

    /** @return list<string> the error diagnostics' messages, in order */
    public function errors(): array
    {
        return array_values(array_map(
            static fn (array $diagnostic): string => $diagnostic['message'],
            array_filter($this->diagnostics, static fn (array $diagnostic): bool => $diagnostic['type'] === 'error'),
        ));
    }

    public function has(string $option): bool
    {
        return isset($this->options[$option]);
    }

    /** The value of an option, or null when it was not given. A flag's value is `''`. */
    public function value(string $option): ?string
    {
        return $this->options[$option] ?? null;
    }

    /**
     * Every value a `REPEATABLE` option was given, in order; empty when it was not given.
     *
     * @return list<string>
     */
    public function values(string $option): array
    {
        return $this->repeated[$option] ?? [];
    }

    /** @param list<string> $words */
    private static function orList(array $words): string
    {
        $last = array_pop($words);

        return implode(', ', $words) . ', or ' . $last;
    }

    /**
     * @param list<string> $rest
     * @return array{0: list<string>, 1: list<string>}
     */
    private static function split(array $rest): array
    {
        $messages = [];
        $files = [];

        foreach ($rest as $argument) {
            // A bare `@` is not a path to anything, so it is a message — probably a typo, and
            // reading it as a request to open a file called nothing helps nobody.
            if (str_starts_with($argument, '@') && $argument !== '@') {
                $files[] = substr($argument, 1);

                continue;
            }

            $messages[] = $argument;
        }

        return [$messages, $files];
    }
}
