<?php

declare(strict_types=1);

namespace Pig\Extensions\Llama;

use Closure;
use Pig\Ai\ClassifierModel;
use Pig\Ai\Extension\ApiKeyCredential;
use Pig\Ai\Model;
use Pig\Async\AbortSignal;

/**
 * Upstream's `RefreshModelsContext`, the argument of a provider's `refreshModels()`.
 *
 * pig's core has no model registry refresh for a provider to hang this on (the Antigravity
 * extension's `Discovery` says the same), so `LlamaExtension::refresh()` builds one of these the way
 * upstream's `Models.refresh()` does — the stored catalog and no network first, then the resolved
 * credential and the network — and `LlamaProvider::refreshModels()` takes it as upstream's takes it.
 *
 * `$publish` is upstream's `publish({persist, update})`: the entry to store, or null to leave the
 * store as it is, and the update to run once it is stored. False when the refresh was superseded.
 */
final readonly class RefreshModelsContext
{
    /**
     * @param array{models: list<Model|ClassifierModel>, checkedAt?: int}|null $stored "Immutable
     *        provider-scoped catalog snapshot captured before this refresh phase."
     * @param Closure(?array{models: list<Model|ClassifierModel>, checkedAt: int}, ?Closure(): void): bool $publish
     * @param bool $allowNetwork "False during offline/cache-only initialization."
     */
    public function __construct(
        public ?ApiKeyCredential $credential,
        public ?array $stored,
        public Closure $publish,
        public bool $allowNetwork,
        public AbortSignal $signal,
    ) {
    }
}
