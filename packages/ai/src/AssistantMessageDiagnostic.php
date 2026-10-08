<?php

declare(strict_types=1);

namespace Pig\Ai;

/**
 * Something a provider or the runtime noticed about a turn that is not part of the answer —
 * upstream's `AssistantMessageDiagnostic` (`utils/diagnostics.ts`).
 *
 * Kept on the message, written to the session file, and read by `/bug`, so a recovery or an
 * oddity can be reported without sending the conversation. The one pig provider that makes any is
 * Anthropic: `anthropic_input_transformations`, when the API says it rewrote the request.
 *
 * `details` is upstream's `JsonObject`: plain values only, because it goes to JSON as it is.
 */
final readonly class AssistantMessageDiagnostic
{
    /** @param array<string, mixed>|null $details */
    public function __construct(
        public string $type,
        public int $timestamp,
        public ?DiagnosticErrorInfo $error = null,
        public ?array $details = null,
    ) {
    }
}
