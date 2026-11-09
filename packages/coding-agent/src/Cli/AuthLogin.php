<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Cli;

use Closure;
use Pig\Ai\BuiltinProviders;
use Pig\Ai\Extension\ApiKeyAuth;
use Pig\Ai\Extension\EnvApiKeyAuth;
use Pig\Ai\Extension\OauthFlow;
use Pig\Ai\Extension\ProviderRegistry;
use Pig\Ai\Models;
use Pig\Ai\Stream;
use Pig\Ai\Utils\Oauth\OauthProvider;
use Pig\Ai\Utils\Oauth\TrustedUrl;
use Pig\Async\AbortController;
use Pig\Async\AbortSignal;
use Pig\Async\Loop;
use Pig\CodingAgent\Auth;
use Pig\CodingAgent\ModelRefresh;
use Pig\CodingAgent\ModelsRefreshResult;
use Pig\Tui\Process;
use Throwable;

/**
 * `pig auth login <provider> [--api-key] [--no-browser]` — upstream's `runAuthLogin()`
 * (`cli/auth-login.ts`): log in from the shell and exit, without starting a session, as `/login`
 * does inside one.
 *
 * Account sign-in is the default and takes the provider's browser method; a provider with another
 * method (Anthropic's copy-code, OpenAI's device code) says so, and `--no-browser` takes it instead,
 * asking only when there are several. `--api-key` saves an API key; a provider without account
 * sign-in asks for one anyway, since that is its only way in. Then the provider's catalog is fetched.
 *
 * The providers are the built-in ones and `models.json`'s — no extensions, as upstream loads none
 * here. Upstream's Radius step (pointing the global `mcp.json`'s Radius server at the login) is not
 * here: pig's MCP servers have no `auth: {provider}` to point.
 */
final class AuthLogin
{
    public const string USAGE = 'pig auth login <provider> [--api-key] [--no-browser]';

    /** Upstream's `AbortSignal.timeout(15_000)` around the catalog refresh. */
    private const float REFRESH_TIMEOUT = 15.0;

    /**
     * @param Closure(string): void $write a line of output
     * @param Closure(string): void $error a line of error output
     * @param Closure(string, AbortSignal): ?string $question one line, null when cancelled
     * @param Closure(string, AbortSignal): ?string $secret the same, not echoed
     * @param Closure(string): void $openUrl the browser, at a web URL
     * @param bool $interactive whether there is a terminal to ask on — the prompts need one
     * @param (Closure(string, AbortSignal): ModelsRefreshResult)|null $refresh the provider's
     *        catalog, `ModelRefresh::refresh()` unless a test says otherwise
     */
    public function __construct(
        private readonly Auth $auth,
        private readonly Closure $write,
        private readonly Closure $error,
        private readonly Closure $question,
        private readonly Closure $secret,
        private readonly Closure $openUrl,
        private readonly bool $interactive,
        private readonly ?Closure $refresh = null,
    ) {
    }

    /** Upstream's `createProcessLoginTerminal()`: standard input and output. */
    public static function forTerminal(Auth $auth): self
    {
        return new self(
            $auth,
            static function (string $line): void {
                fwrite(STDOUT, $line . "\n");
            },
            static function (string $line): void {
                fwrite(STDERR, $line . "\n");
            },
            static fn (string $message, AbortSignal $signal): ?string => TerminalQuestion::ask($message, $signal),
            static fn (string $message, AbortSignal $signal): ?string => TerminalQuestion::ask($message, $signal, secret: true),
            static function (string $url): void {
                Process::run(match (PHP_OS_FAMILY) {
                    'Darwin' => ['open', $url],
                    'Windows' => ['cmd', '/c', 'start', '', $url],
                    default => ['xdg-open', $url],
                }, 2.0);
            },
            TerminalQuestion::isTerminal(STDIN) && TerminalQuestion::isTerminal(STDOUT),
        );
    }

    /**
     * @param list<string> $args what follows `pig auth login`
     * @return int the exit code
     */
    public function run(array $args, AbortSignal $signal): int
    {
        $request = self::parse($args);

        if (is_string($request)) {
            ($this->error)($request);

            return 1;
        }

        $provider = self::find($request['provider']);

        if ($provider === null) {
            $names = array_map(
                static fn (array $candidate): string => $candidate['id'],
                array_filter(self::providers(), static fn (array $candidate): bool => $candidate['oauth'] !== null || $candidate['apiKey'] !== null),
            );
            sort($names);
            ($this->error)('Unknown provider "' . self::printable($request['provider']) . '".');
            ($this->error)('Providers: ' . implode(', ', $names));

            return 1;
        }

        if (!$this->interactive) {
            ($this->error)('"' . self::USAGE . '" requires an interactive terminal');

            return 1;
        }

        $type = self::authType($provider, $request['apiKey']);

        if (str_starts_with($type, '!')) {
            ($this->error)(substr($type, 1));

            return 1;
        }

        try {
            $saved = $type === 'oauth'
                ? $this->auth->login(
                    $provider['oauth'],
                    $this->onAuth($provider['id'], $request['browser']),
                    fn (string $message, string $placeholder, bool $mayBeEmpty, ?AbortSignal $closing = null): ?string
                        => $this->prompt($message, $placeholder, $mayBeEmpty, $signal, $closing, secret: false),
                    function (string $step): void {
                        ($this->write)($step);
                    },
                    $signal,
                    $this->onSelect($request['browser'], $signal),
                )
                : $this->auth->loginApiKey(
                    $provider['id'],
                    $provider['apiKey'],
                    // Upstream's API key prompt is a `secret` one: not echoed.
                    fn (string $message, string $placeholder, bool $mayBeEmpty, ?AbortSignal $closing = null): ?string
                        => $this->prompt($message, $placeholder, $mayBeEmpty, $signal, $closing, secret: true),
                    $signal,
                );
        } catch (Throwable $problem) {
            ($this->error)($signal->aborted() ? 'Login cancelled.' : "Failed to log in to {$provider['name']}: {$problem->getMessage()}");

            return 1;
        }

        if ($saved === null) {
            ($this->error)('Login cancelled.');

            return 1;
        }

        ($this->write)(
            ($type === 'oauth' ? "Logged in to {$provider['name']}" : "Saved API key for {$provider['name']}")
            . '. Credentials saved to ' . ($this->auth->path() ?? 'memory'),
        );
        $this->refreshCatalog($provider, $signal);

        return 0;
    }

    /**
     * Upstream's `parseLoginRequest()`.
     *
     * @param list<string> $args
     * @return array{provider: string, apiKey: bool, browser: bool}|string the request, or what is wrong
     */
    private static function parse(array $args): array|string
    {
        $usage = 'Usage: ' . self::USAGE;
        $request = ['provider' => '', 'apiKey' => false, 'browser' => true];

        foreach ($args as $arg) {
            if ($arg === '--api-key') {
                $request['apiKey'] = true;
            } elseif ($arg === '--no-browser') {
                $request['browser'] = false;
            } elseif (str_starts_with($arg, '-') || $request['provider'] !== '') {
                return $usage;
            } else {
                $request['provider'] = $arg;
            }
        }

        if ($request['provider'] === '') {
            return $usage;
        }

        if ($request['apiKey'] && !$request['browser']) {
            return '--no-browser only applies to account sign-in; omit it with --api-key.';
        }

        return $request;
    }

    /**
     * Every provider there is a way into here, with its account sign-in and its API key sign-in —
     * the rows `/login` lists, without the extensions, which are not loaded for this command.
     *
     * @return list<array{id: string, name: string, oauth: OauthProvider|OauthFlow|null, apiKey: ?ApiKeyAuth}>
     */
    public static function providers(): array
    {
        $ids = [];

        foreach (Auth::signIns() as $flow) {
            $ids[$flow instanceof OauthProvider ? $flow->value : $flow->id()] = true;
        }

        foreach (ProviderRegistry::apiKeyProviders() as $registered) {
            $ids[$registered->id] = true;
        }

        foreach ([...BuiltinProviders::apiKeyIds(), ...Models::providers()] as $id) {
            $ids[$id] = true;
        }

        $providers = [];

        foreach (array_keys($ids) as $id) {
            $id = (string) $id;
            $oauth = Auth::signIn($id);
            $name = BuiltinProviders::has($id)
                ? BuiltinProviders::name($id)
                : (ProviderRegistry::get($id)?->name ?? ($oauth instanceof OauthFlow ? $oauth->label() : ucwords(str_replace(['-', '_'], ' ', $id))));
            // A provider pig has no table row for (`models.json`'s) takes its environment's key, as
            // `/login` offers it; a built-in one configured only from the environment takes none.
            $apiKey = ProviderRegistry::apiKeyAuthFor($id)
                ?? BuiltinProviders::apiKeyAuth($id)
                ?? (BuiltinProviders::has($id) || $oauth !== null ? null : new EnvApiKeyAuth("{$name} API key", Stream::envApiKeyNames($id)));
            $providers[] = ['id' => $id, 'name' => $name, 'oauth' => $oauth, 'apiKey' => $apiKey];
        }

        return $providers;
    }

    /**
     * Upstream's `findMatchingProviders()`: the provider whose id or name it is, ignoring case and
     * the whitespace around it.
     *
     * @return array{id: string, name: string, oauth: OauthProvider|OauthFlow|null, apiKey: ?ApiKeyAuth}|null
     */
    public static function find(string $ref): ?array
    {
        $wanted = strtolower(trim($ref));

        if ($wanted === '') {
            return null;
        }

        foreach (self::providers() as $provider) {
            if (strtolower($provider['id']) === $wanted || strtolower($provider['name']) === $wanted) {
                return $provider;
            }
        }

        return null;
    }

    /**
     * Upstream's `selectAuthType()`: the account unless `--api-key` asks for a key; a provider with
     * no account sign-in takes its key, since that is the only way in.
     *
     * @param array{id: string, name: string, oauth: OauthProvider|OauthFlow|null, apiKey: ?ApiKeyAuth} $provider
     * @return 'oauth'|'api_key'|string the type, or `!` and why there is none
     */
    private static function authType(array $provider, bool $apiKey): string
    {
        if (!$apiKey && $provider['oauth'] !== null) {
            return 'oauth';
        }

        if ($provider['apiKey'] !== null) {
            return 'api_key';
        }

        if ($apiKey && $provider['oauth'] !== null) {
            return "!{$provider['name']} has no API key login. Omit --api-key to sign in.";
        }

        return "!{$provider['name']} is configured outside pig (ambient credentials).";
    }

    /** The methods `--no-browser` would have offered, for the hint under the URL. @var list<string> */
    private array $skippedMethods = [];

    /**
     * Upstream's `selectMethod()`: the browser sign-in is the first option, as `/login` lists it.
     * With the browser, it is taken and the others are named under the URL; with `--no-browser`,
     * the other one is taken, or asked for when there are several — and a provider whose only
     * method is the browser one still gets it, without the browser opening.
     *
     * @return Closure(string, list<array{0: string, 1: string}>): ?string
     */
    private function onSelect(bool $browser, AbortSignal $signal): Closure
    {
        return function (string $title, array $options) use ($browser, $signal): ?string {
            $first = $options[0] ?? null;

            if ($first === null) {
                return null;
            }

            $others = array_slice($options, 1);

            if ($browser) {
                $this->skippedMethods = array_map(static fn (array $option): string => $option[1], $others);

                return $first[0];
            }

            if ($others === []) {
                return $first[0];
            }

            if (count($others) > 1) {
                return $this->choose($title, $others, $signal);
            }

            ($this->write)(rtrim($title, ':') . ': ' . $others[0][1]);

            return $others[0][0];
        };
    }

    /**
     * Upstream's `selectInTerminal()`: a numbered list, asked until a number on it comes back.
     *
     * @param list<array{0: string, 1: string}> $options
     */
    private function choose(string $title, array $options, AbortSignal $signal): ?string
    {
        ($this->write)(rtrim($title, ':') . ':');

        foreach ($options as $index => [, $label]) {
            ($this->write)('  ' . ($index + 1) . ". {$label}");
        }

        while (true) {
            $answer = ($this->question)('Select 1-' . count($options) . ': ', $signal);

            if ($answer === null) {
                return null;
            }

            $answer = trim($answer);

            if (preg_match('/^\d+$/', $answer) === 1 && isset($options[(int) $answer - 1])) {
                return $options[(int) $answer - 1][0];
            }

            ($this->write)('Enter a number from 1 to ' . count($options) . '.');
        }
    }

    /**
     * Upstream's `auth_url` and `device_code` events: the URL, printed whatever happens — opened
     * only when it is a web URL and the browser was not declined, since the launcher would run a
     * `file:` URL or a custom protocol's program as readily — and the other ways in, named.
     *
     * @return Closure(string, ?string): void
     */
    private function onAuth(string $providerId, bool $browser): Closure
    {
        return function (string $url, ?string $instructions) use ($providerId, $browser): void {
            $target = TrustedUrl::trustedHttpUrl($url);
            ($this->write)("Open this URL to sign in:\n  " . ($target ?? self::printable($url)));

            if ($instructions !== null && $instructions !== '') {
                ($this->write)($instructions);
            }

            if ($target !== null && $browser) {
                ($this->openUrl)($target);
            }

            $rerun = "\"pig auth login {$providerId} --no-browser\"";

            if (count($this->skippedMethods) === 1) {
                ($this->write)("To use \"{$this->skippedMethods[0]}\" instead, run {$rerun}");
            } elseif ($this->skippedMethods !== []) {
                $labels = implode(', ', array_map(static fn (string $label): string => "\"{$label}\"", $this->skippedMethods));
                ($this->write)("Other sign-in methods: {$labels}. Run {$rerun} to choose one.");
            }
        };
    }

    /**
     * A flow's prompt on the terminal. `$closing` is the flow's own, for a box that races the
     * browser callback and closes when the callback wins; the run's signal ends it too. An empty
     * answer goes back as empty to a flow that takes one (Copilot's `github.com`) and as a
     * cancellation to one that does not, as `pig-ai`'s does.
     */
    private function prompt(string $message, string $placeholder, bool $mayBeEmpty, AbortSignal $signal, ?AbortSignal $closing, bool $secret): ?string
    {
        $either = self::either($signal, $closing);
        $text = $message . ($placeholder !== '' ? " ({$placeholder})" : '') . ': ';

        try {
            $answer = ($secret ? $this->secret : $this->question)($text, $either['signal']);
        } finally {
            ($either['release'])();
        }

        return $answer === null || ($answer === '' && !$mayBeEmpty) ? null : $answer;
    }

    /**
     * A signal that aborts when either does — upstream's `AbortSignal.any()`.
     *
     * @return array{signal: AbortSignal, release: Closure(): void}
     */
    private static function either(AbortSignal $one, ?AbortSignal $other): array
    {
        if ($other === null) {
            return ['signal' => $one, 'release' => static fn () => null];
        }

        $controller = new AbortController();
        $abort = static fn () => $controller->abort();
        $listeners = [[$one, $one->onAbort($abort)], [$other, $other->onAbort($abort)]];

        if ($one->aborted() || $other->aborted()) {
            $controller->abort();
        }

        return [
            'signal' => $controller->signal,
            'release' => static function () use ($listeners): void {
                foreach ($listeners as [$signal, $id]) {
                    $signal->removeListener($id);
                }
            },
        ];
    }

    /**
     * Upstream's `refreshProviderCatalog()`: the provider's catalog now, since a dynamic one stays
     * empty until the first signed-in refresh — fifteen seconds at most, and the login stands
     * whatever happens to it.
     *
     * @param array{id: string, name: string, oauth: OauthProvider|OauthFlow|null, apiKey: ?ApiKeyAuth} $provider
     */
    private function refreshCatalog(array $provider, AbortSignal $signal): void
    {
        if (!ModelRefresh::networkEnabled()) {
            return;
        }

        $controller = new AbortController();
        $timer = Loop::get()->delay(self::REFRESH_TIMEOUT, static fn () => $controller->abort('The operation was aborted due to timeout'));
        $listener = $signal->onAbort(static fn () => $controller->abort('Cancelled'));
        $skipped = "Skipped the {$provider['name']} model catalog refresh; pig refreshes it on next start.";

        try {
            $result = $this->refresh !== null
                ? ($this->refresh)($provider['id'], $controller->signal)
                : ModelRefresh::refresh($this->auth, [$provider['id']], $controller->signal);

            if ($signal->aborted()) {
                ($this->write)($skipped);
            } elseif ($result->aborted) {
                ($this->write)("The {$provider['name']} model catalog refresh timed out; pig refreshes it on next start.");
            } elseif ($result->errors !== []) {
                ($this->write)("The {$provider['name']} model catalog could not be refreshed; pig retries on next start.");
            }
        } catch (Throwable $problem) {
            ($this->write)($signal->aborted() ? $skipped : "The {$provider['name']} model catalog could not be refreshed: {$problem->getMessage()}");
        } finally {
            Loop::get()->cancel($timer);
            $signal->removeListener($listener);
        }
    }

    /** Upstream's `replaceControlCharacters()`: a string typed by somebody, safe to print. */
    public static function printable(string $text): string
    {
        return (string) preg_replace('/[\x00-\x1f\x7f]/', '?', $text);
    }
}
