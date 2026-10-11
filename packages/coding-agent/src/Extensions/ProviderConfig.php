<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Extensions;

use Closure;
use InvalidArgumentException;
use Pig\Ai\AnthropicCompat;
use Pig\Ai\Api;
use Pig\Ai\BedrockCompat;
use Pig\Ai\ClassifierApi;
use Pig\Ai\ClassifierModel;
use Pig\Ai\Extension\OauthFlow;
use Pig\Ai\Extension\Provider;
use Pig\Ai\Extension\ProviderRegistry;
use Pig\Ai\Extension\RefreshModelsContext;
use Pig\Ai\Extension\StreamApi;
use Pig\Ai\ImageApi;
use Pig\Ai\ImageModel;
use Pig\Ai\Model;
use Pig\Ai\Models;
use Pig\Ai\ModelType;
use Pig\Ai\OpenAiCompat;
use Pig\Ai\SimpleStreamOptions;
use Pig\Ai\StreamOptions;
use Pig\Ai\TranscriptContext;
use Pig\Ai\Utils\AssistantMessageEventStream;
use Pig\CodingAgent\ConfigValue;
use Pig\CodingAgent\CustomModels;
use UnexpectedValueException;

/**
 * Upstream's legacy `registerProvider(name, config: ProviderConfig)`, turned into pig's `Provider`.
 *
 * The keys are upstream's `ProviderConfig`: `name`, `baseUrl`, `apiKey`, `api`, `streamSimple`,
 * `headers`, `authHeader`, `models`, `refreshModels`, `oauth`, `images`, `classifiers`. What they do is
 * upstream's `applyExtension()` and `composeApiKeyAuth()`:
 *
 * - **No `models`**: the provider's models stay — a built-in provider's included — at `baseUrl` when
 *   one is given, with `headers` on them. **With `models`**, the list replaces every model the provider
 *   had, chat, image and classifier alike, until `unregisterProvider()` restores them.
 * - A definition's `type` is `chat` when left out; `image` and `classifier` name the operation. Its
 *   `api` falls back to the config's (chat only) and then to an existing model's of the same type, its
 *   `baseUrl` to the config's and then to the existing model's (upstream's `findExtensionModelDefaults()`).
 * - `apiKey` is a `ConfigValue` (`$NAME`, `${NAME}`, `!command`, a literal), resolved per request by
 *   `ConfiguredApiKeyAuth`; a key stored with `/login` wins over it.
 * - `streamSimple(Model, TranscriptContext, ?SimpleStreamOptions): AssistantMessageEventStream` speaks
 *   for the models whose `api` is the config's `api` — they become `Api::Extension` models — and `api` is
 *   then required. Every other model goes to the built-in protocol its `api` names.
 * - `refreshModels(RefreshModelsContext): list<definition>` replaces the registration's models with what
 *   it returns, through `$context->publish`, after checking them.
 *
 * Where pig differs, it says so rather than doing something else:
 * - `headers` (and `authHeader`'s `Authorization`) are resolved **at registration**: pig's headers are
 *   part of the model. A header that cannot be resolved fails the registration in upstream's words.
 * - `oauth` is a pig `OauthFlow`, the shape a native provider's sign-in already has; upstream's object of
 *   callbacks has no PHP form.
 * - `images` and `classifiers` — implementations keyed by a custom API name — are refused: pig's image
 *   and classifier APIs are a fixed set (`ImageApi`, `ClassifierApi`). An image or classifier model on one
 *   of those APIs is accepted.
 */
final class ProviderConfig
{
    /** Upstream's `modelFromJson()` defaults, which a definition here takes too when it leaves them out. */
    private const int DEFAULT_CONTEXT_WINDOW = 128_000;

    private const int DEFAULT_MAX_TOKENS = 16_384;

    /**
     * @param array<string, mixed> $config
     * @throws InvalidArgumentException naming the key that is wrong
     */
    public static function toProvider(string $id, array $config): Provider
    {
        $where = "Provider {$id}";
        $api = $config['api'] ?? null;
        $streamSimple = $config['streamSimple'] ?? null;

        if ($api !== null && (!is_string($api) || $api === '')) {
            throw new InvalidArgumentException("{$where}: \"api\" must be a non-empty string.");
        }

        if ($streamSimple !== null && !$streamSimple instanceof Closure) {
            throw new InvalidArgumentException("{$where}: \"streamSimple\" must be a Closure.");
        }

        // Upstream's `validateExtensionProvider()`.
        if ($streamSimple !== null && $api === null) {
            throw new InvalidArgumentException("{$where}: \"api\" is required when registering streamSimple.");
        }

        foreach (['images', 'classifiers'] as $key) {
            if (($config[$key] ?? []) !== []) {
                throw new InvalidArgumentException("{$where}: \"{$key}\" implementations are not supported by pig — its image and classifier APIs are a fixed set; give the models a built-in api instead.");
            }
        }

        $oauth = $config['oauth'] ?? null;

        if ($oauth !== null && !$oauth instanceof OauthFlow) {
            throw new InvalidArgumentException("{$where}: \"oauth\" must be a Pig\\Ai\\Extension\\OauthFlow.");
        }

        $rawKey = $config['apiKey'] ?? null;

        if ($rawKey !== null && (!is_string($rawKey) || $rawKey === '')) {
            throw new InvalidArgumentException("{$where}: \"apiKey\" must be a non-empty string.");
        }

        $headers = ConfigValue::resolveAll(self::strings($config['headers'] ?? null, "{$where}: \"headers\""), "provider \"{$id}\" header");

        if (($config['authHeader'] ?? false) === true && $rawKey !== null && ($key = ConfigValue::resolveUncached($rawKey)) !== null) {
            $headers['Authorization'] = 'Bearer ' . $key;
        }

        $baseUrl = $config['baseUrl'] ?? null;

        if ($baseUrl !== null && (!is_string($baseUrl) || $baseUrl === '')) {
            throw new InvalidArgumentException("{$where}: \"baseUrl\" must be a non-empty string.");
        }

        // What the provider has before this registration: the defaults a definition takes, and the
        // models kept when there is no `models` list. Taken before `ProviderRegistry::register()`
        // replaces them, and kept for `refreshModels`, whose definitions take the same defaults.
        $existing = [
            ModelType::Chat->value => Models::baseModelsOf($id),
            ModelType::Classifier->value => self::ofProvider(Models::allOfType(ModelType::Classifier), $id),
            ModelType::Image->value => self::ofProvider(Models::allOfType(ModelType::Image), $id),
        ];
        $build = static fn (?array $definitions): array => self::models($id, $config, $definitions, $existing, $headers, $baseUrl, $api, $streamSimple !== null);
        [$chat, $classifiers, $images] = $build(isset($config['models']) ? self::list($config['models'], "{$where}: \"models\"") : null);

        $refresh = $config['refreshModels'] ?? null;

        if ($refresh !== null && !$refresh instanceof Closure) {
            throw new InvalidArgumentException("{$where}: \"refreshModels\" must be a Closure.");
        }

        return new Provider(
            id: $id,
            name: self::name($id, $config, $oauth),
            models: $chat,
            api: $streamSimple === null ? null : self::streamApi($streamSimple),
            oauth: $oauth,
            apiKeyAuth: $rawKey === null ? null : new ConfiguredApiKeyAuth($id, $rawKey),
            classifiers: $classifiers,
            refreshModels: $refresh === null ? null : static function (RefreshModelsContext $context) use ($refresh, $build, $id): void {
                // "The returned list replaces extension-provided models." Checked before it is published,
                // as upstream validates before publishing the new synchronous list.
                $definitions = $refresh($context);

                if ($context->signal->aborted()) {
                    return;
                }

                if (!is_array($definitions) || !array_is_list($definitions)) {
                    throw new UnexpectedValueException("Provider {$id}: refreshModels must return a list of model definitions.");
                }

                [$chat, $classifiers, $images] = $build($definitions);
                ($context->publish)(null, static fn () => Models::replaceProvider($id, $chat, $classifiers, $images));
            },
            images: $images,
            replacesModels: true,
        );
    }

    /**
     * Upstream's `applyExtension()`: no definitions keeps the existing models (at the config's `baseUrl`),
     * definitions replace them.
     *
     * @param array<string, mixed> $config
     * @param list<mixed>|null $definitions
     * @param array<string, list<Model|ClassifierModel|ImageModel>> $existing by `ModelType` value
     * @param array<string, string> $headers the provider's, resolved
     * @return array{0: list<Model>, 1: list<ClassifierModel>, 2: list<ImageModel>}
     */
    private static function models(string $id, array $config, ?array $definitions, array $existing, array $headers, ?string $baseUrl, ?string $api, bool $hasStream): array
    {
        if ($definitions === null) {
            $rebased = static fn (Model|ClassifierModel|ImageModel $model): array => [...get_object_vars($model), 'baseUrl' => $baseUrl ?? $model->baseUrl, 'headers' => [...$model->headers, ...$headers]];

            return [
                array_map(static fn (Model $model): Model => new Model(...$rebased($model)), $existing[ModelType::Chat->value]),
                array_map(static fn (ClassifierModel $model): ClassifierModel => new ClassifierModel(...$rebased($model)), $existing[ModelType::Classifier->value]),
                array_map(static fn (ImageModel $model): ImageModel => new ImageModel(...$rebased($model)), $existing[ModelType::Image->value]),
            ];
        }

        $chat = $classifiers = $images = [];

        foreach ($definitions as $position => $definition) {
            if (!is_array($definition)) {
                throw new InvalidArgumentException("Provider {$id}: model {$position} is not an array.");
            }

            $type = ModelType::tryFrom((string) ($definition['type'] ?? 'chat'))
                ?? throw new InvalidArgumentException("Provider {$id}: model {$position} has an unknown \"type\".");
            $model = self::model($id, $config, $definition, $type, $existing[$type->value], $headers, $baseUrl, $api, $hasStream);

            match (true) {
                $model instanceof Model => $chat[] = $model,
                $model instanceof ClassifierModel => $classifiers[] = $model,
                default => $images[] = $model,
            };
        }

        return [$chat, $classifiers, $images];
    }

    /**
     * Upstream's `extensionModelFromDefinition()`.
     *
     * @param array<string, mixed> $config
     * @param array<mixed> $definition
     * @param list<Model|ClassifierModel|ImageModel> $existing the provider's models of this type
     * @param array<string, string> $headers
     */
    private static function model(string $id, array $config, array $definition, ModelType $type, array $existing, array $headers, ?string $baseUrl, ?string $api, bool $hasStream): Model|ClassifierModel|ImageModel
    {
        $modelId = $definition['id'] ?? null;

        if (!is_string($modelId) || $modelId === '') {
            throw new InvalidArgumentException("Provider {$id}: a model with no \"id\".");
        }

        $where = "Provider {$id}, model {$modelId}";
        $defaults = self::defaults($existing, $modelId, is_string($definition['api'] ?? null) ? $definition['api'] : null, $type);
        $modelApi = $definition['api'] ?? ($type === ModelType::Chat ? $api : null) ?? $defaults?->api;
        $modelBaseUrl = $definition['baseUrl'] ?? $baseUrl ?? $defaults?->baseUrl;

        if ($modelApi === null) {
            throw new InvalidArgumentException("{$where}: no \"api\" specified. Set it at model level" . ($type === ModelType::Chat ? ' or provider level.' : '.'));
        }

        if (!is_string($modelBaseUrl) || $modelBaseUrl === '') {
            throw new InvalidArgumentException("Provider {$id}: \"baseUrl\" is required when defining custom models.");
        }

        $name = is_string($definition['name'] ?? null) && $definition['name'] !== '' ? $definition['name'] : $modelId;
        $input = array_values(array_filter(is_array($definition['input'] ?? null) ? $definition['input'] : ['text'], static fn (mixed $one): bool => $one === 'text' || $one === 'image'));
        $pricing = CustomModels::pricing(is_array($definition['cost'] ?? null) ? $definition['cost'] : []);
        $modelHeaders = [...$headers, ...ConfigValue::resolveAll(self::strings($definition['headers'] ?? null, "{$where}: \"headers\""), "model \"{$id}/{$modelId}\" header")];
        $inputLimits = is_array($definition['inputLimits'] ?? null) ? $definition['inputLimits'] : null;
        $contextWindow = self::positive($definition, 'contextWindow', self::DEFAULT_CONTEXT_WINDOW, $where);

        if ($type === ModelType::Image) {
            $imageApi = $modelApi instanceof ImageApi ? $modelApi : ImageApi::tryFrom((string) $modelApi)
                ?? throw new InvalidArgumentException("{$where}: pig has no image API \"{$modelApi}\".");
            $output = array_values(array_filter(is_array($definition['output'] ?? null) ? $definition['output'] : ['image'], static fn (mixed $one): bool => $one === 'text' || $one === 'image'));

            return new ImageModel($modelId, $name, $imageApi, $id, rtrim($modelBaseUrl, '/'), $input ?: ['text'], $output ?: ['image'], $pricing, $modelHeaders, $inputLimits);
        }

        if ($type === ModelType::Classifier) {
            $classifierApi = $modelApi instanceof ClassifierApi ? $modelApi : ClassifierApi::tryFrom((string) $modelApi)
                ?? throw new InvalidArgumentException("{$where}: pig has no classifier API \"{$modelApi}\".");

            return new ClassifierModel($modelId, $name, $classifierApi, $id, rtrim($modelBaseUrl, '/'), $contextWindow, $input ?: ['text'], $pricing, $modelHeaders, $inputLimits);
        }

        // A chat model on the config's own api, when `streamSimple` speaks it, goes to the extension;
        // any other names a built-in protocol.
        $chatApi = match (true) {
            $modelApi instanceof Api => $modelApi,
            $hasStream && $modelApi === $api => Api::Extension,
            default => CustomModels::api((string) $modelApi)
                ?? throw new InvalidArgumentException("{$where}: no API provider registered for api: {$modelApi}"),
        };
        $compat = $definition['compat'] ?? null;

        return new Model(
            $modelId,
            $name,
            $chatApi,
            $id,
            rtrim($modelBaseUrl, '/'),
            $contextWindow,
            self::positive($definition, 'maxTokens', self::DEFAULT_MAX_TOKENS, $where),
            ($definition['reasoning'] ?? false) === true,
            $input ?: ['text'],
            $pricing,
            $modelHeaders,
            // "A model's `compat` is the extension's to write and is used as written" — a typed compat as
            // it is, an array read under upstream's key names.
            match (true) {
                $compat instanceof OpenAiCompat, $compat instanceof AnthropicCompat, $compat instanceof BedrockCompat => $compat,
                is_array($compat) => CustomModels::compat($compat, $chatApi),
                default => null,
            },
            CustomModels::thinkingLevelMap(is_array($definition['thinkingLevelMap'] ?? null) ? $definition['thinkingLevelMap'] : null),
            $inputLimits,
            is_array($definition['promptCache'] ?? null) ? $definition['promptCache'] : null,
        );
    }

    /**
     * Upstream's `findExtensionModelDefaults()`: the same id, else the api asked for, else (chat only)
     * an `openai-completions` model, else the first of the type.
     *
     * @param list<Model|ClassifierModel|ImageModel> $models
     */
    private static function defaults(array $models, string $id, ?string $api, ModelType $type): Model|ClassifierModel|ImageModel|null
    {
        foreach ($models as $model) {
            if ($model->id === $id) {
                return $model;
            }
        }

        foreach ([$api, $type === ModelType::Chat ? 'openai-completions' : null] as $wanted) {
            foreach ($wanted === null ? [] : $models as $model) {
                if ($model->api->value === $wanted) {
                    return $model;
                }
            }
        }

        return $models[0] ?? null;
    }

    /** Upstream's `extension?.name ?? config?.name ?? base?.name ?? extension?.oauth?.name ?? providerId`. */
    private static function name(string $id, array $config, ?OauthFlow $oauth): string
    {
        $name = $config['name'] ?? null;

        if (is_string($name) && $name !== '') {
            return $name;
        }

        return ProviderRegistry::get($id)->name ?? $oauth?->label() ?? $id;
    }

    /** `streamSimple` as pig's `StreamApi`: the simple options carried through with the key on them. */
    private static function streamApi(Closure $streamSimple): StreamApi
    {
        return new readonly class ($streamSimple) implements StreamApi {
            public function __construct(private Closure $streamSimple)
            {
            }

            public function translate(Model $model, ?SimpleStreamOptions $options, string $apiKey): StreamOptions
            {
                return new SimpleStreamOptions(...[
                    ...($options ?? new SimpleStreamOptions())->baseArgs(),
                    'apiKey' => $apiKey,
                    'reasoning' => $options?->reasoning,
                    'toolChoice' => $options?->toolChoice,
                    'thinkingBudgets' => $options?->thinkingBudgets,
                ]);
            }

            public function stream(Model $model, TranscriptContext $context, StreamOptions $options): AssistantMessageEventStream
            {
                $stream = ($this->streamSimple)($model, $context, $options instanceof SimpleStreamOptions ? $options : new SimpleStreamOptions(...$options->baseArgs()));

                if (!$stream instanceof AssistantMessageEventStream) {
                    throw new UnexpectedValueException("streamSimple for {$model->provider}/{$model->id} did not return an AssistantMessageEventStream.");
                }

                return $stream;
            }
        };
    }

    /** @param array<mixed> $definition */
    private static function positive(array $definition, string $key, int $default, string $where): int
    {
        $value = $definition[$key] ?? $default;

        if (!is_int($value) || $value <= 0) {
            throw new InvalidArgumentException("{$where}: invalid {$key}");
        }

        return $value;
    }

    /**
     * @param list<Model|ClassifierModel|ImageModel> $models
     * @return list<Model|ClassifierModel|ImageModel>
     */
    private static function ofProvider(array $models, string $provider): array
    {
        return array_values(array_filter($models, static fn (Model|ClassifierModel|ImageModel $model): bool => $model->provider === $provider));
    }

    /** @return list<mixed> */
    private static function list(mixed $value, string $what): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new InvalidArgumentException("{$what} must be a list.");
        }

        return $value;
    }

    /** @return array<string, string> */
    private static function strings(mixed $value, string $what): array
    {
        if ($value === null) {
            return [];
        }

        if (!is_array($value)) {
            throw new InvalidArgumentException("{$what} must be an array of strings.");
        }

        foreach ($value as $key => $entry) {
            if (!is_string($key) || !is_string($entry)) {
                throw new InvalidArgumentException("{$what} must be an array of strings.");
            }
        }

        return $value;
    }
}
