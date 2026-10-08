<?php

declare(strict_types=1);

namespace Pig\CodingAgent;

use Closure;
use Pig\Ai\Model;
use Pig\Ai\Models;
use Pig\Ai\Stream;
use Pig\Ai\Extension\OauthFlow;
use Pig\Ai\Extension\ProviderRegistry;
use Pig\Ai\Utils\Oauth\Anthropic;
use Pig\Ai\Utils\Oauth\Credentials;
use Pig\Ai\Utils\Oauth\GithubCopilot;
use Pig\Ai\Utils\Oauth\OauthError;
use Pig\Ai\Utils\Oauth\OpenAiCodex;
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

    /**
     * @var array<string, array{0: Closure(): ?Credentials, 1: Closure(Credentials, Credentials): void}>
     *      a second place a provider's credentials live, by provider id — see `useSecondStore()`
     */
    private array $secondStores = [];

    /**
     * @param Settings|null $settings the settings this run started with, for an extension's flow
     *        to read its own keys out of
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
     * A second place one provider's credentials live, beside this file.
     *
     * `auth.json` holds one entry per provider, and an extension may let somebody sign in with
     * several accounts for one — the Antigravity extension's `antigravity-accounts.json` is the
     * store, and this file's entry is a copy of whichever account is active. So the two normally
     * agree, and what this seam is for is the two cases where they do not: a sign-in the copy-out
     * never reached (`$read`, asked only when this file has nothing), and a renewal pig made that
     * the store has to be told about (`$renewed`, with the old credential and the new), or the
     * extension presents a token as valid hours after it was replaced.
     *
     * @param Closure(): ?Credentials $read
     * @param Closure(Credentials, Credentials): void $renewed old, new
     */
    public function useSecondStore(string $provider, Closure $read, Closure $renewed): void
    {
        $this->secondStores[$provider] = [$read, $renewed];
    }

    /** The settings this run started with, for an extension's flow to read its own keys from. */
    public function settings(): ?Settings
    {
        return $this->settings;
    }

    /**
     * The stored tokens for a provider signed in with OAuth, or null.
     *
     * By name as well as by the built-in enum, because a provider an extension registered is a
     * name and not a case. Only when this file has nothing is a second store asked — a
     * credential that *is* in `auth.json` is the one both tools are using.
     */
    public function credentials(Provider|string $provider): ?Credentials
    {
        $id = $provider instanceof Provider ? $provider->value : $provider;
        $entry = $this->data[$id] ?? null;

        if ($entry === null || ($entry['type'] ?? null) !== 'oauth') {
            return isset($this->secondStores[$id]) ? ($this->secondStores[$id][0])() : null;
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
            self::text($entry, 'accountId'),
        );
    }

    public function setApiKey(string $provider, string $key): void
    {
        $this->data[$provider] = ['type' => 'api_key', 'key' => $key];
        $this->save();
    }

    public function setCredentials(Provider|string $provider, Credentials $credentials): void
    {
        $this->data[$provider instanceof Provider ? $provider->value : $provider] = array_filter(
            [
                'type' => 'oauth',
                'refresh' => $credentials->refresh,
                'access' => $credentials->access,
                'expires' => $credentials->expires,
                'enterpriseUrl' => $credentials->enterpriseUrl,
                'projectId' => $credentials->projectId,
                'email' => $credentials->email,
                'accountId' => $credentials->accountId,
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

        // An extension's sign-in first, then the built-in enum: the registry is what knows how
        // to turn a stored credential into a key for a provider that did not ship with pig.
        $flow = ProviderRegistry::oauthFor($provider);
        $named = $flow ?? Provider::tryFrom($provider);
        $credentials = $named === null ? null : $this->credentials($provider);

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
     * @param Closure(string, string, bool, ?AbortSignal=): ?string $onPrompt message, placeholder,
     *        may be empty, and — from Anthropic's browser flow only — a signal that closes the
     *        box from outside when the browser answers first; null when the person escaped. The
     *        fourth argument is optional at the callee because Copilot's flow passes three.
     * @param Closure(string): void|null $onProgress a line for a step that takes a moment
     * @param Closure(string, list<array{0: string, 1: string}>): ?string|null $onSelect a
     *        choice between named options — upstream's `prompt({type: "select"})`, which
     *        Anthropic's flow asks first (browser, or copy the code) and OpenAI's Codex flow too
     *        (browser, or device code). Null means there is no
     *        screen to ask on and the flow takes its default.
     */
    public function login(
        Provider|OauthFlow $provider,
        Closure $onAuth,
        Closure $onPrompt,
        ?Closure $onProgress = null,
        ?AbortSignal $signal = null,
        ?Closure $onSelect = null,
    ): ?Credentials {
        if ($provider instanceof OauthFlow) {
            $credentials = $provider->login($onAuth, $onPrompt, $onProgress, $signal, $onSelect);
            $id = $provider->id();
        } else {
            if (!$provider->available()) {
                throw new OauthError("Signing in with {$provider->label()} is not ported yet.");
            }

            $credentials = match ($provider) {
                Provider::Anthropic => $this->anthropic($onAuth, $onPrompt, $onProgress, $signal, $onSelect),
                Provider::GithubCopilot => $this->copilot($onAuth, $onPrompt, $onProgress, $signal),
                Provider::OpenAiCodex => (new OpenAiCodex())->login($onAuth, $onPrompt, $signal, $onSelect),
            };
            $id = $provider->value;
        }

        if ($credentials === null) {
            return null;
        }

        $this->setCredentials($id, $credentials);

        return $credentials;
    }

    /**
     * Every sign-in on offer: the built-in three, then whatever the loaded extensions brought.
     *
     * @return list<Provider|OauthFlow>
     */
    public static function signIns(): array
    {
        return [...Provider::cases(), ...ProviderRegistry::oauthFlows()];
    }

    /** One sign-in by its id — `anthropic`, or an extension's — or null. */
    public static function signIn(string $id): Provider|OauthFlow|null
    {
        return ProviderRegistry::oauthFor($id) ?? Provider::tryFrom($id);
    }

    /**
     * Which way in, then that way. pi 1.0's `anthropicOAuth.login()`: a browser that comes back
     * to a loopback server is the default, and copying the code off Anthropic's page is for a
     * headless machine. Escaping the choice is a cancellation.
     *
     * @param Closure(string, ?string): void $onAuth
     * @param Closure(string, string, bool, ?AbortSignal): ?string $onPrompt
     * @param Closure(string): void|null $onProgress
     * @param Closure(string, list<array{0: string, 1: string}>): ?string|null $onSelect
     */
    private function anthropic(
        Closure $onAuth,
        Closure $onPrompt,
        ?Closure $onProgress,
        ?AbortSignal $signal,
        ?Closure $onSelect,
    ): ?Credentials {
        $method = Anthropic::METHOD_BROWSER;

        if ($onSelect !== null) {
            $method = $onSelect('Select Anthropic login method', [
                [Anthropic::METHOD_BROWSER, 'Browser login (default)'],
                [Anthropic::METHOD_COPY_CODE, 'Copy code login (headless)'],
            ]);

            if ($method === null) {
                return null;
            }
        }

        $flow = new Anthropic();

        return match ($method) {
            Anthropic::METHOD_BROWSER => $flow->loginWithBrowser($onAuth, $onPrompt, $onProgress, $signal),
            Anthropic::METHOD_COPY_CODE => $flow->loginWithCopyCode($onAuth, $onPrompt, $onProgress),
            default => throw new OauthError("Unknown Anthropic login method: {$method}"),
        };
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

    private function setting(string $key): ?string
    {
        $value = $this->settings?->get($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * The stored tokens, renewed first if they are due — `credentials()` with the renewal
     * `apiKey()` does on the way past.
     *
     * For a caller that needs the *credential* rather than the api-key string: the Antigravity
     * extension's quota and model calls want the bare access token and the project id apart,
     * where `apiKey()` joins them. Reading `credentials()->access` for that is what it did, and
     * an hour after signing in every one of its commands answered 401 — the stored half had
     * expired and only `apiKey()` knew to renew. One door to a fresh token, not two.
     */
    public function freshCredentials(Provider|string $provider): ?Credentials
    {
        $id = $provider instanceof Provider ? $provider->value : $provider;
        $flow = ProviderRegistry::oauthFor($id) ?? Provider::tryFrom($id);
        $credentials = $flow === null ? null : $this->credentials($id);

        return $credentials === null || $flow === null ? null : $this->fresh($flow, $credentials);
    }

    /** The token, renewed first if it is due. A renewal is written down, or it happens every turn. */
    private function fresh(Provider|OauthFlow $provider, Credentials $credentials): Credentials
    {
        if (!$credentials->hasExpired()) {
            return $credentials;
        }

        $id = $provider instanceof Provider ? $provider->value : $provider->id();

        try {
            $renewed = $provider->refresh($credentials);
        } catch (Throwable $problem) {
            if (\Fiber::getCurrent() === null && str_contains($problem->getMessage(), 'inside a coroutine')) {
                // Outside a coroutine (e.g. peeking during startup or session restore before the
                // loop starts): do not crash on the async refresh; return the existing credentials
                // and let the actual turn refresh properly inside the event loop.
                return $credentials;
            }

            throw new OauthError(
                "Could not renew the {$id} token: {$problem->getMessage()}",
                0,
                $problem,
            );
        }

        $this->setCredentials($id, $renewed);

        // And into the second store, if the provider keeps one — see `useSecondStore()`.
        if (isset($this->secondStores[$id])) {
            ($this->secondStores[$id][1])($credentials, $renewed);
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
