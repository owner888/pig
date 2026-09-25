<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Cli;

use Closure;
use Pig\Ai\Utils\Oauth\OauthError;
use Pig\Ai\Utils\Oauth\Provider;
use Pig\CodingAgent\Auth;
use Throwable;

/**
 * `pig-ai` — signing in from a command line instead of from inside pig.
 *
 * Upstream's `ai/cli.ts`, which is a whole second entry point whose only job is the OAuth flows.
 * `/login` does the same thing inside pig; this is for the times there is no terminal UI to be
 * inside — setting a machine up over ssh, a container being built, or a flow that has to be
 * watched because it is not working.
 *
 * **Here rather than in `bin/pig-ai`**, and the bug that decided it is worth recording: the first
 * version was the whole thing inline in the script, and its `$onPrompt` closure was declared
 * `static function` without `use ($ask)` — so the moment Anthropic's flow asked for the pasted
 * code, it called null. Nothing could have caught that except reaching the prompt, and a script
 * that ends in `exit()` is not something a test can reach twice. `bin/pig-ai` is now nine lines
 * over this, which is what `cli.ts` is to `main.ts` upstream anyway.
 *
 * **It writes where pig reads.** Upstream's writes `auth.json` into the *current directory* — a
 * relative path, so a login run inside a checkout leaves a file full of refresh tokens next to
 * the code, and nothing reads it there afterwards either. Here the credentials go through `Auth`,
 * which puts them in the one file `pig` and `pi` both read, with the mode that file gets.
 */
final readonly class SignIn
{
    public const string USAGE = <<<TEXT
    pig-ai — sign in to a provider.

      pig-ai list                    the providers there are
      pig-ai login                   choose one from a list
      pig-ai login <provider>        sign in to that one
      pig-ai help                    this

    The token is kept in the same file `pig` reads — pi's `~/.pi/agent/auth.json` when pi has one,
    and `~/.pig/auth.json` otherwise. `pig` itself has `/login`, which does the same thing; this is
    for a machine with no terminal UI to do it from.

    TEXT;

    /**
     * @param Closure(string): ?string $ask   one line from wherever the answers come from; null
     *        at end of input
     * @param Closure(string): void    $say   ordinary output
     * @param Closure(string): void    $warn  problems, which the caller sends to stderr
     */
    public function __construct(
        private Auth $auth,
        private Closure $ask,
        private Closure $say,
        private Closure $warn,
    ) {
    }

    /**
     * @param list<string> $arguments usually `array_slice($argv, 1)`
     * @return int the exit code
     */
    public function run(array $arguments): int
    {
        $command = $arguments[0] ?? 'help';

        if (in_array($command, ['help', '--help', '-h'], true)) {
            ($this->say)(self::USAGE);

            return 0;
        }

        if ($command === 'list') {
            foreach (Provider::cases() as $provider) {
                ($this->say)(sprintf('  %-20s %s', $provider->value, $provider->label()));
            }

            return 0;
        }

        if ($command !== 'login') {
            ($this->warn)("No command called '{$command}'. Try `pig-ai help`.");

            return 1;
        }

        $provider = $this->chooseProvider($arguments[1] ?? null);

        return $provider === null ? 1 : $this->login($provider);
    }

    private function chooseProvider(?string $named): ?Provider
    {
        if ($named !== null) {
            $provider = Provider::tryFrom($named);

            if ($provider === null) {
                ($this->warn)("No provider called '{$named}'. Try `pig-ai list`.");
            }

            return $provider;
        }

        $choices = Provider::cases();

        ($this->say)('Sign in with:');
        ($this->say)('');

        foreach ($choices as $index => $provider) {
            ($this->say)(sprintf('  %d. %s', $index + 1, $provider->label()));
        }

        ($this->say)('');

        $picked = ($this->ask)('Which one (1-' . count($choices) . ')?');
        $at = $picked === null ? -1 : (int) $picked - 1;

        if (!isset($choices[$at])) {
            // Named rather than "invalid selection": somebody who typed the provider's name
            // instead of its number should be told that both work.
            ($this->warn)('That is not one of the numbers. `pig-ai login <provider>` works too.');

            return null;
        }

        return $choices[$at];
    }

    /**
     * The flow, and what it leaves behind.
     *
     * The caller runs this inside a fiber, because two of the four park one: Gemini CLI and
     * Antigravity listen on a socket for the browser to come back, and Copilot's device flow
     * sleeps between polls.
     */
    private function login(Provider $provider): int
    {
        ($this->say)("Signing in to {$provider->label()}…");

        try {
            $credentials = $this->auth->login(
                $provider,
                function (string $url, ?string $instructions): void {
                    ($this->say)('');
                    ($this->say)('Open this in your browser:');
                    ($this->say)('');
                    ($this->say)($url);

                    if ($instructions !== null && $instructions !== '') {
                        ($this->say)('');
                        ($this->say)($instructions);
                    }

                    ($this->say)('');
                },
                function (string $message, string $placeholder, bool $mayBeEmpty): ?string {
                    $answer = ($this->ask)($placeholder === '' ? "{$message}:" : "{$message} ({$placeholder}):");

                    // An empty answer means "the default" to a flow that allows one — Copilot
                    // takes it as `github.com` — and a cancellation to one that does not. Passed
                    // through either way, so the flow decides, which is where the knowledge of
                    // which is which already lives.
                    return $answer === '' && !$mayBeEmpty ? null : $answer;
                },
                function (string $step): void {
                    ($this->say)($step);
                },
            );
        } catch (OauthError $problem) {
            // Named, because every one of these says what is missing: a client id that is not in
            // this repository, a provider whose flow refused, a token endpoint that answered 400.
            ($this->warn)($problem->getMessage());

            return 1;
        } catch (Throwable $problem) {
            // Anything else is a bug rather than a refusal, and a stack trace on a terminal
            // somebody is setting a machine up on is not the message they need.
            ($this->warn)('The sign-in failed: ' . $problem->getMessage());

            return 1;
        }

        if ($credentials === null) {
            // Escaped, or an empty paste. Not an error — nobody finished, and nothing was
            // written — but not a success either, so it is worth an exit code.
            ($this->warn)('Nothing was signed in.');

            return 1;
        }

        ($this->say)(
            "Signed in to {$provider->label()}"
            . ($credentials->email === null ? '' : " as {$credentials->email}")
            . '.',
        );

        return 0;
    }
}
