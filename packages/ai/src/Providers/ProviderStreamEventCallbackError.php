<?php

declare(strict_types=1);

namespace Pig\Ai\Providers;

use RuntimeException;
use Throwable;

/**
 * Upstream's `ProviderStreamEventCallbackError`: the caller's `onProviderStreamEvent` threw while the
 * Codex stream was read. Its message is the callback's error's own (`formatThrownValue(cause)`).
 */
final class ProviderStreamEventCallbackError extends RuntimeException
{
    public function __construct(Throwable $cause)
    {
        parent::__construct($cause->getMessage(), 0, $cause);
    }
}
