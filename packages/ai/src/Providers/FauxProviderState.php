<?php

declare(strict_types=1);

namespace Pig\Ai\Providers;

/**
 * Upstream's `FauxProviderState`: how many requests the faux provider has answered. Its
 * `deferredFetchCount` and `cancelledDeferred` are not here — pig's providers have no deferred
 * responses for a script to fetch or cancel (see `StopReason::Deferred`).
 */
final class FauxProviderState
{
    public int $callCount = 0;
}
