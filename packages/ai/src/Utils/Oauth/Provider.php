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
 * **The built-in sign-ins**: Anthropic and GitHub Copilot, matching pi 1.0's, and OpenAI's ChatGPT
 * sign-in for the `openai-codex` provider (`OpenAiCodex`), upstream's `openaiCodexOAuth`. The two Google
 * ones that used to be cases here went the way upstream's did in 0.71 — the Antigravity flow lives
 * in `extensions/pig-antigravity` now and registers itself as an `Extension\OauthFlow`, which is
 * what `CodingAgent\Auth` asks before this enum. `available()` is still a table rather than
 * `return true`: the credentials file is keyed by these strings and is shared with pi, so a name
 * pig could not sign in with would still be a name pig has to *read* without choking, and the row
 * is where a half-ported provider says so.
 */
enum Provider: string
{
    case Anthropic = 'anthropic';

    case GithubCopilot = 'github-copilot';

    /** ChatGPT Plus/Pro for the `openai-codex` provider — upstream's `openaiCodexOAuth`. */
    case OpenAiCodex = 'openai-codex';

    /** Upstream's wording, because it is what someone picking from a list has to recognise. */
    public function label(): string
    {
        return match ($this) {
            self::Anthropic => 'Anthropic (Claude Pro/Max)',
            self::GithubCopilot => 'GitHub Copilot',
            self::OpenAiCodex => 'OpenAI (ChatGPT Plus/Pro)',
        };
    }

    /**
     * Whether pig can actually sign in with this one.
     *
     * Offering one pig cannot finish would be the same mistake as offering a model whose
     * protocol is not ported — a list entry that fails after the person has committed to it,
     * rather than one that says so up front.
     *
     * **A table, not a computed answer**, which is upstream's shape: `getOAuthProviders()`
     * carries `available` as a literal field on each of its entries and this is that list,
     * beside `label()` and matching it line for line. `return true` would read as a condition
     * that cannot be false, which is a claim the code would be making and not keeping; lines
     * that each say `true` are a table whose rows currently agree, and a provider ported halfway
     * has somewhere to say so without anybody inventing a condition for it.
     *
     * No `default`, like `refresh()` and `apiKey()`: an enum is closed, so a new case is a
     * `match` that stops rather than a silent `true`.
     */
    /**
     * Whether this sign-in is backed by a subscription, or is an account somebody has.
     *
     * Upstream's `isSubscription` on each `OAuthAuth`: Anthropic's and Copilot's say so, and the
     * label in `/login` reads "subscription" for them and "account" for the rest. A table, like
     * `available()`, so a new case stops the `match` instead of defaulting either way.
     */
    public function isSubscription(): bool
    {
        return match ($this) {
            self::Anthropic => true,
            self::GithubCopilot => true,
            self::OpenAiCodex => true,
        };
    }

    public function available(): bool
    {
        return match ($this) {
            self::Anthropic => true,
            self::GithubCopilot => true,
            self::OpenAiCodex => true,
        };
    }

    /**
     * A new access token, or a refusal naming what is missing.
     *
     * @param string|null $clientId kept for the signature `Auth::fresh()` calls with; neither
     *        built-in flow needs one. A provider that does (Google's grant carries client id and
     *        secret) is an extension's `OauthFlow` now and renews itself.
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
            self::OpenAiCodex => (new OpenAiCodex($http ?? new HttpClient()))->refresh($credentials->refresh),
        };
    }

    /**
     * What goes on a request as the key: the access token itself, for all three — upstream's
     * `toAuth()` is `{apiKey: credential.access}` for each. A flow that
     * needs more on the key — Antigravity's carries a project id — is an extension's `OauthFlow`
     * and answers for itself.
     */
    public function apiKey(Credentials $credentials): string
    {
        return match ($this) {
            // Both are the short-lived half. Anthropic's is recognised by its `sk-ant-oat`
            // prefix and Copilot's by the provider name, and both go out as bearer tokens.
            self::Anthropic, self::GithubCopilot, self::OpenAiCodex => $credentials->access,
        };
    }
}
