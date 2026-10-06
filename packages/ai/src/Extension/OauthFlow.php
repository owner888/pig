<?php

declare(strict_types=1);

namespace Pig\Ai\Extension;

use Closure;
use Pig\Ai\Utils\Oauth\Credentials;
use Pig\Async\AbortSignal;

/**
 * A sign-in an extension brings with it.
 *
 * Upstream's `OAuthAuth`: `name`, `isSubscription`, `login(interaction)`, `refresh(credential)`,
 * `toAuth(credential)`. The three built-in flows are cases of `Utils\Oauth\Provider`; a provider
 * registered by an extension implements this, and `CodingAgent\Auth` asks the registry before
 * the enum. The credentials file is keyed by `id()`, and it is pi's file, so an id that pi's
 * version of the same extension writes is the id to use here.
 *
 * The four closures are the ones `Auth::login()` takes, in the same order, because they are what
 * every mode already has: a line to show a URL on, a box to ask in, a line for a step that takes a
 * moment, and a list to choose from.
 */
interface OauthFlow
{
    /** The key in `auth.json`, and what `Models` calls the provider. */
    public function id(): string;

    /** What a list shows: "Antigravity (Gemini 3, Claude, GPT-OSS)". */
    public function label(): string;

    /** A subscription, or an account somebody has — the word the list puts after "configured". */
    public function isSubscription(): bool;

    /**
     * Sign in. Null means nobody finished — a cancellation, not a failure.
     *
     * @param Closure(string, ?string): void $onAuth where to go, and what to do there
     * @param Closure(string, string, bool, ?AbortSignal=): ?string $onPrompt a box: message,
     *        placeholder, may-be-empty, and a signal that closes it from outside
     * @param Closure(string): void|null $onProgress
     * @param Closure(string, list<array{0: string, 1: string}>): ?string|null $onSelect a choice
     *        between named options; null when there is no screen to ask on
     */
    public function login(
        Closure $onAuth,
        Closure $onPrompt,
        ?Closure $onProgress = null,
        ?AbortSignal $signal = null,
        ?Closure $onSelect = null,
    ): ?Credentials;

    /** A new access token from the stored credentials; the old refresh token may be rotated. */
    public function refresh(Credentials $credentials): Credentials;

    /** What goes on a request as the key — the access token, or something built around it. */
    public function apiKey(Credentials $credentials): string;
}
