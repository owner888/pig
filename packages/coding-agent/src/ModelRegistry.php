<?php

declare(strict_types=1);

namespace Pig\CodingAgent;

use Pig\Agent\ThinkingLevel;
use Pig\Ai\AssistantImages;
use Pig\Ai\ClassifierContext;
use Pig\Ai\ClassifierModel;
use Pig\Ai\ClassifierOptions;
use Pig\Ai\Context;
use Pig\Ai\Extension\AuthResult;
use Pig\Ai\Extension\ProviderRegistry;
use Pig\Ai\ImageModel;
use Pig\Ai\ImagesContext;
use Pig\Ai\ImagesOptions;
use Pig\Ai\Model;
use Pig\Ai\Models;
use Pig\Ai\ModelType;
use Pig\Ai\ClassifierResult;
use Pig\Ai\SimpleStreamOptions;
use Pig\Ai\Stream;
use Pig\Ai\TranscriptContext;
use Pig\Ai\Utils\AssistantMessageEventStream;
use Pig\Async\AbortSignal;

/**
 * Upstream's `ModelRegistry` (`core/model-registry.ts`): "Synchronous compatibility facade exposed to
 * extensions" — `ctx.modelRegistry` on an extension's context, and the `ctx` a virtual model's router
 * looks its physical model up in.
 *
 * pig's registry is static — `Ai\Models` for the catalogue, `ProviderRegistry` for what extensions
 * brought, `ModelRefresh` for fetched catalogues, `Auth` for credentials — so this holds nothing but
 * the run's `Auth` and asks them. The methods are upstream's, under upstream's names; a request made
 * through `streamSimple()`, `classify()` or `generateImages()` carries the provider's key from `Auth`
 * when the caller gave none, upstream's "request-time authentication".
 */
final readonly class ModelRegistry
{
    public function __construct(private ?Auth $auth = null)
    {
    }

    /**
     * "Reload models.json asynchronously" — upstream's `refresh(options)`: `models.json` read again and
     * installed, then every provider with a fetched catalogue refreshed (`ModelRefresh::refresh()`).
     *
     * @param list<string>|null $providers only these
     */
    public function refresh(?array $providers = null, ?AbortSignal $signal = null, ?bool $allowNetwork = null, ?bool $force = null): ModelRefreshResult
    {
        CustomModels::discover()->install($this->auth);

        if ($this->auth === null) {
            return new ModelRefreshResult($signal?->aborted() ?? false, []);
        }

        return ModelRefresh::refresh($this->auth, $providers, $signal, $allowNetwork ?? ModelRefresh::networkEnabled(), $force);
    }

    /** @return list<Model> every chat model — upstream's `getAll()` */
    public function getAll(): array
    {
        return Models::all();
    }

    /** @return list<Model> the chat models whose provider has credentials — upstream's `getAvailable()` */
    public function getAvailable(): array
    {
        return $this->auth?->availableModels() ?? array_values(array_filter(Models::all(), $this->hasConfiguredAuth(...)));
    }

    public function find(string $provider, string $modelId): ?Model
    {
        return Models::find($provider, $modelId);
    }

    /**
     * "Find a model of a non-chat type, e.g. `findOfType("classifier", "typesafe", "jev-latest")`."
     * Upstream's `findOfType()` and `getModelOfType()`, which are the same lookup.
     */
    public function findOfType(ModelType|string $type, string $provider, string $modelId): Model|ImageModel|ClassifierModel|null
    {
        return Models::findOfType(self::type($type), $provider, $modelId);
    }

    public function getModelOfType(ModelType|string $type, string $provider, string $modelId): Model|ImageModel|ClassifierModel|null
    {
        return $this->findOfType($type, $provider, $modelId);
    }

    /**
     * "Every known model of a type (chat, image, classifier), optionally for one provider."
     *
     * @return list<Model|ImageModel|ClassifierModel>
     */
    public function getModelsOfType(ModelType|string $type, ?string $provider = null): array
    {
        return array_values(array_filter(
            Models::allOfType(self::type($type)),
            static fn (Model|ImageModel|ClassifierModel $model): bool => $provider === null || $model->provider === $provider,
        ));
    }

    /**
     * "Models of a type whose provider has working credentials."
     *
     * @return list<Model|ImageModel|ClassifierModel>
     */
    public function getAvailableOfType(ModelType|string $type, ?string $provider = null): array
    {
        return array_values(array_filter(
            $this->getModelsOfType($type, $provider),
            fn (Model|ImageModel|ClassifierModel $model): bool => $this->hasKey($model->provider),
        ));
    }

    public function hasConfiguredAuth(Model $model): bool
    {
        return $this->hasKey($model->provider);
    }

    /** Upstream's `getProviderAuth(provider)`: the provider's resolved auth, or null when it is not configured. */
    public function getProviderAuth(string $provider): ?AuthResult
    {
        return $this->auth?->providerAuth($provider);
    }

    public function getProviderDisplayName(string $provider): string
    {
        return ProviderRegistry::get($provider)->name ?? $provider;
    }

    /**
     * "Stream with provider-neutral options and request-time authentication." A virtual model is routed
     * first, with reason `direct` — "a request made outside the agent loop, such as … an extension
     * calling `ctx.modelRegistry.streamSimple()`" — and its level clamped to the model it went to.
     */
    public function streamSimple(Model $model, Context|TranscriptContext $context, ?SimpleStreamOptions $options = null): AssistantMessageEventStream
    {
        $reasoning = $options?->reasoning;

        if (Models::isVirtual($model)) {
            $route = VirtualModelRegistry::current()->resolve(
                $model,
                $context->messages,
                ModelRouteRequest::DIRECT,
                ThinkingLevel::fromReasoning($reasoning),
                $this->hasConfiguredAuth(...),
                $options?->signal,
            );
            $model = $route->model;
            $reasoning = $route->thinkingLevel->toReasoning();
        }

        $base = ($options ?? new SimpleStreamOptions())->baseArgs();
        $apiKey = $base['apiKey'] ?? null;

        return Stream::simple($model, $context, new SimpleStreamOptions(...[
            ...$base,
            'apiKey' => is_string($apiKey) && $apiKey !== '' ? $apiKey : $this->auth?->apiKey($model->provider),
            'reasoning' => $reasoning,
            'toolChoice' => $options?->toolChoice,
            'thinkingBudgets' => $options?->thinkingBudgets,
        ]));
    }

    /** "Classify structured state with request-time authentication. Never rejects." */
    public function classify(ClassifierModel $model, ClassifierContext $context, ?ClassifierOptions $options = null): ClassifierResult
    {
        try {
            $args = ($options ?? new ClassifierOptions())->args();
            $args['apiKey'] = $options?->apiKey !== null && $options->apiKey !== '' ? $options->apiKey : $this->auth?->apiKey($model->provider);
        } catch (\Throwable $error) {
            return ClassifierResult::error($model, $error, $options?->signal?->aborted() ?? false);
        }

        return Models::classify($model, $context, new ClassifierOptions(...$args));
    }

    /** "Generate images with request-time authentication. Never rejects." */
    public function generateImages(ImageModel $model, ImagesContext $context, ?ImagesOptions $options = null): AssistantImages
    {
        try {
            $args = ($options ?? new ImagesOptions())->args();
            $args['apiKey'] = $options?->apiKey !== null && $options->apiKey !== '' ? $options->apiKey : $this->auth?->apiKey($model->provider);
        } catch (\Throwable $error) {
            return AssistantImages::error($model, $error, $options?->signal?->aborted() ?? false);
        }

        return Models::generateImages($model, $context, new ImagesOptions(...$args));
    }

    private function hasKey(string $provider): bool
    {
        return $this->auth?->hasKeyFor($provider) ?? Stream::envApiKey($provider) !== null;
    }

    private static function type(ModelType|string $type): ModelType
    {
        return $type instanceof ModelType ? $type : ModelType::from($type);
    }
}
