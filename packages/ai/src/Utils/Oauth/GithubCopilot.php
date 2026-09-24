<?php

declare(strict_types=1);

namespace Pig\Ai\Utils\Oauth;

use Closure;
use Pig\Ai\Http\HttpClient;
use Pig\Ai\Http\Request;
use Pig\Ai\Timestamp;
use Pig\Async\AbortSignal;
use Pig\Async\Deferred;
use Pig\Async\Loop;
use Throwable;

/**
 * Signing in to GitHub Copilot, by typing a code into a browser.
 *
 * Upstream's `utils/oauth/github-copilot.ts`. A **device flow**: pig asks GitHub for a pair of
 * codes, shows one of them, and then asks over and over whether the person has typed it in yet.
 * No redirect to catch, so no loopback server and no port to hope is free — which is why this is
 * the second flow ported and the two Google ones are not.
 *
 * It ends with a swap that is easy to miss: what the device flow produces is a **GitHub** token,
 * and that is not what Copilot's API accepts. `refresh()` trades it at
 * `copilot_internal/v2/token` for a short-lived Copilot token, and that is the one that goes on
 * a request. So the GitHub token is stored as the `refresh` half and the Copilot token as the
 * `access` half, which is exactly what those two fields mean everywhere else here.
 *
 * Two things about this flow have no counterpart in the Anthropic one:
 *
 * - **Where Copilot answers is in the token.** Its claims carry
 *   `proxy-ep=proxy.individual.githubcopilot.com`, which becomes `api.individual.…`; a business
 *   account says something else and an enterprise install answers at `copilot-api.<domain>`.
 *   `baseUrl()` reads it, and `Providers\OpenAiCompletions` and `OpenAiResponses` ask — so the
 *   `api.individual…` in the registry is a default and never the answer once there is a token.
 * - **Some models have to be switched on.** Claude's and Grok's are off until the account has
 *   accepted them, so `enableModels()` POSTs a policy for each one after signing in. Upstream
 *   does all nineteen at once with `Promise.all`; here they go one after another, because
 *   nineteen fibers against one host is not politeness and the whole thing happens once.
 *
 * One addition to upstream, and it is the reason this waited for a decision: **the polling can
 * be stopped.** Upstream loops for fifteen minutes with nothing that can interrupt it, which in
 * pig is a fiber parked where nobody can reach it — the failure the `tool_call` dialogs already
 * had a rule about. An `AbortSignal` ends it, and escape is what raises one.
 */
final class GithubCopilot
{
    public const string DEFAULT_DOMAIN = 'github.com';

    /**
     * GitHub's own client id for Copilot Chat.
     *
     * Written out rather than hidden behind `atob()` of a base64 string as upstream has it: it
     * is in a published npm package and in every request this makes, so it is not a secret, and
     * a reader who cannot see what it is has to go and decode it to find out.
     */
    public const string CLIENT_ID = 'Iv1.b507a08c87ecfe98';

    /** Where an ordinary account's Copilot answers, when no token has said otherwise. */
    public const string DEFAULT_BASE_URL = 'https://api.individual.githubcopilot.com';

    /**
     * Who Copilot is told it is talking to. The same four `Ai\Models` puts on every model.
     *
     * Not decoration: the endpoint is VS Code's and answers a request that does not claim to be
     * VS Code with a 4xx.
     */
    private const array HEADERS = [
        'User-Agent' => 'GitHubCopilotChat/0.35.0',
        'Editor-Version' => 'vscode/1.107.0',
        'Editor-Plugin-Version' => 'copilot-chat/0.35.0',
        'Copilot-Integration-Id' => 'vscode-chat',
    ];

    /** Five minutes, which is upstream's margin: a token is treated as dead before it is. */
    private const int MARGIN_MS = 5 * 60 * 1000; // 5 minutes

    /** However eager the server says it is, it is asked no more than once a second. */
    private const float MIN_INTERVAL = 1.0;

    /** What `slow_down` costs, added to the interval each time it is said. */
    private const float SLOW_DOWN = 5.0;

    /**
     * @param string|null $origin every endpoint under this instead of under the domain, so a
     *        test can point the whole flow at a loopback server. The same seam the Anthropic
     *        flow has and for the same reason: the alternative is an authentication flow with
     *        no coverage at all.
     */
    public function __construct(
        private readonly HttpClient $http = new HttpClient(),
        private readonly ?string $origin = null,
    ) {
    }

    /**
     * A host name out of whatever somebody typed, or null if it is not one.
     *
     * `company.ghe.com`, `https://company.ghe.com`, `https://company.ghe.com/some/path` all
     * come back as the host. Upstream leans on `new URL()`; `parse_url()` answers the same
     * question, and the `//` is added when there is no scheme because without it the whole
     * string parses as a path.
     */
    public static function normalizeDomain(string $input): ?string
    {
        $trimmed = trim($input);

        if ($trimmed === '') {
            return null;
        }

        $host = parse_url(str_contains($trimmed, '://') ? $trimmed : '//' . $trimmed, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : null;
    }

    /**
     * Where to send requests for a given token.
     *
     * The token's claims are a `;`-separated list and one of them says which proxy issued it.
     * `proxy.` becomes `api.`, which is upstream's substitution and not a guess — the two are
     * the same host with a different prefix.
     *
     * With no token, or one whose claims say nothing, an enterprise domain decides and an
     * ordinary account gets the default.
     */
    public static function baseUrl(?string $token = null, ?string $enterpriseDomain = null): string
    {
        if ($token !== null && preg_match('/proxy-ep=([^;]+)/', $token, $match) === 1) {
            return 'https://' . preg_replace('/^proxy\./', 'api.', $match[1]);
        }

        if ($enterpriseDomain !== null && $enterpriseDomain !== '') {
            return "https://copilot-api.{$enterpriseDomain}";
        }

        return self::DEFAULT_BASE_URL;
    }

    /** Ask for the pair of codes. */
    public function start(string $domain = self::DEFAULT_DOMAIN): DeviceCode
    {
        $data = $this->json('POST', $this->url($domain, 'login/device/code'), [
            'client_id' => self::CLIENT_ID,
            'scope' => 'read:user',
        ]);

        $code = $data['device_code'] ?? null;
        $user = $data['user_code'] ?? null;
        $uri = $data['verification_uri'] ?? null;
        $interval = $data['interval'] ?? null;
        $expires = $data['expires_in'] ?? null;

        // Every field named, because a device flow missing one of them is a sign-in that
        // cannot finish and the mistake should be reported where it happened.
        if (!is_string($code) || !is_string($user) || !is_string($uri) || !is_int($interval) || !is_int($expires)) {
            throw new OauthError('GitHub answered the device-code request without the codes in it.');
        }

        return new DeviceCode($code, $user, $uri, $interval, $expires);
    }

    /**
     * Ask until the person has approved it, or until there is no point.
     *
     * Returns the **GitHub** token, which `refresh()` then trades for a Copilot one. Null means
     * the waiting was cancelled — a person changing their mind is an outcome, not a failure,
     * which is why it is not an exception.
     */
    public function poll(string $domain, DeviceCode $device, ?AbortSignal $signal = null): ?string
    {
        $deadline = Timestamp::nowMs() + $device->expiresIn * 1000;
        $interval = max(self::MIN_INTERVAL, (float) $device->interval);

        while (Timestamp::nowMs() < $deadline) {
            if ($signal?->aborted() === true) {
                return null;
            }

            $data = $this->json('POST', $this->url($domain, 'login/oauth/access_token'), [
                'client_id' => self::CLIENT_ID,
                'device_code' => $device->deviceCode,
                'grant_type' => 'urn:ietf:params:oauth:grant-type:device_code',
            ]);

            $token = $data['access_token'] ?? null;

            if (is_string($token) && $token !== '') {
                return $token;
            }

            $error = $data['error'] ?? null;

            if (is_string($error) && $error !== 'authorization_pending' && $error !== 'slow_down') {
                // `access_denied`, `expired_token`, a revoked client: all of them mean asking
                // again says the same thing.
                throw new OauthError("GitHub refused the sign-in: {$error}");
            }

            if ($error === 'slow_down') {
                $interval += self::SLOW_DOWN;
            }

            if (!$this->sleep($interval, $signal)) {
                return null;
            }
        }

        throw new OauthError('The sign-in code expired before it was entered.');
    }

    /**
     * Trade a GitHub token for the Copilot one a request actually carries.
     *
     * Also the renewal: the Copilot token lasts under an hour and this is the only way to get
     * another, which is why the GitHub token is what gets stored as `refresh`.
     */
    public function refresh(string $githubToken, ?string $enterpriseDomain = null): Credentials
    {
        $domain = $enterpriseDomain ?? self::DEFAULT_DOMAIN;

        $data = $this->json('GET', $this->url($domain, 'copilot_internal/v2/token', api: true), null, [
            'authorization' => "Bearer {$githubToken}",
            ...self::HEADERS,
        ]);

        $token = $data['token'] ?? null;
        $expires = $data['expires_at'] ?? null;

        if (!is_string($token) || $token === '' || !is_int($expires)) {
            throw new OauthError('GitHub answered the Copilot token request without a token in it.');
        }

        return new Credentials(
            $githubToken,
            $token,
            // Seconds there, milliseconds here, less the margin.
            $expires * 1000 - self::MARGIN_MS,
            $enterpriseDomain,
        );
    }

    /**
     * Switch on the models that are off until an account has accepted them.
     *
     * Claude's and Grok's are, so a sign-in that skipped this would leave a list of nineteen
     * models where a third of them fail on the first turn. A refusal for one model is reported
     * through `$onProgress` and does not stop the rest: an account without access to Claude is
     * not a broken sign-in, and the model list already says what an account can reach.
     *
     * @param list<string> $modelIds
     * @param Closure(string, bool): void|null $onProgress
     */
    public function enableModels(
        string $copilotToken,
        array $modelIds,
        ?string $enterpriseDomain = null,
        ?Closure $onProgress = null,
    ): void {
        $base = self::baseUrl($copilotToken, $enterpriseDomain);

        foreach ($modelIds as $id) {
            $enabled = true;

            try {
                $this->json('POST', rtrim($base, '/') . "/models/{$id}/policy", ['state' => 'enabled'], [
                    'authorization' => "Bearer {$copilotToken}",
                    ...self::HEADERS,
                    'openai-intent' => 'chat-policy',
                    'x-interaction-type' => 'chat-policy',
                ]);
            } catch (Throwable) {
                // Upstream swallows this too, and here it is the one place a swallow is the
                // right answer: the question asked was "may this account use that model", and
                // "no" is an answer rather than a failure of the sign-in that is now finished.
                $enabled = false;
            }

            if ($onProgress !== null) {
                $onProgress($id, $enabled);
            }
        }
    }

    /**
     * The whole thing: ask which GitHub, show the code, wait, and swap the token.
     *
     * @param Closure(string, string, bool): ?string $onPrompt message, placeholder, may be empty
     * @param Closure(string, ?string): void $onAuth where to go, and what to type when there
     * @param Closure(string): void|null $onProgress
     */
    public function login(
        Closure $onPrompt,
        Closure $onAuth,
        ?Closure $onProgress = null,
        ?AbortSignal $signal = null,
    ): ?Credentials {
        $typed = $onPrompt('GitHub Enterprise URL/domain (blank for github.com)', 'company.ghe.com', true);

        if ($typed === null) {
            return null;
        }

        $enterprise = self::normalizeDomain($typed);

        // Something was typed and it is not a host. Upstream throws here too, and it is the
        // right way round: carrying on against github.com would sign somebody in to the wrong
        // GitHub and look like it worked.
        if (trim($typed) !== '' && $enterprise === null) {
            throw new OauthError("'{$typed}' is not a GitHub Enterprise domain.");
        }

        $domain = $enterprise ?? self::DEFAULT_DOMAIN;
        $device = $this->start($domain);

        $onAuth($device->verificationUri, "Enter code: {$device->userCode}");

        $github = $this->poll($domain, $device, $signal);

        if ($github === null) {
            return null;
        }

        if ($onProgress !== null) {
            $onProgress('Swapping it for a Copilot token…');
        }

        return $this->refresh($github, $enterprise);
    }

    /**
     * One request, and the only place an answer is trusted.
     *
     * @param array<string, mixed>|null $body
     * @param array<string, string> $headers
     * @return array<string, mixed>
     */
    private function json(string $method, string $url, ?array $body = null, array $headers = []): array
    {
        $encoded = $body === null ? null : self::encode($body);

        $response = $this->http->send(new Request($method, $url, [
            'accept' => 'application/json',
            ...($encoded === null ? [] : ['content-type' => 'application/json']),
            'user-agent' => self::HEADERS['User-Agent'],
            ...$headers,
        ], $encoded));

        $text = $response->body->all();

        if (!$response->isSuccessful()) {
            // The body verbatim: an OAuth error is a short JSON object naming what was wrong,
            // and it is the only thing that says which of several identical failures this was.
            throw new OauthError("GitHub answered {$response->status} to {$url}: {$text}");
        }

        $data = json_decode($text, true);

        if (!is_array($data)) {
            throw new OauthError("GitHub answered {$url} with something that is not JSON.");
        }

        return $data;
    }

    /**
     * `https://github.com/login/…`, or `https://api.github.com/copilot_internal/…`.
     *
     * The `api.` prefix is not a scheme difference but a different host, which is why it is a
     * flag here rather than something the caller assembles.
     */
    private function url(string $domain, string $path, bool $api = false): string
    {
        if ($this->origin !== null) {
            return rtrim($this->origin, '/') . '/' . $path;
        }

        return 'https://' . ($api ? 'api.' : '') . $domain . '/' . $path;
    }

    /**
     * Wait, unless something says not to. False means it was cut short.
     *
     * The timer is cancelled on an abort rather than left to fire into nothing: a pending timer
     * keeps `Loop::isIdle()` false, and `bin/pig` would then not exit. The same shape
     * `AgentSession::sleep()` uses for a retry it must be possible to escape.
     */
    private function sleep(float $seconds, ?AbortSignal $signal): bool
    {
        if ($signal?->aborted() === true) {
            return false;
        }

        $done = new Deferred();
        $timer = Loop::get()->delay($seconds, static function () use ($done): void {
            if (!$done->isComplete()) {
                $done->complete(true);
            }
        });

        $listener = $signal?->onAbort(static function () use ($done, $timer): void {
            Loop::get()->cancel($timer);

            if (!$done->isComplete()) {
                $done->complete(false);
            }
        });

        try {
            return $done->future->await() === true;
        } finally {
            if ($signal !== null && $listener !== null) {
                $signal->removeListener($listener);
            }
        }
    }

    /** @param array<string, mixed> $body */
    private static function encode(array $body): string
    {
        try {
            return json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (Throwable $problem) {
            throw new OauthError('Could not encode the request to GitHub.', 0, $problem);
        }
    }
}
