<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Interactive;

/**
 * One row of `/login` and `/logout` — upstream's `AuthSelectorProvider` (`oauth-selector.ts`).
 *
 * `$status` is upstream's `AuthCheck`: how the provider is configured now (`oauth` or `api_key`) and
 * where from, or null when it is not. `$sign` is what starts or ends that sign-in, for the caller.
 */
final readonly class AuthSelectorProvider
{
    /**
     * @param 'oauth'|'api_key' $authType
     * @param array{type: 'oauth'|'api_key', source: string|null}|null $status
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $authType,
        public ?array $status,
        public bool $subscription = false,
        public mixed $sign = null,
        public string $methodName = '',
    ) {
    }
}
