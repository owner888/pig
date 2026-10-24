<?php

declare(strict_types=1);

namespace Pig\Ai;


/**
 * Every model this can talk to, by provider and id.
 *
 * Every model whose **protocol is ported** — a model that could be selected and then not talked to
 * is a worse answer than "no such model". Chat models are `all()`/`find()`; the classifier and image
 * models are tables of their own (reached by `findOfType()` and `allOfType()` and used through
 * `classify()` and `generateImages()`), as upstream keeps "chat and classifier entries with the same
 * provider and id separate".
 *
 * ### The built-in catalogue is pi's JSON
 *
 * The shipped models are read from `CATALOGUE_DIR`: a file per provider in pi's model format — the
 * format of pi's own `providers/data/<provider>.json`, of pi.dev's `/api/models/providers/<id>` and
 * of `models.json` — read by `ModelJson`. `scripts/build-models.php` writes those files from
 * `scripts/BuiltinCatalog.php`, the generated rows and the rules upstream's generator applies to
 * them; nothing here derives a row any more, so what is in the files is what pig sends.
 *
 * Over the catalogue, in order: a credential's narrowing of a provider (`personalize()`), models
 * declared elsewhere (`register()`), the few allowed to overwrite a built-in (`register(replace:
 * true)`), whole-provider replacements (`replaceProvider()`), `models.json` (`useOverlay()`), and
 * the virtual models (`registerVirtual()`).
 *
 * `ModelsTest` pins a row per provider and the collision rule, so a regeneration that moved
 * something load-bearing is a red test rather than a surprise; `ModelsCatalogueTest` fails while the
 * files and `BuiltinCatalog` disagree.
 *
 * Ported from upstream's `models.ts`; the rows come from where upstream's come from.
 */
final class Models
{
    public const string ANTHROPIC = 'anthropic';

    public const string COPILOT = 'github-copilot';

    public const string GOOGLE_VERTEX = 'google-vertex';

    public const string AMAZON_BEDROCK = 'amazon-bedrock';

    public const string AZURE = 'azure';

    public const string OPENAI_CODEX = 'openai-codex';

    public const string RADIUS = 'radius';

    /** The `baseUrl` of the Radius gateway's `/v1/config` (`getRadiusModelsFromConfig()` gives it to every row). */
    public const string RADIUS_BASE_URL = 'https://radius.pi.dev/v1';

    /**
     * Providers that answer with somebody else's models, under somebody else's ids.
     *
     * Copilot serves OpenAI's, Anthropic's and Google's models at their own ids, so `gpt-5`
     * now names two things. **A bare id means the direct provider**; Copilot's is reached as
     * `github-copilot/gpt-5`. Written down as a rule rather than left to the order the tables
     * happen to be built in — that gave the same answer and would have stopped doing so the
     * first time somebody moved a `foreach`.
     *
     * **Vertex AI is here for the same reason**: it serves Google's Gemini models under Google's own
     * ids, so `gemini-2.5-flash` names two things again and the bare id stays the Gemini API's; Vertex's
     * is `google-vertex/gemini-2.5-flash`. Bedrock is not — its ids carry their vendor
     * (`anthropic.claude-…`) and collide with nobody's.
     *
     * **Azure, ChatGPT's Codex backend and the Radius gateway too**: Azure's table is a clone of
     * OpenAI's, Codex serves OpenAI's models and Radius OpenAI's and Anthropic's, all under the ids their
     * makers use — so `gpt-5.4` stays OpenAI's and `azure/gpt-5.4` is Azure's. An id only one of them
     * has (Radius's `balanced`) is still found, as a resold id always is.
     *
     * **And every provider of `CATALOGUE_PROVIDERS` that serves another maker's models**: the gateways
     * (OpenRouter, Vercel's AI Gateway, OpenCode Zen and Go), the hosts (Together, Fireworks, Baseten,
     * Hugging Face, NVIDIA), the Token Plans (Qwen's three, which serve GLM, DeepSeek and Kimi as well,
     * and Xiaomi's), and the China endpoints of a maker whose own endpoint is here too (MiniMax,
     * Moonshot, z.ai). So `kimi-k2.6` is Moonshot's, `glm-5.3` z.ai's, `mimo-v2.5-pro` Xiaomi's, and
     * `openrouter/moonshotai/kimi-k2.6` OpenRouter's. The makers' own — DeepSeek, Ant Ling, Meta,
     * MiniMax, Moonshot, Xiaomi, Kimi For Coding — are not. This is pig's rule, not upstream's: pi
     * refuses a bare id more than one provider claims (`findExactModelReferenceMatch()`) and falls
     * through to its partial matching.
     */
    private const array RESOLD = [
        self::COPILOT,
        self::GOOGLE_VERTEX,
        self::AZURE,
        self::OPENAI_CODEX,
        self::RADIUS,
        'baseten',
        'cloudflare-ai-gateway',
        'cloudflare-workers-ai',
        'fireworks',
        'huggingface',
        'minimax-cn',
        'moonshotai-cn',
        'nvidia',
        'opencode',
        'opencode-go',
        'openrouter',
        'qwen-token-plan',
        'qwen-token-plan-cn',
        'qwen-token-plan-individual',
        'together',
        'vercel-ai-gateway',
        'xiaomi-token-plan-ams',
        'xiaomi-token-plan-cn',
        'xiaomi-token-plan-sgp',
        'zai-coding-cn',
    ];

    /** Upstream's generator `DEFAULT_IMAGE_RESIZE`: 2000 × 2000, 4.5 MiB, JPEG quality 80. */
    private const array DEFAULT_IMAGE_RESIZE = [
        'maxWidth' => 2000,
        'maxHeight' => 2000,
        'maxBytes' => 4_718_592,
        'jpegQuality' => 80,
    ];

    /** @var array<string, Model>|null built once, on the first lookup that needs it */
    private static ?array $models = null;

    /** @var array<string, ClassifierModel>|null the built-in classifiers and the layers over them, built once */
    private static ?array $classifiers = null;

    /** @var array<string, ImageModel>|null the built-in image models and the layers over them, built once */
    private static ?array $images = null;

    /**
     * Where the built-in catalogue lives: a JSON file per provider in pi's model format (chat,
     * classifier and image entries together, as pi's own `providers/data/<provider>.json`), and
     * `providers.json`, the providers in the order `all()` lists them. `scripts/build-models.php`
     * writes them; nothing here derives a row.
     */
    public const string CATALOGUE_DIR = __DIR__ . '/../data/models';

    /** @var array{0: list<Model>, 1: list<ClassifierModel>, 2: list<ImageModel>}|null the catalogue, read once */
    private static ?array $builtins = null;

    /**
     * @var list<Model> declared somewhere else and handed over — see `register()`
     */
    private static array $registered = [];

    /**
     * @var list<Model> handed over by something entitled to overwrite a built-in row — see the
     *      `$replace` argument on `register()`, which has exactly one caller
     */
    private static array $replacing = [];

    /**
     * Add models the table does not know about.
     *
     * For `models.json`: somebody's own endpoint, or a local server, declared in a file rather
     * than waiting for a release. `CodingAgent\CustomModels` is what reads that file; this end
     * knows nothing about files and only takes finished `Model` objects.
     *
     * **They go in at the end, so a built-in always wins a collision.** Both `find()` and the
     * table are keyed `provider/id`, so a file declaring `anthropic/claude-sonnet-4-5` would
     * otherwise quietly replace the real one — a config file being able to redefine a shipped
     * model is a bug report nobody could read. A custom provider with a name of its own
     * collides with nothing and is the normal case.
     *
     * Static because `Models` is: everything downstream — `--model`, `/model`, restoring a
     * session, `RpcMode` — asks this class by id, and a model that only some of them could see
     * would be a model that works until you save the conversation.
     *
     * **`$replace` is the one exception to that rule and it has one caller**: the Antigravity
     * extension's `Catalog`, reading the catalogue pi keeps refreshed on disk, over the fallback
     * table the same extension registers first. The rule above exists so a *person's* file cannot
     * redefine a shipped model; there both sides have one author — the deployment's own catalogue
     * endpoint — and the fallback is itself a frozen print of it, so the two differ only in when
     * they were taken. A snapshot beating a live reading of the same source is the wrong way
     * round. Nothing else may pass true, and a second caller is the moment to ask whether this is
     * still an exception or has become a rule.
     *
     * @param list<Model> $models
     */
    public static function register(array $models, bool $replace = false): void
    {
        if ($replace) {
            self::$replacing = [...self::$replacing, ...$models];
        } else {
            self::$registered = [...self::$registered, ...$models];
        }

        self::$models = null;
    }

    /**
     * @var list<ClassifierModel> classifier models a provider declared elsewhere — see
     *      `registerClassifiers()`
     */
    private static array $registeredClassifiers = [];

    /**
     * Add classifier models the table does not know about — the classifier half of an extension
     * provider's `getAllModels()`, which upstream's llama.cpp provider fills from the router's
     * catalog. A built-in row wins a collision, as `register()` has it for chat models.
     *
     * @param list<ClassifierModel> $models
     */
    public static function registerClassifiers(array $models): void
    {
        self::$registeredClassifiers = [...self::$registeredClassifiers, ...$models];
        self::$classifiers = null;
    }

    /**
     * @var array<string, Model> virtual models, keyed `provider/id` — upstream's `withVirtualModels()`:
     *      laid over the table last, and **hiding** a physical chat model of the same id, which a
     *      catalogue refresh can add after the registration
     */
    private static array $virtual = [];

    /**
     * @var array<string, array{0: list<Model>, 1: list<ClassifierModel>, 2: list<ImageModel>}> a provider's
     *      whole model list, every type, put in place of its built-in and registered rows — see
     *      `replaceProvider()`
     */
    private static array $providerReplacements = [];

    /**
     * @var array<string, \Closure(list<Model>): list<Model>> provider => what narrows its built-in chat
     *      rows to one credential's — see `personalize()`
     */
    private static array $personalized = [];

    /** @var list<ImageModel> image models a provider declared elsewhere — see `registerImages()` */
    private static array $registeredImages = [];

    /**
     * @var (\Closure(array<string, Model>): array<string, Model>)|null the `models.json` layer — see
     *      `useOverlay()`
     */
    private static ?\Closure $overlay = null;

    /** @var array<string, Model>|null the chat table before the overlay and the virtual models */
    private static ?array $base = null;

    /**
     * Put a provider's model list in place of every row it has — upstream's legacy
     * `registerProvider(name, config)` with `models`: "Supplying `models` in the legacy form replaces
     * that provider's models across chat, image, and classifier operations." `forgetProvider()` takes it
     * back, and the built-in rows return. Also the way a config with only a `baseUrl` or `headers` for a
     * built-in provider is laid over it: the rows, rewritten, in place of themselves.
     *
     * @param list<Model> $chat
     * @param list<ClassifierModel> $classifiers
     * @param list<ImageModel> $images
     */
    public static function replaceProvider(string $provider, array $chat, array $classifiers = [], array $images = []): void
    {
        self::$providerReplacements[$provider] = [$chat, $classifiers, $images];
        self::$models = null;
        self::$classifiers = null;
        self::$images = null;
    }

    /**
     * Narrow a built-in provider's chat rows to what one credential may use — upstream's
     * `withPersonalizedCatalog()`, OpenRouter's `GET /models/user` being the one there is (see
     * `Providers\OpenRouterUserModels`). `$personalize` is given the provider's built-in rows and
     * answers the ones to keep, with their limits and prices; classifier and image rows are left as
     * they are. Applied before registrations, replacements and `models.json`, so a `baseUrl` from the
     * file still lands on the kept rows. Null takes it away.
     *
     * @param (\Closure(list<Model>): list<Model>)|null $personalize
     */
    public static function personalize(string $provider, ?\Closure $personalize): void
    {
        if ($personalize === null) {
            unset(self::$personalized[$provider]);
        } else {
            self::$personalized[$provider] = $personalize;
        }

        self::$models = null;
    }

    /**
     * Image models the table does not know about — the image half of an extension provider's models.
     * A built-in row wins a collision, as `register()` has it.
     *
     * @param list<ImageModel> $models
     */
    public static function registerImages(array $models): void
    {
        self::$registeredImages = [...self::$registeredImages, ...$models];
        self::$images = null;
    }

    /**
     * The `models.json` layer: a function from the finished chat table to the table — upstream's
     * `applyModelsJson()` and `modelOverrides`, which compose over built-in and extension providers
     * alike. Applied after every other layer and before the virtual models; **replaced**, not added
     * to, because the file is read again whenever `/model` opens. `CodingAgent\CustomModels` is what
     * builds it; null takes it away.
     *
     * @param (\Closure(array<string, Model>): array<string, Model>)|null $overlay
     */
    public static function useOverlay(?\Closure $overlay): void
    {
        self::$overlay = $overlay;
        self::$models = null;
    }

    /**
     * A provider's chat models as the built-in table, the registrations and the provider replacements
     * have them — **before** the `models.json` layer: the defaults upstream's `findModelDefaults()`
     * reads for a model a file declares under that provider.
     *
     * @return list<Model>
     */
    public static function baseModelsOf(string $provider): array
    {
        self::table();

        return array_values(array_filter(self::$base ?? [], static fn (Model $model): bool => $model->provider === $provider));
    }

    /** Forget what `register()` added. For tests, which must not leak models into each other. */
    public static function forgetRegistered(): void
    {
        self::$registered = [];
        self::$replacing = [];
        self::$virtual = [];
        self::$registeredClassifiers = [];
        self::$registeredImages = [];
        self::$providerReplacements = [];
        self::$personalized = [];
        self::$overlay = null;
        self::$models = null;
        self::$classifiers = null;
        self::$images = null;
    }

    /**
     * A virtual model (`Api::Virtual`) under any provider, with or without physical models of its
     * own; the same provider and id again replaces it. `Pig\CodingAgent\VirtualModelRegistry` builds the
     * row and keeps the router; this end only lists it.
     */
    public static function registerVirtual(Model $model): void
    {
        if ($model->api !== Api::Virtual) {
            throw new \InvalidArgumentException("Not a virtual model: {$model->provider}/{$model->id}");
        }

        self::$virtual[$model->provider . '/' . $model->id] = $model;
        self::$models = null;
    }

    public static function forgetVirtual(string $provider, string $id): void
    {
        unset(self::$virtual[$provider . '/' . $id]);
        self::$models = null;
    }

    /** Whether a model is a virtual one — upstream's `isVirtualModel()`. */
    public static function isVirtual(Model $model): bool
    {
        return $model->api === Api::Virtual;
    }

    /** Whether nothing but virtual models defines this provider, which then needs no credentials. */
    public static function isVirtualOnlyProvider(string $provider): bool
    {
        $hasVirtual = false;

        foreach (self::table() as $model) {
            if ($model->provider !== $provider) {
                continue;
            }

            if ($model->api !== Api::Virtual) {
                return false;
            }

            $hasVirtual = true;
        }

        return $hasVirtual;
    }

    /** Forget one provider's registered models — `ProviderRegistry::unregister()`'s half. */
    public static function forgetProvider(string $provider): void
    {
        $keep = static fn (Model $model): bool => $model->provider !== $provider;
        self::$registered = array_values(array_filter(self::$registered, $keep));
        self::$replacing = array_values(array_filter(self::$replacing, $keep));
        self::$registeredClassifiers = array_values(array_filter(self::$registeredClassifiers, static fn (ClassifierModel $model): bool => $model->provider !== $provider));
        self::$registeredImages = array_values(array_filter(self::$registeredImages, static fn (ImageModel $model): bool => $model->provider !== $provider));
        unset(self::$providerReplacements[$provider]);
        self::$models = null;
        self::$classifiers = null;
        self::$images = null;
    }

    /**
     * One model by id, or null when there is no such model.
     *
     * Ids were unique across the providers here until Copilot's table landed; it serves
     * `gpt-5` and `gemini-2.5-pro` under the same names their own providers use. **A bare id
     * means the direct provider**, and Copilot's is reached through `find()` or through
     * `github-copilot/gpt-5` on the command line. See `RESOLD`.
     */
    public static function get(string $id): ?Model
    {
        $resold = null;

        foreach (self::table() as $model) {
            if ($model->id !== $id) {
                continue;
            }

            if (!self::isResold($model->provider)) {
                return $model;
            }

            $resold ??= $model;
        }

        // Only when nobody sells it directly. `grok-code-fast-1` is Copilot's alone here,
        // because xAI's own table does not carry it — so a resold id is still an answer, it
        // is just never the first one.
        return $resold;
    }

    /**
     * Whether a provider answers with somebody else's models under their own ids.
     *
     * Public because `ModelResolver` needs the same rule and two copies of it would disagree
     * the first time one of them was edited.
     */
    public static function isResold(string $provider): bool
    {
        return in_array($provider, self::RESOLD, true) || Extension\ProviderRegistry::isResold($provider);
    }

    public static function find(string $provider, string $id): ?Model
    {
        return self::table()[$provider . '/' . $id] ?? null;
    }

    /** @return list<Model> every model, in the order the table lists them */
    public static function all(): array
    {
        return array_values(self::table());
    }

    /** @return list<string> the providers there are models for */
    public static function providers(): array
    {
        $providers = [];

        foreach (self::table() as $model) {
            if (!in_array($model->provider, $providers, true)) {
                $providers[] = $model->provider;
            }
        }

        return $providers;
    }

    /**
     * One model of a type, or null — upstream's `getModelOfType(type, provider, id)`. A chat id and a
     * classifier or image id may be the same string under the same provider; each type is its own
     * table, as upstream keeps them apart.
     */
    public static function findOfType(ModelType $type, string $provider, string $id): Model|ImageModel|ClassifierModel|null
    {
        return match ($type) {
            ModelType::Chat => self::find($provider, $id),
            ModelType::Image => self::imageTable()[$provider . '/' . $id] ?? null,
            ModelType::Classifier => self::classifierTable()[$provider . '/' . $id] ?? null,
        };
    }

    /**
     * Every model of a type — upstream's `getModelsOfType(type)`; `all()` is the chat ones.
     *
     * @return list<Model>|list<ImageModel>|list<ClassifierModel>
     */
    public static function allOfType(ModelType $type): array
    {
        return match ($type) {
            ModelType::Chat => self::all(),
            ModelType::Image => array_values(self::imageTable()),
            ModelType::Classifier => array_values(self::classifierTable()),
        };
    }

    /**
     * "Classify structured state through the owning provider. Never rejects." — upstream's
     * `Models.classify()`: the provider's auth applied (`applyAuth()`: the key the caller gave, else the
     * environment's; for Workers AI the account id into the env and the base URL, as
     * `cloudflareClassifier()` wraps it), then the classifier API the model names. Every failure,
     * an unconfigured provider included, is an error result.
     *
     * Dispatched by the model's API rather than by its provider's `classifiers` table, as `Stream`
     * dispatches a chat model: upstream's providers each register `typesafe-system-one`, or Workers
     * AI's own, under the API name, so the two pick the same implementation for every built-in row.
     * A llama.cpp model needs no key — upstream's llama extension resolves it keyless.
     *
     * `ClassifierApi::Extension` is upstream's provider-supplied `classifiers` implementation: the
     * `Extension\ClassificationApi` the model's provider registered, after the same input check and
     * with the same key, its result returned as it is and a throw turned into an error result.
     */
    public static function classify(ClassifierModel $model, ClassifierContext $context, ?ClassifierOptions $options = null): ClassifierResult
    {
        try {
            // Upstream's `assertClassifierInputSupported()`: "Rejects classifier images for models
            // whose catalog entry does not accept image input."
            if ($context->images !== [] && !in_array('image', $model->input, true)) {
                throw new ProviderError("Model {$model->provider}/{$model->id} does not accept image input");
            }

            [$requestModel, $requestOptions] = self::applyClassifierAuth($model, $options);

            return match ($model->api) {
                ClassifierApi::TypesafeSystemOne => (new Providers\TypesafeSystemOne())->classify($requestModel, $context, $requestOptions),
                ClassifierApi::CloudflareWorkersAiSystemOne => (new Providers\CloudflareWorkersAiSystemOne())->classify($requestModel, $context, $requestOptions),
                ClassifierApi::LlamaCppClassify => (new Providers\LlamaCppClassify())->classify($requestModel, $context, $requestOptions),
                ClassifierApi::OpenAiDecisions => (new Providers\OpenAiDecisions())->classify($requestModel, $context, $requestOptions),
                ClassifierApi::Extension => (Extension\ProviderRegistry::classifierApiFor($requestModel)
                    ?? throw new ProviderError("No extension provides classification for {$model->provider}/{$model->id}. Is its extension loaded?"))
                    ->classify($requestModel, $context, $requestOptions),
            };
        } catch (\Throwable $error) {
            return ClassifierResult::error($model, $error, $options?->signal?->aborted() ?? false);
        }
    }

    /**
     * "Generate images through the owning provider with auth resolved like `stream()`. Never rejects:
     * unknown providers, unconfigured auth, and providers without `generateImages` return an error
     * `AssistantImages`." — upstream's `Models.generateImages()`, dispatched by the model's API.
     *
     * `ImageApi::Extension` is upstream's provider-supplied `images` implementation: the
     * `Extension\ImageGenerationApi` the model's provider registered, handed the same key, and its
     * result returned as it is — or, when it throws, the same error result as any other failure. A
     * model of a provider whose extension is not loaded is refused by name, as `Stream` refuses an
     * `Api::Extension` chat model.
     */
    public static function generateImages(ImageModel $model, ImagesContext $context, ?ImagesOptions $options = null): AssistantImages
    {
        try {
            $apiKey = $options?->apiKey !== null && $options->apiKey !== ''
                ? $options->apiKey
                : Stream::envApiKey($model->provider, $options?->env);

            if ($apiKey === null || $apiKey === '') {
                throw new ProviderError("Provider is not configured: {$model->provider}");
            }

            $requestOptions = new ImagesOptions(...[...($options ?? new ImagesOptions())->args(), 'apiKey' => $apiKey]);

            return match ($model->api) {
                ImageApi::OpenRouterImages => (new Providers\OpenRouterImages())->generateImages($model, $context, $requestOptions),
                ImageApi::Extension => (Extension\ProviderRegistry::imageApiFor($model)
                    ?? throw new ProviderError("No extension provides image generation for {$model->provider}/{$model->id}. Is its extension loaded?"))
                    ->generateImages($model, $context, $requestOptions),
            };
        } catch (\Throwable $error) {
            return AssistantImages::error($model, $error, $options?->signal?->aborted() ?? false);
        }
    }

    /**
     * `applyAuth()` for a classifier: the key, and Workers AI's account id resolved into the env and
     * the base URL (`cloudflareWorkersAIAuth()` and `cloudflareClassifier()`). "Provider is not
     * configured" when nothing answers, as upstream's `ModelsError("auth")` says it.
     *
     * @return array{0: ClassifierModel, 1: ClassifierOptions}
     */
    private static function applyClassifierAuth(ClassifierModel $model, ?ClassifierOptions $options): array
    {
        $args = ($options ?? new ClassifierOptions())->args();
        $apiKey = $options?->apiKey !== null && $options->apiKey !== ''
            ? $options->apiKey
            : Stream::envApiKey($model->provider, $options?->env);

        if (Providers\Cloudflare::isCloudflare($model->provider)) {
            $resolved = Providers\Cloudflare::resolveCloudflareEnv($model->provider, $apiKey, $options?->env)
                ?? throw new ProviderError("Provider is not configured: {$model->provider}");
            $env = [...$resolved['env'], ...($options?->env ?? [])];
            $requestModel = Providers\Cloudflare::resolveCloudflareModel($model, $env);
            \assert($requestModel instanceof ClassifierModel);

            return [$requestModel, new ClassifierOptions(...[...$args, 'apiKey' => $resolved['apiKey'], 'env' => $env])];
        }

        if (($apiKey === null || $apiKey === '') && $model->api !== ClassifierApi::LlamaCppClassify) {
            throw new ProviderError("Provider is not configured: {$model->provider}");
        }

        return [$model, new ClassifierOptions(...[...$args, 'apiKey' => $apiKey])];
    }

    /** @return array<string, ClassifierModel> keyed by "provider/id" */
    private static function classifierTable(): array
    {
        if (self::$classifiers !== null) {
            return self::$classifiers;
        }

        $models = [];

        foreach (self::builtins()[1] as $model) {
            $models[$model->provider . '/' . $model->id] = $model;
        }

        // Last, and only where nothing is already: see `registerClassifiers()`.
        foreach (self::$registeredClassifiers as $model) {
            $models[$model->provider . '/' . $model->id] ??= $model;
        }

        return self::$classifiers = self::withReplacements($models, 1);
    }

    /** @return array<string, ImageModel> keyed by "provider/id" */
    private static function imageTable(): array
    {
        if (self::$images !== null) {
            return self::$images;
        }

        $models = [];

        foreach (self::builtins()[2] as $model) {
            $models[$model->provider . '/' . $model->id] = $model;
        }

        // Last, and only where nothing is already: see `registerImages()`.
        foreach (self::$registeredImages as $model) {
            $models[$model->provider . '/' . $model->id] ??= $model;
        }

        return self::$images = self::withReplacements($models, 2);
    }

    /**
     * What a provider's models cost to run this usage.
     *
     * Upstream's `calculateCost()` mutates the usage it is given; this returns the cost,
     * because a function that quietly rewrites its argument is the kind of thing that
     * makes a total wrong twice.
     */
    public static function cost(Model $model, Usage $usage): Cost
    {
        // One rule, `Usage::withCost()`'s — tiers and the one-hour write included — rather than a
        // second copy here that knew neither.
        return $usage->withCost($model)->cost;
    }

    /**
     * The shipped catalogue, read from `CATALOGUE_DIR`: chat, classifier and image models, each in
     * `providers.json`'s order and each file's.
     *
     * @return array{0: list<Model>, 1: list<ClassifierModel>, 2: list<ImageModel>}
     */
    private static function builtins(): array
    {
        if (self::$builtins !== null) {
            return self::$builtins;
        }

        $chat = $classifiers = $images = [];
        $read = static fn (string $file): mixed => json_decode((string) file_get_contents(self::CATALOGUE_DIR . '/' . $file), true, flags: JSON_THROW_ON_ERROR);

        foreach ($read('providers.json') as $provider) {
            foreach ($read("{$provider}.json") as $entry) {
                $model = ModelJson::decode($entry, $provider);

                match (true) {
                    $model instanceof ClassifierModel => $classifiers[] = $model,
                    $model instanceof ImageModel => $images[] = $model,
                    default => $chat[] = $model,
                };
            }
        }

        return self::$builtins = [$chat, $classifiers, $images];
    }

    /** @return array<string, Model> keyed by "provider/id", which is unique by construction */
    private static function table(): array
    {
        if (self::$models !== null) {
            return self::$models;
        }

        $models = [];

        foreach (self::builtins()[0] as $model) {
            $models[$model->provider . '/' . $model->id] = $model;
        }

        // A credential's narrowing of a built-in provider — see `personalize()`.
        foreach (self::$personalized as $provider => $personalize) {
            $own = array_filter($models, static fn (Model $model): bool => $model->provider === $provider);
            $models = array_diff_key($models, $own);

            foreach ($personalize(array_values($own)) as $model) {
                $models[$model->provider . '/' . $model->id] = $model;
            }
        }

        // Last, and only where nothing is already: see `register()`. A built-in wins.
        foreach (self::$registered as $model) {
            $models[$model->provider . '/' . $model->id] ??= $model;
        }

        // And after even that, the one kind that is allowed to overwrite a row already there — see
        // the `$replace` argument on `register()` for why Antigravity's catalogue is it.
        foreach (self::$replacing as $model) {
            $models[$model->provider . '/' . $model->id] = $model;
        }

        // A provider whose whole list was replaced — see `replaceProvider()`.
        $models = self::withReplacements($models, 0);
        self::$base = $models;

        // `models.json` over all of that — see `useOverlay()`.
        if (self::$overlay !== null) {
            $models = (self::$overlay)($models);
        }

        // And the virtual ones over everything, hiding a physical model of the same id — see
        // `registerVirtual()`.
        foreach (self::$virtual as $key => $model) {
            $models[$key] = $model;
        }

        return self::$models = $models;
    }

    /**
     * A table with each replaced provider's rows taken out and its replacement list put in.
     *
     * @template T of Model|ClassifierModel|ImageModel
     * @param array<string, T> $models
     * @param 0|1|2 $type chat, classifier, image — the position in `$providerReplacements`
     * @return array<string, T>
     */
    private static function withReplacements(array $models, int $type): array
    {
        foreach (self::$providerReplacements as $provider => $replacement) {
            $models = array_filter($models, static fn (Model|ClassifierModel|ImageModel $model): bool => $model->provider !== $provider);

            foreach ($replacement[$type] as $model) {
                $models[$model->provider . '/' . $model->id] = $model;
            }
        }

        return $models;
    }

    /**
     * Upstream's generator `applyImageInputMetadata()`, for a built-in model: nothing for a model that
     * takes no images; otherwise the provider's limits — Anthropic `{maxRequestBytes: 32 MiB, images:
     * {maxPerRequest: 100 at a 200,000 window, else 600}}`, OpenAI `{maxRequestBytes: 512 MiB, images:
     * {maxPerRequest: 1500}}`, Google `{maxRequestBytes: 20 MiB, images: {maxPerRequest: 3600}}`, Amazon
     * Bedrock `{images: {maxPerMessage: 20}}` — with `DEFAULT_IMAGE_RESIZE` as the images' `resize`
     * on every one: "Keep the generated default no less restrictive than coding-agent's historical
     * image preprocessing. Provider limits can narrow this profile, but unknown providers retain the
     * cache-safe 2000px / 4.5 MiB behavior."
     *
     * Public for the models a provider extension declares, which upstream's generator would have
     * given the same treatment: `pig-antigravity`'s catalogue gets the default branch.
     *
     * @param list<string> $input
     * @return array<string, mixed>|null
     */
    public static function inputLimits(string $provider, array $input, int $contextWindow): ?array
    {
        if (!in_array('image', $input, true)) {
            return null;
        }

        $providerLimits = match ($provider) {
            self::ANTHROPIC => ['maxRequestBytes' => 32 * 1024 * 1024, 'images' => ['maxPerRequest' => $contextWindow === 200_000 ? 100 : 600]],
            self::AMAZON_BEDROCK => ['images' => ['maxPerMessage' => 20]],
            'openai' => ['maxRequestBytes' => 512 * 1024 * 1024, 'images' => ['maxPerRequest' => 1500]],
            'google' => ['maxRequestBytes' => 20 * 1024 * 1024, 'images' => ['maxPerRequest' => 3600]],
            default => [],
        };

        return [
            ...$providerLimits,
            'images' => [
                ...($providerLimits['images'] ?? []),
                'resize' => self::DEFAULT_IMAGE_RESIZE,
            ],
        ];
    }

}
