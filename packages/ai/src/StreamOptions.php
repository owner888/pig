<?php

declare(strict_types=1);

namespace Pig\Ai;

use Pig\Async\AbortSignal;

/** What every provider understands. Providers extend this with their own knobs. */
readonly class StreamOptions
{
    public function __construct(
        public ?float $temperature = null,
        public ?int $maxTokens = null,
        public ?AbortSignal $signal = null,
        public ?string $apiKey = null,
    ) {
    }
}
