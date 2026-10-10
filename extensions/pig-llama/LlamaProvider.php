<?php

declare(strict_types=1);

namespace Pig\Extensions\Llama;

use Closure;
use Pig\Ai\Api;
use Pig\Ai\ClassifierApi;
use Pig\Ai\ClassifierModel;
use Pig\Ai\Extension\ApiKeyCredential;
use Pig\Ai\Extension\Provider;
use Pig\Ai\Model;
use Pig\Ai\Models;
use Pig\Ai\OpenAiCompat;
use Pig\Ai\Pricing;
use Pig\Async\AbortSignal;
use Throwable;

/**
 * Upstream's `extensions/llama/provider.ts`, `createLlamaProvider()`'s controller: the provider and
 * `setCatalog()`.
 *
 * The models are the router's catalog: a chat model on `openai-completions` at the router's `/v1`
 * for every model that can be selected and generates text, and a classifier with the same id for
 * every model that can be selected — `typesafe-system-one` for a llama.cpp decision model,
 * `llama-cpp-classify` otherwise. Both dispatch by their API in `Models`, as upstream's `stream` and
 * `classify` do.
 *
 * Upstream's provider answers `getModels()` from its private lists; pig's registry is `Models`, so an
 * update of the lists is followed by `install()`, which replaces the provider's rows there — the
 * Antigravity catalog's `install()` does the same.
 *
 * @phpstan-import-type LlamaModelInfo from LlamaClient
 */
final class LlamaProvider
{
    public const string LLAMA_PROVIDER_ID = 'llama.cpp';

    public const string DEFAULT_LLAMA_SERVER_URL = 'http://127.0.0.1:8080';

    /** @var list<Model> */
    private array $models = [];

    /** @var list<ClassifierModel> */
    private array $classifiers = [];

    public readonly Provider $provider;

    /** @param Closure(ApiKeyCredential): void|null $onLogin told about a credential `/login` is about to keep */
    public function __construct(?Closure $onLogin = null)
    {
        $this->provider = new Provider(
            id: self::LLAMA_PROVIDER_ID,
            name: 'llama.cpp',
            models: [],
            apiKeyAuth: new LlamaApiKeyAuth($onLogin),
        );
    }

    /** Upstream's `credentialServerUrl()`: the stored `LLAMA_BASE_URL`, normalized. */
    public static function credentialServerUrl(?ApiKeyCredential $credential): ?string
    {
        $value = $credential?->env['LLAMA_BASE_URL'] ?? null;

        return is_string($value) && trim($value) !== '' ? LlamaClient::normalizeLlamaServerUrl($value) : null;
    }

    /** Upstream's `resolveServerUrl()`: the stored URL, then the `LLAMA_BASE_URL` environment variable. */
    public static function resolveServerUrl(?ApiKeyCredential $credential): ?string
    {
        $fromEnvironment = getenv('LLAMA_BASE_URL');
        $configured = self::credentialServerUrl($credential) ?? ($fromEnvironment === false ? null : trim($fromEnvironment));

        return $configured !== null && $configured !== '' ? LlamaClient::normalizeLlamaServerUrl($configured) : null;
    }

    /** @param LlamaModelInfo $model */
    public static function modelIsSelectable(array $model, bool $routerAutoload): bool
    {
        if ($model['status']['value'] === 'loaded') {
            return true;
        }

        // llama.cpp reports idle-slept models as "sleeping"; requests wake them automatically.
        if ($model['status']['value'] === 'sleeping') {
            return true;
        }

        // Unloaded presets are routable only when llama.cpp router autoload can load them on first use.
        return $routerAutoload && $model['status']['value'] === 'unloaded' && !($model['status']['failed'] ?? false) && ($model['source'] ?? null) === 'preset';
    }

    /** @param list<LlamaModelInfo> $catalog */
    public static function routerAutoloadEnabled(LlamaClient $client, array $catalog, AbortSignal $signal): bool
    {
        $presets = array_filter($catalog, static fn (array $model): bool => $model['status']['value'] === 'unloaded' && ($model['source'] ?? null) === 'preset');

        if ($presets === []) {
            return false;
        }

        try {
            return ($client->props(signal: $signal)['models_autoload'] ?? null) === true;
        } catch (Throwable) {
            return false;
        }
    }

    /** @param LlamaModelInfo $model */
    public static function configuredContextWindow(array $model): ?int
    {
        $args = $model['status']['args'] ?? [];

        for ($index = 0; $index < count($args) - 1; $index++) {
            $flag = $args[$index];

            if ($flag !== '--ctx-size' && $flag !== '-c' && $flag !== '-ctx') {
                continue;
            }

            $contextWindow = $args[$index + 1];

            // `Number.isSafeInteger(Number(value)) && value > 0`.
            if (preg_match('/^\s*\d+\s*$/', (string) $contextWindow) === 1 && (int) $contextWindow > 0) {
                return (int) $contextWindow;
            }
        }

        return null;
    }

    /** @param LlamaModelInfo $model */
    public static function contextWindowOf(array $model, ?int $cachedContextWindow = null): int
    {
        $runtimeContextWindow = $model['meta']['n_ctx'] ?? null;

        if (is_int($runtimeContextWindow) && $runtimeContextWindow > 0) {
            return $runtimeContextWindow;
        }

        $configuredContext = self::configuredContextWindow($model);

        if ($configuredContext !== null) {
            return $configuredContext;
        }

        if ($cachedContextWindow !== null && $cachedContextWindow > 0) {
            return $cachedContextWindow;
        }

        $trainingContextWindow = $model['meta']['n_ctx_train'] ?? null;

        return is_int($trainingContextWindow) && $trainingContextWindow > 0 ? $trainingContextWindow : 128000;
    }

    /**
     * Whether llama.cpp reports a native decision model. Since llama.cpp 0.6.0, `GET /models` lists
     * `decisions` in `architecture.output_modalities` for these models, including unloaded and
     * sleeping ones. Older servers report `["text"]` or omit `architecture`, so their models are
     * treated as chat models.
     *
     * @param LlamaModelInfo $model
     */
    public static function isDecisionModel(array $model): bool
    {
        return in_array('decisions', $model['architecture']['output_modalities'] ?? [], true);
    }

    /**
     * Decision-only models cannot generate text and are not listed for chat.
     *
     * @param LlamaModelInfo $model
     */
    public static function isChatModel(array $model): bool
    {
        return !self::isDecisionModel($model) || in_array('text', $model['architecture']['output_modalities'] ?? [], true);
    }

    /**
     * A llama.cpp model used as a classifier. Decision models answer natively through llama.cpp's
     * System One endpoint (`/v1/systemone`). Chat models fall back to `llama-cpp-classify`, which
     * reads answers from next-token label probabilities.
     *
     * @param LlamaModelInfo $model
     */
    public static function toPiClassifierModel(array $model, string $serverUrl, ?int $cachedContextWindow = null): ClassifierModel
    {
        $decision = self::isDecisionModel($model);

        return new ClassifierModel(
            id: $model['id'],
            name: $model['id'],
            api: $decision ? ClassifierApi::TypesafeSystemOne : ClassifierApi::LlamaCppClassify,
            provider: self::LLAMA_PROVIDER_ID,
            baseUrl: $decision ? LlamaClient::llamaInferenceUrl($serverUrl) : $serverUrl,
            contextWindow: self::contextWindowOf($model, $cachedContextWindow),
            input: ['text'],
            pricing: new Pricing(),
        );
    }

    public static function isLlamaClassifierModel(Model|ClassifierModel $model): bool
    {
        return $model instanceof ClassifierModel
            && ($model->api === ClassifierApi::LlamaCppClassify || $model->api === ClassifierApi::TypesafeSystemOne);
    }

    /**
     * @param LlamaModelInfo $model
     * @param array{models_autoload?: bool, chat_template?: string}|null $props
     */
    public static function toPiModel(array $model, string $serverUrl, ?array $props = null, ?int $cachedContextWindow = null): Model
    {
        $contextWindow = self::contextWindowOf($model, $cachedContextWindow);
        $reasoning = str_contains($props['chat_template'] ?? '', 'enable_thinking');

        return new Model(
            id: $model['id'],
            name: $model['id'],
            api: Api::OpenAiCompletions,
            provider: self::LLAMA_PROVIDER_ID,
            baseUrl: LlamaClient::llamaInferenceUrl($serverUrl),
            contextWindow: $contextWindow,
            maxTokens: $contextWindow,
            reasoning: $reasoning,
            input: in_array('image', $model['architecture']['input_modalities'] ?? [], true) ? ['text', 'image'] : ['text'],
            pricing: new Pricing(),
            compat: self::compat($reasoning),
            thinkingLevelMap: $reasoning ? ['off' => 'off', 'minimal' => null, 'low' => null, 'medium' => 'medium', 'high' => null, 'xhigh' => null] : [],
        );
    }

    private static function compat(bool $reasoning): OpenAiCompat
    {
        return new OpenAiCompat(
            store: false,
            developerRole: false,
            reasoningEffort: false,
            maxTokensField: 'max_tokens',
            strictMode: false,
            thinkingFormat: $reasoning ? 'qwen-chat-template' : null,
            supportsUsageInStreaming: true,
        );
    }

    /**
     * Upstream's `setCatalog()`: the catalog's selectable models as chat models and classifiers.
     *
     * @param list<LlamaModelInfo> $catalog
     */
    public function setCatalog(array $catalog, string $serverUrl, bool $routerAutoload = false): void
    {
        $selectable = array_values(array_filter($catalog, static fn (array $model): bool => self::modelIsSelectable($model, $routerAutoload)));
        $this->models = array_map(static fn (array $model): Model => self::toPiModel($model, $serverUrl), array_values(array_filter($selectable, self::isChatModel(...))));
        $this->classifiers = array_map(static fn (array $model): ClassifierModel => self::toPiClassifierModel($model, $serverUrl), $selectable);
        $this->install();
    }

    /** @return list<Model> upstream's `getModels()` */
    public function getModels(): array
    {
        return $this->models;
    }

    /** @return list<Model|ClassifierModel> upstream's `getAllModels()` */
    public function getAllModels(): array
    {
        return [...$this->models, ...$this->classifiers];
    }

    /** The lists into `Models`, in place of what the provider listed there before. */
    public function install(): void
    {
        Models::forgetProvider(self::LLAMA_PROVIDER_ID);
        Models::register($this->models);
        Models::registerClassifiers($this->classifiers);
    }

    /** Upstream's `refreshModels(context)`. */
    public function refreshModels(RefreshModelsContext $context): void
    {
        /** @var array<string, int> $cachedContextWindows */
        $cachedContextWindows = [];

        if ($context->stored !== null) {
            $stored = array_values(array_filter($context->stored['models'], static fn (Model|ClassifierModel $model): bool => $model->provider === self::LLAMA_PROVIDER_ID));
            $restored = array_values(array_filter($stored, static fn (Model|ClassifierModel $model): bool => $model instanceof Model && $model->api === Api::OpenAiCompletions));
            $restoredClassifiers = array_values(array_filter($stored, self::isLlamaClassifierModel(...)));

            foreach ([...$restored, ...$restoredClassifiers] as $model) {
                $cachedContextWindows[$model->id] = $model->contextWindow;
            }

            /** @var list<Model> $restored */
            /** @var list<ClassifierModel> $restoredClassifiers */
            if (!($context->publish)(null, function () use ($restored, $restoredClassifiers): void {
                $this->models = $restored;
                $this->classifiers = $restoredClassifiers;
            })) {
                return;
            }
        }

        if (!$context->allowNetwork || $context->signal->aborted() || $context->credential === null) {
            return;
        }

        $serverUrl = self::credentialServerUrl($context->credential);

        if ($serverUrl === null) {
            return;
        }

        $client = new LlamaClient($serverUrl, $context->credential->key);
        $catalog = $client->list(signal: $context->signal);

        if ($context->signal->aborted()) {
            return;
        }

        $routerAutoload = self::routerAutoloadEnabled($client, $catalog, $context->signal);

        if ($context->signal->aborted()) {
            return;
        }

        $selectable = array_values(array_filter($catalog, static fn (array $model): bool => self::modelIsSelectable($model, $routerAutoload)));
        // Only loaded models expose their chat template without side effects. Unloaded autoload presets would
        // need to be loaded, while querying sleeping models may wake them. Those models remain without thinking
        // support until they are loaded and a later catalog refresh discovers it. Decision models need no
        // template, and llama.cpp reports them in the catalog regardless of their status.
        $refreshed = array_map(static function (array $model) use ($cachedContextWindows, $client, $serverUrl, $context): Model {
            $cachedContextWindow = $cachedContextWindows[$model['id']] ?? null;

            if ($model['status']['value'] !== 'loaded') {
                return self::toPiModel($model, $serverUrl, null, $cachedContextWindow);
            }

            $props = $client->props($model['id'], $context->signal);

            return self::toPiModel($model, $serverUrl, $props, $cachedContextWindow);
        }, array_values(array_filter($selectable, self::isChatModel(...))));
        $refreshedClassifiers = array_map(
            static fn (array $model): ClassifierModel => self::toPiClassifierModel($model, $serverUrl, $cachedContextWindows[$model['id']] ?? null),
            $selectable,
        );

        if ($context->signal->aborted()) {
            return;
        }

        ($context->publish)(
            ['models' => [...$refreshed, ...$refreshedClassifiers], 'checkedAt' => (int) floor(microtime(true) * 1000)],
            function () use ($refreshed, $refreshedClassifiers): void {
                $this->models = $refreshed;
                $this->classifiers = $refreshedClassifiers;
            },
        );
    }

    /**
     * A model as upstream's models store files it — the model object, JSON-encoded, with `type:
     * "classifier"` on a classifier.
     *
     * @return array<string, mixed>
     */
    public static function toStoredModel(Model|ClassifierModel $model): array
    {
        $cost = ['input' => 0, 'output' => 0, 'cacheRead' => 0, 'cacheWrite' => 0];

        if ($model instanceof ClassifierModel) {
            return [
                'type' => 'classifier',
                'id' => $model->id,
                'name' => $model->name,
                'api' => $model->api->value,
                'provider' => $model->provider,
                'baseUrl' => $model->baseUrl,
                'input' => $model->input,
                'cost' => $cost,
                'contextWindow' => $model->contextWindow,
            ];
        }

        return [
            'id' => $model->id,
            'name' => $model->name,
            'api' => $model->api->value,
            'provider' => $model->provider,
            'baseUrl' => $model->baseUrl,
            'reasoning' => $model->reasoning,
            ...($model->reasoning ? ['thinkingLevelMap' => $model->thinkingLevelMap] : []),
            'input' => $model->input,
            'cost' => $cost,
            'contextWindow' => $model->contextWindow,
            'maxTokens' => $model->maxTokens,
            'compat' => [
                'supportsStore' => false,
                'supportsDeveloperRole' => false,
                'supportsReasoningEffort' => false,
                'supportsUsageInStreaming' => true,
                'supportsStrictMode' => false,
                'maxTokensField' => 'max_tokens',
                ...($model->reasoning ? ['thinkingFormat' => 'qwen-chat-template'] : []),
            ],
        ];
    }

    /**
     * The reverse of `toStoredModel()`, for the rows this provider files: a chat model on
     * `openai-completions` and a llama.cpp classifier. Anything else is left out, as upstream's
     * `refreshModels()` leaves it out of what it restores.
     *
     * @param array<string, mixed> $stored
     */
    public static function fromStoredModel(array $stored): Model|ClassifierModel|null
    {
        $id = $stored['id'] ?? null;
        $provider = $stored['provider'] ?? null;
        $baseUrl = $stored['baseUrl'] ?? null;
        $contextWindow = $stored['contextWindow'] ?? null;

        if (!is_string($id) || !is_string($provider) || !is_string($baseUrl) || !is_int($contextWindow)) {
            return null;
        }

        $name = is_string($stored['name'] ?? null) ? $stored['name'] : $id;
        $input = is_array($stored['input'] ?? null) && in_array('image', $stored['input'], true) ? ['text', 'image'] : ['text'];

        if (($stored['type'] ?? 'chat') === 'classifier') {
            $api = ClassifierApi::tryFrom((string) ($stored['api'] ?? ''));

            return $api === null ? null : new ClassifierModel($id, $name, $api, $provider, $baseUrl, $contextWindow, ['text'], new Pricing());
        }

        if (($stored['type'] ?? 'chat') !== 'chat' || ($stored['api'] ?? null) !== Api::OpenAiCompletions->value) {
            return null;
        }

        $reasoning = ($stored['reasoning'] ?? false) === true;
        $maxTokens = is_int($stored['maxTokens'] ?? null) ? $stored['maxTokens'] : $contextWindow;

        return new Model(
            id: $id,
            name: $name,
            api: Api::OpenAiCompletions,
            provider: $provider,
            baseUrl: $baseUrl,
            contextWindow: $contextWindow,
            maxTokens: $maxTokens,
            reasoning: $reasoning,
            input: $input,
            pricing: new Pricing(),
            compat: self::compat($reasoning),
            thinkingLevelMap: $reasoning && is_array($stored['thinkingLevelMap'] ?? null) ? $stored['thinkingLevelMap'] : [],
        );
    }
}
