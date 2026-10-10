<?php

declare(strict_types=1);

namespace Pig\Extensions\Llama;

use Closure;
use Pig\Async\Future;

/**
 * Upstream's `LlamaUi` (`extensions/llama/ui.ts`): the screens `/llama` moves between.
 *
 * Upstream's methods answer promises; here a method that waits parks the calling fiber and answers
 * the value, as every `HookUi` dialog does — except `progress()`, which `runWithProgress()` races
 * against the work it reports on, so it answers the `Future` itself.
 *
 * An action is upstream's `LlamaManagerAction`: `['type' => 'model', 'model' => …]`, `['type' =>
 * 'download']` or `['type' => 'close']`. A progress state is upstream's `ProgressState`, a
 * `LlamaProgress` with `title` and `model`.
 *
 * @phpstan-import-type LlamaModelInfo from LlamaClient
 * @phpstan-import-type HuggingFaceModel from HuggingFaceClient
 * @phpstan-type LlamaManagerAction array{type: 'model', model: LlamaModelInfo}|array{type: 'download'}|array{type: 'close'}
 * @phpstan-type ProgressState array{title: string, model: string, message: string, ratio?: float, detail?: string}
 */
interface LlamaUi
{
    /**
     * @param list<LlamaModelInfo> $models
     * @return LlamaManagerAction
     */
    public function showModels(string $serverUrl, array $models): array;

    /** @param list<string> $options */
    public function select(string $title, array $options): ?string;

    public function confirm(string $title, string $message): bool;

    /** @return 'retry'|'close' */
    public function connectionError(string $serverUrl, string $message): string;

    /** @param Closure(string, \Pig\Async\AbortSignal): list<HuggingFaceModel> $search */
    public function searchModels(Closure $search): ?string;

    public function showStatus(string $title, string $message): void;

    /**
     * Show the progress screen; the future completes when the person asks to stop.
     *
     * @param ProgressState $state
     * @return Future<null>
     */
    public function progress(array $state): Future;

    /** @param ProgressState $state */
    public function updateProgress(array $state): void;
}
