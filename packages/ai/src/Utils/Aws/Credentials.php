<?php

declare(strict_types=1);

namespace Pig\Ai\Utils\Aws;

/**
 * One set of AWS credentials — the SDK's `AwsCredentialIdentity`.
 *
 * `features` is what `setCredentialFeature()` records on the identity (`$source`): the business
 * metric codes the SDK's user agent middleware appends to `m/…` for where the credentials came from —
 * `e` for credentials handed to the client in code, `g` from the environment, `n` from a profile, and
 * so on (`AwsSdkCredentialsFeatures`).
 */
final readonly class Credentials
{
    /**
     * @param int|null $expiration milliseconds since the epoch, or null for credentials that do not expire
     * @param array<string, string> $features feature name => metric code, in the order they were set
     */
    public function __construct(
        public string $accessKeyId,
        public string $secretAccessKey,
        public ?string $sessionToken = null,
        public ?int $expiration = null,
        public ?string $accountId = null,
        public array $features = [],
    ) {
    }

    /** `setCredentialFeature(credentials, name, code)`: the same credentials with one more source. */
    public function withFeature(string $name, string $code): self
    {
        return new self(
            $this->accessKeyId,
            $this->secretAccessKey,
            $this->sessionToken,
            $this->expiration,
            $this->accountId,
            [...$this->features, $name => $code],
        );
    }
}
