<?php

declare(strict_types=1);

namespace Pig\Ai\Providers;

use RuntimeException;
use Throwable;

/**
 * Upstream's `CodexApiError`: what the Codex backend said went wrong in the stream — an `error`
 * event or a `response.failed` — with the event's `code` and the event itself.
 */
final class CodexApiError extends RuntimeException
{
    /** @param array<string, mixed>|null $payload */
    public function __construct(
        string $message,
        public readonly ?string $codexCode = null,
        public readonly ?array $payload = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
