<?php

declare(strict_types=1);

namespace Pig\Ai\Extension;

use Closure;
use Pig\Async\AbortSignal;

/**
 * Upstream's `RefreshModelsContext`, the argument of a provider's `refreshModels` (`Provider`):
 * built by `CodingAgent\ModelRefresh`, once with the stored catalog and no network, then with the
 * resolved credential and the network.
 *
 * **The stored rows are the provider's own**, in pi's `models-store.json` shape (one object per
 * model, `type: "classifier"` on a classifier): pig has no codec for every model a provider could
 * file, so the provider reads and writes its rows itself. `$publish` is upstream's
 * `publish({persist, update})`: the entry to store, or null to leave the store as it is, and the
 * update to run once it is stored. False when the refresh was cancelled or superseded, and then
 * nothing was stored or run.
 */
final readonly class RefreshModelsContext
{
    /**
     * @param array{models: list<array<string, mixed>>, checkedAt?: int}|null $stored "Immutable
     *        provider-scoped catalog snapshot captured before this refresh phase."
     * @param Closure(?array{models: list<array<string, mixed>>, checkedAt: int}, ?Closure(): void): bool $publish
     * @param bool $allowNetwork "False during offline/cache-only initialization."
     * @param bool|null $force "Bypass provider-specific freshness checks", network phase only
     */
    public function __construct(
        public ?ApiKeyCredential $credential,
        public ?array $stored,
        public Closure $publish,
        public bool $allowNetwork,
        public AbortSignal $signal,
        public ?bool $force = null,
    ) {
    }
}
