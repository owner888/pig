<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Cli;

use Pig\Ai\Model;
use Pig\Ai\Models;
use Pig\Ai\Utils\Oauth\Provider;
use Pig\Async\Async;
use Pig\CodingAgent\Auth;
use Pig\CodingAgent\ModelReference;
use Pig\CodingAgent\ModelResolver;
use Pig\Tui\Style;
use Throwable;

/**
 * `pig auth <check|print-api-key|print-bearer-token>` — a provider's credential, for something
 * outside pig, or whether there is one.
 *
 * Upstream's `cli/auth-command.ts` (`parseAuthCommand()`, `validateAuthCommandArgs()`,
 * `getAuthCredential()`), `cli/auth-check.ts` (`checkProviderAuth()`, `getProviderCredential()`),
 * `cli/credential-print.ts` (`resolveCredentialForPrint()`) and `main.ts`'s `runAuthCommand()`,
 * which ties them together: the sentences, the exit codes (`check`: 0 ready, 1 not ready, 2 invalid;
 * printing: 0 or 1) and the output are theirs.
 *
 * pig's `Auth` is upstream's model runtime and credential store in one, so "the providers there
 * are" is `Models::all()`'s (the built-ins, `models.json`'s), "a stored credential" is `auth.json`'s
 * entry, and `getAuth()` is `Auth::providerAuth()` — run inside the loop, because that is where an
 * OAuth token can be renewed.
 */
final class AuthCommand
{
    /** Upstream's `AUTH_COMMAND_USAGE`. */
    public const array USAGE = [
        'check' => 'pig auth check --provider <provider> [--json] [--credentials] [--no-refresh]',
        'api_key' => 'pig auth print-api-key --provider <provider> [--model <model>]',
        'bearer_token' => 'pig auth print-bearer-token --provider <provider> [--model <model>] [--min-expiry <duration>]',
    ];

    /** Upstream's `DEFAULT_BEARER_TOKEN_MIN_EXPIRY_MS`: thirty minutes. */
    public const int DEFAULT_MIN_EXPIRY_MS = 30 * 60_000;

    /**
     * @param \Closure(string): void $out standard output
     * @param \Closure(string): void $err standard error
     */
    public function __construct(
        private readonly Auth $auth,
        private readonly \Closure $out,
        private readonly \Closure $err,
        /** Problems reading `models.json` — upstream's `modelRuntime.getError()` for `check`. */
        private readonly array $modelProblems = [],
    ) {
    }

    /**
     * `runAuthCommand()`: the exit code.
     *
     * @param list<string> $args everything after `pig`, starting with `auth`
     */
    public function run(array $args): int
    {
        if (self::isHelp($args)) {
            ($this->out)(self::help());

            return 0;
        }

        try {
            $command = self::parseCommand($args);
        } catch (AuthCommandError $error) {
            ($this->err)(Style::red("Error: {$error->getMessage()}") . "\n");

            return 1;
        }

        $parsed = Arguments::parse($command['args']);

        if ($parsed->unknownFlags !== []) {
            $option = array_key_first($parsed->unknownFlags);
            ($this->err)(Style::red("Unknown option --{$option} for \"" . self::name($command['kind']) . '".') . "\n");
            ($this->err)(Style::dim('Use "pig --help" or "' . self::USAGE[$command['kind']] . '".') . "\n");

            return 1;
        }

        try {
            if ($parsed->diagnostics !== []) {
                throw new AuthCommandError(implode("\n", array_map(static fn (array $d): string => $d['message'], $parsed->diagnostics)));
            }

            if ($command['kind'] !== 'check') {
                $credential = Async::run(fn (): string => $this->credentialForPrint($parsed, $command['kind'], $command['minExpiryMs']));
                ($this->out)("{$credential}\n");

                return 0;
            }

            $requested = self::validate($parsed, 'check');
            $credential = null;

            try {
                [$result, $credential] = Async::run(function () use ($parsed, $command): array {
                    $result = $this->check($parsed, !$command['noRefresh']);
                    $credential = null;

                    if ($command['credentials'] && $result['status'] === 'ready') {
                        $credential = $this->providerCredential($result['provider'], !$command['noRefresh']);

                        if ($credential === null) {
                            $result = ['status' => 'not_ready', 'provider' => $result['provider'], 'reason' => 'credential_not_available'];
                        }
                    }

                    return [$result, $credential];
                });
            } catch (Throwable) {
                // Upstream's `catch {}` here: whatever went wrong reading the state is `invalid`,
                // which is an answer — `check` exists to be asked by a script.
                $result = ['status' => 'invalid', 'provider' => $requested['provider'] ?? $requested['model'], 'reason' => 'invalid_state'];
            }

            ($this->out)(($command['json']
                ? json_encode($credential === null ? $result : [...$result, 'credentials' => $credential], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                : ($credential ?? $result['status'])) . "\n");

            return match ($result['status']) {
                'ready' => 0,
                'not_ready' => 1,
                default => 2,
            };
        } catch (AuthCommandError $error) {
            ($this->err)(Style::red("Error: {$error->getMessage()}") . "\n");
        } catch (Throwable $error) {
            // Upstream's "Failed to resolve credential" hides the cause; the cause is the useful half.
            ($this->err)(Style::red("Error: Failed to resolve credential: {$error->getMessage()}") . "\n");
        }

        return $command['kind'] === 'check' ? 2 : 1;
    }

    /** Upstream's `isAuthCommandHelp()`. @param list<string> $args */
    public static function isHelp(array $args): bool
    {
        return ($args[0] ?? null) === 'auth'
            && (!isset($args[1]) || $args[1] === 'help' || in_array('--help', $args, true) || in_array('-h', $args, true));
    }

    /** Upstream's `printAuthCommandHelp()`. */
    public static function help(): string
    {
        return <<<TEXT
        Usage:
          pig auth print-api-key [--provider <provider>] [--model <model>]
          pig auth print-bearer-token [--provider <provider>] [--model <model>] [--min-expiry <duration>]
          pig auth check [--provider <provider>] [--model <model>] [--json] [--credentials] [--no-refresh]

        Auth commands require at least one of --provider or --model. Checks refresh expired OAuth credentials by default; --no-refresh prevents this. --credentials emits the credential, or includes it in JSON output.

        TEXT;
    }

    /**
     * Upstream's `parseAuthCommand()`: which command, its own options taken out, the rest left for
     * `Arguments`.
     *
     * @param list<string> $args
     * @return array{kind: 'check'|'api_key'|'bearer_token', args: list<string>, json: bool, credentials: bool, noRefresh: bool, minExpiryMs: int|null}
     * @throws AuthCommandError
     */
    public static function parseCommand(array $args): array
    {
        $kind = match ($args[1] ?? null) {
            'check' => 'check',
            'print-api-key' => 'api_key',
            'print-bearer-token' => 'bearer_token',
            default => throw new AuthCommandError(
                'Unknown auth command "' . ($args[1] ?? '') . '". Use "pig auth print-api-key", "pig auth print-bearer-token", or "pig auth check".',
            ),
        };

        $rest = [];
        $json = false;
        $credentials = false;
        $noRefresh = false;
        $minExpiryMs = null;
        $count = count($args);

        for ($at = 2; $at < $count; $at++) {
            $arg = $args[$at];

            if ($arg === '--min-expiry') {
                if ($kind !== 'bearer_token') {
                    throw new AuthCommandError('--min-expiry is only supported by print-bearer-token');
                }

                $value = $args[++$at] ?? null;

                if ($value === null || preg_match('/^(\d+)(ms|s|m|h)$/i', $value, $match) !== 1) {
                    throw new AuthCommandError('--min-expiry must use a duration such as 30m or 1h');
                }

                $minExpiryMs = (int) $match[1] * match (strtolower($match[2])) {
                    'ms' => 1,
                    's' => 1_000,
                    'm' => 60_000,
                    default => 3_600_000,
                };

                continue;
            }

            if (in_array($arg, ['--json', '--credentials', '--no-refresh'], true)) {
                if ($kind !== 'check') {
                    throw new AuthCommandError("{$arg} is only supported by auth check");
                }

                match ($arg) {
                    '--json' => $json = true,
                    '--credentials' => $credentials = true,
                    default => $noRefresh = true,
                };

                continue;
            }

            $rest[] = $arg;
        }

        return ['kind' => $kind, 'args' => $rest, 'json' => $json, 'credentials' => $credentials, 'noRefresh' => $noRefresh, 'minExpiryMs' => $minExpiryMs];
    }

    /**
     * Upstream's `validateAuthCommandArgs()`.
     *
     * @return array{provider: ?string, model: ?string}
     * @throws AuthCommandError
     */
    public static function validate(Arguments $args, string $kind): array
    {
        $provider = trim($args->value('provider') ?? '');
        $model = trim($args->value('model') ?? '');
        $provider = $provider === '' ? null : $provider;
        $model = $model === '' ? null : $model;

        if ($args->unknownFlags !== []) {
            throw new AuthCommandError('Unknown option --' . array_key_first($args->unknownFlags) . ' for "' . self::name($kind) . '".');
        }

        if ($args->has('api-key') || $args->messages !== [] || $args->files !== []) {
            throw new AuthCommandError('Auth commands only accept --provider and --model');
        }

        if ($provider === null && $model === null) {
            throw new AuthCommandError($kind === 'check'
                ? 'Auth checks require --provider <provider> or --model <model>'
                : 'Credential printing requires --provider <provider> or --model <model>');
        }

        return ['provider' => $provider, 'model' => $model];
    }

    /** Upstream's `getAuthCommandName()`. */
    private static function name(string $kind): string
    {
        return match ($kind) {
            'check' => 'auth check',
            'api_key' => 'auth print-api-key',
            default => 'auth print-bearer-token',
        };
    }

    /**
     * Upstream's `checkProviderAuth()`.
     *
     * @return array{status: string, provider: string, reason?: string, authType?: string}
     * @throws AuthCommandError
     */
    private function check(Arguments $args, bool $refresh): array
    {
        ['provider' => $provider, 'model' => $model] = self::validate($args, 'check');

        if ($model !== null) {
            $provider = $this->resolveModel($provider, $model)->provider;
        }

        if ($provider === null) {
            throw new AuthCommandError('Unable to resolve an auth provider');
        }

        if ($this->modelProblems !== [] || $this->auth->problems() !== []) {
            return ['status' => 'invalid', 'provider' => $provider, 'reason' => 'invalid_state'];
        }

        if (!in_array($provider, self::providers(), true)) {
            return ['status' => 'not_ready', 'provider' => $provider, 'reason' => 'provider_not_found'];
        }

        try {
            if (!$this->auth->hasKeyFor($provider)) {
                return ['status' => 'not_ready', 'provider' => $provider, 'reason' => 'credentials_not_configured'];
            }

            if ($refresh && $this->auth->providerAuth($provider) === null) {
                return ['status' => 'not_ready', 'provider' => $provider, 'reason' => 'credentials_not_configured'];
            }

            return ['status' => 'ready', 'provider' => $provider, 'authType' => $this->auth->kind($provider) === 'oauth' ? 'oauth' : 'api_key'];
        } catch (Throwable) {
            return ['status' => 'invalid', 'provider' => $provider, 'reason' => 'invalid_state'];
        }
    }

    /** Upstream's `getProviderCredential()`: without a refresh, a stored OAuth token as it is. */
    private function providerCredential(string $provider, bool $refresh): ?string
    {
        if (!$refresh && $this->auth->kind($provider) === 'oauth') {
            return $this->auth->credentials($provider)?->access;
        }

        return $this->credential($provider);
    }

    /**
     * Upstream's `resolveCredentialForPrint()`.
     *
     * @param 'api_key'|'bearer_token' $kind
     * @throws AuthCommandError
     */
    private function credentialForPrint(Arguments $args, string $kind, ?int $minExpiryMs): string
    {
        ['provider' => $cliProvider, 'model' => $cliModel] = self::validate($args, $kind);
        $types = [];

        foreach ($this->auth->providers() as $stored) {
            $types[$stored] = $this->auth->kind($stored);
        }

        /** @var list<string> $providers */
        $providers = [];

        if ($cliProvider !== null) {
            if (!in_array($cliProvider, self::providers(), true)) {
                throw new AuthCommandError("Unknown provider \"{$cliProvider}\". Use --list-models to see available providers.");
            }

            if ($cliModel !== null) {
                $this->resolveModel($cliProvider, $cliModel);
            }

            $providers[] = $cliProvider;
        } else {
            foreach (self::providers() as $provider) {
                if (!isset($types[$provider])) {
                    continue;
                }

                // Upstream's `resolveModelReference()` per provider: a match there, not a custom id.
                if (ModelResolver::reference((string) $cliModel, Models::all(), static fn (): bool => true, $provider, 'strict', true)->status === ModelReference::RESOLVED) {
                    $providers[] = $provider;
                }
            }

            if ($providers === []) {
                throw new AuthCommandError("Model \"{$cliModel}\" not found. Use --list-models to see available models.");
            }
        }

        $credentials = [];

        foreach ($providers as $provider) {
            $type = $types[$provider] ?? null;

            if ($kind === 'api_key' && $type === 'oauth') {
                continue;
            }

            if ($kind === 'bearer_token' && $type !== 'oauth') {
                continue;
            }

            if ($kind === 'bearer_token') {
                // Upstream's `minOAuthValidityMs`: renewed now unless it has that long left.
                $this->auth->freshCredentials($provider, $minExpiryMs ?? self::DEFAULT_MIN_EXPIRY_MS);
            }

            $value = $this->credential($provider);

            if ($value !== null) {
                $credentials[] = ['providerId' => $provider, 'value' => $value];
            }
        }

        if (count($credentials) === 1) {
            return $credentials[0]['value'];
        }

        if ($credentials === []) {
            $providerId = $providers[0] ?? null;
            $type = $providerId === null ? null : ($types[$providerId] ?? null);

            if ($cliProvider !== null && $kind === 'api_key' && $type === 'oauth') {
                throw new AuthCommandError("Provider \"{$providerId}\" is configured with OAuth, not an API key");
            }

            if ($cliProvider !== null && $kind === 'bearer_token' && $type !== 'oauth') {
                throw new AuthCommandError("Provider \"{$providerId}\" is not configured with an OAuth bearer token");
            }

            throw new AuthCommandError('No usable ' . ($kind === 'api_key' ? 'API key' : 'OAuth bearer token') . ' is configured');
        }

        throw new AuthCommandError(
            'Multiple configured providers matched (' . implode(', ', array_column($credentials, 'providerId')) . '). Specify --provider.',
        );
    }

    /**
     * Upstream's `getAuthCredential(await modelRuntime.getAuth(provider))`: the key, or the token of
     * an `Authorization: Bearer` header.
     */
    private function credential(string $provider): ?string
    {
        $resolved = $this->auth->providerAuth($provider);

        if ($resolved === null) {
            return null;
        }

        if ($resolved->auth->apiKey !== null && $resolved->auth->apiKey !== '') {
            return $resolved->auth->apiKey;
        }

        foreach ($resolved->auth->headers ?? [] as $name => $value) {
            if (strtolower((string) $name) === 'authorization' && is_string($value) && preg_match('/^Bearer\s+(.+)$/i', $value, $match) === 1) {
                return $match[1];
            }
        }

        return null;
    }

    /**
     * Upstream's `resolveCliModel()` as these commands use it: within `$provider` when one is
     * given, else anywhere, credentials breaking a tie between providers.
     *
     * @throws AuthCommandError
     */
    private function resolveModel(?string $provider, string $model): Model
    {
        [$choice, $error] = ModelResolver::cli($provider, $model, null, false, Models::all(), $this->auth->hasKeyFor(...));

        if ($choice === null) {
            throw new AuthCommandError($error ?? "Unable to resolve model \"{$model}\"");
        }

        return $choice->model;
    }

    /**
     * The providers there are — upstream's `modelRuntime.getProvider()` answers for these: every
     * provider a model names (the built-ins, `models.json`'s), and the built-in sign-ins. No
     * extension is loaded for these commands, as upstream's `runAuthCommand()` loads none.
     *
     * @return list<string>
     */
    private static function providers(): array
    {
        $ids = array_map(static fn (Model $model): string => $model->provider, Models::all());

        foreach (Provider::cases() as $case) {
            $ids[] = $case->value;
        }

        return array_values(array_unique($ids));
    }
}
