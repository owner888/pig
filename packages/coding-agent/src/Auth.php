<?php

declare(strict_types=1);

namespace Pig\CodingAgent;

use Closure;
use Pig\Ai\Models;
use Pig\Ai\Stream;
use Pig\Ai\Utils\Oauth\Anthropic;
use Pig\Ai\Utils\Oauth\Credentials;
use Pig\Ai\Utils\Oauth\GeminiCli;
use Pig\Ai\Utils\Oauth\GithubCopilot;
use Pig\Ai\Utils\Oauth\OauthError;
use Pig\Ai\Utils\Oauth\Pkce;
use Pig\Ai\Utils\Oauth\Provider;
use Pig\Async\AbortSignal;
use Throwable;

/**
 * Where the keys and the tokens live.
 *
 * Upstream's `core/auth-storage.ts`, named after `Settings` rather than after upstream's
 * `AuthStorage`: both are one JSON file under the home directory with a typed reader over it,
 * and the developer chose to keep those two matching. One object per provider, under upstream's
 * own shape, so a file either tool wrote is read by both:
 *
 * ```json
 * {
 *   "anthropic":  {"type": "oauth",   "refresh": "…", "access": "sk-ant-oat…", "expires": 0},
 *   "openai":     {"type": "api_key", "key": "sk-…"}
 * }
 * ```
 *
 * **It is pi's file, not a copy of it.** `discover()` opens `~/.pi/agent/auth.json` when that
 * exists and only falls back to `~/.pig/auth.json`, and the reason is not tidiness: Anthropic
 * **rotates** refresh tokens, so the old one is void the moment a new one is issued. Two files
 * holding the same token is two tools taking it in turns to log each other out — whichever
 * refreshes first wins and the other has to sign in again. One file is the only arrangement
 * where signing in once means signing in once.
 *
 * The file is created `0600` inside a `0700` directory, as upstream does. A credential readable
 * by every account on the machine is the kind of thing nobody notices until it matters.
 */
final class Auth
{
    private const string FILE = 'auth.json';

    /** @var array<string, array<string, mixed>> one entry per provider, as written */
    private array $data = [];

    /** @var array<string, string> keys given on the command line, which are never written down */
    private array $runtime = [];

    /** @var array<string, string> what a `models.json` provider's `apiKey` said — see `setCustomProviderKeys()` */
    private array $customKeys = [];

    /** @var list<string> what could not be read, for the caller to complain about */
    private array $problems = [];

    /**
     * @param Settings|null $settings where Gemini CLI's client id and secret may be kept. Only
     *        that one flow needs them, and they are not in this repository — see `googleClient()`.
     */
    public function __construct(
        private readonly ?string $path,
        private readonly ?Settings $settings = null,
    ) {
        $this->reload();
    }

    /**
     * pi's file if there is one, pig's otherwise.
     *
     * @param string|null $path a file to use instead, which is how a test gets its own
     */
    public static function discover(?string $path = null, ?Settings $settings = null): self
    {
        if ($path !== null) {
            return new self($path, $settings);
        }

        $theirs = Config::piHome() . '/' . self::FILE;

        return new self(is_file($theirs) ? $theirs : Config::home() . '/' . self::FILE, $settings);
    }

    /** Credentials that are never written anywhere, for tests and for `--no-save`. */
    public static function inMemory(?Settings $settings = null): self
    {
        return new self(null, $settings);
    }

    public function path(): ?string
    {
        return $this->path;
    }

    /** @return list<string> */
    public function problems(): array
    {
        return $this->problems;
    }

    public function reload(): void
    {
        $this->data = [];

        if ($this->path === null || !is_file($this->path)) {
            return;
        }

        if (!is_readable($this->path)) {
            $this->problems[] = "Could not read {$this->path}";

            return;
        }

        $decoded = json_decode((string) file_get_contents($this->path), true);

        if (!is_array($decoded)) {
            // Named rather than ignored, which is what `Settings` does with the same
            // mistake: a credentials file with a typo in it is otherwise a login that
            // quietly stopped working.
            $this->problems[] = "{$this->path} is not valid JSON, so no stored credentials were read";

            return;
        }

        foreach ($decoded as $provider => $entry) {
            if (is_array($entry)) {
                $this->data[(string) $provider] = $entry;
            }
        }
    }

    /**
     * Which providers have something stored.
     *
     * Upstream calls this `list()`, which is legal as a method name in PHP and reads as though
     * it returns the credentials rather than the names.
     *
     * @return list<string>
     */
    public function providers(): array
    {
        return array_keys($this->data);
    }

    public function has(string $provider): bool
    {
        return isset($this->data[$provider]);
    }

    /** `api_key`, `oauth`, or null for a provider nothing is stored for. */
    public function kind(string $provider): ?string
    {
        $type = $this->data[$provider]['type'] ?? null;

        return is_string($type) ? $type : null;
    }

    /** The stored tokens for a provider signed in with OAuth, or null. */
    public function credentials(Provider $provider): ?Credentials
    {
        $entry = $this->data[$provider->value] ?? null;

        if ($entry === null || ($entry['type'] ?? null) !== 'oauth') {
            return null;
        }

        $refresh = $entry['refresh'] ?? null;
        $access = $entry['access'] ?? null;

        if (!is_string($refresh) || !is_string($access)) {
            return null;
        }

        return new Credentials(
            $refresh,
            $access,
            is_int($entry['expires'] ?? null) ? $entry['expires'] : 0,
            self::text($entry, 'enterpriseUrl'),
            self::text($entry, 'projectId'),
            self::text($entry, 'email'),
        );
    }

    public function setApiKey(string $provider, string $key): void
    {
        $this->data[$provider] = ['type' => 'api_key', 'key' => $key];
        $this->save();
    }

    public function setCredentials(Provider $provider, Credentials $credentials): void
    {
        $this->data[$provider->value] = array_filter(
            [
                'type' => 'oauth',
                'refresh' => $credentials->refresh,
                'access' => $credentials->access,
                'expires' => $credentials->expires,
                'enterpriseUrl' => $credentials->enterpriseUrl,
                'projectId' => $credentials->projectId,
                'email' => $credentials->email,
            ],
            static fn (mixed $value): bool => $value !== null,
        );

        $this->save();
    }

    public function remove(string $provider): void
    {
        unset($this->data[$provider]);
        $this->save();
    }

    /**
     * A key given on the command line, for this run only.
     *
     * Upstream's `setRuntimeApiKey`, and it beats everything else because it is the most
     * recent thing anybody said.
     */
    public function setRuntimeApiKey(string $provider, string $key): void
    {
        $this->runtime[$provider] = $key;
    }

    /**
     * The key to send for a provider, renewing an expired token on the way.
     *
     * Upstream's order, unchanged: what was typed, then a stored API key, then a stored OAuth
     * token, then the environment. `Stream::envApiKey()` is the last step rather than a second
     * table of variable names — it is upstream's `getEnvApiKey()` and it already knows that
     * `ANTHROPIC_OAUTH_TOKEN` beats `ANTHROPIC_API_KEY`.
     *
     * **A refresh that fails is reported, not cleaned up.** Upstream deletes the stored
     * credential and carries on to the environment, which means one blocked network call
     * throws away a login that was perfectly good — and the person finds out by being asked to
     * sign in again for no reason. Here it throws, naming the provider.
     */
    public function apiKey(string $provider): ?string
    {
        if (isset($this->runtime[$provider])) {
            return $this->runtime[$provider];
        }

        if ($this->kind($provider) === 'api_key') {
            $key = $this->data[$provider]['key'] ?? null;

            if (is_string($key) && $key !== '') {
                return $key;
            }
        }

        $named = Provider::tryFrom($provider);
        $credentials = $named === null ? null : $this->credentials($named);

        if ($named !== null && $credentials !== null) {
            return $named->apiKey($this->fresh($named, $credentials));
        }

        // A provider nothing here has heard of — declared in `models.json` — after the
        // stored answers and before the environment's table, because `Stream::envApiKey()`
        // knows nothing about it and would answer null for every one of them.
        if (isset($this->customKeys[$provider])) {
            return CustomModels::resolve($this->customKeys[$provider]);
        }

        return Stream::envApiKey($provider);
    }

    /**
     * What a `models.json` provider said its key is, by provider name.
     *
     * Upstream's `setFallbackResolver`, narrowed to the one thing it was used for. A closure
     * would be upstream's shape; a map is what the only caller has, and the indirection bought
     * nothing but a second place to look when a key does not resolve.
     *
     * The value is a variable name before it is a key — `CustomModels::resolve()` is what
     * decides, and it looks at the environment first so the file can stay free of secrets.
     *
     * @param array<string, string> $keys
     */
    public function setCustomProviderKeys(array $keys): void
    {
        $this->customKeys = [...$this->customKeys, ...$keys];
    }

    /**
     * Sign in, and remember it. Null means nobody finished — a cancellation, not a failure.
     *
     * The three closures are upstream's `{onAuth, onPrompt, onProgress}`, which is the shape
     * both flows need between them rather than the shape either one needs alone: Anthropic
     * shows a URL and then asks for a paste, Copilot asks for a domain and then shows a URL
     * with a code to type at it. Closures rather than an interface because that is what
     * upstream passes and because the only two callers — a terminal and a test — have nothing
     * else in common.
     *
     * @param Closure(string, ?string): void $onAuth where to go, and what to type when there
     * @param Closure(string, string, bool): ?string $onPrompt message, placeholder, may be
     *        empty; null when the person escaped
     * @param Closure(string): void|null $onProgress a line for a step that takes a moment
     */
    public function login(
        Provider $provider,
        Closure $onAuth,
        Closure $onPrompt,
        ?Closure $onProgress = null,
        ?AbortSignal $signal = null,
    ): ?Credentials {
        if (!$provider->available()) {
            throw new OauthError("Signing in with {$provider->label()} is not ported yet.");
        }

        $credentials = match ($provider) {
            Provider::Anthropic => $this->anthropic($onAuth, $onPrompt),
            Provider::GithubCopilot => $this->copilot($onAuth, $onPrompt, $onProgress, $signal),
            // Its flow is here, and `available()` is what decides whether it is offered — so
            // this arm is reachable the moment that flips, and reaching it now means somebody
            // called `login()` past the check.
            Provider::GoogleGeminiCli => $this->geminiCli($onAuth, $onProgress, $signal),
            // `available()` already refused this one, so reaching here is a bug in that list
            // rather than something a person did.
            Provider::GoogleAntigravity
                => throw new OauthError("{$provider->label()} has no flow here."),
        };

        if ($credentials === null) {
            return null;
        }

        $this->setCredentials($provider, $credentials);

        return $credentials;
    }

    /**
     * @param Closure(string, ?string): void $onAuth
     * @param Closure(string, string, bool): ?string $onPrompt
     */
    private function anthropic(Closure $onAuth, Closure $onPrompt): ?Credentials
    {
        $pkce = Pkce::create();
        $onAuth(Anthropic::authorizeUrl($pkce), null);

        $pasted = $onPrompt('Paste the authorization code', 'code#state', false);

        if ($pasted === null || trim($pasted) === '') {
            return null;
        }

        return (new Anthropic())->exchange($pasted, $pkce->verifier);
    }

    /**
     * @param Closure(string, ?string): void $onAuth
     * @param Closure(string, string, bool): ?string $onPrompt
     * @param Closure(string): void|null $onProgress
     */
    private function copilot(
        Closure $onAuth,
        Closure $onPrompt,
        ?Closure $onProgress,
        ?AbortSignal $signal,
    ): ?Credentials {
        $flow = new GithubCopilot();
        $credentials = $flow->login($onPrompt, $onAuth, $onProgress, $signal);

        if ($credentials === null) {
            return null;
        }

        // Claude's and Grok's models are off until the account has accepted them, so a
        // sign-in that skipped this leaves a third of the list failing on its first turn.
        // The ids come from the registry rather than from the flow, because which models
        // exist is the registry's question and the flow should not have a second answer.
        $ids = [];

        foreach (Models::all() as $model) {
            if ($model->provider === Models::COPILOT) {
                $ids[] = $model->id;
            }
        }

        if ($onProgress !== null) {
            $onProgress('Switching on the models this account can use…');
        }

        $flow->enableModels($credentials->access, $ids, $credentials->enterpriseUrl);

        return $credentials;
    }

    /**
     * @param Closure(string, ?string): void $onAuth
     * @param Closure(string): void|null $onProgress
     */
    private function geminiCli(Closure $onAuth, ?Closure $onProgress, ?AbortSignal $signal): ?Credentials
    {
        [$id, $secret] = $this->googleClient();

        return (new GeminiCli($id, $secret))->login($onAuth, $onProgress, $signal);
    }

    /**
     * Google's client id and secret for the Gemini CLI, which this repository does not hold.
     *
     * Upstream embeds them behind `atob()`, and for a Google installed-application client that is
     * defensible — the secret is not confidential by design, it ships in every Gemini CLI install
     * and in a published npm package, and PKCE is what protects the exchange. It is still not
     * something a repository can carry: GitHub's push protection matches them plain **and**
     * base64-decoded, and the scanners that report a credential get it revoked. So they are
     * configuration, in the order everything else in pig is: the environment, then the settings
     * file.
     *
     * @return array{0: string, 1: string}
     */
    public function googleClient(): array
    {
        $id = getenv('GEMINI_CLI_CLIENT_ID');
        $secret = getenv('GEMINI_CLI_CLIENT_SECRET');

        $id = is_string($id) && $id !== '' ? $id : $this->setting('geminiCli.clientId');
        $secret = is_string($secret) && $secret !== '' ? $secret : $this->setting('geminiCli.clientSecret');

        if ($id === null || $secret === null) {
            // Named in full, because somebody who has not got them needs to know both where they
            // go and that they are not something pig can supply.
            throw new OauthError(
                'Signing in to Gemini CLI needs Google\'s own client id and secret, which pig does not ship. '
                . 'Set GEMINI_CLI_CLIENT_ID and GEMINI_CLI_CLIENT_SECRET, or put geminiCli.clientId and '
                . 'geminiCli.clientSecret in ~/.pig/settings.json. They are the ones in the published '
                . 'gemini-cli package.',
            );
        }

        return [$id, $secret];
    }

    private function setting(string $key): ?string
    {
        $value = $this->settings?->get($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** The token, renewed first if it is due. A renewal is written down, or it happens every turn. */
    private function fresh(Provider $provider, Credentials $credentials): Credentials
    {
        if (!$credentials->hasExpired()) {
            return $credentials;
        }

        try {
            [$id, $secret] = $provider === Provider::GoogleGeminiCli ? $this->googleClient() : [null, null];
            $renewed = $provider->refresh($credentials, null, $id, $secret);
        } catch (Throwable $problem) {
            throw new OauthError(
                "Could not renew the {$provider->value} token: {$problem->getMessage()}",
                0,
                $problem,
            );
        }

        $this->setCredentials($provider, $renewed);

        return $renewed;
    }

    /**
     * Write it out, `0600` in a `0700` directory.
     *
     * Unlike `Settings`, a failure here **throws**. A preference that did not stick is a
     * preference somebody sets again; a token that did not stick is a sign-in that said it
     * worked and did not, and the next thing that happens is an authentication error nobody
     * can connect to this.
     */
    private function save(): void
    {
        if ($this->path === null) {
            return;
        }

        $directory = dirname($this->path);

        if (!is_dir($directory) && !mkdir($directory, 0o700, true) && !is_dir($directory)) {
            throw new OauthError("Could not create {$directory} to save credentials in.");
        }

        $json = json_encode($this->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            throw new OauthError('Could not encode the credentials.');
        }

        // Narrowed before there is anything in it to read. Writing first and fixing the mode
        // afterwards — which is upstream's order — leaves the file world-readable for as long
        // as the two calls take, and that is long enough.
        if (!is_file($this->path) && (!touch($this->path) || !chmod($this->path, 0o600))) {
            throw new OauthError("Could not create {$this->path} to save credentials in.");
        }

        if (file_put_contents($this->path, $json . "\n") === false) {
            throw new OauthError("Could not write {$this->path}.");
        }
    }

    /**
     * @param array<string, mixed> $entry
     */
    private static function text(array $entry, string $key): ?string
    {
        $value = $entry[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
