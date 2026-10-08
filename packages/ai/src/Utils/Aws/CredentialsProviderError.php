<?php

declare(strict_types=1);

namespace Pig\Ai\Utils\Aws;

use RuntimeException;
use Throwable;

/**
 * `@smithy/property-provider`'s `CredentialsProviderError` (and the `ProviderError` /
 * `TokenProviderError` it shares a shape with): a link of the credential chain that could not answer.
 *
 * `tryNextLink` is the whole point of the type. `chain()` moves on to the next provider when it is true
 * — the default — and stops with this error when it is false; any other throwable stops the chain too.
 * `statusCode` is what the instance metadata service's `httpRequest()` puts on its "Error response
 * received from instance metadata service", which the IMDSv2 token step reads.
 */
final class CredentialsProviderError extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $tryNextLink = true,
        ?Throwable $previous = null,
        public readonly ?int $statusCode = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
