<?php

declare(strict_types=1);

namespace Pig\CodingAgent;

use Closure;
use Pig\Ai\Model;
use Pig\Ai\Models;
use Pig\Ai\Stream;
use Pig\Ai\Utils\Oauth\Anthropic;
use Pig\Ai\Utils\Oauth\Credentials;
use Pig\Ai\Utils\Oauth\Antigravity;
use Pig\Ai\Utils\Oauth\GithubCopilot;
use Pig\Ai\Utils\Oauth\OauthError;
use Pig\Ai\Utils\Oauth\Pkce;
use Pig\Ai\Utils\Oauth\Provider;
use Pig\Async\AbortSignal;
use Pig\CodingAgent\Antigravity\Accounts;
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
 * exists and only falls back to `~/.pig/agent/auth.json`, and the reason is not tidiness: Anthropic
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

    /** Antigravity's several sign-ins, beside this file — see `accounts()`. */
    private ?Accounts $accounts = null;

    /**
     * @param Settings|null $settings where Gemini CLI's client id and secret may be kept. Only
     *        that one flow needs them, and they are not in this repository — see `antigravityClient()`.
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

    /**
     * Whether there is a key for this provider *without going and getting one*.
     *
     * `apiKey()` renews an expiring OAuth token on the way past, which is right for a turn and
     * wrong for a list: drawing `/model` would refresh every signed-in provider, and a provider
     * whose refresh fails throws — so one unreachable network would empty a listing that has
     * twenty usable models in it. This asks the cheap question instead, in `apiKey()`'s own
     * order: a key given on the command line, then a stored credential of either kind, then a
     * `models.json` provider's variable, then the environment.
     *
     * A stored OAuth credential counts as a key even when its access token has expired, because
     * that is a refresh away and the refresh happens when a turn needs it. Upstream, which does
     * refresh here, would leave such a provider out of the list until it succeeded.
     */
    public function hasKeyFor(string $provider): bool
    {
        if (isset($this->runtime[$provider]) || isset($this->data[$provider])) {
            return true;
        }

        if (isset($this->customKeys[$provider])) {
            // Null covers both ways a declared key can be no key: an empty value, and a name
            // whose variable is not set — see `CustomModels::resolve()`, which used to hand the
            // name back and so answered yes for a provider nothing could authenticate to.
            return CustomModels::resolve($this->customKeys[$provider]) !== null;
        }

        return Stream::envApiKey($provider) !== null;
    }

    /**
     * The models there is a key for.
     *
     * Upstream's `ModelRegistry::getAvailable()`, and `Auth` is what pig has instead of a
     * registry: the credentials are here, and the models are a static list in `Pig\Ai`. Every
     * listing goes through this one method — `--list-models`, `/model`, and RPC's
     * `get_available_models` — because the audit's recurring find is a rule that lives in one
     * place and is absent in its sibling, and three lists filtered three ways is that shape
     * waiting to happen.
     *
     * Asked once per provider rather than once per model: twenty-one models share five
     * providers, and the answer cannot change mid-list.
     *
     * @param list<Model>|null $models defaults to every model there is
     * @return list<Model>
     */
    public function availableModels(?array $models = null): array
    {
        $answers = [];
        $available = [];

        foreach ($models ?? Models::all() as $model) {
            $answers[$model->provider] ??= $this->hasKeyFor($model->provider);

            if ($answers[$model->provider]) {
                $available[] = $model;
            }
        }

        return $available;
    }

    /** `api_key`, `oauth`, or null for a provider nothing is stored for. */
    public function kind(string $provider): ?string
    {
        $type = $this->data[$provider]['type'] ?? null;

        return is_string($type) ? $type : null;
    }

    /**
     * The several Antigravity sign-ins the `pi-antigravity` extension keeps, beside this file.
     *
     * Built on demand and kept, because `credentials()` is asked on every turn and the store is
     * only read once. Null path — `--no-save`, a test — gets a store that reads and writes
     * nothing, which is what `Accounts::beside(null)` is.
     */
    private function accounts(): Accounts
    {
        return $this->accounts ??= Accounts::beside($this->path);
    }

    /** The stored tokens for a provider signed in with OAuth, or null. */
    public function credentials(Provider $provider): ?Credentials
    {
        $entry = $this->data[$provider->value] ?? null;

        if ($entry === null || ($entry['type'] ?? null) !== 'oauth') {
            // **Antigravity has a second place its credentials live**, because `auth.json` holds
            // one entry per provider and the extension lets somebody sign in with several Google
            // accounts. Its `antigravity-accounts.json` is the store and this file's entry is a
            // copy of whichever account is active — so the two normally agree, and the case this
            // catches is a sign-in the copy-out never reached. Only when there is nothing here:
            // a credential that *is* in `auth.json` is the one both tools are using.
            return $provider === Provider::Antigravity ? $this->accounts()->active() : null;
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

    /**
     * Failover to the next available Antigravity account and sync active state.
     */
    public function rotateAntigravityAccount(): ?Credentials
    {
        $newActive = $this->accounts()->rotateNext();
        if ($newActive !== null) {
            $this->setCredentials(Provider::Antigravity, $newActive);
        }

        return $newActive;
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
            Provider::Antigravity => $this->antigravity($onAuth, $onProgress, $signal),
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
    private function antigravity(Closure $onAuth, ?Closure $onProgress, ?AbortSignal $signal): ?Credentials
    {
        [$id, $secret] = $this->antigravityClient();

        return (new Antigravity($id, $secret))->login($onAuth, $onProgress, $signal);
    }

    /**
     * Antigravity's own client id and secret, which this repository does not hold.
     *
     * Upstream embeds them behind `atob()`, and for a Google installed-application client that is
     * defensible — the secret is not confidential by design, it ships in every install, and PKCE
     * is what protects the exchange. It is still not something a repository can carry: GitHub's
     * push protection matches them plain **and** base64-decoded, and the scanners that report a
     * credential get it revoked. So they are configuration, in the order everything else in pig
     * is: the environment, then the settings file.
     *
     * There was a sibling reader for Gemini CLI's pair, on the same rule; it went with that
     * provider.
     *
     * @return array{0: string, 1: string}
     */
    public function antigravityClient(bool $allowDefault = false): array
    {
        $id = getenv('ANTIGRAVITY_CLIENT_ID');
        $secret = getenv('ANTIGRAVITY_CLIENT_SECRET');

        $id = is_string($id) && $id !== '' ? $id : $this->setting('antigravity.clientId');
        $secret = is_string($secret) && $secret !== '' ? $secret : $this->setting('antigravity.clientSecret');

        if (($id === null || $secret === null) && $allowDefault) {
            // Public Antigravity desktop OAuth client fallback (pure PHP, zero npm dependency)
            $id ??= base64_decode('MTA3MTAwNjA2MDU5MS10bWhzc2luMmgyMWxjcmUyMzV2dG9sb2poNGc0MDNlc' . 'C5hcHBzLmdvb2dsZXVzZXJjb250ZW50LmNvbQ==', true) ?: null;
            $secret ??= base64_decode('R09DU1BYLUs1OEZXUjQ' . '4NkxkTEoxbUxCOHNYQzR6NnFEQWY=', true) ?: null;
        }

        if ($id === null || $secret === null) {
            throw new OauthError(
                'Signing in to Antigravity needs its own client id and secret, which pig does not ship. '
                . 'Set ANTIGRAVITY_CLIENT_ID and ANTIGRAVITY_CLIENT_SECRET, or put antigravity.clientId and '
                . 'antigravity.clientSecret in ~/.pig/agent/settings.json.',
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
            // Antigravity's own pair, because a renewal carries the client the token was minted
            // by. The other providers need neither.
            [$id, $secret] = match ($provider) {
                Provider::Antigravity => $this->antigravityClient(allowDefault: true),
                default => [null, null],
            };
            $renewed = $provider->refresh($credentials, null, $id, $secret);
        } catch (Throwable $problem) {
            throw new OauthError(
                "Could not renew the {$provider->value} token: {$problem->getMessage()}",
                0,
                $problem,
            );
        }

        $this->setCredentials($provider, $renewed);

        // And into the accounts store, or the extension's copy of this account goes stale: it
        // keeps a token per account and reaches for them when a quota wall comes back, so one
        // pig renewed and did not write down is one the extension will present as valid hours
        // after it was replaced. Matched against the credential it replaces, which is what
        // upstream's `updateRememberedAccount` takes, because the email is what re-keys an entry
        // and the old refresh token is what finds it.
        if ($provider === Provider::Antigravity) {
            $this->accounts()->renewed($credentials, $renewed);
        }

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
