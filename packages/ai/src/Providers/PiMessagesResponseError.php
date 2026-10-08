<?php

declare(strict_types=1);

namespace Pig\Ai\Providers;

use RuntimeException;

/**
 * Upstream's `PiMessagesResponseError`: a pi-messages backend refused the request. `piCode` is the
 * body's `error.code` (upstream's `code`, renamed because `Exception::$code` is an int), and
 * `diagnosticDetails` what the `pi_messages_response_failure` diagnostic records.
 */
final class PiMessagesResponseError extends RuntimeException
{
    /** @param array<string, mixed> $diagnosticDetails */
    public function __construct(
        string $message,
        public readonly ?string $piCode,
        public readonly array $diagnosticDetails,
    ) {
        parent::__construct($message);
    }
}
