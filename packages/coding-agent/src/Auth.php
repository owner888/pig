<?php

declare(strict_types=1);

namespace Pig\CodingAgent;

use Closure;
use Pig\Ai\Stream;
use Pig\Ai\Utils\Oauth\Anthropic;
use Pig\Ai\Utils\Oauth\Credentials;
use Pig\Ai\Utils\Oauth\OauthError;
use Pig\Ai\Utils\Oauth\Pkce;
use Pig\Ai\Utils\Oauth\Provider;
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

    /** @var list<string> what could not be read, for the caller to complain about */
    private array $problems = [];

    public function __construct(private readonly ?string $path)
    {
        $this->reload();
    }

    /**
     * pi's file if there is one, pig's otherwise.
     *
     * @param string|null $path a file to use instead, which is how a test gets its own
     */
    public static function discover(?string $path = null): self
    {
        if ($path !== null) {
            return new self($path);
        }

        $theirs = Config::piHome() . '/' . self::FILE;

        return new self(is_file($theirs) ? $theirs : Config::home() . '/' . self::FILE);
    }

    /** Credentials that are never written anywhere, for tests and for `--no-save`. */
    public static function inMemory(): self
    {
        return new self(null);
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

        return Stream::envApiKey($provider);
    }

    /**
     * Sign in, and remember it.
     *
     * The two closures are the UI's half: one is handed the URL to open, the other is asked
     * for what came back and returns null if the person gave up. They are closures rather than
     * an interface because that is what upstream passes and because the only two callers — a
     * terminal and a test — have nothing else in common.
     *
     * @param Closure(string): void $showUrl
     * @param Closure(): ?string $askForPaste
     */
    public function login(Provider $provider, Closure $showUrl, Closure $askForPaste): Credentials
    {
        if (!$provider->available()) {
            throw new OauthError("Signing in with {$provider->label()} is not ported yet.");
        }

        $pkce = Pkce::create();
        $showUrl(Anthropic::authorizeUrl($pkce));

        $pasted = $askForPaste();

        if ($pasted === null || trim($pasted) === '') {
            throw new OauthError('Nothing was pasted, so nobody was signed in.');
        }

        $credentials = (new Anthropic())->exchange($pasted, $pkce->verifier);
        $this->setCredentials($provider, $credentials);

        return $credentials;
    }

    /** The token, renewed first if it is due. A renewal is written down, or it happens every turn. */
    private function fresh(Provider $provider, Credentials $credentials): Credentials
    {
        if (!$credentials->hasExpired()) {
            return $credentials;
        }

        try {
            $renewed = $provider->refresh($credentials);
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
