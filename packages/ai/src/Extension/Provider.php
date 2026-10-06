<?php

declare(strict_types=1);

namespace Pig\Ai\Extension;

use Pig\Ai\Model;

/**
 * A provider an extension registers — upstream's `registerProvider(provider: Provider)`.
 *
 * Everything a built-in provider is spread across `Models`, `Api`, `Stream` and
 * `Utils\Oauth\Provider`, in one object: the models it sells, the protocol they speak (or null
 * for one pig already has — `Model::$api` names it), the sign-in (or null for a key), and which
 * environment variables carry that key. `ProviderRegistry::register()` takes one of these and
 * the four core classes ask the registry when their own tables come up empty.
 *
 * `resold` is `Models::RESOLD`'s dynamic half: a provider that serves other providers' models
 * under their own ids (`claude-sonnet-4-6` through a Google deployment) says so, and a bare id on
 * the command line keeps meaning the direct provider.
 */
final readonly class Provider
{
    /**
     * @param list<Model> $models every model, with `provider` set to `$id` and `api` set to
     *        `Api::Extension` when `$api` is given
     * @param list<string> $envKeys environment variable names that carry this provider's key,
     *        first one set wins — `Stream::envApiKey()`'s table for one more row
     */
    public function __construct(
        public string $id,
        public string $name,
        public array $models,
        public ?StreamApi $api = null,
        public ?OauthFlow $oauth = null,
        public array $envKeys = [],
        public bool $resold = false,
    ) {
        if ($id === '' || $name === '') {
            throw new \InvalidArgumentException('A provider needs an id and a name.');
        }

        foreach ($models as $model) {
            if ($model->provider !== $id) {
                throw new \InvalidArgumentException("Model '{$model->id}' says provider '{$model->provider}', not '{$id}'.");
            }
        }
    }
}
