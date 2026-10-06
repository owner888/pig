<?php

declare(strict_types=1);

namespace Pig\Ai\Extension;

use Pig\Ai\Model;
use Pig\Ai\Models;

/**
 * The providers extensions have registered, by id.
 *
 * Static for `Models::register()`'s reason: `--model`, `/model`, restoring a session, the sign-in
 * list and `Stream::start()` all ask by name, and a provider only some of them could see is a
 * provider that works until you save the conversation. `forget()` is for tests, which must not
 * leak providers into each other, and for `/reload`, which loads the extensions again.
 *
 * What registering does: the models go into `Models` through `register()`, so every lookup
 * already written finds them; the protocol is found by `Stream::start()` through `apiFor()`; the
 * sign-in by `Auth` through `oauthFor()`; the key's variable names by `Stream::envApiKey()`.
 * Unregistering takes all four back.
 */
final class ProviderRegistry
{
    /** @var array<string, Provider> */
    private static array $providers = [];

    public static function register(Provider $provider): void
    {
        if (isset(self::$providers[$provider->id])) {
            self::unregister($provider->id);
        }

        self::$providers[$provider->id] = $provider;
        Models::register($provider->models);
    }

    public static function unregister(string $id): void
    {
        $provider = self::$providers[$id] ?? null;

        if ($provider === null) {
            return;
        }

        unset(self::$providers[$id]);
        Models::forgetProvider($id);
    }

    /** Every provider registered, in registration order. @return list<Provider> */
    public static function all(): array
    {
        return array_values(self::$providers);
    }

    public static function get(string $id): ?Provider
    {
        return self::$providers[$id] ?? null;
    }

    public static function apiFor(Model $model): ?StreamApi
    {
        return self::$providers[$model->provider]->api ?? null;
    }

    public static function oauthFor(string $id): ?OauthFlow
    {
        return self::$providers[$id]->oauth ?? null;
    }

    /** @return list<OauthFlow> the sign-ins registered, for a list to offer */
    public static function oauthFlows(): array
    {
        $out = [];

        foreach (self::$providers as $provider) {
            if ($provider->oauth !== null) {
                $out[] = $provider->oauth;
            }
        }

        return $out;
    }

    /** @return list<string> */
    public static function envKeysFor(string $id): array
    {
        return self::$providers[$id]->envKeys ?? [];
    }

    public static function isResold(string $id): bool
    {
        return self::$providers[$id]->resold ?? false;
    }

    /** Forget everything. For tests and `/reload`. */
    public static function forget(): void
    {
        foreach (array_keys(self::$providers) as $id) {
            Models::forgetProvider($id);
        }

        self::$providers = [];
    }
}
