<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Extensions;

use Closure;
use InvalidArgumentException;
use Pig\Ai\AnthropicCompat;
use Pig\Ai\Api;
use Pig\Ai\BedrockCompat;
use Pig\Ai\AssistantImages;
use Pig\Ai\ClassifierApi;
use Pig\Ai\ClassifierContext;
use Pig\Ai\ClassifierModel;
use Pig\Ai\ClassifierOptions;
use Pig\Ai\ClassifierResult;
use Pig\Ai\Extension\ClassificationApi;
use Pig\Ai\Extension\ImageGenerationApi;
use Pig\Ai\Extension\OauthFlow;
use Pig\Ai\Extension\Provider;
use Pig\Ai\Extension\ProviderRegistry;
use Pig\Ai\Extension\RefreshModelsContext;
use Pig\Ai\Extension\StreamApi;
use Pig\Ai\ImageApi;
use Pig\Ai\ImageModel;
use Pig\Ai\ImagesContext;
use Pig\Ai\ImagesOptions;
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
 * - `images` and `classifiers` are upstream's implementations keyed by API name — each an
 *   `Extension\ImageGenerationApi` / `Extension\ClassificationApi`, or a Closure of the same shape as its
 *   one method. An image (classifier) model whose `api` is a key of the map becomes an
 *   `ImageApi::Extension` (`ClassifierApi::Extension`) model and goes to that implementation — a key that
 *   is a built-in API's name included, as upstream's composer lets an extension's implementation win over
 *   the base provider's; every other image or classifier model goes to the built-in API its `api` names.
 * - `refreshModels(RefreshModelsContext): list<definition>` replaces the registration's models with what
 *   it returns, through `$context->publish`, after checking them.
 *
 * Where pig differs, it says so rather than doing something else:
 * - `headers` (and `authHeader`'s `Authorization`) are resolved **at registration**: pig's headers are
 *   part of the model. A header that cannot be resolved fails the registration in upstream's words.
 * - `oauth` is a pig `OauthFlow`, the shape a native provider's sign-in already has; upstream's object of
 *   callbacks has no PHP form.
 * - An implementation is handed the model with `api` set to the `Extension` case, not to the API name it
 *   was registered under: pig's `ImageApi` / `ClassifierApi` are closed. Which implementation a model goes
 *   to is decided here, by the name its definition gave, so a map of several names still dispatches as
 *   upstream's `images[model.api]` does.
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

        $imageImplementations = self::implementations($config['images'] ?? null, ImageGenerationApi::class, "{$where}: \"images\"");
        $classifierImplementations = self::implementations($config['classifiers'] ?? null, ClassificationApi::class, "{$where}: \"classifiers\"");
        // Which implementation each `Extension` model goes to, by model id; kept current with the models
        // themselves, a `refreshModels` included.
        $imageRouter = $imageImplementations === [] ? null : self::imageRouter($id, $imageImplementations);
        $classifierRouter = $classifierImplementations === [] ? null : self::classifierRouter($id, $classifierImplementations);

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
        $apis = [ModelType::Image->value => array_keys($imageImplementations), ModelType::Classifier->value => array_keys($classifierImplementations)];
        $build = static fn (?array $definitions): array => self::models($id, $config, $definitions, $existing, $headers, $baseUrl, $api, $streamSimple !== null, $apis);
        [$chat, $classifiers, $images, $routes] = $build(isset($config['models']) ? self::list($config['models'], "{$where}: \"models\"") : null);
        $route = static function (array $routes) use ($imageRouter, $classifierRouter): void {
            $imageRouter?->route($routes[ModelType::Image->value]);
            $classifierRouter?->route($routes[ModelType::Classifier->value]);
        };
        $route($routes);

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
            refreshModels: $refresh === null ? null : static function (RefreshModelsContext $context) use ($refresh, $build, $id, $route): void {
                // "The returned list replaces extension-provided models." Checked before it is published,
                // as upstream validates before publishing the new synchronous list.
                $definitions = $refresh($context);

                if ($context->signal->aborted()) {
                    return;
                }

                if (!is_array($definitions) || !array_is_list($definitions)) {
                    throw new UnexpectedValueException("Provider {$id}: refreshModels must return a list of model definitions.");
                }

                [$chat, $classifiers, $images, $routes] = $build($definitions);
                ($context->publish)(null, static function () use ($id, $chat, $classifiers, $images, $routes, $route): void {
                    $route($routes);
                    Models::replaceProvider($id, $chat, $classifiers, $images);
                });
            },
            images: $images,
            replacesModels: true,
            imageApi: $imageRouter,
            classifierApi: $classifierRouter,
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
     * @param array<string, list<string>> $apis the API names `images` / `classifiers` implement, by `ModelType` value
     * @return array{0: list<Model>, 1: list<ClassifierModel>, 2: list<ImageModel>, 3: array<string, array<string, string>>}
     *         the models, and by `ModelType` value which API name each `Extension` model's id goes to
     */
    private static function models(string $id, array $config, ?array $definitions, array $existing, array $headers, ?string $baseUrl, ?string $api, bool $hasStream, array $apis): array
    {
        $routes = [ModelType::Image->value => [], ModelType::Classifier->value => []];

        if ($definitions === null) {
            // The models stay, and an implementation for a built-in API's name takes those of that API over —
            // upstream's `extensionImages?.[model.api]` ahead of the base provider's `generateImages`.
            $rebased = static function (Model|ClassifierModel|ImageModel $model, ?ModelType $type = null) use ($baseUrl, $headers, $apis, &$routes): array {
                $args = [...get_object_vars($model), 'baseUrl' => $baseUrl ?? $model->baseUrl, 'headers' => [...$model->headers, ...$headers]];

                if ($type !== null && in_array($model->api->value, $apis[$type->value], true)) {
                    $routes[$type->value][$model->id] = $model->api->value;
                    $args['api'] = $type === ModelType::Image ? ImageApi::Extension : ClassifierApi::Extension;
                }

                return $args;
            };

            return [
                array_map(static fn (Model $model): Model => new Model(...$rebased($model)), $existing[ModelType::Chat->value]),
                array_map(static fn (ClassifierModel $model): ClassifierModel => new ClassifierModel(...$rebased($model, ModelType::Classifier)), $existing[ModelType::Classifier->value]),
                array_map(static fn (ImageModel $model): ImageModel => new ImageModel(...$rebased($model, ModelType::Image)), $existing[ModelType::Image->value]),
                $routes,
            ];
        }

        $chat = $classifiers = $images = [];

        foreach ($definitions as $position => $definition) {
            if (!is_array($definition)) {
                throw new InvalidArgumentException("Provider {$id}: model {$position} is not an array.");
            }

            $type = ModelType::tryFrom((string) ($definition['type'] ?? 'chat'))
                ?? throw new InvalidArgumentException("Provider {$id}: model {$position} has an unknown \"type\".");
            [$model, $implemented] = self::model($id, $config, $definition, $type, $existing[$type->value], $headers, $baseUrl, $api, $hasStream, $apis[$type->value] ?? []);

            match (true) {
                $model instanceof Model => $chat[] = $model,
                $model instanceof ClassifierModel => $classifiers[] = $model,
                default => $images[] = $model,
            };

            if ($implemented !== null) {
                $routes[$type->value][$model->id] = $implemented;
            }
        }

        return [$chat, $classifiers, $images, $routes];
    }

    /**
     * Upstream's `extensionModelFromDefinition()`.
     *
     * @param array<string, mixed> $config
     * @param array<mixed> $definition
     * @param list<Model|ClassifierModel|ImageModel> $existing the provider's models of this type
     * @param array<string, string> $headers
     * @param list<string> $implemented the API names the config's `images` / `classifiers` implement, for this type
     * @return array{0: Model|ClassifierModel|ImageModel, 1: string|null} the model, and the API name whose
     *         implementation it goes to when it is an `Extension` one
     */
    private static function model(string $id, array $config, array $definition, ModelType $type, array $existing, array $headers, ?string $baseUrl, ?string $api, bool $hasStream, array $implemented): array
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

        // The name the definition gave (or the existing model's): one the config's `images` / `classifiers`
        // implements goes to that implementation, any other must be a built-in API — `extension` itself is not
        // one a definition can name.
        $apiName = $modelApi instanceof \BackedEnum ? (string) $modelApi->value : (string) $modelApi;
        $route = in_array($apiName, $implemented, true) ? $apiName : null;

        if ($type === ModelType::Image) {
            $imageApi = match (true) {
                $route !== null => ImageApi::Extension,
                $modelApi instanceof ImageApi => $modelApi,
                default => ($apiName === ImageApi::Extension->value ? null : ImageApi::tryFrom($apiName))
                    ?? throw new InvalidArgumentException("{$where}: pig has no image API \"{$apiName}\", and \"images\" does not implement it."),
            };
            $output = array_values(array_filter(is_array($definition['output'] ?? null) ? $definition['output'] : ['image'], static fn (mixed $one): bool => $one === 'text' || $one === 'image'));

            return [new ImageModel($modelId, $name, $imageApi, $id, rtrim($modelBaseUrl, '/'), $input ?: ['text'], $output ?: ['image'], $pricing, $modelHeaders, $inputLimits), $route];
        }

        if ($type === ModelType::Classifier) {
            $classifierApi = match (true) {
                $route !== null => ClassifierApi::Extension,
                $modelApi instanceof ClassifierApi => $modelApi,
                default => ($apiName === ClassifierApi::Extension->value ? null : ClassifierApi::tryFrom($apiName))
                    ?? throw new InvalidArgumentException("{$where}: pig has no classifier API \"{$apiName}\", and \"classifiers\" does not implement it."),
            };

            return [new ClassifierModel($modelId, $name, $classifierApi, $id, rtrim($modelBaseUrl, '/'), $contextWindow, $input ?: ['text'], $pricing, $modelHeaders, $inputLimits), $route];
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

        return [new Model(
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
        ), null];
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

    /**
     * Upstream's `images` / `classifiers` map, checked: API names to an implementation — the interface, or a
     * Closure of its one method's shape.
     *
     * @param class-string $interface
     * @return array<string, object> by API name
     */
    private static function implementations(mixed $map, string $interface, string $what): array
    {
        if ($map === null) {
            return [];
        }

        if (!is_array($map) || ($map !== [] && array_is_list($map))) {
            throw new InvalidArgumentException("{$what} must be an array of implementations keyed by API name.");
        }

        foreach ($map as $name => $implementation) {
            if (!is_string($name) || $name === '' || $name === 'extension') {
                throw new InvalidArgumentException("{$what}: \"{$name}\" is not an API name an implementation can be registered under.");
            }

            if (!$implementation instanceof Closure && !$implementation instanceof $interface) {
                throw new InvalidArgumentException("{$what}: \"{$name}\" must be a Closure or a {$interface}.");
            }
        }

        return $map;
    }

    /**
     * The config's `images` as the provider's one `ImageGenerationApi`: upstream's
     * `images[model.api]`, with the API name each model was defined under standing in for `model.api`.
     *
     * @param array<string, ImageGenerationApi|Closure> $implementations
     */
    private static function imageRouter(string $provider, array $implementations): ImageGenerationApi
    {
        return new class ($provider, $implementations) implements ImageGenerationApi {
            /** @var array<string, string> model id => API name */
            private array $routes = [];

            /** @param array<string, ImageGenerationApi|Closure> $implementations */
            public function __construct(private readonly string $provider, private readonly array $implementations)
            {
            }

            /** @param array<string, string> $routes */
            public function route(array $routes): void
            {
                $this->routes = $routes;
            }

            public function generateImages(ImageModel $model, ImagesContext $context, ImagesOptions $options): AssistantImages
            {
                $name = $this->routes[$model->id] ?? null;
                $implementation = $name === null ? null : $this->implementations[$name];

                if ($implementation === null) {
                    // Upstream's `imageErrorResult(model, …"has no image generation implementation"…)`.
                    return AssistantImages::error($model, new UnexpectedValueException("Provider {$this->provider} has no image generation implementation for \"{$model->id}\"."));
                }

                $result = $implementation instanceof Closure ? $implementation($model, $context, $options) : $implementation->generateImages($model, $context, $options);

                if (!$result instanceof AssistantImages) {
                    throw new UnexpectedValueException("images[\"{$name}\"] for {$model->provider}/{$model->id} did not return a Pig\\Ai\\AssistantImages.");
                }

                return $result;
            }
        };
    }

    /**
     * The config's `classifiers` as the provider's one `ClassificationApi` — `imageRouter()`'s twin.
     *
     * @param array<string, ClassificationApi|Closure> $implementations
     */
    private static function classifierRouter(string $provider, array $implementations): ClassificationApi
    {
        return new class ($provider, $implementations) implements ClassificationApi {
            /** @var array<string, string> model id => API name */
            private array $routes = [];

            /** @param array<string, ClassificationApi|Closure> $implementations */
            public function __construct(private readonly string $provider, private readonly array $implementations)
            {
            }

            /** @param array<string, string> $routes */
            public function route(array $routes): void
            {
                $this->routes = $routes;
            }

            public function classify(ClassifierModel $model, ClassifierContext $context, ClassifierOptions $options): ClassifierResult
            {
                $name = $this->routes[$model->id] ?? null;
                $implementation = $name === null ? null : $this->implementations[$name];

                if ($implementation === null) {
                    return ClassifierResult::error($model, new UnexpectedValueException("Provider {$this->provider} has no classifier implementation for \"{$model->id}\"."));
                }

                $result = $implementation instanceof Closure ? $implementation($model, $context, $options) : $implementation->classify($model, $context, $options);

                if (!$result instanceof ClassifierResult) {
                    throw new UnexpectedValueException("classifiers[\"{$name}\"] for {$model->provider}/{$model->id} did not return a Pig\\Ai\\ClassifierResult.");
                }

                return $result;
            }
        };
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
