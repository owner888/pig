<?php

declare(strict_types=1);

namespace Pig\Ai\Extension;

use Pig\Ai\ClassifierModel;
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
     *        `Api::Extension` when `$api` is given. **A model's `compat` is the extension's to
     *        write and is used as written**: upstream's `extensionModelFromDefinition()` spreads the
     *        definition (`{ ...definition, api, provider, baseUrl }`), so its `compat` is whatever
     *        the definition said and nothing is worked out from the id. An `anthropic-messages`
     *        model that wants adaptive thinking says `new AnthropicCompat(forceAdaptiveThinking:
     *        true)`, as a `models.json` entry says `"forceAdaptiveThinking": true`; the id list
     *        `Models` applies (`AnthropicCompat::isAdaptiveThinkingModel()`) is upstream's
     *        generator, for the built-in rows only
     * @param list<string> $envKeys environment variable names that carry this provider's key,
     *        first one set wins — `Stream::envApiKey()`'s table for one more row
     * @param ApiKeyAuth|null $apiKeyAuth upstream's `auth.apiKey`: a sign-in that stores an
     *        `api_key` credential, and the answer to whether the provider is configured and with
     *        what key — asked by `CodingAgent\Auth` before `$envKeys`
     * @param list<ClassifierModel> $classifiers the classifier half of upstream's `getAllModels()`,
     *        each with `provider` set to `$id`
     */
    public function __construct(
        public string $id,
        public string $name,
        public array $models,
        public ?StreamApi $api = null,
        public ?OauthFlow $oauth = null,
        public array $envKeys = [],
        public bool $resold = false,
        public ?ApiKeyAuth $apiKeyAuth = null,
        public array $classifiers = [],
    ) {
        if ($id === '' || $name === '') {
            throw new \InvalidArgumentException('A provider needs an id and a name.');
        }

        foreach ($models as $model) {
            if ($model->provider !== $id) {
                throw new \InvalidArgumentException("Model '{$model->id}' says provider '{$model->provider}', not '{$id}'.");
            }
        }

        foreach ($classifiers as $model) {
            if ($model->provider !== $id) {
                throw new \InvalidArgumentException("Model '{$model->id}' says provider '{$model->provider}', not '{$id}'.");
            }
        }
    }
}
