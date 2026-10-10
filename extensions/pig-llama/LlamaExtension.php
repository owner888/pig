<?php

declare(strict_types=1);

namespace Pig\Extensions\Llama;

use Pig\Async\AbortController;
use Pig\Async\AbortSignal;
use Pig\Async\Loop;
use Pig\CodingAgent\Auth;
use Pig\CodingAgent\Hooks\HookContext;
use Pig\CodingAgent\ModelRefresh;
use RuntimeException;
use Throwable;

/**
 * Upstream's `extensions/llama/index.ts`: `/llama`, and the catalog kept in step with the router.
 *
 * The closures `llamaExtension()` builds — `syncCatalog`, `loadModel`, `unloadModel`,
 * `downloadModel` and the command's handler — are this class's methods, so a test can drive them
 * with a `LlamaUi` of its own.
 *
 * The catalog is the provider's (`LlamaProvider::refreshModels()`), refreshed by pig's
 * `ModelRefresh` as upstream's registry refreshes it; `ctx.modelRegistry.getProviderAuth()` is
 * `Auth::providerAuth()`.
 *
 * @phpstan-import-type LlamaModelInfo from LlamaClient
 */
final class LlamaExtension
{
    private const int CATALOG_TIMEOUT_MS = 15_000;

    public function __construct(
        private readonly Auth $auth,
        public readonly LlamaProvider $provider,
        private readonly string $huggingFaceUrl = HuggingFaceClient::DEFAULT_HUGGING_FACE_URL,
    ) {
    }

    /** @param LlamaModelInfo $model */
    public static function modelIsLoaded(array $model): bool
    {
        return $model['status']['value'] === 'loaded' || $model['status']['value'] === 'sleeping';
    }

    /** Upstream's `isConnectionError()`: `${name} ${message}` names a failed fetch, a timeout or the network. */
    public static function isConnectionError(Throwable $error): bool
    {
        $message = strtolower((new \ReflectionClass($error))->getShortName() . ' ' . $error->getMessage());

        return str_contains($message, 'fetch failed') || str_contains($message, 'timeout') || str_contains($message, 'network');
    }

    public static function connectionErrorMessage(Throwable $error): string
    {
        return self::isConnectionError($error) ? 'Could not connect to the server.' : $error->getMessage();
    }

    /**
     * Upstream's `parseHuggingFaceModel()`: `owner/repository[:quant]` split at the first colon after the slash.
     *
     * @return array{repository: string, quantization?: string}
     */
    public static function parseHuggingFaceModel(string $value): array
    {
        $slash = strpos($value, '/');
        $colon = strpos($value, ':', $slash === false ? 0 : $slash + 1);

        return $colon === false
            ? ['repository' => $value]
            : ['repository' => substr($value, 0, $colon), 'quantization' => substr($value, $colon + 1)];
    }

    /** Upstream's `configuredClient()`: the router from the provider's resolved auth, or a warning. */
    public function configuredClient(HookContext $ctx): ?LlamaClient
    {
        $result = $this->auth->providerAuth(LlamaProvider::LLAMA_PROVIDER_ID);

        if ($result === null) {
            $ctx->ui->notify('Configure llama.cpp with /login ' . LlamaProvider::LLAMA_PROVIDER_ID, 'warning');

            return null;
        }

        $configuredUrl = $result->env['LLAMA_BASE_URL'] ?? null;
        $serverUrl = LlamaClient::normalizeLlamaServerUrl(
            is_string($configuredUrl) && $configuredUrl !== '' ? $configuredUrl : ($result->auth->baseUrl ?? ''),
        );

        return new LlamaClient($serverUrl, $result->auth->apiKey);
    }

    /**
     * Upstream's `syncCatalog()`: the catalog given, or the router's, into the provider, then the
     * provider's refresh — kept live even with `PIG_OFFLINE`, since `/llama` already contacted the
     * configured server.
     *
     * @param list<LlamaModelInfo>|null $catalog
     * @return list<LlamaModelInfo>
     */
    public function syncCatalog(LlamaClient $client, ?array $catalog = null): array
    {
        $controller = new AbortController();
        $timer = Loop::get()->delay(self::CATALOG_TIMEOUT_MS / 1000, static fn () => $controller->abort('The operation was aborted due to timeout'));

        try {
            $current = $catalog ?? $client->list(signal: $controller->signal);
            $this->provider->setCatalog($current, $client->serverUrl);
            // /llama already contacted the configured llama.cpp server, so keep this refresh live even in PIG_OFFLINE.
            $result = $this->refresh(true, $controller->signal);
        } finally {
            Loop::get()->cancel($timer);
        }

        if ($result['aborted']) {
            throw new RuntimeException('Model catalog refresh timed out.');
        }

        if ($result['error'] !== null) {
            throw $result['error'];
        }

        return $current;
    }

    /**
     * @param list<LlamaModelInfo> $catalog
     * @param LlamaModelInfo $target
     */
    public function loadModel(HookContext $ctx, LlamaUi $ui, LlamaClient $client, array $catalog, array $target): void
    {
        $loaded = array_values(array_filter($catalog, static fn (array $model): bool => $model['id'] !== $target['id'] && self::modelIsLoaded($model)));
        $replace = false;

        if ($loaded !== []) {
            $count = count($loaded);
            $choice = $ui->select("{$count} model" . ($count === 1 ? ' is' : 's are') . ' loaded', [
                'Unload all and load',
                'Keep loaded and load',
                'Cancel',
            ]);

            if ($choice === null || $choice === 'Cancel') {
                return;
            }

            $replace = $choice === 'Unload all and load';
        }

        $restoreLoaded = function () use ($ctx, $client, $loaded): void {
            $ctx->ui->notify('Restoring previously loaded models');

            foreach ($loaded as $model) {
                $client->loadAndWait($model['id'], static function (): void {
                });
            }

            $this->syncCatalog($client);
        };

        if ($replace) {
            foreach ($loaded as $model) {
                $client->unloadAndWait($model['id']);
            }
        }

        try {
            $result = LlamaView::runWithProgress(
                $ui,
                title: 'Loading model',
                model: $target['id'],
                initialMessage: 'Starting…',
                cancelTitle: 'Stop loading?',
                cancelMessage: $target['id'],
                run: static fn (AbortSignal $signal, \Closure $update): array => $client->loadAndWait($target['id'], $update, $signal),
                cancel: static fn () => $client->unload($target['id']),
            );

            if ($result['cancelled']) {
                if ($replace) {
                    $restoreLoaded();
                }

                return;
            }

            $refreshed = $this->syncCatalog($client);
            $loadedModel = null;

            foreach ($refreshed as $model) {
                if ($model['id'] === $target['id']) {
                    $loadedModel = $model;
                    break;
                }
            }

            $ctx->ui->notify(($loadedModel['status']['value'] ?? null) === 'loaded' ? "Loaded {$target['id']}" : "Load started for {$target['id']}");
        } catch (Throwable $error) {
            if ($replace) {
                try {
                    $restoreLoaded();
                } catch (Throwable) {
                    // Preserve the original load error.
                }
            }

            throw $error;
        }
    }

    /** @param LlamaModelInfo $model */
    public function unloadModel(HookContext $ctx, LlamaUi $ui, LlamaClient $client, array $model): void
    {
        if (!$ui->confirm('Unload model?', $model['id'])) {
            return;
        }

        $client->unloadAndWait($model['id']);
        $this->syncCatalog($client);
        $ctx->ui->notify("Unloaded {$model['id']}");
    }

    public function downloadModel(HookContext $ctx, LlamaUi $ui, LlamaClient $client): void
    {
        $huggingFace = new HuggingFaceClient(HuggingFaceClient::findHuggingFaceToken(), $this->huggingFaceUrl);
        $selected = $ui->searchModels(static fn (string $query, AbortSignal $signal): array => $huggingFace->search($query, $signal));

        if ($selected === null || $selected === '') {
            return;
        }

        $parsed = self::parseHuggingFaceModel($selected);
        $ui->showStatus('Loading model details', $parsed['repository']);
        $details = $huggingFace->details($parsed['repository']);

        if ($details['gated'] !== false) {
            $approval = $details['gated'] === 'manual' ? 'Manual approval is required' : 'Accept the access terms';
            $choice = $ui->select(
                "Hugging Face access required\n{$details['id']}\n\n{$approval} at:\nhttps://huggingface.co/{$details['id']}\n\nThe llama.cpp server needs HF_TOKEN with access.",
                ['Continue', 'Back'],
            );

            if ($choice !== 'Continue') {
                return;
            }
        }

        $quantization = $parsed['quantization'] ?? null;

        if (($quantization === null || $quantization === '') && $details['quantizations'] !== []) {
            $options = array_map(static function (array $entry): string {
                $detail = implode(' · ', array_filter([
                    isset($entry['size']) ? LlamaClient::formatBytes($entry['size']) : null,
                    $entry['name'] === 'Q4_K_M' ? 'recommended' : null,
                ], static fn (?string $value): bool => $value !== null && $value !== ''));

                return $detail !== '' ? "{$entry['name']} · {$detail}" : $entry['name'];
            }, $details['quantizations']);
            $choice = $ui->select("Select quantization\n{$details['id']}", $options);

            if ($choice === null || $choice === '') {
                return;
            }

            $index = array_search($choice, $options, true);
            $quantization = $index === false ? null : ($details['quantizations'][$index]['name'] ?? null);

            if ($quantization === null || $quantization === '') {
                return;
            }
        }

        $model = $quantization !== null && $quantization !== '' ? "{$details['id']}:{$quantization}" : $details['id'];
        $result = LlamaView::runWithProgress(
            $ui,
            title: 'Downloading model',
            model: $model,
            initialMessage: 'Starting…',
            cancelTitle: 'Stop download?',
            cancelMessage: $model,
            run: static fn (AbortSignal $signal, \Closure $update): array => $client->downloadAndWait($model, $update, $signal),
            cancel: static fn () => $client->unload($model),
        );

        if ($result['cancelled']) {
            return;
        }

        $this->syncCatalog($client, $result['value']);
        $ctx->ui->notify("Downloaded {$model}");
    }

    /** Upstream's `/llama` handler. */
    public function command(string $args, HookContext $ctx): void
    {
        if ($ctx->mode() !== 'tui') {
            $ctx->ui->notify('/llama is available in interactive mode', 'warning');

            return;
        }

        $client = $this->configuredClient($ctx);

        if ($client === null) {
            return;
        }

        LlamaView::showLlamaUi($ctx, fn (LlamaUi $ui) => $this->manage($ctx, $ui, $client));
    }

    /** The body `/llama` runs in its view: the router's state, and the action chosen on it, until closed. */
    public function manage(HookContext $ctx, LlamaUi $ui, LlamaClient $client): void
    {
        $readCatalog = function () use ($ui, $client): ?array {
            while (true) {
                try {
                    return $this->syncCatalog($client);
                } catch (Throwable $error) {
                    if ($ui->connectionError($client->serverUrl, self::connectionErrorMessage($error)) === 'close') {
                        return null;
                    }
                }
            }
        };

        $catalog = $readCatalog();

        if ($catalog === null) {
            return;
        }

        while (true) {
            $action = $ui->showModels($client->serverUrl, $catalog);

            if ($action['type'] === 'close') {
                return;
            }

            $actionError = null;

            try {
                if ($action['type'] === 'download') {
                    $this->downloadModel($ctx, $ui, $client);
                } elseif (self::modelIsLoaded($action['model'])) {
                    $this->unloadModel($ctx, $ui, $client, $action['model']);
                } elseif ($action['model']['status']['value'] === 'unloaded') {
                    $this->loadModel($ctx, $ui, $client, $catalog, $action['model']);
                } else {
                    $ctx->ui->notify("{$action['model']['id']} is {$action['model']['status']['value']}", 'warning');
                }
            } catch (Throwable $error) {
                $actionError = $error;
            }

            $refreshed = $readCatalog();

            if ($refreshed === null) {
                return;
            }

            $catalog = $refreshed;

            if ($actionError !== null && !self::isConnectionError($actionError)) {
                $ctx->ui->notify($actionError->getMessage(), 'error');
            }
        }
    }

    /**
     * Upstream's `ctx.modelRegistry.refresh({providers: ["llama.cpp"], allowNetwork, signal})`:
     * `ModelRefresh` for this provider alone. A failure is the answer's `error`, not a throw, as
     * upstream's `errors` map is.
     *
     * @return array{aborted: bool, error: ?Throwable}
     */
    public function refresh(bool $allowNetwork, AbortSignal $signal): array
    {
        $result = ModelRefresh::refresh($this->auth, [LlamaProvider::LLAMA_PROVIDER_ID], $signal, $allowNetwork);

        return ['aborted' => $result->aborted, 'error' => $result->errors[LlamaProvider::LLAMA_PROVIDER_ID] ?? null];
    }
}
