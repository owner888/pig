<?php

declare(strict_types=1);

namespace Pig\Ai\Extension;

use Closure;
use Pig\Async\AbortSignal;

/**
 * Upstream's `ApiKeyAuth`: "Api-key auth: stored key/provider env plus ambient sources (env vars,
 * AWS profiles, ADC files)." — the second half of a provider's `auth`, beside `OauthFlow`.
 *
 * A provider an extension registers with one of these is signed in to with `/login`, which keeps
 * what `login()` answers as an `api_key` entry of `auth.json`; `CodingAgent\Auth` then asks
 * `check()` whether the provider is configured and `resolve()` for the key a request carries,
 * instead of its own table. Upstream's `AuthContext` (`ctx.env(name)`) is the process environment
 * here, which is what `Stream::envApiKey()` reads too.
 */
interface ApiKeyAuth
{
    /** Upstream's `name`: "Display name, e.g. "Anthropic API key"." */
    public function name(): string;

    /**
     * Upstream's `login(interaction)`: "Interactive setup (prompt for key/provider env)." Null means
     * nobody finished — a cancellation, as `OauthFlow::login()` has it.
     *
     * @param Closure(string, string, bool, ?AbortSignal=): ?string $onPrompt a box: message,
     *        placeholder, may-be-empty — `OauthFlow::login()`'s, null when the person escaped
     */
    public function login(Closure $onPrompt, ?AbortSignal $signal = null): ?ApiKeyCredential;

    /**
     * Upstream's `check()`: "Optional side-effect-free availability check." The source when the
     * provider is configured (`AuthCheck.source`; the type is always `api_key`), null when it is not.
     */
    public function check(?ApiKeyCredential $credential): ?string;

    /**
     * Upstream's `resolve()`: "Resolve auth from the stored credential and/or ambient sources,
     * merging per field. undefined = not configured."
     */
    public function resolve(?ApiKeyCredential $credential): ?AuthResult;
}
