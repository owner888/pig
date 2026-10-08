<?php

declare(strict_types=1);

namespace Pig\Ai\Utils\Aws;

use RuntimeException;
use Throwable;

/**
 * An AWS service's error, as the SDK throws it: `name` is the error code (`ValidationException`,
 * `ThrottlingException`, `Unknown`, …), the message the service's own, and `$metadata`'s
 * `httpStatusCode` and `requestId` kept beside them.
 *
 * `bedrock` says whether this is a `BedrockRuntimeServiceException` — every error the Bedrock runtime
 * client deserializes from an HTTP answer or a modeled stream exception is — as opposed to another
 * client's (STS, SSO) or an unmodeled stream error, which upstream's `formatBedrockError()` prints
 * without a prefix.
 */
final class ServiceError extends RuntimeException
{
    /** @param array<string, string>|null $headers the response's, lowercased, for the retry policy */
    public function __construct(
        string $message,
        public readonly string $name,
        public readonly ?int $status,
        public readonly ?string $requestId,
        public readonly bool $bedrock,
        public readonly ?array $headers = null,
        ?Throwable $previous = null,
        public readonly bool $clockSkewCorrected = false,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /** `$metadata.clockSkewCorrected = true`, which makes the retry policy try again. */
    public function withClockSkewCorrected(): self
    {
        return new self($this->getMessage(), $this->name, $this->status, $this->requestId, $this->bedrock, $this->headers, $this->getPrevious(), true);
    }
}
