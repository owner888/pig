<?php

declare(strict_types=1);

namespace Pig\Ai\Utils\Oauth;

use Pig\Ai\Http\HttpClient;

/**
 * Which sign-in a set of credentials belongs to.
 *
 * Upstream's `OAuthProvider` union, and the high-level half of its `utils/oauth/index.ts`:
 * `refreshOAuthToken()` and `getOAuthApiKey()` are methods here rather than free functions,
 * because PHP has no module-level functions and both were a `switch` on the provider anyway.
 *
 * **All four are named and only one works.** That is not an oversight left to be tidied: the
 * credentials file is keyed by these strings and is shared with pi, so a name pig cannot sign
 * in with is still a name pig has to be able to *read* without choking — and `available()` is
 * what keeps anything from offering a sign-in that would fail. The other three need a loopback
 * HTTP server and a browser, or a device flow against models pig's registry does not carry
 * yet; each can arrive on its own.
 */
enum Provider: string
{
    case Anthropic = 'anthropic';

    case GithubCopilot = 'github-copilot';

    case GoogleGeminiCli = 'google-gemini-cli';

    case GoogleAntigravity = 'google-antigravity';

    /** Upstream's wording, because it is what someone picking from a list has to recognise. */
    public function label(): string
    {
        return match ($this) {
            self::Anthropic => 'Anthropic (Claude Pro/Max)',
            self::GithubCopilot => 'GitHub Copilot',
            self::GoogleGeminiCli => 'Google Cloud Code Assist (Gemini CLI)',
            self::GoogleAntigravity => 'Antigravity (Gemini 3, Claude, GPT-OSS)',
        };
    }

    /**
     * Whether pig can actually sign in with this one.
     *
     * Upstream answers true for all four. Offering one pig cannot finish would be the same
     * mistake as offering a model whose protocol is not ported — a list entry that fails
     * after the person has committed to it, rather than one that says so up front.
     */
    public function available(): bool
    {
        // All four now. This answered false for `GoogleAntigravity` until its flow and its
        // seven models arrived; the method stays because the reason it exists has not changed —
        // a fifth provider ported halfway needs somewhere to say so.
        return true;
    }

    /**
     * A new access token, or a refusal naming what is missing.
     *
     * @param string|null $clientId the two Google flows only: Google's renewal grant carries the
     *        client id and secret, and this repository holds neither pair — `CodingAgent\Auth`
     *        finds them and passes them in. Anthropic's and Copilot's need nothing here.
     */
    public function refresh(
        Credentials $credentials,
        ?HttpClient $http = null,
        ?string $clientId = null,
        ?string $clientSecret = null,
    ): Credentials {
        if ($credentials->refresh === '') {
            throw new OauthError("The stored {$this->value} credentials have no refresh token — sign in again.");
        }

        // Every case listed rather than a `default`: an enum is closed, so naming them all is
        // what makes a fifth provider a compile-time question instead of a silent fall-through.
        return match ($this) {
            self::Anthropic => (new Anthropic($http ?? new HttpClient()))->refresh($credentials->refresh),
            // The GitHub token is what was stored, and trading it for a Copilot one is both how
            // the sign-in ends and how it is renewed — there is no separate refresh endpoint.
            self::GithubCopilot => (new GithubCopilot($http ?? new HttpClient()))
                ->refresh($credentials->refresh, $credentials->enterpriseUrl),
            // Renewable even though `available()` refuses a fresh sign-in: a credentials file
            // written by pi can hold these, and a token that can be renewed should be.
            self::GoogleGeminiCli => (new GeminiCli(
                $clientId ?? throw new OauthError('Renewing a Gemini CLI token needs Google\'s client id and secret.'),
                $clientSecret ?? throw new OauthError('Renewing a Gemini CLI token needs Google\'s client secret.'),
                $http ?? new HttpClient(),
            ))->refresh(
                $credentials->refresh,
                $credentials->projectId ?? throw new OauthError(
                    'The stored Gemini CLI credentials have no Cloud project id, so there is nothing to spend the token against.',
                ),
            ),
            self::GoogleAntigravity => (new Antigravity(
                $clientId ?? throw new OauthError('Renewing an Antigravity token needs its client id and secret.'),
                $clientSecret ?? throw new OauthError('Renewing an Antigravity token needs its client secret.'),
                $http ?? new HttpClient(),
            ))->refresh(
                $credentials->refresh,
                // Unlike Gemini CLI's, this one always has a project — `Antigravity::project()`
                // falls back to a constant rather than failing — so a stored credential without
                // one was written by something else, and guessing which project it meant is not
                // something to do with somebody's quota.
                $credentials->projectId ?? throw new OauthError(
                    'The stored Antigravity credentials have no Cloud project id, so there is nothing to spend the token against.',
                ),
            ),
        };
    }

    /**
     * What goes on a request as the key.
     *
     * For Anthropic and Copilot that is the access token itself. The two Google flows encode
     * the project id alongside it, which is why this is a method and not a field read.
     */
    public function apiKey(Credentials $credentials): string
    {
        return match ($this) {
            // Both are the short-lived half. Anthropic's is recognised by its `sk-ant-oat`
            // prefix and Copilot's by the provider name, and both go out as bearer tokens.
            self::Anthropic, self::GithubCopilot => $credentials->access,
            // Upstream's shape: Code Assist needs a Cloud project as well as a token, and the
            // two travel as one string because that is all an api key field can carry.
            // `Providers\GoogleGeminiCli` is what parses it back, for both of these — they are
            // the same protocol against two deployments.
            self::GoogleGeminiCli, self::GoogleAntigravity => self::projectKey($credentials),
        };
    }

    private static function projectKey(Credentials $credentials): string
    {
        $project = $credentials->projectId;

        if ($project === null || $project === '') {
            throw new OauthError('The stored Gemini CLI credentials have no Cloud project id.');
        }

        $json = json_encode(['token' => $credentials->access, 'projectId' => $project]);

        if ($json === false) {
            throw new OauthError('Could not encode the Gemini CLI key.');
        }

        return $json;
    }
}
