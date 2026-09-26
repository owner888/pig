<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Cli;

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
 * Ported from upstream's `cli/args.ts`, which spells out each option for the same reason.
 * Lives here rather than in `bin/pig` so it can be tested: a script that calls `exit()` is
 * not something a test can call twice.
 */
final readonly class Arguments
{
    /** Every option that is followed by its value. Everything else is a flag. */
    public const array TAKES_A_VALUE = [
        'model', 'theme', 'thinking', 'cwd', 'resume', 'skills-dir', 'mode', 'api-key', 'models', 'proxy',
        'tools',
    ];

    /**
     * The short ones, and the long option each stands for.
     *
     * Upstream's five. A short option is only ever an abbreviation: it resolves to its long
     * name and then goes through exactly the same handling, so `-r path` and `--resume path`
     * cannot come to mean different things.
     */
    public const array SHORT = [
        'c' => 'continue',
        'h' => 'help',
        'p' => 'print',
        'r' => 'resume',
        'v' => 'version',
    ];

    /** The three modes `--mode` accepts. */
    public const array MODES = ['text', 'json', 'rpc'];

    /**
     * @param array<string, string> $options a flag is present with an empty value
     * @param list<string>          $messages what to say, in order
     * @param list<string>          $files    the `@file` paths, without their `@`
     */
    public function __construct(
        public array $options,
        public array $messages,
        public array $files,
    ) {
    }

    /** @param list<string> $arguments usually `array_slice($argv, 1)` */
    public static function parse(array $arguments): self
    {
        $options = [];
        $rest = [];
        $verbatim = [];

        for ($at = 0; $at < count($arguments); $at++) {
            $argument = $arguments[$at];

            if ($argument === '--') {
                // Everything after it is a message exactly as written — not an option even
                // if it starts with a dash, and not a file even if it starts with an `@`.
                // It is the only way to say something that begins with either, and half an
                // escape hatch is not one.
                $verbatim = array_slice($arguments, $at + 1);

                break;
            }

            $name = self::shortOf($argument);

            if ($name === null) {
                if (!str_starts_with($argument, '--')) {
                    $rest[] = $argument;

                    continue;
                }

                $name = substr($argument, 2);

                if (str_contains($name, '=')) {
                    [$name, $value] = explode('=', $name, 2);
                    $options[$name] = $value;

                    continue;
                }
            }

            // From here the long and the short form are the same thing, which is the point:
            // resolving `-r` to `resume` and then asking `TAKES_A_VALUE` about it is what
            // keeps `-r path` and `--resume path` from drifting apart. Giving every short
            // option an empty value — which is what this did first — would have read that
            // path as a message and said nothing about it.

            if (!in_array($name, self::TAKES_A_VALUE, true)) {
                $options[$name] = '';

                continue;
            }

            // An option at the end with nothing after it gets an empty value rather than
            // reading past the end of the array. Whoever validates it says what is wrong,
            // which is a better message than one from here about array bounds.
            $options[$name] = $arguments[++$at] ?? '';
        }

        [$messages, $files] = self::split($rest);

        return new self($options, [...$messages, ...$verbatim], $files);
    }

    /**
     * Which way in: `text`, `json`, `rpc`, or null for the terminal.
     *
     * Upstream's rule, kept: `--mode` given at all means no terminal, and `-p` is the short
     * way of saying `--mode text`. So the terminal is what you get when neither was said,
     * and nothing has to ask for it.
     *
     * @return string|null the mode as it was written, valid or not — `isKnownMode()` judges it
     */
    public function mode(): ?string
    {
        return $this->options['mode'] ?? (isset($this->options['print']) ? 'text' : null);
    }

    public function isInteractive(): bool
    {
        return $this->mode() === null;
    }

    public function isKnownMode(): bool
    {
        $mode = $this->mode();

        return $mode === null || in_array($mode, self::MODES, true);
    }

    /**
     * Whether a message has to be on the command line for this to do anything.
     *
     * The terminal needs none — somebody will type one. **`--mode rpc` needs none either, and
     * missing that made rpc mode impossible to start**: its conversation arrives as commands on
     * standard input, so `bin/pig --mode rpc` was refused for having no message while
     * `bin/pig --mode rpc "hello"` was refused for having one. Two guards, each right on its own,
     * closing the door between them. See CLAUDE.md.
     *
     * A method here rather than a condition in `bin/pig` so it can be tested, which is the same
     * reason this class exists at all.
     */
    public function needsAMessage(): bool
    {
        return !$this->isInteractive() && $this->mode() !== 'rpc';
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

    /** `-p`, as the long option it means, or null when this is not a short option. */
    private static function shortOf(string $argument): ?string
    {
        if (!str_starts_with($argument, '-') || str_starts_with($argument, '--')) {
            return null;
        }

        return self::SHORT[substr($argument, 1)] ?? null;
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
